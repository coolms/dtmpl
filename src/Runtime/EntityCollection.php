<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Stringable;
use Traversable;

/**
 * Stringable iterable container around a list of domain entities.
 *
 * Iteration yields raw entities so DTMPL loops navigate them
 * directly via PropertyAccessor at the template layer. Stringifies
 * to the empty string by default; callers should iterate or count.
 *
 * @implements IteratorAggregate<int, object>
 */
final readonly class EntityCollection implements IteratorAggregate, Countable, Stringable
{
    /** @var array<int, object> */
    private array $entities;

    /**
     * @param iterable<object> $entities
     */
    public function __construct(iterable $entities)
    {
        $items = [];
        foreach ($entities as $entity) {
            $items[] = $entity;
        }
        $this->entities = $items;
    }

    /**
     * @return Traversable<int, object>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->entities);
    }

    public function count(): int
    {
        return count($this->entities);
    }

    public function __toString(): string
    {
        return '';
    }
}
