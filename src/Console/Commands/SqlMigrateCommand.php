<?php

declare(strict_types=1);

namespace BrewPHP\Console\Commands;

use BrewPHP\Console\Command;
use BrewPHP\Db\Migrations\MigrationError;
use BrewPHP\Db\Migrations\Migrator;

final class SqlMigrateCommand extends Command
{
    public string $name = 'sqlmigrate';

    public string $description = 'Print the SQL of a migration without running it';

    protected function configure(): void
    {
        $this->addArgument('app', 'The app label', required: true);
        $this->addArgument('migration', 'The migration name or prefix, e.g. 0001', required: true);
        $this->addOption('database', 'The connection whose SQL dialect to use (default: "default")', value: true);
        $this->addOption('backwards', 'Print the SQL that unapplies the migration');
    }

    public function handle(): int
    {
        $app = (string) $this->argument('app');
        $database = $this->option('database');
        try {
            $executor = (new Migrator($this->app, is_string($database) ? $database : null))->executor();
            $key = $executor->graph()->find($app, (string) $this->argument('migration'));
            $statements = $executor->collectSql($key, (bool) $this->option('backwards'));
        } catch (MigrationError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach ($statements as $sql) {
            $this->line(str_starts_with($sql, '--') ? $this->output->style($sql, 'gray') : $sql . ';');
        }

        return self::SUCCESS;
    }
}
