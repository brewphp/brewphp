<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Forms;

use BrewPHP\Auth\Models\User;
use BrewPHP\Auth\Passwords;
use BrewPHP\Core\AltersData;
use BrewPHP\Core\ValidationError;
use BrewPHP\Forms\Fields\CharField;
use BrewPHP\Forms\Form;
use BrewPHP\Forms\Widgets\PasswordInput;

/**
 * Set a user's password without the old one (administrators):
 * the new password twice, checked by AUTH_PASSWORD_VALIDATORS.
 */
class SetPasswordForm extends Form
{
    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $files
     * @param array<string, mixed> $initial
     */
    public function __construct(?array $data = null, array $files = [], array $initial = [], string $prefix = '', ?string $autoId = 'id_%s', protected ?User $user = null)
    {
        parent::__construct($data, $files, $initial, $prefix, $autoId);
    }

    public function fields(): array
    {
        return [
            'new_password1' => new CharField(
                strip: false,
                label: 'New password',
                widget: new PasswordInput(['autocomplete' => 'new-password']),
                helpText: implode(' ', Passwords::helpTexts()),
            ),
            'new_password2' => new CharField(
                strip: false,
                label: 'New password confirmation',
                widget: new PasswordInput(['autocomplete' => 'new-password']),
            ),
        ];
    }

    public function clean(array $data): array
    {
        $first = $data['new_password1'] ?? null;
        $second = $data['new_password2'] ?? null;
        if (is_string($first) && is_string($second)) {
            if (!hash_equals($first, $second)) {
                throw new ValidationError(['new_password2' => 'The two password fields didn’t match.']);
            }
            try {
                Passwords::validate($first, $this->user);
            } catch (ValidationError $e) {
                throw new ValidationError(['new_password2' => $e->messages()]);
            }
        }

        return $data;
    }

    /**
     * Set and save the new password.
     */
    #[AltersData]
    public function save(): User
    {
        if ($this->user === null || !$this->isValid()) {
            throw new \LogicException('The form has no user or is not valid.');
        }
        $this->user->setPassword((string) $this->cleanedData()['new_password1']);
        $this->user->save();

        return $this->user;
    }
}
