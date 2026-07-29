<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Exception;

use InvalidArgumentException;

/**
 * A foreign object was passed where an instance of the mapped entity
 * class is required.
 */
class InvalidEntityException extends InvalidArgumentException implements HydratorException
{
}
