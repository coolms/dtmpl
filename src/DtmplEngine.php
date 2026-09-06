<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl;

use CoolMS\Dtmpl\AST\TemplateNode;
use CoolMS\Dtmpl\Exception\SyntaxException;
use CoolMS\Dtmpl\Exception\TemplateNotFoundException;
use CoolMS\Dtmpl\Lexer\Lexer;
use CoolMS\Dtmpl\Optimizer\WhitespaceTrimmer;
use CoolMS\Dtmpl\Parser\Parser;
use CoolMS\Dtmpl\Runtime\AssetGatherer;
use CoolMS\Dtmpl\Runtime\CollectedAssets;
use CoolMS\Dtmpl\Runtime\ConstantProviderInterface;
use CoolMS\Dtmpl\Runtime\Executor;
use CoolMS\Dtmpl\Runtime\FilterRegistry;
use CoolMS\Dtmpl\Runtime\OutputMode;
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
    /**
     * Schema version of the serialized AST, part of every cache key.
     *
     * The persistent cache stores compiled {@see TemplateNode} object
     * graphs. Keying them on the template SOURCE alone was wrong across
     * an engine upgrade: add a property to any AST node and yesterday's
     * serialized graph deserializes into today's class without it, so
     * the first read after deploy dies on "typed property ... must not
     * be accessed before initialization" -- on every page, until the TTL
     * expires or someone thinks to purge the pool by hand.
     *
     * **Bump this whenever an AST node's shape changes** (a new
     * constructor property, a removed one, a changed type). Old entries
     * then age out under their own keys instead of being read back.
     */
    private const string AST_VERSION = '3';

    private readonly Lexer $lexer;
    private readonly Parser $parser;
    private readonly WhitespaceTrimmer $whitespaceTrimmer;
    private readonly Executor $executor;

    /** @var array<string, TemplateNode> */
    private array $compiledCache = [];

    /** @var array<string, CollectedAssets> */
    private array $gatheredAssets = [];

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
        private readonly OutputMode $outputMode = OutputMode::Html,
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
            outputMode: $outputMode,
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

        return $this->ungatheredAssetNotice($ast, $data, $templatePath)
            . $this->executor->execute($ast, $this->mergeConstants($data), $templatePath);
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
     * The `{css}` / `{js}` declared by `$source` and everything it includes.
     *
     * The host calls this BEFORE rendering and puts the result where the
     * document head can reach it -- which is the whole reason the gather is a
     * separate pass rather than something the Executor accumulates as it goes.
     * By the time a block partial renders, the `<head>` is already bytes on
     * the wire; anything collected during the render can only be written after
     * the markup that needed it.
     *
     * Requires a loader (bound via {@see withLoader()}) to follow includes;
     * without one it returns only what the root template itself declares.
     *
     * @param string $templatePath VFS path of `$source`, for relative include resolution
     *
     * @throws InvalidArgumentException
     */
    public function gatherAssets(string $source, string $templatePath = ''): CollectedAssets
    {
        // Per-instance memo only. `withLoader()` clones the engine for each
        // render, so this lives exactly as long as the loader chain whose
        // answers it caches -- a memo keyed on source alone, on the shared
        // engine, would hand one theme's block styles to the next theme's page.
        return $this->gatheredAssets[md5($templatePath . "\0" . $source)] ??=
            new AssetGatherer($this->loader, $this)->gather($this->compile($source), $templatePath);
    }

    /**
     * Return a new engine instance with the given loader bound.
     * Used to create a per-render, user-scoped engine without mutating shared state.
     */
    public function withLoader(TemplateLoaderInterface $loader): self
    {
        return $this->cloneWith(loader: $loader);
    }

    /**
     * Return a new engine instance producing `$mode` instead of HTML.
     *
     * For a host pointing the engine at something that is not a web page
     * -- a filename pattern, a spreadsheet cell, an OOXML run -- where
     * HTML-encoding a value corrupts it. Chosen once, at the seam that
     * knows what it is building, rather than per template or per value.
     */
    public function withOutputMode(OutputMode $mode): self
    {
        return $this->cloneWith(outputMode: $mode);
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
        $itemKey = 'dtmpl_' . self::AST_VERSION . '_' . $cacheKey;
        // Check persistent cache. The stored value is type-checked
        // rather than trusted: a pool is shared infrastructure, and a
        // key collision or a half-written entry must degrade to a
        // recompile, not to a TypeError deep in the Executor.
        if (null !== $this->cache) {
            $item = $this->cache->getItem($itemKey);
            if ($item->isHit()) {
                $ast = $item->get();
                if ($ast instanceof TemplateNode) {
                    $this->compiledCache[$cacheKey] = $ast;

                    return $ast;
                }
            }
        }
        // Compile template
        $tokens = $this->lexer->tokenize($source);
        $ast = $this->parser->parse($tokens);
        $ast = $this->whitespaceTrimmer->trim($ast);
        // Store in caches
        $this->compiledCache[$cacheKey] = $ast;
        if (null !== $this->cache) {
            $item = $this->cache->getItem($itemKey);
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
     * A message, in debug only, when this template declares `{css}` / `{js}`
     * that the document it is being rendered into does not carry.
     *
     * ⚠️ **THE FAILURE THIS EXISTS FOR IS SILENCE, NOT BREAKAGE.** The gather
     * is a static walk of `{include:}` from the page root. A template reached
     * any other way is invisible to it -- a widget names its partial at render
     * time (ADR-135), so `{widget:nav:menu}`'s `{css}` was never gathered and
     * the menu simply rendered unstyled. Nothing logged, nothing 500'd, and
     * the person who eventually notices is looking at an unstyled block with
     * no reason for it anywhere. The rule "a block owns its assets" was true
     * everywhere except where it quietly was not.
     *
     * ⚠️ **A NOTICE AND NOT AN EXCEPTION, DELIBERATELY.** Throwing here would
     * be worse than the silence it replaces: {@see \App\Navi\Infrastructure\Widget\NaviMenuWidgetRenderer}
     * catches `Throwable` and degrades to an empty menu -- because it renders
     * on every public page -- so a throw would turn an unstyled menu into a
     * MISSING one, still silently. Every widget with that guard behaves the
     * same way. The loss has to be reported through a channel the guard does
     * not swallow, and the rendered document is that channel.
     *
     * ⚠️ **It checks the condition, not the cause.** It asks whether these
     * declarations reached the head, so it catches every route around the walk
     * -- a widget, a service rendering a partial on its own, a dynamically
     * named include -- rather than only the one that was found first.
     *
     * No `_assets` key at all means nobody gathered, which is also worth
     * saying: a host that forgets the pass loses every declaration on the page,
     * and that shipped once already.
     *
     * @param array<string, mixed> $data
     */
    private function ungatheredAssetNotice(TemplateNode $ast, array $data, string $templatePath): string
    {
        if (!$this->debug || [] === $ast->assets) {
            return '';
        }

        $assets = $data['_assets'] ?? null;
        $gathered = is_array($assets) && is_array($assets['keys'] ?? null) ? $assets['keys'] : null;

        $missing = [];
        foreach ($ast->assets as $asset) {
            if (null === $gathered || !in_array($asset->key(), $gathered, true)) {
                $missing[] = $asset->kind->value;
            }
        }

        if ([] === $missing) {
            return '';
        }

        return sprintf(
            '<!-- dtmpl: %d asset block(s) declared by `%s` (%s) did not reach the document head%s. '
            . 'The gather is a static walk of {include:} from the page root; a template reached any other way '
            . '-- a widget naming its partial at render time, a service rendering it directly -- is not on that walk. '
            . "Move the declaration into a template the page includes, or include this one. -->\n",
            count($missing),
            '' !== $templatePath ? $templatePath : '(inline source)',
            implode(', ', $missing),
            null === $gathered ? ' (nothing was gathered for this render at all)' : '',
        );
    }

    /**
     * Copy this engine, overriding the named settings.
     *
     * Every `with*()` goes through here so a setting can never be
     * dropped by one of them: `withLoader()` used to rebuild the engine
     * by listing its arguments, and each new constructor argument had to
     * be remembered in two places. The translator's own docblock records
     * what happened the time it was not -- `{t:}` went inert in exactly
     * the templates it exists for, because mail composition renders
     * through a theme-bound clone.
     */
    private function cloneWith(
        ?TemplateLoaderInterface $loader = null,
        ?OutputMode $outputMode = null,
    ): self {
        $clone = new self(
            cache: $this->cache,
            debug: $this->debug,
            encoding: $this->encoding,
            loader: $loader ?? $this->loader,
            widgets: $this->widgets,
            translator: $this->translator,
            outputMode: $outputMode ?? $this->outputMode,
        );
        $clone->constantProviders = $this->constantProviders;

        return $clone;
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
