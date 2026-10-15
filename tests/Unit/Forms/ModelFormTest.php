<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Forms;

use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Forms\Fields;
use BrewPHP\Forms\ModelForm;
use BrewPHP\Tests\DbTestCase;
use BrewPHP\Tests\Fixtures\Models\Author;
use BrewPHP\Tests\Fixtures\Models\Category;
use BrewPHP\Tests\Fixtures\Models\Post;
use BrewPHP\Tests\Fixtures\Models\PostStatus;

final class PostForm extends ModelForm
{
    protected function model(): string
    {
        return Post::class;
    }

    protected function fieldNames(): array
    {
        return ['title', 'slug', 'author', 'status', 'views', 'rating'];
    }
}

final class AuthorForm extends ModelForm
{
    protected function model(): string
    {
        return Author::class;
    }

    protected function fieldNames(): array
    {
        return ['__all__'];
    }
}

final class ModelFormTest extends DbTestCase
{
    public function testFieldsFromTheModel(): void
    {
        $fields = (new PostForm())->getFields();

        self::assertSame(['title', 'slug', 'author', 'status', 'views', 'rating'], array_keys($fields));
        self::assertInstanceOf(Fields\CharField::class, $fields['title']);
        self::assertSame(200, $fields['title'] instanceof Fields\CharField ? $fields['title']->maxLength : null);
        self::assertTrue($fields['title']->required);
        self::assertFalse($fields['slug']->required);
        self::assertInstanceOf(Fields\ModelChoiceField::class, $fields['author']);
        self::assertInstanceOf(Fields\ChoiceField::class, $fields['status']);
        self::assertSame(PostStatus::Draft, $fields['status']->initial);
        self::assertSame(0, $fields['views'] instanceof Fields\IntegerField ? $fields['views']->minValue : null);
        self::assertInstanceOf(Fields\DecimalField::class, $fields['rating']);

        // '__all__' takes editable fields only.
        self::assertSame(['name', 'email', 'active', 'score'], array_keys((new AuthorForm())->getFields()));
    }

    public function testCreatesAnObject(): void
    {
        $ann = $this->author();
        $form = new PostForm(['title' => 'Hello', 'author' => (string) $ann->pk, 'status' => 'published', 'views' => '3', 'rating' => '4.50']);

        self::assertTrue($form->isValid(), (string) json_encode($form->errors()));
        $post = $form->save();
        self::assertTrue($post->isPersisted());
        $post->refresh();
        self::assertSame('Hello', $post->title);
        self::assertSame(PostStatus::Published, $post->status);
        self::assertSame($ann->pk, $post->author_id);
        self::assertSame(3, $post->views);
    }

    public function testOnlyListedFieldsCanBeSet(): void
    {
        $ann = $this->author();
        $post = $this->post('Original', $ann, body: 'kept');
        $form = new PostForm(['title' => 'Changed', 'author' => (string) $ann->pk, 'status' => 'draft', 'views' => '0', 'body' => 'injected', 'id' => '999'], instance: $post);

        self::assertTrue($form->isValid(), (string) json_encode($form->errors()));
        $form->save();
        $post->refresh();
        self::assertSame('Changed', $post->title);
        self::assertSame('kept', $post->body);
        self::assertNotSame(999, $post->pk);
    }

    public function testEditingShowsTheObject(): void
    {
        $ann = $this->author();
        $post = $this->post('Original <title>', $ann);
        $html = (string) new PostForm(instance: $post);

        self::assertStringContainsString('value="Original &lt;title&gt;"', $html);
        self::assertStringContainsString('<option value="' . $ann->pk . '" selected>Ann</option>', $html);
    }

    public function testModelValidationErrorsGoToTheFields(): void
    {
        $ann = $this->author();
        $this->post('Taken', $ann, slug: 'taken');

        $form = new PostForm(['title' => 'forbidden', 'author' => (string) $ann->pk, 'status' => 'draft', 'views' => '0']);
        self::assertFalse($form->isValid());
        self::assertSame(['This title is not allowed.'], $form->errors()['title']);
        self::assertSame(['Try again.'], $form->errors()['__all__']);

        $form = new PostForm(['title' => 'New', 'slug' => 'taken', 'author' => (string) $ann->pk, 'status' => 'draft', 'views' => '0']);
        self::assertFalse($form->isValid());
        self::assertNotSame([], $form->errors());

        $this->expectException(\LogicException::class);
        $form->save();
    }

    public function testChoicesAreLimitedToTheQueryset(): void
    {
        $ann = $this->author();
        $bob = $this->author('Bob');
        $form = new class (['author' => (string) $bob->pk]) extends ModelForm {
            protected function model(): string
            {
                return Post::class;
            }

            protected function fieldNames(): array
            {
                return ['title', 'author'];
            }

            public function fields(): array
            {
                $fields = parent::fields();
                $fields['author'] = new Fields\ModelChoiceField(Author::objects()->filter(name: 'Ann'));

                return $fields;
            }
        };

        self::assertFalse($form->isValid());
        self::assertSame(['Select a valid choice. That choice is not one of the available choices.'], $form->errors()['author']);
        self::assertStringNotContainsString('Bob', (string) $form->author);
        self::assertStringContainsString('>Ann</option>', (string) $form->author);
        self::assertSame('Ann', (string) $ann);
    }

    public function testModelMultipleChoiceField(): void
    {
        Category::create(['name' => 'Tea', 'slug' => 'tea']);
        $coffee = Category::create(['name' => 'Coffee', 'slug' => 'coffee']);
        $field = new Fields\ModelMultipleChoiceField(Category::objects(), required: false);

        $cleaned = $field->clean([(string) $coffee->pk]);
        self::assertCount(1, $cleaned);
        self::assertSame('coffee', $cleaned[0]->slug);
        self::assertSame([], $field->clean([]));

        $this->expectException(\BrewPHP\Core\ValidationError::class);
        $field->clean(['99999']);
    }

    public function testFieldNamesMustBeListed(): void
    {
        $form = new class () extends ModelForm {
            protected function model(): string
            {
                return Author::class;
            }
        };

        $this->expectException(ImproperlyConfigured::class);
        $this->expectExceptionMessage('must list its fields');
        $form->getFields();
    }

    public function testNonEditableFieldsCannotBeListed(): void
    {
        $form = new class () extends ModelForm {
            protected function model(): string
            {
                return Author::class;
            }

            protected function fieldNames(): array
            {
                return ['name', 'created_at'];
            }
        };

        $this->expectException(ImproperlyConfigured::class);
        $form->getFields();
    }
}
