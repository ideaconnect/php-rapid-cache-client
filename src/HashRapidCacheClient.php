<?php

declare(strict_types=1);

namespace IDCT\Cache;

use DateInterval;
use IDCT\Cache\Exception\CacheException;
use IDCT\Cache\Exception\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use Redis;
use RedisException;

/**
 * High-performance Redis hash-backed cache client for flat associative arrays.
 *
 * Where {@see RapidCacheClient} serializes arbitrary PHP values (with igbinary)
 * and stores each as a Redis string, this client takes the opposite trade: the
 * cached value MUST be a non-empty flat array of scalars (string/int/float)
 * and gets stored field-for-field as a Redis HASH. No serializer is configured
 * - values go on the wire as native Redis strings.
 *
 * What you get in exchange:
 * - HGETALL / HGET / HSET / HINCRBY semantics: read or update a single field
 *   without round-tripping the whole array.
 * - Atomic in-place counters (HINCRBY / HINCRBYFLOAT) on individual fields.
 * - Predictable, igbinary-free wire format.
 *
 * What you give up:
 * - Cannot store anything that isn't a flat array of scalars.
 * - No per-field TTL - the whole hash expires as a unit via PEXPIRE.
 * - bool / null / nested arrays / objects are rejected; use
 *   {@see RapidCacheClient} when type fidelity matters.
 *
 * Tagging mirrors {@see RapidCacheClient}'s dual-index design but lives under
 * separate `H_TAG:` and `H_TAGS:` prefixes so the two clients can coexist on
 * the same Redis namespace without their tag indexes colliding (a tag set
 * populated by one client would otherwise feed HGETALL across keys of the
 * wrong Redis type and trigger WRONGTYPE errors).
 */
class HashRapidCacheClient implements CacheInterface
{
    /** Standard Redis TCP port, used when the legacy constructor gets a null port. */
    public const DEFAULT_REDIS_PORT = 6379;

    /**
     * Tagging uses the same dual-index design as {@see RapidCacheClient}:
     *
     *   H_TAG:<tag>   -> SET of cache keys carrying that tag   (forward index)
     *   H_TAGS:<key>  -> SET of tags attached to that key       (reverse index)
     *
     * The prefixes intentionally differ from the string client's so that both
     * clients can operate on the same Redis instance with the same OPT_PREFIX
     * without their tag spaces overlapping - a `TAG:foo` populated by the
     * string client and read back by the hash client would feed HGETALL across
     * non-hash keys and surface WRONGTYPE errors.
     */
    private const H_TAGS_SET_NAME_PREFIX = 'H_TAGS:';

    /** @see H_TAGS_SET_NAME_PREFIX for the full description of the dual-index scheme. */
    private const H_TAG_KEY_SET_PREFIX = 'H_TAG:';

    /**
     * PSR-16 reserved key chars, mirroring {@see RapidCacheClient::RESERVED_KEY_CHARS}.
     * `:` is rejected because phpredis uses it as the conventional key-prefix
     * separator; `\` because it has historically caused encoding surprises.
     */
    private const RESERVED_KEY_CHARS = '{}()/\\@:';

    /**
     * The live phpredis handle, or null before the first connect / after a
     * transport error nulled it in {@see wrap()}. Never read directly - go
     * through {@see getRedis()} so a dropped connection is transparently
     * re-established.
     */
    private ?\Redis $redis = null;

    /** Immutable connection settings resolved once in the constructor. */
    private RedisConnectionConfig $config;

    /**
     * Re-entrancy guard for the retry-once logic in {@see wrap()}. Stops the
     * single permitted retry from itself triggering another retry. Set true
     * only for the duration of the retry attempt.
     */
    private bool $retrying = false;

