<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Format;

/**
 * Marker interface of the database format family, usable as an attribute
 * scope: `#[Name('some__name', [DatabaseFormat::class])]` applies to all
 * database formats including future ones.
 */
interface DatabaseFormat
{
}
