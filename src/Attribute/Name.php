<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Attribute;

use Attribute;

/**
 * Overrides the field name of a property in data.
 *
 * Repeatable; attributes are evaluated top-down in declaration order and the
 * first one matching the current format wins (like a `match` expression), so
 * declare more specific scopes first. An attribute without a format scope is
 * a catch-all and must be declared last — any attribute after it is
 * unreachable and rejected with a MetadataException.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class Name
{
    /**
     * @param string $name Field name in data.
     * @param list<class-string> $formats Formats the override applies to, matched
     *   by `instanceof` — a concrete format class, its ancestor or a family
     *   marker interface (e.g. DatabaseFormat). Empty list = all formats.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $formats = [],
    ) {
    }
}
