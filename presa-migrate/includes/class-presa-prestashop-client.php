<?php
/**
 * PrestaShop Web Service API client.
 *
 * @package Presa_Migrate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Presa_Prestashop_Client
 */
class Presa_Prestashop_Client {

	/**
	 * Base URL of PrestaShop (no trailing slash).
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * API key (Webservice key).
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * API mode: 'api' (default /api/) or 'dispatcher' (webservice/dispatcher.php).
	 *
	 * @var string
	 */
	private $api_mode;

	/**
	 * Constructor.
	 *
	 * @param string $base_url PrestaShop base URL.
	 * @param string $api_key  Webservice API key.
	 * @param string $api_mode Optional. 'api' or 'dispatcher'. Default 'api'.
	 */
	public function __construct( $base_url, $api_key, $api_mode = 'api' ) {
		$this->base_url = rtrim( $base_url, '/' );
		$this->api_key  = $api_key;
		$this->api_mode = in_array( $api_mode, array( 'api', 'dispatcher' ), true ) ? $api_mode : 'api';
	}

	/**
	 * Build API URL with auth and format.
	 *
	 * @param string $path   Path after /api/ e.g. 'products' or 'products/1'.
	 * @param array  $params Query params (output_format added automatically).
	 * @return string
	 */
	private function build_url( $path, $params = array() ) {
		$path = ltrim( $path, '/' );
		$params['ws_key']        = $this->api_key;
		$params['output_format'] = 'JSON';
		if ( $this->api_mode === 'dispatcher' ) {
			$params['url'] = $path;
			return add_query_arg( $params, $this->base_url . '/webservice/dispatcher.php' );
		}
		return add_query_arg( $params, $this->base_url . '/api/' . $path );
	}

	/**
	 * Perform GET request.
	 *
	 * @param string $url Full URL (ws_key should be in query string).
	 * @return array|WP_Error Decoded JSON or WP_Error.
	 */
	private function get( $url ) {
		$response = wp_remote_get( $url, array(
			'timeout'   => 30,
			'sslverify' => true,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code >= 400 ) {
			$message = sprintf(
				/* translators: 1: HTTP code, 2: response body snippet */
				__( 'API greška: %1$s. %2$s', 'presa-migrate' ),
				$code,
				wp_strip_all_tags( substr( $body, 0, 200 ) )
			);
			return new WP_Error( 'presa_api_error', $message );
		}
		$data = json_decode( $body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'presa_json_error', __( 'Neispravan JSON odgovor od API-ja.', 'presa-migrate' ) );
		}
		return $data;
	}

