<?php

declare(strict_types=1);

namespace BrewPHP\Admin;

use BrewPHP\Admin\Forms\AdminModelForm;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Db\Fields\BooleanField;
use BrewPHP\Db\Fields\DateField;
use BrewPHP\Db\Fields\DateTimeField;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Model;
use BrewPHP\Db\ModelMeta;
use BrewPHP\Db\Q;
use BrewPHP\Db\QuerySet;
use BrewPHP\Forms\Form;
use BrewPHP\Forms\Html;
use BrewPHP\Forms\ModelForm;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Templates\HtmlSafe;
use BrewPHP\Templates\SafeString;

/**
 * How one model looks and behaves in the admin, like Django's ModelAdmin.
 * Subclass it, set the public properties, and register it:
 *
 *     #[Register(Post::class)]
 *     final class PostAdmin extends ModelAdmin
 *     {
 *         public array $listDisplay = ['title', 'author', 'status', 'word_count'];
 *         public array $listFilter = ['status', 'author', 'published_at'];
 *         public array $searchFields = ['title', 'author__name'];
 *         public array $readonlyFields = ['created_at'];
 *         public array $actions = ['publish' => 'Publish selected posts'];
 *
 *         public function wordCount(Post $post): int   // a list column
 *         {
 *             return str_word_count($post->body);
 *         }
 *
 *         public function publish(Request $request, QuerySet $posts): void   // an action
 *         {
 *             $posts->update(['status' => 'published']);
 *         }
 *     }
 *
 * Override the has*Permission() methods to change who may do what, and
 * getQueryset() to limit the rows a user can see and change.
 */
class ModelAdmin
{
    /** @var list<string> list columns: field names, "__str__", or methods of this class (snake_case or camelCase) */
    public array $listDisplay = ['__str__'];

    /** @var list<string> columns that link to the change page (default: the first) */
    public array $listDisplayLinks = [];

    /** @var list<string> fields to filter by in the sidebar: booleans, choices, foreign keys, dates */
    public array $listFilter = [];

    /** @var list<string> fields searched with the search box ("^" starts with, "=" exact, default contains) */
    public array $searchFields = [];

    /** @var list<string> default ordering of the list (default: the model's) */
    public array $ordering = [];

    public int $listPerPage = 100;

    /** @var list<string>|null fields of the form, in order (null: every editable field) */
    public ?array $fields = null;

    /** @var list<string> fields left out of the form */
    public array $exclude = [];

    /** @var list<string> fields (or methods) shown but not editable */
    public array $readonlyFields = [];

    /** @var array<string, string> actions: method name => description */
    public array $actions = [];

    /** Whether the "Delete selected" action is offered. */
    public bool $deleteSelectedAction = true;

    /** @var array<string, string> column labels by name (default: from the field or method name) */
    public array $labels = [];

    /** @var class-string<ModelForm>|null a ModelForm class (its constructor must accept instance:) */
    public ?string $formClass = null;

    /**
     * @param class-string<Model> $model
     */
    final public function __construct(public readonly string $model, public readonly AdminSite $site)
    {
    }

    public function meta(): ModelMeta
    {
        return $this->model::meta();
    }

    // Permissions ---------------------------------------------------------------

    public function hasViewPermission(Request $request, ?Model $object = null): bool
    {
        return $this->can($request, 'view') || $this->can($request, 'change');
    }

    public function hasAddPermission(Request $request): bool
    {
        return $this->can($request, 'add');
    }

    public function hasChangePermission(Request $request, ?Model $object = null): bool
    {
        return $this->can($request, 'change');
    }

    public function hasDeletePermission(Request $request, ?Model $object = null): bool
    {
        return $this->can($request, 'delete');
    }

    /**
     * Whether the model shows up in the admin at all for this user.
     */
    public function hasAnyPermission(Request $request): bool
    {
        return $this->hasViewPermission($request) || $this->hasAddPermission($request) || $this->hasDeletePermission($request);
    }

