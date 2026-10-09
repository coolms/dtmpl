<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use Closure;
use CoolMS\Dtmpl\Runtime\Context;
use CoolMS\Dtmpl\Runtime\EntityWrapper;
use CoolMS\Dtmpl\Runtime\EntityWrapperFactory;
use CoolMS\Dtmpl\Tests\Runtime\Fixture\Customer;
use CoolMS\Dtmpl\Tests\Runtime\Fixture\Hidden;
use CoolMS\Dtmpl\Tests\Runtime\Fixture\Line;
use CoolMS\Dtmpl\Tests\Runtime\Fixture\Order;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * A wrapper made with a read guard reads only the fields the guard allows for its record, and hands out no object of
 * its own: a related record, or one in a collection, is asked of the same guard and comes back wrapped, or absent when
 * the guard refuses it. So a template's next step on it is limited too, however deep the path.
 */
final class AGuardedWrapperReadsOnlyWhatTheGuardAllowsTest extends TestCase
{
    #[Test]
    public function aRecordReadsOnlyTheFieldsTheGuardAllowsUnderAnySpellingOfTheirName(): void
    {
        $order = $this->wrap($this->order());

        self::assertSame('A-1', $order->__get('number'));
        self::assertSame('A-1', $order->__get('getNumber'), 'a getter spelling reads the same field');
        self::assertTrue($order->__isset('number'));
        self::assertNull($order->__get('note'), 'a field the guard does not allow reads as absent');
        self::assertNull($order->__get('getNote'));
        self::assertFalse($order->__isset('note'));
    }

    #[Test]
    public function aRelatedRecordTheGuardRefusesReadsAsAbsent(): void
    {
        $order = $this->wrap($this->order(), refused: [Customer::class]);

        self::assertNull($order->__get('customer'));
    }

    #[Test]
    public function aRelatedRecordComesBackWrappedAndLimitedToItsOwnFields(): void
    {
        $customer = $this->wrap($this->order())->__get('customer');

        self::assertInstanceOf(EntityWrapper::class, $customer, 'never the bare object');
        self::assertSame('Ada', $customer->__get('name'));
        self::assertNull($customer->__get('email'), 'the customer guard allows its name only');
    }

    #[Test]
    public function aCollectionHandsOutEachRecordWrappedAndLeavesOutARefusedOne(): void
    {
        $order = $this->wrap($this->order(), refused: [Hidden::class]);

        foreach (['lines', 'linesIterable'] as $field) {
            $lines = $order->__get($field);
            self::assertIsArray($lines, $field);
            self::assertCount(1, $lines, $field . ': the refused line is left out');
            self::assertInstanceOf(EntityWrapper::class, $lines[0]);
            self::assertSame('pen', $lines[0]->__get('item'));
            self::assertNull($lines[0]->__get('cost'), $field . ': a line allows its item only');
        }
    }

    #[Test]
    public function aTemplatePathThroughTheContextMeetsAWrapperAtEveryStep(): void
    {
        $context = new Context(['order' => $this->wrap($this->order())]);

        self::assertSame('Ada', $context->get(['order', 'customer', 'name']));
        self::assertNull($context->get(['order', 'customer', 'email']), 'the next step is limited too');
        self::assertSame('pen', $context->get(['order', 'lines', '0', 'item']));
        self::assertNull($context->get(['order', 'lines', '0', 'cost']));
    }

    #[Test]
    public function aDateIsAValueAndIsHandedOutAsItIs(): void
    {
        self::assertEquals(new DateTimeImmutable('2026-10-09'), $this->wrap($this->order())->__get('placedAt'));
    }

    #[Test]
    public function aGuardedRecordPrintsNothingOfItsOwnStringForm(): void
    {
        self::assertSame('', (string) $this->wrap($this->order()));
        self::assertSame('order A-1', (string) new EntityWrapperFactory(PropertyAccess::createPropertyAccessor())
            ->wrap($this->order()), 'unguarded, as before');
    }

    #[Test]
    public function theFactoryWrapsWithTheGuardOrAnswersNullWhenItRefusesTheRecord(): void
    {
        $factory = new EntityWrapperFactory(PropertyAccess::createPropertyAccessor());

        self::assertNull($factory->wrap($this->order(), static fn (object $o): ?array => null));
        $wrapped = $factory->wrap($this->order(), $this->guard([]));
        self::assertInstanceOf(EntityWrapper::class, $wrapped);
        self::assertNull($wrapped->__get('note'));
    }

    /** @param list<class-string> $refused */
    private function wrap(Order $order, array $refused = []): EntityWrapper
    {
        $wrapper = EntityWrapper::guarded($order, PropertyAccess::createPropertyAccessor(), $this->guard($refused));
        self::assertNotNull($wrapper);

        return $wrapper;
    }

    /**
     * @param list<class-string> $refused
     *
     * @return Closure(object): ?list<string>
     */
    private function guard(array $refused): Closure
    {
        $allowed = [
            Order::class => ['number', 'customer', 'lines', 'linesIterable', 'placedAt'],
            Customer::class => ['name'],
            Line::class => ['item'],
            Hidden::class => ['item'],
        ];

        return static function (object $record) use ($allowed, $refused): ?array {
            if (in_array($record::class, $refused, true)) {
                return null;
            }

            return $allowed[$record::class] ?? null;
        };
    }

    private function order(): Order
    {
        return new Order(
            'A-1',
            'internal note',
            new Customer('Ada', 'ada@example.org'),
            [new Line('pen', 3), new Hidden('secret item', 9)],
            new DateTimeImmutable('2026-10-09'),
        );
    }
}
