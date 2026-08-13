<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\ValueObject;

/**
 * One `{if:path}` / `{unless:path}` block. The path's truthiness drives
 * the branch; `negate=true` represents the `{unless:}` (or
 * `{if:!path}`) flavour so callers can render UI hints accurately.
 */
final readonly class ContextSchemaConditional
{
    public function __construct(
        public string $path,
        public bool $negate = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'negate' => $this->negate,
        ];
    }
}
