# Changelog

All notable changes to BrewPHP are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **Admin (v0.6):** the `brewphp.admin` app, an automatic admin like
  Django's: `AdminSite` (`AdminSite::default()->urls()` under the `admin`
  namespace) with a dashboard, app pages, change lists, add/change/delete
  pages with a confirmation that lists cascaded objects, object history,
  and staff login, logout (POST only) and password change; `ModelAdmin`
  with `listDisplay` (fields, `__str__` or admin methods),
  `listDisplayLinks`, `listFilter` (booleans, choices, foreign keys, date
  ranges, values), `searchFields` (`^`, `=` and related lookups),
  `ordering`, `listPerPage`, `fields`, `exclude`, `readonlyFields`,
  `labels`, `formClass`, custom `actions` and "Delete selected", and the
  hooks `getQueryset()`, `getFields()`, `getReadonlyFields()`, `getForm()`,
  `saveModel()`, `saveRelated()`, `deleteModel()` and the
  `has*Permission()` methods; the `#[Register]` attribute and auto-discovery
  of each app's `admin.php`; `UserAdmin` and `GroupAdmin`; the `LogEntry`
  model (`admin_log_entry`) for recent actions and history; templates in
  `resources/templates/admin/` and a stylesheet and script served from a
  fixed list (no inline code, so a strict CSP works); `admin.E001` to
  `admin.E004`, `admin.E108` and `admin.E116` checks.
- **Admin security (v0.6):** only active staff get in; every page checks
  the model's view/add/change/delete permission; `getQueryset()` limits
  every page and action (rows outside it are 404s); filters, sorting and
  actions only accept what the ModelAdmin declares; deleting needs the
  delete permission of every cascaded model; staff who are not superusers
  cannot see superusers or change superuser status, groups or permissions;
  admin pages are never cached.
- **`startapp` (v0.6):** also writes an `admin.php`.
- **Forms (v0.6):** `AuthenticationForm::getUserUnchecked()` for subclasses
  that add login rules.
- **Database (v0.6):** `Collector::objects()` lists what a deletion would
  remove.

- **Sessions (v0.5):** `SessionMiddleware` and `$request->session()`
  (`get`, `set`, `has`, `remove`, `pull`, `clear`, `flush`, `cycleKey`),
  loaded lazily and saved only when changed; file (default), database
  (`brewphp.sessions` app, `sessions_session` table) and array stores;
  data stored as JSON, never unserialize(); ids the store does not know are
  never adopted (no session fixation); HttpOnly, SameSite=Lax cookies with
  the `SESSION_*` settings; the `clearsessions` command.
- **Messages (v0.5):** one-time flash messages (`Messages::success()`,
  `info()`, `warning()`, `error()`, `debug()`) and the `messages` context
  processor.
- **CSRF protection (v0.5):** `CsrfViewMiddleware` with Django's masked
  tokens (a different token on every page, against BREACH), the
  `csrfmiddlewaretoken` field or `X-CSRFToken` header, Origin checks, Referer
  checks over HTTPS, `CSRF_TRUSTED_ORIGINS` (with `https://*.example.com`),
  `CSRF_USE_SESSIONS`, the `#[CsrfExempt]` attribute, token rotation at
  login, and `csrf_token` in every template rendered with a request.
- **Auth (v0.5):** the `brewphp.auth` app with `User`, `Group` and
  `Permission` models (and their link tables), default add/change/delete/view
  permissions created after `migrate`; `Auth::authenticate()`, `login()`,
  `logout()` and `updateSessionAuthHash()`; `AuthenticationMiddleware` and
  `$request->user()` (a `User` or `AnonymousUser`); `ModelBackend` with
  timing-safe lookups; Argon2id or bcrypt-SHA256 password hashes upgraded at
  login (`PASSWORD_HASHER`, `PASSWORD_HASHER_OPTIONS`); password validators
  (`MinimumLengthValidator`, `CommonPasswordValidator`,
  `NumericPasswordValidator`, `UserAttributeSimilarityValidator`); sessions
  logged out when the password changes; login throttling
  (`AUTH_LOGIN_ATTEMPTS`, `AUTH_LOGIN_LOCKOUT`); `LoginRequired` and
  `PermissionRequired` route middleware; `LoginView`, `LogoutView` (POST
  only), `PasswordChangeView` and `PasswordChangeDoneView` with templates and
  `Auth\Urls::patterns()`; the `auth` context processor (`user`, `perms`);
  the `createsuperuser` and `changepassword` commands.
