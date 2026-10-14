<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Fixtures\Models;

use BrewPHP\Db\Fields\BooleanField;
use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\DateTimeField;
use BrewPHP\Db\Fields\EmailField;
use BrewPHP\Db\Fields\IntegerField;

final class Author extends BlogModel
{
    public static function fields(): array
    {
        return [
            'name' => new CharField(maxLength: 100),
            'email' => new EmailField(blank: true),
            'active' => new BooleanField(default: true),
            'score' => new IntegerField(default: 0),
            'created_at' => new DateTimeField(autoNowAdd: true),
        ];
    }

    public static function ordering(): array
    {
        return ['name'];
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
