<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Entity;

use DateTime;
use JakubBoucek\Hydrator\Attribute\Name;
use JakubBoucek\Hydrator\Attribute\Type;
use JakubBoucek\Hydrator\Format\Mysql;

class BrokenUnionType implements Entity
{
    public int|string $value;
}

class BrokenMutableDate implements Entity
{
    public DateTime $createdAt;
}

class BrokenDateOnString implements Entity
{
    #[Type\Date]
    public string $createdAt;
}

class BrokenTimeOnString implements Entity
{
    #[Type\Time]
    public string $startsAt;
}

class BrokenDateAndTime implements Entity
{
    #[Type\Date]
    #[Type\Time]
    public \DateTimeImmutable $moment;
}

class BrokenUnreachableName implements Entity
{
    #[Name('catch_all')]
    #[Name('mysql_col', [Mysql::class])]
    public string $value;
}

class BrokenUnknownScope implements Entity
{
    #[Name('col', ['NonExistent\FormatClass'])]
    public string $value;
}
