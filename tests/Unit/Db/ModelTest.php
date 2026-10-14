<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Db;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\FieldError;
use BrewPHP\Db\Fields\BigAutoField;
use BrewPHP\Db\IntegrityError;
use BrewPHP\Db\ObjectDoesNotExist;
use BrewPHP\Tests\DbTestCase;
use BrewPHP\Tests\Fixtures\Models\Author;
use BrewPHP\Tests\Fixtures\Models\Category;
use BrewPHP\Tests\Fixtures\Models\Item;
use BrewPHP\Tests\Fixtures\Models\Post;
use BrewPHP\Tests\Fixtures\Models\PostStatus;

final class ModelTest extends DbTestCase
{
    public function testMetadata(): void
    {
        $meta = Post::meta();

        self::assertSame('blog_post', $meta->table);
        self::assertSame('blog', $meta->appLabel);
        self::assertSame('Post', $meta->modelName);
        self::assertSame('blog.Post', $meta->label());
        self::assertSame('post', $meta->verboseName);
        self::assertSame('posts', $meta->verboseNamePlural);
        self::assertSame('categories', Category::meta()->verboseNamePlural);
        self::assertInstanceOf(BigAutoField::class, $meta->pk);
        self::assertSame('id', array_key_first($meta->fields));
        self::assertSame('author_id', $meta->field('author')->column);
        self::assertSame($meta->pk, $meta->field('pk'));
        self::assertSame(['-id'], $meta->ordering);
        self::assertSame('uuid', Item::meta()->pk->name);
        self::assertSame('inventory_items', Item::meta()->table);
        self::assertSame(Post::meta(), Post::meta());

        $this->expectException(FieldError::class);
        $this->expectExceptionMessage('has no field named "nope". Choices are: id, title');
        $meta->field('nope');
    }

    public function testCreateSetsDefaultsAndPrimaryKey(): void
    {
        $author = $this->author('Ann');

        self::assertSame(1, $author->pk);
        self::assertSame(1, $author->id);
        self::assertTrue($author->isPersisted());
        self::assertTrue($author->active);
        self::assertSame(0, $author->score);
        self::assertSame('', $author->email);
        self::assertInstanceOf(\DateTimeImmutable::class, $author->created_at);

        $post = $this->post('Hello', $author);
        self::assertSame(PostStatus::Draft, $post->status);
        self::assertSame([], $post->metadata);
        self::assertNull($post->category);
        self::assertSame(1, $post->author_id);
    }

    public function testSaveUpdatesAndRefreshReloads(): void
    {
        $author = $this->author('Ann');
        $author->name = 'Annie';
        $author->score = 5;
        $author->save();

        $fresh = Author::objects()->get(pk: $author->pk);
        self::assertSame('Annie', $fresh->name);
        self::assertSame(5, $fresh->score);
        self::assertSame(1, Author::objects()->count());

        $this->db()->statement('UPDATE blog_author SET name = ? WHERE id = ?', ['Changed', $author->pk]);
        $author->refresh();
        self::assertSame('Changed', $author->name);
    }

    public function testNewObjectIsNotSavedUntilSave(): void
    {
        $author = new Author(['name' => 'Bob']);

        self::assertFalse($author->isPersisted());
        self::assertNull($author->pk);
        self::assertSame('Category object (unsaved)', (string) (new Category()));
        self::assertSame(0, Author::objects()->count());

        $author->save();
        self::assertSame(1, Author::objects()->count());
    }

    public function testValuesRoundTripThroughTheDatabase(): void
    {
        $author = $this->author();
        $post = $this->post('Typed', $author, status: PostStatus::Published, views: 7, rating: '4.5', published_at: '2026-01-02 03:04:05', metadata: ['tags' => ['php', 'orm'], 'ok' => true]);

        $loaded = Post::objects()->get(pk: $post->pk);
        self::assertSame(PostStatus::Published, $loaded->status);
        self::assertSame(7, $loaded->views);
        self::assertSame('4.50', $loaded->rating);
        self::assertEquals(new \DateTimeImmutable('2026-01-02 03:04:05'), $loaded->published_at);
        self::assertSame(['tags' => ['php', 'orm'], 'ok' => true], $loaded->metadata);
        self::assertTrue(Author::objects()->get()->active);
    }

