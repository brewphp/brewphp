<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Fixtures\Models;

use BrewPHP\Db\Fields\OneToOneField;
use BrewPHP\Db\Fields\TextField;

final class Profile extends BlogModel
{
    public static function fields(): array
    {
        return [
            'author' => new OneToOneField(Author::class, relatedName: 'profile'),
            'bio' => new TextField(blank: true),
        ];
    }
}
