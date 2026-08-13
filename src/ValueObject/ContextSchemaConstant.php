<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\ValueObject;

/**
 * One `{const:foo}` reference found in a template. Constants are
 * platform-injected at render time; recording them lets the UI show
 * "this template depends on `currentDate`, `siteName`" without forcing
 * the user to learn DTMPL syntax.
 */
final readonly class ContextSchemaConstant
{
    public function __construct(
        public string $name,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
        ];
    }
}
