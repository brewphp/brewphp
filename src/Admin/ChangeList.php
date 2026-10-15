<?php

declare(strict_types=1);

namespace BrewPHP\Admin;

use BrewPHP\Db\Fields\BooleanField;
use BrewPHP\Db\Fields\DateField;
use BrewPHP\Db\Fields\ForeignKey;
use BrewPHP\Db\Model;
use BrewPHP\Db\QuerySet;
use BrewPHP\Http\Request;
use BrewPHP\Pagination\Page;
use BrewPHP\Pagination\Paginator;

/**
 * The list page of a model: search, filters, sorting and pagination,
 * built from the query string.
 *
 * Only parameters the ModelAdmin declares are used: a filter on a field
 * that is not in `listFilter`, or sorting by a column that is not shown,
 * is ignored, so the URL cannot be used to probe other fields.
 */
final class ChangeList
{
    public const SEARCH = 'q';
    public const ORDER = 'o';
    public const PAGE = 'p';

    public readonly string $search;

    public readonly ?string $order;

    /** @var array<string, string> applied filter parameters */
    public readonly array $params;

    /** @var QuerySet<Model> */
    public readonly QuerySet $queryset;

    /** @var Page<Model> */
    public readonly Page $page;

    public readonly int $total;

    /** @var list<array{title: string, choices: list<array{label: string, url: string, selected: bool}>, active: array<string, string>}>|null */
    private ?array $filters = null;

    public function __construct(private readonly ModelAdmin $admin, private readonly Request $request)
    {
        $search = $request->query(self::SEARCH, '');
        $search = is_string($search) ? trim($search) : '';
        $this->search = function_exists('mb_substr') ? mb_substr($search, 0, 200) : substr($search, 0, 200);

        $base = $admin->getQueryset($request);
        $this->total = $base->count();
        $queryset = $this->search !== '' ? $admin->search($base, $this->search) : $base;

        $params = [];
        foreach ($this->filters() as $filter) {
            foreach ($filter['active'] as $name => $value) {
                $params[$name] = $value;
            }
        }
        $this->params = $params;
        foreach ($params as $name => $value) {
            $queryset = $queryset->filter(...[$name => self::filterValue($name, $value)]);
        }

        $order = $request->query(self::ORDER);
        $this->order = is_string($order) && $admin->isSortable(ltrim($order, '-')) && in_array(ltrim($order, '-'), $admin->listDisplay, true) ? $order : null;
        if ($this->order !== null) {
            $queryset = $queryset->orderBy($this->order, '-' . $admin->meta()->pk->name);
        }

        $related = array_values(array_filter($admin->listDisplay, static fn (string $name): bool => $admin->meta()->hasField($name) && $admin->meta()->field($name) instanceof ForeignKey));
        if ($related !== []) {
            $queryset = $queryset->selectRelated(...$related);
        }
        $this->queryset = $queryset;
        $this->page = (new Paginator($queryset, max(1, $admin->listPerPage)))->getPage($request->query(self::PAGE, 1));
    }

    /**
     * The filters of the sidebar, with their choices and the parameters
     * that are active now.
     *
     * @return list<array{title: string, choices: list<array{label: string, url: string, selected: bool}>, active: array<string, string>}>
     */
    public function filters(): array
    {
        return $this->filters ??= $this->buildFilters();
    }

    /**
     * @return list<array{title: string, choices: list<array{label: string, url: string, selected: bool}>, active: array<string, string>}>
     */
    private function buildFilters(): array
    {
        $filters = [];
        $meta = $this->admin->meta();
        foreach ($this->admin->listFilter as $name) {
            $field = $meta->field($name);
            $options = $this->filterOptions($name);
            $active = [];
            $choices = [['label' => 'All', 'params' => []]];
            foreach ($options as $option) {
                $choices[] = $option;
            }
            $rendered = [];
            foreach ($choices as $choice) {
                $selected = true;
                foreach ($this->paramsOf($name) as $param) {
                    if (($choice['params'][$param] ?? null) !== $this->currentParam($param)) {
                        $selected = false;
                    }
                }
                if ($selected && $choice['params'] !== []) {
                    $active = $choice['params'];
                }
                $replace = array_fill_keys($this->paramsOf($name), null);
                $rendered[] = [
                    'label' => $choice['label'],
                    'url' => $this->url([...$replace, ...$choice['params'], self::PAGE => null]),
                    'selected' => $selected,
                ];
            }
            $filters[] = ['title' => ucfirst($field->label()), 'choices' => $rendered, 'active' => $active];
        }

        return $filters;
    }

