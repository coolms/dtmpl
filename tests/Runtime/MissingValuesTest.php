<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Loader\FilesystemTemplateLoader;
use CoolMS\Dtmpl\Runtime\MissingValues;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An engine given a collector records every variable a render prints with no value and no default of its own:
 * wherever it is printed -- the branch taken, each loop item, an include -- and only there. What it renders does not
 * change, and an engine given none records nothing.
 */
final class MissingValuesTest extends TestCase
{
    private string $dir;

    #[Test]
    public function aPrintedVariableWithNoValueIsRecordedAndStillRendersAsBefore(): void
    {
        $missing = new MissingValues();
        $engine = new DtmplEngine()->withMissingValues($missing);

        $out = $engine->render('Dear {var:name}, total {var:total}.', ['total' => 12]);

        self::assertSame('Dear , total 12.', $out, 'the render itself is unchanged');
        self::assertSame([['path' => 'name', 'template' => '', 'loops' => []]], $missing->all());
    }

    #[Test]
    public function anEmptyStringABlankOneAndAnEmptyListAreNoValueButZeroAndFalseAreValues(): void
    {
        $missing = new MissingValues();
        new DtmplEngine()->withMissingValues($missing)->render(
            '{var:empty}{var:blank}{var:none}{var:zero}{var:no}',
            ['empty' => '', 'blank' => '  ', 'none' => [], 'zero' => 0, 'no' => false],
        );

        self::assertSame(['empty', 'blank', 'none'], array_column($missing->all(), 'path'));
    }

    #[Test]
    public function aDefaultOfItsOwnIsAFallbackAndAnAssignmentSetsAValue(): void
    {
        $missing = new MissingValues();
        new DtmplEngine()->withMissingValues($missing)->render(
            '{var:a default:`none`}{var:b:default=`Guest`}{def:c=`set`}{var:c}',
            [],
        );

        self::assertTrue($missing->isEmpty(), implode(', ', array_column($missing->all(), 'path')));
    }

    #[Test]
    public function onlyTheBranchTakenIsRecorded(): void
    {
        $missing = new MissingValues();
        new DtmplEngine()->withMissingValues($missing)->render(
            '{if:vip}{var:discount}{else}{var:regular}{endif}',
            ['vip' => false],
        );

        self::assertSame(['regular'], array_column($missing->all(), 'path'), 'the branch not taken prints nothing');
    }

    #[Test]
    public function aLineItemWithNoValueIsRecordedWithTheItemItIsIn(): void
    {
        $missing = new MissingValues();
        new DtmplEngine()->withMissingValues($missing)->render(
            '{loop:lines:line}{var:line.description}: {var:line.amount}; {endloop}',
            ['lines' => [
                ['description' => 'Consulting', 'amount' => '1200.00'],
                ['description' => 'Licence', 'amount' => null],
                ['description' => '', 'amount' => '80.00'],
            ]],
        );

        self::assertSame([
            ['path' => 'line.amount', 'template' => '', 'loops' => [['path' => 'lines', 'index' => 1, 'key' => 1]]],
            [
                'path' => 'line.description',
                'template' => '',
                'loops' => [['path' => 'lines', 'index' => 2, 'key' => 2]],
            ],
        ], $missing->all());
    }

    #[Test]
    public function aNestedLoopRecordsEveryItemAroundIt(): void
    {
        $missing = new MissingValues();
        new DtmplEngine()->withMissingValues($missing)->render(
            '{loop:sections:section}{loop:section.lines:line}{var:line.note}{endloop}{endloop}',
            ['sections' => [['lines' => [['note' => 'ok']]], ['lines' => [['note' => 'ok'], ['note' => null]]]]],
        );

        self::assertSame(
            [['path' => 'sections', 'index' => 1, 'key' => 1], ['path' => 'section.lines', 'index' => 1, 'key' => 1]],
            $missing->all()[0]['loops'] ?? null,
        );
        self::assertCount(1, $missing->all());
    }

    #[Test]
    public function aVariableAnIncludePrintsIsRecordedWithTheIncludedTemplate(): void
    {
        file_put_contents($this->dir . '/footer.dtmpl', 'Registered as {var:company.registration}');
        $missing = new MissingValues();
        $engine = new DtmplEngine(loader: new FilesystemTemplateLoader($this->dir))->withMissingValues($missing);

        $engine->render('{include:`footer.dtmpl`}', ['company' => []], $this->dir . '/invoice.dtmpl');

        self::assertSame(['company.registration'], array_column($missing->all(), 'path'));
        self::assertStringEndsWith('footer.dtmpl', $missing->all()[0]['template']);
    }

    #[Test]
    public function anEngineGivenNoCollectorRendersTheSameAndRecordsNothing(): void
    {
        $template = '{loop:lines:line}{var:line.amount};{endloop}{var:name}';
        $data = ['lines' => [['amount' => null]]];
        $missing = new MissingValues();

        $plain = new DtmplEngine()->render($template, $data);
        $collected = new DtmplEngine()->withMissingValues($missing)->render($template, $data);

        self::assertSame($plain, $collected, 'off or on, the same output');
        self::assertCount(2, $missing->all());
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dtmpl_missing_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }
}
