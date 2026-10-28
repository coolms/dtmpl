<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\DtmplEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnum;
use ReflectionProperty;

use function basename;
use function class_exists;
use function enum_exists;
use function glob;
use function hash;
use function implode;
use function interface_exists;
use function sort;
use function sprintf;

/**
 * The compiled AST is cached serialized, keyed by DtmplEngine::AST_VERSION. A node whose shape changes (a property
 * added, removed, renamed or retyped; an enum case) without a new version makes yesterday's cached graphs deserialize
 * into today's classes without the change: "typed property ... must not be accessed before initialization" on every
 * page until the cache is purged. This test records the shape each version was released with.
 */
final class AstVersionTest extends TestCase
{
    /** The fingerprint of src/AST for each AST_VERSION. A changed shape needs a new version AND a new line here. */
    private const array SHAPES = [
        '3' => '5241f74786ae251ab6017754e44467cfd8ef32a8964cd2cf101e3143aa8ca2a1',
        // FilterNode::$placeholder (the `?` placeholder). 2.3.0-alpha4 shipped this shape still as version 3.
        '4' => '71767021269be786cffb6f11081c21dde9c9c6c49d73adba8f00f374136989d8',
    ];

    #[Test]
    public function theAstShapeIsTheOneItsVersionWasReleasedWith(): void
    {
        $version = new ReflectionClassConstant(DtmplEngine::class, 'AST_VERSION')->getValue();
        $shape = self::fingerprint();

        self::assertArrayHasKey($version, self::SHAPES, sprintf(
            'AST_VERSION %s has no recorded shape: add \'%s\' => \'%s\' to SHAPES.',
            $version,
            $version,
            $shape,
        ));
        self::assertSame(self::SHAPES[$version], $shape, sprintf(
            'The AST changed shape but AST_VERSION is still %s: bump it in DtmplEngine, then record the new shape (%s).',
            $version,
            $shape,
        ));
    }

    /** Every class and enum in src/AST, by its serialized shape: properties with their types, and enum cases. */
    private static function fingerprint(): string
    {
        $lines = [];
        foreach (glob(__DIR__ . '/../src/AST/*.php') ?: [] as $file) {
            $class = 'CoolMS\\Dtmpl\\AST\\' . basename($file, '.php');
            if (enum_exists($class)) {
                $cases = [];
                foreach (new ReflectionEnum($class)->getCases() as $case) {
                    $cases[] = $case->getName();
                }
                sort($cases);
                $lines[] = $class . ' enum ' . implode(',', $cases);

                continue;
            }
            if (!class_exists($class) && !interface_exists($class)) {
                continue;
            }
            $properties = [];
            foreach (new ReflectionClass($class)->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }
                $properties[] = self::describe($property);
            }
            sort($properties);
            $lines[] = $class . ' ' . implode(',', $properties);
        }
        sort($lines);

        return hash('sha256', implode("\n", $lines));
    }

    private static function describe(ReflectionProperty $property): string
    {
        return sprintf('%s:%s', $property->getName(), (string) $property->getType());
    }
}
