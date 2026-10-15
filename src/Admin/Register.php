<?php

declare(strict_types=1);

namespace BrewPHP\Admin;

use BrewPHP\Db\Model;

/**
 * Registers a ModelAdmin for one or more models, like Django's
 * `@admin.register`. Put it in your app's `admin.php`:
 *
 *     #[Register(Post::class)]
 *     final class PostAdmin extends ModelAdmin
 *     {
 *         public array $listDisplay = ['title', 'author', 'published'];
 *     }
 *
 * The admin app finds every such class after loading the `admin.php` files.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Register
{
    /** @var list<class-string<Model>> */
    public readonly array $models;

    /**
     * @param class-string<Model> ...$models
     */
    public function __construct(string ...$models)
    {
        $this->models = array_values($models);
    }
}
