<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use DateInterval;
use DateTimeImmutable;
use JakubBoucek\Hydrator\Attribute\Fraction;
use JakubBoucek\Hydrator\Attribute\Type;
use JakubBoucek\Hydrator\Entity;

/** Entity of the integration test table covering common MariaDB column types. */
class DataRow implements Entity
{
    public int $id;
    public bool $active;                    // TINYINT(1)
    public int $counter;                    // INT
    public int $bigNumber;                  // BIGINT
    public float $price;                    // DECIMAL(10,2)
    public float $ratio;                    // DOUBLE
    public string $title;                   // VARCHAR(100)
    public ?string $body;                   // TEXT NULL

    #[Type\Date]
    public DateTimeImmutable $bornOn;       // DATE

    public DateTimeImmutable $createdAt;    // DATETIME

    #[Fraction(6)]
    public DateTimeImmutable $measuredAt;   // DATETIME(6)

    public DateTimeImmutable $syncedAt;     // TIMESTAMP

    public DateInterval $elapsed;           // TIME (full domain, over 24 hours)

    #[Type\Time]
    #[Fraction(3)]
    public DateTimeImmutable $alarmAt;      // TIME(3), day-scoped

    public ArticleStatus $status;           // ENUM('draft', 'published')
    public ?string $payload;                // JSON NULL (snapshot philosophy: untyped)
}
