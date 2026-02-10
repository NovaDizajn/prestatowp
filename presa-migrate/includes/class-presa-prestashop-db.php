<?php
/**
 * PrestaShop MySQL database client for direct product import.
 *
 * @package Presa_Migrate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Presa_Prestashop_Db
 */
class Presa_Prestashop_Db {

	/**
	 * @var string
	 */
	private $host;

	/**
	 * @var string
	 */
	private $user;

	/**
	 * @var string
	 */
	private $password;

	/**
	 * @var string
	 */
	private $dbname;

	/**
	 * @var int DB port.
	 */
	private $port;

	/**
	 * @var string Table prefix (e.g. ps_).
	 */
	private $prefix;

	/**
	 * @var string Base URL for image paths (e.g. https://shop.example.com).
	 */
	private $base_url;

	/**
	 * @var string Language ISO code (e.g. sr, en) for *_lang joins.
	 */
	private $lang_iso;

	/**
	 * @var string Optional id_shop for multistore (empty = auto first active).
	 */
	private $shop_id;

	/**
	 * @var mysqli|null
	 */
	private $conn;

	/**
	 * Cached id_shop for filters (set after first get_shop_id() call).
	 *
	 * @var int|null
	 */
	private $shop_id_resolved = null;

	/**
	 * Constructor.
	 *
	 * @param string $host     DB host (optional; defaults from plugin options).
	 * @param string $user     DB user (optional; defaults from plugin options).
	 * @param string $password DB password (optional; defaults from plugin options).
	 * @param string $dbname   DB name (optional; defaults from plugin options).
	 * @param string $prefix   Table prefix (default ps_).
	 * @param string $base_url Base URL for image URLs.
	 * @param string $lang_iso Language ISO code from ps_lang (default sr).
	 * @param string $shop_id  Optional id_shop for multistore; empty = first active.
	 * @param int    $port     DB port (default 3306).
	 */
	public function __construct( $host = '', $user = '', $password = '', $dbname = '', $prefix = '', $base_url = '', $lang_iso = '', $shop_id = '', $port = 0 ) {
		$opt = function_exists( 'get_option' );
		$this->host     = $host !== '' ? $host : ( $opt ? (string) get_option( 'presa_db_host', '' ) : '' );
		$this->user     = $user !== '' ? $user : ( $opt ? (string) get_option( 'presa_db_user', '' ) : '' );
		if ( $password === '' && $opt ) {
			$password = (string) get_option( 'presa_db_pass', get_option( 'presa_db_password', '' ) );
		}
		$this->password = $password;
		$this->dbname   = $dbname !== '' ? $dbname : ( $opt ? (string) get_option( 'presa_db_name', '' ) : '' );
		$this->prefix   = $prefix !== '' ? $prefix : ( $opt ? (string) get_option( 'presa_db_prefix', 'ps_' ) : 'ps_' );
		if ( $base_url === '' && $opt ) {
			$base_url = (string) get_option( 'presa_source_url', get_option( 'presa_prestashop_url', '' ) );
		}
		$this->base_url = rtrim( $base_url, '/' );
		$this->lang_iso = $lang_iso !== '' ? $lang_iso : ( $opt ? (string) get_option( 'presa_db_lang_iso', 'sr' ) : 'sr' );
		$this->shop_id  = $shop_id !== '' ? $shop_id : ( $opt ? (string) get_option( 'presa_db_shop_id', '' ) : '' );
		if ( ! $port && $opt ) {
			$port = (int) get_option( 'presa_db_port', 3306 );
		}
		$this->port = $port > 0 ? (int) $port : 3306;
	}

	/**
	 * Get mysqli connection (opens if needed).
	 *
	 * @return mysqli|WP_Error
	 */
	private function get_connection() {
		if ( $this->conn instanceof mysqli ) {
			return $this->conn;
		}
		try {
			$this->conn = new mysqli( $this->host, $this->user, $this->password, $this->dbname, (int) $this->port );
		} catch ( Exception $e ) {
			return new WP_Error( 'db_connect', $e->getMessage() );
		}
		if ( $this->conn->connect_error ) {
			$msg = $this->conn->connect_error;
			$this->conn = null;
			return new WP_Error( 'db_connect', $msg );
		}
		$this->conn->set_charset( 'utf8mb4' );
		return $this->conn;
	}