	/**
	 * Test connection (GET api or products limit 1).
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$url = $this->build_url( 'products', array( 'display' => 'full', 'limit' => '1' ) );
		$result = $this->get( $url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Get one page of products list (one API request). Use this to avoid timeout/memory 500.
	 *
	 * @param int $offset Offset for pagination.
	 * @param int $limit  Page size (default 100).
	 * @return array|WP_Error { 'products' => array[], 'has_more' => bool } or WP_Error.
	 */
	public function get_products_list_page( $offset = 0, $limit = 100 ) {
		$limit  = min( 250, max( 1, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$display = array( 'id', 'name', 'reference', 'price', 'active' );
		$display_str = '[' . implode( ',', $display ) . ']';
		// PrestaShop: limit = offset,count; sort id_ASC = redosled od najmanjeg ID ka najvećem.
		$limit_param = $offset . ',' . $limit;
		$url = $this->build_url( 'products', array(
			'display' => $display_str,
			'sort'    => '[id_ASC]',
			'limit'   => $limit_param,
		) );
		$data = $this->get( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		// Ako sort vrati prazno (dispatcher često ne podržava sort), probaj bez sorta.
		if ( $this->is_products_response_empty( $data ) ) {
			$url = $this->build_url( 'products', array(
				'display' => $display_str,
				'limit'   => $limit_param,
			) );
			$data = $this->get( $url );
		}
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$raw = isset( $data['products'] ) ? $data['products'] : array();
		if ( isset( $raw['product'] ) && is_array( $raw['product'] ) ) {
			$products = $raw['product'];
		} elseif ( is_array( $raw ) ) {
			$products = array_values( $raw );
		} else {
			$products = array();
		}
		$out = array();
		foreach ( $products as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$item = array(
				'id'        => isset( $p['id'] ) ? $p['id'] : '',
				'name'      => '',
				'reference' => isset( $p['reference'] ) ? $p['reference'] : '',
				'price'     => isset( $p['price'] ) ? $p['price'] : '',
				'active'    => isset( $p['active'] ) ? $p['active'] : '1',
			);
			if ( ! empty( $p['name'] ) && is_array( $p['name'] ) ) {
				$item['name'] = reset( $p['name'] );
				if ( is_array( $item['name'] ) && isset( $item['name']['value'] ) ) {
					$item['name'] = $item['name']['value'];
				}
			} elseif ( ! empty( $p['name'] ) ) {
				$item['name'] = $p['name'];
			}
			$out[] = $item;
		}
		return array(
			'products' => $out,
			'has_more' => count( $products ) === $limit,
		);
	}

	/**
	 * Get raw API response for one product (for debugging).
	 * Tries direct GET first; on 404 tries list endpoint with filter (dispatcher often only supports list).
	 *
	 * @param int $id Product ID.
	 * @return array|WP_Error Full decoded response or WP_Error.
	 */
	public function get_product_raw( $id ) {
		$url = $this->build_url( 'products/' . $id, array( 'display' => 'full' ) );
		$data = $this->get( $url );
		if ( ! is_wp_error( $data ) ) {
			return $data;
		}
		// 404 common with dispatcher for single resource; fallback: products list with filter[id]=[id].
		if ( $data->get_error_code() !== 'presa_api_error' ) {
			return $data;
		}
		$msg = $data->get_error_message();
		if ( strpos( $msg, '404' ) === false ) {
			return $data;
		}
		// PrestaShop API: filter[id]=[X] is literal match (docs). Try filter so we get exactly this id with display=full.
		$filter_val = '[' . (int) $id . ']';
		// Dispatcher: try full query inside url first (some setups only forward the url param).
		if ( $this->api_mode === 'dispatcher' ) {
			$path_with_filter = 'products?display=full&limit=0,1&filter[id]=' . rawurlencode( $filter_val );
			$dispatcher_url = add_query_arg( array(
				'url'           => $path_with_filter,
				'ws_key'        => $this->api_key,
				'output_format' => 'JSON',
			), $this->base_url . '/webservice/dispatcher.php' );
			$data = $this->get( $dispatcher_url );
			if ( ! is_wp_error( $data ) && ! $this->is_products_response_empty( $data ) ) {
				$this->set_debug_raw_source( $id, 'dispatcher_filter_url' );
				return $data;
			}
		}
		// Standard API or dispatcher with separate params (url=products, display=full, limit=0,1, filter[id]=[X]).
		$filter_url = $this->build_url( 'products', array(
			'display'     => 'full',
			'limit'       => '0,1',
			'filter[id]'  => $filter_val,
		) );
		$data = $this->get( $filter_url );
		if ( ! is_wp_error( $data ) && ! $this->is_products_response_empty( $data ) ) {
			$this->set_debug_raw_source( $id, $this->api_mode === 'dispatcher' ? 'dispatcher_filter_params' : 'filter' );
			return $data;
		}
		$this->set_debug_raw_source( $id, 'list_0_250' );
		// Fallback: first 250. Try with sort=id_ASC (1–250); if empty, dispatcher možda ne podržava sort — probaj bez sorta.
		$list_url = $this->build_url( 'products', array(
			'display' => 'full',
			'sort'    => '[id_ASC]',
			'limit'   => '0,250',
		) );
		$data = $this->get( $list_url );
		if ( ! is_wp_error( $data ) && ! $this->is_products_response_empty( $data ) ) {
			return $data;
		}
		$list_url_no_sort = $this->build_url( 'products', array(
			'display' => 'full',
			'limit'   => '0,250',
		) );
		return $this->get( $list_url_no_sort );
	}

	/**
	 * Store debug: which get_product_raw path was used (for admin + optional error_log).
	 *
	 * @param int    $id     Product ID.
	 * @param string $source 'filter' | 'dispatcher_filter_url' | 'dispatcher_filter_params' | 'list_0_250'.
	 */
	private function set_debug_raw_source( $id, $source ) {
		$payload = array( 'id' => $id, 'source' => $source );
		set_transient( 'presa_debug_raw_source', $payload, 300 );
		if ( function_exists( 'presa_migrate_log' ) ) {
			presa_migrate_log( 'get_product_raw: ID ' . $id . ' -> ' . $source );
		}
	}

	/**
	 * Store debug: raw response of second request (sort+limit) for admin diagnostics.
	 *
	 * @param int   $id          Product ID.
	 * @param array $single_data Decoded response of the sort+limit request.
	 */
	private function set_debug_request2( $id, $single_data ) {
		$top_keys = is_array( $single_data ) ? array_keys( $single_data ) : array();
		$products = isset( $single_data['products'] ) ? $single_data['products'] : null;
		$first_has_name = false;
		$sample = array();
		if ( is_array( $products ) ) {
			$list = isset( $products['product'] ) ? $products['product'] : $products;
			$first = null;
			if ( is_array( $list ) && isset( $list['id'] ) && ! array_key_exists( 0, $list ) ) {
				$first = $list;
			} elseif ( is_array( $list ) && array_key_exists( 0, $list ) ) {
				$first = $list[0];
			} elseif ( is_array( $list ) ) {
				$first = reset( $list );
			}
			if ( is_array( $first ) ) {
				$first_has_name = $this->extract_first_lang_value( $first['name'] ?? '' ) !== '';
				$sample = array_intersect_key( $first, array_flip( array( 'id', 'name', 'description', 'price' ) ) );
			}
		}
		$payload = array(
			'id'             => $id,
			'top_level_keys' => $top_keys,
			'first_has_name' => $first_has_name,
			'sample'         => $sample,
		);
		set_transient( 'presa_debug_request2', $payload, 300 );
		if ( function_exists( 'presa_migrate_log' ) ) {
			presa_migrate_log( 'Zahtev 2 (sort+limit): ID ' . $id . ', first_has_name=' . ( $first_has_name ? 'da' : 'ne' ) . ', keys=' . implode( ',', $top_keys ) );
		}
	}

	/**
	 * Extract first language value from PrestaShop multilingual field (name, description, etc.).
	 * Handles: string, array with 'language' key, value/#/content.
	 *
	 * @param mixed $field Raw field from API.
	 * @return string
	 */
	private function extract_first_lang_value( $field ) {
		if ( is_scalar( $field ) ) {
			return is_string( $field ) ? trim( $field ) : (string) $field;
		}
		if ( ! is_array( $field ) ) {
			return '';
		}
		if ( isset( $field['language'] ) && is_array( $field['language'] ) ) {
			$field = $field['language'];
		}
		foreach ( array( 'value', '#', '__value', 'content', '$' ) as $key ) {
			if ( isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) ) {
				return trim( (string) $field[ $key ] );
			}
		}
		$first = reset( $field );
		if ( $first === false ) {
			return '';
		}
		if ( is_scalar( $first ) ) {
			return trim( (string) $first );
		}
		if ( is_array( $first ) ) {
			foreach ( array( 'value', '#', '__value', 'content', '$' ) as $key ) {
				if ( isset( $first[ $key ] ) && is_scalar( $first[ $key ] ) ) {
					return trim( (string) $first[ $key ] );
				}
			}
		}
		return '';
	}

	/**
	 * Check if products API response has no usable product entries (empty or missing products).
	 *
	 * @param array $data Decoded response.
	 * @return bool True if empty.
	 */
	private function is_products_response_empty( $data ) {
		$items = $this->parse_products_to_list( is_array( $data ) ? $data : array() );
		return empty( $items );
	}

	/**
	 * Parse API response into a list of product arrays.
	 * Handles: products as array [{},{}], products.product as single object or array, numeric string keys ("0","1").
	 *
	 * @param array $data Decoded response with 'products' key.
	 * @return array List of product arrays.
	 */
	private function parse_products_to_list( $data ) {
		$products = isset( $data['products'] ) ? $data['products'] : null;
		if ( ( empty( $products ) || ! is_array( $products ) ) && ! empty( $data['prestashop']['products'] ) && is_array( $data['prestashop']['products'] ) ) {
			$products = $data['prestashop']['products'];
		}
		if ( empty( $products ) || ! is_array( $products ) ) {
			return array();
		}
		$raw = $products;
		$list = isset( $raw['product'] ) ? $raw['product'] : $raw;
		if ( ! is_array( $list ) ) {
			return array();
		}
		// Single product object (e.g. filter result): has 'id', no numeric key 0.
		if ( isset( $list['id'] ) && ! array_key_exists( 0, $list ) ) {
			return array( $list );
		}
		// List (array or object with numeric keys / string "0","1"...).
		return array_values( $list );
	}

	/**
	 * Find product by id in a list of product arrays.
	 *
	 * @param array  $items  List of product arrays.
	 * @param string $id_str Product ID as string.
	 * @return array|null Product array or null.
	 */
	/**
	 * Get scalar ID from product (API ponekad vraća id kao objekat: value/#/@attributes).
	 *
	 * @param array $product Product array.
	 * @return string
	 */
	private function get_product_id_scalar( $product ) {
		if ( ! is_array( $product ) || ! isset( $product['id'] ) ) {
			return '';
		}
		$id = $product['id'];
		if ( is_scalar( $id ) ) {
			return (string) $id;
		}
		if ( is_array( $id ) ) {
			foreach ( array( 'value', '#', '__value', 'content', '$' ) as $key ) {
				if ( isset( $id[ $key ] ) && is_scalar( $id[ $key ] ) ) {
					return (string) $id[ $key ];
				}
			}
			$first = reset( $id );
			if ( is_scalar( $first ) ) {
				return (string) $first;
			}
		}
		return '';
	}

	/**
	 * Find product by id in a list of product arrays.
	 *
	 * @param array  $items  List of product arrays.
	 * @param string $id_str Product ID as string.
	 * @return array|null Product array or null.
	 */
	private function find_product_by_id_in_list( array $items, $id_str ) {
		foreach ( $items as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$pid = $this->get_product_id_scalar( $p );
			if ( $pid !== '' && $pid === $id_str ) {
				return (array) $p;
			}
		}
		return null;
	}

	/**
	 * Fetch one page of products with display=full.
	 *
	 * @param int  $offset Offset (0, 250, 500, ...).
	 * @param int  $limit  Page size (default 250).
	 * @param bool $use_sort If true, add sort=[id_ASC] so page N contains ids N*250+1..(N+1)*250. Dispatcher may ignore or break on sort.
	 * @return array|WP_Error Decoded response or WP_Error.
	 */
	private function fetch_products_page( $offset, $limit = 250, $use_sort = true ) {
		$params = array(
			'display' => 'full',
			'limit'   => $offset . ',' . $limit,
		);
		if ( $use_sort ) {
			$params['sort'] = '[id_ASC]';
		}
		$url = $this->build_url( 'products', $params );
		$data = $this->get( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		// Ako sort vrati prazno (dispatcher često ne podržava sort), probaj bez sorta.
		if ( $use_sort && $this->is_products_response_empty( $data ) ) {
			$url_no_sort = $this->build_url( 'products', array(
				'display' => 'full',
				'limit'   => $offset . ',' . $limit,
			) );
			return $this->get( $url_no_sort );
		}
		return $data;
	}

	/**
	 * Get single product by ID (full display). Normalizes various API response wrappers.
	 * Uses list+filter when direct GET returns 404 (e.g. dispatcher mode).
	 *
	 * @param int $id Product ID.
	 * @return array|WP_Error
	 */
	public function get_product( $id ) {
		$data = $this->get_product_raw( $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$product = array();
		$id_str = (string) $id;
		// Dispatcher/API često vraća { "prestashop": { "products": { "product": [...] } } } — nema data['products'].
		if ( empty( $data['products'] ) && ! empty( $data['prestashop']['products'] ) && is_array( $data['prestashop']['products'] ) ) {
			$data['products'] = $data['prestashop']['products'];
			if ( function_exists( 'presa_migrate_log' ) ) {
				presa_migrate_log( 'get_product(' . $id . '): odgovor u prestashop.products, unwrap-ovan' );
			}
		}
		if ( empty( $data['product'] ) && ! empty( $data['prestashop']['product'] ) && is_array( $data['prestashop']['product'] ) ) {
			$data['product'] = $data['prestashop']['product'];
		}
		// PrestaShop wrappers: product, prestashop.product, products (list), or root is the product.
		if ( ! empty( $data['prestashop']['product'] ) && is_array( $data['prestashop']['product'] ) ) {
			$product = $data['prestashop']['product'];
		} elseif ( ! empty( $data['product'] ) && is_array( $data['product'] ) ) {
			$product = $data['product'];
		} elseif ( ! empty( $data['products'] ) && is_array( $data['products'] ) ) {
			$items = $this->parse_products_to_list( $data );
			$product = $this->find_product_by_id_in_list( $items, $id_str );
			$product = is_array( $product ) ? $product : array();

			// If not found in first page: try sort+limit (one request), then pagination by pages if result is incomplete.
			if ( empty( $product ) && (int) $id > 0 ) {
				if ( function_exists( 'presa_migrate_log' ) ) {
					presa_migrate_log( 'get_product(' . $id . '): nije u prvih 250, šaljem Zahtev 2 (sort+limit)' );
				}
				$single_url = $this->build_url( 'products', array(
					'display' => 'full',
					'sort'    => '[id_ASC]',
					'limit'   => ( (int) $id - 1 ) . ',1',
				) );
				$single_data = $this->get( $single_url );
				if ( is_array( $single_data ) && empty( $single_data['products'] ) && ! empty( $single_data['prestashop']['products'] ) ) {
					$single_data['products'] = $single_data['prestashop']['products'];
				}
				$this->set_debug_request2( $id, is_array( $single_data ) ? $single_data : array() );
				if ( ! is_wp_error( $single_data ) && ! $this->is_products_response_empty( $single_data ) ) {
					$single_list = isset( $single_data['products']['product'] ) ? $single_data['products']['product'] : $single_data['products'];
					$first = null;
					if ( is_array( $single_list ) && isset( $single_list['id'] ) && ! array_key_exists( 0, $single_list ) ) {
						$first = $single_list;
					} elseif ( is_array( $single_list ) && array_key_exists( 0, $single_list ) ) {
						$first = $single_list[0];
					} elseif ( is_array( $single_list ) ) {
						$first = reset( $single_list );
					}
					if ( is_array( $first ) && $this->get_product_id_scalar( $first ) === $id_str ) {
						$product = (array) $first;
					}
				}

				// If sort+limit returned no product or incomplete (e.g. only id, no name), fetch by pagination.
				$has_name = $this->extract_first_lang_value( $product['name'] ?? '' ) !== '';
				if ( empty( $product ) || ! $has_name ) {
					if ( function_exists( 'presa_migrate_log' ) ) {
						presa_migrate_log( 'get_product(' . $id . '): Zahtev 2 nepotpun, pokrećem paginaciju (prvo sa sort)' );
					}
					$product = array();
					$page_size = 250;
					$max_pages = 40;
					// Helper: accept only if product has real data (name or price), not just id. Name can be multilingual array.
					$client = $this;
					$product_has_data = function ( $p ) use ( $client ) {
						if ( ! is_array( $p ) ) {
							return false;
						}
						$has_name = $client->extract_first_lang_value( $p['name'] ?? '' ) !== '';
						$has_price = isset( $p['price'] ) && $p['price'] !== '' && $p['price'] !== null;
						return $has_name || $has_price;
					};

					// 1) Try with sort=[id_ASC]. Some dispatchers return only id when sort is used — then we skip and use no-sort.
					for ( $page = 0; $page < $max_pages; $page++ ) {
						$offset = $page * $page_size;
						$page_data = $this->fetch_products_page( $offset, $page_size, true );
						if ( is_wp_error( $page_data ) || $this->is_products_response_empty( $page_data ) ) {
							break;
						}
						$page_items = $this->parse_products_to_list( $page_data );
						$found = $this->find_product_by_id_in_list( $page_items, $id_str );
						if ( is_array( $found ) && $product_has_data( $found ) ) {
							$product = $found;
							if ( function_exists( 'presa_migrate_log' ) ) {
								presa_migrate_log( 'get_product(' . $id . '): pronađen u paginaciji sa sort (offset ' . $offset . ')' );
							}
							break;
						}
						if ( count( $page_items ) < $page_size ) {
							break;
						}
					}
					// 2) If not found or only id (e.g. sort returns minimal data), paginate WITHOUT sort — same as list, display=full.
					if ( empty( $product ) || ! $product_has_data( $product ) ) {
						if ( function_exists( 'presa_migrate_log' ) ) {
							presa_migrate_log( 'get_product(' . $id . '): paginacija bez sorta' );
						}
						$product = array();
						// Try likely page first: if order were by id, product $id is at offset floor(($id-1)/250)*250.
						$likely_offsets = array();
						if ( (int) $id > 0 ) {
							$likely_offsets[] = (int) floor( ( (int) $id - 1 ) / $page_size ) * $page_size;
						}
						for ( $page = 0; $page < $max_pages; $page++ ) {
							$off = $page * $page_size;
							if ( ! in_array( $off, $likely_offsets, true ) ) {
								$likely_offsets[] = $off;
							}
						}
						foreach ( $likely_offsets as $offset ) {
							$page_data = $this->fetch_products_page( $offset, $page_size, false );
							if ( is_wp_error( $page_data ) || $this->is_products_response_empty( $page_data ) ) {
								continue;
							}
							$page_items = $this->parse_products_to_list( $page_data );
							$found = $this->find_product_by_id_in_list( $page_items, $id_str );
							if ( is_array( $found ) && $product_has_data( $found ) ) {
								$product = $found;
								if ( function_exists( 'presa_migrate_log' ) ) {
									presa_migrate_log( 'get_product(' . $id . '): pronađen u paginaciji (offset ' . $offset . ')' );
								}
								break;
							}
							if ( count( $page_items ) < $page_size ) {
								break;
							}
						}
					}
				}
			}
		}
		// Single-element list wrapper.
		if ( isset( $product[0] ) && is_array( $product[0] ) ) {
			$product = $product[0];
		}
		// Root might be the product (no wrapper).
		if ( empty( $product ) && isset( $data['id'] ) && (string) $data['id'] === $id_str ) {
			$product = $data;
		}
		// Uvek vrati id kao skalar (API ponekad šalje id kao objekat).
		if ( is_array( $product ) && ! empty( $product ) ) {
			$product['id'] = $this->get_product_id_scalar( $product );
		}
		// Ne vraćaj proizvod koji nema name ni price — mapper ne može napraviti smislen WC proizvod. Name može biti višejezični niz.
		if ( is_array( $product ) && ! empty( $product ) ) {
			$has_name = $this->extract_first_lang_value( $product['name'] ?? '' ) !== '';
			$has_price = isset( $product['price'] ) && $product['price'] !== '' && $product['price'] !== null;
			if ( ! $has_name && ! $has_price ) {
				$product = array();
			}
		}
		// Dijagnostika kada nemamo proizvod: upiši šta je get_product_raw zaista vratio.
		if ( empty( $product ) && function_exists( 'presa_migrate_log' ) ) {
			$raw_keys = is_array( $data ) ? array_keys( $data ) : array();
			$msg = 'get_product(' . $id . ') prazan: raw_keys=' . implode( ',', $raw_keys );
			if ( ! empty( $data['prestashop'] ) && is_array( $data['prestashop'] ) ) {
				$msg .= ' prestashop_keys=' . implode( ',', array_keys( $data['prestashop'] ) );
			}
			if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
				$p = $data['products'];
				$inner = isset( $p['product'] ) ? $p['product'] : $p;
				$cnt = is_array( $inner ) ? count( $inner ) : 0;
				$msg .= ' products_count=' . $cnt;
			}
			$parsed = $this->parse_products_to_list( $data );
			$msg .= ' parsed_list_count=' . count( $parsed );
			presa_migrate_log( $msg );
		}
		return is_array( $product ) ? $product : array();
	}

	/**
	 * Get category by ID.
	 *
	 * @param int $id Category ID.
	 * @return array|WP_Error
	 */
	public function get_category( $id ) {
		$url = $this->build_url( 'categories/' . $id, array( 'display' => 'full' ) );
		$data = $this->get( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['category'] ) ? $data['category'] : array();
	}

	/**
	 * Get image binary (or URL) for product image.
	 * PrestaShop returns image via /api/images/products/{id_product}/{id_image}
	 *
	 * @param int $product_id Product ID.
	 * @param int $image_id   Image ID.
	 * @return string|WP_Error Image URL (we use direct image URL) or binary content.
	 */
	public function get_product_image_url( $product_id, $image_id ) {
		if ( $this->api_mode === 'dispatcher' ) {
			return add_query_arg( array(
				'url'    => 'images/products/' . $product_id . '/' . $image_id,
				'ws_key' => $this->api_key,
			), $this->base_url . '/webservice/dispatcher.php' );
		}
		return $this->base_url . '/api/images/products/' . $product_id . '/' . $image_id . '?ws_key=' . rawurlencode( $this->api_key );
	}

	/**
	 * Download image from PrestaShop and return local path or WP_Error.
	 *
	 * @param int $product_id Product ID.
	 * @param int $image_id   Image ID.
	 * @return string|WP_Error Temporary file path or WP_Error.
	 */
	public function download_product_image( $product_id, $image_id ) {
		$url = $this->get_product_image_url( $product_id, $image_id );
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		return $tmp;
	}
}
