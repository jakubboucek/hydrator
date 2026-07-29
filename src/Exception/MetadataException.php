<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Exception;

use LogicException;

/**
 * The entity class cannot be mapped: unsupported property type, invalid
 * attribute usage or other defect of the entity definition itself.
 */
class MetadataException extends LogicException implements HydratorException
{
}