	/**
	 * Escape table name (prefix + table).
	 *
	 * @param string $table Table name without prefix.
	 * @return string
	 */
	private function table( $table ) {
		return '`' . $this->prefix . $table . '`';
	}

	/**
	 * Test database connection.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$conn = $this->get_connection();
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		$t = $this->table( 'product' );
		$r = $conn->query( "SELECT id_product FROM {$t} LIMIT 1" );
		if ( $r === false ) {
			return new WP_Error( 'db_query', $conn->error ?: __( 'Tabela product nije pronađena. Proverite prefiks.', 'presa-migrate' ) );
		}
		return true;
	}

	/**
	 * Get id_lang used for *_lang tables. Reads from option presa_lang_id (default 2), min 1.
	 *
	 * @return int
	 */
	private function get_lang_id() {
		if ( ! function_exists( 'get_option' ) ) {
			return 2;
		}
		$id = (int) get_option( 'presa_lang_id', 2 );
		return $id >= 1 ? $id : 2;
	}

	/**
	 * Check if a table has a column.
	 *
	 * @param string $table Table name with prefix (e.g. from table()).
	 * @param string $col   Column name.
	 * @return bool
	 */
	private function has_column( $table, $col ) {
		$conn = $this->get_connection();
		if ( is_wp_error( $conn ) ) {
			return false;
		}
		$col_esc = $conn->real_escape_string( $col );
		$r       = $conn->query( "SHOW COLUMNS FROM {$table} LIKE '" . $col_esc . "'" );
		return $r && $r->num_rows > 0;
	}

	/**
	 * Get id_shop for multistore filters: setting or first active from ps_shop.
	 *
	 * @return int|null Null if no shop filter (table missing or no id_shop columns used).
	 */
	private function get_shop_id() {
		if ( $this->shop_id_resolved !== null ) {
			return $this->shop_id_resolved;
		}
		if ( $this->shop_id !== '' && is_numeric( $this->shop_id ) ) {
			$this->shop_id_resolved = (int) $this->shop_id;
			return $this->shop_id_resolved;
		}
		$conn = $this->get_connection();
		if ( is_wp_error( $conn ) ) {
			$this->shop_id_resolved = null;
			return null;
		}
		$t = $this->table( 'shop' );
		$r = $conn->query( "SELECT id_shop FROM {$t} WHERE active = 1 ORDER BY id_shop ASC LIMIT 1" );
		if ( $r && $row = $r->fetch_assoc() ) {
			$this->shop_id_resolved = (int) $row['id_shop'];
		} else {
			$this->shop_id_resolved = null;
		}
		return $this->shop_id_resolved;
	}

	/**
	 * Get SQL fragment to filter by id_shop for a table that has id_shop column (e.g. pl.id_shop = 1).
	 *
	 * @param string $table_name Table name without prefix (e.g. product_lang).
	 * @param string $alias      Table alias used in query (e.g. pl).
	 * @return string Empty or " AND alias.id_shop = N".
	 */
	private function get_shop_filter_sql( $table_name, $alias ) {
		$t = $this->table( $table_name );
		if ( ! $this->has_column( $t, 'id_shop' ) ) {
			return '';
		}
		$sid = $this->get_shop_id();
		if ( $sid === null ) {
			return '';
		}
		return ' AND ' . $alias . '.id_shop = ' . (int) $sid;
	}

