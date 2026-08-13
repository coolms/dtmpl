<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Exception;

/**
 * Thrown when a circular include chain is detected during template execution.
 */
class CircularIncludeException extends RuntimeException
{
    /** @param string[] $chain */
    public function __construct(array $chain)
    {
        parent::__construct('Circular include detected: ' . implode(' -> ', $chain));
    }
}
