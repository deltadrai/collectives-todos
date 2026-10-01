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

### Maximum Checkboxes per Collective

To prevent cache files from becoming too large, you can set a limit:

1. Go to Settings → Admin → Collectives Todos
2. Set "Maximum Checkboxes" to your desired limit (0 = unlimited)

## Data Storage

- Cache: `.collective/todos.json` in each collectives folder
- Generated page: `Todos.md` in each collectives folder
- All data is stored within the collectives and is backed up with the collectives

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
