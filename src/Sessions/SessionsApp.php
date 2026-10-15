<?php

declare(strict_types=1);

namespace BrewPHP\Sessions;

use BrewPHP\Core\AppConfig;

/**
 * The built-in `brewphp.sessions` app. Install it to keep sessions in the
 * database (SESSION_ENGINE 'database') and to get the `clearsessions`
 * command. The file store works without it.
 */
final class SessionsApp extends AppConfig
{
    public string $name = 'brewphp.sessions';

    public string $label = 'sessions';

    public string $verboseName = 'Sessions';

    /** @var list<string> */
    public array $modules = [];
}
