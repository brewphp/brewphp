<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Forms;

use BrewPHP\Core\ValidationError;
use BrewPHP\Forms\Fields;
use BrewPHP\Forms\Form;
use BrewPHP\Forms\Html;
use BrewPHP\Forms\Widgets;
use BrewPHP\Http\Request;
use BrewPHP\Tests\TestCase;

final class ContactForm extends Form
{
    public function fields(): array
    {
        return [
            'name' => new Fields\CharField(maxLength: 50, helpText: 'Your <full> name'),
            'email' => new Fields\EmailField(),
            'topic' => new Fields\ChoiceField(['help' => 'Help', 'sales' => 'Sales <team>']),
            'message' => new Fields\CharField(widget: new Widgets\Textarea(['rows' => 3])),
            'source' => new Fields\CharField(required: false, widget: new Widgets\HiddenInput()),
            'agree' => new Fields\BooleanField(label: 'I agree'),
        ];
    }

    public function cleanName(string $name): string
    {
        if ($name === 'root') {
            throw new ValidationError('Pick another name.');
        }

        return ucfirst($name);
    }

    public function clean(array $data): array
    {
        if (($data['topic'] ?? null) === 'sales' && str_ends_with((string) ($data['email'] ?? ''), '@competitor.example')) {
            throw new ValidationError('No thanks.');
        }

        return $data;
    }
}

