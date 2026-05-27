<?php

declare(strict_types=1);

namespace IDCT\Cache;

use DateInterval;
use DateTimeImmutable;
use Generator;
use IDCT\Cache\Exception\CacheException;
use IDCT\Cache\Exception\InvalidArgumentException;
use Redis;
use RedisException;

use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * High-performance Redis-based cache client.
 *
 * Implements the PSR-16 Simple Cache contract while exposing additional
 * features: tagging, queues, sets, sorted sets, and atomic counters.
 */
class RapidCacheClient implements CacheServiceInterface
{
    /** Standard Redis TCP port, used when the legacy constructor gets a null port. */
    public const DEFAULT_REDIS_PORT = 6379;

    /**
     * Tagging is built on a pair of mirrored Redis SETs ("dual index") so that
     * both directions of the relationship can be resolved in O(1):
     *
     *   TAG:<tag>   -> SET of cache keys carrying that tag   (forward index)
     *   TAGS:<key>  -> SET of tags attached to that key       (reverse index)
     *
     * The forward index ({@see TAG_KEY_SET_PREFIX}) powers {@see getTagged()}
     * and {@see clearByTag()} ("give me every key for this tag"). The reverse
     * index ({@see TAGS_SET_NAME_PREFIX}) powers cleanup ({@see unindexKey()}):
     * when a key is deleted we must remove it from every tag set it belonged
     * to, and without the reverse index that would require scanning all tags.
     *
     * Every tagging write touches both sets inside one MULTI/EXEC pipeline so
     * the two indexes never drift apart. These prefixes are applied on top of
     * any user-configured Redis::OPT_PREFIX, so they are namespaced per client.
     */
    private const TAGS_SET_NAME_PREFIX = 'TAGS:';

    /** @see TAGS_SET_NAME_PREFIX for the full description of the dual-index scheme. */
    private const TAG_KEY_SET_PREFIX = 'TAG:';

    /**
     * Characters PSR-16 reserves for future cache-driver extensions and which
     * {@see validateKey()} therefore rejects. `:` is included because phpredis
     * uses it as the conventional key-prefix separator, and `\` because
     * igbinary payloads/key encodings can be corrupted by stray backslashes.
     */
    private const RESERVED_KEY_CHARS = '{}()/\\@:';

    /**
     * The live phpredis handle, or null before the first connect / after a
     * transport error nulled it in {@see wrap()}. Never read directly — go
     * through {@see getRedis()} so a dropped connection is transparently
     * re-established.
     */
    private ?Redis $redis = null;

    /** Immutable connection settings resolved once in the constructor. */
    private RedisConnectionConfig $config;

    /**
     * Re-entrancy guard for the retry-once logic in {@see wrap()}. It stops the
     * single permitted retry from itself triggering another retry (which would
     * turn one transient error into an unbounded retry loop). Set true only for
     * the duration of the retry attempt.
     */
    private bool $retrying = false;

    /**
     * Accepts either the legacy positional form (host, port, prefix) or a
     * pre-built {@see RedisConnectionConfig} for full control over auth,
     * database, timeouts, and persistence.
     *
     * No connection is opened here — the first cache operation triggers a
     * lazy connect via {@see reconnect()}.
     *
     * @param string|RedisConnectionConfig $hostOrConfig Either a hostname (legacy form)
     *                                                   or a full connection config
     * @param int|null                     $port         TCP port; ignored when $hostOrConfig
     *                                                   is a RedisConnectionConfig
     * @param string|null                  $prefix       Key prefix; ignored when $hostOrConfig
     *                                                   is a RedisConnectionConfig
     *
     * @throws \InvalidArgumentException When the legacy form produces an invalid
     *                                   {@see RedisConnectionConfig} (empty host, port
     *                                   out of range, etc.)
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testLegacyConstructorAppliesDefaultPortWhenNullGiven()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testLegacyConstructorPreservesExplicitPort()
     */
    public function __construct(
        string|RedisConnectionConfig $hostOrConfig,
        ?int $port = self::DEFAULT_REDIS_PORT,
        ?string $prefix = null
    ) {
        if ($hostOrConfig instanceof RedisConnectionConfig) {
            $this->config = $hostOrConfig;
            return;
        }
        $this->config = new RedisConnectionConfig(
            host: $hostOrConfig,
            port: $port ?? self::DEFAULT_REDIS_PORT,
            prefix: $prefix,
        );
    }

