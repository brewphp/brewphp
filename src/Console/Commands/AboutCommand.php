<?php

declare(strict_types=1);

namespace BrewPHP\Console\Commands;

use BrewPHP\Console\Command;
use BrewPHP\Core\Application;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Db\Connection;

final class AboutCommand extends Command
{
    public string $name = 'about';

    public string $description = 'Show versions, environment and installed apps';

    protected function configure(): void
    {
        $this->addOption('json', 'Print the information as JSON');
    }

    public function handle(): int
    {
        $settings = $this->app->settings();
        $sections = [
            'Environment' => [
                'BrewPHP version' => Application::VERSION,
                'PHP version' => PHP_VERSION,
                'Operating system' => PHP_OS_FAMILY,
                'Project path' => $this->app->basePath(),
                'Debug mode' => $settings->bool('DEBUG') ? 'ON' : 'OFF',
                'Time zone' => $settings->string('TIME_ZONE'),
                'Language' => $settings->string('LANGUAGE'),
                'URLconf' => $settings->string('ROOT_URLCONF'),
                'Database' => $this->database(),
            ],
            'Installed apps' => [],
            'Extensions' => [
                'PDO drivers' => class_exists(\PDO::class) ? (implode(', ', \PDO::getAvailableDrivers()) ?: 'none') : 'PDO missing',
                'mbstring' => extension_loaded('mbstring') ? 'yes' : 'no',
                'intl' => extension_loaded('intl') ? 'yes' : 'no',
                'openssl' => extension_loaded('openssl') ? 'yes' : 'no',
                'sodium' => extension_loaded('sodium') ? 'yes' : 'no',
                'OPcache' => function_exists('opcache_get_status') && @opcache_get_status(false) !== false ? 'enabled' : 'off',
            ],
        ];
        foreach ($this->app->apps() as $label => $app) {
            $sections['Installed apps'][$label] = $app->name;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($sections as $title => $pairs) {
            $this->title($title);
            $this->definitionList($pairs === [] ? ['(none)' => ''] : $pairs);
            $this->line();
        }

        return self::SUCCESS;
    }

    /**
     * "sqlite (storage/db.sqlite3)"; does not connect.
     */
    private function database(): string
    {
        $config = $this->app->settings()->get('DATABASES.default');
        if (!is_array($config)) {
            return 'not configured';
        }
        try {
            $engine = Connection::engineOf($config);
        } catch (ImproperlyConfigured $e) {
            return 'invalid: ' . $e->getMessage();
        }
        $name = $config['NAME'] ?? '';

        return $engine . (is_string($name) && $name !== '' ? ' (' . $name . ')' : '');
    }
}
