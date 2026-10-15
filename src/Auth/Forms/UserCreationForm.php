<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Forms;

use BrewPHP\Auth\Models\User;
use BrewPHP\Auth\Passwords;
use BrewPHP\Core\AltersData;
use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Model;
use BrewPHP\Forms\Fields\CharField;
use BrewPHP\Forms\ModelForm;
use BrewPHP\Forms\Widgets\PasswordInput;

/**
 * Create a user from a username and a password typed twice (used by the
 * admin). Only the username is taken from the model; nothing else can be
 * set through it.
 */
class UserCreationForm extends ModelForm
{
    protected function model(): string
    {
        return User::class;
    }

    protected function fieldNames(): array
    {
        return ['username'];
    }

    public function fields(): array
    {
        return [
            ...parent::fields(),
            'password1' => new CharField(
                strip: false,
                label: 'Password',
                widget: new PasswordInput(['autocomplete' => 'new-password']),
                helpText: implode(' ', Passwords::helpTexts()),
            ),
            'password2' => new CharField(
                strip: false,
                label: 'Password confirmation',
                widget: new PasswordInput(['autocomplete' => 'new-password']),
                helpText: 'Enter the same password as before, for verification.',
            ),
        ];
    }

    public function clean(array $data): array
    {
        $first = $data['password1'] ?? null;
        $second = $data['password2'] ?? null;
        if (is_string($first) && is_string($second)) {
            if (!hash_equals($first, $second)) {
                throw new ValidationError(['password2' => 'The two password fields didn’t match.']);
            }
            $user = $this->instance;
            if ($user instanceof User && is_string($data['username'] ?? null)) {
                $user->username = $data['username'];
            }
            try {
                Passwords::validate($first, $user instanceof User ? $user : null);
            } catch (ValidationError $e) {
                throw new ValidationError(['password2' => $e->messages()]);
            }
        }

        return $data;
    }

    #[AltersData]
    public function save(bool $commit = true): Model
    {
        $user = $this->instance;
        if ($user instanceof User && $this->isValid()) {
            $user->setPassword((string) $this->cleanedData()['password1']);
        }

        return parent::save($commit);
    }
}
