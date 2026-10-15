<?php

declare(strict_types=1);

namespace BrewPHP\Forms;

use BrewPHP\Core\ValidationError;
use BrewPHP\Forms\Fields\Field;
use BrewPHP\Http\Request;
use BrewPHP\Support\Str;
use BrewPHP\Templates\HtmlSafe;

/**
 * A form, like Django's: a set of fields that validate submitted data and
 * render themselves as HTML.
 *
 *     final class ContactForm extends Form
 *     {
 *         public function fields(): array
 *         {
 *             return [
 *                 'name' => new Fields\CharField(maxLength: 100),
 *                 'email' => new Fields\EmailField(),
 *                 'message' => new Fields\CharField(widget: new Widgets\Textarea()),
 *             ];
 *         }
 *
 *         // One field: return the clean value or throw ValidationError.
 *         public function cleanEmail(string $email): string { ... }
 *
 *         // Several fields together.
 *         public function clean(array $data): array { ... }
 *     }
 *
 *     $form = ContactForm::fromRequest($request);   // bound on POST, empty on GET
 *     if ($request->isMethod('POST') && $form->isValid()) {
 *         send_mail($form->cleanedData()['email'], ...);
 *     }
 *     return render('contact.html', ['form' => $form]);
 *
 * In a template, `{{ form }}` prints every field with its label, help text
 * and errors; `{{ form.email }}`, `{{ form.email.errors }}` and
 * `{% for field in form %}` give finer control. Add `{% csrf_token %}`
 * inside the <form> yourself. Every value is escaped.
 *
 * @implements \IteratorAggregate<string, BoundField>
 *
 * @phpstan-consistent-constructor
 */
class Form implements HtmlSafe, \IteratorAggregate
{
    /** @var array<string, Field>|null */
    private ?array $fieldObjects = null;

    /** @var array<string, list<string>>|null */
    private ?array $errors = null;

    /** @var array<string, mixed> */
    protected array $cleaned = [];

    /**
     * @param array<string, mixed>|null $data submitted data (null: an unbound form)
     * @param array<string, mixed> $files uploaded files by field name
     * @param array<string, mixed> $initial values for an unbound form, by field name
     * @param string $prefix prefix of the HTML names, to use several forms in one <form>
     * @param string|null $autoId format of the HTML ids ("id_%s"), or null for none
     */
    public function __construct(
        protected ?array $data = null,
        protected array $files = [],
        protected array $initial = [],
        public readonly string $prefix = '',
        public readonly ?string $autoId = 'id_%s',
    ) {
    }

    /**
     * A form bound to the request's POST data and files when the request
     * is a POST (or PUT/PATCH), or an unbound one otherwise. Other
     * constructor arguments can be given by name, e.g. `prefix: 'billing'`
     * or, for a ModelForm, `instance: $post`.
     *
     * @param array<string, mixed> $initial
     */
    public static function fromRequest(Request $request, array $initial = [], mixed ...$arguments): static
    {
        $bound = in_array($request->method(), ['POST', 'PUT', 'PATCH'], true);

        return new static($bound ? (array) $request->post() : null, $bound ? $request->files() : [], $initial, ...$arguments);
    }

    /**
     * The fields, by name. Override it to declare the form.
     *
     * @return array<string, Field>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * Validate several fields together. `$data` holds the fields that are
     * valid so far; return it (changed if you like) or throw ValidationError,
     * whose messages go to the whole form (or to fields, with an array).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function clean(array $data): array
    {
        return $data;
    }

    // Data ----------------------------------------------------------------------

    public function isBound(): bool
    {
        return $this->data !== null;
    }

    /**
     * Whether the form is bound and every value is valid.
     */
    public function isValid(): bool
    {
        return $this->isBound() && $this->errors() === [];
    }

    /**
     * Error messages by field name; `__all__` holds errors of the whole form.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        if ($this->errors === null) {
            $this->errors = [];
            if ($this->isBound()) {
                $this->fullClean();
            }
        }

        return $this->errors ?? [];
    }

    public function hasError(string $field): bool
    {
        return isset($this->errors()[$field]);
    }

    /**
     * Add an error to a field (or to the whole form with null). The field's
     * clean value is dropped.
     */
    public function addError(?string $field, string|ValidationError $error): void
    {
        $this->errors();
        $byField = $error instanceof ValidationError ? $error->errors() : [ValidationError::NON_FIELD => [$error]];
        foreach ($byField as $name => $messages) {
            $name = $name === ValidationError::NON_FIELD && $field !== null ? $field : $name;
            if ($name !== ValidationError::NON_FIELD && !isset($this->getFields()[$name])) {
                $name = ValidationError::NON_FIELD;
            }
            foreach ($messages as $message) {
                $this->errors[$name][] = $message;
            }
            unset($this->cleaned[$name]);
        }
    }

    /**
     * The clean values (only those that passed validation).
     *
     * @return array<string, mixed>
     */
    public function cleanedData(): array
    {
        $this->errors();

        return $this->cleaned;
    }

