<?php

declare(strict_types=1);

namespace BrewPHP\Admin\Forms;

use BrewPHP\Db\Model;
use BrewPHP\Forms\ModelForm;

/**
 * The ModelForm the admin builds for a ModelAdmin without a formClass:
 * the model and its fields are given to the constructor.
 */
final class AdminModelForm extends ModelForm
{
    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $files
     * @param array<string, mixed> $initial
     * @param class-string<Model>|'' $modelClass
     * @param list<string> $names
     */
    public function __construct(
        ?array $data = null,
        array $files = [],
        array $initial = [],
        string $prefix = '',
        ?string $autoId = 'id_%s',
        ?Model $instance = null,
        private readonly string $modelClass = '',
        private readonly array $names = [],
    ) {
        parent::__construct($data, $files, $initial, $prefix, $autoId, $instance);
    }

    protected function model(): string
    {
        if ($this->modelClass === '') {
            throw new \LogicException('AdminModelForm needs a modelClass.');
        }

        return $this->modelClass;
    }

    protected function fieldNames(): array
    {
        return $this->names;
    }
}