    public function testAutoNowFields(): void
    {
        $post = $this->post('Clock', $this->author());
        $created = Author::objects()->get()->created_at;
        self::assertInstanceOf(\DateTimeImmutable::class, $post->updated_at);

        $post->updated_at = new \DateTimeImmutable('2000-01-01');
        $post->save();
        self::assertNotSame('2000', $post->updated_at->format('Y'));
        self::assertEquals($created, Author::objects()->get()->created_at);
    }

    public function testHooksRunAroundSaveAndDelete(): void
    {
        $post = $this->post('Hooked', $this->author());
        $post->save();
        $post->delete();

        self::assertSame([
            'beforeSave:Hooked',
            'afterSave:Hooked:created',
            'beforeSave:Hooked',
            'afterSave:Hooked:updated',
            'beforeDelete:Hooked',
            'afterDelete:Hooked',
        ], Post::$events);
        self::assertNull($post->pk);
        self::assertFalse($post->isPersisted());
        self::assertSame(0, Post::objects()->count());
    }

    public function testDeleteRequiresASavedObject(): void
    {
        $this->expectException(\LogicException::class);
        (new Author(['name' => 'x']))->delete();
    }

    public function testRefreshOfADeletedRowThrows(): void
    {
        $author = $this->author();
        Author::objects()->delete();

        $this->expectException(ObjectDoesNotExist::class);
        $this->expectExceptionMessage('Author matching query does not exist.');
        $author->refresh();
    }

    public function testForeignKeyAssignment(): void
    {
        $ann = $this->author('Ann');
        $bob = $this->author('Bob');
        $post = $this->post('FK', $ann);

        self::assertSame($ann, $post->author);
        $post->author_id = $bob->pk;
        self::assertSame('Bob', $post->author->name);
        $post->author = $ann;
        self::assertSame($ann->pk, $post->author_id);
        $post->save();

        $loaded = Post::objects()->get(pk: $post->pk);
        $queries = $this->countQueries(function () use ($loaded): void {
            self::assertSame('Ann', $loaded->author->name);
            self::assertSame('Ann', $loaded->author->name);
        });
        self::assertSame(1, $queries, 'The related object is loaded once and cached.');

        $loaded->category = null;
        self::assertNull($loaded->category);
        self::assertFalse(isset($loaded->category));
        self::assertTrue(isset($loaded->author));
    }

    public function testRelatedObjectSavedAfterAssignmentIsUsed(): void
    {
        $author = new Author(['name' => 'Late']);
        $post = new Post(['title' => 'Late author', 'author' => $author]);
        $author->save();
        $post->save();

        self::assertSame($author->pk, Post::objects()->get()->author_id);
    }