	/**
	 * Get products list (ids and basic fields) for batch import.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return array|WP_Error { products: array of { id, name, reference, price }, has_more: bool } or WP_Error.
	 */
	public function get_products_list( $offset = 0, $limit = 10 ) {
		$conn = $this->get_connection();
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		$lang_id  = $this->get_lang_id();
		$p        = $this->table( 'product' );
		$pl       = $this->table( 'product_lang' );
		$shop_sql = $this->get_shop_filter_sql( 'product_lang', 'pl' );
		$offset   = max( 0, (int) $offset );
		$limit    = max( 1, min( 500, (int) $limit ) );
		$sql      = "SELECT p.id_product, pl.name, p.reference, p.price, p.active
			FROM {$p} p
			LEFT JOIN {$pl} pl ON pl.id_product = p.id_product AND pl.id_lang = " . (int) $lang_id . $shop_sql . "
			ORDER BY p.id_product ASC
			LIMIT " . ( $limit + 1 ) . " OFFSET " . $offset;
		$result = $conn->query( $sql );
		if ( $result === false ) {
			return new WP_Error( 'db_query', $conn->error );
		}
		$rows = array();
		while ( $row = $result->fetch_assoc() ) {
			$rows[] = $row;
		}
		$result->free();
		$has_more = count( $rows ) > $limit;
		$rows     = array_slice( $rows, 0, $limit );
		$products = array();
		foreach ( $rows as $row ) {
			$products[] = array(
				'id'        => (int) $row['id_product'],
				'name'      => $row['name'] ?: '',
				'reference' => $row['reference'] ?: '',
				'price'     => $row['price'] ?: '',
				'active'    => isset( $row['active'] ) ? $row['active'] : '1',
			);
		}
		return array(
			'products' => $products,
			'has_more' => $has_more,
		);
	}

	/**
	 * Build image URL for PrestaShop img/p path (id_image 123 -> img/p/1/2/3/123.jpg).
	 *
	 * @param int $id_image Image ID.
	 * @return string
	 */
	private function get_image_url( $id_image ) {
		$id_image = (int) $id_image;
		if ( $id_image <= 0 || ! $this->base_url ) {
			return '';
		}
		$str = (string) $id_image;
		$parts = str_split( $str );
		$path = implode( '/', $parts ) . '/' . $id_image . '.jpg';
		return $this->base_url . '/img/p/' . $path;
	}

