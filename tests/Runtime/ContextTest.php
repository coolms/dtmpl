<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\Runtime\Context;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * Targeted tests for `Context::resolvePath`'s PropertyAccessor
 * delegation. Engine-level navigation is covered end-to-end by the
 * DTMPL render tests; these tests pin the specific patterns the
 * delegation needs to handle: getter, missing property null-on-miss,
 * magic `__get` via `__isset` gate.
 */
final class ContextTest extends TestCase
{
    public function testResolvePathReturnsNullForMissingObjectProperty(): void
    {
        $obj = new class {
            public string $name = 'A';
        };
        $ctx = new Context(['x' => $obj]);

        self::assertNull($ctx->get(['x', 'nonexistent']));
    }

    public function testResolvePathNavigatesGetter(): void
    {
        $obj = new class {
            public function getName(): string
            {
                return 'Alice';
            }
        };
        $ctx = new Context(['x' => $obj]);

        self::assertSame('Alice', $ctx->get(['x', 'name']));
    }

    public function testResolvePathNavigatesMagicGet(): void
    {
        $obj = new class implements Stringable {
            public function __get(string $name): mixed
            {
                return 'foo' === $name ? 'bar' : null;
            }

            public function __isset(string $name): bool
            {
                return 'foo' === $name;
            }

            public function __toString(): string
            {
                return '';
            }
        };
        $ctx = new Context(['x' => $obj]);

        self::assertSame('bar', $ctx->get(['x', 'foo']));
        self::assertNull($ctx->get(['x', 'absent']));
    }
}
