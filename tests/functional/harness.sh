#!/bin/sh
# Functional test harness for the collectives_todos app.
#
# Boots the latest Nextcloud (SQLite, admin/admin, onboarding skipped) plus the
# latest Collectives in a podman container, and deploys the local working tree
# of this app into it. Seeding and smoke checks are added by later subcommands.
#
# Usage: tests/functional/harness.sh up|down|clean|status|seed|smoke
set -e

CONTAINER=collectives-todos-functest
PORT=8080
BASE_URL="http://127.0.0.1:${PORT}"
# Pinned to the newest Nextcloud major supported by appinfo/info.xml (max 34);
# `nextcloud:latest` may be newer and the app refuses to enable there.
# Override with NC_IMAGE=... to test against a different version.
NC_IMAGE="${NC_IMAGE:-docker.io/library/nextcloud:34-apache}"

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
FIXTURES="$APP_DIR/tests/functional/fixtures/pages"
COLLECTIVE_NAME="Aurora Product"
DAV_FILES="$BASE_URL/remote.php/dav/files/admin"

# Collectives 4.7.1 OCS endpoints (pinned from its appinfo/routes.php):
#   POST $OCS_API/collectives                        param: name
#   GET  $OCS_API/collectives                        -> .ocs.data.collectives[]
#   POST $OCS_API/collectives/{id}/pages/{parentId}  param: title (parentId 0 is
#                                                    rejected; root pages are
#                                                    children of the landing page)
#   GET  $OCS_API/collectives/{id}/pages             -> .ocs.data.pages[]
# Collectives mounts the collective below the hidden '.Collectives' user folder
# (lib/Fs/UserFolderHelper.php). Fixture titles contain spaces only, so urlenc
# only needs to encode those.
OCS_API="$BASE_URL/ocs/v2.php/apps/collectives/api/v1.0"

occ() {
	podman exec "$CONTAINER" su -s /bin/sh www-data -c "php /var/www/html/occ $*"
}

container_exists() {
	podman ps -a --format '{{.Names}}' | grep -qx "$CONTAINER"
}

up() {
	if container_exists; then
		echo "Container $CONTAINER already exists. Run '$0 down' first." >&2
		exit 1
	fi
	if curl -s -o /dev/null "$BASE_URL" 2>/dev/null; then
		echo "Something is already listening on $BASE_URL." >&2
		exit 1
	fi

	echo "Starting Nextcloud from $NC_IMAGE (SQLite, admin/admin) ..."
	podman run -d --name "$CONTAINER" \
		-p "127.0.0.1:${PORT}:80" \
		-e NEXTCLOUD_ADMIN_USER=admin \
		-e NEXTCLOUD_ADMIN_PASSWORD=admin \
		"$NC_IMAGE" >/dev/null

	echo "Waiting for Apache to come up ..."
	i=0
	while [ "$i" -lt 60 ]; do
		if curl -s -o /dev/null "$BASE_URL" 2>/dev/null; then
			break
		fi
		i=$((i + 1))
		sleep 1
	done
	if [ "$i" -ge 60 ]; then
		echo "Timed out waiting for Apache. See: podman logs $CONTAINER" >&2
		exit 1
	fi

	echo "Installing Nextcloud (SQLite, admin/admin) ..."
	occ maintenance:install --database sqlite --admin-user admin --admin-pass admin

	echo "Installing Collectives from the app store ..."
	occ app:install collectives >/dev/null

	echo "Deploying the local working tree of collectives_todos ..."
	podman cp "$APP_DIR" "$CONTAINER":/tmp/collectives_todos_src >/dev/null
	podman exec "$CONTAINER" sh -c \
		'cp -r /tmp/collectives_todos_src /var/www/html/apps/collectives_todos && rm -rf /var/www/html/apps/collectives_todos/.git && chown -R www-data:www-data /var/www/html/apps/collectives_todos'

	if ! occ app:enable collectives_todos >/dev/null 2>&1; then
		echo "Failed to enable collectives_todos on Nextcloud $(occ --version 2>/dev/null)." >&2
		echo "The NC_IMAGE may be newer than the app's supported range (appinfo/info.xml)." >&2
		exit 1
	fi

	echo "Nextcloud:   $(occ --version)"
	echo "Collectives: $(occ app:list --output=plain | grep '^  - collectives:' | head -1 | tr -d ' -')"
	echo "App version: $(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$APP_DIR/appinfo/info.xml" | head -1)"
	echo "Up at $BASE_URL (admin/admin)"
}

down() {
	if container_exists; then
		podman rm -f "$CONTAINER" >/dev/null
		echo "Removed $CONTAINER."
	else
		echo "No container $CONTAINER — nothing to do."
	fi
}

