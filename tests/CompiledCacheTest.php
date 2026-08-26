<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\AST\TemplateNode;
use CoolMS\Dtmpl\DtmplEngine;
use DateInterval;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The persistent compiled-AST cache.
 *
 * Found the hard way: adding one property to one AST node made every
 * page 500 after deploy, because yesterday's serialized object graph
 * came back out of the pool and deserialized into today's class without
 * the new property. The key was the template SOURCE alone, so nothing
 * about an engine upgrade invalidated anything -- and the failure lasted
 * until the TTL expired or somebody purged the pool by hand.
 *
 * Two guards, both asserted here: the key carries a schema version, and
 * whatever comes back out is type-checked before it is trusted.
 */
final class CompiledCacheTest extends TestCase
{
    #[Test]
    public function aCachedGraphOfTheWrongShapeIsRecompiledNotReturned(): void
    {
        // Stand-in for "an entry written by a previous engine version":
        // a hit whose payload is not a TemplateNode. Degrading to a
        // recompile is the difference between a slow page and a 500.
        $pool = new InMemoryPool();
        $pool->poison('anything at all');

        $engine = new DtmplEngine(cache: $pool);

        self::assertSame('hello', $engine->render('hello'));
    }

    #[Test]
    public function theCacheKeyCarriesASchemaVersion(): void
    {
        // Without this, a node-shape change reuses the old key and the
        // stale graph is read back. The version is what makes old
        // entries age out under keys nothing looks up any more.
        $pool = new InMemoryPool();

        new DtmplEngine(cache: $pool)->render('hello');

        self::assertNotEmpty($pool->keys);
        foreach ($pool->keys as $key) {
            self::assertMatchesRegularExpression('/^dtmpl_\d+_[0-9a-f]{32}$/', $key);
        }
    }

    #[Test]
    public function aValidCachedGraphIsReused(): void
    {
        // The other direction -- the guards must not defeat the cache.
        $pool = new InMemoryPool();

        self::assertSame('hello', new DtmplEngine(cache: $pool)->render('hello'));
        self::assertSame(1, $pool->writes);

        self::assertSame('hello', new DtmplEngine(cache: $pool)->render('hello'));
        self::assertSame(1, $pool->writes, 'second engine should have read the cached AST, not recompiled it');
    }
}

/**
 * Minimal PSR-6 pool. Only what DtmplEngine::compile() touches.
 */
final class InMemoryPool implements CacheItemPoolInterface
{
    /** @var array<string, mixed> */
    public array $stored = [];

    /** @var list<string> */
    public array $keys = [];

    public int $writes = 0;
    private mixed $poison = null;
    private bool $poisoned = false;

    public function poison(mixed $value): void
    {
        $this->poison = $value;
        $this->poisoned = true;
    }

    public function getItem(string $key): CacheItemInterface
    {
        $this->keys[] = $key;
        $hit = $this->poisoned || array_key_exists($key, $this->stored);
        $value = $this->poisoned ? $this->poison : ($this->stored[$key] ?? null);

        return new InMemoryItem($key, $value, $hit);
    }

    /**
     * @param list<string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return array_key_exists($key, $this->stored);
    }

    public function clear(): bool
    {
        $this->stored = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->stored[$key]);

        return true;
    }

    /**
     * @param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        ++$this->writes;
        $this->stored[$item->getKey()] = $item->get();
        // A real pool never hands back a value it was not given; once a
        // genuine AST is written, stop pretending otherwise.
        $this->poisoned = false;

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}

final class InMemoryItem implements CacheItemInterface
{
    public function __construct(
        private readonly string $key,
        private mixed $value,
        private readonly bool $hit,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function expiresAfter(int|DateInterval|null $time): static
    {
        return $this;
    }
}
