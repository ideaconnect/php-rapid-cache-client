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
}
