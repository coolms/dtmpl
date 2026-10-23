<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use Closure;
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

    /**
     * The entity, wrapped. With a read guard, the wrapper reads only the fields it allows, and every object a read
     * hands out is asked of it too ({@see EntityWrapper::guarded()}); null when it refuses the entity itself.
     *
     * @param (Closure(object): ?list<string>)|null $fieldsFor
     *
     * @return ($fieldsFor is null ? EntityWrapper : EntityWrapper|null)
     */
    public function wrap(object $entity, ?Closure $fieldsFor = null): ?EntityWrapper
    {
        return null === $fieldsFor
            ? new EntityWrapper($entity, $this->accessor)
            : EntityWrapper::guarded($entity, $this->accessor, $fieldsFor);
    }
}
