<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use Symfony\Component\PropertyAccess\Exception\AccessException;
use Symfony\Component\PropertyAccess\Exception\NoSuchIndexException;
use Symfony\Component\PropertyAccess\Exception\NoSuchPropertyException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

use function array_key_exists;
use function is_array;
use function is_object;

/**
 * One segment of a path read from a value, the one way a template reads: an array by its key, an object through
 * {@see ObjectReadPolicy} (a secret is refused however it is reached, a bare method call is not a read) and then
 * the property accessor. A miss -- an absent key or property, a refusal, a scalar -- reads as null.
 */
final class SegmentReader
{
    public static function read(mixed $value, string $segment, PropertyAccessorInterface $accessor): mixed
    {
        if (is_array($value)) {
            return array_key_exists($segment, $value) ? $value[$segment] : null;
        }
        if (!is_object($value) || !ObjectReadPolicy::mayRead($value, $segment)) {
            return null;
        }
        try {
            return $accessor->getValue($value, $segment);
        } catch (NoSuchPropertyException|NoSuchIndexException|AccessException) {
            return null;
        }
    }
}