    public function nonFieldErrors(): ErrorList
    {
        return new ErrorList($this->errors()[ValidationError::NON_FIELD] ?? [], class: 'errorlist nonfield');
    }

    /**
     * The submitted data (an empty array for an unbound form).
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * The initial value of a field: from the form's `$initial`, else the field's.
     */
    public function initialFor(string $name): mixed
    {
        $value = array_key_exists($name, $this->initial) ? $this->initial[$name] : ($this->getFields()[$name] ?? null)?->initial;

        return $value instanceof \Closure ? $value() : $value;
    }

    // Fields --------------------------------------------------------------------

    /**
     * @return array<string, Field>
     */
    public function getFields(): array
    {
        return $this->fieldObjects ??= $this->fields();
    }

    public function field(string $name): BoundField
    {
        $field = $this->getFields()[$name] ?? throw new \InvalidArgumentException(sprintf(
            '%s has no field "%s". Fields are: %s.',
            static::class,
            $name,
            implode(', ', array_keys($this->getFields())),
        ));

        return new BoundField($this, $field, $name);
    }

    /**
     * `{{ form.email }}` in templates.
     */
    public function __get(string $name): BoundField
    {
        return $this->field($name);
    }

    public function __isset(string $name): bool
    {
        return isset($this->getFields()[$name]);
    }

    /**
     * @return \ArrayIterator<string, BoundField>
     */
    public function getIterator(): \ArrayIterator
    {
        $fields = [];
        foreach (array_keys($this->getFields()) as $name) {
            $fields[$name] = $this->field($name);
        }

        return new \ArrayIterator($fields);
    }

    /**
     * @return list<BoundField>
     */
    public function visibleFields(): array
    {
        return array_values(array_filter(iterator_to_array($this), static fn (BoundField $f): bool => !$f->isHidden()));
    }

    /**
     * @return list<BoundField>
     */
    public function hiddenFields(): array
    {
        return array_values(array_filter(iterator_to_array($this), static fn (BoundField $f): bool => $f->isHidden()));
    }

    /**
     * Whether the <form> needs enctype="multipart/form-data" (file fields).
     */
    public function isMultipart(): bool
    {
        foreach ($this->getFields() as $field) {
            if ($field->widget->needsMultipart) {
                return true;
            }
        }

        return false;
    }

    public function addPrefix(string $name): string
    {
        return $this->prefix !== '' ? $this->prefix . '-' . $name : $name;
    }

    public function autoIdFor(string $htmlName): ?string
    {
        return $this->autoId !== null ? sprintf($this->autoId, $htmlName) : null;
    }

    // Rendering -----------------------------------------------------------------

    /**
     * Every field in a <div> with its label, help text and errors.
     */
    public function asDiv(): string
    {
        return $this->renderFields('div');
    }

    public function __toString(): string
    {
        return $this->asDiv();
    }

    // Cleaning ------------------------------------------------------------------

    /**
     * Clean every field, then the form.
     */
    protected function fullClean(): void
    {
        $this->cleaned = [];
        foreach ($this->getFields() as $name => $field) {
            $htmlName = $this->addPrefix($name);
            $value = $field->disabled
                ? $this->initialFor($name)
                : $field->widget->valueFromData($this->data ?? [], $this->files, $htmlName);
            try {
                $value = $field->clean($value);
                $hook = 'clean' . Str::studly($name);
                if (method_exists($this, $hook)) {
                    $value = $this->{$hook}($value);
                }
                $this->cleaned[$name] = $value;
            } catch (ValidationError $e) {
                $this->addError($name, $e);
            }
        }
        try {
            $this->cleaned = $this->clean($this->cleaned);
        } catch (ValidationError $e) {
            $this->addError(null, $e);
        }
        $this->postClean();
    }

    /**
     * Runs after clean(); ModelForm validates the model here.
     */
    protected function postClean(): void
    {
    }

    private function renderFields(string $tag): string
    {
        $html = (string) $this->nonFieldErrors();
        $hiddenErrors = [];
        foreach ($this->hiddenFields() as $field) {
            foreach ($field->errors() as $message) {
                $hiddenErrors[] = sprintf('(Hidden field %s) %s', $field->name, $message);
            }
        }
        $html .= (string) new ErrorList($hiddenErrors, class: 'errorlist nonfield');
        foreach ($this->visibleFields() as $field) {
            $id = $field->autoId();
            $help = $field->helpText() !== ''
                ? Html::tag('div', ['class' => 'helptext', 'id' => $id !== null ? $id . '_helptext' : null], Html::escape($field->helpText()))
                : '';
            $html .= Html::tag($tag, [], $field->labelTag() . $help . $field->errors() . $field->asWidget()) . "\n";
        }
        foreach ($this->hiddenFields() as $field) {
            $html .= $field->asWidget() . "\n";
        }

        return $html;
    }
}
