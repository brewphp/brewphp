<?php

declare(strict_types=1);

namespace BrewPHP\Admin;

use BrewPHP\Admin\Forms\AdminAuthenticationForm;
use BrewPHP\Admin\Models\LogEntry;
use BrewPHP\Auth\Auth;
use BrewPHP\Auth\Forms\PasswordChangeForm;
use BrewPHP\Auth\Models\User;
use BrewPHP\Core\Application;
use BrewPHP\Core\ImproperlyConfigured;
use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Collector;
use BrewPHP\Db\Model;
use BrewPHP\Db\ProtectedError;
use BrewPHP\Db\QueryException;
use BrewPHP\Db\QuerySet;
use BrewPHP\Forms\ModelForm;
use BrewPHP\Http\Http404;
use BrewPHP\Http\MethodNotAllowed;
use BrewPHP\Http\PermissionDenied;
use BrewPHP\Http\RedirectResponse;
use BrewPHP\Http\Request;
use BrewPHP\Http\Response;
use BrewPHP\Routing\Route;
use BrewPHP\Security\Redirects;
use BrewPHP\Sessions\Messages;

/**
 * The admin site: the registered models and every admin page, like
 * Django's AdminSite. Mount it in the root URLconf:
 *
 *     path('admin/', include_urls(AdminSite::default()->urls(), namespace: 'admin')),
 *
 * Only active staff users get in, and each page also checks the model
 * permissions (view, add, change, delete) of the ModelAdmin.
 */
final class AdminSite
{
    public string $siteHeader = 'BrewPHP administration';

    public string $siteTitle = 'BrewPHP site admin';

    public string $indexTitle = 'Site administration';

    /** Where the "View site" link goes. */
    public string $siteUrl = '/';

    /** Files the admin serves from resources/admin/ (nothing else is reachable). */
    public const STATIC_FILES = ['admin.css' => 'text/css; charset=utf-8', 'admin.js' => 'text/javascript; charset=utf-8'];

    /** @var array<class-string<Model>, ModelAdmin> */
    private array $registry = [];

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * The site of the current application.
     */
    public static function default(): self
    {
        return self::of(Application::getInstance());
    }

    /**
     * The one site of an application, created on first use, so apps whose
     * ready() runs before the admin app's still register on the right site.
     */
    public static function of(Application $app): self
    {
        if (!$app->has(self::class)) {
            $app->singleton(self::class, static fn (): self => new self($app));
        }

        return $app->get(self::class);
    }

    // Registry ------------------------------------------------------------------

    /**
     * Show models in the admin, with a ModelAdmin subclass (or the default).
     *
     * @param class-string<Model>|list<class-string<Model>> $models
     * @param class-string<ModelAdmin> $adminClass
     */
    public function register(string|array $models, string $adminClass = ModelAdmin::class): void
    {
        foreach ((array) $models as $model) {
            if (!is_subclass_of($model, Model::class)) {
                throw new ImproperlyConfigured(sprintf('"%s" is not a model class.', $model));
            }
            if (isset($this->registry[$model])) {
                throw new ImproperlyConfigured(sprintf('The model %s is already registered in the admin.', $model));
            }
            $this->registry[$model] = new $adminClass($model, $this);
        }
    }

    /**
     * @param class-string<Model> $model
     */
    public function unregister(string $model): void
    {
        unset($this->registry[$model]);
    }

    /**
     * @param class-string<Model> $model
     */
    public function isRegistered(string $model): bool
    {
        return isset($this->registry[$model]);
    }

    /**
     * @return array<class-string<Model>, ModelAdmin>
     */
    public function registry(): array
    {
        return $this->registry;
    }

    /**
     * The ModelAdmin of "app_label/model_name" from a URL, or a 404.
     */
    public function modelAdmin(string $appLabel, string $modelName): ModelAdmin
    {
        foreach ($this->registry as $admin) {
            $meta = $admin->meta();
            if ($meta->appLabel === $appLabel && strtolower($meta->modelName) === strtolower($modelName)) {
                return $admin;
            }
        }

        throw new Http404('No such model in the admin.');
    }

