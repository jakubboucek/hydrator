<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Value;

/**
 * Whitelist of intermediate native types a custom type maps to. The value
 * first passes the format codec for this native type (with all its
 * strictness), only then reaches the custom conversion — custom types are
 * therefore format-blind: how a bool or a date-time is represented in data
 * stays the format's job.
 */
enum NativeType
{
    case String;
    case Int;
    case Float;
    case Bool;
    case DateTime;
    case Interval;
}
