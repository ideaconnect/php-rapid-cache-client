<?php

declare(strict_types=1);

namespace IDCT\Cache\Exception;

use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * Thrown when the caller passes an argument the cache contract forbids - an
 * empty key, a key containing one of the PSR-16 reserved characters
 * ({@see \IDCT\Cache\RapidCacheClient}), a null queue value, a non-positive
 * pop/peek range, or tagging a key that does not exist.
 *
 * It is the "you called this wrong" counterpart to {@see CacheException}
 * ("the backend failed"). Catching one but not the other lets calling code
 * tell a programming bug apart from an infrastructure hiccup.
 *
 * Why two interfaces are bridged here:
 * PSR-16 requires invalid-argument failures to implement
 * {@see PsrInvalidArgumentException} (which itself extends
 * `Psr\SimpleCache\CacheException`). We extend the SPL
 * {@see \InvalidArgumentException} so the exception also reads naturally to any
 * generic handler. The result: PSR-16 consumers can `catch
 * (Psr\SimpleCache\InvalidArgumentException)` and stay backend-agnostic, while
 * non-PSR code still catches the familiar SPL type.
 */
class InvalidArgumentException extends \InvalidArgumentException implements PsrInvalidArgumentException
{
}
