<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Templates;

use BrewPHP\Pagination\Paginator;
use BrewPHP\Tests\DbTestCase;
use BrewPHP\Tests\Fixtures\Models\Post;

/**
 * Models, QuerySets and pages in templates.
 */
final class ModelTemplatesTest extends DbTestCase
{
    /**
     * @param array<string, mixed> $context
     */
    private function render(string $source, array $context = []): string
    {
        return $this->app->templates()->renderString($source, $context);
    }

    public function testModelFieldsAndRelations(): void
    {
        $ann = $this->author('Ann', email: 'ann@example.com');
        $post = $this->post('Hello <world>', $ann, views: 3);

        self::assertSame(
            sprintf('Hello &lt;world&gt; 3 Ann %d %d ann@example.com Hello &lt;world&gt;', $post->pk, $ann->pk),
            $this->render('{{ post.title }} {{ post.views }} {{ post.author.name }} {{ post.pk }} {{ post.author_id }} {{ post.author.email }} {{ post }}', ['post' => $post]),
        );
        self::assertSame('[]', $this->render('[{{ post.category }}{{ post.category.name }}{{ post.nope }}]', ['post' => $post]));
        self::assertSame('Hello &lt;world&gt;,', $this->render('{% for p in author.posts %}{{ p.title }},{% endfor %}', ['author' => $ann]));
    }

    public function testQuerySets(): void
    {
        $ann = $this->author('Ann');
        $this->post('One', $ann);
        $this->post('Two', $ann);

        $source = '{% if posts %}{{ posts|length }}:{% for p in posts %}{{ forloop.counter }}.{{ p.title }} {% endfor %}{{ posts.0.title }}{% endif %}';

        self::assertSame('2:1.Two 2.One Two', $this->render($source, ['posts' => Post::objects()->all()]));
        self::assertSame('none', $this->render('{% for p in posts %}x{% empty %}none{% endfor %}{% if posts %}!{% endif %}', ['posts' => Post::objects()->none()]));
        self::assertSame('Two', $this->render('{{ posts.first.title }}', ['posts' => Post::objects()->all()]));
        self::assertSame('2', $this->render('{{ posts.count }}', ['posts' => Post::objects()->all()]));
    }

    public function testTemplatesCannotChangeData(): void
    {
        $ann = $this->author('Ann');
        $post = $this->post('Keep me', $ann);
        Post::$events = [];

        $this->render('{{ post.delete }}{{ post.save }}{{ post.refresh }}{{ post.before_save }}{{ posts.delete }}{{ posts.create }}{{ posts.update }}', [
            'post' => $post,
            'posts' => Post::objects()->all(),
        ]);

        self::assertSame(1, Post::objects()->count());
        self::assertSame([], Post::$events, 'beforeSave overridden in Post is still blocked');
    }

    public function testPaginatorPages(): void
    {
        $ann = $this->author('Ann');
        foreach (range(1, 5) as $i) {
            $this->post('Post ' . $i, $ann);
        }
        $page = (new Paginator(Post::objects()->orderBy('id'), 2))->page(2);

        $source = '{% for p in page %}{{ p.title }},{% endfor %} page {{ page.number }} of {{ page.paginator.num_pages }}'
            . '{% if page.has_previous %} prev={{ page.previous_page_number }}{% endif %}'
            . '{% if page.has_next %} next={{ page.next_page_number }}{% endif %}'
            . ' items {{ page.start_index }}-{{ page.end_index }} of {{ page.paginator.count }}'
            . ' [{% for n in page.paginator.page_range %}{% if n == page.number %}<{{ n }}>{% else %}{{ n }}{% endif %}{% endfor %}]';

        self::assertSame('Post 3,Post 4, page 2 of 3 prev=1 next=3 items 3-4 of 5 [1<2>3]', $this->render($source, ['page' => $page]));
    }
}
