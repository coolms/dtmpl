<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\TemplateLoaderInterface;
use CoolMS\Dtmpl\Widget\WidgetRegistry;
use CoolMS\Dtmpl\Widget\WidgetRendererInterface;
use CoolMS\Dtmpl\Widget\WidgetView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;

/**
 * WidgetView data-unwrap on `{def}`-bind.
 *
 * A widget that returns a {@see WidgetView} is "data + optional view". When
 * bound with `{def:x=widget:...}`, the SAME name behaves two ways with no
 * ambiguity:
 *   - `{var:x}` still stringifies to the rendered partial HTML (unchanged);
 *   - `{loop:x:i}` iterates the view's data (its `items` list);
 *   - `{var:x.field}` reads a data field.
 * This is what makes a data-widget like `widget:vfs` consumable in a template.
 */
final class WidgetDataUnwrapTest extends TestCase
{
    #[Test]
    public function boundWidgetStillStringifiesToRenderedHtml(): void
    {
        // Backward-compatible: {var:x} on a {def}-bound widget renders the
        // partial, exactly as the previous RenderedHtml bind did.
        $engine = new DtmplEngine(
            loader: $this->loaderWith(['partials/greet.html.dtmpl' => 'Hello {var:name}!']),
            widgets: $this->registryWith($this->viewWidget('greet', 'partials/greet.html.dtmpl', ['name' => 'World'])),
        );

        self::assertSame('Hello World!', $engine->render('{def:g=widget:greet}{var:g}'));
    }

    #[Test]
    public function boundWidgetLoopIteratesItsItemsList(): void
    {
        // The net-new semantic: {loop:x:i} reaches into the widget's data.
        $data = ['items' => [['title' => 'A'], ['title' => 'B'], ['title' => 'C']]];
        $engine = new DtmplEngine(
            loader: $this->loaderWith(['partials/list.html.dtmpl' => '']),
            widgets: $this->registryWith($this->viewWidget('vfs', 'partials/list.html.dtmpl', $data)),
        );

        self::assertSame(
            '[A][B][C]',
            $engine->render('{def:articles=widget:vfs}{loop:articles:a}[{var:a.title}]{endloop}'),
        );
    }

    #[Test]
    public function boundWidgetExposesTopLevelDataFields(): void
    {
        // {var:x.field} reads a single-record data-widget's fields via the
        // Executor's PropertyAccessor magic-get on the WidgetResult.
        $data = ['title' => 'Tech News', 'count' => 42];
        $engine = new DtmplEngine(
            loader: $this->loaderWith(['partials/x.html.dtmpl' => '']),
            widgets: $this->registryWith($this->viewWidget('vfs', 'partials/x.html.dtmpl', $data)),
        );

        self::assertSame(
            'Tech News/42',
            $engine->render('{def:d=widget:vfs}{var:d.title}/{var:d.count}'),
        );
    }

    #[Test]
    public function boundWidgetWithNoItemsIteratesTheDataMap(): void
    {
        // Fallback: no `items` key -> iterate the data map as key/value pairs.
        $data = ['a' => '1', 'b' => '2'];
        $engine = new DtmplEngine(
            loader: $this->loaderWith(['partials/x.html.dtmpl' => '']),
            widgets: $this->registryWith($this->viewWidget('vfs', 'partials/x.html.dtmpl', $data)),
        );

        self::assertSame(
            'a=1;b=2;',
            $engine->render('{def:d=widget:vfs}{loop:d:row}{var:row.key}={var:row.value};{endloop}'),
        );
    }

    #[Test]
    public function inlineWidgetConcatStillStringifiesHtml(): void
    {
        // The inline {widget:...} concat path is unchanged: it stringifies.
        $engine = new DtmplEngine(
            loader: $this->loaderWith(['partials/greet.html.dtmpl' => 'Hi {var:name}']),
            widgets: $this->registryWith($this->viewWidget('greet', 'partials/greet.html.dtmpl', ['name' => 'Ada'])),
        );

        self::assertSame('<p>Hi Ada</p>', $engine->render('<p>{widget:greet}</p>'));
    }

    /** @param array<string, string> $map path → source */
    private function loaderWith(array $map): TemplateLoaderInterface
    {
        return new class($map) implements TemplateLoaderInterface {
            /** @param array<string, string> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function load(string $path, string $basePath = ''): string
            {
                return $this->map[$path] ?? throw new RuntimeException("no such partial: $path");
            }

            public function resolve(string $path, string $basePath = ''): string
            {
                return $path;
            }
        };
    }

    private function registryWith(WidgetRendererInterface $renderer): WidgetRegistry
    {
        $registry = new WidgetRegistry();
        $registry->register($renderer);

        return $registry;
    }

    /** @param array<string, mixed> $data */
    private function viewWidget(string $key, string $template, array $data): WidgetRendererInterface
    {
        return new class($key, $template, $data) implements WidgetRendererInterface {
            public string $key { get => $this->keyValue; }

            /** @param array<string, mixed> $data */
            public function __construct(
                private readonly string $keyValue,
                private readonly string $template,
                private readonly array $data,
            ) {
            }

            public function __invoke(array $context, array $params = []): Stringable
            {
                return new WidgetView($this->template, $this->data);
            }
        };
    }
}
