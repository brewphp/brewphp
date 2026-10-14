<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Db;

use BrewPHP\Db\Count;
use BrewPHP\Db\F;
use BrewPHP\Db\Q;
use BrewPHP\Tests\Fixtures\Models\Author;
use BrewPHP\Tests\Fixtures\Models\Post;
use BrewPHP\Tests\TestCase;

/**
 * The SQL of querysets on MySQL and PostgreSQL. Connections are lazy, so
 * compiling queries needs no server.
 */
final class ServerSqlTest extends TestCase
{
    private function useEngine(string $engine): void
    {
        $this->makeApp(['DATABASES' => ['default' => ['ENGINE' => $engine, 'NAME' => 'app']]]);
    }

    public function testPostgresSelect(): void
    {
        $this->useEngine('pgsql');
        [$sql, $bindings] = Post::objects()
            ->filter(title__icontains: 'php', author__name__startswith: 'A', published_at__year: 2026)
            ->exclude(views__lt: 10)
            ->orderBy('author__name', '-views')
            ->slice(20, 10)
            ->toSql();

        self::assertSame(
            'SELECT "blog_post"."id", "blog_post"."title", "blog_post"."slug", "blog_post"."body", "blog_post"."author_id", "blog_post"."category_id", '
            . '"blog_post"."status", "blog_post"."views", "blog_post"."rating", "blog_post"."published_at", "blog_post"."metadata", "blog_post"."updated_at" '
            . 'FROM "blog_post" INNER JOIN "blog_author" "T1" ON "T1"."id" = "blog_post"."author_id" '
            . 'WHERE ("blog_post"."title"::text ILIKE ? AND "T1"."name"::text LIKE ? AND EXTRACT(YEAR FROM "blog_post"."published_at") = ?) '
            . 'AND NOT ("blog_post"."views" < ?) '
            . 'ORDER BY "T1"."name" ASC, "blog_post"."views" DESC LIMIT 10 OFFSET 20',
            $sql,
        );
        self::assertSame(['%php%', 'A%', 2026, 10], $bindings);
    }

    public function testMysqlSelect(): void
    {
        $this->useEngine('mysql');
        [$sql, $bindings] = Post::objects()
            ->where(Q::or(new Q(title__contains: 'PHP'), new Q(category__name__iexact: 'news')))
            ->exclude(slug: 'x')
            ->offset(5)
            ->toSql(['title', 'category__name']);

        self::assertSame(
            'SELECT `blog_post`.`title`, `T1`.`name` FROM `blog_post` LEFT JOIN `blog_category` `T1` ON `T1`.`id` = `blog_post`.`category_id` '
            . 'WHERE (`blog_post`.`title` LIKE BINARY ? OR `T1`.`name` LIKE ?) AND NOT ((`blog_post`.`slug` = ? AND `blog_post`.`slug` IS NOT NULL)) '
            . 'ORDER BY `blog_post`.`id` DESC LIMIT 18446744073709551615 OFFSET 5',
            $sql,
        );
        self::assertSame(['%PHP%', 'news', 'x'], $bindings);
    }

    public function testInSubqueryAndReverseJoin(): void
    {
        $this->useEngine('pgsql');
        [$sql, $bindings] = Author::objects()
            ->filter(posts__views__gte: 100, pk__in: Author::objects()->filter(active: true))
            ->distinct()
            ->toSql(['name']);

        self::assertSame(
            'SELECT DISTINCT "blog_author"."name" FROM "blog_author" LEFT JOIN "blog_post" "T1" ON "T1"."author_id" = "blog_author"."id" '
            . 'WHERE ("T1"."views" >= ? AND "blog_author"."id" IN (SELECT "blog_author"."id" FROM "blog_author" WHERE "blog_author"."active" = ?)) '
            . 'ORDER BY "blog_author"."name" ASC',
            $sql,
        );
        self::assertSame([100, true], $bindings);
    }

    public function testUpdateWithJoinUsesASubquery(): void
    {
        $this->useEngine('mysql');
        $compiler = new \BrewPHP\Db\Compiler(
            (static function (): \BrewPHP\Db\Query {
                $query = new \BrewPHP\Db\Query(Post::meta());
                $query->where[] = new Q(author__name: 'Ann');

                return $query;
            })(),
            (new \BrewPHP\Db\Grammars\MysqlGrammar()),
        );
        [$sql, $bindings] = $compiler->compileUpdate(['views' => (new F('views'))->add(1), 'status' => 'published']);

        self::assertSame(
            'UPDATE `blog_post` SET `views` = (`blog_post`.`views` + ?), `status` = ? WHERE `id` IN (SELECT `pk` FROM '
            . '(SELECT `blog_post`.`id` AS `pk` FROM `blog_post` INNER JOIN `blog_author` `T1` ON `T1`.`id` = `blog_post`.`author_id` WHERE `T1`.`name` = ?) `sub`)',
            $sql,
        );
        self::assertSame([1, 'published', 'Ann'], $bindings);
    }

    public function testAggregateAndCountSql(): void
    {
        $this->useEngine('pgsql');
        $compiler = new \BrewPHP\Db\Compiler(new \BrewPHP\Db\Query(Post::meta()), new \BrewPHP\Db\Grammars\PostgresGrammar());
        [$sql] = $compiler->compileAggregate(['n' => new Count('author', distinct: true)]);
        self::assertSame('SELECT COUNT(DISTINCT "blog_post"."author_id") AS "a0" FROM "blog_post"', $sql);

        $query = new \BrewPHP\Db\Query(Post::meta());
        $query->limit = 5;
        [$sql] = (new \BrewPHP\Db\Compiler($query, new \BrewPHP\Db\Grammars\PostgresGrammar()))->compileCount();
        self::assertSame('SELECT COUNT(*) FROM (SELECT "blog_post"."id" FROM "blog_post" ORDER BY "blog_post"."id" DESC LIMIT 5) "sub"', $sql);
    }
}
