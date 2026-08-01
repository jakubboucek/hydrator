<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Converter;

/**
 * Keeps property names as-is (camelCase properties map to camelCase fields).
 */
class IdentityConverter implements NameConverter
{
    public function toFieldName(string $propertyName): string
    {
        return $propertyName;
    }
}
