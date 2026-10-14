<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Db;

use Apps\Demo\Models\Tag;
use Apps\Demo\Note;
use BrewPHP\Db\FieldError;
use BrewPHP\Db\Registry;
use BrewPHP\Tests\Fixtures\Models\Author;
use BrewPHP\Tests\Fixtures\Models\BlogModel;
use BrewPHP\Tests\TestCase;

final class RegistryTest extends TestCase
{
    public function testAppModelsAreLoadedAtBoot(): void
    {
        $app = $this->makeApp(['DATABASES' => ['default' => ['ENGINE' => 'sqlite', 'NAME' => ':memory:']]]);

        self::assertTrue(class_exists(Note::class, false), 'models.php is loaded at boot');
        self::assertTrue(class_exists(Tag::class, false), 'Models/*.php classes are loaded at boot');
        self::assertSame([Note::class, Tag::class], Registry::models('demo'));
        self::assertSame('demo_note', Note::meta()->table);
        self::assertSame('demo_tag', Tag::meta()->table);
        self::assertSame('demo', Tag::meta()->appLabel);
        self::assertSame(Tag::class, Registry::get('demo.tag'));
        self::assertSame($app->apps()->get('demo')->path . DIRECTORY_SEPARATOR . 'Models', $app->apps()->get('demo')->modelsPath());

        // "Tag" in a ForeignKey means a model of the same app.
        self::assertSame(Tag::class, Note::meta()->foreignKeys()['tag']->target());

        $editor = $app->db()->schemaEditor();
        $editor->createModel(Tag::class);
        $editor->createModel(Note::class);
        $tag = Tag::create(['slug' => 'php']);
        Note::create(['text' => 'Hello', 'tag' => $tag]);
        self::assertSame(['Hello'], $tag->notes->valuesList('text', flat: true));
        self::assertSame(['php'], Tag::objects()->filter(notes__text: 'Hello')->valuesList('slug', flat: true));
    }

    public function testAbstractModelsAreNotRegistered(): void
    {
        class_exists(Author::class);

        self::assertContains(Author::class, Registry::models());
        self::assertNotContains(BlogModel::class, Registry::models());
    }

    public function testAppLabelWithoutAnApplication(): void
    {
        self::assertSame('blog', Registry::appLabelFor('Apps\\Blog\\Models\\Post'));
        self::assertSame('shop', Registry::appLabelFor('Vendor\\Shop\\Product'));
        self::assertSame('app', Registry::appLabelFor('Product'));
    }

    public function testUnknownModels(): void
    {
        $this->expectException(FieldError::class);
        $this->expectExceptionMessage('No model "demo.Missing" is loaded');
        Registry::get('demo.Missing');
    }

    public function testSelfNeedsAModel(): void
    {
        $this->expectException(FieldError::class);
        Registry::resolve('self');
    }
}
