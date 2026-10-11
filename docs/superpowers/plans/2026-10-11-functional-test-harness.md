# Functional Test Harness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A scripted, repeatable functional harness that boots the latest Nextcloud + latest Collectives in a podman container, seeds a two-page-by-three-subpage collective of business documents for a Mistral-powered AI product, and deploys the local bleeding-edge `collectives_todos` working tree against it.

**Architecture:** One POSIX sh script (`tests/functional/harness.sh`) with subcommands for the container lifecycle (`up`/`down`/`clean`/`status`), seeding (`seed`), and functional assertions (`smoke`), plus a committed fixture corpus of 8 markdown files reused verbatim across runs. Seeding talks to the instance through public surfaces only — OCS API for creating the collective and page tree, WebDAV for page content — so the app's real event listeners fire and `Todos.md` generation and reverse sync are exercised end-to-end. The app under test is always the local working tree, copied in with `podman cp`.

**Tech Stack:** podman, `docker.io/library/nextcloud:latest` (SQLite, Apache), Collectives from the Nextcloud app store, curl (OCS + WebDAV), POSIX sh.

**Spec:** User request (conversation, 2026-10-11):
> Plan a functional test harness that sets up the latest nextcloud, sqlite based; skips the user onboarding and runs with admin/admin; installs latest Collectives; preinitializes the Collectives App with 2 pages that have 3 sub pages — content: business documents of a new AI product that is powered by Mistral (generate once as .md for reuse); installs Collectives Todos current bleeding edge dev code from the local project.

## Global Constraints

- Nextcloud image: `docker.io/library/nextcloud:latest`, no DB env vars → SQLite. Overridable via `NC_IMAGE` env var. Container name `collectives-todos-functest`, bound to `127.0.0.1:8080`.
- Credentials: `NEXTCLOUD_ADMIN_USER=admin`, `NEXTCLOUD_ADMIN_PASSWORD=admin` (image auto-installs, onboarding never shown).
- Collectives: latest from the app store via `occ app:install collectives`.
- App under test: the local working tree (`podman cp`, `.git` excluded), enabled via `occ app:enable collectives_todos`.
- Page tree: exactly 2 top-level pages, each with exactly 3 subpages; content = business documents of a new AI product powered by Mistral.
- Fixtures are generated **once**, committed under `tests/functional/fixtures/pages/`, and never modified by the harness.
- App facts to rely on: default Todos page is `Todos.md` (`DEFAULT_TODOS_PAGE_NAME = 'Todos'`); fallback backfill command is `occ collectives_todos:init`.
- Scripts: POSIX sh, style matching `tests/run.sh`. No direct writes into `data/` — everything through OCS/WebDAV/occ.
- Repo conventions (AGENTS.md): work on a branch with semantic prefix; merge via PR only.

## Review Focus

1. **Duplicate checkbox texts across pages** — the app's reverse sync matches checkboxes by text (v2 limitation: identical texts on one page are ambiguous). Fixtures must have globally unique checkbox texts; Task 1 pins this with a check.
2. **`latest` image drift beyond the app's supported range** (info.xml: max 34) — `up` must fail with a clear message naming the resolved NC version if `app:enable` refuses.
3. **Seeding that bypasses filesystem hooks** (direct `data/` writes) would leave `Todos.md` ungenerated — seeding must use OCS/WebDAV only; smoke's first assertion catches a missing `Todos.md`.
4. **Port collision / stale container from a previous run** — `up` fails fast with a readable message; `clean` is idempotent (re-running on a gone container exits 0).
5. **Spaces in page titles** (e.g. "Product Strategy") break naive shell quoting — seed must handle paths with spaces; PROPFIND verification in Task 3 catches breakage.
6. **Checkbox-count truncation setting** — fixtures stay small (≤ 6 per page, ≤ 40 total); smoke asserts the full expected count, so a too-low truncation limit surfaces as a count mismatch.

---

### Task 1: Fixture corpus (business documents, generated once)

**Files:**
- Create: `tests/functional/fixtures/pages/Product Strategy.md`
- Create: `tests/functional/fixtures/pages/Product Strategy/Business Case.md`
- Create: `tests/functional/fixtures/pages/Product Strategy/Pricing and Licensing.md`
- Create: `tests/functional/fixtures/pages/Product Strategy/Go-to-Market Plan.md`
- Create: `tests/functional/fixtures/pages/Product Requirements.md`
- Create: `tests/functional/fixtures/pages/Product Requirements/PRD Overview.md`
- Create: `tests/functional/fixtures/pages/Product Requirements/API Requirements.md`
- Create: `tests/functional/fixtures/pages/Product Requirements/Security and Compliance.md`

