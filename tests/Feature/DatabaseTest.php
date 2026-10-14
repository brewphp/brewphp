<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Feature;

use BrewPHP\Console\Output;
use BrewPHP\Core\CheckMessage;
use BrewPHP\Core\SystemChecks;
use BrewPHP\Http\Http404;
use BrewPHP\Tests\DbTestCase;
use BrewPHP\Tests\Fixtures\Models\Post;

final class DatabaseTest extends DbTestCase
{
    public function testGetObjectOr404(): void
    {
        $post = $this->post('Hello', $this->author());

        self::assertSame($post->pk, get_object_or_404(Post::class, id: $post->pk)->pk);
        self::assertSame($post->pk, get_object_or_404(Post::objects()->filter(title: 'Hello'), pk: $post->pk)->pk);

        try {
            get_object_or_404(Post::class, id: 999);
            self::fail('Http404 expected');
        } catch (Http404 $e) {
            self::assertSame(404, $e->status());
            self::assertSame('No Post matches the given query.', $e->getMessage());
        }
    }

    public function testAboutShowsTheDatabase(): void
    {
        $output = Output::buffered();
        $this->app->console()->run(['manage.php', 'about'], $output);
        self::assertMatchesRegularExpression('/Database \.+ sqlite \(:memory:\)/', $output->fetch());

        $app = $this->makeApp(['DATABASES' => ['default' => ['ENGINE' => 'oracle']]]);
        $app->console()->run(['manage.php', 'about', '--json'], $output);
        self::assertStringContainsString('"Database": "invalid: Unknown database ENGINE', $output->fetch());

        $app = $this->makeApp(['DATABASES' => []]);
        $app->console()->run(['manage.php', 'about', '--json'], $output);
        self::assertStringContainsString('"Database": "not configured"', $output->fetch());
    }

    public function testSystemChecks(): void
    {
        $ids = function (array $databases): array {
            $app = $this->makeApp(['DATABASES' => $databases]);
            $messages = $app->get(SystemChecks::class)->run($app);

            return array_values(array_filter(array_map(static fn (CheckMessage $m): string => $m->id, $messages), static fn (string $id): bool => str_starts_with($id, 'db.')));
        };

        self::assertSame([], $ids(['default' => ['ENGINE' => 'sqlite', 'NAME' => ':memory:']]));
        self::assertSame(['db.E001'], $ids(['other' => ['ENGINE' => 'sqlite']]));
        self::assertSame(['db.E002', 'db.E002'], $ids(['default' => ['ENGINE' => 'oracle'], 'x' => 'sqlite']));
    }

    public function testDefaultSqliteFileIsRelativeToTheProject(): void
    {
        $base = $this->copyProject();
        $app = $this->makeApp(['DATABASES' => ['default' => ['ENGINE' => 'sqlite', 'NAME' => 'storage/test.sqlite3']]], $base);

        self::assertSame(1, $app->db()->scalar('SELECT 1'));
        self::assertFileExists($base . '/storage/test.sqlite3');
        $app->db()->disconnect();
    }
}
