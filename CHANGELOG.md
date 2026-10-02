# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Per-collective Enable/Disable button in the settings page's per-collective table. Disabling
  deletes the collective's Todos page (to trash) and stops generating or reverse-syncing it; the
  checkbox cache keeps being maintained, so enabling regenerates a consistent page immediately.
  Enabled is the default.
- Global Enable/Disable button in the settings page's Defaults section: flips the instance-wide
  default. Enabling resets every collective to enabled - it clears all per-collective enabled
  opt-outs and regenerates their Todos pages, since opting into the app means wanting it everywhere;
  disabling single collectives stays the manual choice. Disabling globally deletes the Todos page of
  every collective that is not already opted out.
- The per-collective Enable button clears the collective's enabled override instead of writing a
  sticky enabled value: a sticky override would shield the collective from the global default toggle
  forever, so a global disable reliably deletes every Todos page of collectives that were not
  explicitly disabled. The per-row Enable buttons render as inactive while the global default is
  disabled. Deleting the Todos page on disable now also removes its `collectives_pages` row (the
  delete is a hard delete on the appdata path, which the Collectives trash does not handle; the
  managed page's content is fully derived, so nothing of value is lost).
- The settings page shows the resolved enabled state next to every toggle (`Enabled`,
  `Disabled (opt-out)` or `Disabled (global)`): the button carries the action, the badge the state,
  so a row with a `Disable` button no longer reads as a disabled collective.

### Fixed

- Deleting the Todos page recreates it immediately. Collectives moves deleted pages to trash; the
  trash move is complete before the delete event fires and collectives paths are never locked, so no
  delayed recreation is needed.
- The emoji of a freshly (re)created Todos page is now really written: inserting the missing
  `collectives_pages` row failed because its `last_user_id` column is NOT NULL. The app-created row
  now carries an empty user id (the frontend hides the "last edited" info for such pages), like
  untouched pages.

### Added

- Todos page emoji config item (default none, per-collective override): the emoji is written to the
  Todos page's `collectives_pages` row like the emoji of any other Collectives page and rendered in
  the page tree. Validated with the Collectives app's own emoji validation; the settings page ships
  a curated emoji picker built with plain HTML/JS (no new dependencies), and any emoji can be pasted
  directly. The emoji is enforced on every Todos page regeneration and on settings save.

- Admin settings page (Settings → Admin → Collectives Todos) with instance-wide defaults and
  per-collective overrides for three config items: the Todos page name (default `Todos`), the
  position of the Todos page in the Collectives page tree (always on top / always on bottom /
  alphabetically, default always on top) and the maximum number of cached checkboxes per collective
  (0 = unlimited, default 0). An empty override field uses the default. Invalid input is rejected
  without storing anything.
- The tree position is enforced by writing the landing page's `collectives_pages.subpage_order` (the
  Collectives frontend sorts listed pages by array order, unlisted pages alphabetically), on every
  Todos page regeneration and on settings save. Manual ordering of the other pages is preserved; an
  already-open Collectives tab needs a reload to pick up the new order.
- Changing the Todos page name renames the existing Todos page file; if a page with the new name
  already exists in a collective, the rename is skipped and reported on the settings page.
- When the checkbox limit is reached, further checkboxes are not cached and the Todos page renders a
  `*(list truncated by the settings limit)*` note under the affected page's section.
- Minimum Nextcloud version raised from 25 to 27 (the admin settings controller uses the
  `AuthorizedAdminSetting` attribute introduced in 27).

### Added

- Bidirectional checkbox sync: ticking or unticking a checkbox on the Todos page now also updates
  the checkbox on the source page it was aggregated from. Sections of the Todos page are resolved to
  source pages by the page file id in the heading link URL (title as fallback), and checkboxes are
  matched by text within a section. Only checked-state changes are synced; text edits on the Todos
  page are discarded with the next regeneration. If the cached line number of a checkbox has
  drifted, the checkbox is located by a unique text match, otherwise the change is skipped and a
  warning is logged.
- Page headings on the Todos page are now links to the corresponding page, in the same URL format
  the Collectives app itself uses (`/apps/collectives/<collectives>/<slug>-<pageId>`, with a
  `?fileId=` fallback for pages without a slug). If the URL cannot be resolved, the plain heading is
  kept.
- Regenerating the Todos page while the Text editor had it open caused stale content to be shown and
  "Error saving the document" on manual saves: the Text app keeps per-file session state (etag,
  checksum, editor steps) that our background write invalidated. The Text document state of the
  Todos page is now reset on every regeneration, so open editors reload the fresh content. Unsaved
  manual edits to the Todos page are discarded by design.

### Fixed

- Deleting a page did not clean up the Todos page: the deleted page's section and checkboxes stayed
  listed. A Collectives page is a folder (the index page is its Readme.md, subpages are further
  files in it), and deleting it only emits a filesystem event for the folder itself - the markdown
  files inside vanish without their own events. A new listener for `BeforeNodeDeletedEvent` now
  collects the markdown pages under a folder before it is deleted, removes them from the cache and
  regenerates the Todos page. Plain `.md` file deletes keep their previous cleanup path. Restoring a
  page from the Collectives trash does not re-add it to the Todos page until the page is saved again
  or `collectives_todos:init` runs.
- After ticking a checkbox on the Todos page, the source page briefly showed the tick and then
  reverted to its old content, with the editor refusing saves ("File changed in the meantime from
  outside"): the Text app keeps per-file session state (etag, checksum, steps) also for source
  pages, and the reverse sync's external write desynced it. The Text document state of a source page
  is now reset when the reverse sync writes it, so the page loads with the synced state and saves
  work. Unsaved keystrokes in an editing session open on that page at that moment are discarded
  (same contract the Todos page already has).
- Checking a checkbox on the Todos page and saving broke the editing session: the reverse sync's
  regeneration of the Todos page ran nested inside the save and rewrote the page with a fresh "Last
  updated" timestamp, changing the etag under the open editor ("Error saving the document", conflict
  diff prompts). The Todos page is now only rewritten when its content actually changes (checkbox
  states, pages, titles); an update that would only refresh the "Last updated" timestamp leaves the
  file - and open editing sessions - untouched.
- App bootstrap was silently skipped: `info.xml` declared the full namespace
  (`OCA\CollectiveTodos`), which Nextcloud prefixes with `OCA\` itself, so no class of the app was
  autoloadable. The namespace is now `CollectiveTodos`.
- `Application.php` moved from `appinfo/` to `lib/AppInfo/` (PSR-4 location that the bootstrap looks
  up).
- CLI command is now registered via `info.xml` `<commands>` (previously the occ command never showed
  up).
- Todos page is now written to the collectives root folder (previously it was written next to the
  edited page).
- Page titles for `Readme.md` pages now use the page folder name (Collectives stores a page as
  folder with a `Readme.md` inside).
- The listener skips its own `Todos.md` writes to avoid an infinite write-event loop, and logs
  failures instead of breaking saves.
- `collectives_todos:init` now scans all pages of a collectives and accepts the collectives id as
  used by the Collectives app.

## [1.0.0] - 2026-10-01

### Added

- Initial release of Collectives Todos app
- Event-driven checkbox aggregation using Nextcloud core file events
- Auto-generated Todos.md page per collectives
- JSON cache for efficient updates
- CLI command for initializing existing collectives
- Unit tests for all core services and listeners
