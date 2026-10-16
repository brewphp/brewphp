<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Feature;

use BrewPHP\Admin\Models\LogEntry;
use BrewPHP\Auth\Auth;
use BrewPHP\Auth\Models\Group;
use BrewPHP\Auth\Models\Permission;
use BrewPHP\Auth\Models\User;
use BrewPHP\Core\Application;
use BrewPHP\Core\Settings;
use BrewPHP\Db\Connection;
use BrewPHP\Db\ConnectionManager;
use BrewPHP\Db\Fields\BigAutoField;
use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Fields\IntegerField;
use BrewPHP\Db\Index;
use BrewPHP\Db\Migrations\Executor;
use BrewPHP\Db\Migrations\Graph;
use BrewPHP\Db\Migrations\Migration;
use BrewPHP\Db\Migrations\Migrator;
use BrewPHP\Db\Migrations\Operations;
use BrewPHP\Db\Migrations\Recorder;
use BrewPHP\Sessions\DatabaseSessionStore;
use BrewPHP\Sessions\Session;
use BrewPHP\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Migrations, the built-in apps and database sessions against real MySQL
 * and PostgreSQL servers. Skipped unless the environment provides a DSN
 * (see LiveDatabaseTest). Every table of the database is dropped first,
 * so use an empty database made for the tests.
 */
