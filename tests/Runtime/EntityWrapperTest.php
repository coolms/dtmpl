<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\Runtime\EntityWrapper;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Tests for the EntityWrapper Stringable proxy over domain entities.
 */
final class EntityWrapperTest extends TestCase
{
    private PropertyAccessorInterface $accessor;

    public function testEntityWrapperGetterAccess(): void
    {
        $entity = new class {
            public function getName(): string
            {
                return 'Alice';
            }
        };
        $wrapper = new EntityWrapper($entity, $this->accessor);

        self::assertSame('Alice', $wrapper->__get('name'));
    }

    public function testEntityWrapperIsserAccess(): void
    {
        $entity = new class {
            public function isActive(): bool
            {
                return true;
            }
        };
        $wrapper = new EntityWrapper($entity, $this->accessor);

        self::assertTrue($wrapper->__get('active'));
    }

    public function testEntityWrapperPublicPropertyAccess(): void
    {
        $entity = new class {
            public string $title = 'Hello';
        };
        $wrapper = new EntityWrapper($entity, $this->accessor);

        self::assertSame('Hello', $wrapper->__get('title'));
    }

    public function testEntityWrapperMissingPropertyReturnsNull(): void
    {
        $entity = new class {
            public function getName(): string
            {
                return 'Alice';
            }
        };
        $wrapper = new EntityWrapper($entity, $this->accessor);

        self::assertNull($wrapper->__get('missingProperty'));
    }

    public function testEntityWrapperIssetReadsReadable(): void
    {
        $entity = new class {
            public function getName(): string
            {
                return 'X';
            }
        };
        $wrapper = new EntityWrapper($entity, $this->accessor);

        self::assertTrue(isset($wrapper->name));
        self::assertFalse(isset($wrapper->nope));
    }

    public function testEntityWrapperToStringForStringableEntity(): void
    {
        $entity = new class implements Stringable {
            public function __toString(): string
            {
                return 'STR';
            }
        };
        $wrapper = new EntityWrapper($entity, $this->accessor);

        self::assertSame('STR', (string) $wrapper);
    }

    public function testEntityWrapperToStringForNonStringableEntity(): void
    {
        $entity = new class {
            public function getName(): string
            {
                return 'X';
            }
        };
        $wrapper = new EntityWrapper($entity, $this->accessor);

        self::assertSame('', (string) $wrapper);
    }

    public function testEntityWrapperExposesUnderlyingEntity(): void
    {
        $entity = new class {
            public function getName(): string
            {
                return 'Y';
            }
        };
        $wrapper = new EntityWrapper($entity, $this->accessor);

        self::assertSame($entity, $wrapper->entity());
    }

    protected function setUp(): void
    {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }
}