	/**
	 * Get single product in mapper-compatible shape (name, description, price, reference, associations, image_urls).
	 *
	 * @param int $id Product ID.
	 * @return array|WP_Error Product array or WP_Error.
	 */
	public function get_product( $id ) {
		$conn = $this->get_connection();
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		$id       = (int) $id;
		$lang_id  = $this->get_lang_id();
		$p        = $this->table( 'product' );
		$pl       = $this->table( 'product_lang' );
		$shop_sql = $this->get_shop_filter_sql( 'product_lang', 'pl' );
		$fields   = array(
			'p.id_product',
			'p.reference',
			'p.price',
			'p.active',
			'p.id_category_default',
			'p.weight',
			'p.width',
			'p.height',
			'p.depth',
			'p.ean13',
			'p.upc',
			'p.isbn',
			'pl.name',
			'pl.description',
			'pl.description_short',
		);
		if ( $this->has_column( $p, 'id_manufacturer' ) ) {
			$fields[] = 'p.id_manufacturer';
		}
		if ( $this->has_column( $p, 'id_shop_default' ) ) {
			$fields[] = 'p.id_shop_default';
		}
		$sql = 'SELECT ' . implode( ', ', $fields ) . '
			FROM ' . $p . ' p
			LEFT JOIN ' . $pl . ' pl ON pl.id_product = p.id_product AND pl.id_lang = ' . (int) $lang_id . $shop_sql . '
			WHERE p.id_product = ' . $id;
		$result = $conn->query( $sql );
		if ( ! $result || $result->num_rows === 0 ) {
			return array();
		}
		$row = $result->fetch_assoc();
		$result->free();

		// If product_lang row for id_lang is missing (name/description empty), fallback to any available language row.
		$name_ok = isset( $row['name'] ) && trim( (string) $row['name'] ) !== '';
		if ( ! $name_ok ) {
			$fallback_sql = "SELECT pl.name, pl.description, pl.description_short FROM {$pl} pl WHERE pl.id_product = " . $id . $shop_sql . " ORDER BY pl.id_lang ASC LIMIT 1";
			$fallback = $conn->query( $fallback_sql );
			if ( $fallback && $fb = $fallback->fetch_assoc() ) {
				if ( function_exists( 'presa_migrate_log' ) ) {
					presa_migrate_log( sprintf( 'Product %d: product_lang row for id_lang=%d missing; fallback to first available language.', $id, $lang_id ) );
				}
				$row['name'] = isset( $fb['name'] ) ? $fb['name'] : '';
				$row['description'] = isset( $fb['description'] ) ? $fb['description'] : '';
				$row['description_short'] = isset( $fb['description_short'] ) ? $fb['description_short'] : '';
			}
			if ( $fallback ) {
				$fallback->free();
			}
		}

		// Quantity from ps_stock_available (simple product: id_product_attribute = 0; optional id_shop).
		$sa       = $this->table( 'stock_available' );
		$qty      = 0;
		$sa_shop  = '';
		if ( $this->has_column( $sa, 'id_shop' ) ) {
			$sid = $this->get_shop_id();
			if ( $sid !== null ) {
				$sa_shop = ' AND id_shop = ' . (int) $sid;
			}
		}
		$qr = $conn->query( "SELECT quantity FROM {$sa} WHERE id_product = " . $id . " AND id_product_attribute = 0" . $sa_shop . " LIMIT 1" );
		if ( $qr && $qrow = $qr->fetch_assoc() ) {
			$qty = (int) $qrow['quantity'];
		}
		if ( $qr ) {
			$qr->free();
		}

		// Categories from ps_category_product; include id_category_default if not already in list.
		$cp         = $this->table( 'category_product' );
		$cat_result = $conn->query( "SELECT id_category FROM {$cp} WHERE id_product = " . $id );
		$categories = array();
		$cat_ids    = array();
		if ( $cat_result ) {
			while ( $c = $cat_result->fetch_assoc() ) {
				$cid = (int) $c['id_category'];
				$categories[] = array( 'id' => $cid );
				$cat_ids[]    = $cid;
			}
			$cat_result->free();
		}
		$id_default_cat = isset( $row['id_category_default'] ) ? (int) $row['id_category_default'] : 0;
		if ( $id_default_cat > 0 && ! in_array( $id_default_cat, $cat_ids, true ) ) {
			$categories[] = array( 'id' => $id_default_cat );
		}

		// Images: ps_image (id_image, id_product, cover, position). Cover first, then by position.
		$img_t   = $this->table( 'image' );
		$img_r   = $conn->query( "SELECT id_image, cover, position FROM {$img_t} WHERE id_product = " . $id . " ORDER BY cover DESC, position ASC" );
		$images  = array();
		$urls    = array();
		if ( $img_r ) {
			while ( $im = $img_r->fetch_assoc() ) {
				$iid = (int) $im['id_image'];
				$images[] = array( 'id' => $iid );
				$url = $this->get_image_url( $iid );
				if ( $url ) {
					$urls[] = $url;
				}
			}
			$img_r->free();
		}

		$id_default_image = null;
		if ( ! empty( $images ) ) {
			$cover = $conn->query( "SELECT id_image FROM {$img_t} WHERE id_product = " . $id . " AND cover = 1 LIMIT 1" );
			if ( $cover && $cov = $cover->fetch_assoc() ) {
				$id_default_image = (int) $cov['id_image'];
			}
			if ( $cover ) {
				$cover->free();
			}
			if ( $id_default_image === null ) {
				$id_default_image = (int) $images[0]['id'];
			}
		}

		$shop_id_for_combinations = null;
		if ( $this->has_column( $p, 'id_shop_default' ) && isset( $row['id_shop_default'] ) && $row['id_shop_default'] !== '' && $row['id_shop_default'] !== null ) {
			$shop_id_for_combinations = (int) $row['id_shop_default'];
		}
		if ( $shop_id_for_combinations === null ) {
			$shop_id_for_combinations = $this->get_shop_id();
		}
		$combinations = $this->get_combinations( $conn, $id, $lang_id, $shop_id_for_combinations, isset( $row['reference'] ) ? $row['reference'] : '' );

		$id_manufacturer   = ( $this->has_column( $p, 'id_manufacturer' ) && isset( $row['id_manufacturer'] ) ) ? (int) $row['id_manufacturer'] : 0;
		$manufacturer_name = '';
		$manufacturer_logo_urls = array();
		if ( $id_manufacturer > 0 ) {
			$manufacturer_name = $this->get_manufacturer_name( $conn, $id_manufacturer, $lang_id );
			if ( function_exists( 'presa_migrate_log' ) ) {
				presa_migrate_log( sprintf( "Product %s manufacturer id=%d name='%s'.", $id, $id_manufacturer, $manufacturer_name ) );
			}
			if ( $this->base_url !== '' ) {
				$manufacturer_logo_urls[] = rtrim( $this->base_url, '/' ) . '/img/m/' . $id_manufacturer . '.jpg';
				$manufacturer_logo_urls[] = rtrim( $this->base_url, '/' ) . '/img/m/' . $id_manufacturer . '.png';
			}
		}

		$product = array(
			'id'                 => (string) $id,
			'name'               => $row['name'] ?: '',
			'description'        => $row['description'] ?: '',
			'description_short'  => $row['description_short'] ?: '',
			'price'              => $row['price'] !== '' && $row['price'] !== null ? $row['price'] : '',
			'reference'          => $row['reference'] ?: '',
			'active'             => isset( $row['active'] ) ? (string) $row['active'] : '1',
			'quantity'           => $qty,
			'weight'             => isset( $row['weight'] ) ? $row['weight'] : '',
			'width'              => isset( $row['width'] ) ? $row['width'] : '',
			'height'             => isset( $row['height'] ) ? $row['height'] : '',
			'depth'              => isset( $row['depth'] ) ? $row['depth'] : '',
			'ean13'              => isset( $row['ean13'] ) ? $row['ean13'] : '',
			'upc'                => isset( $row['upc'] ) ? $row['upc'] : '',
			'isbn'               => isset( $row['isbn'] ) ? $row['isbn'] : '',
			'id_manufacturer'    => $id_manufacturer,
			'manufacturer_name'  => $manufacturer_name,
			'manufacturer_logo_urls' => $manufacturer_logo_urls,
			'associations'       => array(
				'categories' => $categories,
				'images'     => $images,
			),
			'id_default_image'   => $id_default_image,
			'image_urls'         => $urls,
			'combinations'       => $combinations,
		);
		return $product;
	}