    // Data ----------------------------------------------------------------------

    /**
     * The objects this user may see in the admin. Every page (list, change,
     * delete, actions) works on this QuerySet only, so limiting it here
     * limits what the user can reach.
     *
     * @return QuerySet<Model>
     */
    public function getQueryset(Request $request): QuerySet
    {
        $queryset = $this->model::objects();
        if ($this->ordering !== []) {
            $queryset = $queryset->orderBy(...$this->ordering);
        }

        return $queryset;
    }

    /**
     * @param QuerySet<Model> $queryset
     * @return QuerySet<Model>
     */
    public function search(QuerySet $queryset, string $term): QuerySet
    {
        if ($this->searchFields === []) {
            return $queryset;
        }
        foreach (preg_split('/\s+/', trim($term)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $conditions = [];
            foreach ($this->searchFields as $field) {
                $lookup = match ($field[0]) {
                    '^' => substr($field, 1) . '__istartswith',
                    '=' => substr($field, 1) . '__iexact',
                    default => $field . '__icontains',
                };
                $conditions[] = new Q(...[$lookup => $word]);
            }
            $queryset = $queryset->where(Q::or(...$conditions));
        }
        foreach ($this->searchFields as $field) {
            if (str_contains($field, '__')) {
                return $queryset->distinct();
            }
        }

        return $queryset;
    }

    // Forms ---------------------------------------------------------------------

    /**
     * The fields of the form (without the read-only ones).
     *
     * @return list<string>
     */
    public function getFields(Request $request, ?Model $object = null): array
    {
        $names = $this->fields ?? array_keys(array_filter(
            $this->meta()->fields,
            static fn (\BrewPHP\Db\Fields\Field $field): bool => $field->editable && !$field->isAutoIncrement(),
        ));
        $readonly = $this->getReadonlyFields($request, $object);

        return array_values(array_filter($names, fn (string $name): bool => !in_array($name, $this->exclude, true) && !in_array($name, $readonly, true)));
    }

    /**
     * @return list<string>
     */
    public function getReadonlyFields(Request $request, ?Model $object = null): array
    {
        return $this->readonlyFields;
    }

    /**
     * The form to add or change an object.
     *
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $files
     */
    public function getForm(Request $request, ?Model $object, ?array $data = null, array $files = []): Form
    {
        if ($this->formClass !== null) {
            return new $this->formClass($data, $files, instance: $object);
        }
        $model = $this->model;
        $fields = $this->getFields($request, $object);
        if ($fields === []) {
            throw new ImproperlyConfigured(sprintf('%s has no editable fields to show.', static::class));
        }

        return new AdminModelForm($data, $files, instance: $object, modelClass: $model, names: $fields);
    }

    /**
     * Save the object of a valid form. Override to set extra values first
     * (e.g. `$object->author = $request->user()`).
     */
    public function saveModel(Request $request, Model $object, Form $form, bool $change): void
    {
        if ($form instanceof ModelForm) {
            $form->save();
        } else {
            $object->save();
        }
    }

    /**
     * Called after saveModel(), to save what depends on the object.
     */
    public function saveRelated(Request $request, Form $form, bool $change): void
    {
    }

    public function deleteModel(Request $request, Model $object): void
    {
        $object->delete();
    }

    // Display -------------------------------------------------------------------

    /**
     * The heading of a list column or read-only field.
     */
    public function label(string $name): string
    {
        if (isset($this->labels[$name])) {
            return $this->labels[$name];
        }
        if ($name === '__str__') {
            return ucfirst($this->meta()->verboseName);
        }
        if ($this->meta()->hasField($name)) {
            return ucfirst($this->meta()->field($name)->label());
        }

        return ucfirst(str_replace('_', ' ', \BrewPHP\Support\Str::snake($name)));
    }

    /**
     * A value of an object for the list or a read-only field: escaped text,
     * or HTML from an HtmlSafe value returned by your method.
     */
    public function display(Model $object, string $name): string|HtmlSafe
    {
        if ($name === '__str__') {
            return (string) $object;
        }
        $method = $this->methodFor($name);
        if ($method !== null) {
            return self::format($this->{$method}($object));
        }
        if (!$this->meta()->hasField($name)) {
            throw new ImproperlyConfigured(sprintf('%s: "%s" is neither a field of %s nor a method of the admin.', static::class, $name, $this->model));
        }
        $field = $this->meta()->field($name);
        $value = $field instanceof ForeignKey ? $object->{$name} : $object->getAttribute($field->attname);
        $choices = $field->choices();
        if ($choices !== null && $value !== null) {
            $key = $value instanceof \BackedEnum ? $value->value : $value;

            return is_int($key) || is_string($key) ? ($choices[$key] ?? (string) $key) : '';
        }
        if ($field instanceof BooleanField) {
            return self::boolean($value === null ? null : (bool) $value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format($field instanceof DateTimeField ? 'M j, Y, H:i' : ($field instanceof DateField ? 'M j, Y' : 'H:i'));
        }

        return self::format($value);
    }

    /**
     * Whether a list column can be sorted (it is a model field).
     */
    public function isSortable(string $name): bool
    {
        return $name !== '__str__' && $this->meta()->hasField($name) && $this->methodFor($name) === null;
    }

    /**
     * The method of this class for a column name ("word_count" or "wordCount").
     */
    public function methodFor(string $name): ?string
    {
        $camel = \BrewPHP\Support\Str::camel($name);
        foreach ([$name, $camel] as $candidate) {
            if ($candidate !== '' && method_exists($this, $candidate) && !method_exists(self::class, $candidate) && (new \ReflectionMethod($this, $candidate))->isPublic()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The actions offered on the list: name => description.
     *
     * @return array<string, string>
     */
    public function getActions(Request $request): array
    {
        $actions = [];
        if ($this->deleteSelectedAction && $this->hasDeletePermission($request)) {
            $actions['delete_selected'] = sprintf('Delete selected %s', $this->meta()->verboseNamePlural);
        }
        if ($this->hasChangePermission($request)) {
            foreach ($this->actions as $method => $description) {
                if (!method_exists($this, $method)) {
                    throw new ImproperlyConfigured(sprintf('Action "%s" of %s has no method.', $method, static::class));
                }
                $actions[$method] = $description;
            }
        }

        return $actions;
    }

    /**
     * Run a custom action on the selected objects. Return a Response to
     * show your own page, or null to go back to the list.
     *
     * @param QuerySet<Model> $queryset
     */
    public function runAction(string $action, Request $request, QuerySet $queryset): ?Response
    {
        $result = $this->{$action}($request, $queryset);

        return $result instanceof Response ? $result : null;
    }

    private function can(Request $request, string $action): bool
    {
        $user = $request->user();
        $meta = $this->meta();

        return $user->isActive() && $user->isStaff() && $user->hasPerm($meta->appLabel . '.' . $action . '_' . strtolower($meta->modelName));
    }

    private static function format(mixed $value): string|HtmlSafe
    {
        return match (true) {
            $value instanceof HtmlSafe => $value,
            $value === null => '-',
            is_bool($value) => self::boolean($value),
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format('M j, Y, H:i'),
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => get_debug_type($value),
        };
    }

    private static function boolean(?bool $value): SafeString
    {
        return new SafeString(match ($value) {
            true => Html::tag('span', ['class' => 'bool yes', 'title' => 'Yes'], '✔'),
            false => Html::tag('span', ['class' => 'bool no', 'title' => 'No'], '✘'),
            null => Html::tag('span', ['class' => 'bool unknown', 'title' => 'Unknown'], '?'),
        });
    }
}