- **Forms (v0.5):** `Form` with fields, per-field `clean<Name>()` hooks,
  `clean()`, errors, prefixes, initial values and escaped rendering
  (`{{ form }}`, `{{ form.field }}`, `label_tag`, `errors`, help texts with
  `aria-describedby`); fields `CharField`, `EmailField`, `URLField` (http and
  https only), `SlugField`, `IntegerField`, `FloatField`, `DecimalField`,
  `BooleanField`, `ChoiceField` (arrays or backed enums),
  `MultipleChoiceField`, `DateField`, `DateTimeField`, `TimeField`,
  `UUIDField`, `JsonField`, `FileField` (size and extension checks),
  `ModelChoiceField` and `ModelMultipleChoiceField` (limited to their
  QuerySet); widgets for every input type, selects, radios and checkboxes;
  `ModelForm`, which requires an explicit field list (no mass assignment)
  and runs the model's `fullClean()`.
- **Security helpers (v0.5):** `Signer` (HMAC-SHA256 with salts,
  timestamps and signed JSON), `Redirects::isSafe()` against open
  redirects, and a file-based `RateLimiter`.
- **Testing (v0.5):** `BrewPHP\Testing\Client`, a test browser that keeps
  cookies, logs users in and provides CSRF tokens.
- **Templates (v0.5):** the `HtmlSafe` interface for objects that print
  their own escaped HTML.
- **HTTP (v0.5):** `Response::vary()`; `Request::session()`,
  `hasSession()`, `user()` and `setUser()`.
- **Checks (v0.5):** `auth.W001`/`auth.E001` for the middleware order, and
  `check --deploy` warnings for a missing `CsrfViewMiddleware` and insecure
  session or CSRF cookies.

- **Migrations (v0.4):** `BrewPHP\Db\Migrations` with migration files in
  each app's `migrations/` folder (`NNNN_name.php` returning an anonymous
  `Migration` subclass with `$dependencies`, `$initial`, `$atomic` and
  `operations()`); the operations `CreateModel`, `DeleteModel`,
  `RenameModel`, `AlterModelTable`, `AddField`, `RemoveField`,
  `AlterField`, `RenameField`, `AddIndex`, `RemoveIndex`, `RunSQL` and
  `RunPHP` (with `preserveDefault: false` one-off defaults); `ProjectState`
  and `ModelState` (historical models whose foreign keys point at the
  table as it was); `Loader`, `Graph` (dependency order, conflicts, cycles,
  name prefixes), `Recorder` (the `brewphp_migrations` table), `Executor`
  (forwards, backwards, `zero`, fake, per-migration transactions with
  foreign key checks on SQLite, consistent-history check), `Autodetector`
  with an interactive `Questioner` (renamed models and fields, values for
  new non-null fields) and `Writer`, which only writes plain values
  through `var_export()`. Migrations record only what changes the database:
  options such as `helpText`, `choices` or `onDelete` need no migration.
- **Migration commands (v0.4):** `makemigrations` (apps, `--name`,
  `--empty`, `--dry-run`, `--check` for CI, `--merge` for conflicting
  migrations), `migrate` (app, target migration or `zero`, `--fake`,
  `--plan`, `--database`, a refusal while migrations conflict, and a
  warning for model changes without migrations), `showmigrations` (`--plan`) and
  `sqlmigrate` (`--backwards`). Migrations are never written into the
  framework or a Composer package.
- **`startapp` (v0.4):** creates `apps/<label>/` with `App.php`,
  `models.php`, `views.php`, `urls.php`, a template and `migrations/`, from
  stubs a project can override in `stubs/app/`. Labels are validated (no
  path tricks, no reserved or installed labels).
- **Apps:** `AppConfig::migrationsPath()` and the `AppConfig::migrated()`
  hook, called after every successful `migrate`.
- **CLI:** array arguments (`addArgument(array: true)`, shown as
  `[<app>...]`) and `Command::relative()`.
- **Database:** `Connection::hasTable()`; `SchemaEditor::execute()`,
  `connection()` and `isCollectOnly()` are public;
  `Field::deconstructForMigration()`.

### Changed

- **Migrations (v0.5):** generated files use `return new class () extends Migration {`
  (PSR-12).
- **Debug page (v0.5):** values whose names contain SESSION or CSRF (such
  as the session cookie) are masked too.
- **SQLite schema editor:** adding a non-null column without a default to an
  empty table, or making a column non-null without a default, now works as
  on MySQL and PostgreSQL; it only fails when a row needs a value.

- **Template language:** a Django-like template engine (`BrewPHP\Templates`)
  for every template whose extension is not `.php`: variables with dotted
  lookups (array keys, properties, `__get`, zero-argument methods with
  snake_case to camelCase, model fields and relations, list indexes),
  filters with arguments, tags, comments, template inheritance with
  `{% extends %}`, `{% block %}` and `{{ block.super }}` (including a
  project template extending the template it overrides), and
  `{% include %}` with `with` and `only`. Templates are compiled to PHP in
  `storage/templates/` and compiled again when their source changes.