	/**
	 * Check if product has combinations (variations).
	 *
	 * @param int $id_product Product ID.
	 * @return bool
	 */
	public function has_combinations( $id_product ) {
		$conn = $this->get_connection();
		if ( is_wp_error( $conn ) ) {
			return false;
		}
		$pa  = $this->table( 'product_attribute' );
		if ( ! $this->table_exists( $pa ) ) {
			return false;
		}
		$sql = "SELECT 1 FROM {$pa} WHERE id_product = " . (int) $id_product . " LIMIT 1";
		$r   = $conn->query( $sql );
		$ok  = $r && $r->num_rows > 0;
		if ( $r ) {
			$r->free();
		}
		return $ok;
	}

	/**
	 * Load combinations for a product by ID (for runner when payload has none). Uses id_shop_default from ps_product.
	 *
	 * @param int $id_product Product ID.
	 * @return array Combination list (same structure as get_combinations).
	 */
	public function get_combinations_for_product( $id_product ) {
		$conn = $this->get_connection();
		if ( is_wp_error( $conn ) ) {
			return array();
		}
		$id_product = (int) $id_product;
		$lang_id    = $this->get_lang_id();
		$p          = $this->table( 'product' );
		$ref        = '';
		$shop_id    = $this->get_shop_id();
		if ( $this->has_column( $p, 'id_shop_default' ) ) {
			$row = $conn->query( "SELECT reference, id_shop_default FROM {$p} WHERE id_product = " . $id_product . " LIMIT 1" );
			if ( $row && $r = $row->fetch_assoc() ) {
				$ref     = isset( $r['reference'] ) ? trim( (string) $r['reference'] ) : '';
				$shop_id = isset( $r['id_shop_default'] ) && $r['id_shop_default'] !== '' && $r['id_shop_default'] !== null ? (int) $r['id_shop_default'] : $shop_id;
			}
			if ( $row ) {
				$row->free();
			}
		}
		return $this->get_combinations( $conn, $id_product, $lang_id, $shop_id, $ref );
	}

