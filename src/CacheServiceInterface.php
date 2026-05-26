<?php

declare(strict_types=1);

namespace IDCT\Cache;

use DateInterval;
use Generator;
use Psr\SimpleCache\CacheInterface;

/**
 * Extends PSR-16 SimpleCache with tagging, queue, set, sorted-set, and
 * counter operations that go beyond the SimpleCache contract.
 */
interface CacheServiceInterface extends CacheInterface
{
    /**
     * Stores a value and associates it with a tag in a single call.
     *
     * @param string $key   PSR-16 compliant cache key
     * @param mixed  $value Value to cache (must not be null)
     * @param string $tag   Tag to associate with the key
     * @param null|int|DateInterval $ttl Optional TTL
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function setTagged(string $key, mixed $value, string $tag, null|int|DateInterval $ttl = null): bool;

    /**
     * Removes all cache entries associated with a specific tag.
     */
    public function clearByTag(string $tag): bool;

    /**
     * Retrieves all cached values associated with a specific tag.
     *
     * Note: iteration performs Redis writes for housekeeping (removing tag
     * entries whose underlying key has expired). Consumers that break out
     * before exhausting the generator will leave the un-inspected entries
     * for the next call to clean up.
     *
     * @return Generator<string, mixed>
     */
    public function getTagged(string $tag): Generator;

    /**
     * Associates an existing cache key with a tag.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If the key doesn't exist
     */
    public function tag(string $key, string $tag): self;

    /**
     * Removes the association between a cache key and a tag.
     */
    public function untag(string $key, string $tag): self;

    /**
     * Adds an item to the end of a queue.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If value is null
     */
    public function enqueue(string $queue, mixed $value): self;

    /**
     * Removes and returns item(s) from the beginning of a queue.
     *
     * @return mixed Single item if range=1, array if range>1, null if queue is empty
     */
    public function pop(string $queue, int $range = 1): mixed;

    /**
     * Retrieves item(s) from the beginning of a queue without removing them.
     */
    public function peek(string $queue, int $range = 1): mixed;

    /**
     * Retrieves all items from a queue without removing them.
     *
     * @return array<int, mixed>
     */
    public function getQueue(string $queue): array;

    /**
     * Gets the number of items in a queue.
     */
    public function getQueueLength(string $queue): int;

    /**
     * Atomically increases a numeric value stored in cache.
     */
    public function increase(string $key, int $value): self;

    /**
     * Atomically decreases a numeric value stored in cache.
     */
    public function decrease(string $key, int $value): self;

    /**
     * Returns the number of elements in a (sorted) set.
     */
    public function getCardinality(string $set, bool $sortedSet = false): int;

    /**
     * Returns the number of keys currently associated with the given tag.
     */
    public function getTagCardinality(string $tag): int;

    /**
     * Retrieves elements from a sorted set in order.
     *
     * Note: iteration performs Redis writes for housekeeping (removing
     * sorted-set members whose underlying key has expired). Consumers that
     * break out before exhausting the generator will leave the un-inspected
     * entries for the next call to clean up.
     *
     * @return Generator<string, mixed>
     */
    public function getSorted(string $set, int $count, int $offset = 0, bool $reversed = false): Generator;

    /**
     * Adds a value to a set.
     */
    public function addToSet(string $key, mixed $value): self;

    /**
     * Removes a value from a set.
     */
    public function removeFromSet(string $key, mixed $value): self;

    /**
     * Retrieves all members of a set.
     *
     * @return list<string>|null
     */
    public function getSet(string $key): ?array;

    /**
     * Creates a new set with the specified values, replacing any existing set.
     *
     * @param array<int, mixed> $values
     */
    public function createSet(string $key, array $values): self;
}
