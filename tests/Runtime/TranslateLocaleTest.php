<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\DtmplEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * `{t:}` renders in the locale the RENDER is for, not the ambient one.
 *
 * This is what makes a themeable email layout translatable at all: an email is
 * composed in a message handler, where the translator's ambient locale is the
 * system default rather than anything to do with the recipient. Before this the
 * tag existed but every recipient got the same language.
 */
final class TranslateLocaleTest extends TestCase
{
    #[Test]
    public function rendersInTheLocaleTheRenderContextCarries(): void
    {
        $out = $this->engine()->render('{t:`greeting`:`mail`}', ['locale' => 'uk']);

        self::assertSame('Вітаємо', $out);
    }

    #[Test]
    public function fallsBackToTheAmbientLocaleWhenTheRenderNamesNone(): void
    {
        // SSR renders inside a request, where the ambient locale IS the right
        // answer -- that path must keep working untouched.
        self::assertSame('Hello', $this->engine()->render('{t:`greeting`:`mail`}'));
    }

    #[Test]
    public function anEmptyOrNonStringLocaleIsTreatedAsAbsent(): void
    {
        // A context that carries the key but not a usable value must not be read
        // as "translate into nothing" -- Symfony would reject an empty locale.
        self::assertSame('Hello', $this->engine()->render('{t:`greeting`:`mail`}', ['locale' => '']));
        self::assertSame('Hello', $this->engine()->render('{t:`greeting`:`mail`}', ['locale' => ['uk']]));
    }

    #[Test]
    public function placeholdersSurviveTheLocaleSelection(): void
    {
        // The `%name%` bridging and the locale argument are applied in the same
        // call; a regression in either would show up as an unsubstituted token.
        $out = $this->engine()->render('{t:`bye`:`mail` name=who}', ['locale' => 'uk', 'who' => 'Ada']);

        self::assertSame('Бувай, Ada', $out);
    }

    private function engine(): DtmplEngine
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['greeting' => 'Hello', 'bye' => 'Bye, %name%'], 'en', 'mail');
        $translator->addResource('array', ['greeting' => 'Вітаємо', 'bye' => 'Бувай, %name%'], 'uk', 'mail');

        return new DtmplEngine(translator: $translator);
    }
}
