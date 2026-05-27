<?php

declare(strict_types=1);

namespace IDCT\Cache\Exception;

use Psr\SimpleCache\CacheException as PsrCacheException;
use RuntimeException;

/**
 * Thrown for storage/transport-level cache failures (a dropped connection, a
 * Redis WRONGTYPE error, a serialization failure, etc.) as opposed to caller
 * mistakes - those surface as {@see InvalidArgumentException}.
 *
 * Why two interfaces are bridged here:
 * PSR-16 mandates that "any failure" from a cache operation be a
 * {@see PsrCacheException}, so consumers can catch the standard interface and
 * stay backend-agnostic. We also want it to behave like a normal unchecked
 * runtime fault, hence extending {@see RuntimeException}. Implementing the PSR
 * marker interface on top of a concrete exception base is the idiomatic way to
 * satisfy both: PSR-16 code catches `Psr\SimpleCache\CacheException`, while
 * generic error handlers still catch it as a `RuntimeException`/`Throwable`.
 *
 * {@see \IDCT\Cache\RapidCacheClient} always wraps the originating
 * {@see \RedisException} as this exception's `$previous` cause (see
 * {@see \IDCT\Cache\RapidCacheClient::toCacheException()}), so the low-level
 * Redis message is never lost.
 */
class CacheException extends RuntimeException implements PsrCacheException
{
}
