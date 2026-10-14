<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Templates;

use BrewPHP\Templates\PhpEngine;
use BrewPHP\Templates\TemplateNotFound;
use BrewPHP\Tests\TestCase;

final class PhpEngineTest extends TestCase
{
    private string $first;

    private string $second;

    protected function setUp(): void
    {
        $this->first = $this->tempDir();
        $this->second = $this->tempDir();
        mkdir($this->second . '/blog');
        file_put_contents($this->first . '/page.php', 'first <?= e($name) ?>');
        file_put_contents($this->second . '/page.php', 'second');
        file_put_contents($this->second . '/blog/post.php', '<h1><?= e($title) ?></h1><?= $html ?>');
        file_put_contents($this->second . '/broken.php', 'partial output <?php throw new RuntimeException("template failed");');
    }

    public function testFirstDirectoryWinsAndEscapes(): void
    {
        $engine = new PhpEngine([$this->first, $this->second]);

        self::assertSame('first &lt;b&gt;', $engine->render('page.php', ['name' => '<b>']));
        self::assertSame('<h1>A &amp; B</h1><em>ok</em>', $engine->render('blog/post.php', ['title' => 'A & B', 'html' => '<em>ok</em>']));
        self::assertSame($this->second . DIRECTORY_SEPARATOR . 'blog/post.php', $engine->find('blog/post.php'));
        self::assertTrue($engine->exists('page.php'));
        self::assertFalse($engine->exists('missing.php'));
    }

    public function testRenderFirstExisting(): void
    {
        $engine = new PhpEngine([$this->second]);

        self::assertSame('second', $engine->render(['missing.php', 'page.php']));
    }

    public function testAddDirectory(): void
    {
        $engine = new PhpEngine([$this->second]);
        $engine->addDirectory($this->first, prepend: true);

        self::assertSame([$this->first, $this->second], $engine->directories());
        self::assertSame('first x', $engine->render('page.php', ['name' => 'x']));
    }

    public function testMissingTemplate(): void
    {
        $engine = new PhpEngine([$this->first]);

        try {
            $engine->render('nope.php');
            self::fail('Expected TemplateNotFound');
        } catch (TemplateNotFound $e) {
            self::assertSame('nope.php', $e->template);
            self::assertStringContainsString($this->first, $e->getMessage());
        }
    }

    public function testExceptionCleansOutputBuffers(): void
    {
        $engine = new PhpEngine([$this->second]);
        $level = ob_get_level();

        try {
            $engine->render('broken.php');
            self::fail('Expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('template failed', $e->getMessage());
        }
        self::assertSame($level, ob_get_level());
    }

    public function testRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PhpEngine([$this->first]))->render('../secret.php');
    }
}
