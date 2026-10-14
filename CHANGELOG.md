# Changelog

All notable changes to BrewPHP are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

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
  project, app and framework folders. The Django-like engine is planned for 0.3.
- **CLI:** `manage.php` runner with colours, `--help`, `--no-interaction`,
  exit codes and command discovery from `apps/<app>/Commands/`; commands
  `serve`/`runserver`, `routes`, `about`, `check` (with `--deploy`),
  `key:generate` and `make:command` (with overridable stubs).
- Welcome page shown while `DEBUG` is on and no routes exist, styled with
  Plus Jakarta Sans and the BrewPHP coffee palette (light and dark).
