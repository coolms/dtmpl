<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\ValueObject;

/**
 * One `{loop:path:alias}` block. Iterable path + the per-item alias
 * the body uses. Surfacing this lets the UI render "iterates over
 * `items`" and group variables by their loop scope.
 */
final readonly class ContextSchemaLoop
{
    public function __construct(
        public string $path,
        public string $alias,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'alias' => $this->alias,
        ];
    }
}