    /**
     * Opens a fresh Redis connection from the stored config and applies all
     * options (auth, database, timeouts, prefix, igbinary serializer).
     *
     * Wraps any connection failure — including engine-level errors that aren't
     * RedisException — into a {@see CacheException}. Pure-PHP coding bugs
     * ({@see \Error}) are re-thrown unchanged so they aren't masked.
     *
     * @throws CacheException When connection or option-setup fails for any
     *                        non-engine reason.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testReconnectAppliesAllConfigFields()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testReconnectWithoutPasswordSkipsAuth()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testReconnectWithEmptyPasswordSkipsAuth()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testReconnectWithDatabaseZeroSkipsSelect()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testReconnectWithoutPrefixSkipsPrefixOption()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testReconnectWithZeroReadTimeoutSkipsReadTimeoutOption()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testReconnectUsesNonPersistentConnectByDefault()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testReconnectWrapsRedisExceptionAsCacheException()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testReconnectWrapsArbitraryThrowable()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testReconnectLetsErrorsPropagate()
     */
    protected function reconnect(): Redis
    {
        $config = $this->config;
        try {
            $redis = $this->createRedisInstance();
            if ($config->persistent) {
                $redis->pconnect(
                    $config->host,
                    $config->port,
                    $config->connectTimeout,
                    $config->persistentId
                );
            } else {
                $redis->connect($config->host, $config->port, $config->connectTimeout);
            }
            if ($config->readTimeout > 0) {
                $redis->setOption(Redis::OPT_READ_TIMEOUT, (string) $config->readTimeout);
            }
            if ($config->password !== null && $config->password !== '') {
                $redis->auth($config->password);
            }
            if ($config->database !== 0) {
                $redis->select($config->database);
            }
            if ($config->prefix !== null && $config->prefix !== '') {
                // OPT_PREFIX makes phpredis transparently prepend this string to
                // every key on the wire. Application code keeps using bare keys;
                // the only place we must disable it is the raw SCAN/UNLINK pass
                // in clear(), which works against fully-qualified key names.
                $redis->setOption(Redis::OPT_PREFIX, $config->prefix);
            }
            // The igbinary serializer is what lets arbitrary PHP values (objects,
            // nested arrays, DateTime, …) round-trip losslessly: phpredis packs
            // them on SET and unpacks on GET. It also drives one of this client's
            // quirks — a stored literal `false` is indistinguishable from a cache
            // miss at the protocol level (both surface as PHP `false`), which is
            // why get()/getSorted() add an EXISTS probe to disambiguate.
            $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
        } catch (\Error $e) {
            // Engine-level errors (e.g. missing class, type errors) are bugs,
            // not storage failures — let them propagate unchanged.
            throw $e;
        } catch (\Throwable $e) {
            throw new CacheException(
                sprintf('Unable to connect to Redis at %s:%d: %s', $config->host, $config->port, $e->getMessage()),
                0,
                $e
            );
        }
        $this->redis = $redis;

        return $redis;
    }

    /**
     * Factory hook for the underlying phpredis instance. Override in tests or
     * subclasses to inject a mock/decorated Redis without touching the
     * connection lifecycle.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testCreateRedisInstanceReturnsRealRedis()
     */
    protected function createRedisInstance(): Redis
    {
        return new Redis();
    }

    /**
     * Returns the live Redis connection, reconnecting on demand if the cached
     * one was lost (e.g., killed by a {@see RedisException} in {@see wrap()}).
     *
     * @throws CacheException When the lazy reconnect fails.
     */
    protected function getRedis(): Redis
    {
        if ($this->redis === null || !$this->redis->isConnected()) {
            return $this->reconnect();
        }
        return $this->redis;
    }

    // -------------------------------------------------------------------
    // PSR-16 CacheInterface
    // -------------------------------------------------------------------

    /**
     * Retrieves a value from the cache.
     *
     * Distinguishes a stored literal `false` from a missing key: when GET
     * returns false, an EXISTS probe disambiguates (one extra round-trip on
     * the miss-or-false case, none on the common hit path).
     *
     * @param mixed $default Returned only when the key is absent — a stored
     *                       `false` always wins over $default.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $key violates PSR-16
     *         (empty or contains reserved characters).
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetReturnsValueWhenExists()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetReturnsDefaultWhenTrulyMissing()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetReturnsStoredFalse()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetRejectsInvalidKey()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetWrapsRedisExceptionAsCacheException()
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        return $this->wrap(function () use ($key, $default) {
            $redis = $this->getRedis();
            $value = $redis->get($key);
            if ($value !== false) {
                return $value;
            }
            return $redis->exists($key) ? false : $default;
        });
    }

    /**
     * Stores a value, optionally with a TTL.
     *
     * TTL semantics:
     * - `null`     — persist indefinitely (Redis SET).
     * - positive   — expire after N seconds (Redis SETEX), or after the
     *                resolved interval if a DateInterval is given.
     * - `0` or negative — treated as immediate expiry: the key is deleted and
     *                any tag associations are cleaned up. Returns true.
     *
     * Values are stored igbinary-serialized (configured at connect time), so
     * any serializable PHP value round-trips losslessly.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $key is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetWithoutTtl()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetWithIntegerTtl()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetWithDateIntervalTtl()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetWithZeroTtlDeletesEntry()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetRejectsInvalidKey()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetWrapsRedisExceptionAsCacheException()
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $seconds = $this->normalizeTtl($ttl);
        return $this->wrap(function () use ($key, $value, $seconds) {
            $redis = $this->getRedis();

            if ($seconds !== null && $seconds <= 0) {
                $this->unindexKey($key);
                $redis->del($key);
                return true;
            }

            if ($seconds !== null) {
                return (bool) $redis->setex($key, $seconds, $value);
            }

            return (bool) $redis->set($key, $value);
        });
    }

    /**
     * Deletes a key and removes it from every tag set it belonged to.
     *
     * Idempotent: returns true whether or not the key existed.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $key is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testDeleteRemovesTagAssociations()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testDeleteRejectsInvalidKey()
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);
        return $this->wrap(function () use ($key) {
            $this->unindexKey($key);
            $this->getRedis()->del($key);
            return true;
        });
    }

    /**
     * Clears the cache.
     *
     * With a prefix configured: scans for `<prefix>*` and UNLINKs in batches,
     * leaving keys from other apps that share the Redis database untouched.
     * The SCAN/UNLINK pass disables auto-prefixing so it can operate on raw
     * keys, then restores the prior prefix and SCAN options in a `finally`.
     *
     * Without a prefix: FLUSHDB — wipes the entire database.
     *
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testClearWithoutPrefixUsesFlushDb()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testClearWithPrefixScansAndUnlinks()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testClearWithPrefixIteratesMultipleScanBatches()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testClearSkipsUnlinkOnEmptyBatch()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testClearBreaksOnScanFailure()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testClearRestoresPrefixAndScanOptionsEvenWhenScanThrows()
     */
    public function clear(): bool
    {
        return $this->wrap(function () {
            $redis = $this->getRedis();
            $prefix = $this->config->prefix;

            if ($prefix === null || $prefix === '') {
                $redis->flushDb();
                return true;
            }

            // Disable phpredis auto-prefixing while we SCAN/UNLINK with raw keys.
            $priorScan = $redis->getOption(Redis::OPT_SCAN);
            $redis->setOption(Redis::OPT_PREFIX, '');
            try {
                $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
                $iterator = null;
                do {
                    $keys = $redis->scan($iterator, $prefix . '*', 1000);
                    if ($keys === false) {
                        break;
                    }
                    if ($keys !== []) {
                        $redis->unlink($keys);
                    }
                } while ($iterator > 0);
            } finally {
                $redis->setOption(Redis::OPT_PREFIX, $prefix);
                $redis->setOption(Redis::OPT_SCAN, $priorScan);
            }

            return true;
        });
    }

