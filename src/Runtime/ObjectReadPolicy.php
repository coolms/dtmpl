<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use ReflectionProperty;

use function implode;
use function lcfirst;
use function method_exists;
use function preg_match;
use function property_exists;
use function str_contains;
use function strlen;
use function strtolower;
use function substr;

/**
 * What a template may read from an OBJECT in its context.
 *
 * A path segment reaches an object through `PropertyAccessor`, which reads a public property, a
 * `get`/`is`/`has`/`can` getter, a magic `__get`, or an `ArrayAccess` index -- and also CALLS a public method
 * whose name is the segment itself. That is more than a template should have, in two ways:
 *
 *  - A secret stays unreadable however it is reached. A value that is a secret, or still grants or proves
 *    access -- a password hash, a second-factor secret, a token or its hash, a recovery code, the ciphertext of
 *    any of these -- is refused by NAME, and the name is taken after the getter prefix is stripped. So
 *    `password` and `getPassword` are the same refusal: making the property private and keeping a getter does
 *    not reopen it.
 *  - A template reads; it does not act. A segment that is a bare public method call rather than a property or a
 *    getter is refused outright, because such a method may change state (`reactivate`, `uninstall`, `hide`,
 *    `end`) and because a reader of a template cannot tell a call from a field.
 *
 * A refusal reads as a miss: the segment resolves to null, as an absent property already does, so no template
 * learns from the answer whether the field exists. Arrays are not covered here and need no cover -- an array
 * holds what a renderer put in it, and holds no methods.
 *
 * The names are matched, not the values: this cannot be a list of fields, because a package cannot know an
 * application's entities. An application marks the secrets whose names do not say so, and the layer that can
 * see the marker refuses them as well.
 */
final class ObjectReadPolicy
{
    /**
     * A field whose name contains one of these holds a secret. Matched on the lowercased name after the getter
     * prefix is stripped, so `tokenHash`, `sealedSecret` and `sipCredentials` are all covered.
     */
    private const array SECRET_PARTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'credential',
        'recoverycode',
        'onetimecode',
    ];

    /** The prefixes PropertyAccessor reads a field through; each counts only before an upper-case letter. */
    private const array GETTER_PREFIXES = ['get', 'is', 'has', 'can'];

    /** Whether a template may read this segment from this object. */
    public static function mayRead(object $object, string $segment): bool
    {
        if (self::isSecret($segment)) {
            return false;
        }
        if (null !== self::getterPrefix($segment)) {
            // The segment IS a getter call, which reads; its field name was judged above.
            return true;
        }

        // A bare public method of that name: a call, not a read. A PUBLIC property of the same name is read
        // instead; a private one of that name does not make the call a read, which is what keeps a wrapper's own
        // accessor (`entity()` over a private $entity) from handing a template the object behind it. An object
        // with neither (a magic __get, an ArrayAccess) is left to PropertyAccessor.
        return !method_exists($object, $segment) || self::hasPublicProperty($object, $segment);
    }

    /** Whether a field of this name holds a secret, judged after the getter prefix is stripped. */
    public static function isSecret(string $segment): bool
    {
        $name = strtolower(self::fieldName($segment));

        foreach (self::SECRET_PARTS as $part) {
            if (str_contains($name, $part)) {
                return true;
            }
        }

        return false;
    }

    /** The field a segment names: `getPassword` and `password` are both the field `password`. */
    public static function fieldName(string $segment): string
    {
        $prefix = self::getterPrefix($segment);

        return null === $prefix ? $segment : lcfirst(substr($segment, strlen($prefix)));
    }

    private static function hasPublicProperty(object $object, string $name): bool
    {
        return property_exists($object, $name) && new ReflectionProperty($object, $name)->isPublic();
    }

    /**
     * The getter prefix a segment carries, or null. A prefix counts only when an upper-case letter follows it,
     * so `isActive` is a getter for `active` while `issuer` and `candidate` are names of their own.
     */
    private static function getterPrefix(string $segment): ?string
    {
        $prefixes = implode('|', self::GETTER_PREFIXES);

        return 1 === preg_match('/^(' . $prefixes . ')(?=[A-Z])/', $segment, $m) ? $m[1] : null;
    }
}
