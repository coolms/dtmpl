<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Runtime\RenderedHtml;
use CoolMS\Dtmpl\Widget\WidgetRegistry;
use CoolMS\Dtmpl\Widget\WidgetRendererInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * End-to-end: a widget tag carrying a backtick-quoted id (e.g.
 * `{widget:form:`identity.verify_email_otp`}`) must dispatch through the
 * engine to the namespace-fallback renderer with the verbatim id forwarded
 * as `$params['_id']`. The quoted form is the escape hatch for ids the
 * bare-segment grammar can't express -- notably dots, which otherwise lex
 * as a DOT token and break `{widget:form:a.b}`.
 */
final class WidgetQuotedIdTest extends TestCase
{
    #[Test]
    public function engineForwardsDottedQuotedIdToNamespaceFallback(): void
    {
        $bucket = [];
        $renderer = $this->capturingRenderer('form', $bucket);

        $registry = new WidgetRegistry();
        $registry->register($renderer);
        $engine = new DtmplEngine(widgets: $registry);

        $rendered = $engine->render('before {widget:form:`identity.verify_email_otp`} after');

        self::assertSame('before <out data-id="identity.verify_email_otp"> after', $rendered);
        // $bucket is bound by-ref into the capturing renderer, so it mirrors the
        // captured params (asserting via the interface-typed $renderer would hide
        // the anon class's $bucket property from static analysis).
        self::assertCount(1, $bucket);
        self::assertSame('identity.verify_email_otp', $bucket[0]['_id'] ?? null);
    }

    #[Test]
    public function quotedIdCarriesTrailingParams(): void
    {
        $bucket = [];
        $renderer = $this->capturingRenderer('form', $bucket);

        $registry = new WidgetRegistry();
        $registry->register($renderer);
        $engine = new DtmplEngine(widgets: $registry);

        $engine->render('{widget:form:`identity.login` context=edit}');

        self::assertSame('identity.login', $bucket[0]['_id'] ?? null);
        self::assertSame('edit', $bucket[0]['context'] ?? null);
    }

    /**
     * @param array<int, array<string, mixed>> $bucket
     */
    private function capturingRenderer(string $key, array &$bucket): WidgetRendererInterface
    {
        return new class($key, $bucket) implements WidgetRendererInterface {
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

                return new RenderedHtml('<out data-id="' . ($params['_id'] ?? '') . '">');
            }
        };
    }
}