    /**
     * Bulk get via a single MGET round-trip.
     *
     * Unlike {@see get()}, this does NOT disambiguate stored-`false` from
     * missing — both yield $default. Use {@see get()} per key when that
     * distinction matters.
     *
     * @return array<string, mixed> Keyed by the originally requested keys, in
     *                              input order; missing keys map to $default.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If any key is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetMultiple()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetMultipleChunksMGetByConfiguredBatchSize()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetMultipleWithEmptyKeysReturnsEmptyArrayWithoutRedisCall()
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $normalized = [];
        foreach ($keys as $key) {
            $this->validateKey($key);
            $normalized[] = $key;
        }

        if ($normalized === []) {
            return [];
        }

        return $this->wrap(function () use ($normalized, $default) {
            $redis = $this->getRedis();
            $result = [];
            // Chunk MGET to keep request size and reply buffer bounded — a
            // single MGET with 100k+ keys can exceed client-query-buffer-limit
            // and stall reply parsing.
            foreach (array_chunk($normalized, $this->config->pipelineBatchSize) as $chunk) {
                $values = $redis->mGet($chunk);
                foreach ($chunk as $i => $key) {
                    $value = $values[$i] ?? false;
                    $result[$key] = $value === false ? $default : $value;
                }
            }
            return $result;
        });
    }

    /**
     * Bulk store.
     *
     * - No TTL: single MSET round-trip.
     * - With TTL: pipelined SETEX (one MULTI/EXEC) so the whole batch goes in
     *   one network round-trip. Returns true only if every SETEX succeeded.
     * - TTL ≤ 0: bulk DELETE + tag cleanup, returns true.
     *
     * @param iterable<mixed> $values Map of key → value.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If any key is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetMultipleWithoutTtlUsesMSet()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetMultipleWithTtlUsesPipelinedSetex()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetMultipleWithTtlChunksPipelineByConfiguredBatchSize()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetMultipleWithoutTtlChunksMSetByConfiguredBatchSize()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetMultipleWithoutTtlStopsOnFirstFailedChunk()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetMultipleReturnsFalseWhenExecReturnsNonArray()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetMultipleWithZeroTtlDeletesKeysAndSkipsWrites()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetMultipleWithZeroTtlUnindexesByOriginalKeysNotValues()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetMultipleWithNegativeTtlDeletesKeys()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetMultipleWithEmptyValuesReturnsTrueWithoutRedisCall()
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $stringKey = is_string($key) ? $key : (string) $key;
            $this->validateKey($stringKey);
            $normalized[$stringKey] = $value;
        }
        if ($normalized === []) {
            return true;
        }

        $seconds = $this->normalizeTtl($ttl);
        return $this->wrap(function () use ($normalized, $seconds) {
            $redis = $this->getRedis();
            $batchSize = $this->config->pipelineBatchSize;

            if ($seconds !== null && $seconds <= 0) {
                foreach (array_keys($normalized) as $key) {
                    $this->unindexKey($key);
                }
                foreach (array_chunk(array_keys($normalized), $batchSize) as $chunk) {
                    $redis->del($chunk);
                }
                return true;
            }

            if ($seconds === null) {
                foreach (array_chunk($normalized, $batchSize, true) as $chunk) {
                    if (!$redis->mSet($chunk)) {
                        return false;
                    }
                }
                return true;
            }

            // Chunk the SETEX pipeline: EXEC is one atomic blocking step on
            // the server, so an unbounded batch can stall the single-threaded
            // event loop for the duration of the whole transaction.
            foreach (array_chunk($normalized, $batchSize, true) as $chunk) {
                $redis->multi(Redis::PIPELINE);
                foreach ($chunk as $key => $value) {
                    $redis->setex($key, $seconds, $value);
                }
                $results = $redis->exec();
                if (!is_array($results) || in_array(false, $results, true)) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Bulk delete, with tag cleanup for each key.
     *
     * Idempotent: returns true regardless of which keys actually existed.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If any key is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testDeleteMultiple()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testDeleteMultipleChunksDelByConfiguredBatchSize()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testDeleteMultipleWithEmptyKeysReturnsTrueWithoutRedisCall()
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $normalized = [];
        foreach ($keys as $key) {
            $this->validateKey($key);
            $normalized[] = $key;
        }
        if ($normalized === []) {
            return true;
        }

        return $this->wrap(function () use ($normalized) {
            $redis = $this->getRedis();
            foreach ($normalized as $key) {
                $this->unindexKey($key);
            }
            foreach (array_chunk($normalized, $this->config->pipelineBatchSize) as $chunk) {
                $redis->del($chunk);
            }
            return true;
        });
    }

    /**
     * Checks key existence via a single EXISTS call.
     *
     * Per PSR-16, this is a fast probe and the result is not a strong
     * guarantee — a concurrent expiry between `has()` and a follow-up `get()`
     * is always possible.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $key is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testHas()
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);
        return $this->wrap(fn() => (bool) $this->getRedis()->exists($key));
    }

    // -------------------------------------------------------------------
    // Tagging
    // -------------------------------------------------------------------

    /**
     * Stores a value and atomically associates it with a tag.
     *
     * Single MULTI/EXEC pipeline: the SET/SETEX and both index writes (the
     * tag → keys set and the reverse keys → tags set) commit together.
     *
     * TTL ≤ 0 short-circuits to {@see set()}'s delete branch — tagging a
     * key you're about to delete makes no sense, so tagging is skipped.
     *
     * Tags themselves are validated as PSR-16 keys, so they cannot contain
     * reserved characters.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $key or $tag is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetTagged()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetTaggedWithTtl()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetTaggedWithZeroTtlShortCircuitsToDeleteAndSkipsTagging()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetTaggedWithNegativeTtlShortCircuitsToDelete()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetTaggedReturnsFalseWhenSetFailsInPipeline()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetTaggedReturnsTrueWhenSetSucceedsEvenIfTagWritesAreNoOps()
     */
    public function setTagged(string $key, mixed $value, string $tag, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateKey($tag);
        $seconds = $this->normalizeTtl($ttl);

        if ($seconds !== null && $seconds <= 0) {
            return $this->set($key, $value, $ttl);
        }

        return $this->wrap(function () use ($key, $value, $tag, $seconds) {
            $redis = $this->getRedis();
            $redis->multi(Redis::PIPELINE);
            if ($seconds === null) {
                $redis->set($key, $value);
            } else {
                $redis->setex($key, $seconds, $value);
            }
            $redis->sAdd(self::TAG_KEY_SET_PREFIX . $tag, $key);
            $redis->sAdd(self::TAGS_SET_NAME_PREFIX . $key, $tag);
            $results = $redis->exec();
            return is_array($results) && $results[0] !== false;
        });
    }

