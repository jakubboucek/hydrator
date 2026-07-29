<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Entity;

use JakubBoucek\Hydrator\Attribute\Name;
use JakubBoucek\Hydrator\Format\DatabaseFormat;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;

class NamedEntity implements Entity
{
    public int $id;

    #[Name('some__name')]
    public string $someName;

    #[Name('nette_col', [NetteDatabase::class])]
    #[Name('mysql__col', [Mysql::class])]
    #[Name('generic_col')]
    public string $scoped;

    #[Name('db__only', [DatabaseFormat::class])]
    public string $family;
}
