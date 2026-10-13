<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\Runtime\FilterRegistry;
use CoolMS\Dtmpl\Tests\Runtime\Fixture\PublicAccount;
use JsonSerializable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

use function json_decode;

/**
 * `php.json_encode` takes what the json filter takes: arrays and scalars, never an object's public properties -- nor
 * what its jsonSerialize() would hand over, which is a method call a template could not make itself.
 */
final class PhpJsonEncodeCarriesNoObjectPropertiesTest extends TestCase
{
    #[Test]
    public function anObjectIsNeverDumped(): void
    {
        $json = new FilterRegistry()->apply('php.json_encode', new PublicAccount('ada@example.org', 'hash'));

        self::assertSame('null', $json);
    }

    #[Test]
    public function anObjectAtDepthIsNeverDumped(): void
    {
        $json = new FilterRegistry()->apply(
            'php.json_encode',
            ['request' => ['theme' => new PublicAccount('ada@example.org', 'hash')], 'n' => 1],
        );

        self::assertIsString($json);
        self::assertStringNotContainsString('ada@example.org', $json);
        self::assertSame(['request' => ['theme' => null], 'n' => 1], json_decode($json, true));
    }

    #[Test]
    public function jsonSerializeIsNeverCalled(): void
    {
        $serializable = new class implements JsonSerializable {
            public bool $called = false;

            public function jsonSerialize(): mixed
            {
                $this->called = true;

                return ['secret' => 'from a method'];
            }
        };

        $json = new FilterRegistry()->apply('php.json_encode', $serializable);

        self::assertSame('null', $json);
        self::assertFalse($serializable->called, 'a template makes no method call through it');
    }

    #[Test]
    public function mapOfIt(): void
    {
        $mapped = new FilterRegistry()->apply('map', [new PublicAccount('a@example.org', 'h'), ['x' => 1]], ['php.json_encode']);

        self::assertSame(['null', '{"x":1}'], $mapped);
    }

    /** The control: values still encode as values. */
    #[Test]
    public function valuesStillEncode(): void
    {
        self::assertSame('{"a":[1,"two"]}', new FilterRegistry()->apply('php.json_encode', ['a' => [1, 'two']]));
        self::assertSame('"text"', new FilterRegistry()->apply('php.json_encode', 'text'));
    }

    /**
     * The safe list, pinned: a new member is classified before it joins. print_r, var_export and http_build_query
     * read an object's public properties as json_encode did, and could have joined without a word.
     */
    #[Test]
    public function theSafeListIsPinned(): void
    {
        $safe = new ReflectionClassConstant(FilterRegistry::class, 'SAFE_PHP_FUNCTIONS');

        self::assertSame([
            'strtoupper', 'strtolower', 'ucfirst', 'ucwords', 'trim', 'ltrim', 'rtrim',
            'strlen', 'str_repeat', 'wordwrap', 'nl2br', 'strip_tags',
            'htmlspecialchars', 'htmlentities', 'urlencode', 'rawurlencode', 'urldecode',
            'substr', 'mb_substr', 'mb_strtoupper', 'mb_strtolower',
            'str_replace', 'str_pad', 'strrev',
            'number_format', 'round', 'ceil', 'floor', 'abs', 'max', 'min',
            'count', 'implode', 'array_reverse', 'array_unique', 'array_keys', 'array_values',
            'date', 'strtotime',
            'json_encode', 'base64_encode',
        ], $safe->getValue(), 'a new safe PHP function is classified first: does it read an object?');
    }
}