    // URLs ----------------------------------------------------------------------

    /**
     * The URL patterns, to include with the namespace "admin".
     *
     * @return list<Route>
     */
    public function urls(): array
    {
        return [
            Route::path('', $this->index(...), name: 'index'),
            Route::path('login/', $this->login(...), name: 'login', methods: ['GET', 'POST']),
            Route::path('logout/', $this->logout(...), name: 'logout', methods: ['GET', 'POST']),
            Route::path('password_change/', $this->passwordChange(...), name: 'password_change', methods: ['GET', 'POST']),
            Route::path('password_change/done/', $this->passwordChangeDone(...), name: 'password_change_done'),
            Route::path('static/<str:file>', $this->staticFile(...), name: 'static'),
            Route::path('<str:app_label>/', $this->appIndex(...), name: 'app_list'),
            Route::path('<str:app_label>/<str:model_name>/', $this->changelist(...), name: 'changelist', methods: ['GET', 'POST']),
            Route::path('<str:app_label>/<str:model_name>/add/', $this->add(...), name: 'add', methods: ['GET', 'POST']),
            Route::path('<str:app_label>/<str:model_name>/<path:object_id>/change/', $this->change(...), name: 'change', methods: ['GET', 'POST']),
            Route::path('<str:app_label>/<str:model_name>/<path:object_id>/delete/', $this->delete(...), name: 'delete', methods: ['GET', 'POST']),
            Route::path('<str:app_label>/<str:model_name>/<path:object_id>/history/', $this->history(...), name: 'history'),
        ];
    }

    // Pages ---------------------------------------------------------------------

    public function index(Request $request): Response
    {
        return $this->staffOnly($request) ?? $this->page($request, 'admin/index.html', [
            'title' => $this->indexTitle,
            'apps' => $this->appList($request),
            'recent_actions' => $this->recentActions($request),
        ]);
    }

    public function appIndex(Request $request, string $app_label): Response
    {
        if (($redirect = $this->staffOnly($request)) !== null) {
            return $redirect;
        }
        $apps = $this->appList($request, $app_label);
        if ($apps === []) {
            throw new Http404('No such app in the admin.');
        }

        return $this->page($request, 'admin/app_index.html', [
            'title' => $apps[0]['name'] . ' administration',
            'app' => $apps[0],
            'breadcrumbs' => [['label' => $apps[0]['name'], 'url' => null]],
        ]);
    }

    public function login(Request $request): Response
    {
        $user = $request->user();
        if ($request->isMethod('GET') && $user->isActive() && $user->isStaff()) {
            return $this->redirect($this->safeNext($request) ?? reverse('admin:index'));
        }
        $form = $request->isMethod('POST')
            ? new AdminAuthenticationForm((array) $request->post(), request: $request)
            : new AdminAuthenticationForm(request: $request);
        $loggedIn = $form->getUser();
        if ($loggedIn !== null) {
            Auth::login($request, $loggedIn);

            return $this->redirect($this->safeNext($request) ?? reverse('admin:index'));
        }
        $next = $request->post('next') ?? $request->query('next');

        return $this->page($request, 'admin/login.html', [
            'title' => 'Log in',
            'form' => $form,
            'next' => $this->safeNext($request) !== null && is_string($next) ? $next : '',
            'not_staff' => $user->isAuthenticated() && !$user->isStaff(),
        ], showUserTools: false);
    }

    /**
     * Only POST logs out (with the CSRF token), so a link or an image on
     * another page cannot log staff out.
     */
    public function logout(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            throw new MethodNotAllowed(['POST']);
        }
        Auth::logout($request);
        Messages::info($request, 'You have been logged out.');

