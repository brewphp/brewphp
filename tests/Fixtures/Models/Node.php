<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Fixtures\Models;

use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\ForeignKey;

/**
 * A tree, to test foreign keys to "self".
 */
final class Node extends BlogModel
{
    public static function fields(): array
    {
        return [
            'name' => new CharField(maxLength: 50),
            'parent' => new ForeignKey('self', relatedName: 'children', null: true, blank: true),
        ];
    }
}
