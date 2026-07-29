<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Attribute\Type;

use Attribute;

/**
 * Refines a DateTimeImmutable property to a time-of-day value (no date
 * part), strictly day-scoped: 00:00:00 <= x < 24:00:00. The wall time is
 * pinned to the date 0001-01-01 — a date that predates DST rules, so every
 * wall time on it exists exactly once; only the wall clock is meaningful,
 * not the instant.
 *
 * The day-scoped alternative to a DateInterval property, which covers the
 * full MySQL TIME domain (negative values, hours over 24) that a DateTime
 * cannot hold.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Time
{
}
