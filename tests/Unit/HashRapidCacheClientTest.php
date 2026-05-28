<?php

declare(strict_types=1);

namespace IDCT\Tests\Cache\Unit;

use DateInterval;
use IDCT\Cache\Exception\InvalidArgumentException;
use IDCT\Cache\HashRapidCacheClient;
use IDCT\Cache\RedisConnectionConfig;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;
use Redis;

#[CoversClass(HashRapidCacheClient::class)]
class HashRapidCacheClientTest extends TestCase
{
    use PHPMock;

    private HashRapidCacheClient $cacheService;
    private \Redis&\PHPUnit\Framework\MockObject\MockObject $redisMock;

    protected function setUp(): void
    {
        $this->redisMock = $this->createMock(\Redis::class);
        $host = $_ENV['REDIS_HOST'] ?? 'localhost';
        $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);
        $this->cacheService = new HashRapidCacheClient($host, $port, 'test:');

        $reflection = new \ReflectionClass($this->cacheService);
        $redisProperty = $reflection->getProperty('redis');
        $redisProperty->setValue($this->cacheService, $this->redisMock);
    }

    /**
     * Implements PSR-16 CacheInterface but deliberately NOT CacheServiceInterface -
     * the queue / set / sorted-set / counter surface of the string client is out
     * of scope for the hash variant.
     */
    public function testImplementsPsrSimpleCache(): void
    {
        $this->assertInstanceOf(CacheInterface::class, $this->cacheService);
    }

    // -------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------

    /**
     * On a hit, HGETALL returns the stored hash as a flat associative array.
     */
    public function testGetReturnsHashAsArrayWhenExists(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hGetAll')
            ->with('test-key')
            ->willReturn(['name' => 'foo', 'age' => '42']);

        $this->assertSame(
            ['name' => 'foo', 'age' => '42'],
            $this->cacheService->get('test-key'),
        );
    }

    /**
     * Empty HGETALL = key absent. Returns null when no default given.
     */
    public function testGetReturnsDefaultWhenMissing(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hGetAll')->willReturn([]);

        $this->assertNull($this->cacheService->get('missing-key'));
    }

    /**
     * A caller-supplied default is returned on miss instead of null.
     */
    public function testGetReturnsCustomDefaultWhenMissing(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hGetAll')->willReturn([]);

        $this->assertSame('fallback', $this->cacheService->get('missing-key', 'fallback'));
    }

    /**
     * Reserved chars in the key must throw a PSR-16 InvalidArgumentException
     * before any Redis call.
     */
    public function testGetRejectsInvalidKey(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->get('invalid:key');
    }

    /**
     * A RedisException from HGETALL is wrapped as the package's CacheException
     * (which is PSR-16's Psr\SimpleCache\CacheException).
     */
    public function testGetWrapsRedisExceptionAsCacheException(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hGetAll')
            ->willThrowException(new \RedisException('connection lost'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $this->expectException(\Psr\SimpleCache\CacheException::class);
        $this->cacheService->get('test-key');
    }

    // -------------------------------------------------------------------
    // set()
    // -------------------------------------------------------------------

    /**
     * No TTL → pipelined DEL + HMSET, no PEXPIRE. The DEL ensures a stale hash
     * (or a same-name non-hash key) doesn't bleed extra fields into the new value.
     */
    public function testSetWithoutTtl(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('multi')
            ->with(\Redis::PIPELINE);
        $this->redisMock->expects($this->once())
            ->method('del')
            ->with('test-key');
        $this->redisMock->expects($this->once())
            ->method('hMSet')
            ->with('test-key', ['name' => 'foo', 'age' => 42]);
        $this->redisMock->expects($this->never())->method('pExpire');
        $this->redisMock->expects($this->once())
            ->method('exec')
            ->willReturn([1, true]);

        $this->assertTrue($this->cacheService->set('test-key', ['name' => 'foo', 'age' => 42]));
    }

    /**
     * Positive integer TTL adds a PEXPIRE call to the same pipeline, in
     * milliseconds. Pins the seconds → ms conversion.
     */
    public function testSetWithIntegerTtl(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hMSet')
            ->with('test-key', ['a' => 1]);
        $this->redisMock->expects($this->once())
            ->method('pExpire')
            ->with('test-key', 3_600_000);
        $this->redisMock->method('exec')->willReturn([1, true, true]);

        $this->assertTrue($this->cacheService->set('test-key', ['a' => 1], 3600));
    }

    /**
     * DateInterval is resolved against "now" - `PT1M` becomes 60s → 60000ms.
     */
    public function testSetWithDateIntervalTtl(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('pExpire')
            ->with('test-key', 60_000);
        $this->redisMock->method('exec')->willReturn([1, true, true]);

        $this->assertTrue(
            $this->cacheService->set('test-key', ['a' => 1], new \DateInterval('PT1M')),
        );
    }

    /**
     * TTL=0 short-circuits to unindex+del without ever calling hMSet/pExpire.
     * Returns true.
     */
    public function testSetWithZeroTtlDeletesEntry(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sMembers')
            ->with('H_TAGS:test-key')
            ->willReturn([]);
        $delMatcher = $this->exactly(2);
        $this->redisMock->expects($delMatcher)
            ->method('del')
            ->willReturnCallback(function (string $key) use ($delMatcher) {
                match ($delMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('H_TAGS:test-key', $key),
                    2 => $this->assertSame('test-key', $key),
                };

                return 1;
            });
        $this->redisMock->expects($this->never())->method('hMSet');
        $this->redisMock->expects($this->never())->method('pExpire');

        $this->assertTrue($this->cacheService->set('test-key', ['a' => 1], 0));
    }

    /**
     * Negative TTL takes the same delete path - pins the `<= 0` boundary.
     */
    public function testSetWithNegativeTtlDeletesEntry(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturn([]);
        $this->redisMock->expects($this->exactly(2))->method('del')->willReturn(1);
        $this->redisMock->expects($this->never())->method('hMSet');

        $this->assertTrue($this->cacheService->set('test-key', ['a' => 1], -5));
    }

    /**
     * Reserved chars in the key reject before any Redis call.
     */
    public function testSetRejectsInvalidKey(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->set('bad{key}', ['a' => 1]);
    }

    /**
     * Non-array $value is rejected (this client only stores flat arrays).
     */
    public function testSetRejectsNonArrayValue(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->set('test-key', 'just a string');
    }

    /**
     * Empty array $value is rejected - Redis auto-deletes empty hashes, and
     * "cache an empty array" is suspicious enough to surface rather than
     * silently turn into a delete.
     */
    public function testSetRejectsEmptyArrayValue(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->set('test-key', []);
    }

    /**
     * Booleans inside the array are rejected - Redis hash field values can't
     * losslessly round-trip a PHP bool.
     */
    public function testSetRejectsBooleanFieldValue(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->set('test-key', ['enabled' => true]);
    }

    /**
     * Null inside the array is rejected - same lossless-round-trip rationale
     * as booleans.
     */
    public function testSetRejectsNullFieldValue(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->set('test-key', ['note' => null]);
    }

    /**
     * Nested arrays as field values are rejected; the client only stores flat
     * arrays of scalars.
     */
    public function testSetRejectsNestedArrayFieldValue(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->set('test-key', ['data' => ['nested' => 'value']]);
    }

    /**
     * Objects are rejected - caller should use {@see \IDCT\Cache\RapidCacheClient}
     * for objects (igbinary handles them).
     */
    public function testSetRejectsObjectFieldValue(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->set('test-key', ['obj' => new \stdClass()]);
    }

    /**
     * exec() returning a non-array (transaction failure) yields a `false`
     * return - pins the `is_array($results)` half of the guard.
     */
    public function testSetReturnsFalseWhenExecReturnsNonArray(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('del')->willReturnSelf();
        $this->redisMock->method('hMSet')->willReturnSelf();
        $this->redisMock->method('exec')->willReturn(false);

        $this->assertFalse($this->cacheService->set('k', ['a' => 1]));
    }

    /**
     * Any individual command returning false inside the pipeline result
     * triggers a `false` return - pins the `in_array(false, ..., true)` half.
     */
    public function testSetReturnsFalseWhenAnyExecResultIsFalse(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('del')->willReturnSelf();
        $this->redisMock->method('hMSet')->willReturnSelf();
        $this->redisMock->method('pExpire')->willReturn(true);
        $this->redisMock->method('exec')->willReturn([1, false, true]);

        $this->assertFalse($this->cacheService->set('k', ['a' => 1], 60));
    }

    /**
     * RedisException from any pipeline command is wrapped as CacheException.
     */
    public function testSetWrapsRedisExceptionAsCacheException(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('multi')
            ->willThrowException(new \RedisException('write failure'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $this->cacheService->set('test-key', ['a' => 1]);
    }

    // -------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------

    /**
     * delete() removes the key AND cascades to every tag set it was in
     * (via unindexKey).
     */
    public function testDeleteRemovesTagAssociations(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sMembers')
            ->with('H_TAGS:test-key')
            ->willReturn(['t1', 't2']);
        $this->redisMock->expects($this->once())
            ->method('multi')
            ->with(\Redis::PIPELINE);
        $sRemMatcher = $this->exactly(2);
        $this->redisMock->expects($sRemMatcher)
            ->method('sRem')
            ->willReturnCallback(function (string $set, string $key) use ($sRemMatcher) {
                match ($sRemMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['H_TAG:t1', 'test-key'], [$set, $key]),
                    2 => $this->assertSame(['H_TAG:t2', 'test-key'], [$set, $key]),
                };

                return 1;
            });
        $delMatcher = $this->exactly(2);
        $this->redisMock->expects($delMatcher)
            ->method('del')
            ->willReturnCallback(function (string $key) use ($delMatcher) {
                match ($delMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('H_TAGS:test-key', $key),
                    2 => $this->assertSame('test-key', $key),
                };

                return 1;
            });
        $this->redisMock->expects($this->once())->method('exec');

        $this->assertTrue($this->cacheService->delete('test-key'));
    }

    /**
     * When the key wasn't tagged, unindexKey short-circuits: it deletes the
     * (empty) reverse-lookup set without entering the MULTI/EXEC pipeline.
     */
    public function testDeleteWithoutTagAssociations(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturn([]);
        $this->redisMock->expects($this->never())->method('multi');
        $this->redisMock->expects($this->never())->method('sRem');
        $this->redisMock->expects($this->exactly(2))->method('del')->willReturn(1);

        $this->assertTrue($this->cacheService->delete('test-key'));
    }

    public function testDeleteRejectsInvalidKey(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->delete('bad:key');
    }

    // -------------------------------------------------------------------
    // clear()
    // -------------------------------------------------------------------

    /**
     * Without a prefix, clear() blasts the entire database via FLUSHDB.
     */
    public function testClearWithoutPrefixUsesFlushDb(): void
    {
        $client = new HashRapidCacheClient('host', 6379, null);
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('redis')->setValue($client, $this->redisMock);

        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())->method('flushDb');
        $this->redisMock->expects($this->never())->method('scan');

        $this->assertTrue($client->clear());
    }

    /**
     * With a prefix, clear() temporarily disables OPT_PREFIX, sets SCAN_RETRY,
     * iterates SCAN+UNLINK on the raw `<prefix>*` pattern, then restores both
     * options in a `finally` block.
     */
    public function testClearWithPrefixScansAndUnlinks(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('getOption')->with(\Redis::OPT_SCAN)->willReturn(\Redis::SCAN_NORETRY);
        $optionMatcher = $this->exactly(4);
        $this->redisMock->expects($optionMatcher)
            ->method('setOption')
            ->willReturnCallback(function (int $option, mixed $value) use ($optionMatcher) {
                match ($optionMatcher->numberOfInvocations()) {
                    1 => $this->assertSame([\Redis::OPT_PREFIX, ''], [$option, $value]),
                    2 => $this->assertSame([\Redis::OPT_SCAN, \Redis::SCAN_RETRY], [$option, $value]),
                    3 => $this->assertSame([\Redis::OPT_PREFIX, 'test:'], [$option, $value]),
                    4 => $this->assertSame([\Redis::OPT_SCAN, \Redis::SCAN_NORETRY], [$option, $value]),
                };

                return true;
            });
        $this->redisMock->expects($this->once())
            ->method('scan')
            ->willReturnCallback(function (?int &$iterator, string $pattern, int $count) {
                $this->assertSame('test:*', $pattern);
                $this->assertSame(1000, $count);
                $iterator = 0;

                return ['test:key1', 'test:key2'];
            });
        $this->redisMock->expects($this->once())
            ->method('unlink')
            ->with(['test:key1', 'test:key2']);

        $this->assertTrue($this->cacheService->clear());
    }

    /**
     * SCAN's cursor protocol: keep calling until it returns 0. Multi-batch
     * iteration must walk all batches and dispatch UNLINK on each non-empty one.
     */
    public function testClearWithPrefixIteratesMultipleScanBatches(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('getOption')->willReturn(0);
        $this->redisMock->method('setOption')->willReturn(true);
        $scanMatcher = $this->exactly(2);
        $this->redisMock->expects($scanMatcher)
            ->method('scan')
            ->willReturnCallback(function (?int &$iterator) use ($scanMatcher) {
                if (1 === $scanMatcher->numberOfInvocations()) {
                    $iterator = 42;

                    return ['test:k1'];
                }
                $iterator = 0;

                return ['test:k2'];
            });
        $unlinkMatcher = $this->exactly(2);
        $this->redisMock->expects($unlinkMatcher)
            ->method('unlink')
            ->willReturnCallback(function (array $keys) use ($unlinkMatcher) {
                match ($unlinkMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['test:k1'], $keys),
                    2 => $this->assertSame(['test:k2'], $keys),
                };

                return count($keys);
            });

        $this->assertTrue($this->cacheService->clear());
    }

    /**
     * Empty scan batch (no keys this round, but iteration continues) skips
     * UNLINK to avoid an "UNLINK with no args" error.
     */
    public function testClearSkipsUnlinkOnEmptyBatch(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('getOption')->willReturn(0);
        $this->redisMock->method('setOption')->willReturn(true);
        $scanMatcher = $this->exactly(2);
        $this->redisMock->expects($scanMatcher)
            ->method('scan')
            ->willReturnCallback(function (?int &$iterator) use ($scanMatcher) {
                if (1 === $scanMatcher->numberOfInvocations()) {
                    $iterator = 7;

                    return [];
                }
                $iterator = 0;

                return ['test:k1'];
            });
        $this->redisMock->expects($this->once())->method('unlink')->with(['test:k1']);

        $this->assertTrue($this->cacheService->clear());
    }

    /**
     * If SCAN returns false (server error mid-iteration), the loop breaks
     * cleanly and the options are still restored by the finally block.
     */
    public function testClearBreaksOnScanFailure(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('getOption')->willReturn(0);
        $this->redisMock->method('setOption')->willReturn(true);
        $this->redisMock->expects($this->once())->method('scan')->willReturn(false);
        $this->redisMock->expects($this->never())->method('unlink');

        $this->assertTrue($this->cacheService->clear());
    }

    /**
     * Even if SCAN throws, the finally block restores OPT_PREFIX and OPT_SCAN
     * to their prior values - the client must not leave the connection in
     * "no prefix" mode.
     */
    public function testClearRestoresPrefixAndScanOptionsEvenWhenScanThrows(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('getOption')->willReturn(\Redis::SCAN_NORETRY);
        $setOptionCalls = [];
        $this->redisMock->method('setOption')->willReturnCallback(
            function (int $option, mixed $value) use (&$setOptionCalls): bool {
                $setOptionCalls[] = [$option, $value];

                return true;
            },
        );
        $this->redisMock->method('scan')->willThrowException(new \RedisException('boom'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        try {
            $this->cacheService->clear();
        } finally {
            $this->assertContains([\Redis::OPT_PREFIX, 'test:'], $setOptionCalls);
            $this->assertContains([\Redis::OPT_SCAN, \Redis::SCAN_NORETRY], $setOptionCalls);
        }
    }

    // -------------------------------------------------------------------
    // getMultiple()
    // -------------------------------------------------------------------

    /**
     * Pipelined HGETALL per key; the result associates each input key with
     * its hash (or $default on miss).
     */
    public function testGetMultiple(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('multi')
            ->with(\Redis::PIPELINE);
        $this->redisMock->expects($this->exactly(2))->method('hGetAll');
        $this->redisMock->expects($this->once())
            ->method('exec')
            ->willReturn([
                ['name' => 'foo'],
                [],
            ]);

        $this->assertSame(
            ['k1' => ['name' => 'foo'], 'k2' => null],
            (array) $this->cacheService->getMultiple(['k1', 'k2']),
        );
    }

    /**
     * pipelineBatchSize=2 over 3 keys → two pipelined batches.
     */
    public function testGetMultipleChunksByConfiguredBatchSize(): void
    {
        $config = new RedisConnectionConfig(host: 'h', pipelineBatchSize: 2);
        $client = new HashRapidCacheClient($config);
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('redis')->setValue($client, $this->redisMock);

        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->exactly(2))->method('multi');
        $execMatcher = $this->exactly(2);
        $this->redisMock->expects($execMatcher)
            ->method('exec')
            ->willReturnCallback(fn () => 1 === $execMatcher->numberOfInvocations()
                    ? [['name' => 'foo'], ['name' => 'bar']]
                    : [['name' => 'baz']]);

        $result = (array) $client->getMultiple(['k1', 'k2', 'k3']);
        $this->assertSame(
            ['k1' => ['name' => 'foo'], 'k2' => ['name' => 'bar'], 'k3' => ['name' => 'baz']],
            $result,
        );
    }

    /**
     * If exec() returns a non-array for a batch, every key in that chunk maps
     * to $default - pins the defensive fallback.
     */
    public function testGetMultipleFillsDefaultsWhenExecReturnsNonArray(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('hGetAll')->willReturnSelf();
        $this->redisMock->method('exec')->willReturn(false);

        $result = (array) $this->cacheService->getMultiple(['k1', 'k2'], 'd');
        $this->assertSame(['k1' => 'd', 'k2' => 'd'], $result);
    }

    /**
     * Empty input → no Redis call at all.
     */
    public function testGetMultipleWithEmptyKeysReturnsEmptyArrayWithoutRedisCall(): void
    {
        $this->redisMock->expects($this->never())->method('hGetAll');
        $this->redisMock->expects($this->never())->method('isConnected');

        $this->assertSame([], (array) $this->cacheService->getMultiple([]));
    }

    // -------------------------------------------------------------------
    // setMultiple()
    // -------------------------------------------------------------------

    /**
     * No TTL: one pipelined batch with DEL+HMSET per key, no PEXPIRE calls.
     */
    public function testSetMultipleWithoutTtl(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())->method('multi');
        $this->redisMock->expects($this->exactly(2))->method('del');
        $this->redisMock->expects($this->exactly(2))->method('hMSet');
        $this->redisMock->expects($this->never())->method('pExpire');
        $this->redisMock->method('exec')->willReturn([1, true, 1, true]);

        $this->assertTrue($this->cacheService->setMultiple([
            'k1' => ['a' => 1],
            'k2' => ['b' => 2],
        ]));
    }

    /**
     * With TTL: each key gets DEL+HMSET+PEXPIRE inside the pipeline.
     */
    public function testSetMultipleWithTtl(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())->method('multi');
        $this->redisMock->expects($this->once())->method('hMSet');
        $this->redisMock->expects($this->once())
            ->method('pExpire')
            ->with('k1', 60_000);
        $this->redisMock->method('exec')->willReturn([1, true, true]);

        $this->assertTrue($this->cacheService->setMultiple(['k1' => ['a' => 1]], 60));
    }

    /**
     * pipelineBatchSize=2 over 3 entries → two pipelined batches.
     */
    public function testSetMultipleChunksByConfiguredBatchSize(): void
    {
        $config = new RedisConnectionConfig(host: 'h', pipelineBatchSize: 2);
        $client = new HashRapidCacheClient($config);
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('redis')->setValue($client, $this->redisMock);

        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->exactly(2))->method('multi');
        $this->redisMock->method('exec')->willReturn([1, true, 1, true]);

        $this->assertTrue($client->setMultiple([
            'k1' => ['a' => 1],
            'k2' => ['b' => 2],
            'k3' => ['c' => 3],
        ]));
    }

    /**
     * TTL=0 → unindex+chunked DEL only, NO HMSET / PEXPIRE / MULTI.
     */
    public function testSetMultipleWithZeroTtlDeletesKeysAndSkipsWrites(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturn([]);
        $delCalls = [];
        $this->redisMock->method('del')->willReturnCallback(
            function (string|array $arg) use (&$delCalls): int {
                $delCalls[] = $arg;

                return 1;
            },
        );
        $this->redisMock->expects($this->never())->method('hMSet');
        $this->redisMock->expects($this->never())->method('pExpire');
        $this->redisMock->expects($this->never())->method('multi');

        $this->assertTrue($this->cacheService->setMultiple([
            'k1' => ['a' => 1],
            'k2' => ['b' => 2],
        ], 0));

        $this->assertContains(['k1', 'k2'], $delCalls);
    }

    /**
     * Negative TTL takes the same delete path as TTL=0 - pins `<= 0`.
     */
    public function testSetMultipleWithNegativeTtlDeletesKeys(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturn([]);
        $this->redisMock->method('del')->willReturn(1);
        $this->redisMock->expects($this->never())->method('hMSet');

        $this->assertTrue($this->cacheService->setMultiple(['k1' => ['a' => 1]], -5));
    }

    /**
     * Empty values map short-circuits to true with zero Redis calls.
     */
    public function testSetMultipleWithEmptyValuesReturnsTrueWithoutRedisCall(): void
    {
        $this->redisMock->expects($this->never())->method('hMSet');
        $this->redisMock->expects($this->never())->method('isConnected');

        $this->assertTrue($this->cacheService->setMultiple([]));
    }

    /**
     * exec() returning a non-array yields false.
     */
    public function testSetMultipleReturnsFalseWhenExecReturnsNonArray(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('del')->willReturnSelf();
        $this->redisMock->method('hMSet')->willReturnSelf();
        $this->redisMock->method('exec')->willReturn(false);

        $this->assertFalse($this->cacheService->setMultiple(['k1' => ['a' => 1]]));
    }

    /**
     * Any individual command returning false → false.
     */
    public function testSetMultipleReturnsFalseWhenAnyExecResultIsFalse(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('del')->willReturnSelf();
        $this->redisMock->method('hMSet')->willReturnSelf();
        $this->redisMock->method('exec')->willReturn([1, false]);

        $this->assertFalse($this->cacheService->setMultiple(['k1' => ['a' => 1]]));
    }

    /**
     * Validation runs over every value before the pipeline opens.
     */
    public function testSetMultipleRejectsInvalidValue(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->setMultiple([
            'k1' => ['a' => 1],
            'k2' => 'not-an-array',
        ]);
    }

    /**
     * Integer keys in the iterable get coerced to strings before validation,
     * matching PSR-16's lenient key-type handling.
     */
    public function testSetMultipleNormalizesIntegerKeysToStrings(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->expects($this->once())
            ->method('hMSet')
            ->with('42', ['a' => 1]);
        $this->redisMock->method('exec')->willReturn([1, true]);

        $this->assertTrue($this->cacheService->setMultiple([42 => ['a' => 1]]));
    }

    // -------------------------------------------------------------------
    // deleteMultiple()
    // -------------------------------------------------------------------

    public function testDeleteMultiple(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturn([]);
        $delCalls = [];
        $this->redisMock->method('del')->willReturnCallback(
            function (string|array $arg) use (&$delCalls): int {
                $delCalls[] = $arg;

                return 1;
            },
        );

        $this->assertTrue($this->cacheService->deleteMultiple(['k1', 'k2']));
        $this->assertContains(['k1', 'k2'], $delCalls);
    }

    public function testDeleteMultipleChunksByConfiguredBatchSize(): void
    {
        $config = new RedisConnectionConfig(host: 'h', pipelineBatchSize: 2);
        $client = new HashRapidCacheClient($config);
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('redis')->setValue($client, $this->redisMock);

        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturn([]);
        $bulkDelCalls = [];
        $this->redisMock->method('del')->willReturnCallback(
            function (string|array $arg) use (&$bulkDelCalls): int {
                if (is_array($arg)) {
                    $bulkDelCalls[] = $arg;
                }

                return 1;
            },
        );

        $this->assertTrue($client->deleteMultiple(['k1', 'k2', 'k3']));
        $this->assertSame([['k1', 'k2'], ['k3']], $bulkDelCalls);
    }

    public function testDeleteMultipleWithEmptyKeysReturnsTrueWithoutRedisCall(): void
    {
        $this->redisMock->expects($this->never())->method('del');
        $this->redisMock->expects($this->never())->method('isConnected');

        $this->assertTrue($this->cacheService->deleteMultiple([]));
    }

    // -------------------------------------------------------------------
    // has()
    // -------------------------------------------------------------------

    public function testHas(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('exists')
            ->with('test-key')
            ->willReturn(1);

        $this->assertTrue($this->cacheService->has('test-key'));
    }

    public function testHasReturnsFalseWhenMissing(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('exists')->willReturn(0);

        $this->assertFalse($this->cacheService->has('missing'));
    }

    // -------------------------------------------------------------------
    // setTagged()
    // -------------------------------------------------------------------

    /**
     * setTagged: single MULTI pipeline holds DEL + HMSET + both sAdds.
     */
    public function testSetTagged(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('multi')
            ->with(\Redis::PIPELINE);
        $this->redisMock->expects($this->once())->method('del')->with('test-key');
        $this->redisMock->expects($this->once())
            ->method('hMSet')
            ->with('test-key', ['a' => 1]);
        $sAddMatcher = $this->exactly(2);
        $this->redisMock->expects($sAddMatcher)
            ->method('sAdd')
            ->willReturnCallback(function (string $set, string $value) use ($sAddMatcher) {
                match ($sAddMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['H_TAG:test-tag', 'test-key'], [$set, $value]),
                    2 => $this->assertSame(['H_TAGS:test-key', 'test-tag'], [$set, $value]),
                };

                return 1;
            });
        $this->redisMock->expects($this->never())->method('pExpire');
        $this->redisMock->method('exec')->willReturn([1, true, 1, 1]);

        $this->assertTrue($this->cacheService->setTagged('test-key', ['a' => 1], 'test-tag'));
    }

    /**
     * setTagged with TTL adds PEXPIRE between HMSET and the sAdds.
     */
    public function testSetTaggedWithTtl(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('del')->willReturn(0);
        $this->redisMock->method('hMSet')->willReturnSelf();
        $this->redisMock->expects($this->once())
            ->method('pExpire')
            ->with('k', 5000);
        $this->redisMock->method('sAdd')->willReturn(1);
        $this->redisMock->method('exec')->willReturn([0, true, true, 1, 1]);

        $this->assertTrue($this->cacheService->setTagged('k', ['a' => 1], 't', 5));
    }

    /**
     * TTL=0 short-circuits to set() (delete path) - no sAdd ever fires.
     */
    public function testSetTaggedWithZeroTtlShortCircuitsToDelete(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturn([]);
        $this->redisMock->expects($this->exactly(2))->method('del')->willReturn(1);
        $this->redisMock->expects($this->never())->method('sAdd');
        $this->redisMock->expects($this->never())->method('hMSet');

        $this->assertTrue($this->cacheService->setTagged('k', ['a' => 1], 't', 0));
    }

    /**
     * Negative TTL also short-circuits to delete - pins `<= 0` boundary.
     */
    public function testSetTaggedWithNegativeTtlShortCircuitsToDelete(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturn([]);
        $this->redisMock->expects($this->exactly(2))->method('del')->willReturn(1);
        $this->redisMock->expects($this->never())->method('sAdd');

        $this->assertTrue($this->cacheService->setTagged('k', ['a' => 1], 't', -10));
    }

    /**
     * exec() returning a non-array → false.
     */
    public function testSetTaggedReturnsFalseWhenExecReturnsNonArray(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('del')->willReturnSelf();
        $this->redisMock->method('hMSet')->willReturnSelf();
        $this->redisMock->method('sAdd')->willReturnSelf();
        $this->redisMock->method('exec')->willReturn(false);

        $this->assertFalse($this->cacheService->setTagged('k', ['a' => 1], 't'));
    }

    /**
     * Any false in the exec result → false (the whole pipeline gates the
     * return, not just the HMSET slot).
     */
    public function testSetTaggedReturnsFalseWhenAnyExecResultIsFalse(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('del')->willReturnSelf();
        $this->redisMock->method('hMSet')->willReturnSelf();
        $this->redisMock->method('sAdd')->willReturnSelf();
        $this->redisMock->method('exec')->willReturn([0, false, 1, 1]);

        $this->assertFalse($this->cacheService->setTagged('k', ['a' => 1], 't'));
    }

    // -------------------------------------------------------------------
    // getTagged()
    // -------------------------------------------------------------------

    /**
     * Single SSCAN page returns members; each gets a pipelined HGETALL, and
     * the generator yields `key => hash` in input order.
     */
    public function testGetTaggedYieldsHashesFromSingleScanPage(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sScan')
            ->willReturnCallback(function (string $key, ?int &$iterator) {
                $this->assertSame('H_TAG:t', $key);
                $iterator = 0;

                return ['k1', 'k2'];
            });
        $this->redisMock->expects($this->once())->method('multi');
        $this->redisMock->expects($this->exactly(2))->method('hGetAll');
        $this->redisMock->method('exec')->willReturn([
            ['name' => 'foo'],
            ['name' => 'bar'],
        ]);

        $result = iterator_to_array($this->cacheService->getTagged('t'));
        $this->assertSame(
            ['k1' => ['name' => 'foo'], 'k2' => ['name' => 'bar']],
            $result,
        );
    }

    /**
     * SSCAN's cursor protocol: when the first page returns a non-zero cursor,
     * the generator must call SSCAN again until 0.
     */
    public function testGetTaggedIteratesMultipleScanPages(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $scanMatcher = $this->exactly(2);
        $this->redisMock->expects($scanMatcher)
            ->method('sScan')
            ->willReturnCallback(function (string $key, ?int &$iterator) use ($scanMatcher) {
                if (1 === $scanMatcher->numberOfInvocations()) {
                    $iterator = 11;

                    return ['k1'];
                }
                $iterator = 0;

                return ['k2'];
            });
        $execMatcher = $this->exactly(2);
        $this->redisMock->expects($execMatcher)
            ->method('exec')
            ->willReturnCallback(fn () => 1 === $execMatcher->numberOfInvocations()
                    ? [['name' => 'foo']]
                    : [['name' => 'bar']]);

        $result = iterator_to_array($this->cacheService->getTagged('t'));
        $this->assertSame(
            ['k1' => ['name' => 'foo'], 'k2' => ['name' => 'bar']],
            $result,
        );
    }

    /**
     * Members whose underlying hash has expired (empty HGETALL) get collected
     * and pruned from both indexes in a finally-block MULTI/EXEC.
     */
    public function testGetTaggedPrunesExpiredMembers(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sScan')->willReturnCallback(
            function (string $key, ?int &$iterator) {
                $iterator = 0;

                return ['live-key', 'expired-key'];
            },
        );
        $execMatcher = $this->exactly(2);
        $this->redisMock->expects($execMatcher)
            ->method('exec')
            ->willReturnCallback(fn () => 1 === $execMatcher->numberOfInvocations()
                    ? [['name' => 'foo'], []]
                    : [1, 1]);
        $sRemMatcher = $this->exactly(2);
        $this->redisMock->expects($sRemMatcher)
            ->method('sRem')
            ->willReturnCallback(function (string $set, string $member) use ($sRemMatcher) {
                match ($sRemMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['H_TAG:t', 'expired-key'], [$set, $member]),
                    2 => $this->assertSame(['H_TAGS:expired-key', 't'], [$set, $member]),
                };

                return 1;
            });

        $result = iterator_to_array($this->cacheService->getTagged('t'));
        $this->assertSame(['live-key' => ['name' => 'foo']], $result);
    }

    /**
     * Empty tag → the SSCAN loop yields zero pages, no HGETALL, no cleanup.
     */
    public function testGetTaggedWithNoMembers(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sScan')->willReturnCallback(
            function (string $key, ?int &$iterator): array {
                $iterator = 0;

                return [];
            },
        );
        $this->redisMock->expects($this->never())->method('multi');
        $this->redisMock->expects($this->never())->method('hGetAll');

        $this->assertSame([], iterator_to_array($this->cacheService->getTagged('t')));
    }

    /**
     * A pipeline EXEC returning non-array for the HGETALL batch is treated as
     * "nothing to yield" and continues to the next SSCAN page (or terminates).
     */
    public function testGetTaggedSkipsBatchesWhereExecReturnsNonArray(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sScan')->willReturnCallback(
            function (string $key, ?int &$iterator): array {
                $iterator = 0;

                return ['k1', 'k2'];
            },
        );
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('hGetAll')->willReturnSelf();
        $this->redisMock->method('exec')->willReturn(false);

        $this->assertSame([], iterator_to_array($this->cacheService->getTagged('t')));
    }

    /**
     * Cleanup's MULTI/EXEC swallowing its own RedisException - the finally
     * block must not shadow the primary read-phase exception or fail a happy
     * iteration. Pins the `catch (RedisException)` inside the finally.
     */
    public function testGetTaggedSwallowsCleanupRedisException(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sScan')->willReturnCallback(
            function (string $key, ?int &$iterator): array {
                $iterator = 0;

                return ['expired-key'];
            },
        );
        $execMatcher = $this->exactly(2);
        $this->redisMock->expects($execMatcher)
            ->method('exec')
            ->willReturnCallback(function () use ($execMatcher) {
                if (1 === $execMatcher->numberOfInvocations()) {
                    return [[]];
                }
                throw new \RedisException('cleanup blew up');
            });

        // No exception propagates - swallowed by the inner try/catch.
        $this->assertSame([], iterator_to_array($this->cacheService->getTagged('t')));
    }

    /**
     * A RedisException in the read phase is wrapped as CacheException once
     * the consumer iterates the generator.
     */
    public function testGetTaggedWrapsRedisExceptionFromGenerator(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sScan')
            ->willThrowException(new \RedisException('boom'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        iterator_to_array($this->cacheService->getTagged('t'));
    }

    // -------------------------------------------------------------------
    // tag() / untag()
    // -------------------------------------------------------------------

    public function testTagExistingKey(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('exists')
            ->with('k')
            ->willReturn(1);
        $this->redisMock->expects($this->once())
            ->method('multi')
            ->with(\Redis::PIPELINE);
        $sAddMatcher = $this->exactly(2);
        $this->redisMock->expects($sAddMatcher)
            ->method('sAdd')
            ->willReturnCallback(function (string $set, string $value) use ($sAddMatcher) {
                match ($sAddMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['H_TAG:t', 'k'], [$set, $value]),
                    2 => $this->assertSame(['H_TAGS:k', 't'], [$set, $value]),
                };

                return 1;
            });
        $this->redisMock->expects($this->once())->method('exec');

        $this->assertSame($this->cacheService, $this->cacheService->tag('k', 't'));
    }

    /**
     * Tagging a missing key throws (would create a ghost tag membership).
     */
    public function testTagNonExistingKey(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('exists')->willReturn(0);

        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->tag('k', 't');
    }

    public function testTagRejectsInvalidTagName(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->tag('k', 'bad:tag');
    }

    /**
     * untag is idempotent and just emits a pair of SREM calls - no MULTI/EXEC.
     */
    public function testUntag(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $sRemMatcher = $this->exactly(2);
        $this->redisMock->expects($sRemMatcher)
            ->method('sRem')
            ->willReturnCallback(function (string $set, string $value) use ($sRemMatcher) {
                match ($sRemMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['H_TAG:t', 'k'], [$set, $value]),
                    2 => $this->assertSame(['H_TAGS:k', 't'], [$set, $value]),
                };

                return 1;
            });

        $this->assertSame($this->cacheService, $this->cacheService->untag('k', 't'));
    }

    // -------------------------------------------------------------------
    // clearByTag()
    // -------------------------------------------------------------------

    /**
     * Two-phase cascade: phase 1 reads reverse lookups, phase 2 emits the
     * cross-tag SREMs + per-member TAGS-set DELs + bulk member DEL + tag-set DEL.
     */
    public function testClearByTag(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')
            ->willReturnCallback(fn (string $k) => 'H_TAG:t' === $k ? ['k1'] : []);
        $this->redisMock->expects($this->exactly(2))
            ->method('multi')
            ->with(\Redis::PIPELINE);
        $this->redisMock->method('exec')->willReturnOnConsecutiveCalls([[]], [1]);

        $this->assertTrue($this->cacheService->clearByTag('t'));
    }

    /**
     * A member tagged with both `t` and other tags must be sRem'd from each
     * other tag's set during phase 2.
     */
    public function testClearByTagRemovesKeysFromOtherTagsToo(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturnCallback(
            fn (string $k) => 'H_TAG:t' === $k ? ['k1'] : [],
        );
        $this->redisMock->method('multi')->willReturnSelf();
        $execMatcher = $this->exactly(2);
        $this->redisMock->expects($execMatcher)
            ->method('exec')
            ->willReturnCallback(fn () => 1 === $execMatcher->numberOfInvocations()
                    ? [['t', 'other']]
                    : [1]);
        $sRemMatcher = $this->exactly(1);
        $this->redisMock->expects($sRemMatcher)
            ->method('sRem')
            ->willReturnCallback(function (string $set, string $value) {
                $this->assertSame(['H_TAG:other', 'k1'], [$set, $value]);

                return 1;
            });

        $this->assertTrue($this->cacheService->clearByTag('t'));
    }

    /**
     * Unknown / empty tag → del the tag set and return true. No phase 2.
     */
    public function testClearByTagEmptyTagSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturn([]);
        $this->redisMock->expects($this->once())->method('del')->with('H_TAG:t');
        $this->redisMock->expects($this->never())->method('multi');

        $this->assertTrue($this->cacheService->clearByTag('t'));
    }

    /**
     * Phase-1 EXEC returning non-array → reverseLookups padded with nulls so
     * phase-2 indexing stays aligned. A null reverse lookup means "no known
     * other tags", so no cross-tag sRem is emitted.
     */
    public function testClearByTagPadsReverseLookupsWhenPhaseOneExecReturnsNonArray(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturnCallback(
            fn (string $k) => 'H_TAG:t' === $k ? ['k1'] : [],
        );
        $this->redisMock->expects($this->exactly(2))->method('multi');
        $this->redisMock->expects($this->exactly(2))
            ->method('exec')
            ->willReturnOnConsecutiveCalls(false, [1]);
        $this->redisMock->expects($this->never())->method('sRem');

        $delMatcher = $this->exactly(3);
        $this->redisMock->expects($delMatcher)
            ->method('del')
            ->willReturnCallback(function (string|array $arg) use ($delMatcher) {
                match ($delMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('H_TAGS:k1', $arg),
                    2 => $this->assertSame(['k1'], $arg),
                    3 => $this->assertSame('H_TAG:t', $arg),
                };

                return 1;
            });

        $this->assertTrue($this->cacheService->clearByTag('t'));
    }

    // -------------------------------------------------------------------
    // getTagCardinality()
    // -------------------------------------------------------------------

    public function testGetTagCardinality(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sCard')
            ->with('H_TAG:t')
            ->willReturn(7);

        $this->assertSame(7, $this->cacheService->getTagCardinality('t'));
    }

    /**
     * SCARD on a missing set returns false (phpredis); the (int) cast
     * normalises to 0.
     */
    public function testGetTagCardinalityCoercesFalseToZero(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sCard')->willReturn(false);

        $this->assertSame(0, $this->cacheService->getTagCardinality('missing'));
    }

    // -------------------------------------------------------------------
    // Single-field operations
    // -------------------------------------------------------------------

    public function testGetField(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hGet')
            ->with('k', 'f')
            ->willReturn('v');

        $this->assertSame('v', $this->cacheService->getField('k', 'f'));
    }

    public function testGetFieldReturnsDefaultWhenMissing(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hGet')->willReturn(false);

        $this->assertSame('fallback', $this->cacheService->getField('k', 'f', 'fallback'));
    }

    public function testGetFieldRejectsEmptyField(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->getField('k', '');
    }

    public function testSetField(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hSet')
            ->with('k', 'f', 'v')
            ->willReturn(1);

        $this->assertSame($this->cacheService, $this->cacheService->setField('k', 'f', 'v'));
    }

    /**
     * setField on a non-existent key still issues HSET - phpredis creates the
     * hash. Documented footgun: it has no TTL.
     */
    public function testSetFieldCreatesKeyWhenMissing(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->never())->method('exists');
        $this->redisMock->expects($this->once())->method('hSet')->willReturn(1);

        $this->cacheService->setField('new-key', 'f', 'v');
    }

    public function testSetFieldRejectsEmptyField(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->setField('k', '', 'v');
    }

    public function testDeleteField(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hDel')
            ->with('k', 'f')
            ->willReturn(1);

        $this->assertSame($this->cacheService, $this->cacheService->deleteField('k', 'f'));
    }

    public function testDeleteFieldRejectsEmptyField(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->deleteField('k', '');
    }

    public function testHasField(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hExists')
            ->with('k', 'f')
            ->willReturn(true);

        $this->assertTrue($this->cacheService->hasField('k', 'f'));
    }

    public function testHasFieldReturnsFalseWhenMissing(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hExists')->willReturn(false);

        $this->assertFalse($this->cacheService->hasField('k', 'f'));
    }

    public function testHasFieldRejectsEmptyField(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->hasField('k', '');
    }

    // -------------------------------------------------------------------
    // Multi-field operations
    // -------------------------------------------------------------------

    public function testGetFields(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hMGet')
            ->with('k', ['a', 'b'])
            ->willReturn(['a' => '1', 'b' => '2']);

        $this->assertSame(
            ['a' => '1', 'b' => '2'],
            $this->cacheService->getFields('k', ['a', 'b']),
        );
    }

    /**
     * HMGET returns false-valued slots for missing fields; getFields
     * substitutes the caller's default.
     */
    public function testGetFieldsFillsDefaultsForMissingFields(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hMGet')->willReturn(['a' => '1', 'b' => false]);

        $this->assertSame(
            ['a' => '1', 'b' => 'X'],
            $this->cacheService->getFields('k', ['a', 'b'], 'X'),
        );
    }

    /**
     * HMGET defensively returning a non-array → every field maps to $default.
     */
    public function testGetFieldsFillsDefaultsWhenHMGetReturnsNonArray(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hMGet')->willReturn(false);

        $this->assertSame(
            ['a' => 'd', 'b' => 'd'],
            $this->cacheService->getFields('k', ['a', 'b'], 'd'),
        );
    }

    public function testGetFieldsWithEmptyFieldsReturnsEmptyArrayWithoutRedisCall(): void
    {
        $this->redisMock->expects($this->never())->method('hMGet');
        $this->redisMock->expects($this->never())->method('isConnected');

        $this->assertSame([], $this->cacheService->getFields('k', []));
    }

    public function testGetFieldsRejectsInvalidField(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->getFields('k', ['a', '']);
    }

    public function testSetFields(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hMSet')
            ->with('k', ['a' => 1, 'b' => 'two']);

        $this->assertSame(
            $this->cacheService,
            $this->cacheService->setFields('k', ['a' => 1, 'b' => 'two']),
        );
    }

    /**
     * setFields uses HMSET, which merges into any existing hash (does NOT
     * issue a DEL first - that's set()'s job).
     */
    public function testSetFieldsMergesWithExistingHash(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->never())->method('del');
        $this->redisMock->expects($this->once())->method('hMSet');

        $this->cacheService->setFields('k', ['c' => 3]);
    }

    public function testSetFieldsWithEmptyArrayIsNoOp(): void
    {
        $this->redisMock->expects($this->never())->method('hMSet');
        $this->redisMock->expects($this->never())->method('isConnected');

        $this->assertSame($this->cacheService, $this->cacheService->setFields('k', []));
    }

    public function testSetFieldsRejectsBooleanValue(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->setFields('k', ['f' => true]);
    }

    public function testSetFieldsRejectsNullValue(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->setFields('k', ['f' => null]);
    }

    public function testSetFieldsRejectsNestedArrayValue(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->setFields('k', ['f' => ['nested']]);
    }

    public function testDeleteFields(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hDel')
            ->with('k', 'a', 'b')
            ->willReturn(2);

        $this->assertSame(
            $this->cacheService,
            $this->cacheService->deleteFields('k', ['a', 'b']),
        );
    }

    public function testDeleteFieldsWithEmptyArrayIsNoOp(): void
    {
        $this->redisMock->expects($this->never())->method('hDel');
        $this->redisMock->expects($this->never())->method('isConnected');

        $this->assertSame($this->cacheService, $this->cacheService->deleteFields('k', []));
    }

    public function testDeleteFieldsRejectsInvalidField(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->deleteFields('k', ['']);
    }

    // -------------------------------------------------------------------
    // Counter operations
    // -------------------------------------------------------------------

    public function testIncrementFieldByInteger(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hIncrBy')
            ->with('k', 'counter', 3)
            ->willReturn(10);

        $this->assertSame(10, $this->cacheService->incrementField('k', 'counter', 3));
    }

    public function testIncrementFieldByFloat(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hIncrByFloat')
            ->with('k', 'counter', 1.5)
            ->willReturn(2.5);

        $this->assertSame(2.5, $this->cacheService->incrementField('k', 'counter', 1.5));
    }

    public function testIncrementFieldRejectsEmptyField(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->incrementField('k', '');
    }

    public function testDecrementFieldByInteger(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hIncrBy')
            ->with('k', 'counter', -3)
            ->willReturn(7);

        $this->assertSame(7, $this->cacheService->decrementField('k', 'counter', 3));
    }

    public function testDecrementFieldByFloat(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hIncrByFloat')
            ->with('k', 'counter', -0.5)
            ->willReturn(1.5);

        $this->assertSame(1.5, $this->cacheService->decrementField('k', 'counter', 0.5));
    }

    public function testDecrementFieldRejectsEmptyField(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->decrementField('k', '');
    }

    /**
     * Default $by=1 - pins the literal `1` against IncrementInteger/DecrementInteger
     * mutations that would shift the default to 0 or 2 and silently break callers
     * that rely on the bare {@see incrementField()}.
     */
    public function testIncrementFieldDefaultsToOne(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hIncrBy')
            ->with('k', 'counter', 1)
            ->willReturn(1);

        $this->assertSame(1, $this->cacheService->incrementField('k', 'counter'));
    }

    /**
     * Mirror for {@see decrementField()}: default $by=1 must call HINCRBY with
     * -1, not 0 or -2.
     */
    public function testDecrementFieldDefaultsToOne(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('hIncrBy')
            ->with('k', 'counter', -1)
            ->willReturn(99);

        $this->assertSame(99, $this->cacheService->decrementField('k', 'counter'));
    }

    // -------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------

    public function testValidateKeyRejectsBackslashCharacter(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->get('a\\b');
    }

    public function testValidateKeyRejectsBraceCharacter(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->get('a{b}');
    }

    public function testValidateKeyRejectsParenCharacter(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->get('a(b)');
    }

    /**
     * The exception thrown for invalid keys must satisfy BOTH the SPL base
     * and PSR-16's marker interface - so consumers can catch either.
     */
    public function testInvalidArgumentExceptionIsPsrCompliant(): void
    {
        try {
            $this->cacheService->get('');
            $this->fail('Expected exception');
        } catch (InvalidArgumentException $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertInstanceOf(PsrInvalidArgumentException::class, $e);
        }
    }

    // -------------------------------------------------------------------
    // Hardening: invalid-key rejection on every public method.
    // -------------------------------------------------------------------

    /**
     * @return iterable<string, array{callable(HashRapidCacheClient): mixed}>
     */
    public static function invalidKeyOperationProvider(): iterable
    {
        $badKey = 'bad:key';

        return [
            'has' => [fn (HashRapidCacheClient $c) => $c->has($badKey)],
            'getMultiple invalid key' => [fn (HashRapidCacheClient $c) => iterator_to_array($c->getMultiple([$badKey]))],
            'setMultiple invalid key' => [fn (HashRapidCacheClient $c) => $c->setMultiple([$badKey => ['a' => 1]])],
            'deleteMultiple invalid key' => [fn (HashRapidCacheClient $c) => $c->deleteMultiple([$badKey])],
            'setTagged invalid key' => [fn (HashRapidCacheClient $c) => $c->setTagged($badKey, ['a' => 1], 'goodtag')],
            'setTagged invalid tag' => [fn (HashRapidCacheClient $c) => $c->setTagged('goodkey', ['a' => 1], $badKey)],
            'getTagged invalid tag' => [fn (HashRapidCacheClient $c) => iterator_to_array($c->getTagged($badKey))],
            'tag invalid key' => [fn (HashRapidCacheClient $c) => $c->tag($badKey, 'goodtag')],
            'tag invalid tag' => [fn (HashRapidCacheClient $c) => $c->tag('goodkey', $badKey)],
            'untag invalid key' => [fn (HashRapidCacheClient $c) => $c->untag($badKey, 'goodtag')],
            'untag invalid tag' => [fn (HashRapidCacheClient $c) => $c->untag('goodkey', $badKey)],
            'clearByTag invalid tag' => [fn (HashRapidCacheClient $c) => $c->clearByTag($badKey)],
            'getTagCardinality invalid tag' => [fn (HashRapidCacheClient $c) => $c->getTagCardinality($badKey)],
            'getField invalid key' => [fn (HashRapidCacheClient $c) => $c->getField($badKey, 'f')],
            'setField invalid key' => [fn (HashRapidCacheClient $c) => $c->setField($badKey, 'f', 'v')],
            'deleteField invalid key' => [fn (HashRapidCacheClient $c) => $c->deleteField($badKey, 'f')],
            'hasField invalid key' => [fn (HashRapidCacheClient $c) => $c->hasField($badKey, 'f')],
            'getFields invalid key' => [fn (HashRapidCacheClient $c) => $c->getFields($badKey, ['f'])],
            'setFields invalid key' => [fn (HashRapidCacheClient $c) => $c->setFields($badKey, ['f' => 'v'])],
            'deleteFields invalid key' => [fn (HashRapidCacheClient $c) => $c->deleteFields($badKey, ['f'])],
            'incrementField invalid key' => [fn (HashRapidCacheClient $c) => $c->incrementField($badKey, 'f')],
            'decrementField invalid key' => [fn (HashRapidCacheClient $c) => $c->decrementField($badKey, 'f')],
        ];
    }

    /**
     * @param callable(HashRapidCacheClient): mixed $op
     */
    #[DataProvider('invalidKeyOperationProvider')]
    public function testRejectsInvalidKeyAcrossEveryApi(callable $op): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $op($this->cacheService);
    }

    // -------------------------------------------------------------------
    // Reconnect / connection lifecycle.
    // -------------------------------------------------------------------

    public function testReconnectAppliesAllConfigFields(): void
    {
        $redis = $this->createMock(\Redis::class);
        $config = new RedisConnectionConfig(
            host: 'redis-host',
            port: 6380,
            prefix: 'app:',
            password: 'secret',
            database: 3,
            connectTimeout: 2.5,
            readTimeout: 1.5,
            persistent: true,
            persistentId: 'pool1',
        );

        $redis->expects($this->once())
            ->method('pconnect')
            ->with('redis-host', 6380, 2.5, 'pool1');
        $redis->expects($this->once())->method('auth')->with('secret');
        $redis->expects($this->once())->method('select')->with(3);

        $optionCalls = [];
        $redis->method('setOption')->willReturnCallback(
            function (int $option, mixed $value) use (&$optionCalls): bool {
                $optionCalls[] = [$option, $value];

                return true;
            },
        );

        $this->forceReconnect($config, $redis);

        $this->assertContains([\Redis::OPT_READ_TIMEOUT, '1.5'], $optionCalls);
        $this->assertContains([\Redis::OPT_PREFIX, 'app:'], $optionCalls);
    }

    public function testReconnectWithoutPasswordSkipsAuth(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->never())->method('auth');

        $this->forceReconnect(new RedisConnectionConfig(host: 'h', password: null), $redis);
    }

    public function testReconnectWithEmptyPasswordSkipsAuth(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->never())->method('auth');

        $this->forceReconnect(new RedisConnectionConfig(host: 'h', password: ''), $redis);
    }

    public function testReconnectWithDatabaseZeroSkipsSelect(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->never())->method('select');

        $this->forceReconnect(new RedisConnectionConfig(host: 'h', database: 0), $redis);
    }

    public function testReconnectWithoutPrefixSkipsPrefixOption(): void
    {
        $redis = $this->createMock(\Redis::class);
        $optionCalls = [];
        $redis->method('setOption')->willReturnCallback(
            function (int $option) use (&$optionCalls): bool {
                $optionCalls[] = $option;

                return true;
            },
        );

        $this->forceReconnect(new RedisConnectionConfig(host: 'h', prefix: null), $redis);

        $this->assertNotContains(\Redis::OPT_PREFIX, $optionCalls);
    }

    public function testReconnectWithZeroReadTimeoutSkipsReadTimeoutOption(): void
    {
        $redis = $this->createMock(\Redis::class);
        $optionCalls = [];
        $redis->method('setOption')->willReturnCallback(
            function (int $option) use (&$optionCalls): bool {
                $optionCalls[] = $option;

                return true;
            },
        );

        $this->forceReconnect(new RedisConnectionConfig(host: 'h', readTimeout: 0.0), $redis);

        $this->assertNotContains(\Redis::OPT_READ_TIMEOUT, $optionCalls);
    }

    public function testReconnectUsesNonPersistentConnectByDefault(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())->method('connect')->with('h', 6379, 1.0);
        $redis->expects($this->never())->method('pconnect');

        $this->forceReconnect(new RedisConnectionConfig(host: 'h'), $redis);
    }

    /**
     * Pinned property: the hash client must NOT enable the igbinary serializer
     * because hash field values have to be plain byte strings (otherwise
     * HINCRBY can't operate on counter fields).
     */
    public function testReconnectDoesNotEnableIgbinarySerializer(): void
    {
        $redis = $this->createMock(\Redis::class);
        $optionCalls = [];
        $redis->method('setOption')->willReturnCallback(
            function (int $option) use (&$optionCalls): bool {
                $optionCalls[] = $option;

                return true;
            },
        );

        $this->forceReconnect(new RedisConnectionConfig(host: 'h'), $redis);

        $this->assertNotContains(\Redis::OPT_SERIALIZER, $optionCalls);
    }

    public function testReconnectWrapsRedisExceptionAsCacheException(): void
    {
        $failingRedis = $this->createMock(\Redis::class);
        $failingRedis->method('connect')
            ->willThrowException(new \RedisException('boom'));

        $client = $this->clientWithInjectedRedis($failingRedis);

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $this->expectException(\Psr\SimpleCache\CacheException::class);
        $client->get('any-key');
    }

    public function testReconnectWrapsArbitraryThrowable(): void
    {
        $failingRedis = $this->createMock(\Redis::class);
        $failingRedis->method('connect')
            ->willThrowException(new \RuntimeException('dns blew up'));

        $client = $this->clientWithInjectedRedis($failingRedis);

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $client->get('any-key');
    }

    public function testReconnectLetsErrorsPropagate(): void
    {
        $failingRedis = $this->createMock(\Redis::class);
        $failingRedis->method('connect')
            ->willThrowException(new \TypeError('coding bug'));

        $client = $this->clientWithInjectedRedis($failingRedis);

        $this->expectException(\TypeError::class);
        $client->get('any-key');
    }

    /**
     * Pins {@see createRedisInstance()} as `protected` against a
     * ProtectedVisibility mutation that would narrow it to `private`. The
     * direct `parent::createRedisInstance()` call from a subclass scope
     * raises a fatal Error on a private parent, so PHPUnit catches it as
     * an Error and infection kills the mutant cleanly — without falling
     * through to the slow reconnect path that other tests would trigger
     * (those would each try a real `new \Redis()` connect and the
     * cumulative DNS waits would push the run past the infection timeout).
     */
    public function testCreateRedisInstanceFactoryRemainsProtected(): void
    {
        $client = new class('localhost', 6379, null) extends HashRapidCacheClient {
            public function invokeParentFactory(): \Redis
            {
                return parent::createRedisInstance();
            }
        };

        $this->assertInstanceOf(\Redis::class, $client->invokeParentFactory());
    }

    public function testCreateRedisInstanceReturnsRealRedis(): void
    {
        $client = new HashRapidCacheClient('localhost', 6379, 'test:');
        $factory = new \ReflectionMethod($client, 'createRedisInstance');

        $this->assertInstanceOf(\Redis::class, $factory->invoke($client));
    }

    // -------------------------------------------------------------------
    // wrap() / retry semantics.
    // -------------------------------------------------------------------

    public function testWrapResetsConnectionOnRedisException(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hGetAll')
            ->willThrowException(new \RedisException('connection lost'));

        try {
            $this->cacheService->get('test-key');
            $this->fail('Expected CacheException');
        } catch (\IDCT\Cache\Exception\CacheException) {
            // expected
        }

        $reflection = new \ReflectionClass($this->cacheService);
        $redisProperty = $reflection->getProperty('redis');
        $this->assertNull(
            $redisProperty->getValue($this->cacheService),
            'wrap() should clear $this->redis after a RedisException so the next call reconnects',
        );
    }

    public function testRetryOnceRecoversFromTransientError(): void
    {
        $config = new RedisConnectionConfig(host: 'h', retryOnce: true);
        $callCount = 0;
        $client = new class($config, $this->redisMock) extends HashRapidCacheClient {
            public function __construct(RedisConnectionConfig $config, private \Redis $injected)
            {
                parent::__construct($config);
            }

            protected function createRedisInstance(): \Redis
            {
                return $this->injected;
            }
        };

        $this->redisMock->method('isConnected')->willReturn(false, true);
        $this->redisMock->method('connect')->willReturn(true);
        $this->redisMock->method('hGetAll')->willReturnCallback(function () use (&$callCount) {
            ++$callCount;
            if (1 === $callCount) {
                throw new \RedisException('transient');
            }

            return ['name' => 'recovered'];
        });

        $this->assertSame(['name' => 'recovered'], $client->get('test-key'));
        $this->assertSame(2, $callCount);
    }

    public function testRetryOnceDoesNotMaskPermanentError(): void
    {
        $config = new RedisConnectionConfig(host: 'h', retryOnce: true);
        $client = new class($config, $this->redisMock) extends HashRapidCacheClient {
            public function __construct(RedisConnectionConfig $config, private \Redis $injected)
            {
                parent::__construct($config);
            }

            protected function createRedisInstance(): \Redis
            {
                return $this->injected;
            }
        };

        $this->redisMock->method('isConnected')->willReturn(false, true);
        $this->redisMock->method('connect')->willReturn(true);
        $this->redisMock->method('hGetAll')
            ->willThrowException(new \RedisException('permanent'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $client->get('test-key');
    }

    /**
     * retryOnce=false (the default) must NOT retry. A RedisException surfaces
     * as CacheException on the first attempt - the op closure is invoked
     * exactly once. Pins the `$this->config->retryOnce && ...` guard against
     * a LogicalAnd→LogicalOr mutation that would always enter the retry path.
     *
     * Uses the injected-mock subclass so a hypothetical retry reconnects to
     * the same mock instead of falling back to a real \Redis(); otherwise a
     * mutated retry would silently fail at reconnect (different exception
     * type) and the assertion below would pass by accident.
     */
    public function testWrapDoesNotRetryWhenRetryOnceFalse(): void
    {
        $config = new RedisConnectionConfig(host: 'h', retryOnce: false);
        $client = new class($config, $this->redisMock) extends HashRapidCacheClient {
            public function __construct(RedisConnectionConfig $config, private \Redis $injected)
            {
                parent::__construct($config);
            }

            protected function createRedisInstance(): \Redis
            {
                return $this->injected;
            }
        };

        $this->redisMock->method('isConnected')->willReturn(false, true);
        $this->redisMock->method('connect')->willReturn(true);
        $callCount = 0;
        $this->redisMock->method('hGetAll')->willReturnCallback(function () use (&$callCount): never {
            ++$callCount;
            throw new \RedisException('boom');
        });

        try {
            $client->get('k');
            $this->fail('Expected CacheException');
        } catch (\IDCT\Cache\Exception\CacheException) {
            // expected
        }
        $this->assertSame(1, $callCount, 'wrap() must not retry when retryOnce is false');
    }

    /**
     * After a successful retry, the `$this->retrying` flag must be reset to
     * false so a subsequent transient error on a later call can ALSO trigger
     * a retry. Pins the `finally { $this->retrying = false; }` block against
     * UnwrapFinally / Finally_ mutations.
     */
    public function testRetryFlagIsResetAfterSuccessfulRetry(): void
    {
        $config = new RedisConnectionConfig(host: 'h', retryOnce: true);
        $client = new class($config, $this->redisMock) extends HashRapidCacheClient {
            public function __construct(RedisConnectionConfig $config, private \Redis $injected)
            {
                parent::__construct($config);
            }

            protected function createRedisInstance(): \Redis
            {
                return $this->injected;
            }
        };

        $callCount = 0;
        $this->redisMock->method('isConnected')->willReturn(false, true, true, true);
        $this->redisMock->method('connect')->willReturn(true);
        $this->redisMock->method('hGetAll')->willReturnCallback(function () use (&$callCount): array {
            ++$callCount;
            // Each "call" fails once then recovers - so calls 1 & 3 throw,
            // calls 2 & 4 succeed. If the retrying flag stayed `true` after
            // the first call's recovery, the second call's first attempt
            // wouldn't trigger a retry and the test would fail.
            if (1 === $callCount || 3 === $callCount) {
                throw new \RedisException('transient');
            }

            return ['name' => 'ok'];
        });

        $this->assertSame(['name' => 'ok'], $client->get('first'));
        $this->assertSame(['name' => 'ok'], $client->get('second'));
        $this->assertSame(4, $callCount);
    }

    // -------------------------------------------------------------------
    // Hardening: pin specific code paths against escaping mutants.
    // -------------------------------------------------------------------

    /**
     * setMultiple with TTL=0 must call sMembers('H_TAGS:<key>') for each KEY
     * - not for each VALUE. Pins the `array_keys($normalized)` iteration
     * against a mutation to `$normalized` that would iterate values.
     */
    public function testSetMultipleWithZeroTtlUnindexesByOriginalKeysNotValues(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $sMembersCalls = [];
        $this->redisMock->method('sMembers')->willReturnCallback(
            function (string $key) use (&$sMembersCalls): array {
                $sMembersCalls[] = $key;

                return [];
            },
        );
        $this->redisMock->method('del')->willReturn(1);

        $this->cacheService->setMultiple([
            'key_one' => ['a' => 1],
            'key_two' => ['b' => 2],
        ], 0);

        $this->assertContains('H_TAGS:key_one', $sMembersCalls);
        $this->assertContains('H_TAGS:key_two', $sMembersCalls);
    }

    /**
     * deleteMultiple must call unindexKey() once per KEY (not per VALUE) -
     * the same KEY-vs-VALUE hardening as the setMultiple TTL=0 case.
     */
    public function testDeleteMultipleUnindexesEveryKey(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $sMembersCalls = [];
        $this->redisMock->method('sMembers')->willReturnCallback(
            function (string $key) use (&$sMembersCalls): array {
                $sMembersCalls[] = $key;

                return [];
            },
        );
        $this->redisMock->method('del')->willReturn(1);

        $this->cacheService->deleteMultiple(['key_one', 'key_two']);

        $this->assertContains('H_TAGS:key_one', $sMembersCalls);
        $this->assertContains('H_TAGS:key_two', $sMembersCalls);
    }

    /**
     * setMultiple's TTL=0 bulk delete passes a list of stringified key names
     * to phpredis. Pins the {@see array_map} that converts the chunk into
     * pure strings (PHP auto-coerces numeric string keys to ints, which would
     * fail phpredis's `array|string` parameter on `del`).
     */
    public function testSetMultipleZeroTtlConvertsBulkDelKeysToStrings(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturn([]);
        $delChunks = [];
        $this->redisMock->method('del')->willReturnCallback(
            function (string|array $arg) use (&$delChunks): int {
                if (is_array($arg)) {
                    $delChunks[] = $arg;
                }

                return 1;
            },
        );

        $this->cacheService->setMultiple([
            '42' => ['a' => 1],
            '100' => ['b' => 2],
        ], 0);

        // The list of key names handed to del() must be strings, not the ints
        // PHP would otherwise produce from numeric keys.
        $this->assertSame([['42', '100']], $delChunks);
    }

    /**
     * Sanity check on the SSCAN-driven getTagged: members returned with
     * non-zero indexes (which Redis can emit under certain serializers) get
     * reindexed via array_values before the pipelined HGETALL goes out, so
     * `foreach ($members as $i => $member)` aligns with the HGETALL results
     * by position.
     */
    public function testGetTaggedReindexesSscanMembers(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sScan')->willReturnCallback(
            function (string $key, ?int &$iterator): array {
                $iterator = 0;

                return [42 => 'k1', 99 => 'k2'];
            },
        );
        $this->redisMock->method('exec')->willReturn([
            ['name' => 'foo'],
            ['name' => 'bar'],
        ]);

        $this->assertSame(
            ['k1' => ['name' => 'foo'], 'k2' => ['name' => 'bar']],
            iterator_to_array($this->cacheService->getTagged('t')),
        );
    }

    // -------------------------------------------------------------------
    // Legacy constructor + default port.
    // -------------------------------------------------------------------

    public function testLegacyConstructorAppliesDefaultPortWhenNullGiven(): void
    {
        $client = new HashRapidCacheClient('h', null, 'p:');
        $reflection = new \ReflectionClass($client);
        $config = $reflection->getProperty('config')->getValue($client);
        $this->assertInstanceOf(RedisConnectionConfig::class, $config);
        $this->assertSame(HashRapidCacheClient::DEFAULT_REDIS_PORT, $config->port);
    }

    public function testLegacyConstructorPreservesExplicitPort(): void
    {
        $client = new HashRapidCacheClient('h', 6390, 'p:');
        $reflection = new \ReflectionClass($client);
        $config = $reflection->getProperty('config')->getValue($client);
        $this->assertInstanceOf(RedisConnectionConfig::class, $config);
        $this->assertSame(6390, $config->port);
    }

    public function testConstructorAcceptsRedisConnectionConfig(): void
    {
        $config = new RedisConnectionConfig(host: 'h', port: 6390, prefix: 'p:');
        $client = new HashRapidCacheClient($config);
        $reflection = new \ReflectionClass($client);
        $stored = $reflection->getProperty('config')->getValue($client);
        $this->assertSame($config, $stored);
    }

    // -------------------------------------------------------------------
    // CacheException error code pinning (Increment/Decrement on the
    // `0` literal in the constructor's third argument).
    // -------------------------------------------------------------------

    /**
     * Pins the `0` code passed to {@see CacheException} in {@see reconnect()}.
     * IncrementInteger / DecrementInteger would shift it to 1 or -1.
     */
    public function testCacheExceptionFromReconnectFailureCarriesZeroCode(): void
    {
        $failingRedis = $this->createMock(\Redis::class);
        $failingRedis->method('connect')
            ->willThrowException(new \RedisException('boom'));
        $client = $this->clientWithInjectedRedis($failingRedis);

        try {
            $client->get('any-key');
            $this->fail('Expected CacheException');
        } catch (\IDCT\Cache\Exception\CacheException $e) {
            $this->assertSame(0, $e->getCode());
        }
    }

    /**
     * Same pin for {@see toCacheException()} — the second-line `0` literal in
     * the wrap()-time exception translator.
     */
    public function testCacheExceptionFromRedisFailureCarriesZeroCode(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hGetAll')
            ->willThrowException(new \RedisException('boom'));

        try {
            $this->cacheService->get('k');
            $this->fail('Expected CacheException');
        } catch (\IDCT\Cache\Exception\CacheException $e) {
            $this->assertSame(0, $e->getCode());
        }
    }

    // -------------------------------------------------------------------
    // getRedis() protected visibility — needs a subclass that actually
    // calls it, otherwise the protected→private mutation goes unnoticed.
    // -------------------------------------------------------------------

    /**
     * Pins {@see getRedis()} as `protected`. If it were narrowed to `private`,
     * the subclass below would fatal at `parent::getRedis()` resolution.
     */
    public function testGetRedisIsReachableFromSubclass(): void
    {
        $client = new class('h', 6379, null, $this->redisMock) extends HashRapidCacheClient {
            public function __construct(string $host, ?int $port, ?string $prefix, private \Redis $injected)
            {
                parent::__construct($host, $port, $prefix);
                $reflection = new \ReflectionClass(HashRapidCacheClient::class);
                $reflection->getProperty('redis')->setValue($this, $injected);
            }

            public function callGetRedis(): \Redis
            {
                return $this->getRedis();
            }
        };
        $this->redisMock->method('isConnected')->willReturn(true);

        $this->assertSame($this->redisMock, $client->callGetRedis());
    }

    // -------------------------------------------------------------------
    // clear(): the SCAN-failure `break;` must NOT be a `continue;` — that
    // would re-enter scan() forever when iterator stays > 0.
    // -------------------------------------------------------------------

    /**
     * Pins the `break;` on line 388 against a Break_→Continue_ mutation:
     * second scan returns false with iterator still > 0; only the break
     * keeps total scan calls at 2.
     */
    public function testClearBreaksScanLoopWhenScanFailsWhileIteratorRemainsActive(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('getOption')->willReturn(0);
        $this->redisMock->method('setOption')->willReturn(true);
        $scanMatcher = $this->exactly(2);
        $this->redisMock->expects($scanMatcher)
            ->method('scan')
            ->willReturnCallback(function (?int &$iterator) use ($scanMatcher) {
                if (1 === $scanMatcher->numberOfInvocations()) {
                    $iterator = 5;

                    return ['test:k1'];
                }
                // Second call: iterator stays 5 (server-side error path),
                // returns false. With break;, loop exits. With continue;,
                // it would loop forever — `exactly(2)` would fail.
                return false;
            });
        $this->redisMock->method('unlink')->willReturn(1);

        $this->assertTrue($this->cacheService->clear());
    }

    // -------------------------------------------------------------------
    // getMultiple(): `continue;` on a non-array exec result must move to
    // the next batch; `break;` would skip every batch after the failure.
    // -------------------------------------------------------------------

    /**
     * Pins the `continue;` on line 445. Two batches: first exec returns
     * false (fills defaults), second returns valid results. `break;` would
     * leave the second batch's keys absent from the result map.
     */
    public function testGetMultipleContinuesToNextBatchAfterFailedExec(): void
    {
        $config = new RedisConnectionConfig(host: 'h', pipelineBatchSize: 2);
        $client = new HashRapidCacheClient($config);
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('redis')->setValue($client, $this->redisMock);

        $this->redisMock->method('isConnected')->willReturn(true);
        $execCalls = 0;
        $this->redisMock->method('exec')
            ->willReturnCallback(function () use (&$execCalls) {
                ++$execCalls;

                return 1 === $execCalls
                    ? false
                    : [['name' => 'baz'], ['name' => 'qux']];
            });

        $result = (array) $client->getMultiple(['k1', 'k2', 'k3', 'k4'], 'D');

        $this->assertSame(
            [
                'k1' => 'D',
                'k2' => 'D',
                'k3' => ['name' => 'baz'],
                'k4' => ['name' => 'qux'],
            ],
            $result,
        );
    }

    // -------------------------------------------------------------------
    // setTagged(): validateArrayValue() must run before TTL normalisation
    // for the null/positive-TTL path. MethodCallRemoval would let a
    // non-array slip through and reach hMSet.
    // -------------------------------------------------------------------

    /**
     * Pins {@see validateArrayValue()} as called from {@see setTagged()}.
     * With the call removed, a non-array $value would bypass the contract
     * check and the test would not see the PSR-16 exception.
     */
    public function testSetTaggedRejectsNonArrayValueWithoutTtl(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->setTagged('k', 'not-an-array', 't');
    }

    /**
     * The same pin via empty-array — both branches of validateArrayValue()
     * must fire before TTL handling reaches the pipeline.
     */
    public function testSetTaggedRejectsEmptyArrayValue(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->setTagged('k', [], 't', 60);
    }

    // -------------------------------------------------------------------
    // getTagged(): cleanup must run via `finally` (UnwrapFinally would
    // skip it when the read phase throws), and the pipeline cleanup
    // must actually use multi() (MethodCallRemoval on line 704).
    // -------------------------------------------------------------------

    /**
     * Pins the `finally { ... }` against UnwrapFinally: first page collects
     * a stale entry; second sScan throws. With finally, cleanup still
     * sRem's the stale entry. Without finally, the throw skips cleanup.
     */
    public function testGetTaggedCleansUpStaleMembersEvenWhenReadPhaseThrows(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);

        $sScanCalls = 0;
        $this->redisMock->method('sScan')
            ->willReturnCallback(function (string $key, ?int &$iterator) use (&$sScanCalls) {
                ++$sScanCalls;
                if (1 === $sScanCalls) {
                    $iterator = 5;

                    return ['expired-key'];
                }
                throw new \RedisException('scan failed');
            });

        $this->redisMock->method('multi')->willReturnSelf();
        // First exec: HGETALL batch returns one empty array (=> stale).
        // Second exec (cleanup pipeline) returns true.
        $execCalls = 0;
        $this->redisMock->method('exec')
            ->willReturnCallback(function () use (&$execCalls) {
                ++$execCalls;

                return 1 === $execCalls ? [[]] : [1, 1];
            });

        $sRemCalls = [];
        $this->redisMock->method('sRem')
            ->willReturnCallback(function (string $set, string $member) use (&$sRemCalls) {
                $sRemCalls[] = [$set, $member];

                return 1;
            });

        try {
            iterator_to_array($this->cacheService->getTagged('t'));
            $this->fail('Expected CacheException from second sScan');
        } catch (\IDCT\Cache\Exception\CacheException) {
            // expected — exception propagates AFTER cleanup runs.
        }

        $this->assertContains(['H_TAG:t', 'expired-key'], $sRemCalls);
        $this->assertContains(['H_TAGS:expired-key', 't'], $sRemCalls);
    }

    /**
     * Pins the `$redis->multi(\Redis::PIPELINE);` call inside the finally
     * block (line 704). With it removed, sRem's would still fire but not
     * inside a pipeline — so a strict multi() expectation catches it.
     */
    public function testGetTaggedCleanupRunsInsidePipeline(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sScan')->willReturnCallback(
            function (string $key, ?int &$iterator) {
                $iterator = 0;

                return ['expired-key'];
            },
        );

        // multi() must be called twice: once for the HGETALL batch and once
        // for the finally cleanup.
        $this->redisMock->expects($this->exactly(2))->method('multi');
        $this->redisMock->method('exec')->willReturn([[]], [1, 1]);
        $this->redisMock->method('sRem')->willReturn(1);

        iterator_to_array($this->cacheService->getTagged('t'));
    }

    // -------------------------------------------------------------------
    // getTagged(): `continue;` (not `break;`) on empty page and on
    // non-array exec — pins multi-page iteration semantics.
    // -------------------------------------------------------------------

    /**
     * Pins the `continue;` on line 677: an empty sScan page must not stop
     * iteration; the next page's data must still be yielded.
     */
    public function testGetTaggedContinuesScanLoopAcrossEmptyPage(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $sScanCalls = 0;
        $this->redisMock->method('sScan')->willReturnCallback(
            function (string $key, ?int &$iterator) use (&$sScanCalls): array {
                ++$sScanCalls;
                if (1 === $sScanCalls) {
                    $iterator = 7;

                    return [];
                }
                $iterator = 0;

                return ['k1'];
            },
        );
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('exec')->willReturn([['name' => 'foo']]);

        $result = iterator_to_array($this->cacheService->getTagged('t'));

        $this->assertSame(['k1' => ['name' => 'foo']], $result);
    }

    /**
     * Pins the `continue;` on line 686: an exec returning non-array for one
     * page must not stop iteration; the next page must still be processed.
     */
    public function testGetTaggedContinuesScanLoopAfterExecFailure(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $sScanCalls = 0;
        $this->redisMock->method('sScan')->willReturnCallback(
            function (string $key, ?int &$iterator) use (&$sScanCalls): array {
                ++$sScanCalls;
                if (1 === $sScanCalls) {
                    $iterator = 7;

                    return ['bad-key'];
                }
                $iterator = 0;

                return ['good-key'];
            },
        );
        $this->redisMock->method('multi')->willReturnSelf();
        $execCalls = 0;
        $this->redisMock->method('exec')->willReturnCallback(function () use (&$execCalls) {
            ++$execCalls;

            return 1 === $execCalls ? false : [['name' => 'foo']];
        });

        $result = iterator_to_array($this->cacheService->getTagged('t'));

        $this->assertSame(['good-key' => ['name' => 'foo']], $result);
    }

    // -------------------------------------------------------------------
    // clearByTag(): LogicalOr `||` must stay `||` — `&&` would let a
    // non-array sMembers fall through to array_chunk(false, ...).
    // -------------------------------------------------------------------

    /**
     * Pins the `||` on line 816. With `&&`: a non-array sMembers (false)
     * skips the empty-path early return and reaches array_chunk(false, …)
     * which TypeErrors.
     */
    public function testClearByTagShortCircuitsWhenSMembersReturnsNonArray(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturn(false);
        $this->redisMock->expects($this->once())->method('del')->with('H_TAG:t');
        $this->redisMock->expects($this->never())->method('multi');

        $this->assertTrue($this->cacheService->clearByTag('t'));
    }

    // -------------------------------------------------------------------
    // clearByTag() phase 1: assert that the per-member sMembers calls
    // actually happen with the exact "H_TAGS:<member>" string. Kills the
    // Foreach_, Concat, ConcatOperandRemoval ×2, and MethodCallRemoval
    // mutants in one shot.
    // -------------------------------------------------------------------

    /**
     * Pins the inner foreach on line 827 AND the exact
     * "H_TAGS:<member>" concat on line 828. With any of:
     *   - foreach ([] as $member) — no per-member calls
     *   - $member . self::H_TAGS_SET_NAME_PREFIX — reversed order
     *   - self::H_TAGS_SET_NAME_PREFIX alone — missing member
     *   - $member alone — missing prefix
     *   - sMembers call removed entirely
     * the recorded calls would NOT contain both H_TAGS:m1 and H_TAGS:m2.
     */
    public function testClearByTagPhase1QueriesEachMemberReverseTagSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $sMembersCalls = [];
        $this->redisMock->method('sMembers')->willReturnCallback(
            function (string $key) use (&$sMembersCalls): array {
                $sMembersCalls[] = $key;

                return 'H_TAG:t' === $key ? ['m1', 'm2'] : [];
            },
        );
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('exec')->willReturn([[], []]);

        $this->cacheService->clearByTag('t');

        $this->assertContains('H_TAG:t', $sMembersCalls);
        $this->assertContains('H_TAGS:m1', $sMembersCalls);
        $this->assertContains('H_TAGS:m2', $sMembersCalls);
    }

    // -------------------------------------------------------------------
    // clearByTag() phase 1 else-branch (line 836) pads reverseLookups
    // with null entries; without it, phase 2 indexing misaligns.
    // -------------------------------------------------------------------

    /**
     * Pins `foreach ($chunk as $_) { $reverseLookups[] = null; }` against
     * a Foreach_ mutation that replaces it with `foreach ([] as $_)`.
     * Scenario: batchSize=1, three members. First batch's exec fails
     * (else branch must pad with one null), second/third succeed.
     * Without the pad, phase 2 reverseLookups index 0 picks up m2's
     * `['t','other']` and the cross-tag sRem fires for m1 instead of m2.
     */
    public function testClearByTagPadsReverseLookupsToKeepPhaseTwoAligned(): void
    {
        $config = new RedisConnectionConfig(host: 'h', pipelineBatchSize: 1);
        $client = new HashRapidCacheClient($config);
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('redis')->setValue($client, $this->redisMock);

        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturnCallback(
            fn (string $k) => 'H_TAG:t' === $k ? ['m1', 'm2', 'm3'] : [],
        );
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('del')->willReturn(1);

        $execCalls = 0;
        $this->redisMock->method('exec')->willReturnCallback(function () use (&$execCalls) {
            ++$execCalls;

            return match ($execCalls) {
                1 => false,                  // phase 1 batch 1 (m1): fails -> pad with null
                2 => [['t', 'other']],       // phase 1 batch 2 (m2): tagged with 'other' too
                3 => [['t']],                // phase 1 batch 3 (m3): only 't'
                default => [1],              // phase 2 batches
            };
        });

        $sRemCalls = [];
        $this->redisMock->method('sRem')->willReturnCallback(
            function (string $set, string $member) use (&$sRemCalls) {
                $sRemCalls[] = [$set, $member];

                return 1;
            },
        );

        $client->clearByTag('t');

        $this->assertContains(['H_TAG:other', 'm2'], $sRemCalls);
        $this->assertNotContains(['H_TAG:other', 'm1'], $sRemCalls);
        $this->assertNotContains(['H_TAG:other', 'm3'], $sRemCalls);
    }

    // -------------------------------------------------------------------
    // clearByTag() phase-2 for-loop bounds (line 843).
    // -------------------------------------------------------------------

    /**
     * Pins `$offset < $totalMembers` (against `<=`) AND `$offset +=
     * $batchSize` (against `=`).
     *
     * With N=4 and batchSize=2:
     *   - normal:   phase 1 = 2 multi calls, phase 2 = 2 multi calls (4 total)
     *   - `<=`:     phase 2 fires one extra empty iteration → 5 total
     *   - `=`:      $offset stays at $batchSize forever → infinite loop;
     *               the 100-call safety cap throws a RuntimeException.
     */
    public function testClearByTagPhase2LoopBoundsAreExact(): void
    {
        $config = new RedisConnectionConfig(host: 'h', pipelineBatchSize: 2);
        $client = new HashRapidCacheClient($config);
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('redis')->setValue($client, $this->redisMock);

        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturnCallback(
            fn (string $k) => 'H_TAG:t' === $k ? ['m1', 'm2', 'm3', 'm4'] : [],
        );

        $multiCount = 0;
        $this->redisMock->method('multi')->willReturnCallback(function () use (&$multiCount) {
            if (++$multiCount > 100) {
                throw new \RuntimeException('safety cap: too many multi() calls — likely infinite loop');
            }

            return $this->redisMock;
        });
        $this->redisMock->method('exec')->willReturn([[], [], [], []]);
        $this->redisMock->method('del')->willReturn(1);

        $this->assertTrue($client->clearByTag('t'));
        $this->assertSame(4, $multiCount, 'expected exactly 2 phase-1 + 2 phase-2 multi() calls');
    }

    // -------------------------------------------------------------------
    // hasField(): the (bool) cast pins the return type when phpredis
    // hands back a raw int.
    // -------------------------------------------------------------------

    /**
     * Pins `(bool)` against CastBool removal. phpredis declares
     * `Redis|bool` on hExists — the Redis case happens in pipeline mode.
     * With the cast in place a Redis return becomes `true`; without the
     * cast the source's `: bool` return type rejects the Redis instance
     * with a TypeError.
     */
    public function testHasFieldCoercesRedisReturnFromHExistsToBool(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hExists')->willReturnSelf();

        $this->assertSame(true, $this->cacheService->hasField('k', 'f'));
    }

    // -------------------------------------------------------------------
    // getFields(): the refactored array_key_exists / false-sentinel path
    // already has full coverage; this extra test pins the FalseValue
    // mutant on the new `false === $values[$field]` check.
    // -------------------------------------------------------------------

    /**
     * Pins `false === $values[$field]` against FalseValue → `true ===`.
     * hMGet returns a real value for one field and false (= missing) for
     * the other. With the mutation, the missing field's $default never
     * gets substituted because `true === false` is false.
     */
    public function testGetFieldsAppliesDefaultWhenHMGetReturnsFalseSentinel(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hMGet')
            ->willReturn(['present' => 'v', 'absent' => false]);

        $this->assertSame(
            ['present' => 'v', 'absent' => 'D'],
            $this->cacheService->getFields('k', ['present', 'absent'], 'D'),
        );
    }

    /**
     * Pins the `if (!\is_array($values))` branch (now: `$values = []`):
     * with hMGet returning non-array, all requested fields must come back
     * as the default.
     */
    public function testGetFieldsTreatsNonArrayHMGetAsAllMissing(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hMGet')->willReturn(false);

        $this->assertSame(
            ['a' => 'D', 'b' => 'D'],
            $this->cacheService->getFields('k', ['a', 'b'], 'D'),
        );
    }

    // -------------------------------------------------------------------
    // incrementField() / decrementField(): the (int) / (float) casts
    // matter when phpredis hands back numeric strings (HINCRBYFLOAT
    // historically returns strings via some serialization paths).
    // -------------------------------------------------------------------

    /**
     * Pins `(int)` on incrementField()'s HINCRBY return. phpredis declares
     * `Redis|int|false` — the `false` arm represents WRONGTYPE / error.
     * With the cast, `false` collapses to `0`; without the cast the source's
     * `: int|float` return type rejects `false` with a TypeError.
     */
    public function testIncrementFieldCastsFalseReturnFromHIncrByToZero(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hIncrBy')->willReturn(false);

        $this->assertSame(0, $this->cacheService->incrementField('k', 'counter', 3));
    }

    /**
     * Pins `(float)` on incrementField()'s HINCRBYFLOAT return — mirror of
     * the int case. phpredis declares `Redis|float|false`.
     */
    public function testIncrementFieldCastsFalseReturnFromHIncrByFloatToZero(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hIncrByFloat')->willReturn(false);

        $this->assertSame(0.0, $this->cacheService->incrementField('k', 'counter', 1.5));
    }

    /**
     * Same pin for decrementField()'s HINCRBY path.
     */
    public function testDecrementFieldCastsFalseReturnFromHIncrByToZero(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hIncrBy')->willReturn(false);

        $this->assertSame(0, $this->cacheService->decrementField('k', 'counter', 3));
    }

    /**
     * Same pin for decrementField()'s HINCRBYFLOAT path.
     */
    public function testDecrementFieldCastsFalseReturnFromHIncrByFloatToZero(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('hIncrByFloat')->willReturn(false);

        $this->assertSame(0.0, $this->cacheService->decrementField('k', 'counter', 0.5));
    }

    // -------------------------------------------------------------------
    // wrap() retry semantics: pin the retrying-flag assignment and the
    // retry-error throw (TrueValue / Throw_ mutants).
    // -------------------------------------------------------------------

    /**
     * Pins `$this->retrying = true`. With the mutation `= false`, the
     * flag stays false during the retry — observable via reflection from
     * inside the retry callback.
     */
    public function testWrapSetsRetryingFlagToTrueDuringRetryAttempt(): void
    {
        $config = new RedisConnectionConfig(host: 'h', retryOnce: true);
        $client = new class($config, $this->redisMock) extends HashRapidCacheClient {
            public function __construct(RedisConnectionConfig $config, private \Redis $injected)
            {
                parent::__construct($config);
            }

            protected function createRedisInstance(): \Redis
            {
                return $this->injected;
            }
        };

        $callCount = 0;
        $observed = null;
        $this->redisMock->method('isConnected')->willReturn(false, true, true);
        $this->redisMock->method('connect')->willReturn(true);
        $this->redisMock->method('hGetAll')->willReturnCallback(
            function () use (&$callCount, &$observed, $client) {
                ++$callCount;
                if (1 === $callCount) {
                    throw new \RedisException('transient');
                }
                $observed = (new \ReflectionProperty(HashRapidCacheClient::class, 'retrying'))
                    ->getValue($client);

                return ['ok' => 'yes'];
            },
        );

        $client->get('test-key');

        $this->assertTrue($observed, 'wrap() must set $this->retrying = true during the retry attempt');
    }

    /**
     * Pins the `throw` on line 1332 against Throw_ removal. Without the
     * throw, the inner catch creates the retry CacheException but never
     * raises it — control falls through to the outer
     * `throw $this->toCacheException($e);`, so $e->getPrevious() would
     * become the ORIGINAL error rather than the retry error.
     */
    public function testWrapPropagatesRetryErrorAsCacheExceptionCause(): void
    {
        $config = new RedisConnectionConfig(host: 'h', retryOnce: true);
        $client = new class($config, $this->redisMock) extends HashRapidCacheClient {
            public function __construct(RedisConnectionConfig $config, private \Redis $injected)
            {
                parent::__construct($config);
            }

            protected function createRedisInstance(): \Redis
            {
                return $this->injected;
            }
        };

        $first = new \RedisException('first-attempt');
        $retry = new \RedisException('retry-attempt');
        $callCount = 0;
        $this->redisMock->method('isConnected')->willReturn(false, true, true);
        $this->redisMock->method('connect')->willReturn(true);
        $this->redisMock->method('hGetAll')->willReturnCallback(
            function () use (&$callCount, $first, $retry) {
                ++$callCount;
                throw 1 === $callCount ? $first : $retry;
            },
        );

        try {
            $client->get('test-key');
            $this->fail('Expected CacheException');
        } catch (\IDCT\Cache\Exception\CacheException $e) {
            $this->assertSame(
                $retry,
                $e->getPrevious(),
                'wrap() must chain the retry error as the CacheException cause, not the original',
            );
        }
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function clientWithInjectedRedis(\Redis $redis): HashRapidCacheClient
    {
        return new class('host', 6379, null, $redis) extends HashRapidCacheClient {
            public function __construct(string $host, ?int $port, ?string $prefix, private \Redis $injected)
            {
                parent::__construct($host, $port, $prefix);
            }

            protected function createRedisInstance(): \Redis
            {
                return $this->injected;
            }
        };
    }

    private function forceReconnect(RedisConnectionConfig $config, \Redis $injected): void
    {
        $client = new class($config, $injected) extends HashRapidCacheClient {
            public function __construct(RedisConnectionConfig $config, private \Redis $injected)
            {
                parent::__construct($config);
            }

            protected function createRedisInstance(): \Redis
            {
                return $this->injected;
            }

            public function forceConnect(): void
            {
                $this->reconnect();
            }
        };
        $client->forceConnect();
    }
}
