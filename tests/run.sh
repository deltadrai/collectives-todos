#!/bin/sh
# Run the collectives_todos PHPUnit tests inside a podman container with a Nextcloud
# install. Default container: the one started by tests/functional/test-rig.sh;
# override with CONTAINER=... (phpunit.phar is downloaded into the container if
# missing). Usage: tests/run.sh [phpunit args, repo-relative, e.g. tests/Unit]
set -e

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CONTAINER="${CONTAINER:-collectives-todos-functest}"
PHPUNIT_VERSION=13.4.1
PHPUNIT_SHA256=96bd977c6755e8554d78cd4505acc813d1ae950f607e1fa3f282c01d36de9ba9

if ! podman exec "$CONTAINER" test -f /tmp/phpunit.phar; then
	echo "phpunit.phar missing in container $CONTAINER - downloading ${PHPUNIT_VERSION} ..."
	podman exec "$CONTAINER" sh -c \
		"curl -fsSL -o /tmp/phpunit.phar https://phar.phpunit.de/phpunit-${PHPUNIT_VERSION}.phar"
	echo -n "$PHPUNIT_SHA256  /tmp/phpunit.phar" |
		podman exec -i "$CONTAINER" sh -c 'sha256sum -c - >/dev/null' || {
		echo "phpunit.phar checksum mismatch - aborting." >&2
		podman exec "$CONTAINER" rm -f /tmp/phpunit.phar
		exit 1
	}
fi

podman exec "$CONTAINER" rm -rf /tmp/collectives_todos_src
podman cp "$APP_DIR" "$CONTAINER":/tmp/collectives_todos_src >/dev/null

if [ "$#" -eq 0 ]; then
	set -- tests
fi

podman exec "$CONTAINER" sh -c \
	'cd /tmp/collectives_todos_src && exec php /tmp/phpunit.phar --bootstrap tests/phpunit-bootstrap.php "$@"' sh "$@"
