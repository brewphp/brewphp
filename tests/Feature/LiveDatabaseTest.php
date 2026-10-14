<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Feature;

use BrewPHP\Core\Application;
use BrewPHP\Core\Settings;
use BrewPHP\Db\Connection;
use BrewPHP\Db\ConnectionManager;
use BrewPHP\Db\Count;
use BrewPHP\Db\F;
use BrewPHP\Db\ProtectedError;
use BrewPHP\Db\Q;
use BrewPHP\Db\Sum;
use BrewPHP\Tests\Fixtures\Models\Author;
use BrewPHP\Tests\Fixtures\Models\Category;
use BrewPHP\Tests\Fixtures\Models\Comment;
use BrewPHP\Tests\Fixtures\Models\Item;
use BrewPHP\Tests\Fixtures\Models\Node;
use BrewPHP\Tests\Fixtures\Models\Post;
use BrewPHP\Tests\Fixtures\Models\PostStatus;
use BrewPHP\Tests\Fixtures\Models\Profile;
use BrewPHP\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The ORM against real MySQL and PostgreSQL servers. Skipped unless the
 * environment provides a DSN, e.g.:
 *
 *     BREWPHP_TEST_MYSQL_DSN="mysql:host=127.0.0.1;dbname=brewphp_test"
 *     BREWPHP_TEST_MYSQL_USER=root BREWPHP_TEST_MYSQL_PASSWORD=secret
 *     BREWPHP_TEST_PGSQL_DSN="pgsql:host=127.0.0.1;dbname=brewphp_test"
 *
 * The test tables are dropped and created again, so use an empty database.
 */
final class LiveDatabaseTest extends TestCase
{
    private const MODELS = [Author::class, Category::class, Post::class, Comment::class, Profile::class, Node::class, Item::class];

    /**
     * @return array<string, array{0: string}>
     */
    public static function engines(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    private function connect(string $engine): Connection
    {
        $prefix = 'BREWPHP_TEST_' . strtoupper($engine) . '_';
        $dsn = getenv($prefix . 'DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped(sprintf('Set %sDSN (and %sUSER, %sPASSWORD) to run the %s tests.', $prefix, $prefix, $prefix, $engine));
        }
        $pdo = new \PDO($dsn, (string) getenv($prefix . 'USER'), (string) getenv($prefix . 'PASSWORD'));
        $connection = new Connection('default', ['ENGINE' => $engine], $pdo);

        $app = new Application(self::PROJECT, new Settings(['DATABASES' => ['default' => ['ENGINE' => $engine]]]));
        $manager = $app->get(ConnectionManager::class);
        $manager->set('default', $connection);

        foreach (array_reverse(self::MODELS) as $model) {
            try {
                $connection->statement('DROP TABLE ' . $connection->grammar()->quoteIdentifier($model::meta()->table) . ($engine === 'pgsql' ? ' CASCADE' : ''));
            } catch (\Throwable) {
                // The table did not exist.
            }
        }
        $editor = $connection->schemaEditor();
        foreach (self::MODELS as $model) {
            $editor->createModel($model);
        }

        return $connection;
    }

    #[DataProvider('engines')]
    public function testOrmRoundTrip(string $engine): void
    {
        $db = $this->connect($engine);

        $ann = Author::create(['name' => 'Ann', 'score' => 10]);
        $bob = Author::create(['name' => 'Bob', 'active' => false]);
        $php = Category::create(['name' => 'PHP', 'slug' => 'php']);
        $hello = Post::create(['title' => 'Hello PHP', 'author' => $ann, 'category' => $php, 'views' => 5, 'rating' => '4.5', 'metadata' => ['a' => 1]]);
        Post::create(['title' => 'Second', 'author' => $bob, 'status' => PostStatus::Published, 'published_at' => '2026-02-03 04:05:06']);
        Comment::create(['post' => $hello, 'author' => $bob, 'text' => 'Nice']);
        Item::create(['name' => 'Mug']);

        self::assertIsInt($ann->pk);
        self::assertSame(['Hello PHP'], Post::objects()->filter(title__icontains: 'php', author__name: 'Ann')->valuesList('title', flat: true));
        self::assertSame([], Post::objects()->filter(title__contains: 'php')->valuesList('title', flat: true));
        self::assertSame(['Second'], Post::objects()->filter(published_at__year: 2026)->valuesList('title', flat: true));
        self::assertSame(['Second'], Post::objects()->where(Q::not(new Q(author: $ann)))->valuesList('title', flat: true));
        self::assertSame(['Bob'], Author::objects()->filter(active: false)->valuesList('name', flat: true));
        self::assertSame(['Ann'], Author::objects()->filter(posts__category__slug: 'php')->distinct()->valuesList('name', flat: true));

        $loaded = Post::objects()->selectRelated('author', 'category')->get(pk: $hello->pk);
        self::assertSame('Ann', $loaded->author->name);
        self::assertSame('4.50', $loaded->rating);
        self::assertSame(['a' => 1], $loaded->metadata);
        self::assertSame(PostStatus::Draft, $loaded->status);

        Post::objects()->filter(author__name: 'Ann')->update(['views' => (new F('views'))->add(10)]);
        self::assertSame(['n' => 2, 'views' => 15], Post::objects()->aggregate(n: new Count('id'), views: new Sum('views')));
        self::assertSame(1, Post::objects()->orderBy('-views')->slice(1)->count());

        try {
            $php->delete();
            self::fail('ProtectedError expected');
        } catch (ProtectedError) {
        }
        self::assertSame(3, $ann->delete(), 'author, post, comment');
        self::assertSame(1, Post::objects()->count());

        $db->transaction(function () use ($bob): void {
            $bob->name = 'Robert';
            $bob->save();
        });
        self::assertSame('Robert', Author::objects()->get(pk: $bob->pk)->name);

        $editor = $db->schemaEditor();
        $editor->renameField(Category::class, Category::meta()->field('name'), 'title');
        self::assertSame(['PHP'], array_column($db->select('SELECT title FROM ' . $db->grammar()->quoteIdentifier('blog_category')), 'title'));
    }
}
