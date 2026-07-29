<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use DateTime;
use JakubBoucek\Hydrator\Attribute\Name;
use JakubBoucek\Hydrator\Attribute\Type;
use JakubBoucek\Hydrator\Format\Mysql;

class BrokenUnionType
{
    public int|string $value;
}

class BrokenMutableDate
{
    public DateTime $createdAt;
}

class BrokenDateOnString
{
    #[Type\Date]
    public string $createdAt;
}

class BrokenUnreachableName
{
    #[Name('catch_all')]
    #[Name('mysql_col', [Mysql::class])]
    public string $value;
}

class BrokenUnknownScope
{
    #[Name('col', ['NonExistent\FormatClass'])]
    public string $value;
}
