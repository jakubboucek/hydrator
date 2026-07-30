<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Entity;

class Customer implements Entity
{
    public int $id;
    public string $name;

    /** Struct properties are non-nullable by design: an instance always exists. */
    public ContactStruct $contact;
    public ContactStruct $fallback;
}
