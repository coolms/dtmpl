<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl;

use CoolMS\Dtmpl\AST\TemplateNode;
use CoolMS\Dtmpl\Exception\SyntaxException;
use CoolMS\Dtmpl\Exception\TemplateNotFoundException;
use CoolMS\Dtmpl\Lexer\Lexer;
use CoolMS\Dtmpl\Optimizer\WhitespaceTrimmer;
use CoolMS\Dtmpl\Parser\Parser;
use CoolMS\Dtmpl\Runtime\ConstantProviderInterface;
use CoolMS\Dtmpl\Runtime\Executor;
use CoolMS\Dtmpl\Runtime\FilterRegistry;
use CoolMS\Dtmpl\Runtime\TemplateCompilerInterface;
use CoolMS\Dtmpl\Widget\WidgetRegistry;
use Exception;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Template Engine.
 *
 * Main entry point for dTMPL template rendering.
 * Provides caching, compilation, and execution.
 */
final class DtmplEngine implements TemplateCompilerInterface
{
    private readonly Lexer $lexer;
    private readonly Parser $parser;
    private readonly WhitespaceTrimmer $whitespaceTrimmer;
    private readonly Executor $executor;

    /** @var array<string, TemplateNode> */
    private array $compiledCache = [];

    /** @var ConstantProviderInterface[] */
    private array $constantProviders = [];

    /**
     * `$translator` is what makes `{t:}` do anything. The Executor has accepted
     * one since the tag was built, but for a long time nothing passed it, so
     * every `{t:}` silently rendered its own key -- which is why no template
     * used the tag. Nullable, so the engine stays usable standalone.
     */
    public function __construct(
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly bool $debug = false,
        private readonly string $encoding = 'UTF-8',
        private readonly ?TemplateLoaderInterface $loader = null,
        private readonly ?WidgetRegistry $widgets = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
        $this->lexer = new Lexer($encoding);
        $this->parser = new Parser();
        $this->whitespaceTrimmer = new WhitespaceTrimmer();
        $this->executor = new Executor(
            filters: new FilterRegistry(),
            loader: $this->loader,
            compiler: $this,
            widgets: $widgets,
            translator: $translator,
        );
    }

    /**
     * Create a new instance with custom configuration.
     *
     * @param array<string, mixed> $options
     */
    public static function create(array $options = []): self
    {
        return new self(
            cache: $options['cache'] ?? null,
            debug: $options['debug'] ?? false,
            encoding: $options['encoding'] ?? 'UTF-8',
            loader: $options['loader'] ?? null,
        );
    }

    /**
     * Register constant providers (called by ConstantProviderPass).
     *
     * @param ConstantProviderInterface[] $providers
     */
    public function setConstantProviders(array $providers): void
    {
        $this->constantProviders = $providers;
    }

    /**
     * Render template with data.
     *
     * Constants from all registered providers are automatically merged into
     * the reserved '_const' key before execution.  Caller-supplied '_const'
     * values take precedence over provider defaults.
     *
     * @param array<string, mixed> $data
     * @param string               $templatePath VFS path of this template, used for relative include resolution
     *
     * @throws InvalidArgumentException
     */
    public function render(string $template, array $data = [], string $templatePath = ''): string
    {
        $ast = $this->compile($template);

        return $this->executor->execute($ast, $this->mergeConstants($data), $templatePath);
    }

    /**
     * Render a template from a file.
     *
     * @param array<string, mixed> $data
     */
    public function renderFile(string $path, array $data = []): string
    {
        if (!file_exists($path)) {
            throw new TemplateNotFoundException("Template file not found: $path");
        }
        $template = (string) file_get_contents($path);

        return $this->render($template, $data, $path);
    }

    /**
     * Return a new engine instance with the given loader bound.
     * Used to create a per-render, user-scoped engine without mutating shared state.
     */
    public function withLoader(TemplateLoaderInterface $loader): self
    {
        $clone = new self(
            cache: $this->cache,
            debug: $this->debug,
            encoding: $this->encoding,
            loader: $loader,
            widgets: $this->widgets,
            // A host that renders every layout through a theme-bound clone --
            // mail composition typically does -- would find `{t:}` inert in
            // exactly the templates it exists for if this were dropped.
            translator: $this->translator,
        );
        $clone->constantProviders = $this->constantProviders;

        return $clone;
    }

    /**
     * Compile template to AST.
     *
     * @throws InvalidArgumentException
     */
    public function compile(string $source): TemplateNode
    {
        // Check in-memory cache
        $cacheKey = md5($source);
        if (isset($this->compiledCache[$cacheKey])) {
            return $this->compiledCache[$cacheKey];
        }
        // Check persistent cache
        if (null !== $this->cache) {
            $item = $this->cache->getItem('dtmpl_' . $cacheKey);
            if ($item->isHit()) {
                $ast = $item->get();
                $this->compiledCache[$cacheKey] = $ast;

                return $ast;
            }
        }
        // Compile template
        $tokens = $this->lexer->tokenize($source);
        $ast = $this->parser->parse($tokens);
        $ast = $this->whitespaceTrimmer->trim($ast);
        // Store in caches
        $this->compiledCache[$cacheKey] = $ast;
        if (null !== $this->cache) {
            $item = $this->cache->getItem('dtmpl_' . $cacheKey);
            $item->set($ast);
            $item->expiresAfter(3600); // 1 hour
            $this->cache->save($item);
        }

        return $ast;
    }

    /**
     * Validate template syntax (compile without executing).
     *
     * @throws Exception|InvalidArgumentException
     */
    public function validate(string $template): bool
    {
        try {
            $this->compile($template);

            return true;
        } catch (Exception $e) {
            if ($this->debug) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * Get validation errors.
     *
     * @return list<array{message: string, line: int, column: int}>
     */
    public function getErrors(string $template): array
    {
        $errors = [];

        try {
            $this->compile($template);
        } catch (Exception $e) {
            $errors[] = [
                'message' => $e->getMessage(),
                'line' => $e instanceof SyntaxException ? $e->row : 0,
                'column' => $e instanceof SyntaxException ? $e->column : 0,
            ];
        }

        return $errors;
    }

    /**
     * Register custom filter.
     */
    public function registerFilter(string $name, callable $filter): void
    {
        $this->executor->registerFilter($name, $filter);
    }

    /**
     * Get filter registry.
     */
    public function getFilters(): FilterRegistry
    {
        return $this->executor->getFilters();
    }

    /**
     * Clear compiled cache.
     */
    public function clearCache(): void
    {
        $this->compiledCache = [];
        $this->cache?->clear();
    }

    /**
     * Get cache statistics.
     *
     * @return array<string, mixed>
     */
    public function getCacheStats(): array
    {
        return [
            'in_memory_count' => count($this->compiledCache),
            'persistent_enabled' => null !== $this->cache,
        ];
    }

    /**
     * Merge provider constants into data under '_const' key.
     * Caller-supplied '_const' values override provider defaults.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function mergeConstants(array $data): array
    {
        if ([] === $this->constantProviders) {
            return $data;
        }

        $providerConst = [];
        foreach ($this->constantProviders as $provider) {
            $providerConst = array_merge($providerConst, $provider->getConstants());
        }

        // Caller-supplied _const takes precedence
        $callerConst = is_array($data['_const'] ?? null) ? $data['_const'] : [];
        $data['_const'] = array_merge($providerConst, $callerConst);

        return $data;
    }
}
