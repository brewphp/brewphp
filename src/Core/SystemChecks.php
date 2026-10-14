<?php

declare(strict_types=1);

namespace BrewPHP\Core;

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
        if (!in_array(\BrewPHP\Middleware\SecurityHeadersMiddleware::class, $settings->array('MIDDLEWARE'), true)) {
            $messages[] = CheckMessage::warning('security.W001', 'SecurityHeadersMiddleware is not in MIDDLEWARE, so security headers are not sent.');
        }

        return $messages;
    }
}
