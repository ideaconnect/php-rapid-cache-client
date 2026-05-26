<?php

declare(strict_types=1);

namespace IDCT\Cache\Exception;

use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

class InvalidArgumentException extends \InvalidArgumentException implements PsrInvalidArgumentException
{
}
