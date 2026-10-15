<?php

declare(strict_types=1);

namespace BrewPHP\Auth;

use BrewPHP\Auth\Models\Permission;
use BrewPHP\Core\AppConfig;
use BrewPHP\Core\Application;
use BrewPHP\Core\CheckMessage;
use BrewPHP\Core\SystemChecks;
use BrewPHP\Db\Registry;
use BrewPHP\Sessions\SessionMiddleware;

/**
 * The built-in `brewphp.auth` app: users, groups and permissions.
 *
 * After every `migrate` it creates the add, change, delete and view
 * permissions of each model of the installed apps (like Django).
 */
final class AuthApp extends AppConfig
{
    public string $name = 'brewphp.auth';

    public string $label = 'auth';

    public string $verboseName = 'Authentication and authorization';

    /** @var list<string> */
    public array $modules = [];

    public const DEFAULT_ACTIONS = ['add', 'change', 'delete', 'view'];

    public function ready(Application $app): void
    {
        $app->get(SystemChecks::class)->register(self::checkMiddleware(...));
    }

    public function migrated(Application $app): void
    {
        if (!$app->db()->hasTable(Permission::meta()->table)) {
            return;
        }
        self::createPermissions($app);
    }

    /**
     * Create the default permissions that are missing. Returns how many
     * were created.
     */
    public static function createPermissions(Application $app): int
    {
        $existing = [];
        foreach (Permission::objects()->valuesList('app_label', 'codename') as $row) {
            if (is_array($row)) {
                $existing[$row[0] . '.' . $row[1]] = true;
            }
        }
        $created = 0;
        foreach (array_keys($app->apps()->all()) as $label) {
            foreach (Registry::models($label) as $model) {
                $meta = $model::meta();
                foreach (self::DEFAULT_ACTIONS as $action) {
                    $codename = $action . '_' . strtolower($meta->modelName);
                    if (!isset($existing[$label . '.' . $codename])) {
                        Permission::create([
                            'name' => sprintf('Can %s %s', $action, $meta->verboseName),
                            'app_label' => $label,
                            'codename' => $codename,
                        ]);
                        $created++;
                    }
                }
            }
        }

        return $created;
    }

    /**
     * @return list<CheckMessage>
     */
    private static function checkMiddleware(Application $app): array
    {
        $middleware = array_values(array_filter($app->settings()->array('MIDDLEWARE'), 'is_string'));
        $session = array_search(SessionMiddleware::class, $middleware, true);
        $auth = array_search(AuthenticationMiddleware::class, $middleware, true);
        if ($auth === false) {
            return [CheckMessage::warning(
                'auth.W001',
                'brewphp.auth is installed but AuthenticationMiddleware is not in MIDDLEWARE.',
                'Add BrewPHP\Auth\AuthenticationMiddleware after BrewPHP\Sessions\SessionMiddleware.',
            )];
        }
        if ($session === false || $session > $auth) {
            return [CheckMessage::error(
                'auth.E001',
                'AuthenticationMiddleware must come after SessionMiddleware in MIDDLEWARE.',
            )];
        }

        return [];
    }
}
