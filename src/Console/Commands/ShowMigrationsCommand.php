<?php

declare(strict_types=1);

namespace BrewPHP\Console\Commands;

use BrewPHP\Console\Command;
use BrewPHP\Db\Migrations\MigrationError;
use BrewPHP\Db\Migrations\Migrator;

final class ShowMigrationsCommand extends Command
{
    public string $name = 'showmigrations';

    public string $description = 'List migrations and whether they are applied';

    protected function configure(): void
    {
        $this->addArgument('app', 'Only these apps', array: true);
        $this->addOption('database', 'The connection from DATABASES (default: "default")', value: true);
        $this->addOption('plan', 'List every migration in the order migrate applies them');
    }

    public function handle(): int
    {
        /** @var list<string> $apps */
        $apps = $this->argument('app');
        $database = $this->option('database');
        try {
            $executor = (new Migrator($this->app, is_string($database) ? $database : null))->executor();
            $graph = $executor->graph();
            $applied = $executor->recorder()->applied();
        } catch (MigrationError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $mark = fn (string $key): string => isset($applied[$key]) ? $this->output->style('[X]', 'green') : '[ ]';

        if ($this->option('plan')) {
            foreach ($graph->order() as $key) {
                if ($apps === [] || in_array($graph->node($key)->app, $apps, true)) {
                    $this->line($mark($key) . ' ' . $key);
                }
            }

            return self::SUCCESS;
        }

        foreach ($apps === [] ? array_keys($this->app->apps()->all()) : $apps as $label) {
            if (!$this->app->apps()->has($label)) {
                $this->error(sprintf('No installed app with label "%s".', $label));

                return self::INVALID;
            }
            $this->line($this->output->style($label, 'bold'));
            $nodes = $graph->nodes($label);
            if ($nodes === []) {
                $this->comment(' (no migrations)');
            }
            foreach ($nodes as $key => $migration) {
                $this->line(' ' . $mark($key) . ' ' . $migration->name);
            }
        }

        return self::SUCCESS;
    }
}