    /**
     * Iterates over all values currently associated with $tag.
     *
     * **Side effects on iteration**: tag entries whose underlying key has
     * expired/been deleted are pruned from the tag set during iteration. The
     * cleanup is collected in-memory and flushed via a pipelined MULTI/EXEC
     * in a `finally` block — so even an early `break` from the loop will
     * clean up whatever was inspected so far. Un-inspected entries are left
     * for the next call.
     *
     * Cleanup failures are swallowed (best-effort) so they don't mask the
     * primary exception path.
     *
     * @return Generator<string, mixed> Yielding cacheKey => storedValue.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $tag is invalid.
     * @throws CacheException On Redis transport/storage failures during the
     *         primary read phase.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetTaggedWithExistingItems()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetTaggedWithExpiredItems()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetTaggedWithNoItems()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetTaggedWrapsRedisExceptionFromGenerator()
     */
    public function getTagged(string $tag): Generator
    {
        $this->validateKey($tag);
        $stale = [];
        try {
            $redis = $this->getRedis();
            $tagSet = self::TAG_KEY_SET_PREFIX . $tag;
            $members = $redis->sMembers($tagSet);

            if (!is_array($members) || $members === []) {
                return;
            }

            $values = $redis->mGet($members);

            foreach ($members as $i => $member) {
                $value = $values[$i] ?? false;
                if ($value !== false) {
                    yield $member => $value;
                } else {
                    $stale[] = $member;
                }
            }
        } catch (RedisException $e) {
            throw $this->toCacheException($e);
        } finally {
            if ($stale !== []) {
                try {
                    $redis = $this->getRedis();
                    $tagSet = self::TAG_KEY_SET_PREFIX . $tag;
                    $redis->multi(Redis::PIPELINE);
                    foreach ($stale as $member) {
                        $redis->sRem($tagSet, $member);
                        $redis->sRem(self::TAGS_SET_NAME_PREFIX . $member, $tag);
                    }
                    $redis->exec();
                } catch (RedisException) {
                    // Best-effort cleanup — don't shadow the primary exception.
                }
            }
        }
    }