**Interfaces:**
- Consumes: nothing.
- Produces: `tests/functional/fixtures/pages/` tree where a top-level `.md` file is a collective page and a subdirectory of the same name holds its subpages; the directory name equals the parent page title. Task 3's seed walks exactly this layout. Every `- [ ]` text across all 8 files is globally unique (Task 4's reverse-sync step depends on it).

- [ ] **Step 1: Create branch**

```bash
cd /home/phoenix/repos/collectives-todos && git checkout -b feat/functional-test-harness
```

- [ ] **Step 2: Write the 8 fixture files**

Business documents for a fictional AI product ("Aurora Assistant") powered by Mistral models. Style example for `Product Strategy.md` (others follow it, with page-specific content):

```markdown
# Product Strategy

Aurora Assistant is a business copilot powered by Mistral large language models.

## Goals

- [ ] Finalize the Aurora Assistant positioning against the Mistral model lineup
- [ ] Approve the Q1 strategy deck for the board
- [ ] Book the Mistral partnership review call
- [ ] Agree the reference-customer shortlist with sales
```

Rules for all 8 files:
- 3–6 unchecked `- [ ]` items each (no `- [x]`; the smoke test starts from all-open).
- Checkbox texts globally unique across the corpus (no duplicates anywhere).
- Prose first, checkboxes under a heading; 10–25 lines per file.

- [ ] **Step 3: Verify the corpus**

Run:

```bash
cd /home/phoenix/repos/collectives-todos
find tests/functional/fixtures/pages -name '*.md' | wc -l   # expect 8
grep -rh '^\- \[ \]' tests/functional/fixtures/pages | wc -l   # expect 24-48
grep -rh '^\- \[ \]' tests/functional/fixtures/pages | sed 's/^\- \[ \] //' | sort | uniq -d | wc -l   # expect 0
```

Expected: `8`, a total in 24–48, and `0` duplicates.

- [ ] **Step 4: Commit**

```bash
git add tests/functional/fixtures/pages
git commit -m "feat: add fixture corpus for functional test harness"
```

### Task 2: Container lifecycle (`harness.sh up|down|clean|status`)

**Files:**
- Create: `tests/functional/harness.sh` (mode 0755)

**Interfaces:**
- Consumes: Task 1 branch; `NC_IMAGE` env (default `docker.io/library/nextcloud:latest`).
- Produces: `tests/functional/harness.sh` with subcommands `up`, `down`, `clean`, `status`, and later `seed`, `smoke`. `up` leaves a running container `collectives-todos-functest` reachable at `http://127.0.0.1:8080`, admin/admin, with `collectives` and `collectives_todos` enabled. Tasks 3 and 4 are subcommands added to this same file.

- [ ] **Step 1: Write `up`**

Sequence: fail fast if the container/port is taken → `podman run -d --name collectives-todos-functest -p 127.0.0.1:8080:80 -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin "$NC_IMAGE"` → poll `occ --version` (60 s timeout) → `occ app:install collectives` → `podman cp "$APP_DIR" …/apps/collectives_todos` (exclude `.git`: copy to `/tmp` first and `rm -rf` the `.git` inside the container, same pattern verified on 2026-10-11) → `occ app:enable collectives_todos`. On enable failure, print the resolved `occ --version` and a hint that `latest` may exceed the app's supported range, then exit non-zero. Echo resolved Nextcloud/Collectives/app versions at the end.

- [ ] **Step 2: Write `down`, `clean`, `status`**

`down` = `podman rm -f collectives-todos-functest` (exit 0 if already gone). `clean` = `down` (no host state besides the container). `status` = prints container state, versions, enabled apps.

- [ ] **Step 3: Verify `up` end-to-end**

Run: `tests/functional/harness.sh up && tests/functional/harness.sh status`
Expected: exit 0; status lists `collectives` and `collectives_todos` as enabled; `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/index.php/login` prints `200`.

- [ ] **Step 4: Verify teardown idempotency**

Run: `tests/functional/harness.sh down && tests/functional/harness.sh down && tests/functional/harness.sh status`
Expected: both `down` calls exit 0; status reports no container; `up` works again from scratch.

- [ ] **Step 5: Commit**

```bash
git add tests/functional/harness.sh
git commit -m "feat: add container lifecycle to functional test harness"
```

### Task 3: Seeding (`harness.sh seed`)

**Files:**
- Modify: `tests/functional/harness.sh`

**Interfaces:**
- Consumes: running container from Task 2; fixture tree from Task 1 (directory name = parent page title, file stem = page title, file body = page content).
- Produces: collective "Aurora Product" containing the 8 fixture pages with fixture content, and a generated `Todos.md` in the collective root (Task 4 asserts on it).

- [ ] **Step 1: Pin the Collectives OCS endpoints from the installed app**

