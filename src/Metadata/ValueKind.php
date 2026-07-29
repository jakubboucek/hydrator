<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Metadata;

/**
 * Logical type of an entity property, resolved once from the PHP type and
 * refining attributes when property metadata is built.
 *
 * @internal
 */
enum ValueKind
{
    case Int;
    case Float;
    case String;
    case Bool;
    case Enum;
    case DateTime;
    case Date;
    case Interval;
    case Mixed;
}