    /**
     * The list URL with some query parameters changed (null removes one).
     *
     * @param array<string, string|int|null> $changes
     */
    public function url(array $changes = []): string
    {
        $query = [];
        foreach ([self::SEARCH => $this->search, self::ORDER => $this->order ?? null, self::PAGE => null] as $name => $value) {
            if ($value !== null && $value !== '') {
                $query[$name] = $value;
            }
        }
        foreach ($this->admin->listFilter as $name) {
            foreach ($this->paramsOf($name) as $param) {
                $current = $this->currentParam($param);
                if ($current !== null) {
                    $query[$param] = $current;
                }
            }
        }
        foreach ($changes as $name => $value) {
            if ($value === null || $value === '') {
                unset($query[$name]);
            } else {
                $query[$name] = (string) $value;
            }
        }
        ksort($query);

        return $query === [] ? '?' : '?' . http_build_query($query);
    }

    /**
     * The choices of one filter: label => query parameters.
     *
     * @return list<array{label: string, params: array<string, string>}>
     */
    private function filterOptions(string $name): array
    {
        $field = $this->admin->meta()->field($name);
        $options = [];
        if ($field instanceof BooleanField) {
            $options[] = ['label' => 'Yes', 'params' => [$name . '__exact' => '1']];
            $options[] = ['label' => 'No', 'params' => [$name . '__exact' => '0']];
            if ($field->null) {
                $options[] = ['label' => 'Unknown', 'params' => [$name . '__isnull' => '1']];
            }
        } elseif ($field->choices() !== null) {
            foreach ((array) $field->choices() as $value => $label) {
                $options[] = ['label' => $label, 'params' => [$name . '__exact' => (string) $value]];
            }
        } elseif ($field instanceof ForeignKey) {
            foreach ($field->target()::objects()->limit(200) as $object) {
                $options[] = ['label' => (string) $object, 'params' => [$name . '__exact' => (string) $object->pk]];
            }
            if ($field->null) {
                $options[] = ['label' => 'None', 'params' => [$name . '__isnull' => '1']];
            }
        } elseif ($field instanceof DateField) {
            $today = new \DateTimeImmutable('today');
            $ranges = [
                'Today' => [$today, $today->modify('+1 day')],
                'Past 7 days' => [$today->modify('-7 days'), $today->modify('+1 day')],
                'This month' => [$today->modify('first day of this month'), $today->modify('first day of next month')],
                'This year' => [$today->setDate((int) $today->format('Y'), 1, 1), $today->setDate((int) $today->format('Y') + 1, 1, 1)],
            ];
            foreach ($ranges as $label => [$from, $to]) {
                $options[] = ['label' => $label, 'params' => [$name . '__gte' => $from->format('Y-m-d'), $name . '__lt' => $to->format('Y-m-d')]];
            }
        } else {
            // Only values of rows this user may see.
            $values = $this->admin->getQueryset($this->request)->orderBy($name)->distinct()->limit(200)->valuesList($name, flat: true);
            foreach ($values as $value) {
                if (is_scalar($value) && $value !== '') {
                    $options[] = ['label' => (string) $value, 'params' => [$name . '__exact' => (string) $value]];
                }
            }
        }

        return $options;
    }

    /**
     * The query parameters a filter on `$name` may use.
     *
     * @return list<string>
     */
    private function paramsOf(string $name): array
    {
        $field = $this->admin->meta()->field($name);

        return $field instanceof DateField
            ? [$name . '__gte', $name . '__lt']
            : [$name . '__exact', $name . '__isnull'];
    }

    private function currentParam(string $param): ?string
    {
        $value = $this->request->query($param);

        return is_string($value) && $value !== '' && strlen($value) <= 200 ? $value : null;
    }

    private static function filterValue(string $param, string $value): mixed
    {
        return str_ends_with($param, '__isnull') ? $value === '1' : $value;
    }
}
