<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Exception;

use RuntimeException;

/**
 * The entity failed validation: uninitialized non-nullable properties
 * or custom rules of a SelfValidating entity.
 */
class ValidationException extends RuntimeException implements HydratorException
{
}
