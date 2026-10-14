<?php

declare(strict_types=1);

namespace Apps\Demo;

use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Model;

final class Note extends Model
{
    public static function fields(): array
    {
        return [
            'text' => new CharField(maxLength: 100),
            'tag' => new ForeignKey('Tag', null: true, relatedName: 'notes'),
        ];
    }
}
