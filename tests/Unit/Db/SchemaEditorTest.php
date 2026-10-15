<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Db;

use BrewPHP\Db\Connection;
use BrewPHP\Db\FieldError;
use BrewPHP\Db\Fields\BooleanField;
use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Fields\IntegerField;
use BrewPHP\Db\Fields\TextField;
use BrewPHP\Db\Index;
use BrewPHP\Db\IntegrityError;
use BrewPHP\Db\ModelMeta;
use BrewPHP\Tests\DbTestCase;
use BrewPHP\Tests\Fixtures\Models\Author;
use BrewPHP\Tests\Fixtures\Models\Category;
use BrewPHP\Tests\Fixtures\Models\Item;
use BrewPHP\Tests\Fixtures\Models\Post;

final class SchemaEditorTest extends DbTestCase
{
    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        return array_map(static fn (array $row): string => (string) $row['name'], $this->db()->select('PRAGMA table_info(' . $table . ')'));
    }

    /**
     * @return list<string>
     */
    private function indexes(string $table): array
    {
        $names = array_map(static fn (array $row): string => (string) $row['name'], $this->db()->select('PRAGMA index_list(' . $table . ')'));
        sort($names);

        return array_values(array_filter($names, static fn (string $name): bool => !str_starts_with($name, 'sqlite_autoindex')));
    }

    private function sqlite(): string
    {
        return (string) $this->db()->scalar("SELECT sql FROM sqlite_master WHERE name = 'blog_post'");
    }

    /**
     * A detached table description, like the ones migrations will build.
     *
     * @param array<string, \BrewPHP\Db\Fields\Field> $fields
     */
    private static function meta(array $fields, string $table = 'shop_product'): ModelMeta
    {
        return new ModelMeta('', $table, $fields, 'shop', 'Product');
    }

    public function testCreateModelCreatesColumnsConstraintsAndIndexes(): void
    {
        self::assertSame(['id', 'title', 'slug', 'body', 'author_id', 'category_id', 'status', 'views', 'rating', 'published_at', 'metadata', 'updated_at'], $this->columns('blog_post'));
        $sql = $this->sqlite();
        self::assertStringContainsString('"id" integer NOT NULL PRIMARY KEY AUTOINCREMENT', $sql);
        self::assertStringContainsString('"title" varchar(200) NOT NULL', $sql);
        self::assertStringContainsString('"rating" decimal(5, 2) NULL', $sql);
        self::assertMatchesRegularExpression('/CONSTRAINT "blog_post_author_id_[0-9a-f]{8}_fk" FOREIGN KEY \("author_id"\) REFERENCES "blog_author" \("id"\) DEFERRABLE INITIALLY DEFERRED/', $sql);
        $expected = [
            'blog_post_author_id_slug_' . substr(md5('blog_post|author_id|slug|uniq'), 0, 8) . '_uniq',
            $this->db()->grammar()->indexName('blog_post', ['author_id'], 'idx'),
            $this->db()->grammar()->indexName('blog_post', ['category_id'], 'idx'),
            $this->db()->grammar()->indexName('blog_post', ['slug'], 'idx'),
        ];
        sort($expected);
        self::assertSame($expected, $this->indexes('blog_post'));
        self::assertStringContainsString('UNIQUE ("slug")', (string) $this->db()->scalar("SELECT sql FROM sqlite_master WHERE name = 'blog_category'"));
    }

    public function testDeleteAndRenameModel(): void
    {
        $editor = $this->db()->schemaEditor();
        $editor->createModel(self::meta(['name' => new CharField()]));
        $editor->renameModel('shop_product', 'shop_item');
        self::assertSame(['id', 'name'], $this->columns('shop_item'));

        $editor->deleteModel(self::meta(['name' => new CharField()], 'shop_item'));
        self::assertSame([], $this->columns('shop_item'));
        self::assertCount(3, $editor->collectedSql());
    }

    public function testAddFieldWithAlterTable(): void
    {
        $ann = $this->author();
        $editor = $this->db()->schemaEditor();
        $editor->addField(Author::class, self::attached(Author::class, 'nickname', new CharField(maxLength: 30, null: true)));
        $editor->addField(Author::class, self::attached(Author::class, 'level', new IntegerField(default: 3, dbIndex: true)));

        self::assertSame(['id', 'name', 'email', 'active', 'score', 'created_at', 'nickname', 'level'], $this->columns('blog_author'));
        self::assertSame(3, $this->db()->scalar('SELECT level FROM blog_author WHERE id = ?', [$ann->pk]));
        self::assertContains($this->db()->grammar()->indexName('blog_author', ['level'], 'idx'), $this->indexes('blog_author'));
        self::assertStringStartsWith('ALTER TABLE "blog_author" ADD COLUMN "nickname" varchar(30) NULL', $editor->collectedSql()[0]);
    }

    public function testAddFieldThatNeedsARebuildKeepsTheRows(): void
    {
        $ann = $this->author('Ann');
        $this->post('Kept', $ann, views: 7);

        // A UNIQUE column cannot be added with ALTER TABLE in SQLite.
        $this->db()->schemaEditor()->addField(Post::class, self::attached(Post::class, 'code', new CharField(maxLength: 10, default: 'x', unique: true)));

        self::assertSame([['title' => 'Kept', 'views' => 7, 'code' => 'x']], $this->db()->select('SELECT title, views, code FROM blog_post'));
        self::assertStringContainsString('UNIQUE ("code")', $this->sqlite());
        // Indexes and foreign keys survive the rebuild.
        self::assertContains($this->db()->grammar()->indexName('blog_post', ['author_id'], 'idx'), $this->indexes('blog_post'));
        self::assertSame('1', (string) $this->db()->scalar('PRAGMA foreign_keys'));
        self::assertSame(1, Post::objects()->filter(author__name: 'Ann')->count());
    }

    public function testAddingANonNullableFieldWithoutDefaultFailsWhenTheTableHasRows(): void
    {
        // An empty table accepts it, like on other databases.
        $this->db()->schemaEditor()->addField(Item::class, self::attached(Item::class, 'code', new CharField(unique: true)));
        self::assertContains('code', $this->columns(Item::meta()->table));

        $this->post('Hello', $this->author());
        $this->expectException(FieldError::class);
        $this->expectExceptionMessage('without a default');
        $this->db()->schemaEditor()->addField(Post::class, self::attached(Post::class, 'code', new CharField(unique: true)));
    }

    public function testRemoveField(): void
    {
        $this->post('Hello', $this->author(), body: 'text');
        $this->db()->schemaEditor()->removeField(Post::class, Post::meta()->field('body'));

        self::assertNotContains('body', $this->columns('blog_post'));
        self::assertSame('Hello', $this->db()->scalar('SELECT title FROM blog_post'));
    }

    public function testAlterFieldRebuildsWithNewDefinition(): void
    {
        $this->post('Hello', $this->author());
        $editor = $this->db()->schemaEditor();
        $old = Post::meta()->field('title');
        $editor->alterField(Post::class, $old, self::attached(Post::class, 'headline', new TextField(null: true)));

        self::assertContains('headline', $this->columns('blog_post'));
        self::assertNotContains('title', $this->columns('blog_post'));
        self::assertSame('Hello', $this->db()->scalar('SELECT headline FROM blog_post'));
        self::assertStringContainsString('"headline" text NULL', $this->sqlite());
        self::assertSame('INSERT INTO "new__blog_post" ("id", "headline", "slug", "body", "author_id", "category_id", "status", "views", "rating", "published_at", "metadata", "updated_at") SELECT "id", "title", "slug", "body", "author_id", "category_id", "status", "views", "rating", "published_at", "metadata", "updated_at" FROM "blog_post"', $editor->collectedSql()[1]);
    }

    public function testAlterFieldToNotNullUsesTheDefault(): void
    {
        $this->post('Hello', $this->author());
        $this->db()->schemaEditor()->alterField(Post::class, Post::meta()->field('rating'), self::attached(Post::class, 'rating', new IntegerField(default: 0)));
        self::assertSame(0, $this->db()->scalar('SELECT rating FROM blog_post'));

        // Without a default, it fails only because a row really is NULL.
        $this->expectException(IntegrityError::class);
        $this->db()->schemaEditor()->alterField(Post::class, Post::meta()->field('published_at'), self::attached(Post::class, 'published_at', new IntegerField()));
    }

    public function testRenameField(): void
    {
        $this->post('Hello', $this->author());
        $this->db()->schemaEditor()->renameField(Post::class, Post::meta()->field('author'), 'writer');

        self::assertContains('writer_id', $this->columns('blog_post'));
        self::assertContains($this->db()->grammar()->indexName('blog_post', ['writer_id'], 'idx'), $this->indexes('blog_post'));
        self::assertStringContainsString('FOREIGN KEY ("writer_id") REFERENCES "blog_author"', $this->sqlite());
        self::assertSame('author', Post::meta()->field('author')->name, 'the model field itself is not changed');
    }

    public function testAddAndRemoveIndex(): void
    {
        $editor = $this->db()->schemaEditor();
        $index = new Index(['title', 'views']);
        $named = new Index(['status'], name: 'post_status_idx', unique: false);
        $editor->addIndex(Post::class, $index);
        $editor->addIndex(Post::class, $named);

        $name = $this->db()->grammar()->indexName('blog_post', ['title', 'views'], 'idx');
        self::assertContains($name, $this->indexes('blog_post'));
        self::assertContains('post_status_idx', $this->indexes('blog_post'));

        $editor->removeIndex(Post::class, $index);
        $editor->removeIndex(Post::class, $named);
        self::assertNotContains($name, $this->indexes('blog_post'));
        self::assertSame('DROP INDEX "post_status_idx"', $editor->collectedSql()[3]);
    }

    public function testRebuildIsRefusedInsideATransactionWithForeignKeyChecks(): void
    {
        $this->expectException(\LogicException::class);
        $this->db()->transaction(fn () => $this->db()->schemaEditor()->removeField(Post::class, Post::meta()->field('body')));
    }

    public function testCollectOnlyDoesNotTouchTheDatabase(): void
    {
        $editor = $this->db()->schemaEditor(collectOnly: true);
        $editor->removeField(Post::class, Post::meta()->field('body'));

        self::assertContains('body', $this->columns('blog_post'));
        self::assertCount(8, $editor->collectedSql());
        self::assertSame('DROP TABLE "blog_post"', $editor->collectedSql()[2]);
    }

    public function testFieldsMustBeAttached(): void
    {
        $this->expectException(FieldError::class);
        $this->db()->schemaEditor()->addField(Post::class, new CharField());
    }

    // MySQL and PostgreSQL: the generated SQL ------------------------------------

    /**
     * @return list<string>
     */
    private static function collect(string $engine, callable $callback): array
    {
        $editor = (new Connection('test', ['ENGINE' => $engine, 'NAME' => 'test']))->schemaEditor(collectOnly: true);
        $callback($editor);

        return $editor->collectedSql();
    }

    public function testPostgresCreateModel(): void
    {
        $sql = self::collect('pgsql', fn ($editor) => $editor->createModel(Post::class));
        $fk = 'blog_post_author_id_' . substr(md5('blog_post|author_id|fk'), 0, 8) . '_fk';

        self::assertSame(
            'CREATE TABLE "blog_post" ("id" bigint NOT NULL PRIMARY KEY GENERATED BY DEFAULT AS IDENTITY, "title" varchar(200) NOT NULL, '
            . '"slug" varchar(50) NULL, "body" text NOT NULL, "author_id" bigint NOT NULL, "category_id" bigint NULL, "status" varchar(20) NOT NULL, '
            . '"views" integer NOT NULL, "rating" numeric(5, 2) NULL, "published_at" timestamp NULL, "metadata" jsonb NOT NULL, "updated_at" timestamp NOT NULL, '
            . 'CONSTRAINT "' . $fk . '" FOREIGN KEY ("author_id") REFERENCES "blog_author" ("id") DEFERRABLE INITIALLY DEFERRED, '
            . 'CONSTRAINT "blog_post_category_id_' . substr(md5('blog_post|category_id|fk'), 0, 8) . '_fk" FOREIGN KEY ("category_id") REFERENCES "blog_category" ("id") DEFERRABLE INITIALLY DEFERRED)',
            $sql[0],
        );
        self::assertCount(5, $sql, 'table + 3 field indexes + 1 unique index');
        self::assertStringStartsWith('CREATE UNIQUE INDEX "blog_post_author_id_slug_', $sql[4]);
        self::assertStringEndsWith('ON "blog_post" ("author_id", "slug")', $sql[4]);

        $category = self::collect('pgsql', fn ($editor) => $editor->createModel(Category::class));
        self::assertStringContainsString('CONSTRAINT "blog_category_slug_' . substr(md5('blog_category|slug|uniq'), 0, 8) . '_uniq" UNIQUE ("slug")', $category[0]);
        self::assertCount(1, $category, 'a unique slug needs no extra index');
    }

    public function testMysqlCreateModel(): void
    {
        $sql = self::collect('mysql', fn ($editor) => $editor->createModel(Post::class));

        self::assertStringStartsWith('CREATE TABLE `blog_post` (`id` bigint NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` varchar(200) NOT NULL', $sql[0]);
        self::assertStringContainsString('`body` longtext NOT NULL', $sql[0]);
        self::assertStringContainsString('`rating` decimal(5, 2) NULL', $sql[0]);
        self::assertStringContainsString('`published_at` datetime NULL', $sql[0]);
        self::assertStringContainsString('`metadata` json NOT NULL', $sql[0]);
        self::assertStringContainsString('FOREIGN KEY (`author_id`) REFERENCES `blog_author` (`id`), ', $sql[0]);
        self::assertStringNotContainsString('DEFERRABLE', $sql[0]);
        self::assertStringContainsString('`active` bool NOT NULL', self::collect('mysql', fn ($editor) => $editor->createModel(Author::class))[0]);
    }

    public function testPostgresAlterField(): void
    {
        $old = Post::meta()->field('title');
        $new = self::attached(Post::class, 'title', new TextField(null: true, unique: true));
        $sql = self::collect('pgsql', fn ($editor) => $editor->alterField(Post::class, $old, $new));

        self::assertSame([
            'ALTER TABLE "blog_post" ALTER COLUMN "title" TYPE text USING "title"::text',
            'ALTER TABLE "blog_post" ALTER COLUMN "title" DROP NOT NULL',
            'ALTER TABLE "blog_post" ADD CONSTRAINT "blog_post_title_' . substr(md5('blog_post|title|uniq'), 0, 8) . '_uniq" UNIQUE ("title")',
        ], $sql);

        $grammar = (new Connection('x', ['ENGINE' => 'pgsql']))->grammar();
        $renamed = self::collect('pgsql', fn ($editor) => $editor->renameField(Post::class, Post::meta()->field('author'), 'writer'));
        self::assertSame([
            'ALTER TABLE "blog_post" DROP CONSTRAINT "' . $grammar->indexName('blog_post', ['author_id'], 'fk') . '"',
            'DROP INDEX "' . $grammar->indexName('blog_post', ['author_id'], 'idx') . '"',
            'ALTER TABLE "blog_post" RENAME COLUMN "author_id" TO "writer_id"',
            'CREATE INDEX "' . $grammar->indexName('blog_post', ['writer_id'], 'idx') . '" ON "blog_post" ("writer_id")',
            'ALTER TABLE "blog_post" ADD CONSTRAINT "' . $grammar->indexName('blog_post', ['writer_id'], 'fk') . '" FOREIGN KEY ("writer_id") REFERENCES "blog_author" ("id") DEFERRABLE INITIALLY DEFERRED',
        ], $renamed);

        $notNull = self::collect('pgsql', fn ($editor) => $editor->alterField(Post::class, Post::meta()->field('slug'), self::attached(Post::class, 'slug', new CharField(maxLength: 50, dbIndex: false))));
        self::assertSame([
            'DROP INDEX "' . $grammar->indexName('blog_post', ['slug'], 'idx') . '"',
            'ALTER TABLE "blog_post" ALTER COLUMN "slug" SET NOT NULL',
        ], $notNull);
    }

    public function testMysqlAlterAndRemoveField(): void
    {
        $grammar = (new Connection('x', ['ENGINE' => 'mysql']))->grammar();
        $sql = self::collect('mysql', fn ($editor) => $editor->alterField(Post::class, Post::meta()->field('views'), self::attached(Post::class, 'views', new IntegerField(null: true))));
        self::assertSame(['ALTER TABLE `blog_post` MODIFY `views` integer NULL'], $sql);

        self::assertSame([
            'ALTER TABLE `blog_post` DROP FOREIGN KEY `' . $grammar->indexName('blog_post', ['author_id'], 'fk') . '`',
            'ALTER TABLE `blog_post` DROP COLUMN `author_id`',
        ], self::collect('mysql', fn ($editor) => $editor->removeField(Post::class, Post::meta()->field('author'))));

        $unique = self::collect('mysql', fn ($editor) => $editor->alterField(Category::class, Category::meta()->field('slug'), self::attached(Category::class, 'slug', new CharField(maxLength: 50))));
        self::assertSame(['ALTER TABLE `blog_category` DROP INDEX `' . $grammar->indexName('blog_category', ['slug'], 'uniq') . '`'], $unique);

        self::assertSame(['RENAME TABLE `a` TO `b`'], self::collect('mysql', fn ($editor) => $editor->renameModel('a', 'b')));
        self::assertSame(['DROP INDEX `i` ON `blog_post`'], self::collect('mysql', fn ($editor) => $editor->removeIndex(Post::class, new Index(['title'], name: 'i'))));
    }

    public function testAddFieldOnServers(): void
    {
        $field = self::attached(Post::class, 'editor', new ForeignKey(Author::class, null: true));
        $grammar = (new Connection('x', ['ENGINE' => 'pgsql']))->grammar();

        self::assertSame([
            'ALTER TABLE "blog_post" ADD COLUMN "editor_id" bigint NULL',
            'ALTER TABLE "blog_post" ADD CONSTRAINT "' . $grammar->indexName('blog_post', ['editor_id'], 'fk') . '" FOREIGN KEY ("editor_id") REFERENCES "blog_author" ("id") DEFERRABLE INITIALLY DEFERRED',
            'CREATE INDEX "' . $grammar->indexName('blog_post', ['editor_id'], 'idx') . '" ON "blog_post" ("editor_id")',
        ], self::collect('pgsql', fn ($editor) => $editor->addField(Post::class, $field)));

        self::assertSame(
            ['ALTER TABLE `blog_post` ADD COLUMN `featured` bool DEFAULT 0 NOT NULL'],
            self::collect('mysql', fn ($editor) => $editor->addField(Post::class, self::attached(Post::class, 'featured', new BooleanField(default: false)))),
        );
        self::assertSame(
            ["ALTER TABLE \"blog_post\" ADD COLUMN \"note\" varchar(20) DEFAULT 'it''s' NOT NULL"],
            self::collect('pgsql', fn ($editor) => $editor->addField(Post::class, self::attached(Post::class, 'note', new CharField(maxLength: 20, default: "it's")))),
        );
    }

    private static function attached(string $model, string $name, \BrewPHP\Db\Fields\Field $field): \BrewPHP\Db\Fields\Field
    {
        $field->contributeTo($model, $name);

        return $field;
    }
}
