<?php

declare(strict_types=1);

namespace BrewPHP\Forms;

use BrewPHP\Core\AltersData;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Fields as Db;
use BrewPHP\Db\Model;
use BrewPHP\Forms\Fields\Field;

/**
 * A form built from a model, like Django's ModelForm:
 *
 *     final class PostForm extends ModelForm
 *     {
 *         protected function model(): string
 *         {
 *             return Post::class;
 *         }
 *
 *         protected function fieldNames(): array
 *         {
 *             return ['title', 'body', 'category'];
 *         }
 *     }
 *
 *     $form = PostForm::fromRequest($request, instance: $post);   // without $post: a new one
 *     if ($request->isMethod('POST') && $form->isValid()) {
 *         $post = $form->save();
 *     }
 *
 * Only the listed fields are read from the request, so a visitor cannot set
 * other columns (such as `is_staff`) by adding them to the POST data. List
 * the fields explicitly; `['__all__']` takes every editable field and must
 * be asked for on purpose.
 *
 * Validation runs the form fields, then the model's fullClean() (field
 * validators, clean() and unique checks) for the fields of the form.
 */
abstract class ModelForm extends Form
{
    protected Model $instance;

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $files
     * @param array<string, mixed> $initial
     * @param Model|null $instance the object to edit (null: a new one)
     */
    public function __construct(?array $data = null, array $files = [], array $initial = [], string $prefix = '', ?string $autoId = 'id_%s', ?Model $instance = null)
    {
        $model = $this->model();
        if ($instance !== null && !$instance instanceof $model) {
            throw new \InvalidArgumentException(sprintf('%s edits %s objects, got %s.', static::class, $model, $instance::class));
        }
        $this->instance = $instance ?? new $model();
        parent::__construct($data, $files, array_replace($this->instanceInitial(), $initial), $prefix, $autoId);
    }

    /**
     * The model class.
     *
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    /**
     * The model fields of the form, in order, or `['__all__']`.
     *
     * @return list<string>
     */
    protected function fieldNames(): array
    {
        throw new ImproperlyConfigured(sprintf(
            '%s must list its fields in fieldNames() (or return [\'__all__\'] on purpose), so no field is editable by accident.',
            static::class,
        ));
    }

    /**
     * Fields to leave out (useful with `['__all__']`).
     *
     * @return list<string>
     */
    protected function exclude(): array
    {
        return [];
    }

    /**
     * Widgets by field name, replacing the default ones.
     *
     * @return array<string, Widgets\Widget>
     */
    protected function widgets(): array
    {
        return [];
    }

    /**
     * The object the form edits (a new, unsaved one when none was given).
     */
    public function instance(): Model
    {
        return $this->instance;
    }

    /**
     * The model fields turned into form fields. Override it and add to
     * `parent::fields()` for extra fields.
     *
     * @return array<string, Field>
     */
    public function fields(): array
    {
        $meta = $this->instance::meta();
        $names = $this->fieldNames();
        if ($names === ['__all__']) {
            $names = array_keys(array_filter($meta->fields, static fn (Db\Field $f): bool => $f->editable && !$f->isAutoIncrement()));
        }
        $widgets = $this->widgets();
        $fields = [];
        foreach (array_diff($names, $this->exclude()) as $name) {
            $modelField = $meta->field($name);
            if (!$modelField->editable || $modelField->isAutoIncrement()) {
                throw new ImproperlyConfigured(sprintf('Field "%s" of %s is not editable, so it cannot be in %s.', $name, $meta->model, static::class));
            }
            $field = self::formFieldFor($modelField);
            if (isset($widgets[$name])) {
                $field->widget = $widgets[$name];
                if ($field instanceof Fields\ChoiceField) {
                    $field->refreshChoices();
                }
            }
            $fields[$name] = $field;
        }

        return $fields;
    }

    /**
     * Save the object (unless `$commit` is false) and return it. Only call
     * it after isValid().
     */
    #[AltersData]
    public function save(bool $commit = true): Model
    {
        if (!$this->isBound() || $this->errors() !== []) {
            throw new \LogicException(sprintf('%s cannot save: the form is not bound or has errors.', static::class));
        }
        if ($commit) {
            $this->instance->save();
        }

        return $this->instance;
    }

    /**
     * The form field for a model field.
     */
    public static function formFieldFor(Db\Field $field): Field
    {
        $options = [
            'required' => !$field->blank,
            'label' => ucfirst($field->label()),
            'helpText' => $field->helpText,
        ];
        if ($field->hasDefault()) {
            $options['initial'] = $field->default;
        }
        if ($field->choices !== null && !$field instanceof Db\ForeignKey) {
            return new Fields\ChoiceField($field->enumClass() ?? (array) $field->choices(), null, ...$options);
        }

        $text = ['emptyValue' => $field->null ? null : '', ...$options];

        return match (true) {
            $field instanceof Db\ForeignKey => new Fields\ModelChoiceField($field->target()::objects(), ...$options),
            $field instanceof Db\EmailField => new Fields\EmailField($field->maxLength, ...$text),
            $field instanceof Db\URLField => new Fields\URLField($field->maxLength, ...$text),
            $field instanceof Db\SlugField => new Fields\SlugField($field->maxLength, ...$text),
            $field instanceof Db\CharField => new Fields\CharField($field->maxLength, ...$text),
            $field instanceof Db\TextField => new Fields\CharField(...[...$text, 'widget' => new Widgets\Textarea()]),
            $field instanceof Db\BooleanField => new Fields\BooleanField(...[...$options, 'required' => false]),
            $field instanceof Db\PositiveIntegerField => new Fields\IntegerField(0, ...$options),
            $field instanceof Db\IntegerField => new Fields\IntegerField(...$options),
            $field instanceof Db\FloatField => new Fields\FloatField(...$options),
            $field instanceof Db\DecimalField => new Fields\DecimalField($field->maxDigits, $field->decimalPlaces, ...$options),
            $field instanceof Db\DateTimeField => new Fields\DateTimeField(...$options),
            $field instanceof Db\DateField => new Fields\DateField(...$options),
            $field instanceof Db\TimeField => new Fields\TimeField(...$options),
            $field instanceof Db\UUIDField => new Fields\UUIDField(...$options),
            $field instanceof Db\JsonField => new Fields\JsonField(...$options),
            default => new Fields\CharField(...$options),
        };
    }

    /**
     * Copy the clean values to the object, then run its validation.
     */
    protected function postClean(): void
    {
        $meta = $this->instance::meta();
        $formFields = $this->getFields();
        foreach ($this->cleaned as $name => $value) {
            if (isset($formFields[$name]) && $meta->hasField($name) && $name !== 'pk') {
                $this->instance->{$name} = $value;
            }
        }
        // Validate only what the form controls and what passed the form checks.
        $exclude = array_values(array_filter(
            array_keys($meta->fields),
            fn (string $name): bool => !isset($formFields[$name]) || $this->hasError($name),
        ));
        try {
            $this->instance->fullClean($exclude);
        } catch (ValidationError $e) {
            foreach ($e->errors() as $name => $messages) {
                foreach ($messages as $message) {
                    $this->addError(isset($formFields[$name]) ? $name : null, $message);
                }
            }
        }
    }

    /**
     * Initial values from the object being edited.
     *
     * @return array<string, mixed>
     */
    private function instanceInitial(): array
    {
        if (!$this->instance->isPersisted()) {
            return [];
        }
        $initial = [];
        foreach ($this->instance::meta()->fields as $name => $field) {
            $initial[$name] = $this->instance->getAttribute($field->attname);
        }

        return $initial;
    }
}
