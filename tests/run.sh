#!/bin/sh
# Run the collectives_todos PHPUnit tests inside the systemd-nextcloud container.
# Usage: tests/run.sh [phpunit args, repo-relative, e.g. tests/Unit]
set -e

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CONTAINER=systemd-nextcloud

if ! podman exec "$CONTAINER" test -f /tmp/phpunit.phar; then
	echo "phpunit.phar missing in container $CONTAINER."
	echo "Re-download it, e.g.: podman exec $CONTAINER sh -c \"curl -o /tmp/phpunit.phar https://phar.phpunit.de/phpunit.phar\""
	exit 1
fi

podman exec "$CONTAINER" rm -rf /tmp/collectives_todos_src
podman cp "$APP_DIR" "$CONTAINER":/tmp/collectives_todos_src >/dev/null

if [ "$#" -eq 0 ]; then
	set -- tests
fi

podman exec "$CONTAINER" sh -c \
	'cd /tmp/collectives_todos_src && exec php /tmp/phpunit.phar --bootstrap tests/phpunit-bootstrap.php "$@"' sh "$@"