    /**
     * Adds a tag association to an existing key.
     *
     * The key must already exist; tagging a missing key would create a ghost
     * association that {@see getTagged()} would later have to clean up.
     *
     * Race window: the EXISTS + SADD pair is not atomic. A concurrent
     * `delete($key)` between the two can still leave a ghost membership in
     * the tag set — {@see getTagged()} heals such entries on read.
     *
     * @return self for chaining.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $key or $tag is
     *         invalid, or if $key does not exist.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testTagExistingKey()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testTagNonExistingKey()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testTagRejectsInvalidTagName()
     */
    public function tag(string $key, string $tag): self
    {
        $this->validateKey($key);
        $this->validateKey($tag);
        return $this->wrap(function () use ($key, $tag) {
            $redis = $this->getRedis();
            if (!$redis->exists($key)) {
                throw new InvalidArgumentException(sprintf('Can\'t tag non-existing key "%s"', $key));
            }
            $redis->multi(Redis::PIPELINE);
            $redis->sAdd(self::TAG_KEY_SET_PREFIX . $tag, $key);
            $redis->sAdd(self::TAGS_SET_NAME_PREFIX . $key, $tag);
            $redis->exec();
            return $this;
        });
    }

    /**
     * Removes a single tag association from a key.
     *
     * Symmetric to {@see tag()}: removes the key from the tag's set AND the
     * tag from the key's reverse-lookup set. The underlying cache value is
     * left in place — use {@see delete()} to remove it entirely.
     *
     * Idempotent.
     *
     * @return self for chaining.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $key or $tag is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testUntag()
     */
    public function untag(string $key, string $tag): self
    {
        $this->validateKey($key);
        $this->validateKey($tag);
        return $this->wrap(function () use ($key, $tag) {
            $redis = $this->getRedis();
            $redis->sRem(self::TAG_KEY_SET_PREFIX . $tag, $key);
            $redis->sRem(self::TAGS_SET_NAME_PREFIX . $key, $tag);
            return $this;
        });
    }

    /**
     * Deletes every key currently associated with $tag, plus all related index
     * entries (the tag set itself and each key's reverse-lookup set).
     *
     * Implemented as a two-phase pipelined cascade:
     *  1. One MULTI/EXEC fetches each affected key's reverse tag list (so we
     *     know which OTHER tag sets need to drop this key too).
     *  2. A second MULTI/EXEC executes all the cleanup writes (sRem from
     *     other tag sets, del each reverse-lookup set, del the keys
     *     themselves, del the original tag set).
     *
     * Idempotent: returns true even when the tag is unknown or empty.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $tag is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testClearByTag()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testClearByTagRemovesKeysFromOtherTagsToo()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testClearByTagEmptyTagSet()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testClearByTagChunksPhaseOneAndPhaseTwoByConfiguredBatchSize()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testClearByTagPadsReverseLookupsWhenPhaseOneExecReturnsNonArray()
     */
    public function clearByTag(string $tag): bool
    {
        $this->validateKey($tag);
        return $this->wrap(function () use ($tag) {
            $redis = $this->getRedis();
            $tagSet = self::TAG_KEY_SET_PREFIX . $tag;
            $members = $redis->sMembers($tagSet);

            if (!is_array($members) || $members === []) {
                $redis->del($tagSet);
                return true;
            }

            $batchSize = $this->config->pipelineBatchSize;

            // Phase 1 (pipelined, chunked): fetch each member's reverse tag list.
            $reverseLookups = [];
            foreach (array_chunk($members, $batchSize) as $chunk) {
                $redis->multi(Redis::PIPELINE);
                foreach ($chunk as $member) {
                    $redis->sMembers(self::TAGS_SET_NAME_PREFIX . $member);
                }
                $batchResult = $redis->exec();
                if (is_array($batchResult)) {
                    foreach ($batchResult as $entry) {
                        $reverseLookups[] = $entry;
                    }
                } else {
                    // Pad so phase-2 indexing stays aligned with $members.
                    foreach ($chunk as $_) {
                        $reverseLookups[] = null;
                    }
                }
            }

            // Phase 2 (pipelined, chunked): cascade all cleanup writes. Each
            // member can emit a variable number of sRem commands (one per
            // OTHER tag it belonged to), so chunking by member count is an
            // approximation — but a tighter command-level chunker would only
            // help pathological cases where members carry hundreds of tags.
            $totalMembers = count($members);
            for ($offset = 0; $offset < $totalMembers; $offset += $batchSize) {
                $redis->multi(Redis::PIPELINE);
                $end = min($offset + $batchSize, $totalMembers);
                for ($i = $offset; $i < $end; $i++) {
                    $member = $members[$i];
                    $otherTags = is_array($reverseLookups[$i] ?? null) ? $reverseLookups[$i] : [];
                    foreach ($otherTags as $otherTag) {
                        if ($otherTag === $tag) {
                            // We're about to del() the whole tag set; skip the redundant sRem.
                            continue;
                        }
                        $redis->sRem(self::TAG_KEY_SET_PREFIX . $otherTag, $member);
                    }
                    $redis->del(self::TAGS_SET_NAME_PREFIX . $member);
                }
                $redis->exec();
            }

            // Chunked bulk delete of the members themselves.
            foreach (array_chunk($members, $batchSize) as $chunk) {
                $redis->del($chunk);
            }
            $redis->del($tagSet);

            return true;
        });
    }

