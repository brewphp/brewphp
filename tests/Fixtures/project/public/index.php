<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload.php';

BrewPHP\Core\Application::create(dirname(__DIR__))->run();
