<?php

declare(strict_types=1);

namespace BrewPHP\Forms\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Model;
use BrewPHP\Db\QueryException;
use BrewPHP\Db\QuerySet;
use BrewPHP\Forms\Widgets\ChoiceWidget;

/**
 * One object from a QuerySet, cleaned to the model instance. Only objects
 * of the QuerySet are accepted, so a user cannot pick a row they should not
 * see by sending its id.
 *
 *     new ModelChoiceField(Category::objects()->filter(active: true))
 *
 * The QuerySet is only run when the widget is rendered or a value cleaned.
 */
class ModelChoiceField extends ChoiceField
{
    protected const MESSAGES = [
        'invalid_choice' => 'Select a valid choice. That choice is not one of the available choices.',
    ];

    /**
     * @param QuerySet<covariant Model> $queryset
     * @param string $toFieldName the field identifying the object (default: the primary key)
     * @param (\Closure(Model): string)|null $labelFrom the label of an object (default: (string) $object)
     */
    public function __construct(public QuerySet $queryset, public string $toFieldName = 'pk', public ?\Closure $labelFrom = null, mixed ...$options)
    {
        parent::__construct([], null, ...$options);
    }

    public function setChoices(array|string $choices): void
    {
        // The choices come from the QuerySet, loaded by refreshChoices().
    }

    public function refreshChoices(): void
    {
        $widget = $this->widget;
        if ($widget instanceof ChoiceWidget) {
            $choices = [];
            foreach ($this->queryset as $object) {
                $key = $object->getAttribute($this->keyAttribute($object));
                $choices[is_int($key) ? $key : (string) $key] = $this->labelFrom !== null ? ($this->labelFrom)($object) : (string) $object;
            }
            $this->choices = $choices;
            $widget->choices = $this->widgetChoices();
        }
    }

    public function toPhp(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return $value;
        }

        return $this->lookup($this->stringValue($value));
    }

    public function validate(mixed $value): void
    {
        if ($this->required && $value === null) {
            throw $this->error('required');
        }
    }

    public function clean(mixed $value): mixed
    {
        $value = $this->toPhp($value);
        $this->validate($value);
        foreach ($this->validators as $validator) {
            $validator($value);
        }

        return $value;
    }

    public function prepareValue(mixed $value): mixed
    {
        return $value instanceof Model ? $value->getAttribute($this->keyAttribute($value)) : $value;
    }

    /**
     * The object of the QuerySet with this key, or a ValidationError.
     */
    protected function lookup(?string $key): ?Model
    {
        if ($key === null) {
            return null;
        }
        try {
            return $this->queryset->filter(...[$this->toFieldName => $key])->first() ?? throw $this->error('invalid_choice');
        } catch (QueryException) {
            // A value the column cannot hold ("abc" for an integer id on PostgreSQL).
            throw $this->error('invalid_choice');
        } catch (ValidationError $e) {
            throw $e->errorCode === 'invalid_choice' ? $e : $this->error('invalid_choice');
        }
    }

    protected function keyAttribute(Model $object): string
    {
        $meta = $object::meta();

        return $this->toFieldName === 'pk' ? $meta->pk->attname : $meta->field($this->toFieldName)->attname;
    }
}
