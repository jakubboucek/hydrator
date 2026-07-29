<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Entity;

class SimpleEntity implements Entity
{
    public int $id;
    public string $title;
    public ?string $note;
}