        return $this->redirect(reverse('admin:login'));
    }

    public function passwordChange(Request $request): Response
    {
        if (($redirect = $this->staffOnly($request)) !== null) {
            return $redirect;
        }
        $user = $request->user();
        \assert($user instanceof User);
        $form = $request->isMethod('POST') ? new PasswordChangeForm((array) $request->post(), user: $user) : new PasswordChangeForm(user: $user);
        if ($request->isMethod('POST') && $form->isValid()) {
            $form->save();
            Auth::updateSessionAuthHash($request, $user);

            return $this->redirect(reverse('admin:password_change_done'));
        }

        return $this->page($request, 'admin/password_change_form.html', [
            'title' => 'Password change',
            'form' => $form,
            'breadcrumbs' => [['label' => 'Password change', 'url' => null]],
        ]);
    }

    public function passwordChangeDone(Request $request): Response
    {
        return $this->staffOnly($request) ?? $this->page($request, 'admin/password_change_done.html', [
            'title' => 'Password change successful',
            'breadcrumbs' => [['label' => 'Password change', 'url' => null]],
        ]);
    }

    /**
     * The admin's stylesheet and script, from a fixed list: no path in the
     * URL can reach another file.
     */
    public function staticFile(string $file): Response
    {
        if (!isset(self::STATIC_FILES[$file])) {
            throw new Http404('No such file.');
        }
        $path = $this->app->resourcePath('admin', $file);

        return (new Response((string) file_get_contents($path), 200, [
            'Content-Type' => self::STATIC_FILES[$file],
            'Cache-Control' => 'public, max-age=86400',
        ]));
    }

    public function changelist(Request $request, string $app_label, string $model_name): Response
    {
        if (($redirect = $this->staffOnly($request)) !== null) {
            return $redirect;
        }
        $admin = $this->modelAdmin($app_label, $model_name);
        if (!$admin->hasViewPermission($request)) {
            throw new PermissionDenied('You do not have permission to view this list.');
        }
        $meta = $admin->meta();

        if ($request->isMethod('POST') && $request->post('action') !== null) {
            $response = $this->handleAction($request, $admin);
            if ($response !== null) {
                return $response;
            }

            return $this->redirect($request->fullPath());
        }

        $list = new ChangeList($admin, $request);
        $links = $admin->listDisplayLinks !== [] ? $admin->listDisplayLinks : [$admin->listDisplay[0] ?? '__str__'];
        $columns = [];
        foreach ($admin->listDisplay as $name) {
            $sorted = $list->order === $name ? 'asc' : ($list->order === '-' . $name ? 'desc' : null);
            $columns[] = [
                'label' => $admin->label($name),
                'sortable' => $admin->isSortable($name),
                'sorted' => $sorted,
                'url' => $list->url([ChangeList::ORDER => $sorted === 'asc' ? '-' . $name : $name, ChangeList::PAGE => null]),
            ];
        }
        $rows = [];
        foreach ($list->page as $object) {
            $cells = [];
            foreach ($admin->listDisplay as $name) {
                $cells[] = [
                    'value' => $admin->display($object, $name),
                    'url' => in_array($name, $links, true) ? $this->objectUrl('change', $admin, $object) : null,
                ];
            }
            $rows[] = ['pk' => (string) $object->pk, 'cells' => $cells];
        }
        $page = $list->page;
        $pages = [];
        foreach ($page->paginator->elidedPageRange($page->number) as $number) {
            $pages[] = ['number' => $number, 'url' => is_int($number) ? $list->url([ChangeList::PAGE => $number]) : null, 'current' => $number === $page->number];
        }

        return $this->page($request, 'admin/change_list.html', [
            'title' => 'Select ' . $meta->verboseName . ' to ' . ($admin->hasChangePermission($request) ? 'change' : 'view'),
            'model' => $this->modelInfo($admin),
            'columns' => $columns,
            'rows' => $rows,
            'filters' => $list->filters(),
            'search' => $list->search,
            'has_search' => $admin->searchFields !== [],
            'actions' => array_map(
                static fn (string $name, string $description): array => ['name' => $name, 'description' => $description],
                array_keys($admin->getActions($request)),
                array_values($admin->getActions($request)),
            ),
            'add_url' => $admin->hasAddPermission($request) ? reverse('admin:add', [$meta->appLabel, strtolower($meta->modelName)]) : null,
            'count' => $page->paginator->count(),
            'total' => $list->total,
            'pages' => $page->paginator->numPages() > 1 ? $pages : [],
            'clear_url' => $list->url([ChangeList::SEARCH => null]),
            'breadcrumbs' => $this->breadcrumbs($admin),
        ]);
    }

    public function add(Request $request, string $app_label, string $model_name): Response
    {
        if (($redirect = $this->staffOnly($request)) !== null) {
            return $redirect;
        }
        $admin = $this->modelAdmin($app_label, $model_name);
        if (!$admin->hasAddPermission($request)) {
            throw new PermissionDenied('You do not have permission to add this.');
        }

        return $this->changeForm($request, $admin, null);
    }

    public function change(Request $request, string $app_label, string $model_name, string $object_id): Response
    {
        if (($redirect = $this->staffOnly($request)) !== null) {
            return $redirect;
        }
        $admin = $this->modelAdmin($app_label, $model_name);
        $object = $this->getObject($request, $admin, $object_id);
        if (!$admin->hasViewPermission($request, $object)) {
            throw new PermissionDenied('You do not have permission to view this.');
        }

        return $this->changeForm($request, $admin, $object);
    }

    public function delete(Request $request, string $app_label, string $model_name, string $object_id): Response
    {
        if (($redirect = $this->staffOnly($request)) !== null) {
            return $redirect;
        }
        $admin = $this->modelAdmin($app_label, $model_name);
        $object = $this->getObject($request, $admin, $object_id);
        if (!$admin->hasDeletePermission($request, $object)) {
            throw new PermissionDenied('You do not have permission to delete this.');
        }
        $meta = $admin->meta();
        [$deleted, $lacking, $protected] = $this->deletionSummary($request, [$object]);

        if ($request->isMethod('POST') && $lacking === [] && $protected === []) {
            $repr = (string) $object;
            $user = $request->user();
            \assert($user instanceof User);
            LogEntry::log($user, $object, LogEntry::DELETION);
            $admin->deleteModel($request, $object);
            Messages::success($request, sprintf('The %s “%s” was deleted successfully.', $meta->verboseName, $repr));

            return $this->redirect($this->changelistUrl($admin));
        }

        return $this->page($request, 'admin/delete_confirmation.html', [
            'title' => 'Are you sure?',
            'model' => $this->modelInfo($admin),
            'object' => (string) $object,
            'deleted_objects' => $deleted,
            'perms_lacking' => $lacking,
            'protected' => $protected,
            'breadcrumbs' => [...$this->breadcrumbs($admin), ['label' => (string) $object, 'url' => $this->objectUrl('change', $admin, $object)], ['label' => 'Delete', 'url' => null]],
        ]);
    }

    public function history(Request $request, string $app_label, string $model_name, string $object_id): Response
    {
        if (($redirect = $this->staffOnly($request)) !== null) {
            return $redirect;
        }
        $admin = $this->modelAdmin($app_label, $model_name);
        $object = $this->getObject($request, $admin, $object_id);
        if (!$admin->hasViewPermission($request, $object)) {
            throw new PermissionDenied('You do not have permission to view this.');
        }
        $entries = LogEntry::objects()
            ->filter(content_type: strtolower($admin->meta()->label()), object_id: (string) $object->pk)
            ->orderBy('action_time', 'id')
            ->selectRelated('user');
        $actions = [];
        foreach ($entries as $entry) {
            $actions[] = [
                'time' => $entry->action_time->format('M j, Y, H:i'),
                'user' => (string) $entry->user,
                'message' => $entry->isAddition() ? 'Added.' : ($entry->isDeletion() ? 'Deleted.' : ($entry->change_message !== '' ? $entry->change_message : 'Changed.')),
            ];
        }

        return $this->page($request, 'admin/object_history.html', [
            'title' => 'Change history: ' . $object,
            'model' => $this->modelInfo($admin),
            'actions' => $actions,
            'breadcrumbs' => [...$this->breadcrumbs($admin), ['label' => (string) $object, 'url' => $this->objectUrl('change', $admin, $object)], ['label' => 'History', 'url' => null]],
        ]);
    }

    // Change form ---------------------------------------------------------------

    private function changeForm(Request $request, ModelAdmin $admin, ?Model $object): Response
    {
        $meta = $admin->meta();
        $adding = $object === null;
        $canSave = $adding || $admin->hasChangePermission($request, $object);
        $form = null;
        if ($request->isMethod('POST')) {
            if (!$canSave) {
                throw new PermissionDenied('You do not have permission to change this.');
            }
            $form = $admin->getForm($request, $object, (array) $request->post(), $request->files());
            // Before validation: a ModelForm copies the new values onto the object.
            $before = $object === null ? [] : self::snapshot($object, array_keys($form->getFields()));
            if ($form->isValid()) {
                $saved = $form instanceof ModelForm ? $form->instance() : $object;
                if ($saved === null) {
                    throw new \LogicException('The admin form has no object to save.');
                }
                $admin->saveModel($request, $saved, $form, !$adding);
                $admin->saveRelated($request, $form, !$adding);
                $user = $request->user();
                \assert($user instanceof User);
                if ($adding) {
                    LogEntry::log($user, $saved, LogEntry::ADDITION);
                } else {
                    $changed = array_keys(array_diff_assoc(self::snapshot($saved, array_keys($form->getFields())), $before));
                    $labels = array_map(static fn (string $name): string => $admin->label($name), $changed);
                    LogEntry::log($user, $saved, LogEntry::CHANGE, $changed === [] ? 'No fields changed.' : 'Changed ' . implode(', ', $labels) . '.');
                }

                return $this->afterSave($request, $admin, $saved, $adding);
            }
        }
        $form ??= $admin->getForm($request, $object);

        $readonly = [];
        $readonlyNames = $canSave
            ? $admin->getReadonlyFields($request, $object)
            : array_values(array_unique([...array_keys($form->getFields()), ...$admin->getReadonlyFields($request, $object)]));
        foreach ($readonlyNames as $name) {
            $readonly[] = ['label' => $admin->label($name), 'value' => $object !== null ? $admin->display($object, $name) : '-'];
        }

        return $this->page($request, 'admin/change_form.html', [
            'title' => ($adding ? 'Add ' : ($canSave ? 'Change ' : 'View ')) . $meta->verboseName,
            'model' => $this->modelInfo($admin),
            'form' => $canSave ? $form : null,
            'readonly' => $readonly,
            'adding' => $adding,
            'can_save' => $canSave,
            'can_add_another' => $admin->hasAddPermission($request),
            'object' => $object !== null ? (string) $object : '',
            'delete_url' => $object !== null && $admin->hasDeletePermission($request, $object) ? $this->objectUrl('delete', $admin, $object) : null,
            'history_url' => $object !== null ? $this->objectUrl('history', $admin, $object) : null,
            'breadcrumbs' => [...$this->breadcrumbs($admin), ['label' => $adding ? 'Add ' . $meta->verboseName : (string) $object, 'url' => null]],
        ]);
    }

    private function afterSave(Request $request, ModelAdmin $admin, Model $object, bool $adding): Response
    {
        $meta = $admin->meta();
        $name = sprintf('The %s “%s” was %s successfully.', $meta->verboseName, $object, $adding ? 'added' : 'changed');
        if ($request->post('_continue') !== null) {
            Messages::success($request, $name . ' You may edit it again below.');

            return $this->redirect($this->objectUrl('change', $admin, $object));
        }
        if ($request->post('_addanother') !== null) {
            Messages::success($request, $name . ' You may add another ' . $meta->verboseName . ' below.');

            return $this->redirect(reverse('admin:add', [$meta->appLabel, strtolower($meta->modelName)]));
        }
        Messages::success($request, $name);

        return $this->redirect($this->changelistUrl($admin));
    }

    // Actions -------------------------------------------------------------------

    private function handleAction(Request $request, ModelAdmin $admin): ?Response
    {
        $action = $request->post('action');
        $actions = $admin->getActions($request);
        if (!is_string($action) || !isset($actions[$action])) {
            Messages::warning($request, 'No action selected.');

            return null;
        }
        $selected = $request->post('_selected_action');
        $ids = is_array($selected) ? array_values(array_filter($selected, static fn (mixed $id): bool => is_string($id) && $id !== '' && strlen($id) <= 255)) : [];
        if ($ids === []) {
            Messages::warning($request, 'Items must be selected in order to perform actions on them. No items have been changed.');

            return null;
        }
        // Only objects the user can reach through the ModelAdmin's QuerySet.
        $queryset = $admin->getQueryset($request)->filter(pk__in: $ids);
        // And only those the per-object permission allows.
        $allowed = [];
        foreach ($queryset as $object) {
            $permitted = $action === 'delete_selected'
                ? $admin->hasDeletePermission($request, $object)
                : $admin->hasChangePermission($request, $object);
            if ($permitted) {
                $allowed[] = $object->pk;
            }
        }
        if ($allowed === []) {
            Messages::warning($request, 'You do not have permission to do that to the selected items. No items have been changed.');

            return null;
        }
        $queryset = $admin->getQueryset($request)->filter(pk__in: $allowed);

        if ($action === 'delete_selected') {
            return $this->deleteSelected($request, $admin, $queryset);
        }
        $response = $admin->runAction($action, $request, $queryset);
        if ($response === null) {
            Messages::success($request, sprintf('%s: done.', $actions[$action]));
        }

        return $response;
    }

    /**
     * @param QuerySet<Model> $queryset
     */
    private function deleteSelected(Request $request, ModelAdmin $admin, QuerySet $queryset): ?Response
    {
        $meta = $admin->meta();
        $objects = array_values(iterator_to_array($queryset));
        if ($objects === []) {
            return null;
        }
        [$deleted, $lacking, $protected] = $this->deletionSummary($request, $objects);
        if ($request->post('post') === 'yes' && $lacking === [] && $protected === []) {
            $user = $request->user();
            \assert($user instanceof User);
            foreach ($objects as $object) {
                LogEntry::log($user, $object, LogEntry::DELETION);
                $admin->deleteModel($request, $object);
            }
            Messages::success($request, sprintf('Successfully deleted %d %s.', count($objects), count($objects) === 1 ? $meta->verboseName : $meta->verboseNamePlural));

            return null;
        }

        return $this->page($request, 'admin/delete_selected_confirmation.html', [
            'title' => 'Are you sure?',
            'model' => $this->modelInfo($admin),
            'objects' => array_map(static fn (Model $object): array => ['pk' => (string) $object->pk, 'repr' => (string) $object], $objects),
            'deleted_objects' => $deleted,
            'perms_lacking' => $lacking,
            'protected' => $protected,
            'breadcrumbs' => [...$this->breadcrumbs($admin), ['label' => 'Delete multiple objects', 'url' => null]],
        ]);
    }

    /**
     * What deleting these objects would remove, the models the user may not
     * delete, and the objects that block the deletion.
     *
     * @param list<Model> $objects
     * @return array{0: list<array{model: string, items: list<string>, more: int}>, 1: list<string>, 2: list<string>}
     */
    private function deletionSummary(Request $request, array $objects): array
    {
        $collector = new Collector($objects[0]::connection());
        try {
            $collector->collect($objects);
        } catch (ProtectedError $e) {
            return [[], [], array_map(static fn (Model $o): string => ucfirst($o::meta()->verboseName) . ': ' . $o, $e->protectedObjects)];
        }
        $deleted = [];
        $lacking = [];
        foreach ($collector->objects() as $model => $items) {
            $meta = $model::meta();
            $related = $this->registry[$model] ?? null;
            if ($related !== null && !$related->hasDeletePermission($request)) {
                $lacking[] = $meta->verboseNamePlural;
            } elseif ($related === null && !$request->user()->hasPerm($meta->appLabel . '.delete_' . strtolower($meta->modelName))) {
                $lacking[] = $meta->verboseNamePlural;
            }
            $deleted[] = [
                'model' => ucfirst($meta->verboseNamePlural),
                'items' => array_map('strval', array_slice($items, 0, 50)),
                'more' => max(0, count($items) - 50),
            ];
        }

        return [$deleted, $lacking, []];
    }

    // Helpers -------------------------------------------------------------------

    /**
     * A redirect to the admin login page unless the user is active staff.
     */
    private function staffOnly(Request $request): ?Response
    {
        $user = $request->user();
        if ($user->isActive() && $user->isStaff()) {
            return null;
        }

        return $this->redirect(reverse('admin:login') . '?' . http_build_query(['next' => $request->fullPath()]));
    }

    private function getObject(Request $request, ModelAdmin $admin, string $id): Model
    {
        try {
            $object = $admin->getQueryset($request)->filter(pk: $id)->first();
        } catch (QueryException|ValidationError) {
            $object = null;
        }

        return $object ?? throw new Http404(sprintf('%s with ID “%s” doesn’t exist. Perhaps it was deleted?', ucfirst($admin->meta()->verboseName), $id));
    }

    /**
     * Render an admin page; never cached.
     *
     * @param array<string, mixed> $context
     */
    private function page(Request $request, string $template, array $context, bool $showUserTools = true): Response
    {
        $user = $request->user();
        $response = new Response($this->app->templates()->render($template, [
            'site_header' => $this->siteHeader,
            'site_title' => $this->siteTitle,
            'site_url' => $this->siteUrl,
            'admin_user' => $user,
            'show_user_tools' => $showUserTools && $user->isActive() && $user->isStaff(),
            'admin_messages' => Messages::pull($request),
            'breadcrumbs' => [],
            ...$context,
        ], $request));

        // Never framed, even without SecurityHeadersMiddleware (clickjacking).
        if (!$response->hasHeader('X-Frame-Options')) {
            $response->header('X-Frame-Options', 'DENY');
        }

        return $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
    }

    private function redirect(string $url): RedirectResponse
    {
        $response = new RedirectResponse($url);
        $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');

        return $response;
    }

    private function safeNext(Request $request): ?string
    {
        $next = $request->post('next') ?? $request->query('next');

        return Redirects::isSafe($next, $request) ? (string) $next : null;
    }

    /**
     * The registered apps and models the user may see.
     *
     * @return list<array{label: string, name: string, url: string, models: list<array{name: string, url: ?string, add_url: ?string}>}>
     */
    private function appList(Request $request, ?string $only = null): array
    {
        $apps = [];
        foreach ($this->registry as $admin) {
            $meta = $admin->meta();
            if (($only !== null && $meta->appLabel !== $only) || !$this->app->apps()->has($meta->appLabel) || !$admin->hasAnyPermission($request)) {
                continue;
            }
            $config = $this->app->apps()->get($meta->appLabel);
            $apps[$meta->appLabel] ??= ['label' => $meta->appLabel, 'name' => $config->verboseName, 'url' => reverse('admin:app_list', [$meta->appLabel]), 'models' => []];
            $apps[$meta->appLabel]['models'][] = [
                'name' => ucfirst($meta->verboseNamePlural),
                'url' => $admin->hasViewPermission($request) ? $this->changelistUrl($admin) : null,
                'add_url' => $admin->hasAddPermission($request) ? reverse('admin:add', [$meta->appLabel, strtolower($meta->modelName)]) : null,
            ];
        }
        uasort($apps, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        foreach ($apps as &$app) {
            usort($app['models'], static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        }

        return array_values($apps);
    }

    /**
     * @return list<array{flag: string, repr: string, type: string, url: ?string}>
     */
    private function recentActions(Request $request): array
    {
        $user = $request->user();
        if (!$user instanceof User || !$this->app->db()->hasTable(LogEntry::meta()->table)) {
            return [];
        }
        $actions = [];
        foreach (LogEntry::objects()->filter(user: $user->pk)->limit(10) as $entry) {
            [$appLabel, $modelName] = array_pad(explode('.', $entry->content_type, 2), 2, '');
            $url = null;
            $type = ucfirst(str_replace('_', ' ', $modelName));
            try {
                $type = ucfirst($this->modelAdmin($appLabel, $modelName)->meta()->verboseName);
                if (!$entry->isDeletion()) {
                    $url = reverse('admin:change', [$appLabel, $modelName, $entry->object_id]);
                }
            } catch (Http404) {
                // The model is no longer in the admin.
            }
            $actions[] = [
                'flag' => $entry->isAddition() ? 'addition' : ($entry->isChange() ? 'change' : 'deletion'),
                'repr' => $entry->object_repr,
                'type' => $type,
                'url' => $url,
            ];
        }

        return $actions;
    }

    /**
     * @return array{app_label: string, app_name: string, model_name: string, name: string, plural: string, changelist_url: string}
     */
    private function modelInfo(ModelAdmin $admin): array
    {
        $meta = $admin->meta();

        return [
            'app_label' => $meta->appLabel,
            'app_name' => $this->app->apps()->has($meta->appLabel) ? $this->app->apps()->get($meta->appLabel)->verboseName : $meta->appLabel,
            'model_name' => strtolower($meta->modelName),
            'name' => $meta->verboseName,
            'plural' => ucfirst($meta->verboseNamePlural),
            'changelist_url' => $this->changelistUrl($admin),
        ];
    }

    /**
     * @return list<array{label: string, url: ?string}>
     */
    private function breadcrumbs(ModelAdmin $admin): array
    {
        $info = $this->modelInfo($admin);

        return [
            ['label' => $info['app_name'], 'url' => reverse('admin:app_list', [$info['app_label']])],
            ['label' => $info['plural'], 'url' => $info['changelist_url']],
        ];
    }

    private function changelistUrl(ModelAdmin $admin): string
    {
        $meta = $admin->meta();

        return reverse('admin:changelist', [$meta->appLabel, strtolower($meta->modelName)]);
    }

    private function objectUrl(string $page, ModelAdmin $admin, Model $object): string
    {
        $meta = $admin->meta();

        return reverse('admin:' . $page, [$meta->appLabel, strtolower($meta->modelName), (string) $object->pk]);
    }

    /**
     * The database values of some fields, to find what a save changed.
     *
     * @param list<string> $names
     * @return array<string, string>
     */
    private static function snapshot(Model $object, array $names): array
    {
        $values = [];
        foreach ($names as $name) {
            if ($object::meta()->hasField($name)) {
                $field = $object::meta()->field($name);
                $value = $field->toDb($object->getAttribute($field->attname));
                $values[$name] = is_scalar($value) || $value === null ? var_export($value, true) : (string) json_encode($value);
            }
        }

        return $values;
    }
}
