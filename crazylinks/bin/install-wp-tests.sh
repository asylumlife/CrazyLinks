#!/usr/bin/env bash
#
# Installs WordPress and its PHPUnit test library for the integration tests, without svn or
# Docker. Needs WP-CLI, curl, tar and the mysql client.
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [target-dir]
#
#   db-name     Test database. The tests empty it, so never point this at a real site's database.
#   db-host     Default localhost. For a socket use "localhost:/path/to/mysql.sock".
#   wp-version  Default 7.1.4.
#   target-dir  Default $TMPDIR/crazylinks-tests (or /tmp/crazylinks-tests).
#
# Prints the WP_TESTS_DIR to export before running vendor/bin/phpunit.
#
set -euo pipefail

DB_NAME=${1:?db name}
DB_USER=${2:?db user}
DB_PASS=${3:?db pass}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-7.1.4}
TARGET=${6:-${TMPDIR:-/tmp}/crazylinks-tests}

WP_CORE_DIR="$TARGET/wordpress"
WP_TESTS_DIR="$TARGET/develop/tests/phpunit"
mkdir -p "$TARGET"

if [ ! -f "$WP_CORE_DIR/wp-settings.php" ]; then
	wp core download --path="$WP_CORE_DIR" --version="$WP_VERSION" --skip-content
	mkdir -p "$WP_CORE_DIR/wp-content/plugins" "$WP_CORE_DIR/wp-content/themes" "$WP_CORE_DIR/wp-content/uploads"
fi

if [ ! -f "$WP_TESTS_DIR/includes/bootstrap.php" ]; then
	curl -sL "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz" \
		| tar xz -C "$TARGET" \
			--include="wordpress-develop-${WP_VERSION}/tests/phpunit/includes/*" \
			--include="wordpress-develop-${WP_VERSION}/tests/phpunit/data/*" \
			--include="wordpress-develop-${WP_VERSION}/wp-tests-config-sample.php"
	rm -rf "$TARGET/develop"
	mv "$TARGET/wordpress-develop-${WP_VERSION}" "$TARGET/develop"
fi

sed -e "s#dirname( __FILE__ ) . '/src/'#'${WP_CORE_DIR}/'#" \
	-e "s/youremptytestdbnamehere/${DB_NAME}/" \
	-e "s/yourusernamehere/${DB_USER}/" \
	-e "s/yourpasswordhere/${DB_PASS}/" \
	-e "s#'localhost'#'${DB_HOST}'#" \
	"$TARGET/develop/wp-tests-config-sample.php" > "$TARGET/develop/wp-tests-config.php"

SOCKET=""
case "$DB_HOST" in *:/*) SOCKET="--socket=${DB_HOST#*:}";; esac
mysql -u"$DB_USER" -p"$DB_PASS" $SOCKET -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`" 2>/dev/null || true

echo "Done. Run tests with: WP_TESTS_DIR=$WP_TESTS_DIR vendor/bin/phpunit"