    public function testUnsavedRelatedObjectIsRefused(): void
    {
        $post = new Post(['title' => 'x', 'author' => new Author(['name' => 'Ghost'])]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unsaved related object "author"');
        $post->save();
    }

    public function testExtraAttributesAndUnknownFields(): void
    {
        $author = $this->author();
        $author->postCount = 3;
        self::assertSame(3, $author->postCount);
        self::assertTrue(isset($author->postCount));
        self::assertFalse(isset($author->nothing));

        $this->expectException(FieldError::class);
        $this->expectExceptionMessage('Author has no field or relation named "nothing"');
        $author->nothing;
    }

    public function testToArrayAndJson(): void
    {
        $author = $this->author('Ann', email: 'ann@example.com');
        $post = $this->post('Json', $author, status: PostStatus::Published);

        $array = $post->toArray();
        self::assertSame('Json', $array['title']);
        self::assertSame($author->pk, $array['author_id']);
        self::assertArrayNotHasKey('author', $array);

        $json = json_decode((string) json_encode($post), true);
        self::assertIsArray($json);
        self::assertSame('published', $json['status']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $json['updated_at']);
        self::assertSame('Ann', (string) $author);
    }

    public function testFullCleanCollectsErrorsPerField(): void
    {
        $post = new Post(['title' => str_repeat('x', 201), 'views' => -1, 'status' => 'archived', 'slug' => 'not a slug']);

        try {
            $post->fullClean();
            self::fail('ValidationError expected');
        } catch (ValidationError $e) {
            $errors = $e->errors();
            self::assertSame(['Ensure this value has at most 200 characters (it has 201).'], $errors['title']);
            self::assertSame(['Ensure this value is greater than or equal to 0.'], $errors['views']);
            self::assertSame(["Value 'archived' is not a valid choice."], $errors['status']);
            self::assertSame(['This field cannot be null.'], $errors['author']);
            self::assertStringContainsString('valid "slug"', $errors['slug'][0]);
            self::assertArrayNotHasKey('body', $errors, 'blank: true allows an empty body');
        }
    }

    public function testFullCleanConvertsValuesAndRunsClean(): void
    {
        $author = $this->author();
        $post = new Post(['title' => 'Fine', 'author' => $author, 'views' => '12', 'status' => 'published']);
        $post->fullClean();
        self::assertSame(12, $post->views);
        self::assertSame(PostStatus::Published, $post->status);

        $post->title = 'forbidden';
        try {
            $post->fullClean();
            self::fail('ValidationError expected');
        } catch (ValidationError $e) {
            self::assertSame(['This title is not allowed.'], $e->errors()['title']);
            self::assertSame(['Try again.'], $e->errors()[ValidationError::NON_FIELD]);
        }
    }

    public function testFullCleanChecksUniqueFieldsAndIndexes(): void
    {
        $ann = $this->author();
        Category::create(['name' => 'PHP', 'slug' => 'php']);
        $this->post('One', $ann, slug: 'same');

        try {
            (new Category(['name' => 'Again', 'slug' => 'php']))->fullClean();
            self::fail('ValidationError expected');
        } catch (ValidationError $e) {
            self::assertSame(['Category with this slug already exists.'], $e->errors()['slug']);
        }

        try {
            (new Post(['title' => 'Two', 'author' => $ann, 'slug' => 'same']))->fullClean();
            self::fail('ValidationError expected');
        } catch (ValidationError $e) {
            self::assertSame(['Post with this author and slug already exists.'], $e->errors()[ValidationError::NON_FIELD]);
        }

        // The object itself is not a duplicate of itself.
        $existing = Category::objects()->get(slug: 'php');
        $existing->fullClean();
        $this->addToAssertionCount(1);
    }

    public function testFullCleanChecksThatTheRelatedObjectExists(): void
    {
        $post = new Post(['title' => 'Orphan', 'author_id' => 999]);

        try {
            $post->fullClean();
            self::fail('ValidationError expected');
        } catch (ValidationError $e) {
            self::assertSame(['Author instance with id 999 does not exist.'], $e->errors()['author']);
        }
    }

    public function testDatabaseConstraintsRaiseIntegrityError(): void
    {
        Category::create(['name' => 'PHP', 'slug' => 'php']);

        $this->expectException(IntegrityError::class);
        Category::create(['name' => 'Dup', 'slug' => 'php']);
    }

    public function testUuidPrimaryKey(): void
    {
        $item = Item::create(['name' => 'Mug', 'opens_at' => '9:30']);

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $item->pk);
        $loaded = Item::objects()->get(pk: $item->pk);
        self::assertSame('Mug', $loaded->name);
        self::assertSame('09:30:00', $loaded->opens_at);

        $loaded->name = 'Cup';
        $loaded->save();
        self::assertSame(['Cup'], Item::objects()->valuesList('name', flat: true));
        self::assertSame(1, $loaded->delete());
    }

    public function testCreateWithAttnameAndPk(): void
    {
        $ann = $this->author();
        $post = Post::create(['title' => 'By id', 'author_id' => $ann->pk]);
        $post->pk = $post->pk;

        self::assertSame('Ann', $post->author->name);
    }
}
