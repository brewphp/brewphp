<?php

declare(strict_types=1);

namespace BrewPHP\Tests;

use BrewPHP\Auth\AuthenticationMiddleware;
use BrewPHP\Auth\Models\User;
use BrewPHP\Core\Application;
use BrewPHP\Db\Migrations\Migrator;
use BrewPHP\Middleware\SecurityHeadersMiddleware;
use BrewPHP\Security\CsrfViewMiddleware;
use BrewPHP\Sessions\ArraySessionStore;
use BrewPHP\Sessions\SessionMiddleware;
use BrewPHP\Templates\ContextProcessors;
use BrewPHP\Testing\Client;

/**
 * Boots the fixture project with sessions, CSRF protection and auth on an
 * in-memory SQLite database, migrated with the built-in migrations.
 */
abstract class AuthTestCase extends TestCase
{
    protected Application $app;

    protected Client $client;

    /**
     * @return array<string, mixed>
     */
    protected function settings(): array
    {
        return [
            'DEBUG' => false,
            'ALLOWED_HOSTS' => ['localhost', 'example.com'],
            'INSTALLED_APPS' => ['demo', 'brewphp.auth', 'brewphp.sessions'],
            'MIDDLEWARE' => [SecurityHeadersMiddleware::class, SessionMiddleware::class, CsrfViewMiddleware::class, AuthenticationMiddleware::class],
            'ROOT_URLCONF' => 'config/auth_urls.php',
            'DATABASES' => ['default' => ['ENGINE' => 'sqlite', 'NAME' => ':memory:']],
            'SESSION_ENGINE' => 'array',
            'SESSION_GC_PROBABILITY' => 0,
            'STORAGE_PATH' => $this->tempDir(),
            'PASSWORD_HASHER' => 'bcrypt',
            'PASSWORD_HASHER_OPTIONS' => ['cost' => 4],
            'TEMPLATES' => [
                'DIRS' => ['templates'],
                'CONTEXT_PROCESSORS' => [
                    [ContextProcessors::class, 'request'],
                    [ContextProcessors::class, 'auth'],
                    [ContextProcessors::class, 'messages'],
                ],
            ],
        ];
    }

    protected function setUp(): void
    {
        ArraySessionStore::reset();
        $this->app = $this->makeApp($this->settings());
        $migrator = new Migrator($this->app);
        $executor = $migrator->executor();
        $executor->migrate($executor->plan());
        $migrator->runMigratedHooks();
        $this->client = new Client($this->app);
    }

    protected function user(string $username = 'ann', string $password = 'correct horse battery', mixed ...$extra): User
    {
        return User::createUser($username, $username . '@example.com', $password, $extra);
    }
}
