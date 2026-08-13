<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\TemplateLoaderInterface;
use CoolMS\Dtmpl\Widget\WidgetRegistry;
use CoolMS\Dtmpl\Widget\WidgetRendererInterface;
use CoolMS\Dtmpl\Widget\WidgetView;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;

/**
 * The WidgetView seam: a widget returns a theme-partial path + data
 * (a view-model) instead of building an HTML string in PHP, and the
 * Executor renders that partial through the active theme's loader. This
 * is what lets widget renderers stay logic-only while the markup lives
 * in DTMPL (with any `<script>` in a `{raw}` block).
 */
final class WidgetViewTest extends TestCase
{
    #[Test]
    public function widgetViewRendersPartialWithItsData(): void
    {
        $loader = $this->loaderWith([
            'partials/greet.html.dtmpl' => 'Hello {var:name}!',
        ]);
        $engine = new DtmplEngine(
            loader: $loader,
            widgets: $this->registryWith($this->viewWidget('greet', 'partials/greet.html.dtmpl', ['name' => 'World'])),
        );

        self::assertSame('Hello World!', $engine->render('{widget:greet}'));
    }

    #[Test]
    public function widgetViewDataDrivesAPartialLoop(): void
    {
        $loader = $this->loaderWith([
            'partials/list.html.dtmpl' => '{loop:items:i}<li>{var:i.label}</li>{endloop}',
        ]);
        $data = ['items' => [['label' => 'a'], ['label' => 'b']]];
        $engine = new DtmplEngine(
            loader: $loader,
            widgets: $this->registryWith($this->viewWidget('list', 'partials/list.html.dtmpl', $data)),
        );

        self::assertSame('<li>a</li><li>b</li>', $engine->render('{widget:list}'));
    }

    #[Test]
    public function widgetViewPartialMayCarryARawScriptBlock(): void
    {
        // The combined payoff: a widget's view is a DTMPL partial whose
        // JS lives in a verbatim {raw} block -- no HTML/JS in PHP.
        $loader = $this->loaderWith([
            'partials/form.html.dtmpl' => '<form data-id="{var:id}"></form>{raw}<script>if(x){go({a:1})}</script>{endraw}',
        ]);
        $engine = new DtmplEngine(
            loader: $loader,
            widgets: $this->registryWith($this->viewWidget('form', 'partials/form.html.dtmpl', ['id' => 'contact'])),
        );

        self::assertSame(
            '<form data-id="contact"></form><script>if(x){go({a:1})}</script>',
            $engine->render('{widget:form}'),
        );
    }

    #[Test]
    public function widgetViewWithoutALoaderThrows(): void
    {
        $engine = new DtmplEngine(
            widgets: $this->registryWith($this->viewWidget('greet', 'partials/greet.html.dtmpl', [])),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rendering a WidgetView requires a TemplateLoader');
        $engine->render('{widget:greet}');
    }

    #[Test]
    public function widgetViewIsNotStringifiableDirectly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be rendered by the DTMPL Executor');
        (string) new WidgetView('partials/x.html.dtmpl');
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
