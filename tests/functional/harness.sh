#!/bin/sh
# Functional test harness for the collectives_todos app.
#
# Boots the latest Nextcloud (SQLite, admin/admin, onboarding skipped) plus the
# latest Collectives in a podman container, and deploys the local working tree
# of this app into it. Seeding and smoke checks are added by later subcommands.
#
# Usage: tests/functional/harness.sh up|down|clean|status
set -e

CONTAINER=collectives-todos-functest
PORT=8080
BASE_URL="http://127.0.0.1:${PORT}"
# Pinned to the newest Nextcloud major supported by appinfo/info.xml (max 34);
# `nextcloud:latest` may be newer and the app refuses to enable there.
# Override with NC_IMAGE=... to test against a different version.
NC_IMAGE="${NC_IMAGE:-docker.io/library/nextcloud:34-apache}"

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

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
*)
	echo "Usage: $0 up|down|clean|status" >&2
	exit 1
	;;
esac