	/**
	 * Get product combinations for variable product. Each item: id_product_attribute, sku, price_impact, quantity, attributes (group_name, value_name), image_id, image_url.
	 * Uses id_shop_default (or get_shop_id) for product_attribute_shop and stock_available. MVP: single shop.
	 *
	 * @param mysqli $conn           DB connection.
	 * @param int    $id_product     Product ID.
	 * @param int    $lang_id        Language ID.
	 * @param int|null $shop_id      Shop ID (e.g. from ps_product.id_shop_default).
	 * @param string $product_reference Product reference (for sku fallback).
	 * @return array
	 */
	public function get_combinations( $conn, $id_product, $lang_id, $shop_id, $product_reference = '' ) {
		$id_product = (int) $id_product;
		$lang_id    = (int) $lang_id;
		$pa         = $this->table( 'product_attribute' );
		$pas        = $this->table( 'product_attribute_shop' );
		$pac        = $this->table( 'product_attribute_combination' );
		$a          = $this->table( 'attribute' );
		$al         = $this->table( 'attribute_lang' );
		$ag         = $this->table( 'attribute_group' );
		$agl        = $this->table( 'attribute_group_lang' );
		$sa         = $this->table( 'stock_available' );
		$pai        = $this->table( 'product_attribute_image' );

		foreach ( array( 'product_attribute', 'attribute', 'attribute_group' ) as $tbl ) {
			if ( ! $this->table_exists( $this->table( $tbl ) ) ) {
				return array();
			}
		}

		$sql = "SELECT pa.id_product_attribute, pa.reference, pa.price FROM {$pa} pa WHERE pa.id_product = " . $id_product;
		$res = $conn->query( $sql );
		if ( ! $res || $res->num_rows === 0 ) {
			if ( $res ) {
				$res->free();
			}
			return array();
		}

		$combos = array();
		while ( $r = $res->fetch_assoc() ) {
			$paid = (int) $r['id_product_attribute'];
			$sku  = trim( (string) ( $r['reference'] ?? '' ) );
			if ( $sku === '' ) {
				$sku = trim( $product_reference ) . '-' . $paid;
			}
			$price_impact = (float) ( $r['price'] ?? 0 );
			if ( $shop_id !== null && $shop_id > 0 && $this->table_exists( $pas ) ) {
				$pas_sql = "SELECT pas.price FROM {$pas} pas WHERE pas.id_product_attribute = " . $paid . " AND pas.id_shop = " . (int) $shop_id . " LIMIT 1";
				$pas_r   = $conn->query( $pas_sql );
				if ( $pas_r && $pas_row = $pas_r->fetch_assoc() && isset( $pas_row['price'] ) && $pas_row['price'] !== '' && $pas_row['price'] !== null ) {
					$price_impact = (float) $pas_row['price'];
				}
				if ( $pas_r ) {
					$pas_r->free();
				}
			}
			$combos[ $paid ] = array(
				'id_product_attribute' => $paid,
				'sku'                  => $sku,
				'price_impact'         => $price_impact,
				'quantity'             => 0,
				'attributes'           => array(),
				'image_id'             => null,
				'image_url'            => '',
			);
		}
		$res->free();

		$ids = array_keys( $combos );
		$sa_shop = ( $shop_id !== null && $shop_id > 0 && $this->has_column( $sa, 'id_shop' ) ) ? ' AND id_shop = ' . (int) $shop_id : '';
		foreach ( $ids as $paid ) {
			$q_sql = "SELECT quantity FROM {$sa} WHERE id_product = " . $id_product . " AND id_product_attribute = " . $paid . $sa_shop . " LIMIT 1";
			$q_r   = $conn->query( $q_sql );
			if ( $q_r && $q_row = $q_r->fetch_assoc() ) {
				$combos[ $paid ]['quantity'] = (int) $q_row['quantity'];
			}
			if ( $q_r ) {
				$q_r->free();
			}
		}

		foreach ( $ids as $paid ) {
			$attr_sql = "SELECT agl.name AS group_name, al.name AS value_name, ag.position AS gpos, a.position AS apos
				FROM {$pac} pac
				JOIN {$a} a ON a.id_attribute = pac.id_attribute
				JOIN {$al} al ON al.id_attribute = a.id_attribute AND al.id_lang = " . $lang_id . "
				JOIN {$ag} ag ON ag.id_attribute_group = a.id_attribute_group
				JOIN {$agl} agl ON agl.id_attribute_group = ag.id_attribute_group AND agl.id_lang = " . $lang_id . "
				WHERE pac.id_product_attribute = " . $paid . "
				ORDER BY gpos ASC, apos ASC";
			$attr_r = $conn->query( $attr_sql );
			if ( $attr_r ) {
				while ( $ar = $attr_r->fetch_assoc() ) {
					$combos[ $paid ]['attributes'][] = array(
						'group_name' => $ar['group_name'] ?: '',
						'value_name' => $ar['value_name'] ?: '',
					);
				}
				$attr_r->free();
			}
		}

		if ( $this->table_exists( $pai ) ) {
			foreach ( $ids as $paid ) {
				$img_sql = "SELECT id_image FROM {$pai} WHERE id_product_attribute = " . $paid . " LIMIT 1";
				$img_r   = $conn->query( $img_sql );
				if ( $img_r && $img_row = $img_r->fetch_assoc() && ! empty( $img_row['id_image'] ) ) {
					$combos[ $paid ]['image_id']  = (int) $img_row['id_image'];
					$combos[ $paid ]['image_url'] = $this->get_image_url( (int) $img_row['id_image'] );
				}
				if ( $img_r ) {
					$img_r->free();
				}
			}
		}

		return array_values( $combos );
	}

