<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Runtime\RenderedHtml;
use CoolMS\Dtmpl\Widget\WidgetRegistry;
use CoolMS\Dtmpl\Widget\WidgetRendererInterface;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * End-to-end: a widget tag carrying a UUID-form id with dashes
 * (e.g., `{widget:media:019d3dbc-45e8-7e01-aaad-8aa3db96e95c}`) must
 * dispatch through the engine to the namespace-fallback renderer with
 * the UUID forwarded as `$params['_id']`. Regression: the lexer used
 * to reject the first `-` because `scanIdentifier` only accepted
 * `[a-zA-Z0-9_]`.
 */
final class WidgetUuidIdTest extends TestCase
{
    public function testEngineDispatchesDashedUuidWidgetIdToNamespaceFallback(): void
    {
        $uuid = '019d3dbc-45e8-7e01-aaad-8aa3db96e95c';
        $captured = [];
        $renderer = new class('media', $captured) implements WidgetRendererInterface {
            public string $key { get => $this->keyValue; }

            /**
             * @param array<int, array<string, mixed>> $bucket
             */
            public function __construct(
                private readonly string $keyValue,
                public array &$bucket,
            ) {
            }

            public function __invoke(array $context, array $params = []): Stringable
            {
                $this->bucket[] = $params;

                return new RenderedHtml('<img data-id="' . ($params['_id'] ?? '') . '">');
            }
        };

        $registry = new WidgetRegistry();
        $registry->register($renderer);
        $engine = new DtmplEngine(widgets: $registry);

        $rendered = $engine->render(sprintf('before {widget:media:%s size=small} after', $uuid));

        self::assertSame(
            sprintf('before <img data-id="%s"> after', $uuid),
            $rendered,
        );
        self::assertCount(1, $renderer->bucket);
        self::assertSame($uuid, $renderer->bucket[0]['_id'] ?? null);
        self::assertSame('small', $renderer->bucket[0]['size'] ?? null);
    }
}
