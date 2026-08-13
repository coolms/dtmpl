<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use Symfony\Component\PropertyAccess\Exception\AccessException;
use Symfony\Component\PropertyAccess\Exception\NoSuchIndexException;
use Symfony\Component\PropertyAccess\Exception\NoSuchPropertyException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Context.
 *
 * Manages variable scopes during template execution.
 * Supports nested scopes for loops and blocks.
 *
 * Object navigation inside a path delegates to Symfony's
 * `PropertyAccessor` so getter / declared-property / magic
 * `__get` / `ArrayAccess` lookups all flow through one mature
 * implementation; the default `PropertyAccessor` enables magic
 * methods (`MAGIC_GET | MAGIC_SET`) so wrappers like EntityWrapper
 * work without custom builder configuration. Array-key navigation
 * stays explicit because the path is already split into segments
 * and PropertyAccessor's array syntax (`[key]`) would only force
 * the segments to be re-assembled.
 */
final class Context
{
    /** @var array<string, mixed>[] */
    private array $scopes = [];

    private readonly PropertyAccessorInterface $propertyAccessor;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [], ?PropertyAccessorInterface $propertyAccessor = null)
    {
        $this->scopes[] = $data;
        $this->propertyAccessor = $propertyAccessor ?? PropertyAccess::createPropertyAccessor();
    }

    /**
     * Get variable value by path.
     *
     * @param string[] $path
     */
    public function get(array $path, mixed $default = null): mixed
    {
        if (empty($path)) {
            return $default;
        }

        // Search from innermost to outermost scope
        for ($i = count($this->scopes) - 1; $i >= 0; --$i) {
            $value = $this->resolvePath($this->scopes[$i], $path);
            if (null !== $value) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Set variable in current scope.
     *
     * @param array<int, string>|string $name
     */
    public function set(array|string $name, mixed $value): void
    {
        if (is_array($name)) {
            $root = array_shift($name);
            if (0 < count($name)) {
                $value = array_reduce(array_reverse($name), fn ($acc, $key) => [$key => $acc], $value);
            }
            $key = (string) $root;
        } else {
            $key = $name;
        }
        $this->scopes[count($this->scopes) - 1][$key] = $value;
    }

    /**
     * Check if variable exists.
     *
     * @param string[] $path
     */
    public function has(array $path): bool
    {
        return null !== $this->get($path);
    }

    /**
     * Push new scope.
     *
     * @param array<string, mixed> $data
     */
    public function push(array $data = []): void
    {
        $this->scopes[] = $data;
    }

    /**
     * Pop current scope.
     */
    public function pop(): void
    {
        if (count($this->scopes) > 1) {
            array_pop($this->scopes);
        }
    }

    /**
     * Get current scope.
     *
     * @return array<string, mixed>
     */
    public function getCurrentScope(): array
    {
        return $this->scopes[count($this->scopes) - 1] ?? [];
    }

    /**
     * Get all data (flattened).
     *
     * @return array<string, mixed>
     */
    public function getAllData(): array
    {
        $result = [];
        foreach ($this->scopes as $scope) {
            $result = array_merge($result, $scope);
        }

        return $result;
    }

    /**
     * Create child context (for loops).
     *
     * @param array<string, mixed> $data
     */
    public function createChild(array $data = []): self
    {
        $child = new self([], $this->propertyAccessor);
        $child->scopes = $this->scopes;
        $child->push($data);

        return $child;
    }

    /**
     * Resolve path in data array.
     *
     * Array segments are walked explicitly because PropertyAccessor's
     * array navigation requires bracket syntax (`[key]`) and the path
     * is already split into discrete segments here. Object segments
     * delegate to PropertyAccessor and are wrapped in a try/catch so
     * a missing property / index returns null rather than throwing,
     * preserving Context's null-on-miss contract.
     *
     * @param array<string, mixed> $data
     * @param string[]             $path
     */
    private function resolvePath(array $data, array $path): mixed
    {
        $current = $data;

        foreach ($path as $segment) {
            if (null === $current) {
                return null;
            }
            if (is_array($current)) {
                if (!array_key_exists($segment, $current)) {
                    return null;
                }
                $current = $current[$segment];
                continue;
            }
            if (!is_object($current)) {
                return null;
            }
            try {
                $current = $this->propertyAccessor->getValue($current, $segment);
            } catch (NoSuchPropertyException|NoSuchIndexException|AccessException) {
                return null;
            }
        }

        return $current;
    }
}
