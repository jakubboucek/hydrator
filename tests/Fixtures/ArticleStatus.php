<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
