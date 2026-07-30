<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Attribute;

use Attribute;
use JakubBoucek\Hydrator\Format\FormatScope;

/**
 * Strict control of fractional seconds on export, for date-time, time and
 * interval properties (the analogy of DATETIME(n)/TIME(n) column precision).
 *
 * Without the attribute the format defaults apply (date-times render without
 * a fraction, times and intervals append it when non-zero). With it exactly
 * `digits` places are rendered — zero-padded, truncated when the value
 * carries more — and `digits: 0` never renders a fraction even when the
 * value has one. With a Fraction (or DateFormat) attribute the NetteDatabase
 * format exports a finished string instead of the instance: Nette's own
 * formatting would drop the fraction.
 *
 * Cannot be combined with #[DateFormat] for the same format. Scoping and
 * evaluation order are shared with #[Name] (see FormatScoped).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class Fraction implements FormatScoped
{
    /**
     * @param int<0, 6> $digits Rendered decimal places (0 = never render).
     * @param bool $omitZero Omit the whole fraction part when it is zero.
     * @param list<class-string<FormatScope>> $formats Formats the attribute
     *   applies to, matched by `instanceof`; empty list = all formats.
     */
    public function __construct(
        public readonly int $digits,
        public readonly bool $omitZero = false,
        public readonly array $formats = [],
    ) {
    }
}
