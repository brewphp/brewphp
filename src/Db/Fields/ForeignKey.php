<?php

declare(strict_types=1);

namespace BrewPHP\Db\Fields;

use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Grammars\Grammar;
use BrewPHP\Db\Model;
use BrewPHP\Db\ModelMeta;
use BrewPHP\Db\OnDelete;
use BrewPHP\Db\Registry;
use BrewPHP\Support\Str;

/**
 * A many-to-one relation: each Post has one Author.
 *
 *     'author' => new ForeignKey(Author::class, onDelete: OnDelete::Cascade, relatedName: 'posts'),
 *
 * The column is `author_id`. `$post->author` loads the Author (once),
 * `$post->author_id` is the raw id, and `$author->posts` (or
 * `$author->related('posts')`) is a QuerySet of the author's posts.
 * Without `relatedName`, the reverse accessor is `post_set` and the name
 * used in queries is `post`.
 */
class ForeignKey extends Field
{
    /** The target's metadata when it comes from migration state instead of a model class. */
    private ?ModelMeta $boundTarget = null;

    /**
     * @param string $to the target: a model class, "self", or "app.Model"
     * @param OnDelete $onDelete what happens to this object when the target is deleted
     * @param string|null $relatedName the reverse accessor on the target, e.g. "posts"
     */
    public function __construct(
        public readonly string $to,
        public readonly OnDelete $onDelete = OnDelete::Cascade,
        public readonly ?string $relatedName = null,
        bool $dbIndex = true,
        mixed ...$options,
    ) {
        parent::__construct(...$options, dbIndex: $dbIndex);
        if ($onDelete === OnDelete::SetNull && !$this->null) {
            throw new \InvalidArgumentException('A ForeignKey with onDelete: OnDelete::SetNull must have null: true.');
        }
    }

    /**
     * The target model class.
     *
     * @return class-string<Model>
     */
    public function target(): string
    {
        return Registry::resolve($this->to, $this->model);
    }

    public function targetMeta(): ModelMeta
    {
        return $this->boundTarget ?? $this->target()::meta();
    }

    /**
     * Point the field at a ModelMeta instead of a model class. Migrations
     * do this so that a foreign key in migration state uses the target
     * table as it was at that point, not the current model.
     *
     * @internal
     */
    public function bindTarget(ModelMeta $target): void
    {
        $this->boundTarget = $target;
    }

    /**
     * Migrations name the target as "app_label.ModelName", so they never
     * depend on a model class.
     */
    public function deconstructForMigration(): array
    {
        $deconstructed = parent::deconstructForMigration();
        $target = $this->boundTarget ?? ($this->model !== '' || class_exists($this->to) ? $this->target()::meta() : null);
        if ($target !== null) {
            $deconstructed['options']['to'] = $target->label();
        }

        return $deconstructed;
    }

    public function isRelation(): bool
    {
        return true;
    }

    public function columnType(Grammar $grammar): string
    {
        return $this->targetMeta()->pk->relatedColumnType($grammar);
    }

    /**
     * The attribute on target objects that returns the related objects:
     * the relatedName, or "<model>_set".
     */
    public function relatedAccessorName(): string
    {
        return $this->relatedName ?? $this->relatedQueryName() . '_set';
    }

    /**
     * The name used for this relation in queries on the target model,
     * e.g. `Author::objects()->filter(posts__title: 'Hello')`.
     */
    public function relatedQueryName(): string
    {
        return $this->relatedName ?? Str::snake((string) substr((string) strrchr('\\' . $this->model, '\\'), 1));
    }

    public function toPhp(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return $value->pk;
        }

        return $this->targetMeta()->pk->toPhp($value);
    }

    public function toDb(mixed $value): mixed
    {
        if ($value instanceof Model) {
            $value = $value->pk;
        }

        return $value === null ? null : $this->targetMeta()->pk->toDb($value);
    }

    /**
     * Besides the usual checks, the target object must exist.
     */
    public function validate(mixed $value, ?Model $model = null): void
    {
        parent::validate($value, $model);
        if ($value === null || $value === '') {
            return;
        }
        $target = $this->target();
        if (!$target::objects()->filter(pk: $value)->exists()) {
            throw new ValidationError(sprintf(
                '%s instance with %s %s does not exist.',
                ucfirst($target::meta()->verboseName),
                $target::meta()->pk->name,
                var_export($value, true),
            ), 'invalid');
        }
    }

    protected function attnameFor(string $name): string
    {
        return $name . '_id';
    }

    /**
     * onDelete is applied in PHP when objects are deleted, and relatedName
     * only names the reverse accessor: neither changes the table.
     */
    protected function phpOnlyOptions(): array
    {
        return [...parent::phpOnlyOptions(), 'onDelete', 'relatedName'];
    }
}
