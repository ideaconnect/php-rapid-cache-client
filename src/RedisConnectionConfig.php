<?php

declare(strict_types=1);

namespace IDCT\Cache;

/**
 * Immutable connection settings for {@see RapidCacheClient}.
 *
 * Defaults are tuned for safe production behavior: a finite connect timeout
 * (phpredis's default of 0 means wait forever, which can hang a worker), and
 * non-persistent connections (turn on explicitly for hot paths).
 */
final class RedisConnectionConfig
{
    /**
     * @param string      $host           Redis/Valkey server hostname or IP address.
     * @param int         $port           TCP port the server listens on (default: 6379).
     * @param string|null $prefix         Optional key prefix applied to every operation via
     *                                    Redis::OPT_PREFIX. When null/empty, keys are used as-is.
     *                                    Useful for sharing a Redis instance between apps.
     * @param string|null $password       Optional AUTH password. Null/empty skips authentication.
     * @param int         $database       Redis database index to SELECT after connecting
     *                                    (typically 0–15). Default 0 means "no SELECT call".
     * @param float       $connectTimeout Seconds to wait while establishing the TCP connection.
     *                                    phpredis's native default is 0 ("wait forever"), which
     *                                    can hang a worker indefinitely — we default to 1.0.
     * @param float       $readTimeout    Seconds to wait for a single read response after the
     *                                    connection is established (Redis::OPT_READ_TIMEOUT).
     *                                    Only applied when > 0. Default 1.0.
     * @param bool        $persistent     When true, uses pconnect() so the connection is reused
     *                                    across requests in the same PHP-FPM/worker process.
     *                                    Recommended for hot paths; default false for safety.
     * @param string|null $persistentId   Pool identifier for persistent connections — different
     *                                    IDs get separate pooled connections. Ignored when
     *                                    $persistent is false.
     * @param bool        $retryOnce      When true, a RedisException triggers exactly one
     *                                    reconnect-and-retry of the failed operation before the
     *                                    exception is wrapped and rethrown. Recovers from
     *                                    transient network blips at the cost of one extra
     *                                    round-trip on failure.
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port = RapidCacheClient::DEFAULT_REDIS_PORT,
        public readonly ?string $prefix = null,
        public readonly ?string $password = null,
        public readonly int $database = 0,
        public readonly float $connectTimeout = 1.0,
        public readonly float $readTimeout = 1.0,
        public readonly bool $persistent = false,
        public readonly ?string $persistentId = null,
        public readonly bool $retryOnce = false,
    ) {
        if ($host === '') {
            throw new \InvalidArgumentException('host must be a non-empty string.');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(
                sprintf('port must be between 1 and 65535, got %d.', $port)
            );
        }
        if ($database < 0) {
            throw new \InvalidArgumentException(
                sprintf('database must be >= 0, got %d.', $database)
            );
        }
        if ($connectTimeout < 0) {
            throw new \InvalidArgumentException(
                sprintf('connectTimeout must be >= 0, got %F.', $connectTimeout)
            );
        }
        if ($readTimeout < 0) {
            throw new \InvalidArgumentException(
                sprintf('readTimeout must be >= 0, got %F.', $readTimeout)
            );
        }
    }
}
