<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

class Book
{
    public int $id;
    public string $title;
    public ?string $note;
    public bool $inStock;
}