    /**
     * Accepts either the legacy positional form (host, port, prefix) or a
     * pre-built {@see RedisConnectionConfig} for full control over auth,
     * database, timeouts, and persistence.
     *
     * No connection is opened here - the first cache operation triggers a
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
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testLegacyConstructorAppliesDefaultPortWhenNullGiven()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testLegacyConstructorPreservesExplicitPort()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testConstructorAcceptsRedisConnectionConfig()
     */
    public function __construct(
        string|RedisConnectionConfig $hostOrConfig,
        ?int $port = self::DEFAULT_REDIS_PORT,
        ?string $prefix = null,
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
     * options (auth, database, timeouts, prefix).
     *
     * Unlike {@see RapidCacheClient::reconnect()}, this client does NOT enable
     * the igbinary serializer: values are stored as native Redis hash fields,
     * which must be plain byte strings.
     *
     * Wraps any connection failure - including engine-level errors that aren't
     * RedisException - into a {@see CacheException}. Pure-PHP coding bugs
     * ({@see \Error}) are re-thrown unchanged so they aren't masked.
     *
     * @throws CacheException when connection or option-setup fails for any
     *                        non-engine reason
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testReconnectAppliesAllConfigFields()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testReconnectWithoutPasswordSkipsAuth()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testReconnectWithEmptyPasswordSkipsAuth()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testReconnectWithDatabaseZeroSkipsSelect()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testReconnectWithoutPrefixSkipsPrefixOption()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testReconnectWithZeroReadTimeoutSkipsReadTimeoutOption()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testReconnectUsesNonPersistentConnectByDefault()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testReconnectDoesNotEnableIgbinarySerializer()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testReconnectWrapsRedisExceptionAsCacheException()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testReconnectWrapsArbitraryThrowable()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testReconnectLetsErrorsPropagate()
     */
    protected function reconnect(): \Redis
    {
        $config = $this->config;
        try {
            $redis = $this->createRedisInstance();
            if ($config->persistent) {
                $redis->pconnect(
                    $config->host,
                    $config->port,
                    $config->connectTimeout,
                    $config->persistentId,
                );
            } else {
                $redis->connect($config->host, $config->port, $config->connectTimeout);
            }
            if ($config->readTimeout > 0) {
                $redis->setOption(\Redis::OPT_READ_TIMEOUT, (string) $config->readTimeout);
            }
            if (null !== $config->password && '' !== $config->password) {
                $redis->auth($config->password);
            }
            if (0 !== $config->database) {
                $redis->select($config->database);
            }
            if (null !== $config->prefix && '' !== $config->prefix) {
                $redis->setOption(\Redis::OPT_PREFIX, $config->prefix);
            }
            // Deliberately do NOT enable Redis::OPT_SERIALIZER here. Hash field
            // values must be plain byte strings; an igbinary-serialized payload
            // would prevent HINCRBY from operating on counter fields.
        } catch (\Error $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CacheException(\sprintf('Unable to connect to Redis at %s:%d: %s', $config->host, $config->port, $e->getMessage()), 0, $e);
        }
        $this->redis = $redis;

        return $redis;
    }

    /**
     * Factory hook for the underlying phpredis instance. Override in tests or
     * subclasses to inject a mock/decorated Redis without touching the
     * connection lifecycle.
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testCreateRedisInstanceReturnsRealRedis()
     */
    protected function createRedisInstance(): \Redis
    {
        return new \Redis();
    }

    /**
     * Returns the live Redis connection, reconnecting on demand if the cached
     * one was lost.
     *
     * @throws CacheException when the lazy reconnect fails
     */
    protected function getRedis(): \Redis
    {
        if (null === $this->redis || !$this->redis->isConnected()) {
            return $this->reconnect();
        }

        return $this->redis;
    }

    // -------------------------------------------------------------------
    // PSR-16 CacheInterface
    // -------------------------------------------------------------------

    /**
     * Retrieves a value from the cache as a flat associative array.
     *
     * Returns `$default` when the underlying hash does not exist. Because
     * {@see set()} rejects empty arrays, an empty HGETALL result is
     * unambiguously a miss - no follow-up EXISTS probe is needed.
     *
     * @return array<string, string>|mixed the stored field map on hit, or
     *                                     $default on miss
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key violates PSR-16
     *                                                   (empty or contains reserved characters)
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetReturnsHashAsArrayWhenExists()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetReturnsDefaultWhenMissing()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetReturnsCustomDefaultWhenMissing()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetRejectsInvalidKey()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetWrapsRedisExceptionAsCacheException()
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        return $this->wrap(function () use ($key, $default) {
            $value = $this->getRedis()->hGetAll($key);
            if (!\is_array($value) || [] === $value) {
                return $default;
            }

            return $value;
        });
    }

    /**
     * Stores a flat associative array as a Redis HASH, optionally with a TTL.
     *
     * Contract:
     * - `$value` MUST be a non-empty flat array; every element must be
     *   string|int|float. bool, null, nested arrays, objects, and resources
     *   are rejected (`InvalidArgumentException`). Use {@see RapidCacheClient}
     *   when type fidelity beyond scalars is needed.
     * - The whole hash is the unit of expiry. There is no per-field TTL.
     * - The existing key (if any) is replaced wholesale - DEL is issued inside
     *   the same pipeline before the new HSET, so a pre-existing hash with
     *   extra fields does not bleed into the new value.
     *
     * TTL semantics mirror {@see RapidCacheClient::set()}:
     * - `null`     - persist indefinitely.
     * - positive   - expire after N seconds (PEXPIRE in milliseconds).
     * - DateInterval - resolved to seconds from now.
     * - `0` or negative - treated as immediate expiry: the key is deleted and
     *   any tag associations cleaned up. Returns true.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key is invalid or
     *                                                   $value is not a flat array of scalars
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetWithoutTtl()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetWithIntegerTtl()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetWithDateIntervalTtl()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetWithZeroTtlDeletesEntry()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetWithNegativeTtlDeletesEntry()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsInvalidKey()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsNonArrayValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsEmptyArrayValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsBooleanFieldValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsNullFieldValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsNestedArrayFieldValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsObjectFieldValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetReturnsFalseWhenExecReturnsNonArray()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetReturnsFalseWhenAnyExecResultIsFalse()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetWrapsRedisExceptionAsCacheException()
     */
    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateArrayValue($value);
        $seconds = $this->normalizeTtl($ttl);

        return $this->wrap(function () use ($key, $value, $seconds) {
            $redis = $this->getRedis();

            if (null !== $seconds && $seconds <= 0) {
                $this->unindexKey($key);
                $redis->del($key);

                return true;
            }

            $redis->multi(\Redis::PIPELINE);
            $redis->del($key);
            $redis->hMSet($key, $value);
            if (null !== $seconds) {
                $redis->pExpire($key, $seconds * 1000);
            }
            $results = $redis->exec();

            return \is_array($results) && !\in_array(false, $results, true);
        });
    }

