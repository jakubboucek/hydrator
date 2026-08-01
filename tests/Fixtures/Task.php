<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Entity;
use JakubBoucek\Hydrator\Tests\Fixtures\ThirdParty\PlainDate;

class Task implements Entity
{
    public int $id;
    public DeadlineValue $dueAt;       // DateTimeValue over DATETIME
    public DurationValue $estimate;    // IntervalValue over TIME
    public PlainDate $publishedOn;     // foreign date type via TypeAdapter
}
