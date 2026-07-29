<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Entity;

use DateInterval;
use DateTimeImmutable;
use JakubBoucek\Hydrator\Attribute\Type;

class Article implements Entity
{
    public int $id;
    public string $title;
    public ?string $note;
    public bool $published;
    public DateTimeImmutable $createdAt;
    #[Type\Date]
    public DateTimeImmutable $publishedOn;
    public DateInterval $readingTime;
    public ArticleStatus $status;
    public Level $level;
    public int $viewCount = 0;
    public mixed $rawMeta;
}
