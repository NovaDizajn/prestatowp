<?php
/**
 * Runs migration batch: fetch from PrestaShop, map, create WooCommerce products.
 *
 * @package Presa_Migrate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Presa_Migrate_Runner
 */
class Presa_Migrate_Runner {

	/**
	 * Data source: Presa_Prestashop_Client (API) or Presa_Prestashop_Db.
	 *
	 * @var object
	 */
	private $source;

	/**
	 * @var Presa_Mapper
	 */
	private $mapper;

	/**
	 * Constructor.
	 *
	 * @param object|string $source_or_url Data source (get_product, get_category) or PrestaShop base URL for backward compatibility.
	 * @param string        $api_key       API key (when first arg is URL).
	 * @param string        $api_mode      Optional. 'api' or 'dispatcher'. Default 'api'.
	 */
	public function __construct( $source_or_url, $api_key = null, $api_mode = 'api' ) {
		if ( is_object( $source_or_url ) && method_exists( $source_or_url, 'get_product' ) ) {
			$this->source = $source_or_url;
		} else {
			$this->source = new Presa_Prestashop_Client( $source_or_url, $api_key ?: '', $api_mode );
		}
		$this->mapper = new Presa_Mapper( $this->source );
	}

	/**
	 * Run one batch of migration for the given product IDs (no list fetch).
	 *
	 * @param int[] $product_ids PrestaShop product IDs to migrate in this batch.
	 * @param array $args       Optional. 'update_existing' => true to update existing WC products (found by _presa_prestashop_id).
	 * @return array|WP_Error { migrated: array, total_processed: int, errors: string[] } or WP_Error.
	 */
	public function run_batch( array $product_ids, $args = array() ) {
		$product_ids     = array_filter( array_map( 'absint', $product_ids ) );
		$update_existing = ! empty( $args['update_existing'] );
		$migrated        = array();
		$errors          = array();
		$log             = array();
		$first_id        = ! empty( $product_ids ) ? reset( $product_ids ) : 0;
		if ( function_exists( 'presa_migrate_log' ) ) {
			presa_migrate_log( 'run_batch start: ids=' . implode( ',', $product_ids ) . ', update_existing=' . ( $update_existing ? '1' : '0' ) );
		}
		foreach ( $product_ids as $id ) {
			if ( ! $id ) {
				continue;
			}
			$full = $this->source->get_product( $id );
			if ( function_exists( 'presa_migrate_log' ) && is_array( $full ) && ! is_wp_error( $full ) ) {
				$log_lang_id = function_exists( 'get_option' ) ? max( 1, (int) get_option( 'presa_lang_id', 2 ) ) : 2;
				$log_name    = isset( $full['name'] ) ? $full['name'] : '';
				presa_migrate_log( sprintf( 'Product %s lang_id=%d name=%s', $id, $log_lang_id, json_encode( $log_name, JSON_UNESCAPED_UNICODE ) ) );
			}
			if ( is_wp_error( $full ) ) {
				$msg = sprintf( __( 'Proizvod #%d: %s', 'presa-migrate' ), $id, $full->get_error_message() );
				$errors[] = $msg;
				$log[]    = sprintf( __( 'Proizvod #%d: preskočen – %s', 'presa-migrate' ), $id, $full->get_error_message() );
				continue;
			}
			if ( empty( $full ) || ! is_array( $full ) ) {
				$msg = sprintf( __( 'Proizvod #%d: API/DB nije vratio podatke (naslov/cena).', 'presa-migrate' ), $id );
				$errors[] = $msg;
				$log[]    = sprintf( __( 'Proizvod #%d: preskočen – nema naslova/cene', 'presa-migrate' ), $id );
				continue;
			}
			// Debug: store first product as received (for "Šta je poslato mapper-u").
			if ( $id === $first_id ) {
				set_transient( 'presa_debug_last_raw', array(
					'id'     => $id,
					'keys'   => is_array( $full ) ? array_keys( $full ) : array(),
					'name'   => isset( $full['name'] ) ? ( is_string( $full['name'] ) ? $full['name'] : '[nije string]' ) : '[nema]',
					'sample' => is_array( $full ) ? array_intersect_key( $full, array_flip( array( 'id', 'name', 'description', 'price', 'reference' ) ) ) : array(),
				), 300 );
			}
			$full = $this->normalize_product( $full, $id );
			// If source can detect combinations and payload has none, load them so variable product is created.
			if ( method_exists( $this->source, 'has_combinations' ) && $this->source->has_combinations( $id ) ) {
				$combos = isset( $full['combinations'] ) && is_array( $full['combinations'] ) ? $full['combinations'] : array();
				if ( empty( $combos ) && method_exists( $this->source, 'get_combinations_for_product' ) ) {
					$full['combinations'] = $this->source->get_combinations_for_product( $id );
					if ( function_exists( 'presa_migrate_log' ) && ! empty( $full['combinations'] ) ) {
						presa_migrate_log( 'Product ' . $id . ': combinations loaded (' . count( $full['combinations'] ) . ') for variable product' );
					}
				}
			}
			if ( $id === $first_id ) {
				set_transient( 'presa_debug_last_normalized', array(
					'id'     => $id,
					'keys'   => array_keys( $full ),
					'name'   => isset( $full['name'] ) ? ( is_string( $full['name'] ) ? $full['name'] : '[nije string]' ) : '[nema]',
					'sample' => array_intersect_key( $full, array_flip( array( 'id', 'name', 'description', 'price', 'reference' ) ) ),
				), 300 );
			}
			$existing_wc_id = $this->find_existing_wc_product( $id, $full );
			if ( $existing_wc_id && ! $update_existing ) {
				$log[] = sprintf( __( 'Proizvod #%d: preskočen – već postoji (WC #%d)', 'presa-migrate' ), $id, $existing_wc_id );
				continue;
			}
			$image_ids = $this->upload_product_images( $full );
			$this->upload_combination_images( $full );
			$err       = new WP_Error();
			if ( $existing_wc_id && $update_existing ) {
				$wc_id = $this->mapper->create_or_update_wc_product( $full, $image_ids, $err, $existing_wc_id );
			} else {
				$wc_id = $this->mapper->create_wc_product( $full, $image_ids, $err );
				if ( ! is_wp_error( $wc_id ) ) {
					update_post_meta( (int) $wc_id, '_presa_prestashop_id', (string) $id );
				}
			}
			if ( is_wp_error( $wc_id ) ) {
				$err_msg = $wc_id->get_error_message();
				$errors[] = sprintf( __( 'Proizvod #%d: %s', 'presa-migrate' ), $id, $err_msg );
				$log[]    = sprintf( __( 'Proizvod #%d: preskočen – %s', 'presa-migrate' ), $id, $err_msg );
				continue;
			}
			$migrated[] = array( 'prestashop_id' => $id, 'woocommerce_id' => $wc_id );
			$log[]      = sprintf( __( 'Proizvod #%d: %s (WC #%d)', 'presa-migrate' ), $id, $existing_wc_id ? __( 'ažuriran', 'presa-migrate' ) : __( 'migriran', 'presa-migrate' ), $wc_id );
		}
		return array(
			'migrated'        => $migrated,
			'total_processed' => count( $product_ids ),
			'errors'          => $errors,
			'log'             => $log,
		);
	}

