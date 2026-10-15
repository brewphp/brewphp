<?php

declare(strict_types=1);

namespace BrewPHP\Db\Migrations;

use BrewPHP\Core\AppConfig;
use BrewPHP\Core\Apps;

/**
 * Reads the migration files of every installed app into a Graph.
 *
 * A migration file is `<app>/migrations/NNNN_name.php` and returns a
 * Migration object. Other files in the folder are ignored.
 */
final class Loader
{
    /** A migration file name: four digits, an underscore and a name. */
    public const FILE_PATTERN = '/^(\d{4})_([a-z0-9_]+)\.php$/';

    public function __construct(private readonly Apps $apps)
    {
    }

    public function graph(): Graph
    {
        $migrations = [];
        foreach ($this->apps as $app) {
            array_push($migrations, ...$this->load($app));
        }

        return new Graph($migrations);
    }

    /**
     * The migrations of one app, in file name order.
     *
     * @return list<Migration>
     */
    public function load(AppConfig $app): array
    {
        $migrations = [];
        foreach (self::files($app) as $name => $file) {
            $migration = (static fn (string $file): mixed => require $file)($file);
            if (!$migration instanceof Migration) {
                throw new MigrationError(sprintf('Migration file %s must return a %s object.', $file, Migration::class));
            }
            $migration->app = $app->label;
            $migration->name = $name;
            $migrations[] = $migration;
        }

        return $migrations;
    }

    /**
     * Migration files of an app, by migration name.
     *
     * @return array<string, string>
     */
    public static function files(AppConfig $app): array
    {
        $files = [];
        foreach (glob($app->migrationsPath() . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            if (preg_match(self::FILE_PATTERN, basename($file)) === 1) {
                $files[basename($file, '.php')] = $file;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * The number the next migration of an app gets: 1 after the highest.
     */
    public static function nextNumber(AppConfig $app): int
    {
        $highest = 0;
        foreach (array_keys(self::files($app)) as $name) {
            $highest = max($highest, (int) substr($name, 0, 4));
        }

        return $highest + 1;
    }
}
