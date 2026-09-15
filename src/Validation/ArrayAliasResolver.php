<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Validation;

/**
 * A resolver over a fixed alias-to-class map: for tests, and for a
 * host with a handful of aliases and no registry to derive them from.
 */
final readonly class ArrayAliasResolver implements AliasResolverInterface
{
    /**
     * @param array<string, class-string> $aliases alias (without the `@`) to class
     */
    public function __construct(private array $aliases = [])
    {
    }

    public function resolve(string $alias): ?string
    {
        return $this->aliases[$alias] ?? null;
    }

    public function knownAliases(): array
    {
        return array_keys($this->aliases);
    }
}
