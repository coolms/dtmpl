<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Service that constructs EntityWrapper instances with the shared
 * PropertyAccessor. Injected into widget renderers that surface
 * domain entities to template authors.
 */
final readonly class EntityWrapperFactory
{
    public function __construct(
        private PropertyAccessorInterface $accessor,
    ) {
    }

    public function wrap(object $entity): EntityWrapper
    {
        return new EntityWrapper($entity, $this->accessor);
    }
}
