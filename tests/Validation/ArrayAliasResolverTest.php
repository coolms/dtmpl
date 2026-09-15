<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Validation;

use CoolMS\Dtmpl\Validation\ArrayAliasResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ArrayAliasResolverTest extends TestCase
{
    #[Test]
    public function resolvesAKnownAliasAndNotAnUnknownOne(): void
    {
        $resolver = new ArrayAliasResolver(['user' => stdClass::class]);

        self::assertSame(stdClass::class, $resolver->resolve('user'));
        self::assertNull($resolver->resolve('ghost'));
    }

    #[Test]
    public function knownAliasesAreTheMapsKeysInDeclarationOrder(): void
    {
        $resolver = new ArrayAliasResolver([
            'user' => stdClass::class,
            'media_asset' => stdClass::class,
        ]);

        self::assertSame(['user', 'media_asset'], $resolver->knownAliases());
    }

    #[Test]
    public function anEmptyMapKnowsNothing(): void
    {
        $resolver = new ArrayAliasResolver();

        self::assertSame([], $resolver->knownAliases());
        self::assertNull($resolver->resolve('user'));
    }
}
