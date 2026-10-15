<?php

declare(strict_types=1);

namespace BrewPHP\Core;

use BrewPHP\Db\Connection;
use BrewPHP\Routing\Router;

/**
 * The system check framework behind `manage.php check`.
 *
 * A check is a callable that receives the application and returns a list
 * of CheckMessage objects. Deployment checks only run with `--deploy`.
 * Apps can add their own in `ready()`:
 *
 *     $app->get(SystemChecks::class)->register(function (Application $app): array {
 *         return [CheckMessage::warning('blog.W001', 'No posts yet.')];
 *     });
 */
final class SystemChecks
{
    /** @var list<array{check: \Closure(Application): list<CheckMessage>, deploy: bool}> */
    private array $checks = [];

    public static function withDefaults(): self
    {
        $checks = new self();
        $checks->register(self::checkSecretKey(...));
        $checks->register(self::checkStorage(...));
        $checks->register(self::checkUrls(...));
        $checks->register(self::checkTemplates(...));
        $checks->register(self::checkDatabases(...));
        $checks->register(self::checkDeploy(...), deploy: true);

        return $checks;
    }

    /**
     * @param callable(Application): list<CheckMessage> $check
     */
    public function register(callable $check, bool $deploy = false): void
    {
        $this->checks[] = ['check' => \Closure::fromCallable($check), 'deploy' => $deploy];
    }

    /**
     * @return list<CheckMessage>
     */
    public function run(Application $app, bool $deploy = false): array
    {
        $messages = [];
        foreach ($this->checks as $check) {
            if ($check['deploy'] && !$deploy) {
                continue;
            }
            array_push($messages, ...($check['check'])($app));
        }

        return $messages;
    }

    /**
     * @return list<CheckMessage>
     */
    private static function checkSecretKey(Application $app): array
    {
        if ($app->settings()->string('SECRET_KEY') === '') {
            return [CheckMessage::error(
                'security.E001',
                'The SECRET_KEY setting is empty.',
                'Run "php manage.php key:generate" to create one in .env.',
            )];
        }

        return [];
    }

    /**
     * @return list<CheckMessage>
     */
    private static function checkStorage(Application $app): array
    {
        $storage = $app->storagePath();
        if (!is_dir($storage)) {
            return [CheckMessage::error('core.E001', sprintf('The storage folder "%s" does not exist.', $storage), 'Create it, or fix the STORAGE_PATH setting.')];
        }
        if (!is_writable($storage)) {
            return [CheckMessage::error('core.E002', sprintf('The storage folder "%s" is not writable.', $storage), 'Give the web server user write access to it.')];
        }

        return [];
    }

    /**
     * @return list<CheckMessage>
     */
    private static function checkUrls(Application $app): array
    {
        try {
            $routes = $app->get(Router::class)->routes();
        } catch (\Throwable $e) {
            return [CheckMessage::error('urls.E001', 'The URLconf could not be loaded: ' . $e->getMessage())];
        }

        $messages = [];
        $seen = [];
        foreach ($routes as $route) {
            if ($route->name === null) {
                continue;
            }
            if (isset($seen[$route->name])) {
                $messages[] = CheckMessage::warning(
                    'urls.W001',
                    sprintf('The route name "%s" is used more than once; reverse() only finds the first one.', $route->name),
                );
            }
            $seen[$route->name] = true;
        }

        return $messages;
    }

    /**
     * @return list<CheckMessage>
     */
    private static function checkTemplates(Application $app): array
    {
        $messages = [];
        foreach ($app->settings()->array('TEMPLATES.DIRS') as $directory) {
            if (is_string($directory) && !is_dir($app->resolvePath($directory))) {
                $messages[] = CheckMessage::warning('templates.W001', sprintf('The template folder "%s" in TEMPLATES DIRS does not exist.', $directory));
            }
        }

        return $messages;
    }

