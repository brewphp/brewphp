<?php

declare(strict_types=1);

namespace BrewPHP\Console\Commands;

use BrewPHP\Console\Command;
use BrewPHP\Core\SystemChecks;

final class CheckCommand extends Command
{
    public string $name = 'check';

    public string $description = 'Check the project for common problems';

    public string $help = "Runs the system checks: settings, URLconf, folders and security.\n"
        . "Use --deploy before going live to also run the production checks.\n"
        . 'Exits with status 1 when any error is found.';

    protected function configure(): void
    {
        $this->addOption('deploy', 'Also run the checks for production deployments');
        $this->addOption('fail-level', 'Exit with an error at this level or worse: error or warning', value: true, default: 'error');
    }

    public function handle(): int
    {
        $messages = $this->app->get(SystemChecks::class)->run($this->app, (bool) $this->option('deploy'));
        $failOnWarnings = $this->option('fail-level') === 'warning';

        if ($messages === []) {
            $this->success('System check identified no issues.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($messages as $message) {
            $label = sprintf('[%s] %s', $message->id, $message->message);
            if ($message->isError()) {
                $this->error($label);
                $failed = true;
            } else {
                $this->warn($label);
                $failed = $failed || $failOnWarnings;
            }
            if ($message->hint !== null) {
                $this->comment('    HINT: ' . $message->hint);
            }
        }

        $this->line();
        $this->line(sprintf('System check identified %d issue%s.', count($messages), count($messages) === 1 ? '' : 's'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
