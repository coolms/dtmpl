<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use DateTimeInterface;
use Stringable;

/**
 * Constant Provider Interface.
 *
 * Implementations supply immutable, platform-level constants that are
 * injected under the reserved '_const' key before every template render.
 * Templates access them via {const:name}.
 *
 * Tag services with 'coolms.dtmpl.constant_provider' to auto-register.
 */
interface ConstantProviderInterface
{
    /**
     * @return array<string, scalar|Stringable|DateTimeInterface>
     */
    public function getConstants(): array;
}
