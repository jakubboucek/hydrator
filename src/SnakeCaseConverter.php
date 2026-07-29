<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

/**
 * Converts camelCase (or PascalCase) property names to snake_case field names.
 */
final class SnakeCaseConverter implements NameConverter
{
    public function toFieldName(string $propertyName): string
    {
        return strtolower((string) preg_replace('~(?<!^)[A-Z]~', '_$0', $propertyName));
    }
}
