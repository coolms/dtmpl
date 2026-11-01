<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Runtime\FilterRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `pluck:key` takes the field of every element, as `array_column` does, read the way a template reads a segment: an
 * array by its key, an object through its property or getter -- and a secret never, through `pluck` as through
 * `{var:}`. An element without the field is left out; anything that is not a list, or no key, gives nothing.
 */
final class PluckFilterTest extends TestCase
{
    #[Test]
    public function theFieldOfEveryElementIsTakenAsATemplateReadsIt(): void
    {
        $pages = [
            ['title' => 'Home'],
            new PluckedPage('Products'),
            new PluckedGetterPage('Chairs'),
            ['slug' => 'no-title'],
        ];

        self::assertSame(['Home', 'Products', 'Chairs'], $this->pluck($pages, 'title'), 'array, property, getter');
    }

    #[Test]
    public function aSecretIsNeverTakenThroughPluckAsNeverThroughVar(): void
    {
        $accounts = [new PluckedAccount('ann', 'hash-a'), new PluckedAccount('bob', 'hash-b')];

        self::assertSame(['ann', 'bob'], $this->pluck($accounts, 'name'), 'the subject: the field next to it reads');
        self::assertSame([], $this->pluck($accounts, 'passwordHash'), 'the secret reads as a miss');
        self::assertSame(
            '',
            new DtmplEngine()->render('{var:account.passwordHash}', ['account' => $accounts[0]]),
            'as through {var:}',
        );
    }

    #[Test]
    public function notAListOrNoKeyGivesNothing(): void
    {
        self::assertSame([], $this->pluck('a string', 'title'));
        self::assertSame([], $this->pluck([['title' => 'x']], ''));
    }

    #[Test]
    public function theBreadcrumbTitleReadsInOneExpression(): void
    {
        $page = ['title' => 'Chairs'];
        $ancestors = [['title' => 'Products'], ['title' => 'Home']];

        self::assertSame(
            'Chairs — Home — Products',
            new DtmplEngine()->render(
                '{var:page.title} — {var:ancestors reverse pluck:`title` join:` — `}',
                ['page' => $page, 'ancestors' => $ancestors],
            ),
        );
    }

    /** @return array<mixed> */
    private function pluck(mixed $value, string $key): array
    {
        $result = new FilterRegistry()->apply('pluck', $value, [$key]);
        self::assertIsArray($result);

        return $result;
    }
}

final class PluckedPage
{
    public function __construct(public string $title)
    {
    }
}

final class PluckedGetterPage
{
    public function __construct(private string $title)
    {
    }

    public function getTitle(): string
    {
        return $this->title;
    }
}

final class PluckedAccount
{
    public function __construct(public string $name, public string $passwordHash)
    {
    }
}
