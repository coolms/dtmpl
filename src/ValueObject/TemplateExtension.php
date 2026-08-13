<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\ValueObject;

/**
 * Template Extension.
 *
 * Represents a single file extension that can be templated
 */
final readonly class TemplateExtension
{
    public function __construct(
        public string $baseExtension,
        public string $mimeType,
    ) {
    }
}
