<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use Closure;
use DateTimeInterface;
use Stringable;
use Symfony\Component\PropertyAccess\Exception\AccessException;
use Symfony\Component\PropertyAccess\Exception\NoSuchPropertyException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Traversable;
use UnitEnum;

use function array_any;
use function array_is_list;
use function array_values;
use function in_array;
use function is_a;
use function is_array;
use function is_object;

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
 *
 * A wrapper made with a read guard ({@see self::guarded()}) is also
 * limited to the fields the guard allows for its record, and never
 * hands out an object of its own: every object a read would return --
 * a related record, or one in a collection or an array -- is asked of
 * the same guard and handed out wrapped, or read as absent when the
 * guard refuses it. A template's next step on it therefore meets a
 * wrapper again, never the bare object. A guarded wrapper prints as the
 * empty string: an entity's string form is not one of its fields, and
 * the guard lists fields. Dates, enums and uids are values, not records,
 * and are handed out as they are.
 */
final readonly class EntityWrapper implements Stringable
{
    /** Symfony's uid, named rather than imported: a value where it is installed, and this package does not need it. */
    private const string UID = 'Symfony\Component\Uid\AbstractUid';

    /**
     * @param list<string>|null                     $fields    the fields a template may read; null: every one the
     *                                                         policy allows
     * @param (Closure(object): ?list<string>)|null $fieldsFor the read guard every object a read returns is asked of
     */
    public function __construct(
        private object $entity,
        private PropertyAccessorInterface $accessor,
        private ?array $fields = null,
        private ?Closure $fieldsFor = null,
    ) {
    }

    /**
     * The wrapper a read guard allows: limited to the fields `$fieldsFor` answers for `$entity`, or null when it
     * answers null, so a refused record reads as an absent one.
     *
     * @param Closure(object): ?list<string> $fieldsFor
     */
    public static function guarded(object $entity, PropertyAccessorInterface $accessor, Closure $fieldsFor): ?self
    {
        $fields = $fieldsFor($entity);

        return null === $fields ? null : new self($entity, $accessor, $fields, $fieldsFor);
    }

    public function __get(string $name): mixed
    {
        if (!$this->mayRead($name)) {
            return null;
        }
        try {
            $value = $this->accessor->getValue($this->entity, $name);
        } catch (NoSuchPropertyException|AccessException) {
            return null;
        }

        return null === $this->fieldsFor ? $value : $this->handOut($value, $this->fieldsFor);
    }

    public function __isset(string $name): bool
    {
        return $this->mayRead($name) && $this->accessor->isReadable($this->entity, $name);
    }

    public function __toString(): string
    {
        if (null !== $this->fieldsFor) {
            return '';
        }

        return $this->entity instanceof Stringable
            ? (string) $this->entity
            : '';
    }

    public function entity(): object
    {
        return $this->entity;
    }

    private function mayRead(string $name): bool
    {
        if (!ObjectReadPolicy::mayRead($this->entity, $name)) {
            return false;
        }
        if (null === $this->fields) {
            return true;
        }
        $fields = $this->fields;

        return array_any(ObjectReadPolicy::namesReadBy($name), static fn (string $n): bool => in_array($n, $fields, true));
    }

    /**
     * A value as a guarded read hands it out: a scalar or a value object as it is; any other object wrapped by the
     * guard, or null when it refuses it; an array or a collection with each object in it handed out the same way,
     * and a refused one left out.
     *
     * @param Closure(object): ?list<string> $fieldsFor
     */
    private function handOut(mixed $value, Closure $fieldsFor): mixed
    {
        if (is_array($value)) {
            return $this->handOutEach($value, $fieldsFor);
        }
        if (!is_object($value) || $value instanceof DateTimeInterface || $value instanceof UnitEnum
            || is_a($value, self::UID)) {
            return $value;
        }
        if ($value instanceof Traversable) {
            $items = [];
            foreach ($value as $key => $item) {
                $items[$key] = $item;
            }

            return $this->handOutEach($items, $fieldsFor);
        }

        return self::guarded($value, $this->accessor, $fieldsFor);
    }

    /**
     * @param array<mixed>                   $values
     * @param Closure(object): ?list<string> $fieldsFor
     *
     * @return array<mixed>
     */
    private function handOutEach(array $values, Closure $fieldsFor): array
    {
        $list = array_is_list($values);
        $out = [];
        foreach ($values as $key => $item) {
            $handed = $this->handOut($item, $fieldsFor);
            if (is_object($item) && null === $handed) {
                continue;
            }
            $out[$key] = $handed;
        }

        return $list ? array_values($out) : $out;
    }
}
