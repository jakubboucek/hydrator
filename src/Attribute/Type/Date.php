<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Attribute\Type;

use Attribute;

/**
 * Refines a DateTimeImmutable property to a date-only value (no time part).
 * The PHP type stays DateTimeImmutable; formats represent the value without
 * time (e.g. `Y-m-d` in the Mysql format). No runtime midnight check is
 * performed.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Date
{
}
