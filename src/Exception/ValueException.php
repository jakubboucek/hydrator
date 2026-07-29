<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Exception;

use RuntimeException;

/**
 * A single value cannot be converted by a Format codec. Thrown by Format
 * implementations (including custom ones); the engine catches it and
 * rethrows a HydrationException/ExtractionException with the entity
 * class, property and field context attached.
 */
class ValueException extends RuntimeException implements HydratorException
{
}
