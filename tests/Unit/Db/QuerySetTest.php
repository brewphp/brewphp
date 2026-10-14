<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Db;

use BrewPHP\Db\Avg;
use BrewPHP\Db\Count;
use BrewPHP\Db\F;
use BrewPHP\Db\FieldError;
use BrewPHP\Db\Max;
use BrewPHP\Db\Min;
use BrewPHP\Db\MultipleObjectsReturned;
use BrewPHP\Db\ObjectDoesNotExist;
use BrewPHP\Db\Q;
use BrewPHP\Db\QuerySet;
use BrewPHP\Db\Sum;
use BrewPHP\Tests\DbTestCase;
use BrewPHP\Tests\Fixtures\Models\Author;
use BrewPHP\Tests\Fixtures\Models\Category;
use BrewPHP\Tests\Fixtures\Models\Post;
use BrewPHP\Tests\Fixtures\Models\PostStatus;

final class QuerySetTest extends DbTestCase
{
    private Author $ann;

    private Author $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ann = $this->author('Ann', score: 10);
        $this->bob = $this->author('Bob', score: 3, active: false);
        $this->post('Hello PHP', $this->ann, views: 10, status: PostStatus::Published, published_at: '2025-03-01 10:00:00', rating: '4.50');
        $this->post('Django tips', $this->ann, views: 50, slug: 'django-tips', published_at: '2026-07-15 08:00:00', rating: '3.00');
        $this->post('100% pure', $this->bob, views: 0, body: 'Snake_case and CamelCase');
        $this->post('Lower php', $this->bob, views: 25, status: PostStatus::Published);
    }

    /**
     * @param QuerySet<Post> $query
     * @return list<string>
     */
    private static function titles(QuerySet $query): array
    {
        return array_map(static fn (Post $post): string => (string) $post->title, $query->orderBy('id')->toArray());
    }

    public function testAllAndDefaultOrdering(): void
    {
        self::assertSame(['Lower php', '100% pure', 'Django tips', 'Hello PHP'], array_map('strval', Post::objects()->toArray()));
        self::assertSame(['Ann', 'Bob'], Author::objects()->valuesList('name', flat: true));
        self::assertCount(4, Post::objects());
        self::assertSame(4, Post::objects()->count());
    }

    public function testExactAndComparisonLookups(): void
    {
        self::assertSame(['Hello PHP'], self::titles(Post::objects()->filter(title: 'Hello PHP')));
        self::assertSame(['Django tips', 'Lower php'], self::titles(Post::objects()->filter(views__gt: 10)));
        self::assertSame(['Hello PHP', 'Django tips', 'Lower php'], self::titles(Post::objects()->filter(views__gte: 10)));
        self::assertSame(['100% pure'], self::titles(Post::objects()->filter(views__lt: 10)));
        self::assertSame(['Hello PHP', '100% pure'], self::titles(Post::objects()->filter(views__lte: 10)));
        self::assertSame(['Hello PHP', 'Lower php'], self::titles(Post::objects()->filter(status: PostStatus::Published)));
        self::assertSame(['Hello PHP', 'Lower php'], self::titles(Post::objects()->filter(status: 'published')));
        self::assertSame(['Hello PHP', 'Lower php'], self::titles(Post::objects()->filter(['views__gte' => 10, 'status' => 'published'])));
        self::assertSame(['Hello PHP'], self::titles(Post::objects()->filter(views__gte: 10)->filter(views__lte: 10)));
    }

    public function testTextLookups(): void
    {
        self::assertSame(['Hello PHP'], self::titles(Post::objects()->filter(title__contains: 'PHP')));
        self::assertSame(['Hello PHP', 'Lower php'], self::titles(Post::objects()->filter(title__icontains: 'php')));
        self::assertSame(['Hello PHP'], self::titles(Post::objects()->filter(title__startswith: 'Hell')));
        self::assertSame([], self::titles(Post::objects()->filter(title__startswith: 'hell')));
        self::assertSame(['Hello PHP'], self::titles(Post::objects()->filter(title__istartswith: 'hell')));
        self::assertSame(['Lower php'], self::titles(Post::objects()->filter(title__endswith: 'php')));
        self::assertSame(['Hello PHP', 'Lower php'], self::titles(Post::objects()->filter(title__iendswith: 'PHP')));
        self::assertSame(['Hello PHP'], self::titles(Post::objects()->filter(title__iexact: 'hello php')));
        // Wildcards in the value are literal.
        self::assertSame(['100% pure'], self::titles(Post::objects()->filter(title__contains: '%')));
        self::assertSame(['100% pure'], self::titles(Post::objects()->filter(title__icontains: '0% P')));
        self::assertSame(['100% pure'], self::titles(Post::objects()->filter(body__contains: 'e_c')));
        self::assertSame([], self::titles(Post::objects()->filter(body__contains: 'e*c')));
        self::assertSame([], self::titles(Post::objects()->filter(title__contains: '_')));
    }

    public function testInRangeAndIsNull(): void
    {
        self::assertSame(['Hello PHP', '100% pure'], self::titles(Post::objects()->filter(views__in: [0, 10, null])));
        self::assertSame([], self::titles(Post::objects()->filter(views__in: [])));
        self::assertSame(['Hello PHP', 'Lower php'], self::titles(Post::objects()->filter(views__range: [5, 30])));
        self::assertSame(['Django tips'], self::titles(Post::objects()->filter(slug__isnull: false)));
        self::assertSame(['Hello PHP', '100% pure', 'Lower php'], self::titles(Post::objects()->filter(slug__isnull: true)));
        self::assertSame(['Hello PHP', '100% pure', 'Lower php'], self::titles(Post::objects()->filter(slug: null)));
        self::assertSame(['Hello PHP', 'Django tips'], self::titles(Post::objects()->filter(author__in: [$this->ann])));
        self::assertSame(['100% pure', 'Lower php'], self::titles(Post::objects()->filter(author__in: Author::objects()->filter(active: false))));
    }

    public function testDateTransforms(): void
    {
        self::assertSame(['Django tips'], self::titles(Post::objects()->filter(published_at__year: 2026)));
        self::assertSame(['Hello PHP', 'Django tips'], self::titles(Post::objects()->filter(published_at__year__gte: 2025)));
        self::assertSame(['Hello PHP'], self::titles(Post::objects()->filter(published_at__month: 3)));
        self::assertSame(['Django tips'], self::titles(Post::objects()->filter(published_at__day: 15)));
        self::assertSame(['Django tips'], self::titles(Post::objects()->filter(published_at__gt: new \DateTimeImmutable('2026-01-01'))));
    }

    public function testExcludeKeepsNullRows(): void
    {
        self::assertSame(['Hello PHP', '100% pure', 'Lower php'], self::titles(Post::objects()->exclude(slug: 'django-tips')));
        self::assertSame(['100% pure', 'Lower php'], self::titles(Post::objects()->exclude(title__icontains: 'hello', views__gt: 5)->exclude(author: $this->ann)));
        self::assertSame(['Django tips', '100% pure'], self::titles(Post::objects()->exclude(status: PostStatus::Published)));
    }

    public function testQObjects(): void
    {
        $either = Q::or(new Q(title__icontains: 'django'), new Q(views: 0));
        self::assertSame(['Django tips', '100% pure'], self::titles(Post::objects()->where($either)));
        self::assertSame(['Hello PHP', 'Lower php'], self::titles(Post::objects()->where(Q::not($either))));
        self::assertSame(['Hello PHP', 'Lower php'], self::titles(Post::objects()->where($either->negate())));
        self::assertSame(['Django tips'], self::titles(Post::objects()->where(Q::and($either, new Q(author: $this->ann)))));
        self::assertSame(['Django tips'], self::titles(Post::objects()->filter($either, author__name: 'Ann')));
        self::assertSame(['Hello PHP', 'Django tips', '100% pure'], self::titles(Post::objects()->where(Q::or(new Q(author: $this->ann), new Q(['views' => 0])))));
        self::assertSame(4, Post::objects()->where(new Q())->count(), 'an empty Q matches everything');
    }

    public function testFExpressions(): void
    {
        Author::objects()->filter(pk: $this->ann->pk)->update(['score' => 50]);
        // Posts with more views than their author's score.
        self::assertSame(['Lower php'], self::titles(Post::objects()->filter(views__gt: new F('author__score'))));
        self::assertSame(['Django tips'], self::titles(Post::objects()->filter(views: (new F('author__score'))->multiply(1))));

        $updated = Post::objects()->filter(author: $this->bob)->update(['views' => (new F('views'))->add(5)->multiply(2)]);
        self::assertSame(2, $updated);
        self::assertSame([10, 60], Post::objects()->filter(author: $this->bob)->orderBy('views')->valuesList('views', flat: true));
        Post::objects()->update(['views' => (new F('views'))->subtract(new F('views'))->add(1)->divide(1)]);
        self::assertSame([1], array_values(array_unique(Post::objects()->valuesList('views', flat: true))));
    }

    public function testOrderingSlicingFirstAndLast(): void
    {
        self::assertSame(['Bob', 'Ann'], Author::objects()->orderBy('-name')->valuesList('name', flat: true));
        self::assertSame(['Django tips', 'Lower php'], array_map('strval', Post::objects()->orderBy('-views')->limit(2)->toArray()));
        self::assertSame(['Lower php', 'Hello PHP'], array_map('strval', Post::objects()->orderBy('-views')->slice(1, 2)->toArray()));
        self::assertSame(['Hello PHP', '100% pure'], array_map('strval', Post::objects()->orderBy('-views')->offset(2)->toArray()));
        self::assertSame(['Hello PHP', 'Django tips', '100% pure', 'Lower php'], array_map('strval', Post::objects()->orderBy('author__name', 'id')->toArray()));
        self::assertSame(['Hello PHP', 'Django tips', '100% pure', 'Lower php'], array_map('strval', Post::objects()->reverse()->toArray()));

        self::assertSame('Lower php', (string) Post::objects()->first());
        self::assertSame('Hello PHP', (string) Post::objects()->last());
        self::assertSame('Hello PHP', (string) Post::objects()->orderBy()->first());
        self::assertSame('100% pure', (string) Post::objects()->orderBy('views')->first());
        self::assertNull(Post::objects()->filter(views: 999)->first());
        self::assertSame(2, Post::objects()->limit(2)->count());
        self::assertSame(1, Post::objects()->slice(3, 10)->count());
        self::assertTrue(Post::objects()->slice(3)->exists());
        self::assertFalse(Post::objects()->slice(4)->exists());
    }

    public function testCannotFilterAfterSlicing(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot call filter() once a limit or offset has been set.');
        Post::objects()->limit(2)->filter(views: 1);
    }

    public function testGet(): void
    {
        self::assertSame('Django tips', (string) Post::objects()->get(slug: 'django-tips'));
        self::assertSame('Django tips', (string) Post::objects()->filter(slug: 'django-tips')->get());

        try {
            Post::objects()->get(title: 'Missing');
            self::fail('ObjectDoesNotExist expected');
        } catch (ObjectDoesNotExist $e) {
            self::assertSame(Post::class, $e->model);
            self::assertSame('Post matching query does not exist.', $e->getMessage());
        }

        $this->expectException(MultipleObjectsReturned::class);
        $this->expectExceptionMessage('get() returned more than one Post -- it returned 4!');
        Post::objects()->get();
    }

    public function testCountExistsAndCaching(): void
    {
        $query = Post::objects()->filter(views__gte: 10);

        self::assertSame(1, $this->countQueries(fn () => self::assertSame(3, $query->count())));
        self::assertSame(1, $this->countQueries(fn () => self::assertTrue($query->exists())));
        self::assertSame(1, $this->countQueries(function () use ($query): void {
            iterator_to_array($query);
            iterator_to_array($query);
            self::assertCount(3, $query);
            self::assertTrue($query->exists());
        }));
        self::assertFalse(Post::objects()->filter(views: 999)->exists());
    }

    public function testQuerySetsAreImmutable(): void
    {
        $all = Post::objects();
        $published = $all->filter(status: 'published');

        self::assertNotSame($all, $published);
        self::assertSame(4, $all->count());
        self::assertSame(2, $published->count());
        self::assertSame(4, $all->all()->count());
    }

    public function testNone(): void
    {
        $none = Post::objects()->none();

        self::assertSame(0, $this->countQueries(function () use ($none): void {
            self::assertSame([], $none->toArray());
            self::assertSame(0, $none->count());
            self::assertFalse($none->exists());
            self::assertSame([], $none->values());
            self::assertSame(0, $none->update(['views' => 1]));
            self::assertSame(0, $none->delete());
            self::assertSame(['total' => null, 'n' => 0], $none->aggregate(total: new Sum('views'), n: new Count('id')));
        }));
    }

    public function testValuesAndValuesList(): void
    {
        $rows = Post::objects()->filter(slug: 'django-tips')->values('title', 'author__name', 'status');
        self::assertSame([['title' => 'Django tips', 'author__name' => 'Ann', 'status' => PostStatus::Draft]], $rows);

        $all = Post::objects()->orderBy('id')->values();
        self::assertSame('Hello PHP', $all[0]['title']);
        self::assertSame($this->ann->pk, $all[0]['author_id']);

        self::assertSame([['Hello PHP', 10], ['Django tips', 50]], Post::objects()->filter(author: $this->ann)->orderBy('id')->valuesList('title', 'views'));
        self::assertSame(['Ann', 'Ann', 'Bob', 'Bob'], Post::objects()->orderBy('id')->valuesList('author__name', flat: true));
    }

    public function testValuesListFlatNeedsOneField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Post::objects()->valuesList('id', 'title', flat: true);
    }

    public function testAggregate(): void
    {
        $result = Post::objects()->aggregate(new Count('id'), total: new Sum('views'), average: new Avg('views'), most: new Max('views'), least: new Min('published_at'));

        self::assertSame(85, $result['total']);
        self::assertSame(21.25, $result['average']);
        self::assertSame(4, $result['id__count']);
        self::assertSame(50, $result['most']);
        self::assertEquals(new \DateTimeImmutable('2025-03-01 10:00:00'), $result['least']);

        self::assertSame(['n' => 2], Post::objects()->filter(author__name: 'Bob')->aggregate(n: new Count('*')));
        self::assertSame(['authors' => 2], Post::objects()->aggregate(authors: new Count('author', distinct: true)));
        self::assertSame(['top' => 75], Post::objects()->orderBy('-views')->limit(2)->aggregate(top: new Sum('views')));
        self::assertSame(['score' => 13], Author::objects()->aggregate(score: new Sum('score')));
        self::assertSame(['s' => null], Post::objects()->filter(views: 999)->aggregate(s: new Sum('views')));
        self::assertSame([], Post::objects()->aggregate());
    }

    public function testUpdateAndDelete(): void
    {
        self::assertSame(2, Post::objects()->filter(author__name: 'Bob')->update(['status' => PostStatus::Published, 'body' => 'bob']));
        self::assertSame(3, Post::objects()->filter(status: 'published')->count());
        self::assertSame(0, Post::objects()->update([]));

        Post::$events = [];
        self::assertSame(2, Post::objects()->filter(author: $this->bob)->delete());
        self::assertSame(['beforeDelete:100% pure', 'beforeDelete:Lower php', 'afterDelete:100% pure', 'afterDelete:Lower php'], Post::$events);
        self::assertSame(2, Post::objects()->count());
        self::assertSame(0, Post::objects()->filter(views: 999)->delete());
    }

    public function testUpdateWithUnknownFieldFails(): void
    {
        $this->expectException(FieldError::class);
        Post::objects()->update(['nope' => 1]);
    }

    public function testDeleteRefusesSlices(): void
    {
        $this->expectException(\LogicException::class);
        Post::objects()->limit(1)->delete();
    }

    public function testCreateGetOrCreateAndUpdateOrCreate(): void
    {
        $category = Category::objects()->create(['name' => 'PHP', 'slug' => 'php']);
        self::assertTrue($category->isPersisted());

        [$found, $created] = Category::objects()->getOrCreate(['slug' => 'php'], ['name' => 'Ignored']);
        self::assertFalse($created);
        self::assertSame($category->pk, $found->pk);

        [$new, $created] = Category::objects()->getOrCreate(['slug' => 'go', 'name__icontains' => 'go'], ['name' => 'Go']);
        self::assertTrue($created);
        self::assertSame('Go', $new->name);

        [$updated, $created] = Category::objects()->updateOrCreate(['slug' => 'go'], ['name' => 'Golang']);
        self::assertFalse($created);
        self::assertSame('Golang', Category::objects()->get(slug: 'go')->name);
        self::assertSame($new->pk, $updated->pk);

        [, $created] = Category::objects()->updateOrCreate(['slug' => 'rust'], ['name' => 'Rust']);
        self::assertTrue($created);
        self::assertSame(3, Category::objects()->count());
    }

    public function testInBulk(): void
    {
        $posts = Post::objects()->orderBy('id')->inBulk([1, 3, 99]);
        self::assertSame([1, 3], array_keys($posts));
        self::assertSame('100% pure', (string) $posts[3]);
        self::assertCount(4, Post::objects()->inBulk());
        self::assertSame([], Post::objects()->inBulk([]));

        Category::create(['name' => 'PHP', 'slug' => 'php']);
        self::assertSame(['php'], array_keys(Category::objects()->inBulk(['php'], 'slug')));

        $this->expectException(FieldError::class);
        Post::objects()->inBulk([1], 'title');
    }

    public function testRawAndToSql(): void
    {
        $posts = Post::objects()->raw('SELECT * FROM blog_post WHERE views > ? ORDER BY id', [20]);
        self::assertSame(['Django tips', 'Lower php'], array_map('strval', $posts));
        self::assertTrue($posts[0]->isPersisted());

        [$sql, $bindings] = Post::objects()->filter(title__icontains: 'php', author__name: 'Ann')->limit(5)->toSql();
        self::assertStringStartsWith('SELECT "blog_post"."id", "blog_post"."title"', $sql);
        self::assertStringContainsString(
            'FROM "blog_post" INNER JOIN "blog_author" "T1" ON "T1"."id" = "blog_post"."author_id" WHERE ("blog_post"."title" LIKE ? ESCAPE \'\\\' AND "T1"."name" = ?) ORDER BY "blog_post"."id" DESC LIMIT 5',
            $sql,
        );
        self::assertSame(['%php%', 'Ann'], $bindings);
    }

    public function testDistinct(): void
    {
        self::assertSame(4, Author::objects()->filter(posts__views__gte: 0)->count());
        self::assertSame(2, Author::objects()->filter(posts__views__gte: 0)->distinct()->count());
        self::assertSame(['Ann', 'Bob'], array_map('strval', Author::objects()->filter(posts__views__gte: 0)->distinct()->toArray()));
    }

    public function testUnknownFieldsAndLookups(): void
    {
        try {
            Post::objects()->filter(titel: 'x')->count();
            self::fail('FieldError expected');
        } catch (FieldError $e) {
            self::assertStringContainsString('Cannot resolve keyword "titel" into a field of Post. Choices are: id, title', $e->getMessage());
        }

        $this->expectException(FieldError::class);
        $this->expectExceptionMessage('Unsupported lookup "regex" in "title__regex"');
        Post::objects()->filter(title__regex: 'x')->count();
    }

    public function testInvalidLookupValues(): void
    {
        try {
            Post::objects()->filter(views__in: 5)->count();
            self::fail('FieldError expected');
        } catch (FieldError) {
        }
        try {
            Post::objects()->filter(views__range: [1])->count();
            self::fail('FieldError expected');
        } catch (FieldError) {
        }

        $this->expectException(FieldError::class);
        Post::objects()->filter(title__contains: ['x'])->count();
    }

    public function testPaginate(): void
    {
        $page = Post::objects()->orderBy('id')->paginate(3, '2');

        self::assertSame(2, $page->number);
        self::assertSame(['Lower php'], array_map('strval', $page->items));
        self::assertSame(2, $page->paginator->numPages());
        self::assertSame(1, Post::objects()->paginate(3, 'abc')->number);
        self::assertSame(2, Post::objects()->paginate(3, 99)->number);
    }
}