	/**
	 * Find existing WooCommerce product: first by _presa_prestashop_id, then by SKU (reference).
	 *
	 * @param int   $prestashop_id PrestaShop product ID.
	 * @param array $product      PrestaShop product (for reference/SKU fallback).
	 * @return int|null WC product ID or null if not found.
	 */
	public function find_existing_wc_product( $prestashop_id, $product = array() ) {
		$posts = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_presa_prestashop_id',
					'value' => (string) $prestashop_id,
				),
			),
		) );
		if ( ! empty( $posts ) ) {
			return (int) $posts[0];
		}
		$reference = isset( $product['reference'] ) ? $product['reference'] : '';
		if ( is_array( $reference ) ) {
			$reference = Presa_Mapper::get_first_lang( $reference );
		}
		$reference = trim( (string) $reference );
		if ( $reference !== '' && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$wc_id = wc_get_product_id_by_sku( $reference );
			if ( $wc_id ) {
				return (int) $wc_id;
			}
		}
		return null;
	}

	/**
	 * Normalize product array from API so mapper and image upload get consistent structure.
	 *
	 * @param array $product Raw product from API.
	 * @param int   $id      Product ID (fallback if missing in response).
	 * @return array
	 */
	private function normalize_product( $product, $id ) {
		if ( ! is_array( $product ) ) {
			$product = array();
		}
		$product['id'] = isset( $product['id'] ) ? $product['id'] : (string) $id;
		// PrestaShop JSON can wrap associations.images as .image array.
		if ( ! empty( $product['associations'] ) && is_array( $product['associations'] ) ) {
			$assoc = &$product['associations'];
			if ( isset( $assoc['images'] ) && is_array( $assoc['images'] ) ) {
				$imgs = $assoc['images'];
				if ( isset( $imgs['image'] ) && is_array( $imgs['image'] ) ) {
					$assoc['images'] = $imgs['image'];
				}
			}
			// Quantity sometimes in stock_availables (not on product root).
			if ( ( ! isset( $product['quantity'] ) || $product['quantity'] === '' ) && ! empty( $assoc['stock_availables'] ) ) {
				$stocks = $assoc['stock_availables'];
				$stock_list = isset( $stocks['stock_available'] ) ? $stocks['stock_available'] : $stocks;
				if ( ! is_array( $stock_list ) ) {
					$stock_list = array( $stock_list );
				}
				$first = reset( $stock_list );
				if ( is_array( $first ) && isset( $first['quantity'] ) ) {
					$product['quantity'] = $first['quantity'];
				} elseif ( isset( $stocks['quantity'] ) ) {
					$product['quantity'] = $stocks['quantity'];
				}
			}
		}
		return $product;
	}

	/**
	 * Upload product images from PrestaShop to media library.
	 * If product has image_urls (DB source), download from URLs. Else use source->download_product_image (API).
	 *
	 * @param array $product PrestaShop product (associations.images, id_default_image, or image_urls).
	 * @return int[] Attachment IDs (first = featured).
	 */
	private function upload_product_images( $product ) {
		$ids        = array();
		$product_id = isset( $product['id'] ) ? (int) $product['id'] : 0;
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		if ( ! empty( $product['image_urls'] ) && is_array( $product['image_urls'] ) ) {
			foreach ( $product['image_urls'] as $idx => $url ) {
				$url = esc_url_raw( $url );
				if ( ! $url ) {
					continue;
				}
				// Validate before download: status 200 and Content-Type image/*.
				$head = wp_remote_head( $url, array( 'timeout' => 15, 'redirection' => 3 ) );
				if ( is_wp_error( $head ) ) {
					if ( function_exists( 'presa_migrate_log' ) ) {
						presa_migrate_log( 'Slika skip (product ' . $product_id . ' idx ' . ( $idx + 1 ) . '): ' . $url . ' – ' . $head->get_error_message() );
					}
					continue;
				}
				$code = wp_remote_retrieve_response_code( $head );
				$ctype = wp_remote_retrieve_header( $head, 'content-type' );
				if ( $code !== 200 ) {
					if ( function_exists( 'presa_migrate_log' ) ) {
						presa_migrate_log( 'Slika skip (product ' . $product_id . ' idx ' . ( $idx + 1 ) . '): ' . $url . ' – HTTP ' . $code );
					}
					continue;
				}
				if ( ! $ctype || strpos( strtolower( $ctype ), 'image/' ) !== 0 ) {
					if ( function_exists( 'presa_migrate_log' ) ) {
						presa_migrate_log( 'Slika skip (product ' . $product_id . ' idx ' . ( $idx + 1 ) . '): ' . $url . ' – Content-Type: ' . ( $ctype ?: 'nema' ) );
					}
					continue;
				}
				$tmp = download_url( $url );
				if ( is_wp_error( $tmp ) ) {
					if ( function_exists( 'presa_migrate_log' ) ) {
						presa_migrate_log( 'Slika skip (product ' . $product_id . ' idx ' . ( $idx + 1 ) . '): ' . $url . ' – download: ' . $tmp->get_error_message() );
					}
					continue;
				}
				$ext = pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg';
				$file_array = array(
					'name'     => 'product-' . $product_id . '-' . ( $idx + 1 ) . '.' . $ext,
					'tmp_name' => $tmp,
				);
				$attach_id = media_handle_sideload( $file_array, 0 );
				if ( is_wp_error( $attach_id ) ) {
					@unlink( $tmp );
					continue;
				}
				update_post_meta( (int) $attach_id, '_presa_imported', '1' );
				$ids[] = $attach_id;
			}
			return $ids;
		}

		if ( ! $product_id ) {
			return $ids;
		}
		$image_ids = array();
		if ( ! empty( $product['id_default_image'] ) ) {
			$image_ids[] = (int) $product['id_default_image'];
		}
		if ( ! empty( $product['associations']['images'] ) && is_array( $product['associations']['images'] ) ) {
			foreach ( $product['associations']['images'] as $img ) {
				$iid = isset( $img['id'] ) ? (int) $img['id'] : 0;
				if ( $iid && ! in_array( $iid, $image_ids, true ) ) {
					$image_ids[] = $iid;
				}
			}
		}
		if ( ! empty( $product['id_default_image'] ) && count( $image_ids ) > 1 ) {
			$default    = (int) $product['id_default_image'];
			$image_ids  = array_unique( array_merge( array( $default ), $image_ids ) );
		}
		if ( ! empty( $image_ids ) && method_exists( $this->source, 'download_product_image' ) ) {
			foreach ( $image_ids as $image_id ) {
				$tmp = $this->source->download_product_image( $product_id, $image_id );
				if ( is_wp_error( $tmp ) ) {
					continue;
				}
				$file_array = array(
					'name'     => 'product-' . $product_id . '-' . $image_id . '.jpg',
					'tmp_name' => $tmp,
				);
				$attach_id = media_handle_sideload( $file_array, 0 );
				if ( is_wp_error( $attach_id ) ) {
					@unlink( $tmp );
					continue;
				}
				update_post_meta( (int) $attach_id, '_presa_imported', '1' );
				$ids[] = $attach_id;
			}
		}
		return $ids;
	}

	/**
	 * Download combination (variation) images from image_url and set variation_image_attachment_id on each combination. Modifies $product['combinations'] in place.
	 *
	 * @param array $product Product with 'combinations' (each may have image_url).
	 */
	private function upload_combination_images( &$product ) {
		if ( empty( $product['combinations'] ) || ! is_array( $product['combinations'] ) ) {
			return;
		}
		$product_id = isset( $product['id'] ) ? (int) $product['id'] : 0;
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		foreach ( $product['combinations'] as $idx => &$combo ) {
			$url = isset( $combo['image_url'] ) ? esc_url_raw( $combo['image_url'] ) : '';
			if ( ! $url ) {
				continue;
			}
			$head = wp_remote_head( $url, array( 'timeout' => 15, 'redirection' => 3 ) );
			if ( is_wp_error( $head ) ) {
				continue;
			}
			if ( wp_remote_retrieve_response_code( $head ) !== 200 ) {
				continue;
			}
			$ctype = wp_remote_retrieve_header( $head, 'content-type' );
			if ( ! $ctype || strpos( strtolower( $ctype ), 'image/' ) !== 0 ) {
				continue;
			}
			$tmp = download_url( $url );
			if ( is_wp_error( $tmp ) ) {
				continue;
			}
			$ext = pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg';
			$paid = isset( $combo['id_product_attribute'] ) ? (int) $combo['id_product_attribute'] : $idx;
			$file_array = array(
				'name'     => 'variation-' . $product_id . '-' . $paid . '.' . $ext,
				'tmp_name' => $tmp,
			);
			$attach_id = media_handle_sideload( $file_array, 0 );
			if ( is_wp_error( $attach_id ) ) {
				@unlink( $tmp );
				continue;
			}
			$combo['variation_image_attachment_id'] = (int) $attach_id;
		}
		unset( $combo );
	}
}
