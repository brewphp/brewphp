<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Fixtures\Models;

use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\SlugField;

final class Category extends BlogModel
{
    public static function fields(): array
    {
        return [
            'name' => new CharField(maxLength: 50),
            'slug' => new SlugField(unique: true),
        ];
    }

    public static function verboseNamePlural(): string
    {
        return 'categories';
    }
}
