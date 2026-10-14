<?php

declare(strict_types=1);

namespace Apps\Demo\Models;

use BrewPHP\Db\Fields\SlugField;
use BrewPHP\Db\Model;

final class Tag extends Model
{
    public static function fields(): array
    {
        return [
            'slug' => new SlugField(unique: true),
        ];
    }
}