    /**
     * Each DATABASES entry has a known ENGINE whose PDO driver is installed.
     * Nothing connects to the database.
     *
     * @return list<CheckMessage>
     */
    private static function checkDatabases(Application $app): array
    {
        $databases = $app->settings()->array('DATABASES');
        $messages = [];
        if (!isset($databases['default'])) {
            $messages[] = CheckMessage::error('db.E001', 'DATABASES has no "default" connection.', 'Models use the "default" database. Add it to DATABASES.');
        }
        foreach ($databases as $name => $config) {
            if (!is_array($config)) {
                $messages[] = CheckMessage::error('db.E002', sprintf('DATABASES["%s"] must be an array.', $name));

                continue;
            }
            try {
                /** @var array<string, mixed> $config */
                $engine = Connection::engineOf($config);
            } catch (ImproperlyConfigured $e) {
                $messages[] = CheckMessage::error('db.E002', sprintf('DATABASES["%s"]: %s', $name, $e->getMessage()));

                continue;
            }
            if (!in_array($engine, \PDO::getAvailableDrivers(), true)) {
                $messages[] = CheckMessage::error(
                    'db.E003',
                    sprintf('DATABASES["%s"] uses %s, but the PDO driver is not installed.', $name, $engine),
                    sprintf('Enable the pdo_%s PHP extension.', $engine),
                );
            }
        }

        return $messages;
    }

    /**
     * @return list<CheckMessage>
     */
    private static function checkDeploy(Application $app): array
    {
        $settings = $app->settings();
        $messages = [];

        if ($settings->bool('DEBUG')) {
            $messages[] = CheckMessage::warning('security.W018', 'DEBUG is on. Never run a production site with DEBUG = true.', 'Set DEBUG=false in the production .env.');
        }
        $key = $settings->string('SECRET_KEY');
        if ($key !== '' && (strlen($key) < 50 || count(array_unique(str_split($key))) < 5 || str_starts_with($key, 'insecure'))) {
            $messages[] = CheckMessage::warning('security.W009', 'SECRET_KEY is short or looks insecure.', 'Run "php manage.php key:generate" for a strong random key.');
        }
        if ($settings->array('ALLOWED_HOSTS') === []) {
            $messages[] = CheckMessage::warning('security.W020', 'ALLOWED_HOSTS is empty, so every request will be rejected.', 'List the domain names this site serves.');
        }
        if ((int) $settings->get('SECURE_HSTS_SECONDS', 0) <= 0) {
            $messages[] = CheckMessage::warning('security.W004', 'SECURE_HSTS_SECONDS is not set, so browsers are not told to always use HTTPS.', 'Set it (e.g. 31536000) once the whole site works over HTTPS.');
        }
        $middleware = $settings->array('MIDDLEWARE');
        if (!in_array(\BrewPHP\Middleware\SecurityHeadersMiddleware::class, $middleware, true)) {
            $messages[] = CheckMessage::warning('security.W001', 'SecurityHeadersMiddleware is not in MIDDLEWARE, so security headers are not sent.');
        }
        if (!in_array(\BrewPHP\Security\CsrfViewMiddleware::class, $middleware, true)) {
            $messages[] = CheckMessage::warning('security.W003', 'CsrfViewMiddleware is not in MIDDLEWARE, so forms are not protected against cross-site request forgery.', 'Add BrewPHP\Security\CsrfViewMiddleware to MIDDLEWARE.');
        }
        if (in_array(\BrewPHP\Sessions\SessionMiddleware::class, $middleware, true) && !$settings->bool('SESSION_COOKIE_SECURE')) {
            $messages[] = CheckMessage::warning('security.W012', 'SESSION_COOKIE_SECURE is off, so the session cookie can travel over plain HTTP.', 'Set SESSION_COOKIE_SECURE to true on an HTTPS site.');
        }
        if (in_array(\BrewPHP\Security\CsrfViewMiddleware::class, $middleware, true) && !$settings->bool('CSRF_COOKIE_SECURE') && !$settings->bool('CSRF_USE_SESSIONS')) {
            $messages[] = CheckMessage::warning('security.W016', 'CSRF_COOKIE_SECURE is off, so the CSRF cookie can travel over plain HTTP.', 'Set CSRF_COOKIE_SECURE to true on an HTTPS site.');
        }

        return $messages;
    }
}
