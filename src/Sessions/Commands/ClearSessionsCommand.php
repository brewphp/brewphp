<?php

declare(strict_types=1);

namespace BrewPHP\Sessions\Commands;

use BrewPHP\Console\Command;
use BrewPHP\Sessions\SessionMiddleware;

final class ClearSessionsCommand extends Command
{
    public string $name = 'clearsessions';

    public string $description = 'Delete expired sessions (run it from cron)';

    public function handle(): int
    {
        $deleted = SessionMiddleware::store($this->app)->gc();
        $this->success(sprintf('Deleted %d expired session%s.', $deleted, $deleted === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
