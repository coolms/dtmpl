<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Widget;

use Closure;
use CoolMS\Dtmpl\Widget\WidgetRegistry;
use CoolMS\Dtmpl\Widget\WidgetRendererInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * How MANY renderers a lookup builds.
 *
 * The registry could always answer a lookup correctly; what it could not do was
 * answer one without building every renderer registered, because a renderer's
 * key could only be read off a built instance. These tests assert on the count
 * of constructions, not on the answer -- the answer was never wrong.
 *
 * {@see testUnkeyedRegistrationStillBuildsEveryRenderer} is the control, and it
 * fails in the opposite direction: if `registerKeyed` quietly degraded to the
 * un-keyed path the first test would see 3 instead of 1, and if the un-keyed
 * path were accidentally made lazy per key the control would see 1 instead of 3.
 * A control that shares the failure mode of the thing it guards is not one.
 */
final class WidgetRegistryKeyedTest extends TestCase
{
    public function testKeyedLookupBuildsOnlyTheRendererItMatched(): void
    {
        $built = [];
        $registry = new WidgetRegistry();
        foreach (['alpha', 'beta', 'gamma'] as $key) {
            $registry->registerKeyed($key, $this->countingFactory($key, $built));
        }

        $renderer = $registry->get('beta', null);

        self::assertSame('beta', $renderer->key);
        self::assertSame(['beta'], $built);
    }

    public function testUnkeyedRegistrationStillBuildsEveryRenderer(): void
    {
        $built = [];
        $registry = new WidgetRegistry();
        foreach (['alpha', 'beta', 'gamma'] as $key) {
            $registry->registerLazy($this->countingFactory($key, $built));
        }

        $renderer = $registry->get('beta', null);

        self::assertSame('beta', $renderer->key);
        sort($built);
        self::assertSame(['alpha', 'beta', 'gamma'], $built);
    }

    public function testHasAndIsExactMatchBuildNothing(): void
    {
        $built = [];
        $registry = new WidgetRegistry();
        $registry->registerKeyed('alpha', $this->countingFactory('alpha', $built));
        $registry->registerKeyed('nav:menu', $this->countingFactory('nav:menu', $built));

        self::assertTrue($registry->has('alpha', null));
        self::assertTrue($registry->has('nav', 'menu'));
        self::assertTrue($registry->isExactMatch('nav', 'menu'));
        self::assertFalse($registry->isExactMatch('alpha', 'missing'));

        self::assertSame([], $built, 'answering has()/isExactMatch() must not construct a renderer');
    }

    public function testNamespaceFallbackBuildsOnlyTheCatchAll(): void
    {
        $built = [];
        $registry = new WidgetRegistry();
        $registry->registerKeyed('form', $this->countingFactory('form', $built));
        $registry->registerKeyed('media', $this->countingFactory('media', $built));

        // {widget:form:login} has no exact renderer; 'form' is the catch-all.
        self::assertTrue($registry->has('form', 'login'));
        self::assertFalse($registry->isExactMatch('form', 'login'));
        self::assertSame('form', $registry->get('form', 'login')->key);

        self::assertSame(['form'], $built);
    }

    public function testAMissFallsBackToTheUnkeyedRenderers(): void
    {
        $built = [];
        $registry = new WidgetRegistry();
        $registry->registerKeyed('alpha', $this->countingFactory('alpha', $built));
        $registry->registerLazy($this->countingFactory('zeta', $built));

        self::assertTrue($registry->has('zeta', null));
        self::assertSame('zeta', $registry->get('zeta', null)->key);

        // 'alpha' answered nothing here, so it was never built.
        self::assertSame(['zeta'], $built);
    }

    public function testUnknownWidgetThrows(): void
    {
        $registry = new WidgetRegistry();
        $built = [];
        $registry->registerKeyed('alpha', $this->countingFactory('alpha', $built));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Widget not found: nope');

        $registry->get('nope', null);
    }

    public function testLaterRegistrationWins(): void
    {
        $built = [];
        $registry = new WidgetRegistry();
        $registry->register($this->makeRenderer('alpha', 'first'));
        $registry->registerKeyed('alpha', $this->countingFactory('alpha', $built, 'second'));

        self::assertSame('second', (string) $registry->get('alpha', null)([], []));
        self::assertSame(['alpha'], $built);
    }

    public function testARendererIsBuiltOnceAcrossRepeatedLookups(): void
    {
        $built = [];
        $registry = new WidgetRegistry();
        $registry->registerKeyed('alpha', $this->countingFactory('alpha', $built));

        $registry->get('alpha', null);
        $registry->get('alpha', null);
        $registry->has('alpha', null);

        self::assertSame(['alpha'], $built);
    }

    /**
     * @param list<string> $built
     *
     * @return Closure(): WidgetRendererInterface
     */
    private function countingFactory(string $key, array &$built, string $output = 'x'): Closure
    {
        return function () use ($key, &$built, $output): WidgetRendererInterface {
            $built[] = $key;

            return $this->makeRenderer($key, $output);
        };
    }

    private function makeRenderer(string $key, string $output): WidgetRendererInterface
    {
        return new class($key, $output) implements WidgetRendererInterface {
            public function __construct(
                private readonly string $keyValue,
                private readonly string $output,
            ) {
            }

            public string $key { get => $this->keyValue; }

            public function __invoke(array $context, array $params = []): Stringable
            {
                $out = $this->output;

                return new class($out) implements Stringable {
                    public function __construct(private readonly string $out)
                    {
                    }

                    public function __toString(): string
                    {
                        return $this->out;
                    }
                };
            }
        };
    }
}
