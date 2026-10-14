<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Templates;

use BrewPHP\Templates\Library;

final class CacheTest extends TemplateTestCase
{
    /**
     * @return list<string>
     */
    private function compiledFiles(): array
    {
        return glob($this->cache . '/*.php') ?: [];
    }

    public function testCompiledFilesAreWrittenAndReused(): void
    {
        $this->write(['page.html' => 'Hello {{ name }}']);

        self::assertSame('Hello Ann', $this->templates()->render('page.html', ['name' => 'Ann']));
        $files = $this->compiledFiles();
        self::assertCount(1, $files);
        $code = (string) file_get_contents($files[0]);
        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);", $code);
        self::assertStringContainsString('// line 1', $code);

        // Mark the compiled file so we can tell it is reused, not rebuilt.
        file_put_contents($files[0], str_replace("'Hello '", "'Cached '", $code));
        self::assertSame('Cached Bob', $this->templates()->render('page.html', ['name' => 'Bob']));
        self::assertCount(1, $this->compiledFiles());
    }

    public function testChangedSourcesAreCompiledAgain(): void
    {
        $this->write(['page.html' => 'one']);
        self::assertSame('one', $this->templates()->render('page.html'));

        // Same second, different size.
        $this->write(['page.html' => 'two!']);
        self::assertSame('two!', $this->templates()->render('page.html'));

        // Same size, newer modification time.
        $this->write(['page.html' => 'six!']);
        touch($this->dir . '/page.html', time() + 10);
        self::assertSame('six!', $this->templates()->render('page.html'));
        self::assertCount(1, $this->compiledFiles());
    }

    public function testCacheOffCompilesEveryTime(): void
    {
        $this->write(['page.html' => 'fresh']);
        self::assertSame('fresh', $this->templates(cache: false)->render('page.html'));
        $file = $this->compiledFiles()[0];
        file_put_contents($file, str_replace("'fresh'", "'stale'", (string) file_get_contents($file)));

        self::assertSame('fresh', $this->templates(cache: false)->render('page.html'));
        self::assertSame('fresh', $this->templates(cache: true)->render('page.html'));
    }

    public function testRegisteringTagsChangesTheCacheKey(): void
    {
        $this->write(['page.html' => 'x']);
        $this->templates()->render('page.html');

        $templates = $this->templates();
        $templates->library((new Library())->simpleTag('extra', static fn (): string => 'e'));
        $templates->render('page.html');

        self::assertCount(2, $this->compiledFiles());
    }

    public function testTemplatesAreLoadedOncePerEngine(): void
    {
        $this->write(['page.html' => 'x']);
        $engine = $this->templates()->engine();

        self::assertSame($engine->getTemplate('page.html'), $engine->getTemplate('page.html'));
    }

    public function testCacheFolderIsCreated(): void
    {
        $cache = $this->tempDir() . '/deep/templates';
        $templates = new \BrewPHP\Templates\Templates([$this->dir], $cache);

        self::assertSame('ok', $templates->renderString('ok'));
        self::assertDirectoryExists($cache);
    }
}
