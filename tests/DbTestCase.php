<?php

declare(strict_types=1);

namespace BrewPHP\Tests;

use BrewPHP\Core\Application;
use BrewPHP\Db\Connection;
use BrewPHP\Tests\Fixtures\Models\Author;
use BrewPHP\Tests\Fixtures\Models\Category;
use BrewPHP\Tests\Fixtures\Models\Comment;
use BrewPHP\Tests\Fixtures\Models\Item;
use BrewPHP\Tests\Fixtures\Models\Node;
use BrewPHP\Tests\Fixtures\Models\Post;
use BrewPHP\Tests\Fixtures\Models\Profile;

/**
 * Boots the fixture project on an in-memory SQLite database with the
 * blog test models' tables created by the schema editor.
 */
abstract class DbTestCase extends TestCase
{
    /** The models, in an order where every foreign key target comes first. */
    protected const MODELS = [Author::class, Category::class, Post::class, Comment::class, Profile::class, Node::class, Item::class];

    protected Application $app;

    protected function setUp(): void
    {
        $this->app = $this->makeApp(['DATABASES' => ['default' => ['ENGINE' => 'sqlite', 'NAME' => ':memory:']]]);
        $editor = $this->db()->schemaEditor();
        foreach (static::MODELS as $model) {
            $editor->createModel($model);
        }
        Post::$events = [];
    }

    protected function db(): Connection
    {
        return $this->app->db();
    }

    /**
     * How many queries a callback runs.
     */
    protected function countQueries(callable $callback): int
    {
        $this->db()->enableQueryLog();
        $this->db()->flushQueryLog();
        $callback();

        return count($this->db()->lastQueries());
    }

    protected function author(string $name = 'Ann', mixed ...$values): Author
    {
        return Author::create(['name' => $name, ...$values]);
    }

    protected function post(string $title, Author $author, mixed ...$values): Post
    {
        return Post::create(['title' => $title, 'author' => $author, ...$values]);
    }
}
