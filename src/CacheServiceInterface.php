<?php

declare(strict_types=1);

namespace GryfOSS\Cache;

use Generator;

interface CacheServiceInterface
{
    /**
     * Gets the unserialized object set using the provided key or null if such
     * object does not exist.
     */
    public function get(string $key): mixed;

    /**
     * Sets the given object under the given key.
     *
     * Setting of tag allows to group objects.
     *
     * TTL sets time-to-live in seconds: 0 or null disables TTL.
     */
    public function set(string $key, mixed $value, ?string $tag = null, ?int $ttl = null, ?int $score = null): CacheServiceInterface;

    /**
     * Deletes entry under given key.
     *
     * @return CacheServiceInterface
     */
    public function delete(string $key,  bool $skipTagsRemoval = false): ?CacheServiceInterface;

    /**
     * Deletes all entries.
     *
     * @return CacheServiceInterface
     */
    public function clear(): ?CacheServiceInterface;

    /**
     * Deletes all entries under given tag.
     */
    public function clearByTag(string $tag): CacheServiceInterface;

    /**
     * Gets an array of unserialized objects from under a given tag ordered by
     * their.
     */
    public function getTagged(string $tag): Generator;

    /**
     * Puts entry on top of a queue.
     */
    public function enqueue(string $queue, mixed $value): CacheServiceInterface;

    /**
     * Pops out first element from the queue.
     */
    public function pop(string $queue, int $range = 1): mixed;

    public function tag(string $key, string $tag, ?int $score = null): CacheServiceInterface;

    public function untag(string $key, string $tag): CacheServiceInterface;

    public function increase(string $key, int $value): CacheServiceInterface;

    public function decrease(string $key, int $value): CacheServiceInterface;

    public function getCardinality(string $set): int;

    public function getSorted(string $set, int $count, int $offset): Generator;

    public function addToSet(string $key, mixed $value): self;

    public function removeFromSet(string $key, mixed $value): self;

    public function getSet(string $key): ?array;

    public function createSet(string $key, array $values): self;
}