final class LiveMigrationsTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function engines(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    private Connection $db;

    private function boot(string $engine): Application
    {
        $prefix = 'BREWPHP_TEST_' . strtoupper($engine) . '_';
        $dsn = getenv($prefix . 'DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped(sprintf('Set %sDSN (and %sUSER, %sPASSWORD) to run the %s tests.', $prefix, $prefix, $prefix, $engine));
        }
        $pdo = new \PDO($dsn, (string) getenv($prefix . 'USER'), (string) getenv($prefix . 'PASSWORD'));
        self::dropEverything($pdo, $engine);
        $this->db = new Connection('default', ['ENGINE' => $engine], $pdo);

        $app = new Application(self::PROJECT, new Settings([
            'SECRET_KEY' => 'live-test-secret-key-that-is-long-enough-0123456789abcdef',
            'INSTALLED_APPS' => ['demo', 'brewphp.auth', 'brewphp.sessions', 'brewphp.admin'],
            'DATABASES' => ['default' => ['ENGINE' => $engine]],
            'PASSWORD_HASHER' => 'bcrypt',
            'PASSWORD_HASHER_OPTIONS' => ['cost' => 4],
            'STORAGE_PATH' => $this->tempDir(),
        ]));
        $app->get(ConnectionManager::class)->set('default', $this->db);
        $app->boot();

        return $app;
    }

    private static function dropEverything(\PDO $pdo, string $engine): void
    {
        if ($engine === 'pgsql') {
            $pdo->exec('DROP SCHEMA public CASCADE');
            $pdo->exec('CREATE SCHEMA public');

            return;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')?->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @param list<Operations\Operation> $operations
     * @param list<string> $dependencies
     */
    private static function migration(string $app, string $name, array $operations, array $dependencies = []): Migration
    {
        $migration = new class ($operations) extends Migration {
            /**
             * @param list<Operations\Operation> $ops
             */
            public function __construct(private readonly array $ops)
            {
            }

            public function operations(): array
            {
                return $this->ops;
            }
        };
        $migration->app = $app;
        $migration->name = $name;
        $migration->dependencies = $dependencies;

        return $migration;
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        $grammar = $this->db->grammar();
        $sql = $grammar->name() === 'mysql'
            ? 'SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position'
            : "SELECT column_name AS c FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position";

        return array_map(static fn (array $row): string => (string) ($row['c'] ?? $row['C'] ?? ''), $this->db->select($sql, [$table]));
    }

    #[DataProvider('engines')]
    public function testSchemaOperationsForwardsAndBackwards(string $engine): void
    {
        $this->boot($engine);
        $migrations = [
            self::migration('shop', '0001_initial', [
                new Operations\CreateModel('Category', ['id' => new BigAutoField(), 'name' => new CharField(maxLength: 50, unique: true)], 'shop_category'),
                new Operations\CreateModel('Product', [
                    'id' => new BigAutoField(),
                    'title' => new CharField(maxLength: 100),
                    'category' => new ForeignKey('shop.Category'),
                ], 'shop_product', [new Index(['category', 'title'], unique: true)]),
            ]),
            self::migration('shop', '0002_product_changes', [
                new Operations\AddField('Product', 'stock', new IntegerField(default: 7)),
                // A one-off value for existing rows only.
                new Operations\AddField('Product', 'sku', new CharField(maxLength: 20, default: 'n/a'), preserveDefault: false),
                new Operations\RenameField('Product', 'title', 'name'),
                new Operations\AlterField('Product', 'name', new CharField(maxLength: 200, null: true)),
                new Operations\AddIndex('Product', new Index(['stock'])),
            ], ['shop.0001_initial']),
            self::migration('shop', '0003_more', [
                new Operations\RemoveIndex('Product', new Index(['stock'])),
                new Operations\RemoveField('Product', 'stock'),
                new Operations\AlterModelTable('Product', 'shop_products'),
            ], ['shop.0002_product_changes']),
        ];
        $executor = new Executor($this->db, new Graph($migrations), new Recorder($this->db));

        $executor->migrate($executor->plan('shop', '0001'));
        $this->db->insert('shop_category', ['name' => 'Tea']);
        $categoryId = $this->db->scalar('SELECT id FROM shop_category');
        $this->db->insert('shop_product', ['title' => 'Assam', 'category_id' => $categoryId]);

        $executor->migrate($executor->plan('shop', '0002'));
        self::assertSame(['id', 'name', 'category_id', 'stock', 'sku'], $this->columns('shop_product'));
        $row = $this->db->selectOne('SELECT name, stock, sku FROM shop_product');
        self::assertSame(['Assam', 7, 'n/a'], [$row['name'] ?? null, (int) ($row['stock'] ?? 0), $row['sku'] ?? null]);

        $executor->migrate($executor->plan());
        self::assertTrue($this->db->hasTable('shop_products'));
        self::assertSame(['id', 'name', 'category_id', 'sku'], $this->columns('shop_products'));

        // Back to 0001: the table, column and indexes return to their first shape.
        $executor->migrate($executor->plan('shop', '0001'));
        self::assertSame(['id', 'title', 'category_id'], $this->columns('shop_product'));
        $executor->migrate($executor->plan('shop', 'zero'));
        self::assertFalse($this->db->hasTable('shop_product'));
        self::assertFalse($this->db->hasTable('shop_category'));
        self::assertSame([], $executor->recorder()->applied());

        // The SQL of every migration can be shown in this dialect.
        foreach (array_keys((new Graph($migrations))->nodes()) as $key) {
            self::assertNotSame([], $executor->collectSql($key));
            self::assertNotSame([], $executor->collectSql($key, backwards: true));
        }
    }

    #[DataProvider('engines')]
    public function testBuiltInAppsAuthAndSessions(string $engine): void
    {
        $app = $this->boot($engine);
        $migrator = new Migrator($app);
        $executor = $migrator->executor();
        $executor->migrate($executor->plan());
        $migrator->runMigratedHooks();
        self::assertSame([], $migrator->changes(['auth', 'sessions', 'admin']), 'The built-in migrations match the models.');

        foreach (['auth_user', 'auth_group', 'auth_permission', 'auth_user_groups', 'auth_group_permissions', 'auth_user_permissions', 'sessions_session', 'admin_log_entry'] as $table) {
            self::assertTrue($this->db->hasTable($table), $table);
        }
        self::assertTrue(Permission::objects()->filter(app_label: 'demo', codename: 'add_tag')->exists());
        $created = \BrewPHP\Auth\AuthApp::createPermissions($app);
        self::assertSame(0, $created, 'Running the hook again creates nothing.');

        $ann = User::createUser('ann', 'ann@example.com', 'a long passphrase');
        self::assertTrue(Auth::authenticate(null, ['username' => 'ann', 'password' => 'a long passphrase']) instanceof User);
        self::assertNull(Auth::authenticate(null, ['username' => 'ann', 'password' => 'wrong']));

        $editors = Group::create(['name' => 'Editors']);
        $editors->grant('demo.add_tag', 'demo.change_tag');
        $ann->addToGroup($editors);
        $ann->grant('demo.view_note');
        $fresh = User::objects()->get(pk: $ann->pk);
        self::assertSame(['demo.add_tag', 'demo.change_tag', 'demo.view_note'], $fresh->getAllPermissions());
        self::assertTrue($fresh->hasModulePerms('demo'));
        self::assertSame(['Editors'], $fresh->groups()->valuesList('name', flat: true));
        $fresh->setGroups([]);
        self::assertSame(['demo.view_note'], User::objects()->get(pk: $ann->pk)->getAllPermissions());

        $entry = LogEntry::log($fresh, $editors, LogEntry::CHANGE, 'Changed name.');
        self::assertSame('auth.group', LogEntry::objects()->get(pk: $entry->pk)->content_type);

        $store = new DatabaseSessionStore($this->db);
        $session = new Session($store);
        $session->set('cart', [1, 2, 'é']);
        $id = $session->save();
        self::assertSame([1, 2, 'é'], (new Session($store, $id))->get('cart'));
        $store->write($id, ['cart' => [3]], 60);
        self::assertSame(['cart' => [3]], $store->read($id));
        $store->write(Session::generateId(), [], -10);
        self::assertSame(1, $store->gc());

        // Unapplying auth also unapplies the admin, which depends on it.
        $executor->migrate($executor->plan('auth', 'zero'));
        self::assertFalse($this->db->hasTable('auth_user'));
        self::assertFalse($this->db->hasTable('admin_log_entry'));
        self::assertTrue($this->db->hasTable('sessions_session'));
    }
}