    /**
     * Removes a key from every tag set it belongs to and deletes its reverse
     * lookup. Caller is responsible for wrapping in error translation.
     */
    private function unindexKey(string $key): void
    {
        $redis = $this->getRedis();
        $reverseLookupKey = self::TAGS_SET_NAME_PREFIX . $key;
        $tags = $redis->sMembers($reverseLookupKey);

        if (!is_array($tags) || $tags === []) {
            $redis->del($reverseLookupKey);
            return;
        }

        $redis->multi(Redis::PIPELINE);
        foreach ($tags as $tag) {
            $redis->sRem(self::TAG_KEY_SET_PREFIX . $tag, $key);
        }
        $redis->del($reverseLookupKey);
        $redis->exec();
    }

    // -------------------------------------------------------------------
    // Queues
    // -------------------------------------------------------------------

    /**
     * Appends a value to the tail of a Redis list used as a FIFO queue.
     *
     * Pairs with {@see pop()} (head-removal) to form a producer/consumer FIFO.
     * Null values are rejected because phpredis cannot distinguish a stored
     * null from "list is empty" on pop.
     *
     * @return self for chaining.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $queue is invalid
     *         or $value is null.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testEnqueue()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testEnqueueThrowsForNullValue()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testEnqueueRejectsInvalidQueueName()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testEnqueueWrapsRedisExceptionAsCacheException()
     */
    public function enqueue(string $queue, mixed $value): self
    {
        $this->validateKey($queue);
        if (null === $value) {
            throw new InvalidArgumentException('Can\'t enqueue null item');
        }
        return $this->wrap(function () use ($queue, $value) {
            $this->getRedis()->rPush($queue, $value);
            return $this;
        });
    }

    /**
     * Removes and returns item(s) from the head of the queue (FIFO).
     *
     * - `$range === 1` (default): returns the single popped value, or `null`
     *   when the queue is empty.
     * - `$range > 1`: returns an array of up to `$range` items (whatever was
     *   available), or `null` when the queue is empty.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $queue is invalid
     *         or $range < 1.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testPopSingleItem()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testPopSingleItemReturnsNullWhenEmpty()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testPopMultipleItems()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testPopRejectsNonPositiveRange()
     */
    public function pop(string $queue, int $range = 1): mixed
    {
        $this->validateKey($queue);
        if ($range < 1) {
            throw new InvalidArgumentException('Range must be greater than or equal to 1.');
        }
        return $this->wrap(function () use ($queue, $range) {
            $redis = $this->getRedis();
            if ($range !== 1) {
                $items = $redis->lPop($queue, $range);
                return $items === false ? null : $items;
            }
            $item = $redis->lPop($queue);
            return $item === false ? null : $item;
        });
    }

    /**
     * Inspects item(s) at the head of the queue WITHOUT removing them.
     *
     * Same return shape as {@see pop()} (single value vs array based on
     * `$range`), but the queue is left unchanged.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $queue is invalid
     *         or $range < 1.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testPeekSingleItem()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testPeekSingleItemReturnsNullWhenEmpty()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testPeekMultipleItems()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testPeekMultipleReturnsArrayOfHeadItems()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testPeekMultipleReturnsNullWhenEmpty()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testPeekRejectsNonPositiveRange()
     */
    public function peek(string $queue, int $range = 1): mixed
    {
        $this->validateKey($queue);
        if ($range < 1) {
            throw new InvalidArgumentException('Range must be greater than or equal to 1.');
        }
        return $this->wrap(function () use ($queue, $range) {
            $redis = $this->getRedis();
            if ($range !== 1) {
                $items = $redis->lRange($queue, 0, $range - 1);
                return !is_array($items) || $items === [] ? null : $items;
            }
            $items = $redis->lRange($queue, 0, 0);
            return !is_array($items) || $items === [] ? null : $items[0];
        });
    }

    /**
     * Returns the entire queue contents as a list (head-first), without
     * removing anything.
     *
     * Beware: O(N) and pulls the whole list into memory — for large queues
     * prefer {@see peek()} with a bounded range.
     *
     * @return array<int, mixed>
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $queue is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetQueue()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetQueueWithEmptyQueue()
     */
    public function getQueue(string $queue): array
    {
        $this->validateKey($queue);
        return $this->wrap(function () use ($queue) {
            $items = $this->getRedis()->lRange($queue, 0, -1);
            return is_array($items) ? $items : [];
        });
    }

    /**
     * Returns the number of items currently in the queue (O(1) LLEN).
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $queue is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetQueueLength()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetQueueLengthCoercesFalseToZero()
     */
    public function getQueueLength(string $queue): int
    {
        $this->validateKey($queue);
        return $this->wrap(fn() => (int) $this->getRedis()->lLen($queue));
    }

    // -------------------------------------------------------------------
    // Counters
    // -------------------------------------------------------------------

    /**
     * Atomically increments the integer stored at $key by $value (INCRBY).
     *
     * Auto-creates the key set to 0 before the first increment. The new
     * post-increment value is not returned (this method is for fluent
     * chaining); use {@see get()} if you need it.
     *
     * Negative $value is accepted — it behaves like {@see decrease()}.
     *
     * @return self for chaining.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $key is invalid.
     * @throws CacheException On Redis transport/storage failures, including
     *         when the existing value is not a valid integer.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testIncrease()
     */
    public function increase(string $key, int $value): self
    {
        $this->validateKey($key);
        return $this->wrap(function () use ($key, $value) {
            $this->getRedis()->incrBy($key, $value);
            return $this;
        });
    }

