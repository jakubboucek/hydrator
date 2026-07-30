<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Attribute;

use Attribute;
use JakubBoucek\Hydrator\Format\FormatScope;

/**
 * Custom output format of a date-time, date or time property, as a native
 * PHP date format pattern. Bidirectional by construction: the same pattern
 * drives the export (`format()`) and the import
 * (`DateTimeImmutable::createFromFormat('!' . pattern)`, strict — no
 * fallback to constructor parsing). A pattern that captures the full value
 * roundtrips losslessly; a lossy pattern (e.g. no seconds) zeroes the
 * uncaptured parts deterministically and never crashes.
 *
 * Not available for DateInterval properties — DateInterval::format() has no
 * parsing counterpart in PHP, so the bidirectional promise could not be
 * kept; exotic interval renderings belong to a custom Format subclass.
 *
 * With a DateFormat (or Fraction) attribute the NetteDatabase format
 * exports a finished string instead of the instance. Cannot be combined
 * with #[Fraction] for the same format. Scoping and evaluation order are
 * shared with #[Name] (see FormatScoped).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class DateFormat implements FormatScoped
{
    /**
     * @param non-empty-string $pattern Native PHP date format pattern.
     * @param list<class-string<FormatScope>> $formats Formats the attribute
     *   applies to, matched by `instanceof`; empty list = all formats.
     */
    public function __construct(
        public readonly string $pattern,
        public readonly array $formats = [],
    ) {
    }
}
