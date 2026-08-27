<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Lexer;

use CoolMS\Dtmpl\Lexer\KeywordRegistry;
use CoolMS\Dtmpl\Lexer\Lexer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_keys;
use function count;
use function sort;

/**
 * The keyword list exists twice, and nothing was checking that the copies agree.
 *
 * `Lexer::KEYWORDS` maps identifier -> TokenType because the parser needs the
 * binding. `KeywordRegistry::KEYWORDS` is a flat list because the strict
 * tag-detection pass only needs the names. The registry's own docblock says it
 * "mirrors the Lexer's internal map" -- which is a statement of intent, not a
 * mechanism.
 *
 * ⚠️ The pre-existing `KeywordRegistryTest` cannot catch a divergence: it
 * iterates `KeywordRegistry::KEYWORDS` and asserts `isKeyword()` accepts each
 * one, so it only ever compares the registry with itself. Add a keyword to the
 * Lexer alone and every test stays green while the strict pass treats the new
 * tag as literal text -- silently, because that is the designed fallback for an
 * unrecognised `{`.
 *
 * This is the check that fails instead.
 */
final class KeywordListsAgreeTest extends TestCase
{
    #[Test]
    public function theRegistryAndTheLexerListExactlyTheSameKeywords(): void
    {
        /** @var array<string, mixed>|null $map */
        $map = (new ReflectionClass(Lexer::class))->getConstant('KEYWORDS') ?: null;

        // Reading a private constant by reflection is the point -- the Lexer
        // keeps it private on purpose -- but a rename would make getConstant()
        // return false, and comparing two empty lists would PASS.
        self::assertIsArray($map, 'Lexer::KEYWORDS could not be read -- renamed?');
        self::assertNotSame([], $map, 'Lexer::KEYWORDS is empty -- a broken read, not agreement');

        $fromLexer = array_keys($map);
        $fromRegistry = KeywordRegistry::KEYWORDS;

        self::assertNotSame([], $fromRegistry, 'KeywordRegistry::KEYWORDS is empty');

        sort($fromLexer);
        sort($fromRegistry);

        self::assertSame(
            $fromRegistry,
            $fromLexer,
            'The two keyword lists have diverged. A keyword known to the Lexer '
            . 'but missing from the registry makes the strict pass treat that '
            . 'tag as literal text; the reverse makes the registry offer a '
            . '"did you mean" for a tag the lexer cannot parse.',
        );

        // A denominator, so a future refactor that empties both cannot pass.
        self::assertGreaterThan(15, count($fromRegistry));
    }
}
