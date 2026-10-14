<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Fixtures\Models;

use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\TimeField;
use BrewPHP\Db\Fields\UUIDField;

/**
 * A model with a UUID primary key.
 */
final class Item extends BlogModel
{
    public static function fields(): array
    {
        return [
            'uuid' => new UUIDField(primaryKey: true, default: UUIDField::uuid4(...)),
            'name' => new CharField(maxLength: 50, unique: true),
            'opens_at' => new TimeField(null: true),
        ];
    }

    public static function table(): string
    {
        return 'inventory_items';
    }
}
