<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Attribute;

use JakubBoucek\Hydrator\Format\FormatScope;

/**
 * A repeatable attribute whose validity is limited to formats. Shared
 * resolution rules: attributes are evaluated top-down in declaration order,
 * the first one matching the current format wins (like a `match`
 * expression); scopes match via `instanceof` (a concrete format class, its
 * ancestor or a family marker interface). An attribute with an empty scope
 * list is a catch-all and must be declared last — anything after it is
 * unreachable and rejected.
 */
interface FormatScoped
{
    /** @var list<class-string<FormatScope>> */
    public array $formats { get; }
}