    /**
     * Deletes a key and removes it from every tag set it belonged to.
     *
     * Idempotent: returns true whether or not the key existed.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key is invalid
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDeleteRemovesTagAssociations()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDeleteWithoutTagAssociations()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDeleteRejectsInvalidKey()
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
     * Behaviour mirrors {@see RapidCacheClient::clear()}: with a prefix
     * configured, scans for `<prefix>*` and UNLINKs in batches (leaving keys
     * from other apps intact); without a prefix, FLUSHDB nukes the database.
     *
     * @throws CacheException on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testClearWithoutPrefixUsesFlushDb()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testClearWithPrefixScansAndUnlinks()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testClearWithPrefixIteratesMultipleScanBatches()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testClearSkipsUnlinkOnEmptyBatch()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testClearBreaksOnScanFailure()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testClearRestoresPrefixAndScanOptionsEvenWhenScanThrows()
     */
    public function clear(): bool
    {
        return $this->wrap(function () {
            $redis = $this->getRedis();
            $prefix = $this->config->prefix;

            if (null === $prefix || '' === $prefix) {
                $redis->flushDb();

                return true;
            }

            $priorScan = $redis->getOption(\Redis::OPT_SCAN);
            $redis->setOption(\Redis::OPT_PREFIX, '');
            try {
                $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
                $iterator = null;
                do {
                    $keys = $redis->scan($iterator, $prefix.'*', 1000);
                    if (false === $keys) {
                        break;
                    }
                    if ([] !== $keys) {
                        $redis->unlink($keys);
                    }
                } while ($iterator > 0);
            } finally {
                $redis->setOption(\Redis::OPT_PREFIX, $prefix);
                $redis->setOption(\Redis::OPT_SCAN, $priorScan);
            }

            return true;
        });
    }

