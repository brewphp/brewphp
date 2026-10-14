<?php

declare(strict_types=1);

namespace Apps\Demo\Commands;

use BrewPHP\Console\Command;

final class HelloCommand extends Command
{
    public string $name = 'demo:hello';

    public string $description = 'Say hello from the demo app';

    protected function configure(): void
    {
        $this->addArgument('who', 'Who to greet', default: 'world');
        $this->addOption('shout', 'Use capitals', shortcut: 's');
        $this->addOption('times', 'How many times', value: true, default: '1');
    }

    public function handle(): int
    {
        $text = 'Hello, ' . $this->argument('who');
        for ($i = 0; $i < (int) $this->option('times'); $i++) {
            $this->line($this->option('shout') ? strtoupper($text) : $text);
        }

        return self::SUCCESS;
    }
}