    /**
     * Atomically decrements the integer stored at $key by $value (DECRBY).
     *
     * Mirror of {@see increase()}: auto-creates at 0, accepts negative input,
     * does not return the new value.
     *
     * @return self for chaining.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $key is invalid.
     * @throws CacheException On Redis transport/storage failures, including
     *         when the existing value is not a valid integer.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testDecrease()
     */
    public function decrease(string $key, int $value): self
    {
        $this->validateKey($key);
        return $this->wrap(function () use ($key, $value) {
            $this->getRedis()->decrBy($key, $value);
            return $this;
        });
    }

    // -------------------------------------------------------------------
    // Sets / Sorted sets
    // -------------------------------------------------------------------

    /**
     * Returns the number of members in a set or sorted set (O(1)).
     *
     * @param bool $sortedSet When true uses ZCARD, otherwise SCARD. Pick the
     *                        one matching how the key was originally written —
     *                        calling SCARD on a sorted set (or vice versa) is
     *                        a WRONGTYPE error wrapped as a CacheException.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $set is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetCardinalityForRegularSet()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetCardinalityForSortedSet()
     */
    public function getCardinality(string $set, bool $sortedSet = false): int
    {
        $this->validateKey($set);
        return $this->wrap(function () use ($set, $sortedSet) {
            $redis = $this->getRedis();
            if ($sortedSet) {
                return (int) $redis->zCard($set);
            }
            return (int) $redis->sCard($set);
        });
    }

    /**
     * Returns the number of keys currently associated with $tag (O(1) SCARD
     * on the underlying tag set).
     *
     * The count may temporarily include ghost entries until {@see getTagged()}
     * or {@see clearByTag()} prunes them.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $tag is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetTagCardinality()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetTagCardinalityCoercesFalseToZero()
     */
    public function getTagCardinality(string $tag): int
    {
        $this->validateKey($tag);
        return $this->wrap(fn() => (int) $this->getRedis()->sCard(self::TAG_KEY_SET_PREFIX . $tag));
    }

    /**
     * Iterates a window of a sorted set, paired with each member's cached value.
     *
     * Treats the sorted set as an ordered index of cache keys: each member
     * name is looked up via MGET against the main cache space, so a sorted
     * set entry whose corresponding key has expired/been deleted is detected
     * and pruned (`ZREM` + `delete()`).
     *
     * **Stored `false` vs missing**: when MGET returns `false`, an EXISTS
     * probe disambiguates — a key that still exists yields its `false` value
     * intact; one that's gone is pruned and skipped.
     *
     * **Side effects on iteration**: cleanup is interleaved with iteration
     * (unlike {@see getTagged()}'s deferred-flush model). Consumers that
     * break out early will leave un-inspected dangling entries for the next
     * call to handle.
     *
     * @param int  $count    Window size (number of consecutive members).
     * @param int  $offset   Zero-based start index within the sorted set.
     * @param bool $reversed When true uses ZREVRANGE (highest score first),
     *                       otherwise ZRANGE (lowest score first).
     *
     * @return Generator<string, mixed> Yielding sortedSetMember => cachedValue.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $set is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetSortedWithNormalOrder()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetSortedWithReversedOrder()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetSortedWithEmptySet()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetSortedRemovesDanglingMembers()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetSortedPreservesNullValuedMembers()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetSortedYieldsStoredFalseForExistingMember()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetSortedYieldsStoredFalseAndContinuesIteration()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetSortedWrapsRedisExceptionFromGenerator()
     */
    public function getSorted(string $set, int $count, int $offset = 0, bool $reversed = false): Generator
    {
        $this->validateKey($set);
        try {
            $redis = $this->getRedis();
            $end = $offset + $count - 1;

            $members = $reversed
                ? $redis->zRevRange($set, $offset, $end)
                : $redis->zRange($set, $offset, $end);

            if (!is_array($members) || $members === []) {
                return;
            }

            $values = $redis->mGet($members);

            foreach ($members as $i => $member) {
                $value = $values[$i];
                if ($value !== false) {
                    yield $member => $value;
                    continue;
                }
                // false from MGET means either "missing" or a stored literal
                // `false`. Probe EXISTS to disambiguate before pruning.
                if ($redis->exists($member)) {
                    yield $member => false;
                    continue;
                }
                $redis->zRem($set, $member);
                $this->delete($member);
            }
        } catch (RedisException $e) {
            throw $this->toCacheException($e);
        }
    }

    /**
     * Adds a single value to the set at $key (SADD).
     *
     * Auto-creates the set on first add. Sets dedupe automatically — adding
     * an existing member is a no-op.
     *
     * @return self for chaining.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $key is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testAddToSet()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testAddToSetRejectsInvalidKey()
     */
    public function addToSet(string $key, mixed $value): self
    {
        $this->validateKey($key);
        return $this->wrap(function () use ($key, $value) {
            $this->getRedis()->sAdd($key, $value);
            return $this;
        });
    }

    /**
     * Removes a single value from the set at $key (SREM).
     *
     * Idempotent: removing a non-member or operating on a missing set is a
     * no-op. The set itself is auto-deleted by Redis once empty.
     *
     * @return self for chaining.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $key is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testRemoveFromSet()
     */
    public function removeFromSet(string $key, mixed $value): self
    {
        $this->validateKey($key);
        return $this->wrap(function () use ($key, $value) {
            $this->getRedis()->sRem($key, $value);
            return $this;
        });
    }

