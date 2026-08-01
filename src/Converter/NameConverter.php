<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Converter;

/**
 * Naming convention between entity property names and field names in data.
 * Formats compose a converter internally; custom formats can reuse the
 * bundled implementations or provide their own.
 */
interface NameConverter
{
    public function toFieldName(string $propertyName): string;
}