clean() {
	down
}

urlenc() {
	printf '%s' "$1" | sed 's/ /%20/g'
}

ocs() {
	curl -s -u admin:admin -H 'OCS-APIRequest: true' -H 'Accept: application/json' "$@"
}

require_up() {
	if ! container_exists; then
		echo "Container $CONTAINER is not up. Run '$0 up' first." >&2
		exit 1
	fi
}

collective_id() {
	ocs "$OCS_API/collectives" | jq -r --arg name "$COLLECTIVE_NAME" \
		'.ocs.data.collectives[] | select(.name == $name) | .id' | head -1
}

page_id() { # $1: collective id, $2: exact title
	ocs "$OCS_API/collectives/$1/pages" | jq -r --arg title "$2" \
		'.ocs.data.pages[] | select(.title == $title) | .id' | head -1
}

create_page() { # $1: collective id, $2: parent page id, $3: title; echoes page id
	resp="$(mktemp)"
	code="$(ocs -o "$resp" -w '%{http_code}' -d "title=$3" "$OCS_API/collectives/$1/pages/$2")"
	if [ "$code" != "200" ]; then
		echo "Failed to create page '$3' under parent '$2' (HTTP $code)." >&2
		rm -f "$resp"
		return 1
	fi
	jq -r '.ocs.data.page.id' <"$resp"
	rm -f "$resp"
}

put_page() { # $1: WebDAV path, $2: local file
	code="$(curl -s -o /dev/null -w '%{http_code}' -u admin:admin \
		-X PUT -H 'Content-Type: text/markdown' --data-binary @"$2" "$(urlenc "$1")")"
	case "$code" in
	201 | 204) ;;
	*)
		echo "Failed to write $1 (HTTP $code)." >&2
		exit 1
		;;
	esac
}

