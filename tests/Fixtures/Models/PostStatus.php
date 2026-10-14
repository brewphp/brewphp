<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Fixtures\Models;

enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return $this === self::Draft ? 'Draft (hidden)' : 'Published';
    }
}
