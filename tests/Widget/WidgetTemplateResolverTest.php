<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Widget;

use CoolMS\Dtmpl\Widget\WidgetTemplateResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The config-backed widget→partial resolver: a configured key wins, an absent or
 * blank entry falls through to the renderer's built-in default.
 */
#[CoversClass(WidgetTemplateResolver::class)]
final class WidgetTemplateResolverTest extends TestCase
{
    public function testConfiguredKeyOverridesTheDefault(): void
    {
        $resolver = new WidgetTemplateResolver([
            'comments' => 'theme/custom/comments.html.dtmpl',
        ]);

        self::assertSame(
            'theme/custom/comments.html.dtmpl',
            $resolver->resolve('comments', 'partials/widgets/comments.html.dtmpl'),
        );
    }

    public function testUnconfiguredKeyFallsBackToTheDefault(): void
    {
        $resolver = new WidgetTemplateResolver(['comments' => 'x.dtmpl']);

        self::assertSame(
            'partials/widgets/media.html.dtmpl',
            $resolver->resolve('media', 'partials/widgets/media.html.dtmpl'),
        );
    }

    public function testEmptyOverrideStringFallsBackToTheDefault(): void
    {
        $resolver = new WidgetTemplateResolver(['embed.audio' => '']);

        self::assertSame(
            'partials/widgets/embed_audio.html.dtmpl',
            $resolver->resolve('embed.audio', 'partials/widgets/embed_audio.html.dtmpl'),
        );
    }

    public function testEmptyMapAlwaysYieldsTheDefault(): void
    {
        $resolver = new WidgetTemplateResolver();

        self::assertSame('d.dtmpl', $resolver->resolve('anything', 'd.dtmpl'));
    }

    public function testSubKeyIsResolvedIndependentlyOfItsParent(): void
    {
        $resolver = new WidgetTemplateResolver([
            'embed' => 'theme/embed.dtmpl',
            'embed.audio_file' => 'theme/embed_file.dtmpl',
        ]);

        self::assertSame('theme/embed.dtmpl', $resolver->resolve('embed', 'def_embed'));
        self::assertSame('theme/embed_file.dtmpl', $resolver->resolve('embed.audio_file', 'def_file'));
        // A sub-key with no override still falls through, unaffected by `embed`.
        self::assertSame('def_audio', $resolver->resolve('embed.audio', 'def_audio'));
    }
}
