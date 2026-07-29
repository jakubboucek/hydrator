<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Format;

/**
 * Marker interface of everything usable as a format scope in attributes
 * (`#[Name('field', [SomeScope::class])]`): every Format class implements it
 * and family marker interfaces (like DatabaseFormat) extend it. Custom family
 * interfaces must extend this interface as well.
 */
interface FormatScope
{
}