	/**
	 * Get product variations for a product (public API). Uses presa_lang_id option when $lang_id not provided.
	 * Returns shape: [ { id_product_attribute, sku, attributes: [ { group, value } ], price_impact, quantity, image_ids: [int...] }, ... ]
	 *
	 * @param int      $id_product Product ID.
	 * @param int|null $lang_id    Language ID for *_lang tables; null = get_option('presa_lang_id', 2).
	 * @return array
	 */
	public function get_product_variations( $id_product, $lang_id = null ) {
		if ( $lang_id === null ) {
			$lang_id = $this->get_lang_id();
		}
		$lang_id = (int) $lang_id;
		$conn = $this->get_connection();
		if ( is_wp_error( $conn ) ) {
			return array();
		}
		$id_product = (int) $id_product;
		$p = $this->table( 'product' );
		$ref = '';
		$shop_id = $this->get_shop_id();
		if ( $this->has_column( $p, 'id_shop_default' ) ) {
			$row = $conn->query( "SELECT reference, id_shop_default FROM {$p} WHERE id_product = " . $id_product . " LIMIT 1" );
			if ( $row && $r = $row->fetch_assoc() ) {
				$ref = isset( $r['reference'] ) ? trim( (string) $r['reference'] ) : '';
				$shop_id = isset( $r['id_shop_default'] ) && $r['id_shop_default'] !== '' && $r['id_shop_default'] !== null ? (int) $r['id_shop_default'] : $shop_id;
			}
			if ( $row ) {
				$row->free();
			}
		}
		$combos = $this->get_combinations( $conn, $id_product, $lang_id, $shop_id, $ref );
		$out = array();
		foreach ( $combos as $c ) {
			$attrs = array();
			if ( ! empty( $c['attributes'] ) && is_array( $c['attributes'] ) ) {
				foreach ( $c['attributes'] as $a ) {
					$attrs[] = array(
						'group' => isset( $a['group_name'] ) ? $a['group_name'] : ( isset( $a['group'] ) ? $a['group'] : '' ),
						'value' => isset( $a['value_name'] ) ? $a['value_name'] : ( isset( $a['value'] ) ? $a['value'] : '' ),
					);
				}
			}
			$image_ids = array();
			if ( ! empty( $c['image_id'] ) ) {
				$image_ids[] = (int) $c['image_id'];
			}
			$out[] = array(
				'id_product_attribute' => (int) $c['id_product_attribute'],
				'sku'                  => isset( $c['sku'] ) ? (string) $c['sku'] : '',
				'attributes'           => $attrs,
				'price_impact'         => isset( $c['price_impact'] ) ? (float) $c['price_impact'] : 0,
				'quantity'             => isset( $c['quantity'] ) ? (int) $c['quantity'] : 0,
				'image_ids'            => $image_ids,
			);
		}
		return $out;
	}

