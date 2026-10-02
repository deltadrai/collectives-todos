# AGENTS.md

Nextcloud app `collectives_todos` (namespace `OCA\CollectiveTodos`): aggregates markdown checkboxes
across Collectives pages into a "Todos" page, with reverse-sync back to source pages.
PHP 8.0+, Nextcloud 27–34, depends on the `collectives` app.

## Project layout

- `appinfo/info.xml` — app metadata, dependencies, settings/command registration. Keep in sync.
- `appinfo/routes.php` — route table.
- `lib/AppInfo/Application.php` — `IBootstrap` (register listeners here, `boot()` stays minimal).
- `lib/Controller` — route handlers; thin, delegate to services.
- `lib/Service` — all business logic. One responsibility per class.
- `lib/Listener` — `IEventListener` implementations; validate event type, delegate to services.
- `lib/Settings` — admin settings classes (`ISection`/`IDelegatedSettings`).
- `templates/` — server-side PHP templates. `js/` — plain JS, loaded via `\OCP\Util::addScript`.
- `tests/` — PHPUnit Unit + Integration; run with `tests/run.sh` (podman container `systemd-nextcloud`).

## Conventions

- always branch with semantic commit prefix and task description when starting work.
- always merge via PR to main, never locally.
- Every file: `declare(strict_types=1)`, namespace under `OCA\CollectiveTodos`.
- Dependency injection only: type-hint constructor params; the container resolves them by
  reflection. No `new` for services inside classes, no `registerService` unless reflection is
  impossible. No `\OC::$server`, no global state.
- Code only against `OCP\*` interfaces (never `OC\*` internals). All user-visible strings via
  `IL10n` (`$l->t()`). No inline JS or inline event handlers (CSP).
- Indentation/style: match the file you are editing.

## Depending on other apps (`collectives`)

- Declare in `appinfo/info.xml` under `<depends>`: `<app>collectives</app>` (add `min-version`/
  `max-version` when using version-specific APIs), plus the `<nextcloud>` range.
- Use `OCA\Collective\...` classes only behind a narrow boundary (a single service or adapter
  class). All other code depends on that boundary, never on `OCA\Collective` directly — the
  dependency app's API may change between releases.
- Treat the dependency as potentially disabled at runtime: check
  `\OCP\AppFramework\Services\IAppConfig`/`IAppManager::isEnabledForUser('collectives')` (inject
  `OCP\IAppManager`) before touching its classes; degrade gracefully (skip work, log, return).
- New `info.xml` entries must also be reflected in `README.md` requirements.

## Admin config panel

- `lib/Settings/Section.php` implements `IIconSection`; `lib/Settings/Admin.php` implements
  `IDelegatedSettings` (admin delegation, NC 23+) and returns a
  `TemplateResponse('collectives_todos', 'admin', $params)`.
- Register both in `info.xml`: `<settings><admin>` and `<admin-section>`. Section ID must match
  `getSection()` in `Admin`.
- Persist settings via `OCP\IConfig` (`getAppValue`/`setAppValue`) wrapped in
  `SettingsService`; controllers and services never read `IConfig` keys directly.
- Saving goes through `SettingsController` (`routes.php`) returning `DataResponse`; JS uses
  `OCP.AppConfig`/fetch, then triggers reload of affected state server-side.
- Guard runtime-only calls (`\OCP\Util::addScript`, anything needing booted `\OC`) with
  `class_exists(\OC::class)` so unit tests, which boot no server, still pass.

## KISS

- Smallest solution that works; delete speculative options, flags, and abstractions.
- No new composer/npm production dependencies without need (PHP cannot load two versions of the
  same class; conflicts with server and other apps).
- Prefer core `OCP` APIs over hand-rolled equivalents (IConfig, IEventDispatcher, IRouting).
- Public-facing behavior changes: update `CHANGELOG.md` and `README.md`.

## SOLID

- One class = one responsibility (listener validates + delegates; controller routes + delegates;
  service does logic).
- Extend behavior by adding a new listener/service/class, not by editing unrelated ones.
- Depend on `OCP\*` interfaces in constructors, not concrete classes.
- Keep interfaces/parameter objects small; don't bloat service constructors beyond ~5 deps —
  split the service instead.

## Testing & verification

- Run `./tests/run.sh` (all) or `./tests/run.sh tests/Unit`. Add/adjust a unit test for every
  bug fix or service change; integration tests for cross-app behavior with `collectives`.
- Deploy target: podman container `systemd-nextcloud`, app dir `/var/www/html/apps/`.
  See README for manual deployment steps; do not run servers from this repo.

## When stuck

If an `OCP`/`OCA\Collectives` API behaves unexpectedly, check the version pinned in
`info.xml` against the Nextcloud 34 developer manual and the Collectives app source on the
server before inventing workarounds.
