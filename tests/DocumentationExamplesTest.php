<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\AST\TemplateNode;
use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Lexer\KeywordRegistry;
use CoolMS\Dtmpl\Runtime\FilterRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Every fenced example in the shipped documentation must actually work.
 *
 * Written after a verification pass found SEVEN claims in README.md /
 * docs/*.md that the parser contradicts -- colon-separated filter
 * arguments that silently dropped everything after the second colon, a
 * `$engine->filters()` call that is a fatal error, a `{fill:}` example
 * that cannot parse outside an include, a `default:` example the parser
 * intercepted, a strict mode that does not exist, output escaping that
 * did not happen, and `{endinclude}` shown as mandatory. Every one of
 * them was the same failure: documentation written from the design
 * instead of verified against the code.
 *
 * Care does not prevent that a second time; a test does. Two checks:
 *
 *   1. DTMPL blocks (untagged fences, and ```dtmpl) must COMPILE.
 *      Not render -- rendering wants a context and a loader, and the
 *      examples are deliberately context-free. Compiling is what catches
 *      a syntax the engine does not have.
 *   2. PHP blocks must not call a method on the engine or the filter
 *      registry that does not exist. That is the check that would have
 *      caught `$engine->filters()->register(...)`.
 *
 * A fence that is a deliberate fragment carries `<!-- doctest:skip -->`
 * on the line before it, so skipping is visible in the source rather
 * than implied by an exclusion list somewhere else.
 */
final class DocumentationExamplesTest extends TestCase
{
    private const string SKIP_MARKER = '<!-- doctest:skip -->';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dtmplExamples(): iterable
    {
        foreach (self::docs() as $file) {
            foreach (self::fences($file, ['', 'dtmpl']) as [$line, $code]) {
                yield sprintf('%s:%d', basename($file), $line) => [$code, sprintf('%s line %d', $file, $line)];
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function phpExamples(): iterable
    {
        foreach (self::docs() as $file) {
            foreach (self::fences($file, ['php']) as [$line, $code]) {
                yield sprintf('%s:%d', basename($file), $line) => [$code, sprintf('%s line %d', $file, $line)];
            }
        }
    }

    #[Test]
    #[DataProvider('dtmplExamples')]
    public function everyDocumentedTemplateCompiles(string $code, string $where): void
    {
        $engine = new DtmplEngine();

        try {
            $ast = $engine->compile($code);
        } catch (Throwable $e) {
            self::fail(sprintf(
                "Documented template does not compile (%s):\n\n%s\n\n%s: %s",
                $where,
                $code,
                $e::class,
                $e->getMessage(),
            ));
        }

        self::assertInstanceOf(TemplateNode::class, $ast);
    }

    #[Test]
    #[DataProvider('phpExamples')]
    public function everyDocumentedApiCallExists(string $code, string $where): void
    {
        $targets = [
            'engine' => DtmplEngine::class,
            'filters' => FilterRegistry::class,
        ];

        // `$engine->foo(` / `$filters->foo(` -- the receiver is named by
        // the variable, which is enough for docs we write ourselves.
        preg_match_all('/\$(engine|filters)->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $code, $matches, PREG_SET_ORDER);

        $missing = [];
        foreach ($matches as [, $variable, $method]) {
            if (!method_exists($targets[$variable], $method)) {
                $missing[] = sprintf('$%s->%s() -- no such method on %s', $variable, $method, $targets[$variable]);
            }
        }

        self::assertSame([], $missing, sprintf("Documented API call does not exist (%s).\n\n%s", $where, $code));
    }

    #[Test]
    public function noDocumentedExampleTeachesARemovedKeyword(): void
    {
        // A `doctest:skip` fence is exempt from compilation, which is
        // what lets the upgrade note quote `{raw}`. That exemption must
        // not become a hiding place for an example still teaching the
        // old spelling, so the removed spellings are searched for as
        // WORKING syntax across the whole of every doc, skipped fences
        // included -- and only the migration prose is allowed to name
        // them.
        $offenders = [];
        foreach (self::docs() as $file) {
            foreach (self::fences($file, ['', 'dtmpl'], includeSkipped: true) as [$line, $code]) {
                foreach (array_keys(KeywordRegistry::REMOVED) as $removed) {
                    if (!str_contains($code, '{' . $removed . '}')) {
                        continue;
                    }
                    // Naming it inside prose about the rename is the point;
                    // presenting it as a template is not.
                    if (str_contains($code, 'was renamed to')) {
                        continue;
                    }
                    $offenders[] = sprintf('%s line %d teaches `{%s}`', $file, $line, $removed);
                }
            }
        }

        self::assertSame([], $offenders);
    }

    #[Test]
    public function theDocsAreActuallyBeingRead(): void
    {
        // A provider that silently yields nothing turns this whole file
        // into a no-op that still reports green. Anchor it: the docs
        // ship with far more examples than this, and a count that has
        // collapsed means the extraction broke, not that the examples
        // went away.
        self::assertGreaterThan(15, iterator_count(self::dtmplExamples()));
        self::assertGreaterThan(0, iterator_count(self::phpExamples()));
    }

    /**
     * @return list<string>
     */
    private static function docs(): array
    {
        $root = dirname(__DIR__);
        $files = glob($root . '/docs/*.md') ?: [];
        $files[] = $root . '/README.md';
        sort($files);

        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * Fenced blocks whose info string is one of `$languages`.
     *
     * @param list<string> $languages
     *
     * @return list<array{int, string}> [1-indexed line of the opening fence, block body]
     */
    private static function fences(string $file, array $languages, bool $includeSkipped = false): array
    {
        $lines = explode("\n", (string) file_get_contents($file));
        $blocks = [];
        $count = count($lines);

        for ($i = 0; $i < $count; ++$i) {
            if (!str_starts_with($lines[$i], '```')) {
                continue;
            }
            $language = trim(substr($lines[$i], 3));
            $openedAt = $i;
            $body = [];
            for (++$i; $i < $count && !str_starts_with($lines[$i], '```'); ++$i) {
                $body[] = $lines[$i];
            }
            if (!in_array($language, $languages, true)) {
                continue;
            }
            if (!$includeSkipped && self::SKIP_MARKER === trim($lines[$openedAt - 1] ?? '')) {
                continue;
            }
            $blocks[] = [$openedAt + 1, implode("\n", $body)];
        }

        return $blocks;
    }
}
