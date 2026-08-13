<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\Runtime\FilterRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Focused coverage for the DTMPL {@see FilterRegistry} pipe filters -- pins the
 * `split` (inverse of `join`) and `replace` (template-friendly `str_replace`)
 * filters, the two string filters the registry was missing despite shipping a
 * `join` and a (footgun-as-a-filter) `php.str_replace`.
 */
final class FilterRegistryTest extends TestCase
{
    private FilterRegistry $registry;

    #[Test]
    public function theNewFiltersAreRegistered(): void
    {
        self::assertTrue($this->registry->has('split'));
        self::assertTrue($this->registry->has('replace'));

        $available = $this->registry->getAvailableFilters();
        self::assertContains('split', $available);
        self::assertContains('replace', $available);
    }

    // --- split: string -> array (inverse of join) ---

    #[Test]
    public function splitExplodesAStringOnTheSeparator(): void
    {
        self::assertSame(['a', 'b', 'c'], $this->registry->apply('split', 'a,b,c', [',']));
    }

    #[Test]
    public function splitDefaultsToTheCommaSeparator(): void
    {
        self::assertSame(['a', 'b'], $this->registry->apply('split', 'a,b'));
    }

    #[Test]
    public function splitHonoursTheLimit(): void
    {
        self::assertSame(['a', 'b,c'], $this->registry->apply('split', 'a,b,c', [',', 2]));
    }

    #[Test]
    public function splitWithAnEmptySeparatorSplitsPerCharacter(): void
    {
        self::assertSame(['a', 'b', 'c'], $this->registry->apply('split', 'abc', ['']));
        self::assertSame([], $this->registry->apply('split', '', ['']));
    }

    #[Test]
    public function splitLeavesAnArrayUntouched(): void
    {
        self::assertSame(['x', 'y'], $this->registry->apply('split', ['x', 'y'], [',']));
    }

    #[Test]
    public function splitIsTheInverseOfJoin(): void
    {
        $joined = $this->registry->apply('join', ['a', 'b', 'c'], ['-']);
        self::assertSame('a-b-c', $joined);
        self::assertSame(['a', 'b', 'c'], $this->registry->apply('split', $joined, ['-']));
    }

    // --- replace: template-friendly str_replace (value is the subject) ---

    #[Test]
    public function replaceSubstitutesWithinTheSubjectValue(): void
    {
        self::assertSame('hello world', $this->registry->apply('replace', 'hello there', ['there', 'world']));
    }

    #[Test]
    public function replaceReplacesEveryOccurrence(): void
    {
        self::assertSame('b-b-b', $this->registry->apply('replace', 'a-a-a', ['a', 'b']));
    }

    #[Test]
    public function replaceCastsANonStringSubjectToString(): void
    {
        self::assertSame('1_3_1', $this->registry->apply('replace', 12321, ['2', '_']));
    }

    // --- truncate_words: word-based truncation (vs the char-based `truncate`) ---

    #[Test]
    public function truncateWordsKeepsTheFirstNWordsAndAppendsTheSuffix(): void
    {
        self::assertSame('one two three...', $this->registry->apply('truncate_words', 'one two three four five', [3]));
    }

    #[Test]
    public function truncateWordsDefaultsToThirtyWords(): void
    {
        $text = implode(' ', array_map(static fn (int $i): string => "w$i", range(1, 40)));
        $out = $this->registry->apply('truncate_words', $text);
        self::assertIsString($out);
        self::assertStringEndsWith('w30...', $out);
        self::assertStringStartsWith('w1 w2 ', $out);
    }

    #[Test]
    public function truncateWordsLeavesAStringWithinTheLimitUntouched(): void
    {
        // Fewer words than the limit AND exactly the limit both pass through
        // unchanged -- original spacing preserved, no suffix appended.
        self::assertSame('one two', $this->registry->apply('truncate_words', 'one two', [5]));
        self::assertSame('one  two  three', $this->registry->apply('truncate_words', 'one  two  three', [3]));
    }

    #[Test]
    public function truncateWordsHonoursACustomSuffix(): void
    {
        self::assertSame('a b (more)', $this->registry->apply('truncate_words', 'a b c d', [2, ' (more)']));
    }

    #[Test]
    public function truncateWordsDegradesGracefullyOnEmptyOrNonPositiveLimit(): void
    {
        self::assertSame('', $this->registry->apply('truncate_words', '', [3]));
        self::assertSame('a b c', $this->registry->apply('truncate_words', 'a b c', [0]));
    }

    // --- filesize: human-readable byte size ---

    #[Test]
    public function filesizeRendersBytesWithoutDecimals(): void
    {
        self::assertSame('0 B', $this->registry->apply('filesize', 0));
        self::assertSame('512 B', $this->registry->apply('filesize', 512));
        self::assertSame('1,023 B', $this->registry->apply('filesize', 1023));
    }

    #[Test]
    public function filesizeStepsUpInBinaryUnits(): void
    {
        self::assertSame('1.0 KB', $this->registry->apply('filesize', 1024));
        self::assertSame('1.5 KB', $this->registry->apply('filesize', 1536));
        self::assertSame('1.0 MB', $this->registry->apply('filesize', 1048576));
        self::assertSame('1.5 MB', $this->registry->apply('filesize', 1572864));
        self::assertSame('1.0 GB', $this->registry->apply('filesize', 1073741824));
    }

    #[Test]
    public function filesizeHonoursThePrecisionArgument(): void
    {
        self::assertSame('1.50 KB', $this->registry->apply('filesize', 1536, [2]));
        self::assertSame('2 KB', $this->registry->apply('filesize', 1536, [0]));
    }

    #[Test]
    public function filesizePassesANonNumericValueThrough(): void
    {
        self::assertSame('n/a', $this->registry->apply('filesize', 'n/a'));
    }

    #[Test]
    public function thePresentationFiltersAreRegistered(): void
    {
        self::assertTrue($this->registry->has('truncate_words'));
        self::assertTrue($this->registry->has('filesize'));

        $available = $this->registry->getAvailableFilters();
        self::assertContains('truncate_words', $available);
        self::assertContains('filesize', $available);
    }

    protected function setUp(): void
    {
        $this->registry = new FilterRegistry();
    }
}