    /**
     * Returns all members of the set at $key (SMEMBERS).
     *
     * Sets are unordered — the returned list has no meaningful ordering.
     *
     * @return list<string>|null Null when the set doesn't exist; an empty
     *                           list when the set exists but has no members.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $key is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetSet()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetSetReturnsNullWhenNotExists()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetSetReturnsNumericallyIndexedArray()
     */
    public function getSet(string $key): ?array
    {
        $this->validateKey($key);
        return $this->wrap(function () use ($key) {
            $members = $this->getRedis()->sMembers($key);
            return is_array($members) ? array_values($members) : null;
        });
    }

    /**
     * Atomically replaces the set at $key with exactly the given members.
     *
     * Implemented as DEL + SADD (single SADDARRAY when $values is non-empty).
     * Use this when you have the full desired contents and want a clean
     * swap; for incremental changes prefer {@see addToSet()} /
     * {@see removeFromSet()}.
     *
     * Passing an empty array effectively deletes the set.
     *
     * @param array<int, mixed> $values
     *
     * @return self for chaining.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException If $key is invalid.
     * @throws CacheException On Redis transport/storage failures.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testCreateSet()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testCreateSetWithEmptyArrayDeletesWithoutSAdd()
     */
    public function createSet(string $key, array $values): self
    {
        $this->validateKey($key);
        return $this->wrap(function () use ($key, $values) {
            $redis = $this->getRedis();
            $redis->del($key);
            if ($values === []) {
                return $this;
            }
            $redis->sAddArray($key, $values);
            return $this;
        });
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    /**
     * Enforces PSR-16's cache-key rules: non-empty string, free of the
     * reserved characters {@see RESERVED_KEY_CHARS}.
     *
     * Accepts `mixed` (rather than `string`) so it can be called on
     * loosely-typed iterable members straight from `getMultiple()` etc.
     * without an extra cast.
     *
     * @throws InvalidArgumentException Which implements PSR-16's
     *         {@see \Psr\SimpleCache\InvalidArgumentException}.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetRejectsInvalidKey()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetRejectsInvalidKey()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testDeleteRejectsInvalidKey()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testValidateKeyRejectsBackslashCharacter()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testValidateKeyRejectsBraceCharacter()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testValidateKeyRejectsParenCharacter()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testRejectsInvalidKeyAcrossEveryApi()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testInvalidArgumentExceptionIsPsrCompliant()
     */
    private function validateKey(mixed $key): void
    {
        if (!is_string($key) || $key === '') {
            throw new InvalidArgumentException('Cache key must be a non-empty string.');
        }
        if (preg_match('#[' . preg_quote(self::RESERVED_KEY_CHARS, '#') . ']#', $key) === 1) {
            throw new InvalidArgumentException(
                sprintf('Cache key "%s" contains reserved characters (%s).', $key, self::RESERVED_KEY_CHARS)
            );
        }
    }

    /**
     * Collapses PSR-16's `null|int|DateInterval` TTL form into a single
     * "seconds from now" integer (or null = no expiry).
     *
     * DateInterval is resolved against the current moment, so a `P1D`
     * interval becomes ~86400 seconds. Returned value may be ≤ 0 if the
     * interval points to the past — callers are expected to treat that as
     * an immediate-delete signal.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetWithDateIntervalTtl()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetWithZeroTtlDeletesEntry()
     */
    private function normalizeTtl(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();
            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }
        return $ttl;
    }

    /**
     * Runs the given callable and translates phpredis storage errors into
     * Psr\SimpleCache\CacheException, as PSR-16 requires.
     *
     * On RedisException:
     * - Resets the cached connection so the next call reconnects (a single
     *   network blip doesn't poison the client for the rest of the request).
     * - If {@see RedisConnectionConfig::$retryOnce} is true, retries the
     *   operation exactly once with a fresh connection before giving up.
     *
     * @template T
     * @param callable():T $op
     * @return T
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testWrapResetsConnectionOnRedisException()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testRetryOnceRecoversFromTransientError()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testRetryOnceDoesNotMaskPermanentError()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetWrapsRedisExceptionAsCacheException()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testSetWrapsRedisExceptionAsCacheException()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testEnqueueWrapsRedisExceptionAsCacheException()
     */
    private function wrap(callable $op): mixed
    {
        try {
            return $op();
        } catch (RedisException $e) {
            $this->redis = null;
            if ($this->config->retryOnce && !$this->retrying) {
                $this->retrying = true;
                try {
                    return $op();
                } catch (RedisException $retryError) {
                    throw $this->toCacheException($retryError);
                } finally {
                    $this->retrying = false;
                }
            }
            throw $this->toCacheException($e);
        }
    }

    /**
     * Wraps a phpredis {@see RedisException} as the package's
     * {@see CacheException} (which is PSR-16's
     * {@see \Psr\SimpleCache\CacheException}), preserving the original as
     * the chained cause.
     *
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testInvalidArgumentExceptionIsPsrCompliant()
     * @see \IDCT\Tests\Cache\Unit\RapidCacheClientTest::testGetWrapsRedisExceptionAsCacheException()
     */
    private function toCacheException(RedisException $e): CacheException
    {
        return new CacheException(
            sprintf('Redis operation failed: %s', $e->getMessage()),
            0,
            $e
        );
    }
}
