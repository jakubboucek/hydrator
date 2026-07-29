<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Exception;

use RuntimeException;

/**
 * The entity cannot be extracted to data: a property holds a value
 * the format cannot represent.
 */
class ExtractionException extends RuntimeException implements HydratorException
{
}
