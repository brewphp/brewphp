<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Db;

use BrewPHP\Db\FieldError;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\ObjectDoesNotExist;
use BrewPHP\Db\OnDelete;
use BrewPHP\Db\ProtectedError;
use BrewPHP\Db\QuerySet;
use BrewPHP\Db\Registry;
use BrewPHP\Tests\DbTestCase;
use BrewPHP\Tests\Fixtures\Models\Author;
use BrewPHP\Tests\Fixtures\Models\Category;
use BrewPHP\Tests\Fixtures\Models\Comment;
use BrewPHP\Tests\Fixtures\Models\Node;
use BrewPHP\Tests\Fixtures\Models\Post;
use BrewPHP\Tests\Fixtures\Models\Profile;

final class RelationsTest extends DbTestCase
{
    public function testForwardTraversalJoinsAnyDepth(): void
    {
        $ann = $this->author('Ann');
        $bob = $this->author('Bob');
        $hello = $this->post('Hello', $ann);
        $this->post('Other', $bob);
        Comment::create(['post' => $hello, 'author' => $bob, 'text' => 'Nice']);

        self::assertSame(['Hello'], Post::objects()->filter(author__name: 'Ann')->valuesList('title', flat: true));
        self::assertSame(['Nice'], Comment::objects()->filter(post__author__name__startswith: 'A')->valuesList('text', flat: true));
        self::assertSame(['Nice'], Comment::objects()->filter(post__author: $ann)->valuesList('text', flat: true));
        self::assertSame(['Nice'], Comment::objects()->filter(post__author__id: $ann->pk)->valuesList('text', flat: true));
        self::assertSame(['Ann'], Comment::objects()->valuesList('post__author__name', flat: true));

        // "author__id" uses the author_id column: no join.
        [$sql] = Post::objects()->filter(author__id: 1)->toSql();
        self::assertStringNotContainsString('JOIN', $sql);
        [$sql] = Post::objects()->filter(author__pk__in: [1])->toSql();
        self::assertStringNotContainsString('JOIN', $sql);

        // Nullable foreign keys use LEFT JOIN, so rows without a match stay.
        [$sql] = Post::objects()->filter(category__name: 'x')->toSql();
        self::assertStringContainsString('LEFT JOIN "blog_category" "T1"', $sql);
        self::assertSame(['Hello', 'Other'], Post::objects()->exclude(category__name: 'x')->orderBy('id')->valuesList('title', flat: true));
    }

    public function testReverseRelationsInQueries(): void
    {
        $ann = $this->author('Ann');
        $bob = $this->author('Bob');
        $this->author('Cid');
        $this->post('Hello', $ann, views: 5);
        $this->post('Big', $bob, views: 100);

        self::assertSame(['Bob'], Author::objects()->filter(posts__views__gt: 50)->valuesList('name', flat: true));
        self::assertSame(['Cid'], Author::objects()->filter(posts__isnull: true)->valuesList('name', flat: true));
        self::assertSame(['Ann', 'Bob'], Author::objects()->filter(posts__isnull: false)->valuesList('name', flat: true));

        $comment = Comment::create(['post' => Post::objects()->get(title: 'Hello'), 'text' => 'hi', 'author' => $bob]);
        self::assertSame(['Bob'], Author::objects()->filter(comment__text: 'hi')->valuesList('name', flat: true));
        self::assertSame($comment->pk, Comment::objects()->get(author__name: 'Bob')->pk);
    }

    public function testRelatedAccessors(): void
    {
        $ann = $this->author('Ann');
        $hello = $this->post('Hello', $ann);
        $this->post('Second', $ann);
        Comment::create(['post' => $hello, 'text' => 'one']);

        self::assertInstanceOf(QuerySet::class, $ann->posts);
        self::assertSame(['Second', 'Hello'], array_map('strval', $ann->posts->toArray()));
        self::assertSame(2, $ann->related('posts')->count());
        self::assertSame(['one'], $hello->comments->valuesList('text', flat: true));
        self::assertSame(0, $ann->comment_set->count(), 'without relatedName the accessor is <model>_set');

        $created = $ann->posts->create(['title' => 'Via relation']);
        self::assertSame($ann->pk, $created->author_id);
        self::assertSame(3, $ann->posts->count());

        $this->expectException(FieldError::class);
        $ann->related('nothing');
    }

    public function testOneToOne(): void
    {
        $ann = $this->author('Ann');
        $bob = $this->author('Bob');
        Profile::create(['author' => $ann, 'bio' => 'Writer']);

        self::assertSame('Writer', $ann->profile->bio);
        self::assertSame('Ann', Profile::objects()->get()->author->name);
        self::assertSame(['Ann'], Author::objects()->filter(profile__bio: 'Writer')->valuesList('name', flat: true));

        $this->expectException(ObjectDoesNotExist::class);
        $bob->profile;
    }

