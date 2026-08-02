<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Exception;

use LogicException;

/**
 * The single-pass stream contract was violated: an already consumed
 * entity set was iterated or collected again.
 */
class StreamException extends LogicException implements HydratorException
{
}
