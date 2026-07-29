<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Exception\ValidationException;
use JakubBoucek\Hydrator\SelfValidating;

class ValidatedEntity implements SelfValidating
{
    public int $id;
    public string $title;
    public ?string $note;

    public function validate(): void
    {
        if ($this->title === '') {
            throw new ValidationException('Title must not be empty.');
        }
    }
}
