<?php

declare(strict_types=1);

namespace Apps\Demo;

use BrewPHP\Core\AppConfig;
use BrewPHP\Core\Application;

final class App extends AppConfig
{
    public string $verboseName = 'Demo app';

    public static bool $ready = false;

    public function ready(Application $app): void
    {
        self::$ready = true;
    }
}