seed() {
	require_up
	cid="$(collective_id)"
	if [ -z "$cid" ]; then
		echo "Creating collective '$COLLECTIVE_NAME' ..."
		code="$(ocs -o /dev/null -w '%{http_code}' -d "name=$COLLECTIVE_NAME" "$OCS_API/collectives")"
		if [ "$code" != "200" ]; then
			echo "Failed to create collective (HTTP $code)." >&2
			exit 1
		fi
		cid="$(collective_id)"
	fi
	[ -n "$cid" ] || {
		echo "Could not resolve the id of collective '$COLLECTIVE_NAME'." >&2
		exit 1
	}

	landing_id="$(ocs "$OCS_API/collectives/$cid/pages" |
		jq -r '.ocs.data.pages[] | select(.parentId == 0) | .id' | head -1)"
	[ -n "$landing_id" ] || {
		echo "Could not resolve the landing page of collective $cid." >&2
		exit 1
	}

	for top in "$FIXTURES"/*.md; do
		title="$(basename "$top" .md)"
		pid="$(page_id "$cid" "$title")"
		if [ -z "$pid" ]; then
			pid="$(create_page "$cid" "$landing_id" "$title")"
			[ -n "$pid" ] || {
				echo "Failed to create page '$title'." >&2
				exit 1
			}
		fi
		for sub in "$FIXTURES/$title"/*.md; do
			[ -e "$sub" ] || continue
			subtitle="$(basename "$sub" .md)"
			sid="$(page_id "$cid" "$subtitle")"
			if [ -z "$sid" ]; then
				sid="$(create_page "$cid" "$pid" "$subtitle")"
				[ -n "$sid" ] || {
					echo "Failed to create page '$subtitle'." >&2
					exit 1
				}
			fi
		done
	done

	for top in "$FIXTURES"/*.md; do
		title="$(basename "$top" .md)"
		# Creating the first subpage converts the parent page into a folder with
		# a Readme.md (Collectives PageService::initSubFolder), so the content
		# of a page with subpages lives at <title>/Readme.md.
		put_page "$DAV_FILES/.Collectives/$COLLECTIVE_NAME/$title/Readme.md" "$top"
		for sub in "$FIXTURES/$title"/*.md; do
			[ -e "$sub" ] || continue
			put_page "$DAV_FILES/.Collectives/$COLLECTIVE_NAME/$title/$(basename "$sub" .md).md" "$sub"
		done
	done

	echo "Seeded '$COLLECTIVE_NAME' (collective $cid):"
	ocs "$OCS_API/collectives/$cid/pages" | jq -r '.ocs.data.pages[].title' | sort | sed 's/^/  - /'
}

smoke() {
	require_up
	failures=0
	todos_path="$DAV_FILES/.Collectives/$COLLECTIVE_NAME/Todos.md"
	orig="$(mktemp)"
	work="$(mktemp)"

	code="$(curl -s -o "$orig" -w '%{http_code}' -u admin:admin "$(urlenc "$todos_path")")"
	if [ "$code" = "200" ]; then
		echo "PASS: Todos.md exists (HTTP $code)"
	else
		echo "FAIL: Todos.md missing (HTTP $code)"
		failures=$((failures + 1))
	fi

	for page in "$FIXTURES"/*.md "$FIXTURES"/*/*.md; do
		[ -e "$page" ] || continue
		title="$(basename "$page" .md)"
		if grep -qF "## [$title]" "$orig"; then
			echo "PASS: Todos.md has a section for '$title'"
		else
			echo "FAIL: Todos.md has no section for '$title'"
			failures=$((failures + 1))
		fi
	done

	expected="$(grep -rh '^- \[ \]' "$FIXTURES" | grep -c . || true)"
	actual="$(grep -c '^- \[ \]' "$orig" || true)"
	if [ "$actual" = "$expected" ]; then
		echo "PASS: Todos.md aggregates all $expected checkboxes"
	else
		echo "FAIL: Todos.md has $actual unchecked items, expected $expected"
		failures=$((failures + 1))
	fi

	if [ "$(grep -c '\[x\]' "$orig" || true)" = "0" ]; then
		echo "PASS: no checked items before reverse sync"
	else
		echo "FAIL: Todos.md already contains checked items"
		failures=$((failures + 1))
	fi

	# Reverse sync: check one item on the Todos page and expect it to be
	# checked on its source page afterwards. The task text is globally unique
	# in the fixture corpus (the app matches checkboxes by text).
	task="Approve the Q1 strategy deck for the board"
	cp "$orig" "$work"
	sed -i "s|^- \[ \] $task\$|- [x] $task|" "$work"
	code="$(curl -s -o /dev/null -w '%{http_code}' -u admin:admin \
		-X PUT -H 'Content-Type: text/markdown' --data-binary @"$work" \
		"$(urlenc "$todos_path")")"
	if [ "$code" = "201" ] || [ "$code" = "204" ]; then
		echo "PASS: Todos.md rewritten with one checked item (HTTP $code)"
	else
		echo "FAIL: rewriting Todos.md failed (HTTP $code)"
		failures=$((failures + 1))
	fi

	curl -s -u admin:admin \
		"$(urlenc "$DAV_FILES/.Collectives/$COLLECTIVE_NAME/Product Strategy/Readme.md")" >"$work"
	if grep -qF -- "- [x] $task" "$work"; then
		echo "PASS: reverse sync checked the task on the source page"
	else
		echo "FAIL: reverse sync did not check the task on the source page"
		failures=$((failures + 1))
	fi

	curl -s -u admin:admin "$(urlenc "$todos_path")" >"$work"
	if grep -qF -- "- [x] $task" "$work"; then
		echo "PASS: regenerated Todos.md keeps the task checked"
	else
		echo "FAIL: regenerated Todos.md lost the checked task"
		failures=$((failures + 1))
	fi

	# Restore the all-unchecked state so the smoke can be re-run
	# (the reverse sync just proven is what propagates it back).
	code="$(curl -s -o /dev/null -w '%{http_code}' -u admin:admin \
		-X PUT -H 'Content-Type: text/markdown' --data-binary @"$orig" \
		"$(urlenc "$todos_path")")"
	if [ "$code" = "201" ] || [ "$code" = "204" ]; then
		echo "PASS: restored the seeded state (HTTP $code)"
	else
		echo "FAIL: restoring the seeded state failed (HTTP $code)"
		failures=$((failures + 1))
	fi
	rm -f "$orig" "$work"

	if [ "$failures" -gt 0 ]; then
		echo "smoke: $failures check(s) FAILED"
		exit 1
	fi
	echo "smoke: all checks passed"
}

status() {
	if ! container_exists; then
		echo "No container $CONTAINER."
		exit 0
	fi
	echo "State: $(podman inspect -f '{{.State.Status}}' "$CONTAINER")"
	echo "Nextcloud: $(occ --version 2>/dev/null || echo unreachable)"
	occ app:list --output=plain 2>/dev/null | grep 'collectives' || true
	echo "Login page: HTTP $(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/index.php/login")"
}

case "${1:-}" in
up) up ;;
down) down ;;
clean) clean ;;
status) status ;;
seed) seed ;;
smoke) smoke ;;
*)
	echo "Usage: $0 up|down|clean|status|seed|smoke" >&2
	exit 1
	;;
esac
