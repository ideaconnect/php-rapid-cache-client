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

    public function testImplementsPsrSimpleCache(): void
    {
        $this->assertInstanceOf(CacheInterface::class, $this->cacheService);
        $this->assertInstanceOf(CacheServiceInterface::class, $this->cacheService);
    }

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

    public function testGetRejectsInvalidKey(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->get('invalid:key');
    }

    public function testSetWithoutTtl(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('set')
            ->with('test-key', 'test-value')
            ->willReturn(true);

        $this->assertTrue($this->cacheService->set('test-key', 'test-value'));
    }

    public function testSetWithIntegerTtl(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('setex')
            ->with('test-key', 3600, 'test-value')
            ->willReturn(true);

        $this->assertTrue($this->cacheService->set('test-key', 'test-value', 3600));
    }

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

    public function testSetRejectsInvalidKey(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->set('bad{key}', 'value');
    }

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

    public function testDeleteRejectsInvalidKey(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->cacheService->delete('');
    }

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

    public function testGetTaggedWithNoItems(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sMembers')
            ->with('TAG:test-tag')
            ->willReturn([]);

        $this->assertSame([], iterator_to_array($this->cacheService->getTagged('test-tag')));
    }

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

    public function testEnqueue(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('rPush')
            ->with('test-queue', 'test-value');

        $result = $this->cacheService->enqueue('test-queue', 'test-value');
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

    public function testEnqueueThrowsForNullValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Can\'t enqueue null item');

        $this->cacheService->enqueue('test-queue', null);
    }

    public function testPopSingleItem(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lPop')
            ->with('test-queue')
            ->willReturn('test-value');

        $this->assertSame('test-value', $this->cacheService->pop('test-queue'));
    }

    public function testPopSingleItemReturnsNullWhenEmpty(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lPop')
            ->with('test-queue')
            ->willReturn(false);

        $this->assertNull($this->cacheService->pop('test-queue'));
    }

    public function testPopMultipleItems(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lPop')
            ->with('test-queue', 3)
            ->willReturn(['item1', 'item2', 'item3']);

        $this->assertSame(['item1', 'item2', 'item3'], $this->cacheService->pop('test-queue', 3));
    }

    public function testPeekSingleItem(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lRange')
            ->with('test-queue', 0, 0)
            ->willReturn(['item1']);

        $this->assertSame('item1', $this->cacheService->peek('test-queue'));
    }

    public function testPeekSingleItemReturnsNullWhenEmpty(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lRange')
            ->with('empty-queue', 0, 0)
            ->willReturn([]);

        $this->assertNull($this->cacheService->peek('empty-queue'));
    }

    public function testPopRejectsNonPositiveRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cacheService->pop('test-queue', 0);
    }

    public function testPeekRejectsNonPositiveRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cacheService->peek('test-queue', -1);
    }

    public function testPeekMultipleItems(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lRange')
            ->with('test-queue', 0, 2)
            ->willReturn(['item1', 'item2', 'item3']);

        $this->assertSame(['item1', 'item2', 'item3'], $this->cacheService->peek('test-queue', 3));
    }

    public function testIncrease(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('incrBy')
            ->with('test-key', 5);

        $result = $this->cacheService->increase('test-key', 5);
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

    public function testDecrease(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('decrBy')
            ->with('test-key', 3);

        $result = $this->cacheService->decrease('test-key', 3);
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

    public function testGetTagCardinality(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sCard')
            ->with('TAG:test-tag')
            ->willReturn(5);

        $this->assertSame(5, $this->cacheService->getTagCardinality('test-tag'));
    }

    public function testGetCardinalityForRegularSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sCard')
            ->with('test-set')
            ->willReturn(3);

        $this->assertSame(3, $this->cacheService->getCardinality('test-set'));
    }

    public function testGetCardinalityForSortedSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('zCard')
            ->with('test-sorted-set')
            ->willReturn(7);

        $this->assertSame(7, $this->cacheService->getCardinality('test-sorted-set', true));
    }

    public function testAddToSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sAdd')
            ->with('test-set', 'test-value');

        $result = $this->cacheService->addToSet('test-set', 'test-value');
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

    public function testRemoveFromSet(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sRem')
            ->with('test-set', 'test-value');

        $result = $this->cacheService->removeFromSet('test-set', 'test-value');
        $this->assertInstanceOf(RapidCacheClient::class, $result);
    }

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

    public function testGetSetReturnsNullWhenNotExists(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('sMembers')
            ->with('test-set')
            ->willReturn(false);

        $this->assertNull($this->cacheService->getSet('test-set'));
    }

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

    public function testEnqueueRejectsInvalidQueueName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cacheService->enqueue('bad:queue', 'value');
    }

    public function testTagRejectsInvalidTagName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cacheService->tag('key', 'bad:tag');
    }

    public function testAddToSetRejectsInvalidKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cacheService->addToSet('bad@set', 'value');
    }

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

    public function testGetQueueLength(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lLen')
            ->with('test-queue')
            ->willReturn(5);

        $this->assertSame(5, $this->cacheService->getQueueLength('test-queue'));
    }

    public function testGetQueueWithEmptyQueue(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->expects($this->once())
            ->method('lRange')
            ->with('empty-queue', 0, -1)
            ->willReturn([]);

        $this->assertSame([], $this->cacheService->getQueue('empty-queue'));
    }

    public function testInvalidArgumentExceptionIsPsrCompliant(): void
    {
        $exception = new InvalidArgumentException('boom');
        $this->assertInstanceOf(PsrInvalidArgumentException::class, $exception);
        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
    }

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

    public function testReconnectWrapsArbitraryThrowable(): void
    {
        $failingRedis = $this->createMock(Redis::class);
        $failingRedis->method('connect')
            ->willThrowException(new \RuntimeException('dns blew up'));

        $client = $this->clientWithInjectedRedis($failingRedis);

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $client->get('any-key');
    }

    public function testGetWrapsRedisExceptionAsCacheException(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('get')
            ->willThrowException(new \RedisException('connection lost'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $this->expectException(\Psr\SimpleCache\CacheException::class);
        $this->cacheService->get('test-key');
    }

    public function testSetWrapsRedisExceptionAsCacheException(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('set')
            ->willThrowException(new \RedisException('write failure'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $this->cacheService->set('test-key', 'value');
    }

    public function testEnqueueWrapsRedisExceptionAsCacheException(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('rPush')
            ->willThrowException(new \RedisException('connection lost'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        $this->cacheService->enqueue('test-queue', 'value');
    }

    public function testGetTaggedWrapsRedisExceptionFromGenerator(): void
    {
        $this->redisMock->method('isConnected')->willReturn(true);
        $this->redisMock->method('sMembers')
            ->willThrowException(new \RedisException('boom'));

        $this->expectException(\IDCT\Cache\Exception\CacheException::class);
        iterator_to_array($this->cacheService->getTagged('test-tag'));
    }

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

    public function testReconnectLetsErrorsPropagate(): void
    {
        $failingRedis = $this->createMock(Redis::class);
        $failingRedis->method('connect')
            ->willThrowException(new \TypeError('coding bug'));

        $client = $this->clientWithInjectedRedis($failingRedis);

        $this->expectException(\TypeError::class);
        $client->get('any-key');
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
