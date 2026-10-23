<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime\Fixture;

final readonly class PublicAccount
{
    public function __construct(
        public string $email,
        public string $passwordHash,
    ) {
    }
}
