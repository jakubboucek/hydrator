<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Exception;

use RuntimeException;

/**
 * The input data cannot be hydrated into the entity: missing field,
 * null in a non-nullable property or a value of an unexpected type.
 */
class HydrationException extends RuntimeException implements HydratorException
{
}