Run: `podman exec collectives-todos-functest cat /var/www/html/apps/collectives/appinfo/routes.php > /tmp/collectives-routes.php` and read it. Record the exact routes and parameters for *create collective* and *create page* (incl. the parent-page parameter name — it varies by Collectives version) in a comment block at the top of the seed function. Do not guess endpoints.

- [ ] **Step 2: Write `seed`**

Sequence: fail fast if not up → create collective "Aurora Product" via OCS (`curl -u admin:admin -H 'OCS-APIRequest: true' …`, assert HTTP 200) → for the two top-level pages, create page via OCS, then for each of its 3 subpages create page with the parent parameter → for all 8 pages, `PUT` the fixture content to `http://127.0.0.1:8080/remote.php/dav/files/admin/Collectives/Aurora Product/<title>.md` (content-type `text/markdown`, assert 201/204). WebDAV content writes fire the filesystem events the app listens to. Print the created page tree at the end.

- [ ] **Step 3: Verify seeding and Todos generation**

Run: `tests/functional/harness.sh seed` then fetch the Todos page:

```bash
curl -s -u admin:admin 'http://127.0.0.1:8080/remote.php/dav/files/admin/Collectives/Aurora Product/Todos.md'
```

Expected: seed exits 0; `Todos.md` exists (if it does not, re-run `occ collectives_todos:init` once via `podman exec` to distinguish a seeding bug from an event bug, and fix the cause — the app must not need the fallback).

- [ ] **Step 4: Verify re-runnability**

Run `tests/functional/harness.sh seed` a second time.
Expected: exits 0 (idempotent: page creates that already exist are handled — either skipped or overwritten via WebDAV PUT).

- [ ] **Step 5: Commit**

```bash
git add tests/functional/harness.sh
git commit -m "feat: seed Aurora Product collective in functional test harness"
```

### Task 4: Functional assertions (`harness.sh smoke`)

**Files:**
- Modify: `tests/functional/harness.sh`

**Interfaces:**
- Consumes: seeded instance from Task 3; fixture checkbox count from Task 1.
- Produces: `harness.sh smoke` — exits 0 only if aggregation and reverse sync are functionally correct; prints one `PASS:`/`FAIL:` line per assertion.

- [ ] **Step 1: Write aggregation assertions**

Fetch `Todos.md` via WebDAV into a temp file; assert:
1. it exists (HTTP 200);
2. every one of the 8 fixture page titles appears in it (8 sections);
3. the number of `- [ ]` lines equals the fixture corpus total (computed at runtime from Task 1's directory with the same `grep -rh` pipeline);
4. no `- [x]` lines exist yet.

- [ ] **Step 2: Write reverse-sync assertion**

Pick one checkbox text (hardcode a fixture text that is globally unique, e.g. "Approve the Q1 strategy deck for the board"). Rewrite `Todos.md` fetched in Step 1 with that one item flipped to `- [x]` (everything else byte-identical), `PUT` it back via WebDAV, then assert:
5. the source page (`Product Strategy.md`) now contains `- [x] Approve the Q1 strategy deck for the board`;
6. the regenerated `Todos.md` still lists the item as checked.

- [ ] **Step 3: Verify smoke passes, and fails when it should**

Run: `tests/functional/harness.sh smoke`
Expected: exit 0, all `PASS`. Then negative-control: temporarily break one expectation (e.g. assert a count of `TOTAL+1`), re-run, expect exit non-zero with a `FAIL:` line naming it, then revert.

- [ ] **Step 4: Commit**

```bash
git add tests/functional/harness.sh
git commit -m "feat: add aggregation and reverse-sync smoke checks to functional harness"
```

### Task 5: Docs and PR

**Files:**
- Modify: `README.md` (add a "Functional test harness" subsection near the existing manual deploy steps, ~10 lines: usage `tests/functional/harness.sh up && tests/functional/harness.sh seed && tests/functional/harness.sh smoke`, teardown `down`, and what it covers)

**Interfaces:**
- Consumes: finished harness from Tasks 1–4.
- Produces: branch `feat/functional-test-harness` ready for PR.

- [ ] **Step 1: Update README, run the full harness once from scratch**

Run: `tests/functional/harness.sh clean && tests/functional/harness.sh up && tests/functional/harness.sh seed && tests/functional/harness.sh smoke && tests/functional/harness.sh down`
Expected: all green end-to-end; README matches reality (commands copied from the script).

- [ ] **Step 2: Commit and push, open PR**

```bash
git add README.md docs/superpowers/plans/2026-10-11-functional-test-harness.md
git commit -m "docs: describe functional test harness"
git push -u origin feat/functional-test-harness
```

Open a PR to `main` (never merge locally, per AGENTS.md). Confirm the push with the user before running it.
