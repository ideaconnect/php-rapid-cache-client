<?php

declare(strict_types=1);

namespace GryfOSS\Cache;

use Generator;

/**
 * Interface defining the contract for cache service implementations.
 *
 * This interface provides methods for basic cache operations (get, set, delete, clear),
 * advanced tagging functionality, queue operations, set operations, and sorted set support.
 */
interface CacheServiceInterface
{
    /**
     * Retrieves a cached value by its key.
     *
     * @param string $key The cache key to retrieve
     * @return mixed The cached value, or null if the key doesn't exist
     */
    public function get(string $key): mixed;

    /**
     * Stores a value in the cache under the specified key.
     *
     * @param string $key The cache key
     * @param mixed $value The value to cache (cannot be null)
     * @param string|null $tag Optional tag for grouping cache entries
     * @param int|null $ttl Time-to-live in seconds (null for no expiration)
     * @param int|null $score Optional score for sorted operations
     * @return CacheServiceInterface Fluent interface
     * @throws \InvalidArgumentException If value is null or TTL is out of range
     */
    public function set(string $key, mixed $value, ?string $tag = null, ?int $ttl = null, ?int $score = null): CacheServiceInterface;

    /**
     * Removes a cache entry by its key.
     *
     * @param string $key The cache key to delete
     * @param bool $skipTagsRemoval Whether to skip removing the key from tag associations
     * @return CacheServiceInterface|null Fluent interface or null
     */
    public function delete(string $key, bool $skipTagsRemoval = false): ?CacheServiceInterface;

    /**
     * Clears all cache entries.
     *
     * @return CacheServiceInterface|null Fluent interface or null
     */
    public function clear(): ?CacheServiceInterface;

    /**
     * Removes all cache entries associated with a specific tag.
     *
     * @param string $tag The tag to clear
     * @return CacheServiceInterface Fluent interface
     */
    public function clearByTag(string $tag): CacheServiceInterface;

    /**
     * Retrieves all cached values associated with a specific tag.
     *
     * @param string $tag The tag to search for
     * @return Generator<string, mixed> A generator yielding key-value pairs
     */
    public function getTagged(string $tag): Generator;

    /**
     * Adds an item to the end of a queue.
     *
     * @param string $queue The queue name
     * @param mixed $value The value to enqueue (cannot be null)
     * @return CacheServiceInterface Fluent interface
     * @throws \InvalidArgumentException If value is null
     */
    public function enqueue(string $queue, mixed $value): CacheServiceInterface;

    /**
     * Removes and returns item(s) from the beginning of a queue.
     *
     * @param string $queue The queue name
     * @param int $range Number of items to pop (default: 1)
     * @return mixed Single item if range=1, array if range>1, null if queue is empty
     */
    public function pop(string $queue, int $range = 1): mixed;

    /**
     * Retrieves item(s) from the beginning of a queue without removing them.
     *
     * @param string $queue The queue name
     * @param int $range Number of items to peek (default: 1)
     * @return mixed Single item if range=1, array if range>1, null if queue is empty
     */
    public function peek(string $queue, int $range = 1): mixed;

    /**
     * Retrieves all items from a queue without removing them.
     *
     * @param string $queue The queue name
     * @return array Array of all queue items
     */
    public function getQueue(string $queue): array;

    /**
     * Gets the number of items in a queue.
     *
     * @param string $queue The queue name
     * @return int The number of items in the queue
     */
    public function getQueueLength(string $queue): int;

    /**
     * Associates an existing cache key with a tag.
     *
     * @param string $key The cache key to tag
     * @param string $tag The tag to associate
     * @param int|null $score Optional score for sorted operations
     * @return CacheServiceInterface Fluent interface
     * @throws \InvalidArgumentException If the key doesn't exist
     */
    public function tag(string $key, string $tag, ?int $score = null): CacheServiceInterface;

    /**
     * Removes the association between a cache key and a tag.
     *
     * @param string $key The cache key
     * @param string $tag The tag to remove
     * @return CacheServiceInterface Fluent interface
     */
    public function untag(string $key, string $tag): CacheServiceInterface;

    /**
     * Increases a numeric value stored in cache.
     *
     * @param string $key The cache key containing a numeric value
     * @param int $value The amount to increase by
     * @return CacheServiceInterface Fluent interface
     */
    public function increase(string $key, int $value): CacheServiceInterface;

    /**
     * Decreases a numeric value stored in cache.
     *
     * @param string $key The cache key containing a numeric value
     * @param int $value The amount to decrease by
     * @return CacheServiceInterface Fluent interface
     */
    public function decrease(string $key, int $value): CacheServiceInterface;

    /**
     * Returns the number of elements in a set.
     *
     * @param string $set The set name
     * @param bool $sortedSet Whether to treat as sorted set (default: false)
     * @return int The cardinality (number of elements)
     */
    public function getCardinality(string $set, bool $sortedSet = false): int;

    /**
     * Retrieves elements from a sorted set in order.
     *
     * @param string $set The sorted set name
     * @param int $count Maximum number of elements to retrieve
     * @param int $offset Starting offset (default: 0)
     * @param bool $reversed Whether to return in reverse order (default: false)
     * @return Generator<string, mixed> A generator yielding key-value pairs
     */
    public function getSorted(string $set, int $count, int $offset = 0, bool $reversed = false): Generator;

    /**
     * Adds a value to a set.
     *
     * @param string $key The set name
     * @param mixed $value The value to add
     * @return self Fluent interface
     */
    public function addToSet(string $key, mixed $value): self;

    /**
     * Removes a value from a set.
     *
     * @param string $key The set name
     * @param mixed $value The value to remove
     * @return self Fluent interface
     */
    public function removeFromSet(string $key, mixed $value): self;

    /**
     * Retrieves all members of a set.
     *
     * @param string $key The set name
     * @return array|null Array of set members, or null if set doesn't exist
     */
    public function getSet(string $key): ?array;

    /**
     * Creates a new set with the specified values, replacing any existing set.
     *
     * @param string $key The set name
     * @param array $values Array of values to store in the set
     * @return self Fluent interface
     */
    public function createSet(string $key, array $values): self;
}
