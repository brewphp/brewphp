<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Http;

use BrewPHP\Http\UploadedFile;
use BrewPHP\Tests\TestCase;

final class UploadedFileTest extends TestCase
{
    public function testFakeUploadCanBeInspectedAndMoved(): void
    {
        $dir = $this->tempDir();
        $source = $dir . '/upload.tmp';
        file_put_contents($source, 'plain text');

        $file = UploadedFile::fake($source, 'Notes.TXT', 'text/plain');
        self::assertTrue($file->isValid());
        self::assertSame('', $file->errorMessage());
        self::assertSame('txt', $file->clientExtension());
        self::assertSame(10, $file->size);
        self::assertSame('plain text', $file->content());
        self::assertSame('text/plain', $file->mimeType());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.txt$/', $file->hashName());
        foreach (['shell.php', 'x.PHTML', 'a.php5', 'b.phar'] as $name) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', UploadedFile::fake($source, $name)->hashName(), $name);
        }

        $target = $file->moveTo($dir . '/media/notes', '../escape.txt');
        self::assertSame($dir . '/media/notes' . DIRECTORY_SEPARATOR . 'escape.txt', $target);
        self::assertFileExists($target);
        self::assertFileDoesNotExist($source);
    }

    public function testInvalidUploadCannotBeMoved(): void
    {
        $file = new UploadedFile('/tmp/nothing', 'a.txt', 'text/plain', UPLOAD_ERR_INI_SIZE);

        self::assertFalse($file->isValid());
        self::assertSame('The file is too large.', $file->errorMessage());
        $this->expectException(\RuntimeException::class);
        $file->moveTo($this->tempDir());
    }

    public function testRealUploadNeedsIsUploadedFile(): void
    {
        self::assertFalse((new UploadedFile(__FILE__, 'x.php'))->isValid());
    }

    public function testNormalizeKeepsNestedShape(): void
    {
        $files = UploadedFile::normalize([
            'avatar' => ['name' => 'me.png', 'type' => 'image/png', 'tmp_name' => '/tmp/a', 'error' => 0, 'size' => 3],
            'photos' => [
                'name' => ['one.jpg', 'two.jpg'],
                'type' => ['image/jpeg', 'image/jpeg'],
                'tmp_name' => ['/tmp/1', '/tmp/2'],
                'error' => [0, 4],
                'size' => [1, 0],
            ],
            'junk' => 'not a file',
        ]);

        self::assertInstanceOf(UploadedFile::class, $files['avatar']);
        self::assertSame('me.png', $files['avatar']->clientName);
        self::assertIsArray($files['photos']);
        self::assertInstanceOf(UploadedFile::class, $files['photos'][1]);
        self::assertSame(UPLOAD_ERR_NO_FILE, $files['photos'][1]->error);
        self::assertSame('No file was uploaded.', $files['photos'][1]->errorMessage());
        self::assertArrayNotHasKey('junk', $files);
    }
}
