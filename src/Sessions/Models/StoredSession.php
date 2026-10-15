<?php

declare(strict_types=1);

namespace BrewPHP\Sessions\Models;

use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\DateTimeField;
use BrewPHP\Db\Fields\TextField;
use BrewPHP\Db\Model;

/**
 * A session row of the database store (table `sessions_session`).
 */
final class StoredSession extends Model
{
    public static function fields(): array
    {
        return [
            'session_key' => new CharField(maxLength: 40, primaryKey: true),
            'session_data' => new TextField(),
            'expire_date' => new DateTimeField(dbIndex: true),
        ];
    }

    public static function table(): string
    {
        return 'sessions_session';
    }

    public static function verboseName(): string
    {
        return 'session';
    }
}
