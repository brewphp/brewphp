<?php

declare(strict_types=1);

namespace BrewPHP\Forms;

use BrewPHP\Forms\Fields\ChoiceField;
use BrewPHP\Forms\Fields\Field;
use BrewPHP\Templates\HtmlSafe;
use BrewPHP\Templates\SafeString;

/**
 * A field of one form, with its data and errors: `{{ form.email }}`.
 *
 * Printing it prints the widget. In templates you can also use
 * `field.label_tag`, `field.errors`, `field.help_text`, `field.id_for_label`,
 * `field.value` and `field.is_hidden`.
 */
final class BoundField implements HtmlSafe
{
    public function __construct(
        public readonly Form $form,
        public readonly Field $field,
        public readonly string $name,
    ) {
    }

    /**
     * The name in the HTML, with the form's prefix.
     */
    public function htmlName(): string
    {
        return $this->form->addPrefix($this->name);
    }

    public function label(): string
    {
        return $this->field->label ?? ucfirst(str_replace('_', ' ', $this->name));
    }

    public function helpText(): string
    {
        return $this->field->helpText;
    }

    public function isHidden(): bool
    {
        return $this->field->widget->isHidden;
    }

    public function autoId(): ?string
    {
        return $this->form->autoIdFor($this->htmlName());
    }

    public function idForLabel(): ?string
    {
        $id = $this->field->widget->attrs['id'] ?? $this->autoId();

        return is_string($id) ? $this->field->widget->idForLabel($id) : null;
    }

    public function errors(): ErrorList
    {
        $id = $this->autoId();

        return new ErrorList($this->form->errors()[$this->name] ?? [], $id !== null ? $id . '_error' : null);
    }

    /**
     * The value shown in the widget: the submitted data of a bound form,
     * otherwise the initial value.
     */
    public function value(): mixed
    {
        $initial = $this->form->initialFor($this->name);
        if (!$this->form->isBound() || $this->field->disabled) {
            return $this->field->prepareValue($initial);
        }

        return $this->field->widget->valueFromData($this->form->data(), $this->form->files(), $this->htmlName());
    }

    /**
     * `<label for="id_email">Email:</label>`.
     */
    public function labelTag(?string $contents = null, string $suffix = ':'): SafeString
    {
        $id = $this->idForLabel();
        $text = Html::escape($contents ?? $this->label()) . ($suffix !== '' ? Html::escape($suffix) : '');

        return new SafeString(Html::tag('label', ['for' => $id], $text));
    }

    /**
     * The widget's HTML.
     *
     * @param array<string, mixed> $attrs extra attributes
     */
    public function asWidget(array $attrs = []): SafeString
    {
        $field = $this->field;
        if ($field instanceof ChoiceField) {
            $field->refreshChoices();
        }
        $widget = $field->widget;
        $initial = $this->form->initialFor($this->name);
        $describedBy = [];
        $id = $this->autoId();
        if ($id !== null && $field->helpText !== '' && !$widget->isHidden) {
            $describedBy[] = $id . '_helptext';
        }
        $hasErrors = $this->form->isBound() && isset($this->form->errors()[$this->name]);
        if ($id !== null && $hasErrors) {
            $describedBy[] = $id . '_error';
        }
        $attrs = array_replace(
            $field->widgetAttrs($widget),
            [
                'id' => $widget->attrs['id'] ?? $id,
                'required' => $field->required && !$field->disabled && $widget->useRequiredAttribute($initial),
                'disabled' => $field->disabled,
                'aria-invalid' => $hasErrors ? 'true' : null,
                'aria-describedby' => $describedBy !== [] ? implode(' ', $describedBy) : null,
            ],
            $attrs,
        );

        return new SafeString($widget->render($this->htmlName(), $this->value(), $attrs));
    }

    public function __toString(): string
    {
        return (string) $this->asWidget();
    }
}
