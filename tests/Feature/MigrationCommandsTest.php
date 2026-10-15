<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Feature;

use BrewPHP\Console\Output;
use BrewPHP\Core\Application;
use BrewPHP\Tests\TestCase;

final class MigrationCommandsTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        // Load the demo app's models (Tag and Note) from the original fixture first.
        $this->makeApp();
        $this->project = $this->copyProject();
    }

    /**
     * @param list<string> $arguments
     * @param array<string, mixed> $settings
     * @return array{0: int, 1: string}
     */
    private function manage(array $arguments, array $settings = [], string $answers = ''): array
    {
        $app = $this->makeApp([
            'DATABASES' => ['default' => ['ENGINE' => 'sqlite', 'NAME' => $this->project . '/storage/db.sqlite3']],
            ...$settings,
        ], $this->project);
        $output = Output::buffered();
        $stdin = fopen('php://memory', 'r+');
        self::assertIsResource($stdin);
        fwrite($stdin, $answers);
        rewind($stdin);
        $code = $app->console()->run(['manage.php', ...($answers === '' ? ['--no-interaction'] : []), ...$arguments], $output, $stdin);

        return [$code, $output->fetch()];
    }

    private function migrationFile(string $name): string
    {
        return $this->project . '/apps/demo/migrations/' . $name . '.php';
    }

    public function testMakeMigrationsAndMigrate(): void
    {
        [$code, $out] = $this->manage(['makemigrations', '--check']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Migrations for "demo":', $out);
        self::assertStringContainsString('apps/demo/migrations/0001_initial.php', $out);
        self::assertStringContainsString('- Create model Tag', $out);
        self::assertStringContainsString('- Create model Note', $out);
        self::assertFileDoesNotExist($this->migrationFile('0001_initial'));

        [$code, $out] = $this->manage(['makemigrations']);
        self::assertSame(0, $code, $out);
        self::assertFileExists($this->migrationFile('0001_initial'));
        $source = (string) file_get_contents($this->migrationFile('0001_initial'));
        self::assertStringContainsString("'tag' => new Fields\\ForeignKey(to: 'demo.Tag', null: true)", $source);

        self::assertSame([0, 'No changes detected.' . PHP_EOL], $this->manage(['makemigrations']));
        self::assertSame(0, $this->manage(['makemigrations', '--check'])[0]);

        [$code, $out] = $this->manage(['showmigrations']);
        self::assertSame(0, $code);
        self::assertStringContainsString("demo\n [ ] 0001_initial", str_replace("\r", '', $out));

        [$code, $out] = $this->manage(['migrate', '--plan']);
        self::assertSame(0, $code);
        self::assertStringContainsString('demo.0001_initial', $out);
        self::assertStringContainsString('    Create model Note', $out);

        [$code, $out] = $this->manage(['migrate']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Apply all migrations: demo', $out);
        self::assertStringContainsString('Applying demo.0001_initial... OK', $out);
        self::assertStringNotContainsString('not yet reflected', $out);
        $db = Application::getInstance()->db();
        self::assertTrue($db->hasTable('demo_tag'));
        self::assertTrue($db->hasTable('demo_note'));

        self::assertStringContainsString('[X] 0001_initial', $this->manage(['showmigrations'])[1]);
        self::assertStringContainsString('[X] demo.0001_initial', $this->manage(['showmigrations', '--plan'])[1]);
        self::assertStringContainsString('No migrations to apply.', $this->manage(['migrate'])[1]);

        [$code, $out] = $this->manage(['sqlmigrate', 'demo', '0001']);
        self::assertSame(0, $code);
        self::assertStringContainsString('CREATE TABLE "demo_note"', $out);

        [$code, $out] = $this->manage(['migrate', 'demo', 'zero']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Unapplying demo.0001_initial... OK', $out);
        self::assertFalse(Application::getInstance()->db()->hasTable('demo_note'));

        [$code, $out] = $this->manage(['makemigrations', 'demo', '--empty', '--name=fill_tags']);
        self::assertSame(0, $code, $out);
        $source = (string) file_get_contents($this->migrationFile('0002_fill_tags'));
        self::assertStringContainsString("'demo.0001_initial',", $source);
        self::assertStringContainsString('return [];', $source);
    }

    public function testConflictsAreMerged(): void
    {
        $this->manage(['makemigrations']);
        $this->manage(['makemigrations', 'demo', '--empty', '--name=a']);
        // A teammate wrote another 0002 on their branch.
        copy($this->migrationFile('0002_a'), $this->migrationFile('0002_b'));

        [$code, $out] = $this->manage(['migrate']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Conflicting migrations in app "demo": demo.0002_a and demo.0002_b. Run "php manage.php makemigrations --merge"', $out);
        self::assertSame(1, $this->manage(['makemigrations'])[0]);

        [$code, $out] = $this->manage(['makemigrations', '--merge']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('- Merge demo.0002_a, demo.0002_b', $out);
        $source = (string) file_get_contents($this->migrationFile('0003_merge_0002_a_0002_b'));
        self::assertStringContainsString("'demo.0002_a',\n        'demo.0002_b',", $source);
        self::assertSame([0, 'No conflicts detected to merge.' . PHP_EOL], $this->manage(['makemigrations', '--merge']));

        [$code, $out] = $this->manage(['migrate']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Applying demo.0003_merge_0002_a_0002_b... OK', $out);

        // Going backwards, the plan lists operations in the order they are undone.
        [, $out] = $this->manage(['migrate', 'demo', 'zero', '--plan']);
        self::assertMatchesRegularExpression('/demo\.0001_initial \(unapply\)\s+Undo create model Note\s+Undo create model Tag/', $out);
    }

    public function testUsageErrors(): void
    {
        [$code, $out] = $this->manage(['makemigrations', 'nope']);
        self::assertSame(2, $code);
        self::assertStringContainsString('No installed app with label "nope".', $out);

        [$code, $out] = $this->manage(['makemigrations', 'demo', '--name=../../evil']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Invalid migration name', $out);
        self::assertDirectoryDoesNotExist($this->project . '/apps/demo/migrations');

        self::assertStringContainsString('--empty needs the app', $this->manage(['makemigrations', '--empty'])[1]);

        [$code, $out] = $this->manage(['migrate', 'demo', '0001']);
        self::assertSame(1, $code);
        self::assertStringContainsString('App "demo" has no migrations.', $out);

        [$code, $out] = $this->manage(['sqlmigrate', 'demo', '0009']);
        self::assertSame(1, $code);
        self::assertStringContainsString('has no migration named "0009"', $out);
    }

    public function testMigrateWarnsAboutChangesWithoutMigrations(): void
    {
        [$code, $out] = $this->manage(['migrate']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Your models in app(s) demo have changes that are not yet reflected in a migration.', $out);
    }

    public function testInteractiveNotNullValue(): void
    {
        $this->manage(['makemigrations']);
        // Pretend the migration was written before "slug" existed.
        $file = $this->migrationFile('0001_initial');
        $source = (string) file_get_contents($file);
        $source = (string) preg_replace("/\\s*'slug' => new Fields\\\\SlugField\\(unique: true\\),/", '', $source);
        file_put_contents($file, $source);

        // Without interaction, makemigrations refuses to guess a value.
        [$code, $out] = $this->manage(['makemigrations']);
        self::assertSame(1, $code, $out);
        self::assertStringContainsString('Cannot add the non-nullable field "slug" to Tag without a default', $out);

        // Interactively, empty answers are asked again; the value fills existing rows only.
        [$code, $out] = $this->manage(['makemigrations'], answers: "\n\n'none'\n");
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('The new field Tag.slug cannot be null and has no default.', $out);
        $source = (string) file_get_contents($this->migrationFile('0002_tag_slug'));
        self::assertStringContainsString("new Operations\\AddField(model: 'Tag', name: 'slug', field: new Fields\\SlugField(default: 'none', unique: true), preserveDefault: false),", $source);
        self::assertSame([0, 'No changes detected.' . PHP_EOL], $this->manage(['makemigrations']));
    }

    protected function tearDown(): void
    {
        // Windows cannot delete the SQLite file while a connection holds it.
        if (Application::hasInstance()) {
            Application::getInstance()->db()->disconnect();
        }
        gc_collect_cycles();
        parent::tearDown();
    }

    public function testStartApp(): void
    {
        [$code, $out] = $this->manage(['startapp', 'blog_posts']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Created the app "blog_posts" in apps/blog_posts/', $out);
        foreach (['App.php', 'models.php', 'views.php', 'urls.php', 'templates/blog_posts/index.html', 'migrations/.gitkeep'] as $file) {
            self::assertFileExists($this->project . '/apps/blog_posts/' . $file);
        }
        self::assertStringContainsString('namespace Apps\\BlogPosts;', (string) file_get_contents($this->project . '/apps/blog_posts/App.php'));
        self::assertStringContainsString("public string \$verboseName = 'Blog Posts';", (string) file_get_contents($this->project . '/apps/blog_posts/App.php'));
        self::assertStringContainsString("render('blog_posts/index.html'", (string) file_get_contents($this->project . '/apps/blog_posts/views.php'));

        // The new app boots and its view is defined.
        $app = $this->makeApp(['INSTALLED_APPS' => ['demo', 'blog_posts']], $this->project);
        self::assertSame('Blog Posts', $app->apps()->get('blog_posts')->verboseName);
        self::assertTrue(function_exists('Apps\\BlogPosts\\index'));

        [$code, $out] = $this->manage(['startapp', 'blog_posts']);
        self::assertSame(1, $code);
        self::assertStringContainsString('apps/blog_posts already exists.', $out);
    }

    public function testStartAppRejectsBadLabels(): void
    {
        foreach (['../evil', 'Blog', '1blog', 'blog-posts', 'a/b', str_repeat('a', 51)] as $label) {
            [$code, $out] = $this->manage(['startapp', $label]);
            self::assertSame(2, $code, $label);
            self::assertStringContainsString('is not a valid app label', $out);
        }
        foreach (['auth', 'admin', 'demo'] as $label) {
            [$code, $out] = $this->manage(['startapp', $label]);
            self::assertSame(2, $code, $label);
            self::assertStringContainsString('is already used', $out);
        }
        self::assertDirectoryDoesNotExist(dirname($this->project) . '/evil');
    }
}
