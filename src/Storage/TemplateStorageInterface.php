<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Storage;

/**
 * Abstract contract for the template file storage backend.
 *
 * Defined in DTMPL.Domain so that the Application layer (loaders, handlers)
 * depends on this interface, not on concrete VFS services. The VFS module
 * provides the implementation in VFS\Infrastructure\DTMPL\VfsTemplateStorage.
 *
 * Write operations (write/create) resolve the acting user from the security
 * context inside the implementation -- callers do not need to pass a user.
 */
interface TemplateStorageInterface
{
    /**
     * Read the raw content of a template file.
     * Returns false if the file does not exist or cannot be read.
     */
    public function read(string $path): string|false;

    /**
     * Check whether a template file exists and is readable.
     */
    public function exists(string $path): bool;

    /**
     * Return the last-modified UNIX timestamp, or false on failure.
     */
    public function getModifiedTime(string $path): int|false;

    /**
     * Overwrite (or create) a template file with the given content.
     * The implementation resolves the current user from the security context.
     */
    public function write(string $path, string $content): void;

    /**
     * Create a new template file with the given content and permission mode.
     * The implementation resolves the current user from the security context.
     */
    public function create(string $path, string $content, int $mode = 0o644): void;
}
