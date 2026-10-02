# Collectives Todos App

A Nextcloud app that aggregates markdown checkboxes across all pages in a Collectives into a central
"Todos" page.

## Features

- Automatically scans all pages in a Collectives for markdown checkboxes (`- [ ]` / `- [x]`)
- Aggregates them into a root "Todos.md" page
- Each page heading links to the page itself (Collectives URL format)
- Auto-updates when any page is saved, deleted, or renamed
- Bidirectional: ticking a checkbox on the Todos page also updates the checkbox on the source page
  it came from (matched by page link and checkbox text)
- JSON cache for efficient updates
- CLI command for initializing existing collectives

## Requirements

- Nextcloud 25+
- Collectives app 2.0.0+
- PHP 8.0+

## Installation

The app is not published to the Nextcloud app store. Deploy it manually:

1. Copy `apps/collectives_todos/` into the Nextcloud `apps/` directory (for this server: into the
   `systemd-nextcloud` container at `/var/www/html/apps/`)
2. Make sure the files are owned by the web server user (`www-data`)
3. Enable it: `php occ app:enable collectives_todos`

## Usage

Once installed and enabled, the app works automatically:

1. Create or edit any page in a Collectives with markdown checkboxes
2. The "Todos" page in the collectives root is automatically updated on save
3. All checkboxes from all pages are aggregated in one place
4. Ticking a checkbox on the "Todos" page updates the checkbox on its source page; the "Todos" page
   is then regenerated from the updated pages

## CLI Commands

### Initialize existing collectives

Scans all existing pages of a collectives and (re)builds the Todos page:

```bash
php occ collectives_todos:init <collectives-id>
```

The `collectives-id` is the id of the collectives as used by the Collectives app (e.g. `8`),
alternatively a folder file id or a folder path works.

## Configuration

Configure the app under Settings → Admin → Collectives Todos. Every config item has an instance-wide
default and can be overridden per collective (an empty field uses the default):

| Config item        | Meaning                                                                    | Default       |
| ------------------ | -------------------------------------------------------------------------- | ------------- |
| Todos page name    | Name of the generated page (without `.md`)                                 | `Todos`       |
| Tree position      | Position of the Todos page in the Collectives page tree                    | always on top |
| Maximum checkboxes | Cap on the total cached checkboxes per collective (0 = unlimited)          | `0`           |
| Enabled (toggle)   | Whether the Todos page is managed (global default + per-collective button) | enabled       |

- **Tree position**: `always on top` pins the Todos page to the top of the page tree,
  `always on bottom` to the bottom, `alphabetically` sorts it by title among the unpinned pages. The
  position is enforced by writing the Collectives `subpage_order` of the landing page and
  re-asserted on every Todos page regeneration; after dragging pages around in the browser, the next
  page save in that collective re-asserts it. An already-open Collectives tab shows the new order
  after a reload.
- **Todos page name**: changing the name renames the existing Todos page file. If a page with the
  new name already exists in a collective, the rename is skipped and reported on the settings page.
- **Todos page emoji**: the emoji is written to the Todos page's `collectives_pages` row, exactly
  like the emoji of any other Collectives page, and rendered in the page tree. It is validated the
  same way Collectives validates page emoji (single emoji, max 8 characters). The settings page
  offers a small curated picker, or paste any emoji directly; empty means no emoji. As with the tree
  position, manual emoji changes made in Collectives are re-asserted on the next page save.
- **Maximum checkboxes**: once the limit is reached, further checkboxes are not cached and the Todos
  page shows a `*(list truncated by the settings limit)*` note under the affected page's section.
- **Enabled**: the Defaults section has a global Enable/Disable button (the master switch), the
  per-collective table one per collective. The global default applies to every collective; the
  per-collective button only opts a collective out (Disable) or returns it to the default (Enable
  clears the override) - a collective cannot be enabled while the default is disabled, the settings
  page renders such Enable buttons as inactive. Enabling globally resets every collective to enabled
  (it clears all per-collective opt-outs and regenerates their Todos pages), because opting into the
  app means wanting it everywhere; disabling single collectives stays the manual choice. The
  settings page shows the resolved state next to every toggle - `Enabled`, `Disabled (opt-out)` or
  `Disabled (global)` - so a row's `Disable` button is recognizable as the action, not the state.
  Disabling deletes the affected Todos page and its page row (hard delete, not trash: the page is
  fully derived from the source pages, so a restored copy would only conflict with the page
  regenerated on re-enable) and stops generating or reverse-syncing it; the checkbox cache keeps
  being updated, so enabling regenerates a consistent page immediately. Collectives without any
  checkboxes still get a Todos page showing "No tasks found in this collectives."

## Data Storage

- Cache: `.collective/todos.json` in each collectives folder
- Generated page: the configured Todos page (default `Todos.md`) in each collectives folder
- Settings: `oc_appconfig` (app `collectives_todos`), defaults as plain keys and per-collective
  overrides as `collective.<id>.<key>`
- All other data is stored within the collectives and is backed up with the collectives

## Limitations

- v1: Page titles are derived from filenames (not Collectives's database titles)
- v1: --all CLI option requires manual collectives ID specification
- v1: Assumes collectives-level permissions (not per-page permissions)
- v2: Checkboxes on the Todos page are matched back to source pages by text; two identical checkbox
  texts on one page are ambiguous (the first one is synced)
- v2: Text edits made on the Todos page are discarded with the next regeneration

## Support

Issues and feature requests should be reported to the app maintainer.

## License

AGPL-3.0-or-later
