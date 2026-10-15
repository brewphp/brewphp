<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Db\Migrations;

use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\IntegerField;
use BrewPHP\Db\Index;
use BrewPHP\Db\Migrations\Autodetector;
use BrewPHP\Db\Migrations\Migration;
use BrewPHP\Db\Migrations\MigrationError;
use BrewPHP\Db\Migrations\Migrator;
use BrewPHP\Db\Migrations\Operations\AddField;
use BrewPHP\Db\Migrations\Operations\AddIndex;
use BrewPHP\Db\Migrations\Operations\RunSQL;
use BrewPHP\Db\Migrations\ProjectState;
use BrewPHP\Db\Migrations\Writer;
use BrewPHP\Db\OnDelete;
use BrewPHP\Tests\Fixtures\Models\Author;
use BrewPHP\Tests\Fixtures\Models\Category;
use BrewPHP\Tests\Fixtures\Models\Comment;
use BrewPHP\Tests\Fixtures\Models\Item;
use BrewPHP\Tests\Fixtures\Models\Node;
use BrewPHP\Tests\Fixtures\Models\Post;
use BrewPHP\Tests\Fixtures\Models\Profile;

final class WriterTest extends MigrationTestCase
{
    private const MODELS = [Author::class, Category::class, Post::class, Comment::class, Profile::class, Node::class, Item::class];

    private function load(string $source): Migration
    {
        $file = $this->tempDir() . DIRECTORY_SEPARATOR . '0001_initial.php';
        file_put_contents($file, $source);
        $migration = require $file;
        self::assertInstanceOf(Migration::class, $migration);
        $migration->app = 'blog';
        $migration->name = '0001_initial';

        return $migration;
    }

    public function testInitialMigrationRoundTrips(): void
    {
        $current = ProjectState::fromModels(self::MODELS);
        $changes = (new Autodetector(new ProjectState(), $current))->changes();
        $source = (new Writer())->write($changes['blog']['operations'], [], initial: true);

        self::assertStringContainsString('public bool $initial = true;', $source);
        self::assertStringContainsString("new Operations\\CreateModel(\n", $source);
        self::assertStringContainsString("'author' => new Fields\\ForeignKey(to: 'blog.Author')", $source);
        self::assertStringContainsString("'parent' => new Fields\\ForeignKey(to: 'blog.Node', null: true)", $source);
        self::assertStringContainsString("new Index(fields: ['author', 'slug'], unique: true)", $source);
        self::assertStringNotContainsString('relatedName', $source);
        self::assertStringNotContainsString('onDelete', $source);

        // Loading the file gives the same state: nothing left to migrate.
        $state = new ProjectState();
        $this->load($source)->mutateState($state);
        self::assertSame([], (new Autodetector($state, $current))->changes());
    }

    public function testValuesCannotBreakOutOfTheirLiteral(): void
    {
        $evil = "x'); system('echo pwned'); //\n?><?php exit; \\";
        $operation = new AddField('Post', 'note', new CharField(maxLength: 200, default: $evil));
        $source = (new Writer())->write([$operation], ['blog.0001_initial']);

        $loaded = $this->load($source)->operations()[0];
        self::assertInstanceOf(AddField::class, $loaded);
        self::assertSame($evil, $loaded->field->default);
    }

    public function testExport(): void
    {
        self::assertSame("['a' => 1, 'b' => [true, null, 1.5]]", Writer::export(['a' => 1, 'b' => [true, null, 1.5]]));
        self::assertSame('\\' . OnDelete::class . '::Cascade', Writer::export(OnDelete::Cascade));
        self::assertSame('new \\BrewPHP\\Db\\Fields\\IntegerField(default: 0)', Writer::export(new IntegerField(default: 0)));
        self::assertSame("new \\BrewPHP\\Db\\Index(fields: ['a'], name: 'a_idx')", Writer::export(new Index(['a'], name: 'a_idx')));

        $this->expectException(MigrationError::class);
        Writer::export(new \stdClass());
    }

    public function testOperationsWithoutArgumentsCannotBeWritten(): void
    {
        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('cannot be written to a migration file');
        (new Writer())->write([new RunSQL('SELECT 1')]);
    }

    public function testSuggestedNames(): void
    {
        self::assertSame('initial', Migrator::suggestName([], initial: true));
        self::assertSame('post_note', Migrator::suggestName([new AddField('Post', 'note', new CharField())]));
        self::assertSame('post_note_post_title_slug_uniq', Migrator::suggestName([
            new AddField('Post', 'note', new CharField()),
            new AddIndex('Post', new Index(['title', 'slug'], unique: true)),
        ]));
        self::assertStringStartsWith('auto_', Migrator::suggestName([new RunSQL('SELECT 1')]));
    }
}
