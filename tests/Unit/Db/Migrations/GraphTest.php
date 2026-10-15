<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Db\Migrations;

use BrewPHP\Db\Migrations\Graph;
use BrewPHP\Db\Migrations\MigrationError;

final class GraphTest extends MigrationTestCase
{
    private function graph(): Graph
    {
        return new Graph([
            self::migration('blog', '0002_post_slug', [], ['blog.0001_initial']),
            self::migration('blog', '0001_initial', [], ['auth.0001_initial']),
            self::migration('auth', '0001_initial', []),
            self::migration('auth', '0002_user_email', [], ['auth.0001_initial']),
            self::migration('blog', '0003_post_editor', [], ['blog.0002_post_slug', 'auth.0002_user_email']),
        ]);
    }

    public function testOrderPutsDependenciesFirst(): void
    {
        self::assertSame(
            ['auth.0001_initial', 'auth.0002_user_email', 'blog.0001_initial', 'blog.0002_post_slug', 'blog.0003_post_editor'],
            $this->graph()->order(),
        );
    }

    public function testLeavesAncestorsAndDescendants(): void
    {
        $graph = $this->graph();

        self::assertSame(['auth', 'blog'], $graph->apps());
        self::assertSame('blog.0003_post_editor', $graph->leaf('blog'));
        self::assertNull($graph->leaf('shop'));
        self::assertSame(['auth.0001_initial', 'blog.0001_initial', 'blog.0002_post_slug'], $graph->ancestors('blog.0002_post_slug'));
        self::assertSame(['auth.0002_user_email', 'blog.0003_post_editor'], $graph->descendants('auth.0002_user_email'));
    }

    public function testFindsByPrefix(): void
    {
        self::assertSame('blog.0002_post_slug', $this->graph()->find('blog', '0002'));
        self::assertSame('blog.0002_post_slug', $this->graph()->find('blog', '0002_post_slug'));

        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('More than one migration of "blog" starts with "000"');
        $this->graph()->find('blog', '000');
    }

    public function testMissingDependency(): void
    {
        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('Migration blog.0001_initial depends on auth.0001_initial, which does not exist.');
        new Graph([self::migration('blog', '0001_initial', [], ['auth.0001_initial'])]);
    }

    public function testCycle(): void
    {
        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('Circular dependency between migrations');
        new Graph([
            self::migration('a', '0001_initial', [], ['b.0001_initial']),
            self::migration('b', '0001_initial', [], ['a.0001_initial']),
        ]);
    }

    public function testConflictingLeaves(): void
    {
        $graph = new Graph([
            self::migration('blog', '0001_initial', []),
            self::migration('blog', '0002_a', [], ['blog.0001_initial']),
            self::migration('blog', '0002_b', [], ['blog.0001_initial']),
        ]);
        self::assertSame(['blog.0002_a', 'blog.0002_b'], $graph->leaves('blog'));

        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('Conflicting migrations in app "blog": blog.0002_a and blog.0002_b.');
        $graph->leaf('blog');
    }
}
