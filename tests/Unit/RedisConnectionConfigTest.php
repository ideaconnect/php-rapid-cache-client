<?php

declare(strict_types=1);

namespace IDCT\Tests\Cache\Unit;

use IDCT\Cache\RedisConnectionConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedisConnectionConfig::class)]
class RedisConnectionConfigTest extends TestCase
{
    public function testAcceptsValidValues(): void
    {
        $config = new RedisConnectionConfig(
            host: 'redis.example.com',
            port: 6379,
            prefix: 'app:',
            password: 'secret',
            database: 2,
            connectTimeout: 0.5,
            readTimeout: 0.5,
            persistent: true,
            persistentId: 'pool-1',
            retryOnce: true,
        );

        $this->assertSame('redis.example.com', $config->host);
        $this->assertSame(6379, $config->port);
        $this->assertSame(2, $config->database);
    }

    public function testZeroTimeoutsAreAllowed(): void
    {
        // phpredis treats 0 as "no timeout" — allowed by the underlying API.
        $config = new RedisConnectionConfig(host: 'h', connectTimeout: 0.0, readTimeout: 0.0);

        $this->assertSame(0.0, $config->connectTimeout);
        $this->assertSame(0.0, $config->readTimeout);
    }

    public function testEmptyHostRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('host must be a non-empty string');

        new RedisConnectionConfig(host: '');
    }

    public function testPortTooLowRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('port must be between 1 and 65535');

        new RedisConnectionConfig(host: 'h', port: 0);
    }

    public function testPortTooHighRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('port must be between 1 and 65535');

        new RedisConnectionConfig(host: 'h', port: 65536);
    }

    public function testNegativeDatabaseRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('database must be >= 0');

        new RedisConnectionConfig(host: 'h', database: -1);
    }

    public function testNegativeConnectTimeoutRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('connectTimeout must be >= 0');

        new RedisConnectionConfig(host: 'h', connectTimeout: -0.1);
    }

    public function testNegativeReadTimeoutRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('readTimeout must be >= 0');

        new RedisConnectionConfig(host: 'h', readTimeout: -0.1);
    }

    public function testPipelineBatchSizeBelowOneRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pipelineBatchSize must be >= 1');

        new RedisConnectionConfig(host: 'h', pipelineBatchSize: 0);
    }

    public function testPipelineBatchSizeDefaultIsThousand(): void
    {
        $config = new RedisConnectionConfig(host: 'h');

        $this->assertSame(1000, $config->pipelineBatchSize);
    }

    public function testDefaultsAreSafeForProduction(): void
    {
        $config = new RedisConnectionConfig(host: 'h');

        // These defaults are load-bearing: documented in the constructor's docblock
        // as "tuned for safe production behavior." Pin them so mutations are caught.
        $this->assertSame(0, $config->database, 'default database must be 0 (no SELECT call)');
        $this->assertSame(1.0, $config->connectTimeout, 'default connectTimeout must be 1.0s, never 0 ("wait forever")');
        $this->assertSame(1.0, $config->readTimeout, 'default readTimeout must be 1.0s');
        $this->assertFalse($config->persistent, 'default persistent must be false (opt-in for hot paths)');
        $this->assertFalse($config->retryOnce, 'default retryOnce must be false (explicit opt-in)');
    }

    public function testPortBoundariesAreAccepted(): void
    {
        // Inclusive bounds: the validator must accept the extremes, not just reject
        // outside them. Pins `<` / `>` so off-by-one mutants get killed.
        $low = new RedisConnectionConfig(host: 'h', port: 1);
        $high = new RedisConnectionConfig(host: 'h', port: 65535);

        $this->assertSame(1, $low->port);
        $this->assertSame(65535, $high->port);
    }

    public function testPipelineBatchSizeOfOneIsAccepted(): void
    {
        $config = new RedisConnectionConfig(host: 'h', pipelineBatchSize: 1);

        $this->assertSame(1, $config->pipelineBatchSize);
    }
}
