<?php

declare(strict_types=1);

namespace BrewPHP\Console\Commands;

use BrewPHP\Console\Command;
use BrewPHP\Console\Stub;
use BrewPHP\Support\Str;

final class MakeCommandCommand extends Command
{
    public string $name = 'make:command';

    public string $description = 'Create a new manage.php command in an app';

    protected function configure(): void
    {
        $this->addArgument('class', 'Class name, e.g. SendReport', required: true);
        $this->addOption('app', 'The app to put the command in', value: true);
        $this->addOption('command', 'The command name (default: derived from the class)', value: true);
        $this->addOption('force', 'Overwrite the file if it exists', shortcut: 'f');
    }

    public function handle(): int
    {
        $class = Str::studly((string) $this->argument('class'));
        if (!str_ends_with($class, 'Command')) {
            $class .= 'Command';
        }
        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $class)) {
            $this->error(sprintf('"%s" is not a valid class name.', $class));

            return self::INVALID;
        }

        $label = $this->pickApp();
        if ($label === null) {
            return self::INVALID;
        }
        $app = $this->app->apps()->get($label);

        $command = (string) ($this->option('command') ?? Str::kebab(substr($class, 0, -strlen('Command'))));
        $file = $app->commandsPath() . DIRECTORY_SEPARATOR . $class . '.php';

        $written = Stub::write($this->app, 'command', $file, [
            'namespace' => $app->namespace . '\\Commands',
            'class' => $class,
            'command' => $command,
        ], (bool) $this->option('force'));

        if (!$written) {
            $this->error(sprintf('%s already exists. Use --force to overwrite it.', $this->relative($file)));

            return self::FAILURE;
        }

        $this->success(sprintf('Created %s', $this->relative($file)));
        $this->comment(sprintf('  Run it with: php manage.php %s', $command));

        return self::SUCCESS;
    }

    private function pickApp(): ?string
    {
        $labels = array_keys($this->app->apps()->all());
        $chosen = $this->option('app');

        if (is_string($chosen)) {
            if (!in_array($chosen, $labels, true)) {
                $this->error(sprintf('No installed app named "%s". Installed: %s', $chosen, implode(', ', $labels) ?: 'none'));

                return null;
            }

            return $chosen;
        }
        if (count($labels) === 1) {
            return $labels[0];
        }
        if ($labels === []) {
            $this->error('No apps are installed. Add one to INSTALLED_APPS first.');

            return null;
        }

        $chosen = $this->choice('Which app should the command go in?', $labels);
        if ($chosen === null) {
            $this->error('Pass --app=<label> to choose the app.');
        }

        return $chosen;
    }
}
