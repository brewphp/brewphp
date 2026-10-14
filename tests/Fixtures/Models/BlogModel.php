<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Fixtures\Models;

use BrewPHP\Db\Model;

/**
 * Test models live outside an installed app, so they name their app.
 */
abstract class BlogModel extends Model
{
    public static function appLabel(): string
    {
        return 'blog';
    }
}