final class FormTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function valid(): array
    {
        return ['name' => 'ann', 'email' => 'ann@example.com', 'topic' => 'help', 'message' => 'Hi', 'agree' => 'on'];
    }

    public function testUnboundForm(): void
    {
        $form = new ContactForm(initial: ['name' => 'Ann']);

        self::assertFalse($form->isBound());
        self::assertFalse($form->isValid());
        self::assertSame([], $form->errors());
        $html = (string) $form;
        self::assertStringContainsString('<label for="id_name">Name:</label>', $html);
        self::assertStringContainsString('<input type="text" name="name" value="Ann" maxlength="50" id="id_name" required aria-describedby="id_name_helptext">', $html);
        self::assertStringContainsString('<div class="helptext" id="id_name_helptext">Your &lt;full&gt; name</div>', $html);
        self::assertStringContainsString('<option value="sales">Sales &lt;team&gt;</option>', $html);
        self::assertStringContainsString('<textarea cols="40" rows="3" name="message" id="id_message" required>', $html);
        self::assertStringContainsString('<input type="hidden" name="source" id="id_source">', $html);
        self::assertStringContainsString('<label for="id_agree">I agree:</label>', $html);
    }

    public function testValidData(): void
    {
        $form = new ContactForm(self::valid());

        self::assertTrue($form->isValid(), (string) json_encode($form->errors()));
        self::assertSame(['name' => 'Ann', 'email' => 'ann@example.com', 'topic' => 'help', 'message' => 'Hi', 'source' => '', 'agree' => true], $form->cleanedData());
    }

    public function testErrorsAndRedisplay(): void
    {
        $form = new ContactForm(['name' => 'root', 'email' => 'nope', 'topic' => 'beer', 'message' => '"><script>alert(1)</script>']);

        self::assertFalse($form->isValid());
        self::assertSame(['name', 'email', 'topic', 'agree'], array_keys($form->errors()));
        self::assertSame(['Pick another name.'], $form->errors()['name']);
        self::assertSame('<ul class="errorlist" id="id_email_error"><li>Enter a valid email address.</li></ul>', (string) $form->email->errors());
        self::assertArrayNotHasKey('email', $form->cleanedData());

        $html = (string) $form;
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testFormWideErrors(): void
    {
        $form = new ContactForm([...self::valid(), 'topic' => 'sales', 'email' => 'x@competitor.example']);

        self::assertFalse($form->isValid());
        self::assertSame(['No thanks.'], $form->errors()['__all__']);
        self::assertStringStartsWith('<ul class="errorlist nonfield"><li>No thanks.</li></ul>', (string) $form);

        $form = new ContactForm(self::valid());
        $form->addError('email', 'Already subscribed.');
        self::assertFalse($form->isValid());
        self::assertArrayNotHasKey('email', $form->cleanedData());
    }

    public function testPrefixAndBoundFields(): void
    {
        $form = new ContactForm(['billing-name' => 'bob', 'name' => 'ignored'], prefix: 'billing');

        self::assertSame('billing-name', $form->name->htmlName());
        self::assertSame('id_billing-name', $form->name->autoId());
        self::assertSame('bob', $form->name->value());
        self::assertStringContainsString('name="billing-name" value="bob"', (string) $form->name);
        self::assertSame(['name', 'email', 'topic', 'message', 'source', 'agree'], array_keys(iterator_to_array($form)));
        self::assertCount(1, $form->hiddenFields());
        self::assertFalse($form->isMultipart());
    }

    public function testFromRequest(): void
    {
        self::assertFalse(ContactForm::fromRequest(Request::create('GET', '/contact/?name=x'))->isBound());

        $form = ContactForm::fromRequest(Request::create('POST', '/contact/', self::valid()), prefix: '');
        self::assertTrue($form->isBound());
        self::assertTrue($form->isValid());
    }

    public function testDisabledFieldsIgnoreSubmittedData(): void
    {
        $form = new class (['plan' => 'premium']) extends Form {
            public function fields(): array
            {
                return ['plan' => new Fields\CharField(disabled: true, initial: 'free')];
            }
        };

        self::assertTrue($form->isValid());
        self::assertSame(['plan' => 'free'], $form->cleanedData());
        self::assertStringContainsString('value="free"', (string) $form->plan);
        self::assertStringContainsString(' disabled', (string) $form->plan);
    }

    public function testPasswordsAreNotRedisplayed(): void
    {
        $form = new class (['secret' => 'hunter2']) extends Form {
            public function fields(): array
            {
                return ['secret' => new Fields\CharField(widget: new Widgets\PasswordInput())];
            }
        };

        self::assertStringNotContainsString('hunter2', (string) $form);
    }

    public function testMultipleValueWidgets(): void
    {
        $form = new class (['tags' => ['a', 'c']]) extends Form {
            public function fields(): array
            {
                return [
                    'tags' => new Fields\MultipleChoiceField(['a' => 'A', 'b' => 'B', 'c' => 'C'], widget: new Widgets\CheckboxSelectMultiple()),
                    'size' => new Fields\ChoiceField(['s' => 'S', 'm' => 'M'], required: false, widget: new Widgets\RadioSelect()),
                ];
            }
        };

        self::assertTrue($form->isValid());
        $html = (string) $form->tags;
        self::assertStringContainsString('<input type="checkbox" name="tags[]" value="a" checked id="id_tags_0">', $html);
        self::assertStringContainsString('<input type="checkbox" name="tags[]" value="b" id="id_tags_1">', $html);
        self::assertStringContainsString('<label for="id_tags_0">', (string) $form->tags->labelTag());
        self::assertStringContainsString('<input type="radio" name="size" value="s" id="id_size_0">', (string) $form->size);
    }

    public function testFileFieldsMakeTheFormMultipart(): void
    {
        $form = new class () extends Form {
            public function fields(): array
            {
                return ['avatar' => new Fields\FileField(required: false)];
            }
        };

        self::assertTrue($form->isMultipart());
    }

    public function testHtmlAttributesAreChecked(): void
    {
        self::assertSame(' a="&quot;&gt;" b', Html::attributes(['a' => '">', 'b' => true, 'c' => false, 'd' => null]));

        $this->expectException(\InvalidArgumentException::class);
        Html::attributes(['onload="x"' => 'y']);
    }

    public function testFormsRenderUnescapedInTemplatesButValuesAreEscaped(): void
    {
        $app = $this->makeApp();
        $form = new ContactForm(['name' => '<b>x</b>']);
        $html = $app->templates()->renderString('{{ form.name }}|{{ form.name.label_tag }}|{{ form.email.errors }}|{% for f in form %}{{ f.name }},{% endfor %}', ['form' => $form]);

        self::assertStringContainsString('<input type="text" name="name" value="&lt;b&gt;x&lt;/b&gt;"', $html);
        self::assertStringContainsString('|<label for="id_name">Name:</label>|', $html);
        self::assertStringContainsString('<ul class="errorlist" id="id_email_error"><li>This field is required.</li></ul>', $html);
        self::assertStringContainsString('|name,email,topic,message,source,agree,', $html);
    }
}
