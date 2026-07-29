<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

class HookedEntity
{
    public int $id;
    public string $firstName;
    public string $lastName;

    /** Virtual get-only property: never hydrated nor extracted. */
    public string $fullName {
        get => $this->firstName . ' ' . $this->lastName;
    }

    /** Virtual write-only property: hydrated (set hook), never extracted. */
    public string $names {
        set {
            [$this->firstName, $this->lastName] = explode(' ', $value, 2) + [1 => ''];
        }
    }

    /** Backed property with hooks: hydrated and extracted, hooks apply. */
    public string $email {
        get => strtolower($this->email);
        set => trim($value);
    }

    /** Restricted setter: not hydrated (field not even required), extracted. */
    public private(set) string $secret = 'hidden';

    /** Readonly: not hydrated, extracted when initialized. */
    public readonly int $version;

    public function __construct()
    {
        $this->version = 1;
    }
}
