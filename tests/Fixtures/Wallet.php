<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Entity;
use JakubBoucek\Hydrator\Tests\Fixtures\ThirdParty\Ulid;

class Wallet implements Entity
{
    public int $id;
    public MoneyValue $balance;      // own custom type (IntValue)
    public ?MoneyValue $bonus;       // nullable: NULL field = plain null
    public Ulid $publicId;           // foreign class via TypeAdapter
    public ?SecretValue $token;      // inner nullness demo (StringValue)
    public FlagValue $active;        // bool intermediate
}
