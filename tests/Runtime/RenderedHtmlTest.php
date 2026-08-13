<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\Runtime\RenderedHtml;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * Tests for the Stringable HTML wrapper returned by widget renderers.
 */
final class RenderedHtmlTest extends TestCase
{
    public function testRenderedHtmlReturnsWrappedString(): void
    {
        $wrapped = new RenderedHtml('<p>hello</p>');

        self::assertSame('<p>hello</p>', (string) $wrapped);
    }

    public function testRenderedHtmlPreservesEmptyString(): void
    {
        $wrapped = new RenderedHtml('');

        self::assertSame('', (string) $wrapped);
    }

    public function testRenderedHtmlIsStringable(): void
    {
        $wrapped = new RenderedHtml('content');

        self::assertInstanceOf(Stringable::class, $wrapped);
    }

    public function testRenderedHtmlPreservesHtmlSpecialCharacters(): void
    {
        $html = '<a href="/x?a=1&amp;b=2" class="c">L &amp; R</a>';
        $wrapped = new RenderedHtml($html);

        self::assertSame($html, (string) $wrapped);
    }
}
