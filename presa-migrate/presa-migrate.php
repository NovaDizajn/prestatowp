<?php
/**
 * Plugin Name: Presa Migrate — PrestaShop to WooCommerce
 * Description: Migrira proizvode sa PrestaShop-a na WooCommerce (naslov, opis, kategorije, slike, cena).
 * Version: 1.0.0
 * Author: Presa
 * Text Domain: presa-migrate
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRESA_MIGRATE_VERSION', '1.0.0' );
define( 'PRESA_MIGRATE_PATH', plugin_dir_path( __FILE__ ) );
define( 'PRESA_MIGRATE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Write a line to plugin debug log (always, for migration diagnostics).
 * Log file: wp-content/plugins/presa-migrate/debug.log (created on first write).
 *
 * @param string $message One line to append (no newline needed).
 */
function presa_migrate_log( $message ) {
	$log_file = null;
	if ( defined( 'PRESA_MIGRATE_PATH' ) && PRESA_MIGRATE_PATH ) {
		$log_file = PRESA_MIGRATE_PATH . 'debug.log';
	}
	if ( ! $log_file && defined( 'WP_CONTENT_DIR' ) && WP_CONTENT_DIR ) {
		$log_file = WP_CONTENT_DIR . '/presa-migrate-debug.log';
	}
	if ( ! $log_file ) {
		return;
	}
	$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . "\n";
	@file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
}

/**
 * Write to debug log only when PRESA_DEBUG is true (legacy).
 *
 * @param string $message One line to append.
 */
function presa_migrate_debug_log( $message ) {
	if ( defined( 'PRESA_DEBUG' ) && PRESA_DEBUG ) {
		presa_migrate_log( $message );
	}
}

/**
 * Log message only when WP_DEBUG or plugin debug mode is on (e.g. for brand assignment logs).
 *
 * @param string $message One line to append.
 */
function presa_migrate_brand_log( $message ) {
	if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'PRESA_DEBUG' ) && PRESA_DEBUG ) ) {
		presa_migrate_log( $message );
	}
}

/**
 * Check if WooCommerce is active.
 */
function presa_migrate_requires_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Presa Migrate zahteva WooCommerce. Instalirajte i aktivirajte WooCommerce.', 'presa-migrate' );
			echo '</p></div>';
		} );
		return false;
	}
	return true;
}

/**
 * Load plugin classes.
 */
function presa_migrate_autoload() {
	$includes = array(
		PRESA_MIGRATE_PATH . 'includes/class-presa-prestashop-client.php',
		PRESA_MIGRATE_PATH . 'includes/class-presa-prestashop-db.php',
		PRESA_MIGRATE_PATH . 'includes/class-presa-mapper.php',
		PRESA_MIGRATE_PATH . 'includes/class-presa-migrate-runner.php',
		PRESA_MIGRATE_PATH . 'admin/class-presa-admin.php',
	);
	foreach ( $includes as $file ) {
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

/** Minimum WooCommerce version for native product_brand (Brands) taxonomy. */
define( 'PRESA_MIGRATE_WC_MIN_VERSION_BRANDS', '9.6' );

/**
 * Register fallback brand taxonomy (presa_product_brand) when WooCommerce product_brand does not exist.
 * Called once on init; taxonomy is not changed during import.
 * Fallback: non-hierarchical, show_ui true, show_admin_column true, rewrite slug 'brand'.
 */
function presa_migrate_register_fallback_brand_taxonomy() {
	if ( taxonomy_exists( 'product_brand' ) || ! post_type_exists( 'product' ) ) {
		return;
	}
	if ( taxonomy_exists( 'presa_product_brand' ) ) {
		return;
	}
	$labels = array(
		'name'          => _x( 'Brendovi', 'taxonomy general name', 'presa-migrate' ),
		'singular_name' => _x( 'Brend', 'taxonomy singular name', 'presa-migrate' ),
		'menu_name'     => __( 'Brendovi', 'presa-migrate' ),
	);
	register_taxonomy( 'presa_product_brand', 'product', array(
		'labels'            => $labels,
		'hierarchical'      => false,
		'public'            => true,
		'show_ui'           => true,
		'show_admin_column' => true,
		'query_var'         => true,
		'rewrite'           => array( 'slug' => 'brand' ),
	) );
}

/**
 * Importuje jedan PrestaShop proizvod (sa varijacijama ako postoje) u WooCommerce.
 * Koristi ps_product_attribute, ps_product_attribute_combination, ps_attribute*, ps_product_attribute_shop, ps_stock_available, ps_product_attribute_image.
 * Ako proizvod ima redove u ps_product_attribute → kreira WC variable product + variations; inače simple.
 *
 * @param int   $prestashop_product_id PrestaShop id_product.
 * @param array $options               Opciono: 'update_existing' => true.
 * @return int|WP_Error WC product ID ili WP_Error.
 */
function presa_migrate_import_variable_product( $prestashop_product_id, $options = array() ) {
	if ( ! presa_migrate_requires_woocommerce() || ! class_exists( 'Presa_Prestashop_Db' ) || ! class_exists( 'Presa_Migrate_Runner' ) ) {
		return new WP_Error( 'presa_migrate_not_ready', __( 'Presa Migrate ili WooCommerce nisu spremni.', 'presa-migrate' ) );
	}
	$id = (int) $prestashop_product_id;
	if ( $id <= 0 ) {
		return new WP_Error( 'invalid_id', __( 'Neispravan id_product.', 'presa-migrate' ) );
	}
	$update_existing = ! empty( $options['update_existing'] );
	$host     = get_option( 'presa_db_host', '' );
	$user     = get_option( 'presa_db_user', '' );
	$password = get_option( 'presa_db_password', '' );
	$dbname   = get_option( 'presa_db_name', '' );
	$prefix   = get_option( 'presa_db_prefix', 'ps_' );
	$base_url = get_option( 'presa_prestashop_url', '' );
	$shop_id  = get_option( 'presa_db_shop_id', '' );
	if ( $host === '' || $user === '' || $dbname === '' ) {
		return new WP_Error( 'db_config', __( 'Konfigurišite PrestaShop bazu u podešavanjima.', 'presa-migrate' ) );
	}
	$db     = new Presa_Prestashop_Db( $host, $user, $password, $dbname, $prefix, $base_url, 'sr', $shop_id );
	$runner = new Presa_Migrate_Runner( $db );
	$result = $runner->run_batch( array( $id ), array( 'update_existing' => $update_existing ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( empty( $result['migrated'] ) ) {
		$err_msg = ! empty( $result['errors'] ) ? implode( '; ', $result['errors'] ) : __( 'Proizvod nije migriran.', 'presa-migrate' );
		return new WP_Error( 'import_failed', $err_msg );
	}
	return (int) $result['migrated'][0]['woocommerce_id'];
}

/**
 * Bootstrap admin only when WooCommerce is present.
 */
function presa_migrate_init() {
	if ( ! presa_migrate_requires_woocommerce() ) {
		return;
	}
	presa_migrate_autoload();
	add_action( 'init', 'presa_migrate_register_fallback_brand_taxonomy', 25 );
	if ( is_admin() ) {
		Presa_Admin::instance();
	}
}

add_action( 'plugins_loaded', 'presa_migrate_init' );
