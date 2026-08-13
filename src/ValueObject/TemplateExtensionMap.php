<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\ValueObject;

use InvalidArgumentException;

/**
 * Template Extension Map.
 *
 * Defines which file extensions can have template versions (.dtmpl suffix).
 * For example:
 *   - .html -- .html.dtmpl
 *   - .md   -- .md.dtmpl
 *   - .xml  -- .xml.dtmpl
 *
 * The map also stores MIME types for proper Content-Type headers.
 */
final readonly class TemplateExtensionMap
{
    /**
     * @param array<string, TemplateExtension> $extensions Indexed by base extension (e.g., 'html', 'md')
     */
    public function __construct(
        private array $extensions = [],
    ) {
    }

    /**
     * Create from configuration array.
     *
     * @param array<string, array{mime_type: string, enabled?: bool}> $config
     */
    public static function fromConfig(array $config): self
    {
        $extensions = [];

        foreach ($config as $ext => $settings) {
            $ext = ltrim($ext, '.');

            // @phpstan-ignore isset.offset
            if (!isset($settings['mime_type'])) {
                throw new InvalidArgumentException("Missing 'mime_type' for extension: $ext");
            }

            $enabled = $settings['enabled'] ?? true;

            if ($enabled) {
                $extensions[$ext] = new TemplateExtension(
                    baseExtension: $ext,
                    mimeType: $settings['mime_type'],
                );
            }
        }

        return new self($extensions);
    }

    /**
     * Check if an extension supports templates.
     */
    public function supports(string $extension): bool
    {
        $extension = ltrim($extension, '.');

        return isset($this->extensions[$extension]);
    }

    /**
     * Get template extension for base extension
     * e.g., 'html' -- 'html.dtmpl'.
     */
    public function getTemplateExtension(string $baseExtension): string
    {
        $baseExtension = ltrim($baseExtension, '.');

        if (!$this->supports($baseExtension)) {
            throw new InvalidArgumentException("Extension '$baseExtension' does not support templates");
        }

        return $baseExtension . '.dtmpl';
    }

    /**
     * Get MIME type for extension.
     */
    public function getMimeType(string $extension): string
    {
        $extension = ltrim($extension, '.');

        if (!isset($this->extensions[$extension])) {
            throw new InvalidArgumentException("Unknown extension: $extension");
        }

        return $this->extensions[$extension]->mimeType;
    }

    /**
     * Check if a filename is a template file
     * e.g., 'index.html.dtmpl' -- true.
     */
    public function isTemplateFile(string $filename): bool
    {
        return str_ends_with($filename, '.dtmpl');
    }

    /**
     * Get the base extension from a template filename
     * e.g., 'index.html.dtmpl' -- 'html'.
     */
    public function getBaseExtension(string $templateFilename): ?string
    {
        if (!$this->isTemplateFile($templateFilename)) {
            return null;
        }

        // Remove .dtmpl suffix
        $withoutDtmpl = substr($templateFilename, 0, -6);

        // Get the last extension
        $parts = explode('.', $withoutDtmpl);
        if (count($parts) < 2) {
            return null;
        }

        return end($parts);
    }

    /**
     * Get the target filename (without .dtmpl)
     * e.g., 'index.html.dtmpl' -- 'index.html'.
     */
    public function getTargetFilename(string $templateFilename): ?string
    {
        if (!$this->isTemplateFile($templateFilename)) {
            return null;
        }

        return substr($templateFilename, 0, -6);
    }

    /**
     * Get all supported extensions.
     *
     * @return string[]
     */
    public function getSupportedExtensions(): array
    {
        return array_keys($this->extensions);
    }

    /**
     * Try to find template version of a requested file.
     *
     * @return string|null Template path if found, null otherwise
     */
    public function findTemplatePath(string $requestedPath): ?string
    {
        // Extract extension from requested path
        $parts = explode('.', $requestedPath);

        if (count($parts) < 2) {
            return null;
        }

        $extension = array_last($parts);

        // Check if this extension supports templates
        if (!$this->supports($extension)) {
            return null;
        }

        // Return potential template path
        return $requestedPath . '.dtmpl';
    }
}
