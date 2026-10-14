<?php

declare(strict_types=1);

namespace BrewPHP\Console\Commands;

use BrewPHP\Console\Command;
use BrewPHP\Support\Str;

final class KeyGenerateCommand extends Command
{
    public string $name = 'key:generate';

    public string $description = 'Generate a new SECRET_KEY and write it to .env';

    public string $help = "Changing the key invalidates signed values such as sessions and\npassword-reset links, so you are asked before replacing an existing key.";

    protected function configure(): void
    {
        $this->addOption('show', 'Only print a new key, do not write it');
        $this->addOption('force', 'Replace an existing key without asking', shortcut: 'f');
    }

    public function handle(): int
    {
        $key = Str::random(50);
        if ($this->option('show')) {
            $this->line($key);

            return self::SUCCESS;
        }

        $file = $this->app->basePath('.env');
        if (!is_file($file)) {
            $this->error('No .env file found. Copy .env.example to .env first.');

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($file);
        $hasKey = preg_match('/^SECRET_KEY=(.*)$/m', $contents, $match) === 1;
        $current = $hasKey ? trim($match[1], " \t\"'") : '';

        if ($current !== '' && !$this->option('force')) {
            if (!$this->confirm('A SECRET_KEY already exists. Replace it? Sessions and signed links will stop working.')) {
                $this->warn('Kept the existing key. Use --force to replace it.');

                return $this->input->isInteractive() ? self::SUCCESS : self::FAILURE;
            }
        }

        $line = 'SECRET_KEY=' . $key;
        $contents = $hasKey
            ? (string) preg_replace('/^SECRET_KEY=.*$/m', $line, $contents, 1)
            : rtrim($contents) . ($contents !== '' ? "\n" : '') . $line . "\n";

        if (file_put_contents($file, $contents) === false) {
            $this->error(sprintf('Could not write %s.', $file));

            return self::FAILURE;
        }
        $this->app->settings()->set('SECRET_KEY', $key);
        $this->success('New SECRET_KEY written to .env.');

        return self::SUCCESS;
    }
}