	/**
	 * Woo attribute taxonomy slug: pa_ + sanitize_title. (MVP helper.)
	 *
	 * @param string $group_name Attribute group name.
	 * @return string
	 */
	public function normalize_attr_slug( $group_name ) {
		$slug = sanitize_title( $group_name );
		$slug = $slug ?: 'option';
		return 'pa_' . $slug;
	}

	/**
	 * Normalize term slug for attribute value. (MVP helper.)
	 *
	 * @param string $value_name Attribute value name.
	 * @return string
	 */
	public function normalize_term_slug( $value_name ) {
		return sanitize_title( $value_name );
	}

	/**
	 * Sanitize attribute group name to slug (lowercase, hyphens). Used internally.
	 *
	 * @param string $name Group name.
	 * @return string
	 */
	private function sanitize_attr_slug( $name ) {
		$slug = sanitize_title( $name );
		return $slug ?: 'option';
	}

	/**
	 * Check if a table exists in the database.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function table_exists( $table ) {
		$conn = $this->get_connection();
		if ( is_wp_error( $conn ) ) {
			return false;
		}
		$r = $conn->query( "SHOW TABLES LIKE '" . $conn->real_escape_string( $table ) . "'" );
		$ok = $r && $r->num_rows > 0;
		if ( $r ) {
			$r->free();
		}
		return $ok;
	}

	/**
	 * Get manufacturer (brand) name by ID. First reads {prefix}manufacturer.name (no language); if empty, tries manufacturer_lang by id_lang.
	 *
	 * @param mysqli $conn    DB connection (same as get_product).
	 * @param int    $id_man  Manufacturer ID.
	 * @param int    $lang_id Language ID (for manufacturer_lang fallback).
	 * @return string
	 */
	private function get_manufacturer_name( $conn, $id_man, $lang_id ) {
		$id_man = (int) $id_man;
		$m      = $this->table( 'manufacturer' );
		$result = $conn->query( "SELECT name FROM {$m} WHERE id_manufacturer = " . $id_man . " LIMIT 1" );
		$row    = null;
		if ( $result ) {
			$row = $result->fetch_assoc();
			$result->free();
		}
		$name = ( $row && isset( $row['name'] ) ) ? trim( (string) $row['name'] ) : '';
		if ( $name === '' && $lang_id > 0 ) {
			$ml = $this->table( 'manufacturer_lang' );
			if ( $this->table_exists( $ml ) ) {
				$r2 = $conn->query( "SELECT name FROM {$ml} WHERE id_manufacturer = " . $id_man . " AND id_lang = " . (int) $lang_id . " LIMIT 1" );
				if ( $r2 && $r = $r2->fetch_assoc() && isset( $r['name'] ) && trim( (string) $r['name'] ) !== '' ) {
					$name = trim( (string) $r['name'] );
				}
				if ( $r2 ) {
					$r2->free();
				}
			}
		}
		return $name;
	}

	/**
	 * Get category by ID (mapper-compatible: name, link_rewrite, description, id_parent).
	 *
	 * @param int $id Category ID.
	 * @return array|null Category array or null.
	 */
	public function get_category( $id ) {
		$conn = $this->get_connection();
		if ( is_wp_error( $conn ) ) {
			return null;
		}
		$id       = (int) $id;
		$lang_id  = $this->get_lang_id();
		$c        = $this->table( 'category' );
		$cl       = $this->table( 'category_lang' );
		$shop_sql = $this->get_shop_filter_sql( 'category_lang', 'cl' );
		$sql      = "SELECT c.id_category, c.id_parent, cl.name, cl.link_rewrite, cl.description
			FROM {$c} c
			LEFT JOIN {$cl} cl ON cl.id_category = c.id_category AND cl.id_lang = " . (int) $lang_id . $shop_sql . "
			WHERE c.id_category = " . $id;
		$result = $conn->query( $sql );
		if ( ! $result || $result->num_rows === 0 ) {
			return null;
		}
		$row = $result->fetch_assoc();
		$result->free();
		return array(
			'id'           => (string) $row['id_category'],
			'id_parent'    => (string) $row['id_parent'],
			'name'         => $row['name'] ?: '',
			'link_rewrite' => $row['link_rewrite'] ?: '',
			'description'  => $row['description'] ?: '',
		);
	}
}
