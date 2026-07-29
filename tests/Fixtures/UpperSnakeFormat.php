<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\NameConverter;
use JakubBoucek\Hydrator\SnakeCaseConverter;

/** Custom format: subclass of Mysql with a different naming convention. */
class UpperSnakeFormat extends Mysql
{
    protected function createNameConverter(): NameConverter
    {
        return new class implements NameConverter {
            private SnakeCaseConverter $snake;

            public function toFieldName(string $propertyName): string
            {
                return strtoupper(($this->snake ??= new SnakeCaseConverter())->toFieldName($propertyName));
            }
        };
    }
}