- **Template tags:** `if`/`elif`/`else` (`and`, `or`, `not`, `==`, `!=`,
  `<`, `>`, `<=`, `>=`, `in`, `not in`, `is`, `is not`, parentheses),
  `for`/`empty` (with `forloop`, `reversed` and unpacking), `extends`,
  `block`, `include`, `with`, `url`, `static`, `now`, `cycle`, `firstof`,
  `spaceless`, `verbatim`, `autoescape`, `filter`, `comment`, `load` (a
  no-op) and `csrf_token`.
- **Template filters:** `add`, `capfirst`, `cut`, `date`, `default`,
  `default_if_none`, `dictsort`, `dictsortreversed`, `divisibleby`,
  `escape`, `first`, `floatformat`, `join`, `json_script`, `last`,
  `length`, `linebreaks`, `linebreaksbr`, `lower`, `pluralize`, `safe`,
  `slice`, `slugify`, `striptags`, `time`, `timesince`, `title`,
  `truncatechars`, `truncatewords`, `upper`, `urlencode`, `wordcount` and
  `yesno`.
- **Template security:** automatic HTML escaping, `SafeString` and the
  `mark_safe()` helper (`e()` leaves a `SafeString` unchanged); template
  text, literals and names never become PHP code; `json_script` encodes
  `<`, `>` and `&`; the `#[AltersData]` attribute (`BrewPHP\Core\AltersData`)
  keeps templates from calling `save()`, `delete()` and the other
  data-changing methods of models and QuerySets.
- **Template extensions:** `Library` with `filter()` (`isSafe`,
  `needsAutoescape`), `simpleTag()` (`takesContext`, `as name`) and `tag()`
  for compiled tags, registered from `AppConfig::ready()` with
  `$app->templates()->library()`; context processors in
  `TEMPLATES.CONTEXT_PROCESSORS` (callables or invokable classes) with the
  built-in `ContextProcessors::request`.
- **Templates facade:** `Templates` (`app()->templates()`) picks the
  engine by extension for `render()`, `render(list)`, `renderString()`,
  `exists()` and `find()`; `render()` runs the context processors during a
  request; new `render_to_string()` helper.
- **Template errors:** `TemplateSyntaxError`, `TemplateError` and
  `VariableDoesNotExist` report the template name and line; errors raised
  while rendering are wrapped with the template location (HTTP exceptions
  pass through) and the debug page shows the template source around the
  failing line.
- **Settings:** `TEMPLATES.CACHE`, `TEMPLATES.STRICT` and
  `TEMPLATES.CONTEXT_PROCESSORS`.
- **Error pages:** production error pages can be template-language files
  (`404.html` before `404.php`) and receive the context processors'
  variables.
- **Routing:** `reverse()` also accepts positional parameters in pattern
  order (used by `{% url %}`).

- **Database:** `DATABASES` setting (SQLite by default in
  `storage/db.sqlite3`, MySQL/MariaDB and PostgreSQL), lazy PDO
  `Connection` with typed bindings, `select()`, `selectOne()`, `scalar()`,
  `statement()`, `insert()`, nested `transaction()` with savepoints and a
  query log while `DEBUG` is on; `ConnectionManager` for named connections;
  the `db()` helper; `QueryException` and `IntegrityError`.
- **Grammars:** `SqliteGrammar`, `MysqlGrammar` and `PostgresGrammar` for
  identifier quoting, column types, LIMIT/OFFSET, LIKE, date parts and
  INSERT ... RETURNING.
- **Models:** `Model` base class with `fields()`, an implicit `id`
  primary key, `table()`, `ordering()`, `indexes()`, verbose names,
  attribute access to field values, `save()`, `delete()`, `refresh()`,
  `create()`, `toArray()`, JSON serialisation, the hooks `beforeSave()`,
  `afterSave()`, `beforeDelete()` and `afterDelete()`, `fullClean()` and
  `clean()` validation (fields, validators, unique fields and unique
  indexes), and `meta()` (`ModelMeta`).
- **Fields:** `AutoField`, `BigAutoField`, `CharField`, `TextField`,
  `SlugField`, `EmailField`, `URLField`, `IntegerField`, `BigIntegerField`,
  `PositiveIntegerField`, `FloatField`, `DecimalField`, `BooleanField`,
  `DateField`, `DateTimeField`, `TimeField`, `JsonField`, `UUIDField`,
  `ForeignKey` and `OneToOneField`, with the common options (`null`,
  `blank`, `default`, `unique`, `dbIndex`, `choices` including backed enums,
  `verboseName`, `helpText`, `editable`, `primaryKey`, `dbColumn`,
  `validators`) and `deconstruct()` for migrations.
