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
        try {
            return $this->accessor->getValue($this->entity, $name);
        } catch (NoSuchPropertyException|AccessException) {
            return null;
        }
    }

    public function __isset(string $name): bool
    {
        return $this->accessor->isReadable($this->entity, $name);
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
