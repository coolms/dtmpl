<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime\Fixture;

use ArrayIterator;
use DateTimeImmutable;
use Stringable;

final readonly class Order implements Stringable
{
    /** @param list<object> $lines */
    public function __construct(
        private string $number,
        private string $note,
        private Customer $customer,
        private array $lines,
        private DateTimeImmutable $placedAt,
    ) {
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    /** @return list<object> */
    public function getLines(): array
    {
        return $this->lines;
    }

    /** @return ArrayIterator<int, object> */
    public function getLinesIterable(): ArrayIterator
    {
        return new ArrayIterator($this->lines);
    }

    public function getPlacedAt(): DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function __toString(): string
    {
        return 'order ' . $this->number;
    }
}
