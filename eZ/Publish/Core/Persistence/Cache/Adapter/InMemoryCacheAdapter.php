<?php

/**
 * File containing the ContentHandler implementation.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace eZ\Publish\Core\Persistence\Cache\Adapter;


use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Psr\Cache\CacheItemInterface;

final class InMemoryCacheAdapter implements TagAwareAdapterInterface
{
    /**
     * Default limits to in-memory cache usage, max objects cached and max ttl to live in-memory.
     */
    private const LIMIT = 100;
    private const TTL = 500;

    /**
     * @TODO Change to rather be whitelist in jected as argument, this is purely simplest way to see how much stuff gets
     *       cached if we allow everything byt content to be cached.
     * This matches:
     * - ez-content-${contentId}${versionKey}-${translationsKey}
     * - ez-content-${contentId}-version-list
     */
    private const BLACK_LIST = '/^ez-content-\d+-';

    /**
     * @var \Symfony\Component\Cache\CacheItem[] Cache of cache items by their keys.
     */
    private $cacheItems = [];

    /**
     * @var array Timestamp per cache key for TTL checks.
     */
    private $cacheItemsTS = [];

    /**
     * @var TagAwareAdapterInterface
     */
    private $pool;

    /**
     * @var int
     */
    private $limit;

    /**
     * @var float
     */
    private $ttl;

    public function __construct(TagAwareAdapterInterface $pool, int $limit = self::LIMIT, int $ttl = self::TTL)
    {
        $this->pool = $pool;
        $this->limit = $limit;
        $this->ttl = $ttl / 1000;
    }

    public function getItem($key)
    {
        if ($items = $this->getValidInMemoryCacheItems([$key])) {
            return $items[$key];
        }

        $item = $this->pool->getItem($key);
        if ($item->isHit()) {
            $this->saveCacheHitsInMemory([$key => $item]);
        }

        return $item;
    }

    public function getItems(array $keys = [])
    {
        $missingKeys = [];
        foreach ($this->getValidInMemoryCacheItems($keys, $missingKeys) as $key => $item) {
            yield $key => $item;
        }

        if (!empty($missingKeys)) {
            $hits = [];
            $items = $this->pool->getItems($missingKeys);
            foreach ($items as $key => $item) {
                yield $key => $item;

                if ($item->isHit()) {
                    $hits[$key] = $item;
                }
            }

            $this->saveCacheHitsInMemory($hits);
        }
    }

    public function hasItem($key)
    {
        // We are not interested in trying to cache if we don't have the item, but if we do we can return true
        if (isset($this->cacheItems[$key])) {
            return true;
        }

        return $this->pool->hasItem($key);
    }

    public function clear()
    {
        $this->cacheItems = [];
        $this->cacheItemsTS = [];

        return $this->pool->clear();
    }

    public function deleteItem($key)
    {
        if (isset($this->cacheItems[$key])) {
            unset($this->cacheItems[$key], $this->cacheItemsTS[$key]);
        }

        return $this->pool->deleteItem($key);
    }

    public function deleteItems(array $keys)
    {
        foreach ($keys as $key) {
            if (isset($this->cacheItems[$key])) {
                unset($this->cacheItems[$key], $this->cacheItemsTS[$key]);
            }
        }

        return $this->pool->deleteItems($keys);
    }

    public function save(CacheItemInterface $item)
    {
        $this->saveCacheHitsInMemory([$item->getKey() => $item]);

        return $this->pool->save($item);
    }

    public function saveDeferred(CacheItemInterface $item)
    {
        // Symfony commits the deferred items as soon as getItem(s) is called on it later or on destruct.
        // So seems we can safely save in-memory, also we don't at the time of writing use saveDeferred().
        $this->saveCacheHitsInMemory([$item->getKey() => $item]);

        return $this->pool->saveDeferred($item);
    }

    public function commit()
    {
        return $this->pool->commit();
    }

    public function invalidateTags(array $tags)
    {
        // Cleanup in-Memory cache items affected
        foreach ($this->cacheItems as $key => $item) {
            if (array_intersect($item->getPreviousTags(), $tags)) {
                unset($this->cacheItems[$key], $this->cacheItemsTS[$key]);
            }
        }

        return $this->pool->invalidateTags($tags);
    }

    /**
     * @param \Psr\Cache\CacheItemInterface[] $items Save Cache hits in-memory with cache key as array key.
     */
    private function saveCacheHitsInMemory(array $items): void
    {
        // If items accounts for more then 20% of our limit, assume it's bulk content load and skip saving in-memory
        if (\count($items) >= $this->limit / 5) {
            return;
        }

        // Skips items if they match BLACK_LIST pattern
        foreach ($items as $key => $item) {
            if (preg_match(self::BLACK_LIST, $key)) {
                unset($items[$key]);
            }
        }

        // Skip if empty
        if (empty($items)) {
            return;
        }

        // Will we stay clear of the limit? If so remove clearing the 33% oldest cache values
        if (\count($items) + \count($this->cacheItems) >= $this->limit) {
            $this->cacheItems = \array_slice($this->cacheItems, (int) ($this->limit / 3));
        }

        $this->cacheItems += $items;
        $this->cacheItemsTS += \array_fill_keys(\array_keys($items), \microtime(true));
    }

    /**
     * @param array $keys
     * @param array $missingKeys
     *
     * @return array
     */
    public function getValidInMemoryCacheItems(array $keys = [], array &$missingKeys = []): array
    {
        // 1. Validate TTL and remove items that have exceeded it (on purpose not prefixed for global scope, see tests)
        $expiredTime = \microtime(true) - $this->ttl;
        foreach ($this->cacheItemsTS as $key => $ts) {
            if ($ts <= $expiredTime) {
                unset($this->cacheItemsTS[$key]);

                // Cache items might have been removed in saveInMemoryCacheItems() when enforcing limit
                if (isset($this->cacheItems[$key])) {
                    unset($this->cacheItems[$key]);
                }
            }
        }

        // 2. Get valid items
        $items = [];
        foreach ($keys as $key) {
            if (isset($this->cacheItems[$key])) {
                $items[$key] = $this->cacheItems[$key];
            } else {
                $missingKeys[] = $key;
            }
        }

        return $items;
    }
}
