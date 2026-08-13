<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\Runtime\EntityWrapper;
use CoolMS\Dtmpl\Runtime\EntityWrapperFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Tests for the EntityWrapperFactory service.
 */
final class EntityWrapperFactoryTest extends TestCase
{
    public function testEntityWrapperFactoryProducesWrappers(): void
    {
        $factory = new EntityWrapperFactory(PropertyAccess::createPropertyAccessor());
        $entity = new class {
            public function getName(): string
            {
                return 'F';
            }
        };

        $wrapper = $factory->wrap($entity);

        self::assertInstanceOf(EntityWrapper::class, $wrapper);
        self::assertSame('F', $wrapper->__get('name'));
        self::assertSame($entity, $wrapper->entity());
    }
}
