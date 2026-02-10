<?php
/**
 * Admin UI for Presa Migrate.
 *
 * @package Presa_Migrate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Presa_Admin
 */
class Presa_Admin {

	/**
	 * @var Presa_Admin
	 */
	private static $instance = null;

	/**
	 * @return Presa_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		$this->register_ajax();
	}

	/**
	 * Register admin menu.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Presa Migrate', 'presa-migrate' ),
			__( 'Presa Migrate', 'presa-migrate' ),
			'manage_options',
			'presa-migrate',
			array( $this, 'render_page' ),
			'dashicons-migrate',
			56
		);
	}

	/**
	 * Register settings (options).
	 */
	public function register_settings() {
		register_setting(
			'presa_migrate_settings',
			'presa_prestashop_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $value ) {
					$value = esc_url_raw( trim( $value ) );
					return rtrim( $value, '/' );
				},
			)
		);
		register_setting(
			'presa_migrate_settings',
			'presa_prestashop_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		register_setting(
			'presa_migrate_settings',
			'presa_prestashop_api_mode',
			array(
				'type'              => 'string',
				'default'           => 'api',
				'sanitize_callback' => function ( $value ) {
					return in_array( $value, array( 'api', 'dispatcher' ), true ) ? $value : 'api';
				},
			)
		);
		// PrestaShop database (MySQL) for direct import.
		register_setting( 'presa_migrate_settings', 'presa_db_host', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'presa_migrate_settings', 'presa_db_user', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'presa_migrate_settings', 'presa_db_password', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'presa_migrate_settings', 'presa_db_name', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting(
			'presa_migrate_settings',
			'presa_db_prefix',
			array(
				'type'              => 'string',
				'default'           => 'ps_',
				'sanitize_callback' => function ( $value ) {
					$value = sanitize_text_field( $value );
					return $value !== '' ? $value : 'ps_';
				},
			)
		);
		register_setting(
			'presa_migrate_settings',
			'presa_db_lang_iso',
			array(
				'type'              => 'string',
				'default'           => 'sr',
				'sanitize_callback' => function ( $value ) {
					$value = sanitize_text_field( $value );
					return $value !== '' ? $value : 'sr';
				},
			)
		);
		register_setting(
			'presa_migrate_settings',
			'presa_lang_id',
			array(
				'type'              => 'integer',
				'default'           => 2,
				'sanitize_callback' => function ( $value ) {
					$value = absint( $value );
					return $value >= 1 ? $value : 2;
				},
			)
		);
		register_setting( 'presa_migrate_settings', 'presa_db_shop_id', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	/**
	 * Enqueue admin scripts/styles.
	 */
	public function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'presa-migrate' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'presa-migrate-admin',
			PRESA_MIGRATE_URL . 'admin/css/admin.css',
			array(),
			PRESA_MIGRATE_VERSION
		);
		wp_enqueue_script(
			'presa-migrate-admin',
			PRESA_MIGRATE_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			PRESA_MIGRATE_VERSION,
			true
		);
		wp_localize_script( 'presa-migrate-admin', 'presaMigrate', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'presa_migrate_nonce' ),
		) );
	}

	/**
	 * Register AJAX handlers.
	 */
	private function register_ajax() {
		add_action( 'wp_ajax_presa_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_presa_test_db_connection', array( $this, 'ajax_test_db_connection' ) );
		add_action( 'wp_ajax_presa_fetch_products_list', array( $this, 'ajax_fetch_products_list' ) );
		add_action( 'wp_ajax_presa_run_migration', array( $this, 'ajax_run_migration' ) );
		add_action( 'wp_ajax_presa_import_db_batch', array( $this, 'ajax_import_db_batch' ) );
		add_action( 'wp_ajax_presa_delete_imported', array( $this, 'ajax_delete_imported' ) );
		add_action( 'wp_ajax_presa_debug_product', array( $this, 'ajax_debug_product' ) );
		add_action( 'wp_ajax_presa_debug_last_batch', array( $this, 'ajax_debug_last_batch' ) );
	}

	/**
	 * AJAX: Test PrestaShop connection.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'presa_migrate_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemate dozvolu.', 'presa-migrate' ) ) );
		}
		$url  = get_option( 'presa_prestashop_url', '' );
		$key  = get_option( 'presa_prestashop_api_key', '' );
		$mode = get_option( 'presa_prestashop_api_mode', 'api' );
		if ( ! $url || ! $key ) {
			wp_send_json_error( array( 'message' => __( 'Unesite URL i API ključ.', 'presa-migrate' ) ) );
		}
		$client = new Presa_Prestashop_Client( $url, $key, $mode );
		$result = $client->test_connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Konekcija uspešna.', 'presa-migrate' ) ) );
	}

	/**
	 * AJAX: Test PrestaShop database connection.
	 */
	public function ajax_test_db_connection() {
		check_ajax_referer( 'presa_migrate_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemate dozvolu.', 'presa-migrate' ) ) );
		}
		$host     = get_option( 'presa_db_host', '' );
		$user     = get_option( 'presa_db_user', '' );
		$password = get_option( 'presa_db_password', '' );
		$name     = get_option( 'presa_db_name', '' );
		$prefix   = get_option( 'presa_db_prefix', 'ps_' );
		if ( ! $host || ! $user || ! $name ) {
			wp_send_json_error( array( 'message' => __( 'Unesite host, korisnika i ime baze.', 'presa-migrate' ) ) );
		}
		if ( ! class_exists( 'Presa_Prestashop_Db' ) ) {
			wp_send_json_error( array( 'message' => __( 'Klasa za bazu nije učitana.', 'presa-migrate' ) ) );
		}
		$base_url  = get_option( 'presa_prestashop_url', '' );
		$lang_iso  = get_option( 'presa_db_lang_iso', 'sr' );
		$shop_id   = get_option( 'presa_db_shop_id', '' );
		$db        = new Presa_Prestashop_Db( $host, $user, $password, $name, $prefix, $base_url, $lang_iso, $shop_id );
		$result    = $db->test_connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Konekcija na bazu uspešna.', 'presa-migrate' ) ) );
	}

	/**
	 * AJAX: Import products from PrestaShop database (one batch).
	 * POST offset + limit: import that page (e.g. offset=0, limit=50). Returns has_more for "Import all" loop.
	 * No offset/limit: import first 10 (test batch).
	 */
	public function ajax_import_db_batch() {
		check_ajax_referer( 'presa_migrate_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemate dozvolu.', 'presa-migrate' ) ) );
		}
		$host     = get_option( 'presa_db_host', '' );
		$user     = get_option( 'presa_db_user', '' );
		$password = get_option( 'presa_db_password', '' );
		$name     = get_option( 'presa_db_name', '' );
		$prefix   = get_option( 'presa_db_prefix', 'ps_' );
		$base_url = get_option( 'presa_prestashop_url', '' );
		if ( ! $host || ! $user || ! $name ) {
			wp_send_json_error( array( 'message' => __( 'Unesite DB host, korisnika i ime baze u Podešavanjima.', 'presa-migrate' ) ) );
		}
		if ( ! class_exists( 'Presa_Prestashop_Db' ) ) {
			wp_send_json_error( array( 'message' => __( 'Klasa za bazu nije učitana.', 'presa-migrate' ) ) );
		}
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : null;
		$limit  = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : null;
		$update_existing = ! empty( $_POST['update_existing'] );
		if ( $offset === null || $limit === null ) {
			$offset = 0;
			$limit  = 10;
		} else {
			$limit = min( 100, max( 1, $limit ) );
		}
		try {
			$lang_iso     = get_option( 'presa_db_lang_iso', 'sr' );
			$shop_id      = get_option( 'presa_db_shop_id', '' );
			$db           = new Presa_Prestashop_Db( $host, $user, $password, $name, $prefix, $base_url, $lang_iso, $shop_id );
			$list_result  = $db->get_products_list( $offset, $limit );
			if ( is_wp_error( $list_result ) ) {
				wp_send_json_error( array( 'message' => $list_result->get_error_message() ) );
			}
			$product_ids = array();
			if ( ! empty( $list_result['products'] ) && is_array( $list_result['products'] ) ) {
				foreach ( $list_result['products'] as $p ) {
					if ( isset( $p['id'] ) ) {
						$product_ids[] = (int) $p['id'];
					}
				}
			}
			$has_more = ! empty( $list_result['has_more'] );
			if ( empty( $product_ids ) ) {
				wp_send_json_success( array(
					'migrated'        => array(),
					'total_processed' => 0,
					'errors'          => array(),
					'log'             => array( __( 'Nema proizvoda u bazi za ovaj offset.', 'presa-migrate' ) ),
					'has_more'        => $has_more,
					'next_offset'     => $offset + $limit,
				) );
				return;
			}
			$runner = new Presa_Migrate_Runner( $db );
			$result = $runner->run_batch( $product_ids, array( 'update_existing' => $update_existing ) );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$result['has_more']    = $has_more;
			$result['next_offset'] = $offset + $limit;
			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Greška: ', 'presa-migrate' ) . $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Delete all products and media imported by this plugin (_presa_prestashop_id, _presa_imported).
	 */
	public function ajax_delete_imported() {
		check_ajax_referer( 'presa_migrate_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemate dozvolu.', 'presa-migrate' ) ) );
		}
		$product_ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_presa_prestashop_id',
					'compare' => 'EXISTS',
				),
			),
		) );
		$attachment_ids = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_presa_imported',
					'value'   => '1',
					'compare' => '=',
				),
			),
		) );
		$deleted_products   = 0;
		$deleted_attachments = 0;
		foreach ( $product_ids as $id ) {
			if ( wp_delete_post( (int) $id, true ) ) {
				$deleted_products++;
			}
		}
		foreach ( $attachment_ids as $id ) {
			if ( wp_delete_attachment( (int) $id, true ) ) {
				$deleted_attachments++;
			}
		}
		wp_send_json_success( array(
			'message'             => sprintf(
				/* translators: 1: number of products, 2: number of attachments */
				__( 'Obrisano: %1$d proizvoda, %2$d medija.', 'presa-migrate' ),
				$deleted_products,
				$deleted_attachments
			),
			'deleted_products'    => $deleted_products,
			'deleted_attachments' => $deleted_attachments,
		) );
	}

	/**
	 * AJAX: Fetch one page of products list (avoids 500 from memory/timeout). Client loops until has_more is false.
	 */
	public function ajax_fetch_products_list() {
		check_ajax_referer( 'presa_migrate_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemate dozvolu.', 'presa-migrate' ) ) );
		}
		$url = get_option( 'presa_prestashop_url', '' );
		$key = get_option( 'presa_prestashop_api_key', '' );
		if ( ! $url || ! $key ) {
			wp_send_json_error( array( 'message' => __( 'Unesite URL i API ključ u podešavanjima.', 'presa-migrate' ) ) );
		}
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$limit  = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 100;
		$limit  = min( 250, max( 1, $limit ) );
		try {
			$mode   = get_option( 'presa_prestashop_api_mode', 'api' );
			$client = new Presa_Prestashop_Client( $url, $key, $mode );
			$result = $client->get_products_list_page( $offset, $limit );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Greška servera: ', 'presa-migrate' ) . $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Run migration (batch) for given product IDs. List is already loaded in browser.
	 */
	public function ajax_run_migration() {
		if ( function_exists( 'presa_migrate_log' ) ) {
			presa_migrate_log( 'ajax_run_migration pozvan' );
		}
		check_ajax_referer( 'presa_migrate_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemate dozvolu.', 'presa-migrate' ) ) );
		}
		$product_ids = array();
		if ( ! empty( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ) {
			$product_ids = array_map( 'absint', $_POST['product_ids'] );
		} elseif ( ! empty( $_POST['product_ids'] ) ) {
			$product_ids = array_map( 'absint', explode( ',', sanitize_text_field( $_POST['product_ids'] ) ) );
		}
		$product_ids = array_filter( $product_ids );
		if ( empty( $product_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Nema ID-eva proizvoda. Prvo preuzmite listu proizvoda.', 'presa-migrate' ) ) );
		}
		$url = get_option( 'presa_prestashop_url', '' );
		$key = get_option( 'presa_prestashop_api_key', '' );
		if ( ! $url || ! $key ) {
			wp_send_json_error( array( 'message' => __( 'Unesite URL i API ključ.', 'presa-migrate' ) ) );
		}
		try {
			$mode   = get_option( 'presa_prestashop_api_mode', 'api' );
			$runner = new Presa_Migrate_Runner( $url, $key, $mode );
			$result = $runner->run_batch( $product_ids );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Greška servera: ', 'presa-migrate' ) . $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Debug — return raw API response for one product to inspect structure.
	 */
	public function ajax_debug_product() {
		check_ajax_referer( 'presa_migrate_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemate dozvolu.', 'presa-migrate' ) ) );
		}
		$id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 1;
		$url = get_option( 'presa_prestashop_url', '' );
		$key = get_option( 'presa_prestashop_api_key', '' );
		if ( ! $url || ! $key ) {
			wp_send_json_error( array( 'message' => __( 'Unesite URL i API ključ.', 'presa-migrate' ) ) );
		}
		$mode = get_option( 'presa_prestashop_api_mode', 'api' );
		$client = new Presa_Prestashop_Client( $url, $key, $mode );
		$raw = $client->get_product_raw( $id );
		if ( is_wp_error( $raw ) ) {
			wp_send_json_error( array( 'message' => $raw->get_error_message() ) );
		}
		wp_send_json_success( array( 'raw' => $raw, 'keys' => is_array( $raw ) ? array_keys( $raw ) : array() ) );
	}

	/**
	 * AJAX: Return last batch debug (what was sent to mapper for first product).
	 */
	public function ajax_debug_last_batch() {
		check_ajax_referer( 'presa_migrate_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemate dozvolu.', 'presa-migrate' ) ) );
		}
		$raw = get_transient( 'presa_debug_last_raw' );
		$normalized = get_transient( 'presa_debug_last_normalized' );
		$raw_source = get_transient( 'presa_debug_raw_source' );
		$request2 = get_transient( 'presa_debug_request2' );
		wp_send_json_success( array(
			'raw'         => $raw ?: null,
			'normalized'  => $normalized ?: null,
			'raw_source'  => $raw_source ?: null,
			'request2'    => $request2 ?: null,
		) );
	}

	/**
	 * Render main admin page (tabs: settings, products list).
	 */
	public function render_page() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		?>
		<div class="wrap presa-migrate-wrap">
			<h1><?php esc_html_e( 'Presa Migrate — PrestaShop u WooCommerce', 'presa-migrate' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=presa-migrate&tab=settings' ) ); ?>"
					class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Podešavanja', 'presa-migrate' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=presa-migrate&tab=products' ) ); ?>"
					class="nav-tab <?php echo $tab === 'products' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Lista proizvoda i migracija', 'presa-migrate' ); ?>
				</a>
			</nav>
			<?php
			if ( $tab === 'settings' ) {
				include PRESA_MIGRATE_PATH . 'admin/views/settings.php';
			} else {
				include PRESA_MIGRATE_PATH . 'admin/views/products-list.php';
			}
			?>
		</div>
		<?php
	}
}
