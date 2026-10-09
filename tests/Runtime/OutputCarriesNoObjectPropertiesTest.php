<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\Runtime\FilterRegistry;
use CoolMS\Dtmpl\Runtime\Output;
use CoolMS\Dtmpl\Tests\Runtime\Fixture\Named;
use CoolMS\Dtmpl\Tests\Runtime\Fixture\Plain;
use CoolMS\Dtmpl\Tests\Runtime\Fixture\PublicAccount;
use CoolMS\Dtmpl\Tests\Runtime\Fixture\Status;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Printing a value and the json filter take arrays and scalars only: an object becomes its date text, its enum
 * value or its string form, or nothing -- never a dump of its public properties, which would read around every limit
 * on what a template may read of it.
 */
final class OutputCarriesNoObjectPropertiesTest extends TestCase
{
    #[Test]
    public function printingAnObjectNeverDumpsItsProperties(): void
    {
        $account = new PublicAccount('ada@example.org', 'hash');

        self::assertSame('', Output::stringify($account));
        self::assertSame('[null,"x"]', Output::stringify([$account, 'x']));
    }

    #[Test]
    public function theJsonFilterNeverDumpsAnObjectsProperties(): void
    {
        $json = new FilterRegistry()->apply('json', ['who' => new PublicAccount('ada@example.org', 'hash'), 'n' => 1]);

        self::assertIsString($json);
        self::assertStringNotContainsString('ada@example.org', $json);
        self::assertStringNotContainsString('hash', $json);
        self::assertSame(['who' => null, 'n' => 1], json_decode($json, true));
        self::assertSame('null', new FilterRegistry()->apply('json', new PublicAccount('a', 'b')));
    }

    #[Test]
    public function valuesStillPrintAsValues(): void
    {
        self::assertSame('2026-10-09 00:00:00', Output::stringify(new DateTimeImmutable('2026-10-09')));
        self::assertSame('ready', Output::stringify(Status::Ready));
        self::assertSame('Draft', Output::stringify(Plain::Draft));
        self::assertSame('["ready","2026-10-09 00:00:00"]', Output::stringify([Status::Ready, new DateTimeImmutable('2026-10-09')]));
        self::assertSame('named', Output::stringify(new Named()));
    }
}
