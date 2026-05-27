<?php

declare(strict_types=1);

namespace IDCT\Tests\Cache\Unit;

use DateInterval;
use IDCT\Cache\CacheServiceInterface;
use IDCT\Cache\Exception\InvalidArgumentException;
use IDCT\Cache\RapidCacheClient;
use IDCT\Cache\RedisConnectionConfig;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;
use Redis;

#[CoversClass(RapidCacheClient::class)]
class RapidCacheClientTest extends TestCase
{
    use PHPMock;

    private RapidCacheClient $cacheService;
    private Redis&\PHPUnit\Framework\MockObject\MockObject $redisMock;

    protected function setUp(): void
    {
        $this->redisMock = $this->createMock(Redis::class);
        $host = $_ENV['REDIS_HOST'] ?? 'localhost';
        $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);
        $this->cacheService = new RapidCacheClient($host, $port, 'test:');

        $reflection = new \ReflectionClass($this->cacheService);
        $redisProperty = $reflection->getProperty('redis');
        $redisProperty->setValue($this->cacheService, $this->redisMock);
    }

    /**
     * The client must satisfy both the PSR-16 CacheInterface and this package's
     * CacheServiceInterface (which extends PSR-16 with tags, queues, sets, counters).
     */
    public function testImplementsPsrSimpleCache(): void
    {
        $this->assertInstanceOf(CacheInterface::class, $this->cacheService);
        $this->assertInstanceOf(CacheServiceInterface::class, $this->cacheService);
    }

    /**
     * On a hit, get() returns the stored value via one GET — no follow-up EXISTS
     * probe (that path is only used to disambiguate a stored literal `false`).
     */
    public function testGetReturnsValueWhenExists(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->never())->method('exists');
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('test-key')
            ->willReturn('test-value');

        $this->assertSame('test-value', $this->cacheService->get('test-key'));
    }

    /**
     * When GET returns false AND a follow-up EXISTS confirms the key is gone,
     * we return the caller-supplied default (or null when none was given).
     */
    public function testGetReturnsDefaultWhenTrulyMissing(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $getMatcher = $this->exactly(2);
        $this->redisMock->expects($getMatcher)
            ->method('get')
            ->willReturnCallback(function (string $key) use ($getMatcher) {
                match ($getMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('test-key', $key),
                    2 => $this->assertSame('missing-key', $key),
                };
                return false;
            });
        $existsMatcher = $this->exactly(2);
        $this->redisMock->expects($existsMatcher)
            ->method('exists')
            ->willReturnCallback(function (string $key) use ($existsMatcher) {
                match ($existsMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('test-key', $key),
                    2 => $this->assertSame('missing-key', $key),
                };
                return 0;
            });

        $this->assertNull($this->cacheService->get('test-key'));
        $this->assertSame('fallback', $this->cacheService->get('missing-key', 'fallback'));
    }

    /**
     * The literal `false` is a valid stored value: when GET returns false but
     * EXISTS=1, the client yields `false`, never the default. This is the only
     * reason the extra EXISTS round-trip exists.
     */
    public function testGetReturnsStoredFalse(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('flag-key')
            ->willReturn(false);
        $this->redisMock->expects($this->once())
            ->method('exists')
            ->with('flag-key')
            ->willReturn(1);

        $this->assertFalse($this->cacheService->get('flag-key', 'should-not-be-default'));
    }

    /**
     * PSR-16: keys containing reserved chars ({}()/\@:) must throw a
     * PsrInvalidArgumentException — not a CacheException.
     */
    public function testGetRejectsInvalidKey(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->get('invalid:key');
    }

    /**
     * No TTL → plain SET (not SETEX). The value persists indefinitely.
     */
    public function testSetWithoutTtl(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('set')
            ->with('test-key', 'test-value')
            ->willReturn(true);

        $this->assertTrue($this->cacheService->set('test-key', 'test-value'));
    }

    /**
     * Positive integer TTL → SETEX with the exact seconds value passed through.
     */
    public function testSetWithIntegerTtl(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('setex')
            ->with('test-key', 3600, 'test-value')
            ->willReturn(true);

        $this->assertTrue($this->cacheService->set('test-key', 'test-value', 3600));
    }

    /**
     * PSR-16 also allows DateInterval as TTL — `PT1M` must be resolved to 60s
     * and forwarded to SETEX as an integer.
     */
    public function testSetWithDateIntervalTtl(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('setex')
            ->with('test-key', 60, 'test-value')
            ->willReturn(true);

        $this->assertTrue(
            $this->cacheService->set('test-key', 'test-value', new DateInterval('PT1M'))
        );
    }

    /**
     * TTL=0 is treated as immediate-delete: the key is unlinked, its reverse tag
     * set is cleaned, and neither SET nor SETEX is issued. Returns true.
     */
    public function testSetWithZeroTtlDeletesEntry(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sMembers')
            ->with('TAGS:test-key')
            ->willReturn([]);
        $delMatcher = $this->exactly(2);
        $this->redisMock->expects($delMatcher)
            ->method('del')
            ->willReturnCallback(function (string $key) use ($delMatcher) {
                match ($delMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('TAGS:test-key', $key),
                    2 => $this->assertSame('test-key', $key),
                };
                return 1;
            });
        $this->redisMock->expects($this->never())->method('set');
        $this->redisMock->expects($this->never())->method('setex');

        $this->assertTrue($this->cacheService->set('test-key', 'test-value', 0));
    }

    /**
     * Brace chars are reserved by PSR-16 — set() must reject before touching Redis.
     */
    public function testSetRejectsInvalidKey(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->set('bad{key}', 'value');
    }

    /**
     * setTagged without TTL fires one MULTI/PIPELINE containing SET +
     * sAdd(TAG:tag→key) + sAdd(TAGS:key→tag) — the value and both index sides
     * commit in a single round-trip.
     */
    public function testSetTagged(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('multi')
            ->with(Redis::PIPELINE);
        $this->redisMock->expects($this->once())
            ->method('set')
            ->with('test-key', 'test-value');
        $sAddMatcher = $this->exactly(2);
        $this->redisMock->expects($sAddMatcher)
            ->method('sAdd')
            ->willReturnCallback(function (string $key, string $value) use ($sAddMatcher) {
                match ($sAddMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['TAG:test-tag', 'test-key'], [$key, $value]),
                    2 => $this->assertSame(['TAGS:test-key', 'test-tag'], [$key, $value]),
                };
                return 1;
            });
        $this->redisMock->expects($this->once())
            ->method('exec')
            ->willReturn([true, 1, 1]);

        $this->assertTrue($this->cacheService->setTagged('test-key', 'test-value', 'test-tag'));
    }

    /**
     * setTagged with a TTL swaps SET for SETEX inside the same MULTI/PIPELINE;
     * the two sAdd calls stay unchanged.
     */
    public function testSetTaggedWithTtl(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('multi')
            ->with(Redis::PIPELINE);
        $this->redisMock->expects($this->once())
            ->method('setex')
            ->with('test-key', 3600, 'test-value');
        $sAddMatcher = $this->exactly(2);
        $this->redisMock->expects($sAddMatcher)
            ->method('sAdd')
            ->willReturnCallback(function (string $key, string $value) use ($sAddMatcher) {
                match ($sAddMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['TAG:test-tag', 'test-key'], [$key, $value]),
                    2 => $this->assertSame(['TAGS:test-key', 'test-tag'], [$key, $value]),
                };
                return 1;
            });
        $this->redisMock->expects($this->once())
            ->method('exec')
            ->willReturn([true, 1, 1]);

        $this->assertTrue($this->cacheService->setTagged('test-key', 'test-value', 'test-tag', 3600));
    }

    /**
     * delete() must cascade: read the key's reverse tag list (TAGS:key), then
     * sRem the key from each TAG:* set, drop the reverse set itself, and finally
     * unlink the key. All cleanup writes go in one MULTI/PIPELINE.
     */
    public function testDeleteRemovesTagAssociations(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sMembers')
            ->with('TAGS:test-key')
            ->willReturn(['tag1', 'tag2']);
        $this->redisMock->expects($this->once())
            ->method('multi')
            ->with(Redis::PIPELINE);
        $sRemMatcher = $this->exactly(2);
        $this->redisMock->expects($sRemMatcher)
            ->method('sRem')
            ->willReturnCallback(function (string $set, string $member) use ($sRemMatcher) {
                match ($sRemMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['TAG:tag1', 'test-key'], [$set, $member]),
                    2 => $this->assertSame(['TAG:tag2', 'test-key'], [$set, $member]),
                };
                return 1;
            });
        $delMatcher = $this->exactly(2);
        $this->redisMock->expects($delMatcher)
            ->method('del')
            ->willReturnCallback(function (string $key) use ($delMatcher) {
                match ($delMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('TAGS:test-key', $key),
                    2 => $this->assertSame('test-key', $key),
                };
                return 1;
            });
        $this->redisMock->expects($this->once())
            ->method('exec')
            ->willReturn([1, 1, 1]);

        $this->assertTrue($this->cacheService->delete('test-key'));
    }

    /**
     * Empty string is not a valid PSR-16 key — delete() must reject.
     */
    public function testDeleteRejectsInvalidKey(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->delete('');
    }

    /**
     * clear() with a prefix configured must NOT call FLUSHDB (that'd wipe other
     * apps sharing the database). Instead: temporarily disable phpredis's auto
     * prefixing, SCAN/UNLINK only `<prefix>*` in batches, then restore the
     * prior OPT_PREFIX and OPT_SCAN settings on the way out.
     */
    public function testClearWithPrefixScansAndUnlinks(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->never())->method('flushAll');
        $this->redisMock->expects($this->never())->method('flushDb');
        $this->redisMock->expects($this->once())
            ->method('getOption')
            ->with(Redis::OPT_SCAN)
            ->willReturn(Redis::SCAN_NORETRY);
        $setOptionMatcher = $this->exactly(4);
        $this->redisMock->expects($setOptionMatcher)
            ->method('setOption')
            ->willReturnCallback(function (int $option, mixed $value) use ($setOptionMatcher) {
                match ($setOptionMatcher->numberOfInvocations()) {
                    1 => $this->assertSame([Redis::OPT_PREFIX, ''], [$option, $value]),
                    2 => $this->assertSame([Redis::OPT_SCAN, Redis::SCAN_RETRY], [$option, $value]),
                    3 => $this->assertSame([Redis::OPT_PREFIX, 'test:'], [$option, $value]),
                    4 => $this->assertSame([Redis::OPT_SCAN, Redis::SCAN_NORETRY], [$option, $value]),
                };
                return true;
            });
        $this->redisMock->method('scan')->willReturnCallback(
            function (&$iterator, string $pattern) {
                $this->assertSame('test:*', $pattern);
                if ($iterator === null) {
                    $iterator = 0;
                    return ['test:a', 'test:b'];
                }
                return false;
            }
        );
        $this->redisMock->expects($this->once())
            ->method('unlink')
            ->with(['test:a', 'test:b']);

        $this->assertTrue($this->cacheService->clear());
    }

    /**
     * If SCAN returns false (transport/storage error), the loop must break
     * immediately without attempting UNLINK on an invalid keyset and without
     * looping forever.
     */
    public function testClearBreaksOnScanFailure(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('getOption')->willReturn(Redis::SCAN_NORETRY);
        $scanCalls = 0;
        $this->redisMock->method('scan')->willReturnCallback(
            function (&$iterator) use (&$scanCalls) {
                $scanCalls++;
                $this->assertLessThan(3, $scanCalls, 'scan() loop should break on failure');
                $iterator = 1;
                return false;
            }
        );
        $this->redisMock->expects($this->never())->method('unlink');

        $this->assertTrue($this->cacheService->clear());
        $this->assertSame(1, $scanCalls);
    }

    /**
     * Without a prefix there's nothing to scope to, so FLUSHDB wipes the whole
     * database in one call — and SCAN is never invoked.
     */
    public function testClearWithoutPrefixUsesFlushDb(): void
    {
        $service = new RapidCacheClient('localhost', 6379);
        $reflection = new \ReflectionClass($service);
        $reflection->getProperty('redis')->setValue($service, $this->redisMock);

        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->never())->method('flushAll');
        $this->redisMock->expects($this->never())->method('scan');
        $this->redisMock->expects($this->once())->method('flushDb');

        $this->assertTrue($service->clear());
    }

    /**
     * has() is a single EXISTS round-trip; returns true for present keys, false
     * for missing ones, with no GET fallback.
     */
    public function testHas(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $existsMatcher = $this->exactly(2);
        $this->redisMock->expects($existsMatcher)
            ->method('exists')
            ->willReturnCallback(function (string $key) use ($existsMatcher) {
                $invocation = $existsMatcher->numberOfInvocations();
                match ($invocation) {
                    1 => $this->assertSame('present-key', $key),
                    2 => $this->assertSame('missing-key', $key),
                };
                return $invocation === 1 ? 1 : 0;
            });

        $this->assertTrue($this->cacheService->has('present-key'));
        $this->assertFalse($this->cacheService->has('missing-key'));
    }

    /**
     * Happy path for getMultiple: a single MGET round-trip; missing entries
     * substitute the caller-supplied default. Unlike get(), no EXISTS probe is
     * issued — bulk reads sacrifice the stored-false distinction for speed.
     */
    public function testGetMultiple(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('mGet')
            ->with(['key1', 'key2'])
            ->willReturn(['value1', false]);
        $this->redisMock->expects($this->never())->method('get');

        $result = $this->cacheService->getMultiple(['key1', 'key2'], 'default');
        $this->assertSame(['key1' => 'value1', 'key2' => 'default'], $result);
    }

    /**
     * setMultiple with a TTL pipelines N SETEX commands inside one MULTI/PIPELINE
     * so the whole batch hits Redis in a single round-trip. Returns true only
     * when every queued SETEX succeeded.
     */
    public function testSetMultipleWithTtlUsesPipelinedSetex(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('multi')
            ->with(Redis::PIPELINE);
        $setexMatcher = $this->exactly(2);
        $this->redisMock->expects($setexMatcher)
            ->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) use ($setexMatcher) {
                match ($setexMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['key1', 60, 'value1'], [$key, $ttl, $value]),
                    2 => $this->assertSame(['key2', 60, 'value2'], [$key, $ttl, $value]),
                };
                return true;
            });
        $this->redisMock->expects($this->once())
            ->method('exec')
            ->willReturn([true, true]);

        $this->assertTrue(
            $this->cacheService->setMultiple(['key1' => 'value1', 'key2' => 'value2'], 60)
        );
    }

    /**
     * Without a TTL, MSET is the cheapest path (one server-side command, no
     * MULTI/EXEC overhead, no per-key SETEX). No setex() calls allowed.
     */
    public function testSetMultipleWithoutTtlUsesMSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('mSet')
            ->with(['key1' => 'value1', 'key2' => 'value2'])
            ->willReturn(true);
        $this->redisMock->expects($this->never())->method('setex');

        $this->assertTrue(
            $this->cacheService->setMultiple(['key1' => 'value1', 'key2' => 'value2'])
        );
    }

    /**
     * deleteMultiple must run the tag-cleanup pass per key (unindexKey) BEFORE
     * the bulk DEL — otherwise tag sets would be left pointing at deleted keys.
     */
    public function testDeleteMultiple(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $sMembersMatcher = $this->exactly(2);
        $this->redisMock->expects($sMembersMatcher)
            ->method('sMembers')
            ->willReturnCallback(function (string $key) use ($sMembersMatcher) {
                match ($sMembersMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('TAGS:key1', $key),
                    2 => $this->assertSame('TAGS:key2', $key),
                };
                return [];
            });
        $delMatcher = $this->exactly(3);
        $this->redisMock->expects($delMatcher)
            ->method('del')
            ->willReturnCallback(function (string|array $key) use ($delMatcher) {
                match ($delMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('TAGS:key1', $key),
                    2 => $this->assertSame('TAGS:key2', $key),
                    3 => $this->assertSame(['key1', 'key2'], $key),
                };
                return 1;
            });

        $this->assertTrue($this->cacheService->deleteMultiple(['key1', 'key2']));
    }

    /**
     * With pipelineBatchSize=2 and 5 input keys, MGET must fire 3 times
     * (chunks 2/2/1). Bounds request size so a single MGET can't blow past
     * Redis's client-query-buffer-limit on huge inputs.
     */
    public function testGetMultipleChunksMGetByConfiguredBatchSize(): void
    {
        $client = $this->buildClientWithBatchSize(2);

        $mGetMatcher = $this->exactly(3);
        $this->redisMock->expects($mGetMatcher)
            ->method('mGet')
            ->willReturnCallback(function (array $chunk) use ($mGetMatcher) {
                match ($mGetMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['k1', 'k2'], $chunk),
                    2 => $this->assertSame(['k3', 'k4'], $chunk),
                    3 => $this->assertSame(['k5'], $chunk),
                };
                return array_map(fn($k) => 'v_' . $k, $chunk);
            });

        $result = $client->getMultiple(['k1', 'k2', 'k3', 'k4', 'k5'], 'default');

        $this->assertSame(
            ['k1' => 'v_k1', 'k2' => 'v_k2', 'k3' => 'v_k3', 'k4' => 'v_k4', 'k5' => 'v_k5'],
            $result
        );
    }

    /**
     * The SETEX pipeline is chunked too: each MULTI/EXEC contains at most
     * pipelineBatchSize commands, so EXEC's atomic blocking step on the
     * single-threaded server stays bounded.
     */
    public function testSetMultipleWithTtlChunksPipelineByConfiguredBatchSize(): void
    {
        $client = $this->buildClientWithBatchSize(2);

        $this->redisMock->expects($this->exactly(3))
            ->method('multi')
            ->with(Redis::PIPELINE);
        $this->redisMock->expects($this->exactly(5))->method('setex')->willReturn(true);
        $this->redisMock->expects($this->exactly(3))
            ->method('exec')
            ->willReturnOnConsecutiveCalls([true, true], [true, true], [true]);

        $this->assertTrue($client->setMultiple([
            'k1' => 'v1',
            'k2' => 'v2',
            'k3' => 'v3',
            'k4' => 'v4',
            'k5' => 'v5',
        ], 60));
    }

    /**
     * The no-TTL MSET path is also chunked — one MSET per batch — preserving
     * associative key/value pairing within each chunk via array_chunk(...,
     * preserve_keys: true).
     */
    public function testSetMultipleWithoutTtlChunksMSetByConfiguredBatchSize(): void
    {
        $client = $this->buildClientWithBatchSize(2);

        $mSetMatcher = $this->exactly(3);
        $this->redisMock->expects($mSetMatcher)
            ->method('mSet')
            ->willReturnCallback(function (array $chunk) use ($mSetMatcher) {
                match ($mSetMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['k1' => 'v1', 'k2' => 'v2'], $chunk),
                    2 => $this->assertSame(['k3' => 'v3', 'k4' => 'v4'], $chunk),
                    3 => $this->assertSame(['k5' => 'v5'], $chunk),
                };
                return true;
            });

        $this->assertTrue($client->setMultiple([
            'k1' => 'v1',
            'k2' => 'v2',
            'k3' => 'v3',
            'k4' => 'v4',
            'k5' => 'v5',
        ]));
    }

    /**
     * Short-circuit on failure: if any MSET chunk returns false, the call
     * returns false immediately without issuing subsequent chunks (avoids
     * pouring more data into a broken connection).
     */
    public function testSetMultipleWithoutTtlStopsOnFirstFailedChunk(): void
    {
        $client = $this->buildClientWithBatchSize(2);

        $this->redisMock->expects($this->exactly(2))
            ->method('mSet')
            ->willReturnOnConsecutiveCalls(true, false);

        $this->assertFalse($client->setMultiple([
            'k1' => 'v1',
            'k2' => 'v2',
            'k3' => 'v3',
            'k4' => 'v4',
            'k5' => 'v5',
        ]));
    }

    /**
     * The bulk DEL is split into chunks too — a single DEL with 100k+ args
     * could exceed the server's client-query-buffer-limit. Per-key tag
     * cleanup happens before any chunked DEL.
     */
    public function testDeleteMultipleChunksDelByConfiguredBatchSize(): void
    {
        $client = $this->buildClientWithBatchSize(2);

        // unindexKey() walks each key first — return [] so it short-circuits to a single del per key.
        $this->redisMock->method('sMembers')->willReturn([]);

        $delMatcher = $this->exactly(8);
        $this->redisMock->expects($delMatcher)
            ->method('del')
            ->willReturnCallback(function (string|array $arg) use ($delMatcher) {
                // First 5 calls: reverse-lookup cleanup per key (TAGS:kN).
                // Last 3 calls: chunked del of [k1,k2], [k3,k4], [k5].
                match ($delMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('TAGS:k1', $arg),
                    2 => $this->assertSame('TAGS:k2', $arg),
                    3 => $this->assertSame('TAGS:k3', $arg),
                    4 => $this->assertSame('TAGS:k4', $arg),
                    5 => $this->assertSame('TAGS:k5', $arg),
                    6 => $this->assertSame(['k1', 'k2'], $arg),
                    7 => $this->assertSame(['k3', 'k4'], $arg),
                    8 => $this->assertSame(['k5'], $arg),
                };
                return 1;
            });

        $this->assertTrue($client->deleteMultiple(['k1', 'k2', 'k3', 'k4', 'k5']));
    }

    /**
     * clearByTag's two-phase cascade (fetch reverse tags → cleanup writes) is
     * chunked on both phases plus the final bulk DEL, so a tag with 100k+
     * members doesn't produce one giant pipeline that stalls Redis.
     */
    public function testClearByTagChunksPhaseOneAndPhaseTwoByConfiguredBatchSize(): void
    {
        $client = $this->buildClientWithBatchSize(2);

        // Outer sMembers('TAG:t') gives the tag's 3 members. The three inner
        // sMembers calls happen inside the phase-1 pipeline — their direct
        // return value is irrelevant; results come back via exec().
        $this->redisMock->method('sMembers')
            ->willReturnCallback(fn(string $k) => $k === 'TAG:t' ? ['k1', 'k2', 'k3'] : []);

        // Two batches of phase-1 (sizes 2,1) and two of phase-2 → 4 multi/exec pairs.
        $this->redisMock->expects($this->exactly(4))
            ->method('multi')
            ->with(Redis::PIPELINE);
        $this->redisMock->expects($this->exactly(4))
            ->method('exec')
            ->willReturnOnConsecutiveCalls(
                [[], []],  // phase 1 chunk 1: 2 sMembers replies
                [[]],      // phase 1 chunk 2: 1 sMembers reply
                [1, 1],    // phase 2 chunk 1: 2 del replies
                [1],       // phase 2 chunk 2: 1 del reply
            );

        // Phase-2 dels per member (TAGS:k1..k3, inside the pipelines), then
        // chunked bulk del of the members themselves, then the tag set itself.
        $delMatcher = $this->exactly(6);
        $this->redisMock->expects($delMatcher)
            ->method('del')
            ->willReturnCallback(function (string|array $arg) use ($delMatcher) {
                match ($delMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('TAGS:k1', $arg),
                    2 => $this->assertSame('TAGS:k2', $arg),
                    3 => $this->assertSame('TAGS:k3', $arg),
                    4 => $this->assertSame(['k1', 'k2'], $arg),
                    5 => $this->assertSame(['k3'], $arg),
                    6 => $this->assertSame('TAG:t', $arg),
                };
                return 1;
            });

        $this->assertTrue($client->clearByTag('t'));
    }

    /**
     * @param int<1, max> $batchSize
     */
    private function buildClientWithBatchSize(int $batchSize): RapidCacheClient
    {
        $config = new RedisConnectionConfig(host: 'localhost', prefix: 'test:', pipelineBatchSize: $batchSize);
        $client = new RapidCacheClient($config);
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('redis')->setValue($client, $this->redisMock);
        $this->redisMock->method('isConnected')->willReturn(true);
        return $client;
    }

    /**
     * Happy path for getTagged: read members of TAG:*, MGET their values,
     * yield each as key⇒value through the generator. No cleanup pipeline
     * fires when nothing is stale.
     */
    public function testGetTaggedWithExistingItems(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sMembers')
            ->with('TAG:test-tag')
            ->willReturn(['key1', 'key2']);
        $this->redisMock->expects($this->once())
            ->method('mGet')
            ->with(['key1', 'key2'])
            ->willReturn(['value1', 'value2']);

        $items = iterator_to_array($this->cacheService->getTagged('test-tag'));
        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $items);
    }

    /**
     * Self-healing read: if MGET returns false for some tag members (they
     * expired/were deleted), those entries are pruned from the tag set in a
     * deferred pipeline in the `finally`, while still-live members yield
     * normally.
     */
    public function testGetTaggedWithExpiredItems(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sMembers')
            ->with('TAG:test-tag')
            ->willReturn(['key1', 'key2']);
        $this->redisMock->expects($this->once())
            ->method('mGet')
            ->with(['key1', 'key2'])
            ->willReturn(['value1', false]);
        $this->redisMock->expects($this->once())
            ->method('multi')
            ->with(Redis::PIPELINE);
        $sRemMatcher = $this->exactly(2);
        $this->redisMock->expects($sRemMatcher)
            ->method('sRem')
            ->willReturnCallback(function (string $set, string $member) use ($sRemMatcher) {
                match ($sRemMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['TAG:test-tag', 'key2'], [$set, $member]),
                    2 => $this->assertSame(['TAGS:key2', 'test-tag'], [$set, $member]),
                };
                return 1;
            });
        $this->redisMock->expects($this->once())
            ->method('exec')
            ->willReturn([1, 1]);

        $items = iterator_to_array($this->cacheService->getTagged('test-tag'));
        $this->assertSame(['key1' => 'value1'], $items);
    }

    /**
     * Empty tag set returns an empty iterator — no MGET, no cleanup.
     */
    public function testGetTaggedWithNoItems(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sMembers')
            ->with('TAG:test-tag')
            ->willReturn([]);

        $this->assertSame([], iterator_to_array($this->cacheService->getTagged('test-tag')));
    }

    /**
     * tag() on an existing key probes EXISTS first (to avoid ghost
     * associations) then writes both index sides in a single MULTI/PIPELINE.
     * Returns the client for fluent chaining.
     */
    public function testTagExistingKey(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('exists')
            ->with('test-key')
            ->willReturn(1);
        $this->redisMock->expects($this->once())
            ->method('multi')
            ->with(Redis::PIPELINE);
        $sAddMatcher = $this->exactly(2);
        $this->redisMock->expects($sAddMatcher)
            ->method('sAdd')
            ->willReturnCallback(function (string $key, string $value) use ($sAddMatcher) {
                match ($sAddMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['TAG:test-tag', 'test-key'], [$key, $value]),
                    2 => $this->assertSame(['TAGS:test-key', 'test-tag'], [$key, $value]),
                };
                return 1;
            });
        $this->redisMock->expects($this->once())
            ->method('exec')
            ->willReturn([1, 1]);

        $result = $this->cacheService->tag('test-key', 'test-tag');
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

    /**
     * Tagging a missing key would create a ghost tag entry that getTagged()
     * would later have to clean up. tag() refuses upfront with an exception.
     */
    public function testTagNonExistingKey(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('exists')
            ->with('test-key')
            ->willReturn(0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Can\'t tag non-existing key "test-key"');

        $this->cacheService->tag('test-key', 'test-tag');
    }

    /**
     * untag() removes both sides of the association — key from TAG:tag, tag
     * from TAGS:key — but leaves the cache value itself intact (use delete()
     * to remove the value).
     */
    public function testUntag(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $sRemMatcher = $this->exactly(2);
        $this->redisMock->expects($sRemMatcher)
            ->method('sRem')
            ->willReturnCallback(function (string $set, string $member) use ($sRemMatcher) {
                match ($sRemMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['TAG:test-tag', 'test-key'], [$set, $member]),
                    2 => $this->assertSame(['TAGS:test-key', 'test-tag'], [$set, $member]),
                };
                return 1;
            });

        $result = $this->cacheService->untag('test-key', 'test-tag');
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

    /**
     * Full happy-path for the two-phase pipelined cascade:
     *   phase 1 — pipelined sMembers per tagged key to learn their other tags;
     *   phase 2 — pipelined sRem from those other tag sets, del reverse-lookup
     *             sets, del the values, del the tag set itself.
     * For keys that belong to ONLY this tag (no other tags), no cross-tag
     * sRem fires.
     */
    public function testClearByTag(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $sMembersMatcher = $this->exactly(3);
        $this->redisMock->expects($sMembersMatcher)
            ->method('sMembers')
            ->willReturnCallback(function (string $key) use ($sMembersMatcher) {
                $invocation = $sMembersMatcher->numberOfInvocations();
                match ($invocation) {
                    1 => $this->assertSame('TAG:test-tag', $key),
                    2 => $this->assertSame('TAGS:key1', $key),
                    3 => $this->assertSame('TAGS:key2', $key),
                };
                return $invocation === 1 ? ['key1', 'key2'] : false;
            });
        $this->redisMock->expects($this->exactly(2))
            ->method('multi')
            ->with(Redis::PIPELINE);
        $this->redisMock->expects($this->exactly(2))
            ->method('exec')
            ->willReturnOnConsecutiveCalls(
                [['test-tag'], ['test-tag']],
                [1, 1, 1, 1]
            );
        // 'test-tag' is the tag being cleared, so sRem on it is skipped
        // (the whole tag set is deleted at the end).
        $this->redisMock->expects($this->never())->method('sRem');
        $delMatcher = $this->exactly(4);
        $this->redisMock->expects($delMatcher)
            ->method('del')
            ->willReturnCallback(function (string|array $key) use ($delMatcher) {
                match ($delMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('TAGS:key1', $key),
                    2 => $this->assertSame('TAGS:key2', $key),
                    3 => $this->assertSame(['key1', 'key2'], $key),
                    4 => $this->assertSame('TAG:test-tag', $key),
                };
                return 1;
            });

        $this->assertTrue($this->cacheService->clearByTag('test-tag'));
    }

    /**
     * When a cleared key also belongs to other tags, those tags' sets must be
     * scrubbed too — otherwise they'd contain dangling members. The "current
     * tag" itself is skipped (its whole set is del'd at the end anyway).
     */
    public function testClearByTagRemovesKeysFromOtherTagsToo(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $sMembersMatcher = $this->exactly(2);
        $this->redisMock->expects($sMembersMatcher)
            ->method('sMembers')
            ->willReturnCallback(function (string $key) use ($sMembersMatcher) {
                $invocation = $sMembersMatcher->numberOfInvocations();
                match ($invocation) {
                    1 => $this->assertSame('TAG:test-tag', $key),
                    2 => $this->assertSame('TAGS:key1', $key),
                };
                return $invocation === 1 ? ['key1'] : false;
            });
        $this->redisMock->expects($this->exactly(2))
            ->method('multi')
            ->with(Redis::PIPELINE);
        $this->redisMock->expects($this->exactly(2))
            ->method('exec')
            ->willReturnOnConsecutiveCalls(
                [['test-tag', 'other-tag']],
                [1, 1, 1, 1]
            );
        $this->redisMock->expects($this->once())
            ->method('sRem')
            ->with('TAG:other-tag', 'key1');
        $delMatcher = $this->exactly(3);
        $this->redisMock->expects($delMatcher)
            ->method('del')
            ->willReturnCallback(function (string|array $key) use ($delMatcher) {
                match ($delMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('TAGS:key1', $key),
                    2 => $this->assertSame(['key1'], $key),
                    3 => $this->assertSame('TAG:test-tag', $key),
                };
                return 1;
            });

        $this->assertTrue($this->cacheService->clearByTag('test-tag'));
    }

    /**
     * Empty/unknown tag: only del the tag set itself (which is a no-op for
     * non-existent keys). No MULTI/EXEC pipelines fire — idempotent fast path.
     */
    public function testClearByTagEmptyTagSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sMembers')
            ->with('TAG:test-tag')
            ->willReturn([]);
        $this->redisMock->expects($this->never())->method('multi');
        $this->redisMock->expects($this->once())
            ->method('del')
            ->with('TAG:test-tag');

        $this->assertTrue($this->cacheService->clearByTag('test-tag'));
    }

    /**
     * enqueue() pushes to the tail of a Redis list (RPUSH) — pairs with pop()
     * head-removal to form a FIFO. Returns the client for chaining.
     */
    public function testEnqueue(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('rPush')
            ->with('test-queue', 'test-value');

        $result = $this->cacheService->enqueue('test-queue', 'test-value');
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

    /**
     * Null values are forbidden: phpredis can't tell a stored-null from an
     * empty-list LPOP reply, which would silently corrupt the FIFO contract.
     */
    public function testEnqueueThrowsForNullValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Can\'t enqueue null item');

        $this->cacheService->enqueue('test-queue', null);
    }

    /**
     * Default pop (range=1) calls LPOP with no count and returns the value
     * directly — not wrapped in an array.
     */
    public function testPopSingleItem(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lPop')
            ->with('test-queue')
            ->willReturn('test-value');

        $this->assertSame('test-value', $this->cacheService->pop('test-queue'));
    }

    /**
     * Empty queue: phpredis LPOP returns false; pop() normalises that to null
     * so callers can use the conventional null-check.
     */
    public function testPopSingleItemReturnsNullWhenEmpty(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lPop')
            ->with('test-queue')
            ->willReturn(false);

        $this->assertNull($this->cacheService->pop('test-queue'));
    }

    /**
     * range > 1 calls LPOP with an explicit count and returns an array of up
     * to N popped items (whatever was available).
     */
    public function testPopMultipleItems(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lPop')
            ->with('test-queue', 3)
            ->willReturn(['item1', 'item2', 'item3']);

        $this->assertSame(['item1', 'item2', 'item3'], $this->cacheService->pop('test-queue', 3));
    }

    /**
     * peek() shows the head WITHOUT removing it — implemented via LRANGE(0, 0)
     * and unwraps the single-element array to a scalar.
     */
    public function testPeekSingleItem(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lRange')
            ->with('test-queue', 0, 0)
            ->willReturn(['item1']);

        $this->assertSame('item1', $this->cacheService->peek('test-queue'));
    }

    /**
     * Empty queue: LRANGE returns []; peek normalises to null (consistent
     * with pop()'s empty-queue contract).
     */
    public function testPeekSingleItemReturnsNullWhenEmpty(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lRange')
            ->with('empty-queue', 0, 0)
            ->willReturn([]);

        $this->assertNull($this->cacheService->peek('empty-queue'));
    }

    /**
     * range < 1 is meaningless — fail fast with InvalidArgumentException
     * before any Redis call.
     */
    public function testPopRejectsNonPositiveRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cacheService->pop('test-queue', 0);
    }

    /**
     * Symmetric to pop's range guard — peek also rejects range < 1.
     */
    public function testPeekRejectsNonPositiveRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cacheService->peek('test-queue', -1);
    }

    /**
     * range > 1 uses LRANGE(0, range-1) and returns the head window as an
     * array, leaving the queue unchanged.
     */
    public function testPeekMultipleItems(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lRange')
            ->with('test-queue', 0, 2)
            ->willReturn(['item1', 'item2', 'item3']);

        $this->assertSame(['item1', 'item2', 'item3'], $this->cacheService->peek('test-queue', 3));
    }

    /**
     * increase() delegates to Redis INCRBY (atomic, server-side); the new
     * value is not returned because the method is for fluent chaining.
     */
    public function testIncrease(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('incrBy')
            ->with('test-key', 5);

        $result = $this->cacheService->increase('test-key', 5);
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

    /**
     * Mirror of increase() — delegates to DECRBY with the exact value.
     */
    public function testDecrease(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('decrBy')
            ->with('test-key', 3);

        $result = $this->cacheService->decrease('test-key', 3);
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

    /**
     * SCARD on the tag's underlying TAG:* set — O(1) count, no member fetch.
     * Note: count may include ghost entries until getTagged() prunes them.
     */
    public function testGetTagCardinality(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sCard')
            ->with('TAG:test-tag')
            ->willReturn(5);

        $this->assertSame(5, $this->cacheService->getTagCardinality('test-tag'));
    }

    /**
     * Regular sets use SCARD. The default `sortedSet=false` parameter selects
     * this branch.
     */
    public function testGetCardinalityForRegularSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sCard')
            ->with('test-set')
            ->willReturn(3);

        $this->assertSame(3, $this->cacheService->getCardinality('test-set'));
    }

    /**
     * `sortedSet=true` switches to ZCARD. Picking the wrong flag for the
     * key's actual type yields WRONGTYPE wrapped as CacheException.
     */
    public function testGetCardinalityForSortedSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('zCard')
            ->with('test-sorted-set')
            ->willReturn(7);

        $this->assertSame(7, $this->cacheService->getCardinality('test-sorted-set', true));
    }

    /**
     * SADD adds a single member; sets dedupe automatically so adding an
     * existing member is a server-side no-op. Returns the client for chaining.
     */
    public function testAddToSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sAdd')
            ->with('test-set', 'test-value');

        $result = $this->cacheService->addToSet('test-set', 'test-value');
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

    /**
     * SREM removes a single member; idempotent (removing a non-member is a
     * no-op). Redis auto-deletes the set once it's empty.
     */
    public function testRemoveFromSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sRem')
            ->with('test-set', 'test-value');

        $result = $this->cacheService->removeFromSet('test-set', 'test-value');
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

    /**
     * SMEMBERS returns every member of the set. Order is undefined (sets
     * are unordered) — getSet just passes through whatever phpredis gives back.
     */
    public function testGetSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sMembers')
            ->with('test-set')
            ->willReturn(['value1', 'value2', 'value3']);

        $this->assertSame(
            ['value1', 'value2', 'value3'],
            $this->cacheService->getSet('test-set')
        );
    }

    /**
     * Missing set: phpredis SMEMBERS returns false; getSet maps that to null
     * (distinct from "empty set" → []).
     */
    public function testGetSetReturnsNullWhenNotExists(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sMembers')
            ->with('test-set')
            ->willReturn(false);

        $this->assertNull($this->cacheService->getSet('test-set'));
    }

    /**
     * createSet replaces the entire set: DEL the old key first, then SADDARRAY
     * the new values in one call. Use this for clean swaps; for incremental
     * mutations prefer addToSet/removeFromSet.
     */
    public function testCreateSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('del')
            ->with('test-set');
        $this->redisMock->expects($this->once())
            ->method('sAddArray')
            ->with('test-set', ['value1', 'value2', 'value3']);

        $result = $this->cacheService->createSet('test-set', ['value1', 'value2', 'value3']);
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

    /**
     * Passing []  is the documented "delete this set" form — DEL only, no
     * SADDARRAY (which would WRONGTYPE-error on an empty array anyway).
     */
    public function testCreateSetWithEmptyArrayDeletesWithoutSAdd(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('del')
            ->with('test-set');
        $this->redisMock->expects($this->never())->method('sAddArray');

        $result = $this->cacheService->createSet('test-set', []);
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

    /**
     * Queue names are validated as PSR-16 keys — reserved chars rejected.
     */
    public function testEnqueueRejectsInvalidQueueName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cacheService->enqueue('bad:queue', 'value');
    }

    /**
     * Tag names are validated as PSR-16 keys too — they get prefixed and
     * stored as actual Redis keys, so reserved chars would corrupt the index.
     */
    public function testTagRejectsInvalidTagName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cacheService->tag('key', 'bad:tag');
    }

    /**
     * Set keys are validated like any other PSR-16 key.
     */
    public function testAddToSetRejectsInvalidKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cacheService->addToSet('bad@set', 'value');
    }

    /**
     * Treats a sorted set as an ordered index of cache keys: ZRANGE picks the
     * window of members (lowest score first), MGET fetches their cached values,
     * and the generator yields member⇒value pairs.
     */
    public function testGetSortedWithNormalOrder(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('zRange')
            ->with('test-sorted-set', 0, 1)
            ->willReturn(['key1', 'key2']);
        $this->redisMock->expects($this->once())
            ->method('mGet')
            ->with(['key1', 'key2'])
            ->willReturn(['value1', 'value2']);

        $items = iterator_to_array($this->cacheService->getSorted('test-sorted-set', 2));
        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $items);
    }

    /**
     * `reversed=true` uses ZREVRANGE (highest score first) instead of ZRANGE.
     */
    public function testGetSortedWithReversedOrder(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('zRevRange')
            ->with('test-sorted-set', 0, 1)
            ->willReturn(['key2', 'key1']);
        $this->redisMock->expects($this->once())
            ->method('mGet')
            ->with(['key2', 'key1'])
            ->willReturn(['value2', 'value1']);

        $items = iterator_to_array($this->cacheService->getSorted('test-sorted-set', 2, 0, true));
        $this->assertSame(['key2' => 'value2', 'key1' => 'value1'], $items);
    }

    /**
     * Empty/missing sorted set → empty iterator, no MGET issued.
     */
    public function testGetSortedWithEmptySet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('zRange')
            ->with('test-sorted-set', 0, 1)
            ->willReturn([]);
        $this->redisMock->expects($this->never())->method('mGet');

        $items = iterator_to_array($this->cacheService->getSorted('test-sorted-set', 2));
        $this->assertSame([], $items);
    }

    /**
     * Self-healing read: if MGET returns false for a member AND EXISTS
     * confirms the underlying key is gone, the member is ZREM'd from the
     * sorted set and delete() runs on the (already-missing) key as cleanup.
     */
    public function testGetSortedRemovesDanglingMembers(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('zRange')
            ->with('test-sorted-set', 0, 1)
            ->willReturn(['stale-key', 'live-key']);
        $this->redisMock->expects($this->once())
            ->method('mGet')
            ->with(['stale-key', 'live-key'])
            ->willReturn([false, 'live-value']);
        $this->redisMock->expects($this->once())
            ->method('exists')
            ->with('stale-key')
            ->willReturn(0);
        $this->redisMock->expects($this->once())
            ->method('zRem')
            ->with('test-sorted-set', 'stale-key');
        $this->redisMock->expects($this->once())
            ->method('sMembers')
            ->with('TAGS:stale-key')
            ->willReturn([]);
        $delMatcher = $this->exactly(2);
        $this->redisMock->expects($delMatcher)
            ->method('del')
            ->willReturnCallback(function (string $key) use ($delMatcher) {
                match ($delMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('TAGS:stale-key', $key),
                    2 => $this->assertSame('stale-key', $key),
                };
                return 1;
            });

        $items = iterator_to_array($this->cacheService->getSorted('test-sorted-set', 2));
        $this->assertSame(['live-key' => 'live-value'], $items);
    }

    /**
     * Stored `null` (igbinary-serialised) is a legitimate value, NOT a miss;
     * MGET returns it as null (not false), so yielding-and-continuing is the
     * right call — no pruning, no skipping.
     */
    public function testGetSortedPreservesNullValuedMembers(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('zRange')
            ->with('test-sorted-set', 0, 0)
            ->willReturn(['null-key']);
        $this->redisMock->expects($this->once())
            ->method('mGet')
            ->with(['null-key'])
            ->willReturn([null]);
        $this->redisMock->expects($this->never())->method('exists');
        $this->redisMock->expects($this->never())->method('zRem');

        $items = iterator_to_array($this->cacheService->getSorted('test-sorted-set', 1));
        $this->assertSame(['null-key' => null], $items);
    }

    /**
     * When MGET returns false, an EXISTS probe disambiguates: if the key DOES
     * exist, the false is a real stored value — yield it intact and don't prune.
     */
    public function testGetSortedYieldsStoredFalseForExistingMember(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('zRange')
            ->with('test-sorted-set', 0, 0)
            ->willReturn(['flag-key']);
        $this->redisMock->expects($this->once())
            ->method('mGet')
            ->with(['flag-key'])
            ->willReturn([false]);
        $this->redisMock->expects($this->once())
            ->method('exists')
            ->with('flag-key')
            ->willReturn(1);
        $this->redisMock->expects($this->never())->method('zRem');

        $items = iterator_to_array($this->cacheService->getSorted('test-sorted-set', 1));
        $this->assertSame(['flag-key' => false], $items);
    }

    /**
     * getQueue returns the full queue head-first via LRANGE(0, -1). O(N) —
     * prefer peek() with a bounded range for large queues.
     */
    public function testGetQueue(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->never())->method('rPopLPush');
        $this->redisMock->expects($this->never())->method('lLen');
        $this->redisMock->expects($this->once())
            ->method('lRange')
            ->with('test-queue', 0, -1)
            ->willReturn(['item1', 'item2', 'item3']);

        $this->assertSame(['item1', 'item2', 'item3'], $this->cacheService->getQueue('test-queue'));
    }

    /**
     * O(1) LLEN — does not enumerate elements.
     */
    public function testGetQueueLength(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lLen')
            ->with('test-queue')
            ->willReturn(5);

        $this->assertSame(5, $this->cacheService->getQueueLength('test-queue'));
    }

    /**
     * Missing queue: phpredis LRANGE returns false; getQueue normalises to []
     * so callers can safely foreach without a null check.
     */
    public function testGetQueueWithEmptyQueue(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lRange')
            ->with('empty-queue', 0, -1)
            ->willReturn([]);

        $this->assertSame([], $this->cacheService->getQueue('empty-queue'));
    }

    /**
     * Our package's InvalidArgumentException must implement PSR-16's
     * interface so callers can catch on the PSR contract alone — without
     * needing to know about the IDCT namespace.
     */
    public function testInvalidArgumentExceptionIsPsrCompliant(): void
    {
        $exception = new InvalidArgumentException('boom');
        $this->assertInstanceOf(PsrInvalidArgumentException::class, $exception);
        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
    }

    /**
     * A RedisException during connect() must surface as the package's
     * CacheException (which is PSR-16's CacheException) — not as the raw
     * phpredis exception.
     */
    public function testReconnectWrapsRedisExceptionAsCacheException(): void
    {
        $failingRedis = $this->createMock(Redis::class);
        $failingRedis->method('connect')
            ->willThrowException(new \RedisException('boom'));

        $client = $this->clientWithInjectedRedis($failingRedis);

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $this->expectException(\Psr\SimpleCache\CacheException::class);
        $client->get('any-key');
    }

    /**
     * Any Throwable from setup (auth, select, setOption) — not just
     * RedisException — must also be wrapped as CacheException, since
     * phpredis can emit plain \Exception/RuntimeException from those.
     */
    public function testReconnectWrapsArbitraryThrowable(): void
    {
        $failingRedis = $this->createMock(Redis::class);
        $failingRedis->method('connect')
            ->willThrowException(new \RuntimeException('dns blew up'));

        $client = $this->clientWithInjectedRedis($failingRedis);

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $client->get('any-key');
    }

    /**
     * The wrap() helper translates phpredis storage errors into
     * CacheException as PSR-16 requires. Verified here via get().
     */
    public function testGetWrapsRedisExceptionAsCacheException(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('get')
            ->willThrowException(new \RedisException('connection lost'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $this->expectException(\Psr\SimpleCache\CacheException::class);
        $this->cacheService->get('test-key');
    }

    /**
     * Same wrap() contract on the write side: set() must also translate
     * RedisException to CacheException.
     */
    public function testSetWrapsRedisExceptionAsCacheException(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('set')
            ->willThrowException(new \RedisException('write failure'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $this->cacheService->set('test-key', 'value');
    }

    /**
     * Beyond PSR-16 — verifies the wrap() translation also covers the
     * queue API surface (enqueue).
     */
    public function testEnqueueWrapsRedisExceptionAsCacheException(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('rPush')
            ->willThrowException(new \RedisException('connection lost'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $this->cacheService->enqueue('test-queue', 'value');
    }

    /**
     * getTagged is a generator — exceptions inside the body must still
     * be translated to CacheException once consumers iterate. The
     * deferred-flush cleanup in `finally` runs anyway, swallowing its
     * own errors so they don't shadow the primary cause.
     */
    public function testGetTaggedWrapsRedisExceptionFromGenerator(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')
            ->willThrowException(new \RedisException('boom'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        iterator_to_array($this->cacheService->getTagged('test-tag'));
    }

    /**
     * Full positive case for reconnect(): a fully-populated
     * RedisConnectionConfig (auth + database + prefix + readTimeout +
     * persistent) must result in matching pconnect/auth/select/setOption
     * calls, in the right order, with the right values.
     */
    public function testReconnectAppliesAllConfigFields(): void
    {
        $redis = $this->createMock(Redis::class);
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
        $redis->expects($this->once())
            ->method('auth')
            ->with('secret');
        $redis->expects($this->once())
            ->method('select')
            ->with(3);
        $setOptionMatcher = $this->exactly(3);
        $redis->expects($setOptionMatcher)
            ->method('setOption')
            ->willReturnCallback(function (int $option, mixed $value) use ($setOptionMatcher) {
                match ($setOptionMatcher->numberOfInvocations()) {
                    1 => $this->assertSame([Redis::OPT_READ_TIMEOUT, '1.5'], [$option, $value]),
                    2 => $this->assertSame([Redis::OPT_PREFIX, 'app:'], [$option, $value]),
                    3 => $this->assertSame([Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY], [$option, $value]),
                };
                return true;
            });

        $client = new class ($config, $redis) extends RapidCacheClient {
            public function __construct(RedisConnectionConfig $config, private Redis $injected)
            {
                parent::__construct($config);
            }

            protected function createRedisInstance(): Redis
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

    /**
     * After a RedisException, wrap() must null out the cached connection so
     * the next operation triggers a fresh reconnect. A transient blip
     * shouldn't poison the client for the rest of the request.
     */
    public function testWrapResetsConnectionOnRedisException(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('get')
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
            'wrap() should clear $this->redis after a RedisException so the next call reconnects'
        );
    }

    /**
     * With retryOnce=true, a single transient RedisException triggers exactly
     * one reconnect-and-retry. The retried call returns the recovered value
     * and the caller never sees the exception.
     */
    public function testRetryOnceRecoversFromTransientError(): void
    {
        $config = new RedisConnectionConfig(host: 'h', retryOnce: true);
        $callCount = 0;
        $client = new class ($config, $this->redisMock) extends RapidCacheClient {
            public function __construct(RedisConnectionConfig $config, private Redis $injected)
            {
                parent::__construct($config);
            }
            protected function createRedisInstance(): Redis
            {
                return $this->injected;
            }
        };

        $this->redisMock->method('isConnected')->willReturn(false, true);
        $this->redisMock->method('connect')->willReturn(true);
        $this->redisMock->method('get')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new \RedisException('transient');
            }
            return 'recovered-value';
        });

        $this->assertSame('recovered-value', $client->get('test-key'));
        $this->assertSame(2, $callCount);
    }

    /**
     * If the retry ALSO throws (true outage, not a blip), the second
     * exception is wrapped as CacheException and re-raised — retryOnce
     * doesn't loop forever or swallow real failures.
     */
    public function testRetryOnceDoesNotMaskPermanentError(): void
    {
        $config = new RedisConnectionConfig(host: 'h', retryOnce: true);
        $client = new class ($config, $this->redisMock) extends RapidCacheClient {
            public function __construct(RedisConnectionConfig $config, private Redis $injected)
            {
                parent::__construct($config);
            }
            protected function createRedisInstance(): Redis
            {
                return $this->injected;
            }
        };

        $this->redisMock->method('isConnected')->willReturn(false, true);
        $this->redisMock->method('connect')->willReturn(true);
        $this->redisMock->method('get')
            ->willThrowException(new \RedisException('permanent'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $client->get('test-key');
    }

    /**
     * \Error (engine-level: missing class, type errors) is a coding bug, not a
     * storage failure — must propagate unchanged so it isn't masked behind a
     * misleading "couldn't connect to Redis" CacheException.
     */
    public function testReconnectLetsErrorsPropagate(): void
    {
        $failingRedis = $this->createMock(Redis::class);
        $failingRedis->method('connect')
            ->willThrowException(new \TypeError('coding bug'));

        $client = $this->clientWithInjectedRedis($failingRedis);

        $this->expectException(\TypeError::class);
        $client->get('any-key');
    }

    // -------------------------------------------------------------------
    // Hardening: invalid-key rejection on every public method.
    // -------------------------------------------------------------------

    /**
     * Provider for {@see testRejectsInvalidKeyAcrossEveryApi}.
     *
     * One closure per public API method that takes a PSR-16 key/tag/queue/set
     * name. Each closure exercises the method with a key containing a reserved
     * character (`:`), so validateKey() must throw before any Redis call.
     *
     * Catches the easy-to-forget case where a new method is added but its
     * `$this->validateKey(...)` line is missing or accidentally deleted.
     *
     * @return iterable<string, array{callable(RapidCacheClient): mixed}>
     */
    public static function invalidKeyOperationProvider(): iterable
    {
        $badKey = 'bad:key';
        return [
            'has' => [fn(RapidCacheClient $c) => $c->has($badKey)],
            'getMultiple invalid key' => [fn(RapidCacheClient $c) => iterator_to_array($c->getMultiple([$badKey]))],
            'setMultiple invalid key' => [fn(RapidCacheClient $c) => $c->setMultiple([$badKey => 'v'])],
            'deleteMultiple invalid key' => [fn(RapidCacheClient $c) => $c->deleteMultiple([$badKey])],
            'setTagged invalid key' => [fn(RapidCacheClient $c) => $c->setTagged($badKey, 'v', 'goodtag')],
            'setTagged invalid tag' => [fn(RapidCacheClient $c) => $c->setTagged('goodkey', 'v', $badKey)],
            'getTagged invalid tag' => [fn(RapidCacheClient $c) => iterator_to_array($c->getTagged($badKey))],
            'tag invalid key' => [fn(RapidCacheClient $c) => $c->tag($badKey, 'goodtag')],
            'tag invalid tag' => [fn(RapidCacheClient $c) => $c->tag('goodkey', $badKey)],
            'untag invalid key' => [fn(RapidCacheClient $c) => $c->untag($badKey, 'goodtag')],
            'untag invalid tag' => [fn(RapidCacheClient $c) => $c->untag('goodkey', $badKey)],
            'clearByTag invalid tag' => [fn(RapidCacheClient $c) => $c->clearByTag($badKey)],
            'getTagCardinality invalid tag' => [fn(RapidCacheClient $c) => $c->getTagCardinality($badKey)],
            'getCardinality invalid set' => [fn(RapidCacheClient $c) => $c->getCardinality($badKey)],
            'enqueue invalid queue' => [fn(RapidCacheClient $c) => $c->enqueue($badKey, 'v')],
            'pop invalid queue' => [fn(RapidCacheClient $c) => $c->pop($badKey)],
            'peek invalid queue' => [fn(RapidCacheClient $c) => $c->peek($badKey)],
            'getQueue invalid queue' => [fn(RapidCacheClient $c) => $c->getQueue($badKey)],
            'getQueueLength invalid queue' => [fn(RapidCacheClient $c) => $c->getQueueLength($badKey)],
            'increase invalid key' => [fn(RapidCacheClient $c) => $c->increase($badKey, 1)],
            'decrease invalid key' => [fn(RapidCacheClient $c) => $c->decrease($badKey, 1)],
            'getSorted invalid key' => [fn(RapidCacheClient $c) => iterator_to_array($c->getSorted($badKey, 10))],
            'addToSet invalid key' => [fn(RapidCacheClient $c) => $c->addToSet($badKey, 'v')],
            'removeFromSet invalid key' => [fn(RapidCacheClient $c) => $c->removeFromSet($badKey, 'v')],
            'getSet invalid key' => [fn(RapidCacheClient $c) => $c->getSet($badKey)],
            'createSet invalid key' => [fn(RapidCacheClient $c) => $c->createSet($badKey, [])],
        ];
    }

    /**
     * Uniform contract: every public API rejects PSR-16-invalid keys with
     * a PsrInvalidArgumentException. Runs once per entry in the provider.
     *
     * @param callable(RapidCacheClient): mixed $op
     */
    #[DataProvider('invalidKeyOperationProvider')]
    public function testRejectsInvalidKeyAcrossEveryApi(callable $op): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $op($this->cacheService);
    }

    // -------------------------------------------------------------------
    // Hardening: reconnect skips optional config branches when not set.
    // -------------------------------------------------------------------

    /**
     * password=null → no AUTH call. Auth must be opt-in to avoid surprising
     * "WRONGPASS" errors when connecting to an unauthed Redis.
     */
    public function testReconnectWithoutPasswordSkipsAuth(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->never())->method('auth');

        $config = new RedisConnectionConfig(host: 'h', password: null);
        $this->forceReconnect($config, $redis);
    }

    /**
     * Empty-string password is treated as "no password" — same as null. The
     * `!== null && !== ''` check covers both forms of "unset".
     */
    public function testReconnectWithEmptyPasswordSkipsAuth(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->never())->method('auth');

        $config = new RedisConnectionConfig(host: 'h', password: '');
        $this->forceReconnect($config, $redis);
    }

    /**
     * database=0 means "use the default DB", which is also phpredis's default
     * after connect — so SELECT is skipped to save a round-trip.
     */
    public function testReconnectWithDatabaseZeroSkipsSelect(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->never())->method('select');

        $config = new RedisConnectionConfig(host: 'h', database: 0);
        $this->forceReconnect($config, $redis);
    }

    /**
     * No prefix → no OPT_PREFIX setOption (igbinary serializer still fires).
     * Setting an empty prefix would silently rewrite raw keys.
     */
    public function testReconnectWithoutPrefixSkipsPrefixOption(): void
    {
        $redis = $this->createMock(Redis::class);
        $optionCalls = [];
        $redis->method('setOption')->willReturnCallback(
            function (int $option, mixed $value) use (&$optionCalls): bool {
                $optionCalls[] = $option;
                return true;
            }
        );

        $config = new RedisConnectionConfig(host: 'h', prefix: null);
        $this->forceReconnect($config, $redis);

        $this->assertNotContains(
            Redis::OPT_PREFIX,
            $optionCalls,
            'OPT_PREFIX must not be set when no prefix is configured.'
        );
    }

    /**
     * readTimeout=0 → OPT_READ_TIMEOUT skipped (phpredis treats 0 as "wait
     * forever" — equivalent to not setting it at all).
     */
    public function testReconnectWithZeroReadTimeoutSkipsReadTimeoutOption(): void
    {
        $redis = $this->createMock(Redis::class);
        $setOptionCalls = [];
        $redis->method('setOption')->willReturnCallback(
            function (int $option, mixed $value) use (&$setOptionCalls): bool {
                $setOptionCalls[] = $option;
                return true;
            }
        );

        $config = new RedisConnectionConfig(host: 'h', readTimeout: 0.0);
        $this->forceReconnect($config, $redis);

        $this->assertNotContains(
            Redis::OPT_READ_TIMEOUT,
            $setOptionCalls,
            'OPT_READ_TIMEOUT must not be set when readTimeout is 0.'
        );
    }

    /**
     * Default is `persistent=false` → connect() (non-persistent). pconnect()
     * must NOT fire unless explicitly opted in.
     */
    public function testReconnectUsesNonPersistentConnectByDefault(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->with('h', 6379, 1.0);
        $redis->expects($this->never())->method('pconnect');

        $config = new RedisConnectionConfig(host: 'h');
        $this->forceReconnect($config, $redis);
    }

    private function forceReconnect(RedisConnectionConfig $config, Redis $injected): void
    {
        $client = new class ($config, $injected) extends RapidCacheClient {
            public function __construct(RedisConnectionConfig $config, private Redis $injected)
            {
                parent::__construct($config);
            }
            protected function createRedisInstance(): Redis
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

    // -------------------------------------------------------------------
    // Hardening: legacy constructor & default-port handling.
    // -------------------------------------------------------------------

    /**
     * Legacy 3-arg constructor accepts `?int $port` — passing null must
     * resolve to DEFAULT_REDIS_PORT (6379) via the `?? DEFAULT_REDIS_PORT`.
     */
    public function testLegacyConstructorAppliesDefaultPortWhenNullGiven(): void
    {
        $client = new RapidCacheClient('h', null, null);
        $reflection = new \ReflectionClass($client);
        $config = $reflection->getProperty('config')->getValue($client);
        $this->assertInstanceOf(RedisConnectionConfig::class, $config);
        $this->assertSame(RapidCacheClient::DEFAULT_REDIS_PORT, $config->port);
    }

    /**
     * Explicit port must NOT be replaced by DEFAULT_REDIS_PORT — a `7777` in
     * means a `7777` out. Pins the operand order of `$port ?? DEFAULT` so a
     * swapped `DEFAULT ?? $port` mutation gets killed.
     */
    public function testLegacyConstructorPreservesExplicitPort(): void
    {
        $client = new RapidCacheClient('h', 7777, null);
        $reflection = new \ReflectionClass($client);
        $config = $reflection->getProperty('config')->getValue($client);
        $this->assertInstanceOf(RedisConnectionConfig::class, $config);
        $this->assertSame(7777, $config->port);
    }

    // -------------------------------------------------------------------
    // Hardening: clear() multi-batch SCAN loop semantics.
    // -------------------------------------------------------------------

    /**
     * Multi-batch SCAN: iterator stays non-zero across iterations until the
     * cursor wraps to 0. Asserts:
     *  - SCAN fires exactly the right number of times (not one too few/many),
     *  - the loop uses the documented batch size of 1000,
     *  - each batch UNLINKs the keys it actually got back.
     */
    public function testClearWithPrefixIteratesMultipleScanBatches(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('getOption')
            ->with(Redis::OPT_SCAN)
            ->willReturn(Redis::SCAN_NORETRY);
        $this->redisMock->method('setOption')->willReturn(true);

        $scanMatcher = $this->exactly(2);
        $this->redisMock->expects($scanMatcher)
            ->method('scan')
            ->willReturnCallback(
                function (&$iterator, string $pattern, int $count) use ($scanMatcher) {
                    $this->assertSame('test:*', $pattern);
                    $this->assertSame(1000, $count);
                    switch ($scanMatcher->numberOfInvocations()) {
                        case 1:
                            $this->assertNull($iterator);
                            $iterator = 5;
                            return ['test:a', 'test:b'];
                        case 2:
                            $this->assertSame(5, $iterator);
                            $iterator = 0;
                            return ['test:c'];
                        default:
                            $this->fail('scan called more than expected — loop should exit when iterator hits 0');
                    }
                }
            );
        $unlinkMatcher = $this->exactly(2);
        $this->redisMock->expects($unlinkMatcher)
            ->method('unlink')
            ->willReturnCallback(function (array $keys) use ($unlinkMatcher) {
                match ($unlinkMatcher->numberOfInvocations()) {
                    1 => $this->assertSame(['test:a', 'test:b'], $keys),
                    2 => $this->assertSame(['test:c'], $keys),
                };
                return 1;
            });

        $this->assertTrue($this->cacheService->clear());
    }

    /**
     * When SCAN returns an empty list of keys for a batch, UNLINK must not
     * fire — phpredis UNLINK on `[]` is at best a wasted round-trip, at
     * worst an error depending on driver version.
     */
    public function testClearSkipsUnlinkOnEmptyBatch(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('getOption')->willReturn(Redis::SCAN_NORETRY);
        $this->redisMock->method('setOption')->willReturn(true);
        $this->redisMock->method('scan')->willReturnCallback(
            function (&$iterator) {
                $iterator = 0;
                return [];
            }
        );
        $this->redisMock->expects($this->never())->method('unlink');

        $this->assertTrue($this->cacheService->clear());
    }

    /**
     * Pins the `finally` block in clear(): even when SCAN throws mid-loop,
     * the original OPT_PREFIX and OPT_SCAN values must be restored before
     * the exception propagates. Otherwise the client would be left with
     * prefix='', silently operating on raw global keys.
     */
    public function testClearRestoresPrefixAndScanOptionsEvenWhenScanThrows(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('getOption')
            ->with(Redis::OPT_SCAN)
            ->willReturn(Redis::SCAN_NORETRY);
        $setOptionCalls = [];
        $this->redisMock->method('setOption')->willReturnCallback(
            function (int $option, mixed $value) use (&$setOptionCalls): bool {
                $setOptionCalls[] = [$option, $value];
                return true;
            }
        );
        $this->redisMock->method('scan')
            ->willThrowException(new \RedisException('scan failed'));

        try {
            $this->cacheService->clear();
            $this->fail('Expected CacheException');
        } catch (\IDCT\Cache\Exception\CacheException) {
            // expected
        }

        $this->assertContains(
            [Redis::OPT_PREFIX, 'test:'],
            $setOptionCalls,
            'finally must restore the user-defined prefix even when SCAN throws'
        );
        $this->assertContains(
            [Redis::OPT_SCAN, Redis::SCAN_NORETRY],
            $setOptionCalls,
            'finally must restore the prior SCAN option even when SCAN throws'
        );
    }

    // -------------------------------------------------------------------
    // Hardening: TTL=0 / negative TTL deletion path for tagged + multiple.
    // -------------------------------------------------------------------

    /**
     * setTagged with TTL=0 short-circuits to set()'s delete branch — tagging
     * a key you're about to delete makes no sense. So: no SETEX, no SADD,
     * no MULTI; just unindexKey + del.
     */
    public function testSetTaggedWithZeroTtlShortCircuitsToDeleteAndSkipsTagging(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')
            ->with('TAGS:test-key')
            ->willReturn([]);
        // unindex finds no reverse-tag set → del that reverse-set key.
        $delMatcher = $this->exactly(2);
        $this->redisMock->expects($delMatcher)
            ->method('del')
            ->willReturnCallback(function (string|array $arg) use ($delMatcher) {
                match ($delMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('TAGS:test-key', $arg),
                    2 => $this->assertSame('test-key', $arg),
                };
                return 1;
            });
        // Tagging operations MUST NOT happen on TTL=0.
        $this->redisMock->expects($this->never())->method('sAdd');
        $this->redisMock->expects($this->never())->method('setex');
        $this->redisMock->expects($this->never())->method('multi');

        $this->assertTrue($this->cacheService->setTagged('test-key', 'value', 'tag', 0));
    }

    /**
     * Backslash is reserved by PSR-16. The implementation uses
     * `preg_quote(RESERVED_KEY_CHARS, '#')` so the `\` actually appears as a
     * literal in the character class — removing preg_quote would silently
     * accept backslashes. This test pins that defence.
     */
    public function testValidateKeyRejectsBackslashCharacter(): void
    {
        // Backslash is reserved by PSR-16; only `preg_quote` makes `\\` matchable
        // inside the character class. Removing `preg_quote` would silently allow it.
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->get('bad\\key');
    }

    /**
     * `{` is one of the PSR-16 reserved chars — keep an explicit test so the
     * RESERVED_KEY_CHARS constant can't be silently shortened.
     */
    public function testValidateKeyRejectsBraceCharacter(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->get('bad{key');
    }

    /**
     * Same reasoning for `(` — explicit per-char tests so deletions from
     * RESERVED_KEY_CHARS get caught.
     */
    public function testValidateKeyRejectsParenCharacter(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->get('bad(key');
    }

    /**
     * Distinguishes keys from values so a mutation like
     * `foreach (array_keys($normalized) as $key)` → `foreach ($normalized
     * as $key)` (which would iterate VALUES) gets caught — unindexKey would
     * lookup `TAGS:<value>` instead of `TAGS:<key>`.
     */
    public function testSetMultipleWithZeroTtlUnindexesByOriginalKeysNotValues(): void
    {
        // Distinguish keys from values so a mutation like
        // `foreach (array_keys($normalized) as $key)` → `foreach ($normalized as $key)`
        // (which would iterate VALUES) gets caught.
        $this->redisMock->method('isConnected')->willReturn(true);
        $sMembersCalls = [];
        $this->redisMock->method('sMembers')->willReturnCallback(
            function (string $key) use (&$sMembersCalls): array {
                $sMembersCalls[] = $key;
                return [];
            }
        );
        $this->redisMock->method('del')->willReturn(1);

        $this->cacheService->setMultiple(['key_one' => 'val_one', 'key_two' => 'val_two'], 0);

        // unindexKey() looks up `TAGS:<the key>` — never `TAGS:<the value>`.
        $this->assertContains('TAGS:key_one', $sMembersCalls);
        $this->assertContains('TAGS:key_two', $sMembersCalls);
        $this->assertNotContains('TAGS:val_one', $sMembersCalls);
        $this->assertNotContains('TAGS:val_two', $sMembersCalls);
    }

    /**
     * The `!is_array($results) || in_array(false, $results, true)` guard:
     * exec() returning false (the non-array failure case) must produce a
     * `false` return. Pins the `||` so a mutation to `&&` (where neither
     * arm by itself is enough) gets killed.
     */
    public function testSetMultipleReturnsFalseWhenExecReturnsNonArray(): void
    {
        // The `!is_array($results) || in_array(false, $results, true)` guard:
        // mutating `||` to `&&` makes the first arm useless. Force exec()->false to expose it.
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('setex')->willReturnSelf();
        $this->redisMock->method('exec')->willReturn(false);

        $this->assertFalse(
            $this->cacheService->setMultiple(['k1' => 'v1'], 60),
            'setMultiple must report failure when exec() does not return an array.'
        );
    }

    /**
     * Companion to the TTL=0 test: negative TTLs (e.g. a DateInterval
     * resolving to the past) also short-circuit to delete. Pins the `<= 0`
     * boundary against being mutated to `< 0`.
     */
    public function testSetTaggedWithNegativeTtlShortCircuitsToDelete(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturn([]);
        $this->redisMock->expects($this->exactly(2))->method('del')->willReturn(1);
        $this->redisMock->expects($this->never())->method('sAdd');

        $this->assertTrue($this->cacheService->setTagged('test-key', 'value', 'tag', -10));
    }

    /**
     * setMultiple with TTL=0 must run the tag-cleanup unindex and the chunked
     * bulk DEL — but never SETEX/MSET/MULTI.
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
            }
        );
        $this->redisMock->expects($this->never())->method('setex');
        $this->redisMock->expects($this->never())->method('mSet');
        $this->redisMock->expects($this->never())->method('multi');

        $this->assertTrue($this->cacheService->setMultiple(['k1' => 'v1', 'k2' => 'v2'], 0));

        // Reverse-lookup cleanup per key (TAGS:k1, TAGS:k2), then a single bulk del.
        $this->assertContains(['k1', 'k2'], $delCalls);
    }

    /**
     * Negative TTL on setMultiple takes the same delete path as TTL=0;
     * pins the `<= 0` boundary.
     */
    public function testSetMultipleWithNegativeTtlDeletesKeys(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')->willReturn([]);
        $this->redisMock->method('del')->willReturn(1);
        $this->redisMock->expects($this->never())->method('setex');

        $this->assertTrue($this->cacheService->setMultiple(['k1' => 'v1'], -5));
    }

    // -------------------------------------------------------------------
    // Hardening: setTagged exec result semantics ([0] is the SET reply).
    // -------------------------------------------------------------------

    /**
     * In setTagged's pipeline, `$results[0]` is the SET reply — the value
     * write. If SET fails, the method must return false regardless of whether
     * the two follow-up sAdds succeeded. Pins index 0 as the right slot.
     */
    public function testSetTaggedReturnsFalseWhenSetFailsInPipeline(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('setex')->willReturnSelf();
        $this->redisMock->method('sAdd')->willReturnSelf();
        // exec returns [false (SET failed), true, true]
        $this->redisMock->method('exec')->willReturn([false, true, true]);

        $this->assertFalse($this->cacheService->setTagged('k', 'v', 't', 60));
    }

    /**
     * sAdd returning 0 (member already in set) is the EXPECTED case on re-tagging
     * — only the SET reply (results[0]) gates the bool return. Verifies we're
     * NOT looking at the wrong index or applying an `in_array(false, ...)`-style
     * check across the whole result.
     */
    public function testSetTaggedReturnsTrueWhenSetSucceedsEvenIfTagWritesAreNoOps(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('multi')->willReturnSelf();
        $this->redisMock->method('set')->willReturnSelf();
        $this->redisMock->method('sAdd')->willReturnSelf();
        // exec returns [true (SET ok), 0 (sAdd existing - no-op), 0]
        // Only the SET return value (index 0) determines the return.
        $this->redisMock->method('exec')->willReturn([true, 0, 0]);

        $this->assertTrue($this->cacheService->setTagged('k', 'v', 't'));
    }

    // -------------------------------------------------------------------
    // Hardening: phpredis return types — explicit (int) cast assertions.
    // -------------------------------------------------------------------

    /**
     * phpredis LLEN returns false on a missing/non-list key — the `(int)`
     * cast in getQueueLength normalises that to 0 so callers get a clean
     * integer. Pins the cast against removal mutations.
     */
    public function testGetQueueLengthCoercesFalseToZero(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        // phpredis returns false on a missing/non-list key — the (int) cast normalises that to 0.
        $this->redisMock->method('lLen')->willReturn(false);

        $this->assertSame(0, $this->cacheService->getQueueLength('missing'));
    }

    /**
     * Same `(int)` cast on the tag-cardinality side: SCARD on a missing tag
     * set returns false; we must return 0.
     */
    public function testGetTagCardinalityCoercesFalseToZero(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sCard')->willReturn(false);

        $this->assertSame(0, $this->cacheService->getTagCardinality('missing'));
    }

    // -------------------------------------------------------------------
    // Hardening: getSet must return numerically-indexed array (array_values).
    // -------------------------------------------------------------------

    /**
     * Pins the `array_values()` reindex in getSet: phpredis SMEMBERS can
     * return arbitrarily-keyed arrays under some serializers; the contract
     * is a zero-indexed list. Mutation removing array_values would leave the
     * unexpected keys exposed.
     */
    public function testGetSetReturnsNumericallyIndexedArray(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        // Simulate a phpredis sMembers that returns a non-zero-indexed array.
        $this->redisMock->method('sMembers')->willReturn([5 => 'a', 9 => 'b']);

        $result = $this->cacheService->getSet('set');
        $this->assertSame(['a', 'b'], $result, 'getSet must reindex the result via array_values');
    }

    // -------------------------------------------------------------------
    // Hardening: getSorted continue branch (yields false but does NOT stop).
    // -------------------------------------------------------------------

    /**
     * Stored-false middle element must NOT stop iteration: after yielding
     * the false, the loop must `continue` to subsequent members. Pins the
     * `continue` against a mutation to `break` that would prematurely halt.
     */
    public function testGetSortedYieldsStoredFalseAndContinuesIteration(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('zRange')->willReturn(['m1', 'm2', 'm3']);
        // m2's GET reply is false (could be stored-false or missing) — EXISTS disambiguates.
        $this->redisMock->method('mGet')->willReturn(['v1', false, 'v3']);
        $this->redisMock->method('exists')
            ->with('m2')
            ->willReturn(1);  // m2 exists → its `false` is real, yield and continue
        $this->redisMock->expects($this->never())->method('zRem');

        $items = iterator_to_array($this->cacheService->getSorted('zset', 3));
        $this->assertSame(['m1' => 'v1', 'm2' => false, 'm3' => 'v3'], $items);
    }

    // -------------------------------------------------------------------
    // Hardening: peek bulk return shape (non-empty array path).
    // -------------------------------------------------------------------

    /**
     * peek(queue, 2) → LRANGE(0, 1) — pins the `range - 1` arithmetic.
     * Returns the actual head window as an array.
     */
    public function testPeekMultipleReturnsArrayOfHeadItems(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lRange')
            ->with('queue', 0, 1)  // range=2 → lRange(0, 1)
            ->willReturn(['a', 'b']);

        $this->assertSame(['a', 'b'], $this->cacheService->peek('queue', 2));
    }

    /**
     * peek(queue, range>1) on an empty queue returns null (not [] or false) —
     * uniform empty-queue contract with the single-item peek.
     */
    public function testPeekMultipleReturnsNullWhenEmpty(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('lRange')->willReturn([]);

        $this->assertNull($this->cacheService->peek('queue', 5));
    }

    // -------------------------------------------------------------------
    // Hardening: empty-input short-circuits, the real Redis factory, and
    // the rarely-hit defensive branches in clearByTag / getSorted.
    // -------------------------------------------------------------------

    /**
     * getMultiple([]) must short-circuit to an empty array BEFORE touching the
     * connection — no MGET (and not even a getRedis()/isConnected() probe) is
     * issued for an empty key list.
     */
    public function testGetMultipleWithEmptyKeysReturnsEmptyArrayWithoutRedisCall(): void
    {
        $this->redisMock->expects($this->never())->method('mGet');
        $this->redisMock->expects($this->never())->method('isConnected');

        $this->assertSame([], $this->cacheService->getMultiple([]));
    }

    /**
     * setMultiple([]) is a no-op that returns true without any write — neither
     * the MSET fast path nor the pipelined SETEX path may fire for an empty map.
     */
    public function testSetMultipleWithEmptyValuesReturnsTrueWithoutRedisCall(): void
    {
        $this->redisMock->expects($this->never())->method('mSet');
        $this->redisMock->expects($this->never())->method('setex');
        $this->redisMock->expects($this->never())->method('isConnected');

        $this->assertTrue($this->cacheService->setMultiple([]));
    }

    /**
     * deleteMultiple([]) returns true and issues no DEL — the idempotent
     * "nothing to delete" case short-circuits before the connection is used.
     */
    public function testDeleteMultipleWithEmptyKeysReturnsTrueWithoutRedisCall(): void
    {
        $this->redisMock->expects($this->never())->method('del');
        $this->redisMock->expects($this->never())->method('isConnected');

        $this->assertTrue($this->cacheService->deleteMultiple([]));
    }

    /**
     * The default createRedisInstance() factory returns a genuine phpredis
     * handle. Production code relies on this (tests normally override it to
     * inject a mock), so the real `new Redis()` path is pinned here. Merely
     * constructing the object does not open a socket, so this is safe without
     * a live server.
     */
    public function testCreateRedisInstanceReturnsRealRedis(): void
    {
        $client = new RapidCacheClient('localhost', 6379, 'test:');
        $factory = new \ReflectionMethod($client, 'createRedisInstance');

        $this->assertInstanceOf(Redis::class, $factory->invoke($client));
    }

    /**
     * Defensive branch in clearByTag's phase 1: if a pipelined EXEC returns a
     * non-array (a transport hiccup mid-transaction), the reverse-lookup buffer
     * is padded with `null` for every member of that chunk so phase-2 indexing
     * stays aligned with $members. A padded (null) reverse-lookup means "no
     * known other tags", so no cross-tag sRem is emitted — the member's own
     * reverse set and the member key are still deleted, and the tag set is
     * removed last.
     */
    public function testClearByTagPadsReverseLookupsWhenPhaseOneExecReturnsNonArray(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        // Outer sMembers('TAG:t') yields the single tagged member; the inner
        // sMembers happens inside the phase-1 pipeline and its direct return is
        // irrelevant (results would normally arrive via exec()).
        $this->redisMock->method('sMembers')
            ->willReturnCallback(fn(string $k) => $k === 'TAG:t' ? ['k1'] : []);

        // Two MULTI/EXEC pairs: phase 1 (EXEC returns false → triggers padding)
        // and phase 2 (the cleanup pipeline).
        $this->redisMock->expects($this->exactly(2))
            ->method('multi')
            ->with(Redis::PIPELINE);
        $this->redisMock->expects($this->exactly(2))
            ->method('exec')
            ->willReturnOnConsecutiveCalls(false, [1]);

        // Because the reverse lookup was padded to null, no sRem against other
        // tag sets may be issued.
        $this->redisMock->expects($this->never())->method('sRem');

        // del order: phase-2 del('TAGS:k1'), then bulk del(['k1']), then the
        // tag set del('TAG:t').
        $delMatcher = $this->exactly(3);
        $this->redisMock->expects($delMatcher)
            ->method('del')
            ->willReturnCallback(function (string|array $arg) use ($delMatcher) {
                match ($delMatcher->numberOfInvocations()) {
                    1 => $this->assertSame('TAGS:k1', $arg),
                    2 => $this->assertSame(['k1'], $arg),
                    3 => $this->assertSame('TAG:t', $arg),
                };
                return 1;
            });

        $this->assertTrue($this->cacheService->clearByTag('t'));
    }

    /**
     * getSorted is a generator, so a RedisException raised during its read
     * phase only surfaces once a consumer iterates it. When it does, the catch
     * block must translate it into a CacheException (PSR-16's
     * Psr\SimpleCache\CacheException) just like the non-generator methods.
     */
    public function testGetSortedWrapsRedisExceptionFromGenerator(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('zRange')
            ->willThrowException(new \RedisException('boom'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $this->expectException(\Psr\SimpleCache\CacheException::class);
        iterator_to_array($this->cacheService->getSorted('test-sorted-set', 2));
    }

    private function clientWithInjectedRedis(Redis $redis): RapidCacheClient
    {
        return new class ('host', 6379, null, $redis) extends RapidCacheClient {
            public function __construct(string $host, ?int $port, ?string $prefix, private Redis $injected)
            {
                parent::__construct($host, $port, $prefix);
            }

            protected function createRedisInstance(): Redis
            {
                return $this->injected;
            }
        };
    }
}
