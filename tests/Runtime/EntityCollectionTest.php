<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\Runtime\EntityCollection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the EntityCollection iterable container.
 */
final class EntityCollectionTest extends TestCase
{
    public function testEntityCollectionYieldsRawEntities(): void
    {
        $a = (object) ['id' => 1];
        $b = (object) ['id' => 2];
        $coll = new EntityCollection([$a, $b]);

        $yielded = iterator_to_array($coll);

        self::assertSame([$a, $b], $yielded);
    }

    public function testEntityCollectionCount(): void
    {
        $coll = new EntityCollection([(object) [], (object) [], (object) []]);

        self::assertCount(3, $coll);
    }

    public function testEntityCollectionEmpty(): void
    {
        $coll = new EntityCollection([]);

        self::assertCount(0, $coll);
        self::assertSame([], iterator_to_array($coll));
        self::assertSame('', (string) $coll);
    }

    public function testEntityCollectionAcceptsGenerator(): void
    {
        $a = (object) ['k' => 'a'];
        $b = (object) ['k' => 'b'];
        $generator = (static function () use ($a, $b) {
            yield $a;
            yield $b;
        })();

        $coll = new EntityCollection($generator);

        self::assertCount(2, $coll);
        self::assertSame([$a, $b], iterator_to_array($coll));
    }
}
