<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

/**
 * Pattern Renderer.
 *
 * Renders naming-pattern strings that use {const:name} tokens.
 * Used for VFS conflict resolution (rename suffixes), form field name
 * generation, and any other place where a lightweight string interpolation
 * with platform constants is needed -- without a full DTMPL template context.
 *
 * Pattern examples:
 *   report_{const:date}-{const:counter}  renders as  report_2026-03-16-1
 *   upload_{const:datetime}              renders as  upload_2026-03-16_14-05-22
 *   {const:basename}_{const:random}      renders as  logo_a3f7c2
 */
final readonly class PatternRenderer
{
    /**
     * Render a pattern by substituting {const:name} tokens from $context.
     * Unknown tokens are left as-is in the output.
     *
     * @param array<string, mixed> $context
     */
    public function render(string $pattern, array $context): string
    {
        return preg_replace_callback(
            '/\{const:([^}]+)\}/',
            static fn (array $m) => isset($context[$m[1]]) ? (string) $context[$m[1]] : $m[0],
            $pattern,
        ) ?? $pattern;
    }

    /**
     * Extract all token names from a pattern (without braces or 'const:' prefix).
     *
     * @return string[]
     */
    public function extractTokens(string $pattern): array
    {
        preg_match_all('/\{const:([^}]+)\}/', $pattern, $matches);

        return array_unique($matches[1]);
    }

    /**
     * Build a standard VFS naming context.
     * Provides tokens useful for file conflict resolution and upload naming.
     *
     * @return array<string, string|int>
     */
    public function buildVfsContext(int $counter = 1): array
    {
        return [
            'counter' => $counter,
            'date' => date('Y-m-d'),
            'datetime' => date('Y-m-d_H-i-s'),
            'random' => substr(bin2hex(random_bytes(3)), 0, 6),
            'basename' => '', // filled per-file by the caller
        ];
    }
}
