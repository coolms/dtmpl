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
 * Tests for the WidgetRendererInterface::__invoke contract integration.
 */
final class WidgetInvocationTest extends TestCase
{
    public function testWidgetRendererInvokeReturnsStringable(): void
    {
        $renderer = $this->makeStaticRenderer('hello', '<span>HELLO</span>');

        $result = $renderer(['ctx' => 'value'], ['param' => 'p']);

        self::assertInstanceOf(Stringable::class, $result);
        self::assertSame('<span>HELLO</span>', (string) $result);
    }

    public function testEngineRendersWidgetThroughInvokeContract(): void
    {
        $registry = new WidgetRegistry();
        $registry->register($this->makeStaticRenderer('greeting', '<em>hi</em>'));

        $engine = new DtmplEngine(widgets: $registry);

        self::assertSame(
            'before <em>hi</em> after',
            $engine->render('before {widget:greeting} after'),
        );
    }

    public function testWidgetReturningNullRendersAsEmptyString(): void
    {
        $renderer = new class implements WidgetRendererInterface {
            public string $key { get => 'maybenull'; }

            public function __invoke(array $context, array $params = []): ?Stringable
            {
                return null;
            }
        };
        $registry = new WidgetRegistry();
        $registry->register($renderer);

        $engine = new DtmplEngine(widgets: $registry);

        self::assertSame('before  after', $engine->render('before {widget:maybenull} after'));
    }

    public function testWidgetReturningStringableRendersNormally(): void
    {
        $renderer = new class implements WidgetRendererInterface {
            public string $key { get => 'statichtml'; }

            public function __invoke(array $context, array $params = []): Stringable
            {
                return new RenderedHtml('<span>hi</span>');
            }
        };
        $registry = new WidgetRegistry();
        $registry->register($renderer);

        $engine = new DtmplEngine(widgets: $registry);

        self::assertSame(
            'before <span>hi</span> after',
            $engine->render('before {widget:statichtml} after'),
        );
    }

    public function testEngineStringifiesWidgetViaValueToString(): void
    {
        // Renderer returns a Stringable subclass that is not RenderedHtml --
        // confirms valueToString (and the executeWidget cast) handle any
        // Stringable correctly, not just RenderedHtml specifically.
        $custom = new class('payload') implements Stringable {
            public function __construct(private string $payload)
            {
            }

            public function __toString(): string
            {
                return '[' . $this->payload . ']';
            }
        };
        $renderer = new class($custom) implements WidgetRendererInterface {
            public string $key { get => 'custom'; }

            public function __construct(private Stringable $value)
            {
            }

            public function __invoke(array $context, array $params = []): Stringable
            {
                return $this->value;
            }
        };
        $registry = new WidgetRegistry();
        $registry->register($renderer);

        $engine = new DtmplEngine(widgets: $registry);

        self::assertSame('[payload]', $engine->render('{widget:custom}'));
    }

    private function makeStaticRenderer(string $key, string $html): WidgetRendererInterface
    {
        return new class($key, $html) implements WidgetRendererInterface {
            public string $key { get => $this->keyValue; }

            public function __construct(
                private readonly string $keyValue,
                private readonly string $html,
            ) {
            }

            public function __invoke(array $context, array $params = []): Stringable
            {
                return new RenderedHtml($this->html);
            }
        };
    }
}
