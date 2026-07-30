<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\DynamicStruct;
use JakubBoucek\Hydrator\Entity;
use JakubBoucek\Hydrator\NoteListStruct;
use JakubBoucek\Hydrator\TagListStruct;

/** Integration entity: every bundled Struct flavor over JSON columns. */
class Parcel implements Entity
{
    public int $id;
    public string $label;
    public ContactStruct $contact;
    public TagListStruct $tags;
    public NoteListStruct $notes;
    public DynamicStruct $meta;
}
