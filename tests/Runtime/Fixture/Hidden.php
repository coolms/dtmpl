<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime\Fixture;

final readonly class Hidden
{
    public function __construct(
        public string $item,
        public int $cost,
    ) {
    }
}
