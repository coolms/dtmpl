<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use Stringable;
use Symfony\Component\PropertyAccess\Exception\AccessException;
use Symfony\Component\PropertyAccess\Exception\NoSuchPropertyException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Stringable proxy around a domain entity that exposes property
 * navigation via Symfony PropertyAccessor.
 *
 * Returns null for unreadable or missing properties so DTMPL
 * templates can navigate optional paths without raising. Stringifies
 * to the entity's own __toString when available, otherwise to the
 * empty string.
 *
 * What a template may read of the entity is {@see ObjectReadPolicy}:
 * a secret is refused however it is reached, and a bare method call
 * is refused because it is not a read. The policy is asked here as
 * well as in {@see Context}, so neither door depends on the other
 * being the only way in: this wrapper is handed to widget callers
 * directly, and a context may hold an entity nothing wrapped.
 * A refused name reads as an absent one -- null, and not set -- so a
 * template cannot learn from the answer that the field is there.
 */
final readonly class EntityWrapper implements Stringable
{
    public function __construct(
        private object $entity,
        private PropertyAccessorInterface $accessor,
    ) {
    }

    public function __get(string $name): mixed
    {
        if (!ObjectReadPolicy::mayRead($this->entity, $name)) {
            return null;
        }
        try {
            return $this->accessor->getValue($this->entity, $name);
        } catch (NoSuchPropertyException|AccessException) {
            return null;
        }
    }

    public function __isset(string $name): bool
    {
        return ObjectReadPolicy::mayRead($this->entity, $name)
            && $this->accessor->isReadable($this->entity, $name);
    }

    public function __toString(): string
    {
        return $this->entity instanceof Stringable
            ? (string) $this->entity
            : '';
    }

    public function entity(): object
    {
        return $this->entity;
    }
}