    /**
     * Bulk get: pipelined HGETALL for each key, chunked to the configured
     * pipeline batch size.
     *
     * @return array<string, mixed> keyed by the originally requested keys; a
     *                              missing key (empty HGETALL result) maps to
     *                              $default
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if any key is invalid
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetMultiple()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetMultipleChunksByConfiguredBatchSize()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetMultipleFillsDefaultsWhenExecReturnsNonArray()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetMultipleWithEmptyKeysReturnsEmptyArrayWithoutRedisCall()
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $normalized = [];
        foreach ($keys as $key) {
            $this->validateKey($key);
            $normalized[] = $key;
        }

        if ([] === $normalized) {
            return [];
        }

        return $this->wrap(function () use ($normalized, $default) {
            $redis = $this->getRedis();
            $result = [];
            foreach (\array_chunk($normalized, $this->config->pipelineBatchSize) as $chunk) {
                $redis->multi(\Redis::PIPELINE);
                foreach ($chunk as $key) {
                    $redis->hGetAll($key);
                }
                $values = $redis->exec();
                if (!\is_array($values)) {
                    foreach ($chunk as $key) {
                        $result[$key] = $default;
                    }

                    continue;
                }
                foreach ($chunk as $i => $key) {
                    $value = $values[$i] ?? null;
                    $result[$key] = \is_array($value) && [] !== $value ? $value : $default;
                }
            }

            return $result;
        });
    }

    /**
     * Bulk store: pipelined DEL + HSET (+ optional PEXPIRE) for each key,
     * chunked to the configured pipeline batch size.
     *
     * - TTL ≤ 0: bulk DELETE + tag cleanup, returns true.
     * - With TTL: each key gets PEXPIRE inside the same pipelined batch.
     * - Without TTL: keys persist until explicitly evicted.
     *
     * Returns false if any individual command in any batch's EXEC reports
     * false, or if EXEC itself returns a non-array.
     *
     * @param iterable<mixed> $values map of key → flat associative array
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if any key is invalid
     *                                                   or any value fails the flat-array-of-scalars contract
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetMultipleWithoutTtl()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetMultipleWithTtl()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetMultipleChunksByConfiguredBatchSize()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetMultipleWithZeroTtlDeletesKeysAndSkipsWrites()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetMultipleWithNegativeTtlDeletesKeys()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetMultipleWithEmptyValuesReturnsTrueWithoutRedisCall()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetMultipleReturnsFalseWhenExecReturnsNonArray()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetMultipleReturnsFalseWhenAnyExecResultIsFalse()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetMultipleRejectsInvalidValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetMultipleNormalizesIntegerKeysToStrings()
     */
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $stringKey = \is_string($key) ? $key : (string) $key;
            $this->validateKey($stringKey);
            $this->validateArrayValue($value);
            $normalized[$stringKey] = $value;
        }
        if ([] === $normalized) {
            return true;
        }

        $seconds = $this->normalizeTtl($ttl);

        return $this->wrap(function () use ($normalized, $seconds) {
            $redis = $this->getRedis();
            $batchSize = $this->config->pipelineBatchSize;

            if (null !== $seconds && $seconds <= 0) {
                foreach (\array_keys($normalized) as $key) {
                    $this->unindexKey((string) $key);
                }
                foreach (\array_chunk(\array_keys($normalized), $batchSize) as $chunk) {
                    $redis->del(\array_map(strval(...), $chunk));
                }

                return true;
            }

            foreach (\array_chunk($normalized, $batchSize, true) as $chunk) {
                $redis->multi(\Redis::PIPELINE);
                foreach ($chunk as $key => $value) {
                    $stringKey = (string) $key;
                    $redis->del($stringKey);
                    $redis->hMSet($stringKey, $value);
                    if (null !== $seconds) {
                        $redis->pExpire($stringKey, $seconds * 1000);
                    }
                }
                $results = $redis->exec();
                if (!\is_array($results) || \in_array(false, $results, true)) {
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
     * @throws \Psr\SimpleCache\InvalidArgumentException if any key is invalid
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDeleteMultiple()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDeleteMultipleChunksByConfiguredBatchSize()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDeleteMultipleWithEmptyKeysReturnsTrueWithoutRedisCall()
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $normalized = [];
        foreach ($keys as $key) {
            $this->validateKey($key);
            $normalized[] = $key;
        }
        if ([] === $normalized) {
            return true;
        }

        return $this->wrap(function () use ($normalized) {
            $redis = $this->getRedis();
            foreach ($normalized as $key) {
                $this->unindexKey($key);
            }
            foreach (\array_chunk($normalized, $this->config->pipelineBatchSize) as $chunk) {
                $redis->del($chunk);
            }

            return true;
        });
    }

    /**
     * Checks key existence via a single EXISTS call.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key is invalid
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testHas()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testHasReturnsFalseWhenMissing()
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        return $this->wrap(fn () => (bool) $this->getRedis()->exists($key));
    }

    // -------------------------------------------------------------------
    // Tagging
    // -------------------------------------------------------------------

    /**
     * Stores a value and atomically associates it with a tag.
     *
     * Single MULTI/EXEC pipeline: the DEL + HSET (+ optional PEXPIRE) and both
     * index writes commit together.
     *
     * TTL ≤ 0 short-circuits to {@see set()}'s delete branch - tagging a key
     * you're about to delete makes no sense, so tagging is skipped.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key or $tag is
     *                                                   invalid, or $value fails the flat-array-of-scalars contract
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetTagged()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetTaggedWithTtl()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetTaggedWithZeroTtlShortCircuitsToDelete()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetTaggedWithNegativeTtlShortCircuitsToDelete()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetTaggedReturnsFalseWhenExecReturnsNonArray()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetTaggedReturnsFalseWhenAnyExecResultIsFalse()
     */
    public function setTagged(string $key, mixed $value, string $tag, int|\DateInterval|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateKey($tag);
        $this->validateArrayValue($value);
        $seconds = $this->normalizeTtl($ttl);

        if (null !== $seconds && $seconds <= 0) {
            return $this->set($key, $value, $ttl);
        }

        return $this->wrap(function () use ($key, $value, $tag, $seconds) {
            $redis = $this->getRedis();
            $redis->multi(\Redis::PIPELINE);
            $redis->del($key);
            $redis->hMSet($key, $value);
            if (null !== $seconds) {
                $redis->pExpire($key, $seconds * 1000);
            }
            $redis->sAdd(self::H_TAG_KEY_SET_PREFIX.$tag, $key);
            $redis->sAdd(self::H_TAGS_SET_NAME_PREFIX.$key, $tag);
            $results = $redis->exec();

            return \is_array($results) && !\in_array(false, $results, true);
        });
    }

    /**
     * Iterates over all hashes currently associated with $tag.
     *
     * Streams the tag set with SSCAN (no SMEMBERS up-front) and resolves each
     * page's keys with one pipelined HGETALL batch. Memory and server-side
     * blocking stay bounded regardless of tag size.
     *
     * **Side effects on iteration**: tag entries whose underlying hash has
     * expired/been deleted are pruned per page. Cleanup runs in a `finally`
     * block, so an early `break` still cleans up what was inspected so far;
     * un-inspected entries are left for the next call. Cleanup failures are
     * swallowed (best-effort) so they don't mask the primary exception path.
     *
     * @return \Generator<string, array<string, string>> yielding cacheKey =>
     *                                                   storedHash
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $tag is invalid
     * @throws CacheException                            on Redis transport/storage failures during the
     *                                                   primary read phase
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetTaggedYieldsHashesFromSingleScanPage()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetTaggedIteratesMultipleScanPages()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetTaggedPrunesExpiredMembers()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetTaggedWithNoMembers()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetTaggedSkipsBatchesWhereExecReturnsNonArray()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetTaggedSwallowsCleanupRedisException()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetTaggedWrapsRedisExceptionFromGenerator()
     */
    public function getTagged(string $tag): \Generator
    {
        $this->validateKey($tag);
        $stale = [];
        try {
            $redis = $this->getRedis();
            $tagSet = self::H_TAG_KEY_SET_PREFIX.$tag;
            $iterator = null;
            do {
                $members = $redis->sScan($tagSet, $iterator, '*', $this->config->pipelineBatchSize);
                if (!\is_array($members) || [] === $members) {
                    continue;
                }
                $members = array_values($members);
                $redis->multi(\Redis::PIPELINE);
                foreach ($members as $member) {
                    $redis->hGetAll($member);
                }
                $values = $redis->exec();
                if (!\is_array($values)) {
                    continue;
                }
                foreach ($members as $i => $member) {
                    $value = $values[$i] ?? null;
                    if (\is_array($value) && [] !== $value) {
                        yield $member => $value;
                    } else {
                        $stale[] = $member;
                    }
                }
            } while ($iterator > 0);
        } catch (\RedisException $e) {
            throw $this->toCacheException($e);
        } finally {
            if ([] !== $stale) {
                try {
                    $redis = $this->getRedis();
                    $tagSet = self::H_TAG_KEY_SET_PREFIX.$tag;
                    $redis->multi(\Redis::PIPELINE);
                    foreach ($stale as $member) {
                        $redis->sRem($tagSet, $member);
                        $redis->sRem(self::H_TAGS_SET_NAME_PREFIX.$member, $tag);
                    }
                    $redis->exec();
                } catch (\RedisException) {
                    // Best-effort cleanup - don't shadow the primary exception.
                }
            }
        }
    }

    /**
     * Adds a tag association to an existing key.
     *
     * The key must already exist as a hash; tagging a missing key would create
     * a ghost association that {@see getTagged()} would later have to clean up.
     *
     * Race window: the EXISTS + SADD pair is not atomic. A concurrent delete
     * between the two can still leave a ghost membership in the tag set -
     * {@see getTagged()} heals such entries on read.
     *
     * @return self for chaining
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key or $tag is
     *                                                   invalid, or if $key does not exist
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testTagExistingKey()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testTagNonExistingKey()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testTagRejectsInvalidTagName()
     */
    public function tag(string $key, string $tag): self
    {
        $this->validateKey($key);
        $this->validateKey($tag);

        return $this->wrap(function () use ($key, $tag) {
            $redis = $this->getRedis();
            if (!$redis->exists($key)) {
                throw new InvalidArgumentException(\sprintf('Can\'t tag non-existing key "%s"', $key));
            }
            $redis->multi(\Redis::PIPELINE);
            $redis->sAdd(self::H_TAG_KEY_SET_PREFIX.$tag, $key);
            $redis->sAdd(self::H_TAGS_SET_NAME_PREFIX.$key, $tag);
            $redis->exec();

            return $this;
        });
    }

    /**
     * Removes a single tag association from a key.
     *
     * Symmetric to {@see tag()}: removes the key from the tag's set AND the
     * tag from the key's reverse-lookup set. The underlying hash is left in
     * place - use {@see delete()} to remove it entirely.
     *
     * Idempotent.
     *
     * @return self for chaining
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key or $tag is invalid
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testUntag()
     */
    public function untag(string $key, string $tag): self
    {
        $this->validateKey($key);
        $this->validateKey($tag);

        return $this->wrap(function () use ($key, $tag) {
            $redis = $this->getRedis();
            $redis->sRem(self::H_TAG_KEY_SET_PREFIX.$tag, $key);
            $redis->sRem(self::H_TAGS_SET_NAME_PREFIX.$key, $tag);

            return $this;
        });
    }

    /**
     * Deletes every key currently associated with $tag, plus all related index
     * entries (the tag set itself and each key's reverse-lookup set).
     *
     * Mirrors {@see RapidCacheClient::clearByTag()}'s two-phase cascade:
     *  1. One MULTI/EXEC per chunk fetches each affected key's reverse tag
     *     list (so we know which OTHER tag sets need to drop this key too).
     *  2. A second pass of pipelined cleanup writes removes the cross-tag
     *     references and reverse-lookup sets, then deletes the keys
     *     themselves in chunked DELs and finally the originating tag set.
     *
     * Idempotent: returns true even when the tag is unknown or empty.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $tag is invalid
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testClearByTag()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testClearByTagRemovesKeysFromOtherTagsToo()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testClearByTagEmptyTagSet()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testClearByTagPadsReverseLookupsWhenPhaseOneExecReturnsNonArray()
     */
    public function clearByTag(string $tag): bool
    {
        $this->validateKey($tag);

        return $this->wrap(function () use ($tag) {
            $redis = $this->getRedis();
            $tagSet = self::H_TAG_KEY_SET_PREFIX.$tag;
            $members = $redis->sMembers($tagSet);

            if (!\is_array($members) || [] === $members) {
                $redis->del($tagSet);

                return true;
            }

            $batchSize = $this->config->pipelineBatchSize;

            $reverseLookups = [];
            foreach (\array_chunk($members, $batchSize) as $chunk) {
                $redis->multi(\Redis::PIPELINE);
                foreach ($chunk as $member) {
                    $redis->sMembers(self::H_TAGS_SET_NAME_PREFIX.$member);
                }
                $batchResult = $redis->exec();
                if (\is_array($batchResult)) {
                    foreach ($batchResult as $entry) {
                        $reverseLookups[] = $entry;
                    }
                } else {
                    foreach ($chunk as $_) {
                        $reverseLookups[] = null;
                    }
                }
            }

            $totalMembers = count($members);
            for ($offset = 0; $offset < $totalMembers; $offset += $batchSize) {
                $redis->multi(\Redis::PIPELINE);
                $end = min($offset + $batchSize, $totalMembers);
                for ($i = $offset; $i < $end; ++$i) {
                    $member = $members[$i];
                    $otherTags = \is_array($reverseLookups[$i] ?? null) ? $reverseLookups[$i] : [];
                    foreach ($otherTags as $otherTag) {
                        if ($otherTag === $tag) {
                            continue;
                        }
                        $redis->sRem(self::H_TAG_KEY_SET_PREFIX.$otherTag, $member);
                    }
                    $redis->del(self::H_TAGS_SET_NAME_PREFIX.$member);
                }
                $redis->exec();
            }

            foreach (\array_chunk($members, $batchSize) as $chunk) {
                $redis->del($chunk);
            }
            $redis->del($tagSet);

            return true;
        });
    }

    /**
     * Returns the number of keys currently associated with $tag (O(1) SCARD).
     *
     * The count may temporarily include ghost entries until {@see getTagged()}
     * or {@see clearByTag()} prunes them.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $tag is invalid
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetTagCardinality()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetTagCardinalityCoercesFalseToZero()
     */
    public function getTagCardinality(string $tag): int
    {
        $this->validateKey($tag);

        return $this->wrap(fn () => (int) $this->getRedis()->sCard(self::H_TAG_KEY_SET_PREFIX.$tag));
    }

    // -------------------------------------------------------------------
    // Single-field operations
    // -------------------------------------------------------------------

    /**
     * Reads a single field of the hash at $key (HGET).
     *
     * @return string|mixed the field value as returned by Redis (always a
     *                      string on hit), or $default when the field (or the
     *                      whole hash) doesn't exist
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key is invalid or
     *                                                   $field is empty
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetField()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetFieldReturnsDefaultWhenMissing()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetFieldRejectsEmptyField()
     */
    public function getField(string $key, string $field, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $this->validateField($field);

        return $this->wrap(function () use ($key, $field, $default) {
            $value = $this->getRedis()->hGet($key, $field);

            return false === $value ? $default : $value;
        });
    }

    /**
     * Writes a single field on the hash at $key (HSET).
     *
     * Creates the hash if it didn't exist. **No TTL is applied on creation** -
     * a hash born this way persists until explicitly deleted or until a
     * subsequent {@see set()} replaces it with an expiry. Use {@see set()} if
     * you need TTL semantics.
     *
     * @return self for chaining
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key is invalid or
     *                                                   $field is empty
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetField()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetFieldCreatesKeyWhenMissing()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetFieldRejectsEmptyField()
     */
    public function setField(string $key, string $field, string|int|float $value): self
    {
        $this->validateKey($key);
        $this->validateField($field);

        return $this->wrap(function () use ($key, $field, $value) {
            $this->getRedis()->hSet($key, $field, $value);

            return $this;
        });
    }

    /**
     * Removes a single field from the hash at $key (HDEL).
     *
     * Idempotent: deleting a missing field, or operating on a missing hash, is
     * a no-op. Redis auto-deletes the hash itself once its last field is
     * removed - if that happens, any tag associations remain as ghost entries
     * and will be pruned the next time {@see getTagged()} runs.
     *
     * @return self for chaining
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key is invalid or
     *                                                   $field is empty
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDeleteField()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDeleteFieldRejectsEmptyField()
     */
    public function deleteField(string $key, string $field): self
    {
        $this->validateKey($key);
        $this->validateField($field);

        return $this->wrap(function () use ($key, $field) {
            $this->getRedis()->hDel($key, $field);

            return $this;
        });
    }

    /**
     * Checks whether the hash at $key contains $field (HEXISTS).
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key is invalid or
     *                                                   $field is empty
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testHasField()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testHasFieldReturnsFalseWhenMissing()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testHasFieldRejectsEmptyField()
     */
    public function hasField(string $key, string $field): bool
    {
        $this->validateKey($key);
        $this->validateField($field);

        return $this->wrap(fn () => (bool) $this->getRedis()->hExists($key, $field));
    }

    // -------------------------------------------------------------------
    // Multi-field operations
    // -------------------------------------------------------------------

    /**
     * Reads several fields of the hash at $key in a single HMGET round-trip.
     *
     * @param list<string> $fields  Field names to fetch. An empty
     *                              list short-circuits to `[]`
     *                              without touching Redis.
     * @param mixed        $default returned per-field when a field
     *                              (or the whole hash) is missing
     *
     * @return array<string, mixed> associative result keyed by the requested
     *                              field names, in input order
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key is invalid or
     *                                                   any element of $fields is empty/non-string
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetFields()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetFieldsFillsDefaultsForMissingFields()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetFieldsFillsDefaultsWhenHMGetReturnsNonArray()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetFieldsWithEmptyFieldsReturnsEmptyArrayWithoutRedisCall()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetFieldsRejectsInvalidField()
     */
    public function getFields(string $key, array $fields, mixed $default = null): array
    {
        $this->validateKey($key);
        foreach ($fields as $field) {
            if (!\is_string($field) || '' === $field) {
                throw new InvalidArgumentException('Field name must be a non-empty string.');
            }
        }
        if ([] === $fields) {
            return [];
        }

        return $this->wrap(function () use ($key, $fields, $default) {
            $values = $this->getRedis()->hMGet($key, $fields);
            if (!\is_array($values)) {
                $values = [];
            }
            $result = [];
            foreach ($fields as $field) {
                if (!\array_key_exists($field, $values) || false === $values[$field]) {
                    $result[$field] = $default;

                    continue;
                }
                $result[$field] = $values[$field];
            }

            return $result;
        });
    }

    /**
     * Writes several fields to the hash at $key in one HMSET round-trip.
     *
     * Merges with any existing fields - unlike {@see set()}, the hash is NOT
     * cleared first. Use {@see set()} for a wholesale replace.
     *
     * Each value must satisfy the {@see set()} scalar contract: string, int,
     * or float. bool, null, nested arrays, objects, resources are rejected.
     *
     * Empty $fields short-circuits to a no-op (no Redis call).
     *
     * @param array<int|string, mixed> $fields validated at runtime - each value
     *                                         must be string|int|float; bool,
     *                                         null, nested arrays, objects and
     *                                         resources are rejected with
     *                                         {@see InvalidArgumentException}
     *
     * @return self for chaining
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key is invalid or
     *                                                   any value fails the scalar contract
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetFields()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetFieldsMergesWithExistingHash()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetFieldsWithEmptyArrayIsNoOp()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetFieldsRejectsBooleanValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetFieldsRejectsNullValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetFieldsRejectsNestedArrayValue()
     */
    public function setFields(string $key, array $fields): self
    {
        $this->validateKey($key);
        foreach ($fields as $value) {
            if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
                throw new InvalidArgumentException(\sprintf('Field values must be string|int|float, got %s.', get_debug_type($value)));
            }
        }
        if ([] === $fields) {
            return $this;
        }

        return $this->wrap(function () use ($key, $fields) {
            $this->getRedis()->hMSet($key, $fields);

            return $this;
        });
    }

    /**
     * Removes several fields from the hash at $key in one HDEL round-trip.
     *
     * Idempotent: missing fields are silently skipped by Redis. Empty $fields
     * short-circuits to a no-op (no Redis call).
     *
     * @param list<string> $fields
     *
     * @return self for chaining
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key is invalid or
     *                                                   any element of $fields is empty/non-string
     * @throws CacheException                            on Redis transport/storage failures
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDeleteFields()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDeleteFieldsWithEmptyArrayIsNoOp()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDeleteFieldsRejectsInvalidField()
     */
    public function deleteFields(string $key, array $fields): self
    {
        $this->validateKey($key);
        foreach ($fields as $field) {
            if (!\is_string($field) || '' === $field) {
                throw new InvalidArgumentException('Field name must be a non-empty string.');
            }
        }
        if ([] === $fields) {
            return $this;
        }

        return $this->wrap(function () use ($key, $fields) {
            $this->getRedis()->hDel($key, ...$fields);

            return $this;
        });
    }

    // -------------------------------------------------------------------
    // Counter operations
    // -------------------------------------------------------------------

    /**
     * Atomically adds $by to a numeric field of the hash at $key.
     *
     * Dispatches on the type of $by:
     * - int   -> HINCRBY, returns int (the new field value after the add).
     * - float -> HINCRBYFLOAT, returns float.
     *
     * Auto-creates the hash and/or field, treating a missing field as 0.
     * Negative $by is accepted - it behaves like {@see decrementField()}.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key is invalid or
     *                                                   $field is empty
     * @throws CacheException                            on Redis transport/storage failures, including
     *                                                   when the existing field value isn't a valid number
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testIncrementFieldByInteger()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testIncrementFieldByFloat()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testIncrementFieldRejectsEmptyField()
     */
    public function incrementField(string $key, string $field, int|float $by = 1): int|float
    {
        $this->validateKey($key);
        $this->validateField($field);

        return $this->wrap(function () use ($key, $field, $by) {
            $redis = $this->getRedis();
            if (\is_int($by)) {
                return (int) $redis->hIncrBy($key, $field, $by);
            }

            return (float) $redis->hIncrByFloat($key, $field, $by);
        });
    }

    /**
     * Atomically subtracts $by from a numeric field of the hash at $key.
     *
     * Mirror of {@see incrementField()} - dispatches the same way on int vs
     * float, auto-creates at 0, accepts negative input (which then behaves
     * like an increment).
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $key is invalid or
     *                                                   $field is empty
     * @throws CacheException                            on Redis transport/storage failures, including
     *                                                   when the existing field value isn't a valid number
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDecrementFieldByInteger()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDecrementFieldByFloat()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDecrementFieldRejectsEmptyField()
     */
    public function decrementField(string $key, string $field, int|float $by = 1): int|float
    {
        $this->validateKey($key);
        $this->validateField($field);

        return $this->wrap(function () use ($key, $field, $by) {
            $redis = $this->getRedis();
            if (\is_int($by)) {
                return (int) $redis->hIncrBy($key, $field, -$by);
            }

            return (float) $redis->hIncrByFloat($key, $field, -$by);
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
     * @throws InvalidArgumentException which implements PSR-16's
     *                                  {@see \Psr\SimpleCache\InvalidArgumentException}
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetRejectsInvalidKey()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsInvalidKey()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testDeleteRejectsInvalidKey()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testValidateKeyRejectsBackslashCharacter()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testValidateKeyRejectsBraceCharacter()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testInvalidArgumentExceptionIsPsrCompliant()
     */
    private function validateKey(mixed $key): void
    {
        if (!\is_string($key) || '' === $key) {
            throw new InvalidArgumentException('Cache key must be a non-empty string.');
        }
        if (1 === \preg_match('#['.\preg_quote(self::RESERVED_KEY_CHARS, '#').']#', $key)) {
            throw new InvalidArgumentException(\sprintf('Cache key "%s" contains reserved characters (%s).', $key, self::RESERVED_KEY_CHARS));
        }
    }

    /**
     * Field names sit inside a Redis HASH, so they don't have to obey PSR-16
     * key rules (the `:` separator and friends are fine inside a hash). All we
     * insist on is non-empty.
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetFieldRejectsEmptyField()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetFieldRejectsEmptyField()
     */
    private function validateField(string $field): void
    {
        if ('' === $field) {
            throw new InvalidArgumentException('Field name must be a non-empty string.');
        }
    }

    /**
     * Enforces the {@see set()} value contract: non-empty flat array, every
     * element a scalar (string|int|float). The bool/null/nested-array/object
     * rejections are deliberate - this client trades type fidelity for raw
     * hash-field performance, and consumers needing those types should reach
     * for {@see RapidCacheClient}.
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsNonArrayValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsEmptyArrayValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsBooleanFieldValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsNullFieldValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsNestedArrayFieldValue()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetRejectsObjectFieldValue()
     */
    private function validateArrayValue(mixed $value): void
    {
        if (!\is_array($value)) {
            throw new InvalidArgumentException(\sprintf('Value must be a non-empty flat array, got %s.', get_debug_type($value)));
        }
        if ([] === $value) {
            throw new InvalidArgumentException('Value cannot be an empty array.');
        }
        foreach ($value as $element) {
            if (!\is_string($element) && !\is_int($element) && !\is_float($element)) {
                throw new InvalidArgumentException(\sprintf('Hash field values must be string|int|float, got %s.', get_debug_type($element)));
            }
        }
    }

    /**
     * Collapses PSR-16's `null|int|DateInterval` TTL form into a single
     * "seconds from now" integer (or null = no expiry).
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetWithDateIntervalTtl()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testSetWithZeroTtlDeletesEntry()
     */
    private function normalizeTtl(int|\DateInterval|null $ttl): ?int
    {
        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();

            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        return $ttl;
    }

    /**
     * Runs the given callable and translates phpredis storage errors into
     * Psr\SimpleCache\CacheException, as PSR-16 requires.
     *
     * On RedisException: resets the cached connection so the next call
     * reconnects, optionally retries exactly once when
     * {@see RedisConnectionConfig::$retryOnce} is true.
     *
     * @template T
     *
     * @param callable():T $op
     *
     * @return T
     *
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testWrapResetsConnectionOnRedisException()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testRetryOnceRecoversFromTransientError()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testRetryOnceDoesNotMaskPermanentError()
     * @see \IDCT\Tests\Cache\Unit\HashRapidCacheClientTest::testGetWrapsRedisExceptionAsCacheException()
     */
    private function wrap(callable $op): mixed
    {
        try {
            return $op();
        } catch (\RedisException $e) {
            $this->redis = null;
            if ($this->config->retryOnce && !$this->retrying) {
                $this->retrying = true;
                try {
                    return $op();
                } catch (\RedisException $retryError) {
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
     * {@see CacheException}, preserving the original as the chained cause.
     */
    private function toCacheException(\RedisException $e): CacheException
    {
        return new CacheException(
            \sprintf('Redis operation failed: %s', $e->getMessage()),
            0,
            $e,
        );
    }

    /**
     * Removes a key from every tag set it belongs to and deletes its
     * reverse-lookup set. Caller is responsible for wrapping in error
     * translation.
     */
    private function unindexKey(string $key): void
    {
        $redis = $this->getRedis();
        $reverseLookupKey = self::H_TAGS_SET_NAME_PREFIX.$key;
        $tags = $redis->sMembers($reverseLookupKey);

        if (!\is_array($tags) || [] === $tags) {
            $redis->del($reverseLookupKey);

            return;
        }

        $redis->multi(\Redis::PIPELINE);
        foreach ($tags as $tag) {
            $redis->sRem(self::H_TAG_KEY_SET_PREFIX.$tag, $key);
        }
        $redis->del($reverseLookupKey);
        $redis->exec();
    }
}
