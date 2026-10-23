#!/bin/sh
# Install a throwaway WordPress with this plugin (its last commit, as it
# ships) and the latest PSPG from WordPress.org, then run db-test.php
# against it. CI runs this; it needs WP-CLI as `wp`, PHP with mysqli, git,
# and an empty database.
#
# Usage: sh tests/integration/run.sh <empty folder for WordPress>
# Environment: DB_HOST, DB_NAME, DB_USER, DB_PASS; WP_VERSION (default latest).
set -eu

repo=$(cd "$(dirname "$0")/../.." && pwd)
dir=$1
slug=donations-for-planetstudio-payment-gateway

# Unpacking WordPress needs more than PHP's usual 128M.
wp_bin=$(command -v wp)
wp() { php -d memory_limit=512M "$wp_bin" "$@"; }

mkdir -p "$dir"
wp core download --path="$dir" --version="${WP_VERSION:-latest}" --quiet
wp config create --path="$dir" --dbhost="$DB_HOST" --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --skip-check --quiet
# A random admin password nobody needs; never printed.
wp core install --path="$dir" --url=http://localhost --title=Test --admin_user=admin \
	--admin_password="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')" \
	--admin_email=admin@example.invalid --skip-email --quiet

# Local time ahead of UTC, as on the sites this plugin is built for, so
# local and UTC timestamps cannot be confused without a test noticing.
wp option update timezone_string Asia/Yerevan --path="$dir" --quiet

wp plugin install planetstudio-payment-gateway --activate --path="$dir" --quiet
git -C "$repo" archive --format=tar --prefix="$slug/" HEAD | tar -x -C "$dir/wp-content/plugins"
wp plugin activate "$slug" --path="$dir" --quiet
wp option update pspgd_test_mode 1 --path="$dir" --quiet

echo "WordPress $(wp core version --path="$dir"), PHP $(php -r 'echo PHP_VERSION;'), database $(wp eval 'global $wpdb; echo $wpdb->db_server_info();' --path="$dir")"
wp eval-file "$repo/tests/integration/db-test.php" --path="$dir"
