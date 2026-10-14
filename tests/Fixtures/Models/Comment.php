<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Fixtures\Models;

use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Fields\TextField;
use BrewPHP\Db\OnDelete;

final class Comment extends BlogModel
{
    public static function fields(): array
    {
        return [
            'post' => new ForeignKey(Post::class, relatedName: 'comments'),
            'author' => new ForeignKey(Author::class, onDelete: OnDelete::SetNull, null: true),
            'text' => new TextField(),
        ];
    }
}