    public function testSelectRelatedLoadsInOneQuery(): void
    {
        $ann = $this->author('Ann');
        $php = Category::create(['name' => 'PHP', 'slug' => 'php']);
        $this->post('One', $ann, category: $php);
        $this->post('Two', $ann);
        $hello = Post::objects()->get(title: 'One');
        Comment::create(['post' => $hello, 'text' => 'c']);

        $queries = $this->countQueries(function (): void {
            $posts = Post::objects()->selectRelated('author', 'category')->orderBy('id')->toArray();
            self::assertSame('Ann', $posts[0]->author->name);
            self::assertSame('PHP', $posts[0]->category->name);
            self::assertSame('Ann', $posts[1]->author->name);
            self::assertNull($posts[1]->category);
        });
        self::assertSame(1, $queries);

        $queries = $this->countQueries(function (): void {
            $comment = Comment::objects()->selectRelated('post__author', 'author')->get();
            self::assertSame('One', $comment->post->title);
            self::assertSame('Ann', $comment->post->author->name);
            self::assertNull($comment->author);
        });
        self::assertSame(1, $queries);

        $this->expectException(FieldError::class);
        Post::objects()->selectRelated('title')->toArray();
    }

    public function testCascadeDeletesDependents(): void
    {
        $ann = $this->author('Ann');
        $bob = $this->author('Bob');
        $hello = $this->post('Hello', $ann);
        $this->post('Second', $ann);
        $this->post('Bob post', $bob);
        Comment::create(['post' => $hello, 'author' => $bob, 'text' => 'c1']);
        Profile::create(['author' => $ann]);

        Post::$events = [];
        $deleted = $ann->delete();

        self::assertSame(5, $deleted, 'author + 2 posts + 1 comment + 1 profile');
        self::assertSame(['Bob post'], Post::objects()->valuesList('title', flat: true));
        self::assertSame(0, Comment::objects()->count());
        self::assertSame(0, Profile::objects()->count());
        self::assertContains('beforeDelete:Hello', Post::$events);
        self::assertContains('afterDelete:Second', Post::$events);
    }

    public function testSetNull(): void
    {
        $ann = $this->author('Ann');
        $bob = $this->author('Bob');
        $comment = Comment::create(['post' => $this->post('Hello', $ann), 'author' => $bob, 'text' => 'kept']);

        $bob->delete();

        $comment->refresh();
        self::assertNull($comment->author_id);
        self::assertSame('kept', $comment->text);
    }

    public function testProtect(): void
    {
        $php = Category::create(['name' => 'PHP', 'slug' => 'php']);
        $post = $this->post('Hello', $this->author(), category: $php);

        try {
            $php->delete();
            self::fail('ProtectedError expected');
        } catch (ProtectedError $e) {
            self::assertStringContainsString('protected foreign key Post.category', $e->getMessage());
            self::assertSame($post->pk, $e->protectedObjects[0]->pk);
        }
        self::assertSame(1, Category::objects()->count());

        $post->delete();
        self::assertSame(1, $php->delete());
    }

    public function testProtectedObjectsDeletedInTheSameCascadeAreAllowed(): void
    {
        $php = Category::create(['name' => 'PHP', 'slug' => 'php']);
        $ann = $this->author();
        $this->post('Hello', $ann, category: $php);

        // Deleting the author cascades to the post, which no longer blocks the category.
        $ann->delete();
        self::assertSame(1, $php->delete());
    }

    public function testSelfReferenceCascades(): void
    {
        $root = Node::create(['name' => 'root']);
        $child = Node::create(['name' => 'child', 'parent' => $root]);
        Node::create(['name' => 'grandchild', 'parent' => $child]);
        Node::create(['name' => 'other']);

        self::assertSame(['child'], $root->children->valuesList('name', flat: true));
        self::assertSame(['grandchild'], Node::objects()->filter(parent__parent__name: 'root')->valuesList('name', flat: true));
        self::assertSame(3, $root->delete());
        self::assertSame(['other'], Node::objects()->valuesList('name', flat: true));
    }

    public function testQuerySetDeleteCascades(): void
    {
        $ann = $this->author('Ann');
        Comment::create(['post' => $this->post('Hello', $ann), 'text' => 'c']);

        self::assertSame(3, Author::objects()->filter(name: 'Ann')->delete());
        self::assertSame(0, Comment::objects()->count());
    }

    public function testRegistryFindsReverseRelations(): void
    {
        $names = array_map(static fn (ForeignKey $field): string => $field->model . '.' . $field->name, Registry::reverseRelations(Author::class));

        self::assertContains(Post::class . '.author', $names);
        self::assertContains(Comment::class . '.author', $names);
        self::assertContains(Profile::class . '.author', $names);
        self::assertSame(Node::class, Node::meta()->field('parent') instanceof ForeignKey ? Node::meta()->foreignKeys()['parent']->target() : null);
    }

    public function testSetNullNeedsANullableField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ForeignKey(Author::class, onDelete: OnDelete::SetNull);
    }
}
