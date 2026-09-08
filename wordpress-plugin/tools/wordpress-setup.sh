#!/usr/bin/env bash
#
# Stand up a throwaway WordPress + WooCommerce with the plugin activated.
#
# The plugin's own bugs live in the seams with WordPress — hook order, REST context, block themes —
# and none of them are visible from a unit test or from tools/preview.html, which stands in for the
# two store routes. This builds the real thing so wordpress-check.mjs can drive a real purchase.
#
# Runs on SQLite, so it needs no database server. Everything lands in one directory that can be
# deleted.
#
# Usage:
#   tools/wordpress-setup.sh --api https://api.example --key ak_… --secret sk_… [--dir DIR] [--port 8300]
#
# Then:
#   (cd DIR/wordpress && php -S 127.0.0.1:PORT &)
#   node tools/wordpress-check.mjs --event evt_…

set -euo pipefail

# Resolved before anything changes directory, since $0 may well be a relative path.
TOOLS_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PLUGIN_DIR="$( cd "$TOOLS_DIR/../seatmap-connect" && pwd )"

API=""
KEY=""
SECRET=""
DIR="${TMPDIR:-/tmp}/seatmap-wordpress"
PORT="8300"

while [ $# -gt 0 ]; do
	case "$1" in
		--api) API="$2"; shift 2 ;;
		--key) KEY="$2"; shift 2 ;;
		--secret) SECRET="$2"; shift 2 ;;
		--dir) DIR="$2"; shift 2 ;;
		--port) PORT="$2"; shift 2 ;;
		*) echo "Unknown option: $1" >&2; exit 2 ;;
	esac
done

if [ -z "$API" ] || [ -z "$KEY" ] || [ -z "$SECRET" ]; then
	echo "Usage: $0 --api URL --key ak_… --secret sk_… [--dir DIR] [--port PORT]" >&2
	exit 2
fi

mkdir -p "$DIR"
cd "$DIR"

if [ ! -d wordpress ]; then
	echo "Downloading WordPress…"
	curl -sSL -o wp.tar.gz https://wordpress.org/latest.tar.gz
	tar xzf wp.tar.gz
	rm wp.tar.gz
fi

if [ ! -d wordpress/wp-content/plugins/woocommerce ]; then
	echo "Downloading WooCommerce…"
	curl -sSL -o woocommerce.zip https://downloads.wordpress.org/plugin/woocommerce.zip
	unzip -q -o woocommerce.zip -d wordpress/wp-content/plugins/
	rm woocommerce.zip
fi

if [ ! -d wordpress/wp-content/plugins/sqlite-database-integration ]; then
	echo "Downloading the SQLite drop-in…"
	curl -sSL -o sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
	unzip -q -o sqlite.zip -d wordpress/wp-content/plugins/
	rm sqlite.zip
fi

# The drop-in is a copy, not a symlink, and carries the path to its own implementation.
SQLITE="$DIR/wordpress/wp-content/plugins/sqlite-database-integration"
cp "$SQLITE/db.copy" wordpress/wp-content/db.php
sed -i.bak "s|{SQLITE_IMPLEMENTATION_FOLDER_PATH}|$SQLITE|; s|{SQLITE_PLUGIN}|sqlite-database-integration/load.php|" \
	wordpress/wp-content/db.php
rm -f wordpress/wp-content/db.php.bak

# Symlinked rather than copied: the point is to exercise the working tree.
ln -sfn "$PLUGIN_DIR" wordpress/wp-content/plugins/seatmap-connect

cat > wordpress/wp-config.php <<PHP
<?php
/* Throwaway WordPress for verifying the plugin. Runs on SQLite; not fit for anything else. */
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'check-auth' );
define( 'SECURE_AUTH_KEY', 'check-secure' );
define( 'LOGGED_IN_KEY', 'check-login' );
define( 'NONCE_KEY', 'check-nonce' );
define( 'AUTH_SALT', 'check-auth-salt' );
define( 'SECURE_AUTH_SALT', 'check-secure-salt' );
define( 'LOGGED_IN_SALT', 'check-login-salt' );
define( 'NONCE_SALT', 'check-nonce-salt' );

\$table_prefix = 'wp_';

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', true );
define( 'WP_HOME', 'http://127.0.0.1:$PORT' );
define( 'WP_SITEURL', 'http://127.0.0.1:$PORT' );
define( 'FS_METHOD', 'direct' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
PHP

# Two PHP runs, not one: WooCommerce only defines WC() on `plugins_loaded`, so nothing in the
# process that activates it can use the API it provides.
echo "Installing…"
php <<'PHP'
<?php
define( 'WP_INSTALLING', true );
require getcwd() . '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
	wp_install( 'Seatmap check', 'admin', 'admin@example.test', true, '', 'password' );
}

wp_set_current_user( 1 );

foreach ( array( 'woocommerce/woocommerce.php', 'seatmap-connect/seatmap-connect.php' ) as $plugin ) {
	$result = activate_plugin( $plugin );

	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, $plugin . ': ' . $result->get_error_message() . "\n" );
		exit( 1 );
	}
}

echo "WordPress ", get_bloginfo( 'version' ), "\n";
PHP

echo "Configuring…"
SEATMAP_API="$API" SEATMAP_KEY="$KEY" SEATMAP_SECRET="$SECRET" php <<'PHP'
<?php
require getcwd() . '/wordpress/wp-load.php';
wp_set_current_user( 1 );

update_option( 'seatmap_api_url', getenv( 'SEATMAP_API' ) );
update_option( 'seatmap_api_key_id', getenv( 'SEATMAP_KEY' ) );
update_option( 'seatmap_api_secret', getenv( 'SEATMAP_SECRET' ) );

update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_default_country', 'DE:BE' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_ship_to_countries', 'disabled' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_coming_soon', 'no' );

// Cash on delivery: a gateway that needs no credentials, so the checkout can be completed.
update_option( 'woocommerce_cod_settings', array(
	'enabled'            => 'yes',
	'title'              => 'Pay at the box office',
	'description'        => 'Pay when you collect your tickets.',
	'enable_for_virtual' => 'yes',
) );

// WooCommerce takes over the whole admin on a fresh install; this is not what is being checked.
update_option( 'woocommerce_onboarding_profile', array( 'completed' => true, 'skipped' => true ) );
update_option( 'woocommerce_task_list_hidden', 'yes' );
delete_transient( '_wc_activation_redirect' );

$product = get_page_by_path( 'seat', OBJECT, 'product' );

if ( ! $product ) {
	$seat = new WC_Product_Simple();
	$seat->set_name( 'Seat' );
	$seat->set_slug( 'seat' );
	$seat->set_virtual( true );
	$seat->set_catalog_visibility( 'hidden' );
	$seat->set_regular_price( '0' );
	$seat->set_status( 'publish' );
	$product_id = $seat->save();
} else {
	$product_id = $product->ID;
}

update_option( 'seatmap_seat_product_id', $product_id );

echo "WooCommerce ", WC()->version, " | seat product ", $product_id, "\n";
echo "admin: ", admin_url(), " (admin / password)\n";
PHP

echo
echo "Ready. Start it with:"
echo "  (cd $DIR/wordpress && php -S 127.0.0.1:$PORT &)"
echo "Then point a page at an event:"
echo "  node $TOOLS_DIR/wordpress-check.mjs --event evt_… --url http://127.0.0.1:$PORT --dir $DIR"
