<?php

declare(strict_types=1);

namespace IDCT\Tests\Cache\Unit;

use IDCT\Cache\RedisConnectionConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedisConnectionConfig::class)]
class RedisConnectionConfigTest extends TestCase
{
    /**
     * Smoke test: every constructor parameter accepts a representative
     * value and is exposed via its readonly property.
     */
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

    /**
     * 0 is a legitimate timeout value in phpredis ("wait forever") — the
     * validator allows it, even though we explicitly default to 1.0 to
     * prevent accidental worker hangs.
     */
    public function testZeroTimeoutsAreAllowed(): void
    {
        // phpredis treats 0 as "no timeout" — allowed by the underlying API.
        $config = new RedisConnectionConfig(host: 'h', connectTimeout: 0.0, readTimeout: 0.0);

        $this->assertSame(0.0, $config->connectTimeout);
        $this->assertSame(0.0, $config->readTimeout);
    }

    /**
     * Empty hostname → \InvalidArgumentException at construction (fail-fast,
     * before any connection attempt).
     */
    public function testEmptyHostRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('host must be a non-empty string');

        new RedisConnectionConfig(host: '');
    }

    /**
     * Port 0 is below the TCP-port valid range — rejected.
     */
    public function testPortTooLowRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('port must be between 1 and 65535');

        new RedisConnectionConfig(host: 'h', port: 0);
    }

    /**
     * Port 65536 is above the TCP-port valid range — rejected.
     */
    public function testPortTooHighRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('port must be between 1 and 65535');

        new RedisConnectionConfig(host: 'h', port: 65536);
    }

    /**
     * Redis DB indexes are 0..N — negatives are nonsense, fail-fast at config.
     */
    public function testNegativeDatabaseRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('database must be >= 0');

        new RedisConnectionConfig(host: 'h', database: -1);
    }

    /**
     * Negative connect timeout has no defined meaning — rejected.
     */
    public function testNegativeConnectTimeoutRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('connectTimeout must be >= 0');

        new RedisConnectionConfig(host: 'h', connectTimeout: -0.1);
    }

    /**
     * Negative read timeout has no defined meaning — rejected.
     */
    public function testNegativeReadTimeoutRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('readTimeout must be >= 0');

        new RedisConnectionConfig(host: 'h', readTimeout: -0.1);
    }

    /**
     * Batch size of 0 makes the bulk-operation chunking loops do nothing —
     * rejected so misconfig fails loudly, not silently.
     */
    public function testPipelineBatchSizeBelowOneRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pipelineBatchSize must be >= 1');

        new RedisConnectionConfig(host: 'h', pipelineBatchSize: 0);
    }

    /**
     * Default batch size pinned at 1000 — matches SCAN's COUNT and is a
     * conservative balance between request size and round-trip count.
     */
    public function testPipelineBatchSizeDefaultIsThousand(): void
    {
        $config = new RedisConnectionConfig(host: 'h');

        $this->assertSame(1000, $config->pipelineBatchSize);
    }

    /**
     * Pins each "safe by default" value so a future cleanup can't silently
     * flip them. The docblock on the constructor calls these "tuned for
     * safe production behavior" — this is the test that enforces it.
     */
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

    /**
     * Boundary inclusivity: port=1 and port=65535 are valid TCP ports and
     * must be accepted. Pins `<` and `>` in the port check so off-by-one
     * mutations (`<=` / `>=`) get killed.
     */
    public function testPortBoundariesAreAccepted(): void
    {
        // Inclusive bounds: the validator must accept the extremes, not just reject
        // outside them. Pins `<` / `>` so off-by-one mutants get killed.
        $low = new RedisConnectionConfig(host: 'h', port: 1);
        $high = new RedisConnectionConfig(host: 'h', port: 65535);

        $this->assertSame(1, $low->port);
        $this->assertSame(65535, $high->port);
    }

    /**
     * Boundary: pipelineBatchSize=1 is valid (every operation gets its own
     * round-trip). Pins `< 1` so a `<= 1` mutation gets killed.
     */
    public function testPipelineBatchSizeOfOneIsAccepted(): void
    {
        $config = new RedisConnectionConfig(host: 'h', pipelineBatchSize: 1);

        $this->assertSame(1, $config->pipelineBatchSize);
    }
}
