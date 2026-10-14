<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Fixtures\Models;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\DateTimeField;
use BrewPHP\Db\Fields\DecimalField;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Fields\JsonField;
use BrewPHP\Db\Fields\PositiveIntegerField;
use BrewPHP\Db\Fields\SlugField;
use BrewPHP\Db\Fields\TextField;
use BrewPHP\Db\Index;
use BrewPHP\Db\OnDelete;

final class Post extends BlogModel
{
    /** @var list<string> hooks called, in order */
    public static array $events = [];

    public static function fields(): array
    {
        return [
            'title' => new CharField(maxLength: 200),
            'slug' => new SlugField(null: true, blank: true),
            'body' => new TextField(blank: true),
            'author' => new ForeignKey(Author::class, onDelete: OnDelete::Cascade, relatedName: 'posts'),
            'category' => new ForeignKey(Category::class, onDelete: OnDelete::Protect, relatedName: 'posts', null: true, blank: true),
            'status' => new CharField(maxLength: 20, choices: PostStatus::class, default: PostStatus::Draft),
            'views' => new PositiveIntegerField(default: 0),
            'rating' => new DecimalField(maxDigits: 5, decimalPlaces: 2, null: true, blank: true),
            'published_at' => new DateTimeField(null: true, blank: true),
            'metadata' => new JsonField(default: fn (): array => [], blank: true),
            'updated_at' => new DateTimeField(autoNow: true),
        ];
    }

    public static function ordering(): array
    {
        return ['-id'];
    }

    public static function indexes(): array
    {
        return [new Index(['author', 'slug'], unique: true)];
    }

    public function clean(): void
    {
        if ($this->title === 'forbidden') {
            throw new ValidationError(['title' => 'This title is not allowed.', '__all__' => 'Try again.']);
        }
    }

    public function beforeSave(): void
    {
        self::$events[] = 'beforeSave:' . $this->title;
    }

    public function afterSave(bool $created): void
    {
        self::$events[] = 'afterSave:' . $this->title . ':' . ($created ? 'created' : 'updated');
    }

    public function beforeDelete(): void
    {
        self::$events[] = 'beforeDelete:' . $this->title;
    }

    public function afterDelete(): void
    {
        self::$events[] = 'afterDelete:' . $this->title;
    }

    public function __toString(): string
    {
        return (string) $this->title;
    }
}
