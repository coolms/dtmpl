<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Runtime\ConstantProviderInterface;
use CoolMS\Dtmpl\Runtime\PatternRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the {const:name} DTMPL tag and related infrastructure.
 */
class ConstTagTest extends TestCase
{
    private DtmplEngine $engine;

    /**
     * {const:name} renders a string constant correctly.
     */
    public function testConstRendersStringValue(): void
    {
        $result = $this->engine->render('Welcome to {const:siteName}!');

        $this->assertSame('Welcome to TestSite!', $result);
    }

    /**
     * {const:name} renders an integer constant as a plain string.
     */
    public function testConstRendersInteger(): void
    {
        $result = $this->engine->render('Copyright {const:currentYear}');

        $this->assertSame('Copyright 2026', $result);
    }

    /**
     * {const:name} returns empty string silently for unknown constant names.
     */
    public function testConstRendersEmptyWhenUnknown(): void
    {
        $result = $this->engine->render('Value: [{const:doesNotExist}]');

        $this->assertSame('Value: []', $result);
    }

    /**
     * {const:name} cannot be overridden by a template-level {def:} assignment.
     *
     * A {def:} tag writes to the regular scope, not to the reserved '_const' key,
     * so {const:siteName} must still return the provider-supplied value.
     */
    public function testConstNotOverridableByDef(): void
    {
        $template = '{def:siteName=`HackedSite`}{const:siteName}';
        $result = $this->engine->render($template);

        $this->assertSame('TestSite', $result);
    }

    /**
     * PatternRenderer substitutes {const:name} tokens from a plain context array.
     *
     * This covers the VFS naming-pattern use-case where the full DTMPL engine
     * is not involved.
     */
    public function testConstInNamingPattern(): void
    {
        $renderer = new PatternRenderer();
        $ctx = ['date' => '2026-03-16', 'counter' => 3, 'basename' => 'logo'];

        $this->assertSame(
            'logo_2026-03-16-3',
            $renderer->render('{const:basename}_{const:date}-{const:counter}', $ctx),
        );

        // Unknown tokens survive untouched.
        $this->assertSame(
            'file_{const:unknown}.txt',
            $renderer->render('file_{const:unknown}.txt', $ctx),
        );
    }

    protected function setUp(): void
    {
        $this->engine = new DtmplEngine();

        // Register a test constant provider with known values.
        $provider = new class implements ConstantProviderInterface {
            public function getConstants(): array
            {
                return [
                    'siteName' => 'TestSite',
                    'currentYear' => 2026,
                    'isActive' => true,
                    'pi' => 3.14,
                ];
            }
        };

        $this->engine->setConstantProviders([$provider]);
    }
}
