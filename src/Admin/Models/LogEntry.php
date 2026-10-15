<?php

declare(strict_types=1);

namespace BrewPHP\Admin\Models;

use BrewPHP\Auth\Models\User;
use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\DateTimeField;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Fields\PositiveIntegerField;
use BrewPHP\Db\Fields\TextField;
use BrewPHP\Db\Model;
use BrewPHP\Db\OnDelete;

/**
 * One change made in the admin (table `admin_log_entry`): who added,
 * changed or deleted which object, and when. Shown as "Recent actions" and
 * in each object's history.
 *
 * @property \DateTimeImmutable $action_time
 * @property int $user_id
 * @property User $user
 * @property string $content_type "app_label.modelname"
 * @property string $object_id
 * @property string $object_repr
 * @property int $action_flag
 * @property string $change_message
 */
final class LogEntry extends Model
{
    public const ADDITION = 1;
    public const CHANGE = 2;
    public const DELETION = 3;

    public static function fields(): array
    {
        return [
            'action_time' => new DateTimeField(default: static fn (): \DateTimeImmutable => new \DateTimeImmutable(), editable: false),
            'user' => new ForeignKey(User::class, onDelete: OnDelete::Cascade, relatedName: 'admin_log'),
            'content_type' => new CharField(maxLength: 100, dbIndex: true),
            'object_id' => new CharField(maxLength: 255, blank: true),
            'object_repr' => new CharField(maxLength: 200),
            'action_flag' => new PositiveIntegerField(choices: [self::ADDITION => 'Addition', self::CHANGE => 'Change', self::DELETION => 'Deletion']),
            'change_message' => new TextField(blank: true),
        ];
    }

    public static function ordering(): array
    {
        return ['-action_time', '-id'];
    }

    public static function verboseName(): string
    {
        return 'log entry';
    }

    public static function verboseNamePlural(): string
    {
        return 'log entries';
    }

    /**
     * Record an action on an object.
     */
    public static function log(User $user, Model $object, int $flag, string $message = ''): self
    {
        $meta = $object::meta();

        return self::create([
            'user' => $user,
            'content_type' => strtolower($meta->label()),
            'object_id' => (string) $object->pk,
            'object_repr' => function_exists('mb_substr') ? mb_substr((string) $object, 0, 200) : substr((string) $object, 0, 200),
            'action_flag' => $flag,
            'change_message' => $message,
        ]);
    }

    public function isAddition(): bool
    {
        return $this->action_flag === self::ADDITION;
    }

    public function isChange(): bool
    {
        return $this->action_flag === self::CHANGE;
    }

    public function isDeletion(): bool
    {
        return $this->action_flag === self::DELETION;
    }

    public function __toString(): string
    {
        return match ($this->action_flag) {
            self::ADDITION => sprintf('Added “%s”.', $this->object_repr),
            self::CHANGE => sprintf('Changed “%s” — %s', $this->object_repr, $this->change_message),
            default => sprintf('Deleted “%s”.', $this->object_repr),
        };
    }
}
