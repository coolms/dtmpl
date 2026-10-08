<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use ReflectionProperty;

use function implode;
use function lcfirst;
use function method_exists;
use function preg_match;
use function preg_replace;
use function property_exists;
use function str_contains;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;
use function ucwords;

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
        'codehash',
        'hashedcode',
    ];

    /** The prefixes PropertyAccessor reads a field through; each counts only before an upper-case letter. */
    private const array GETTER_PREFIXES = ['get', 'is', 'has', 'can'];

    /** Whether a template may read this segment from this object. */
    public static function mayRead(object $object, string $segment): bool
    {
        // The name PropertyAccessor will go looking for, not the one written: it camelizes a segment before it
        // tries a method, and PHP matches a method name without regard to case. So `re_activate` finds
        // `reactivate()` and `hashed_code` finds `hashedCode()`, and judging the written segment would let every
        // refusal here be walked around by writing the same name another way (measured 2026-10-08).
        $name = self::camelize($segment);
        if (self::isSecret($name)) {
            return false;
        }
        if (null !== self::getterPrefix($name)) {
            // The segment IS a getter call, which reads; its field name was judged above.
            return true;
        }

        // A method of that name: a call, not a read, and refused whatever else the object has. A public property
        // of the same name does NOT make it a read, because PropertyAccessor tries methods first
        // (ReflectionExtractor::getReadInfo) -- so on an object with both, allowing it would run the method and
        // return its value, not read the field (measured 2026-10-08). An object with no such method -- a magic
        // __get, an ArrayAccess, a plain property -- is left to PropertyAccessor.
        return !method_exists($object, $name) && !method_exists($object, $segment);
    }

    /**
     * Whether a field of this name holds a secret. The name is camelized, the getter prefix stripped and every
     * separator dropped before the parts are looked for, so `hashed_code`, `hashedCode` and `getHashedCode` are
     * one answer and no spelling of a name is a way past the list.
     */
    public static function isSecret(string $segment): bool
    {
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', self::fieldName(self::camelize($segment))));

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

    /**
     * A segment as PropertyAccessor spells it when it looks for a method: `hashed_code` and `hashed-code` both
     * become `hashedCode`. A segment already in that form is unchanged.
     */
    private static function camelize(string $segment): string
    {
        $spaced = str_replace(['-', ' ', '.'], '_', $segment);

        return lcfirst(str_replace('_', '', ucwords($spaced, '_')));
    }

    /**
     * Whether the object declares this name as a PUBLIC property: what a caller reading a field itself, rather
     * than through PropertyAccessor, may read. Reading a private one from outside raises an Error.
     */
    public static function hasPublicProperty(object $object, string $name): bool
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
