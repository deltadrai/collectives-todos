# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