- **Relations:** forward and reverse accessors (`$post->author`,
  `$author->posts`, `related()`), `OnDelete` rules (`Cascade`, `Protect`,
  `Restrict`, `SetNull`, `SetDefault`, `DoNothing`) applied in PHP with
  model hooks, and `ProtectedError`.
- **QuerySets:** lazy, immutable and cached; `filter()`, `exclude()`,
  `where()` with `Q` objects (`Q::or()`, `Q::and()`, `Q::not()`), Django
  lookups (`exact`, `iexact`, `contains`, `icontains`, `startswith`,
  `istartswith`, `endswith`, `iendswith`, `gt`, `gte`, `lt`, `lte`, `in`
  with lists or QuerySets, `range`, `isnull`, `year`, `month`, `day`),
  JOINs across foreign keys in both directions at any depth, `F`
  expressions, `orderBy()`, `reverse()`, `limit()`, `offset()`, `slice()`,
  `distinct()`, `get()`, `first()`, `last()`, `count()`, `exists()`,
  `values()`, `valuesList()`, `inBulk()`, `aggregate()` with `Count`,
  `Sum`, `Avg`, `Min` and `Max`, `selectRelated()`, `update()`, `delete()`,
  `create()`, `getOrCreate()`, `updateOrCreate()`, `none()`, `paginate()`,
  `raw()` and `toSql()`; `ObjectDoesNotExist`, `MultipleObjectsReturned`
  and the `get_object_or_404()` helper.
- **Schema editor:** `createModel()`, `deleteModel()`, `renameModel()`,
  `addField()`, `removeField()`, `alterField()`, `renameField()`,
  `addIndex()` and `removeIndex()` for each engine, with SQLite table
  rebuilds and a collect-only mode for printing SQL.
- **Registry:** models are discovered from each app's `models.php` and
  `Models/` folder (`AppConfig::modelsPath()`) and listed by
  `Registry::models()`.
- **Pagination:** `Paginator` and `Page` for QuerySets and arrays, with
  orphans, a forgiving `getPage()` and `elidedPageRange()`.
- **Validation:** `BrewPHP\Core\ValidationError` with messages per field,
  shared by models and (later) forms.
- **CLI:** `about` shows the database engine; `check` validates the
  `DATABASES` setting (`db.E001` to `db.E003`).
- **Core:** `Application` (base path, settings, apps, container), `Kernel`
  request cycle, a small autowiring `Container`, `Settings` with framework
  defaults and dot access, and a `.env` loader (`Env`, `env()`).
- **Apps:** `INSTALLED_APPS` registry with `AppConfig`; project apps in
  `apps/<label>/` are autoloaded as `Apps\<Label>\` and their `models.php`,
  `views.php` and `forms.php` modules are loaded at boot.
- **HTTP:** `Request`, `Response`, `JsonResponse`, `RedirectResponse`,
  `StreamedResponse`, `Cookie`, `UploadedFile`, and HTTP exceptions
  (`Http404`, `PermissionDenied`, `BadRequest`, `MethodNotAllowed`).
- **Routing:** `path()`, `re_path()`, `include_urls()` (Django's `include()`),
  `group()`, `#[Route]` attributes with `routes_from()`; converters `int`,
  `str`, `slug`, `uuid`, `path` plus custom converters; namespaced URL
  reversing with `reverse()` and `url()`; HTTP method restrictions;
  `APPEND_SLASH` redirects.
- **Views:** function views, controller methods and class-based `View`
  (per-verb handlers, `asView()`, automatic `OPTIONS` and `405`), with
  argument resolution by name and type and return-value conversion.
- **Middleware:** `Middleware` interface, `Pipeline` (classes, instances or
  closures), global `MIDDLEWARE` plus per-route and per-group middleware, and
  `SecurityHeadersMiddleware` (nosniff, frame options, referrer policy, COOP,
  optional HSTS and CSP).
- **Security:** `ALLOWED_HOSTS` Host-header validation; secrets masked on the
  debug page; no stack traces when `DEBUG` is off.
- **Errors:** debug error page (stack trace with source, request, settings,
  server), debug 404 page listing the URL patterns tried, production error
  pages `400.php`/`403.php`/`404.php`/`500.php` overridable by projects, JSON
  errors for API clients, and server errors written to the PHP error log.
- **Templates:** plain PHP template rendering (`render()`, `e()`) searched in
  project, app and framework folders.
- **CLI:** `manage.php` runner with colours, `--help`, `--no-interaction`,
  exit codes and command discovery from `apps/<app>/Commands/`; commands
  `serve`/`runserver`, `routes`, `about`, `check` (with `--deploy`),
  `key:generate` and `make:command` (with overridable stubs).
- Welcome page shown while `DEBUG` is on and no routes exist, styled with
  Plus Jakarta Sans and the BrewPHP coffee palette (light and dark).
