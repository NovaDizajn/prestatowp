<?php
/**
 * Maps PrestaShop product data to WooCommerce product.
 *
 * @package Presa_Migrate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Presa_Mapper
 */
class Presa_Mapper {

	/**
	 * PrestaShop data source (API or DB client; must have get_category).
	 *
	 * @var object
	 */
	private $client;

	/**
	 * Cache category id => WooCommerce term_id.
	 *
	 * @var array
	 */
	private $category_cache = array();

	/**
	 * Brand taxonomy resolved once per import (product_brand or presa_product_brand). Not changed mid-import.
	 *
	 * @var string
	 */
	private $brand_taxonomy_cache = null;

	/**
	 * Cache manufacturer_id => attachment_id for brand logos to avoid repeated downloads in same batch.
	 *
	 * @var array
	 */
	private $brand_image_cache = array();

	/**
	 * Constructor.
	 *
	 * @param object $client API client or DB client (must have get_category).
	 */
	public function __construct( $client ) {
		$this->client = $client;
	}

	/**
	 * Get first language value from PrestaShop multilingual field.
	 * Handles: string, array of objects with value/#, associative array (lang id => value), XML-style.
	 *
	 * @param mixed $field Value (array of lang id => value, or string, or language objects).
	 * @return string
	 */
	public static function get_first_lang( $field ) {
		if ( is_scalar( $field ) ) {
			return is_string( $field ) ? $field : (string) $field;
		}
		if ( ! is_array( $field ) ) {
			return '';
		}
		// Single wrapper key "language" (XML-style in JSON).
		if ( isset( $field['language'] ) && is_array( $field['language'] ) ) {
			$field = $field['language'];
		}
		// Direct content keys (XML-to-JSON).
		foreach ( array( 'value', '#', '__value', 'content', '$' ) as $key ) {
			if ( isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) ) {
				return (string) $field[ $key ];
			}
		}
		$first = reset( $field );
		if ( $first === false ) {
			return '';
		}
		if ( is_scalar( $first ) ) {
			return (string) $first;
		}
		if ( is_array( $first ) ) {
			foreach ( array( 'value', '#', '__value', 'content', '$' ) as $key ) {
				if ( isset( $first[ $key ] ) && is_scalar( $first[ $key ] ) ) {
					return (string) $first[ $key ];
				}
			}
			// @attributes often present; content in # or value.
			foreach ( $first as $k => $v ) {
				if ( $k === '@attributes' || $k === '@' ) {
					continue;
				}
				if ( is_scalar( $v ) ) {
					return (string) $v;
				}
			}
		}
		return '';
	}

	/**
	 * Resolve WooCommerce category term_id from PrestaShop category id (create if needed).
	 *
	 * @param int $prestashop_category_id PrestaShop category ID.
	 * @return int|null WooCommerce product_cat term_id or null.
	 */
	public function get_or_create_wc_category( $prestashop_category_id ) {
		if ( isset( $this->category_cache[ $prestashop_category_id ] ) ) {
			return $this->category_cache[ $prestashop_category_id ];
		}
		$cat = $this->client->get_category( $prestashop_category_id );
		if ( is_wp_error( $cat ) || empty( $cat ) ) {
			$this->category_cache[ $prestashop_category_id ] = null;
			return null;
		}
		$name = self::get_first_lang( isset( $cat['name'] ) ? $cat['name'] : '' );
		$link_rewrite = self::get_first_lang( isset( $cat['link_rewrite'] ) ? $cat['link_rewrite'] : '' );
		$slug = $link_rewrite ? sanitize_title( $link_rewrite ) : sanitize_title( $name );
		if ( ! $name ) {
			$this->category_cache[ $prestashop_category_id ] = null;
			return null;
		}
		$parent_id = 0;
		if ( ! empty( $cat['id_parent'] ) && (int) $cat['id_parent'] > 0 ) {
			$parent_id = (int) $this->get_or_create_wc_category( (int) $cat['id_parent'] ) ?: 0;
		}
		$existing = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $existing ) {
			$this->category_cache[ $prestashop_category_id ] = (int) $existing->term_id;
			return (int) $existing->term_id;
		}
		$term = wp_insert_term( $name, 'product_cat', array(
			'slug'        => $slug,
			'parent'      => $parent_id,
			'description' => self::get_first_lang( isset( $cat['description'] ) ? $cat['description'] : '' ),
		) );
		if ( is_wp_error( $term ) ) {
			$this->category_cache[ $prestashop_category_id ] = null;
			return null;
		}
		$this->category_cache[ $prestashop_category_id ] = (int) $term['term_id'];
		return (int) $term['term_id'];
	}

	/**
	 * Get WooCommerce category IDs for product from PrestaShop associations.
	 *
	 * @param array $product PrestaShop product (with associations).
	 * @return int[]
	 */
	public function get_wc_category_ids( $product ) {
		$ids = array();
		if ( empty( $product['associations']['categories'] ) || ! is_array( $product['associations']['categories'] ) ) {
			return $ids;
		}
		foreach ( $product['associations']['categories'] as $assoc ) {
			$cat_id = isset( $assoc['id'] ) ? (int) $assoc['id'] : 0;
			if ( $cat_id > 0 ) {
				$wc_id = $this->get_or_create_wc_category( $cat_id );
				if ( $wc_id ) {
					$ids[] = $wc_id;
				}
			}
		}
		return array_unique( $ids );
	}

	/**
	 * Create WooCommerce product from PrestaShop product data and attachment IDs for images.
	 *
	 * @param array    $product     PrestaShop product (full).
	 * @param int[]    $image_ids   Array of attachment IDs: first = featured, rest = gallery.
	 * @param WP_Error $errors     Optional. Errors are appended here.
	 * @return int|WP_Error WC product ID or WP_Error.
	 */
	public function create_wc_product( $product, $image_ids = array(), &$errors = null ) {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce nije aktivan.', 'presa-migrate' ) );
		}
		$errors = $errors ?: new WP_Error();

		$combinations = isset( $product['combinations'] ) && is_array( $product['combinations'] ) ? $product['combinations'] : array();
		if ( ! empty( $combinations ) ) {
			if ( function_exists( 'presa_migrate_log' ) ) {
				presa_migrate_log( sprintf( 'Product %s create type=VARIABLE', isset( $product['id'] ) ? $product['id'] : '' ) );
			}
			return $this->create_wc_variable_product_from_combinations( $product, $image_ids, $errors );
		}
		$variations = isset( $product['variations'] ) && is_array( $product['variations'] ) ? $product['variations'] : array();
		if ( ! empty( $variations ) ) {
			if ( function_exists( 'presa_migrate_log' ) ) {
				presa_migrate_log( sprintf( 'Product %s create type=VARIABLE', isset( $product['id'] ) ? $product['id'] : '' ) );
			}
			return $this->create_wc_variable_product( $product, $image_ids, $variations, $errors );
		}

		if ( function_exists( 'presa_migrate_log' ) ) {
			presa_migrate_log( sprintf( 'Product %s create type=SIMPLE', isset( $product['id'] ) ? $product['id'] : '' ) );
		}
		return $this->create_wc_simple_product( $product, $image_ids, $errors );
	}

	/**
	 * Create or update WooCommerce product. When $existing_wc_id is set, updates that product (and converts type if needed).
	 *
	 * @param array    $product        PrestaShop product (full).
	 * @param int[]    $image_ids      Attachment IDs.
	 * @param WP_Error $errors         Errors appended here.
	 * @param int      $existing_wc_id Existing WC product ID to update.
	 * @return int|WP_Error WC product ID or WP_Error.
	 */
	public function create_or_update_wc_product( $product, $image_ids = array(), &$errors = null, $existing_wc_id = 0 ) {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce nije aktivan.', 'presa-migrate' ) );
		}
		$errors = $errors ?: new WP_Error();
		$existing_wc_id = (int) $existing_wc_id;
		if ( $existing_wc_id <= 0 ) {
			return $this->create_wc_product( $product, $image_ids, $errors );
		}
		$wc = wc_get_product( $existing_wc_id );
		if ( ! $wc || ! ( $wc instanceof \WC_Product ) ) {
			return new WP_Error( 'product_not_found', __( 'WooCommerce proizvod nije pronađen.', 'presa-migrate' ) );
		}
		// Delete existing variations so we can replace with new data.
		if ( $wc->is_type( 'variable' ) ) {
			$children = $wc->get_children();
			foreach ( $children as $child_id ) {
				$v = wc_get_product( $child_id );
				if ( $v && $v->is_type( 'variation' ) ) {
					$v->delete( true );
				}
			}
		}
		$combinations = isset( $product['combinations'] ) && is_array( $product['combinations'] ) ? $product['combinations'] : array();
		$variations_legacy = isset( $product['variations'] ) && is_array( $product['variations'] ) ? $product['variations'] : array();
		$has_variations = ! empty( $combinations ) || ! empty( $variations_legacy );
		if ( function_exists( 'presa_migrate_log' ) ) {
			presa_migrate_log( sprintf( 'Product %s create type=%s', isset( $product['id'] ) ? $product['id'] : '', $has_variations ? 'VARIABLE' : 'SIMPLE' ) );
		}
		$new_type = $has_variations ? 'variable' : 'simple';
		wp_set_object_terms( $existing_wc_id, $new_type, 'product_type' );
		$wc = wc_get_product( $existing_wc_id );
		if ( ! $wc ) {
			return new WP_Error( 'reload_failed', __( 'Učitavanje proizvoda nije uspelo.', 'presa-migrate' ) );
		}
		$this->set_wc_product_base_data( $wc, $product, $image_ids );
		if ( $has_variations ) {
			$wc->set_manage_stock( false );
			if ( ! empty( $combinations ) ) {
				$built = $this->build_attributes_from_combinations( $combinations );
				$wc->set_attributes( $built['wc_attributes'] );
				$wc->save();
				$create_result = $this->create_variations_from_combinations( $existing_wc_id, $product, isset( $built['term_slug_by_taxonomy_value'] ) ? $built['term_slug_by_taxonomy_value'] : array() );
				if ( ! empty( $create_result['first_default_attrs'] ) ) {
					$wc->set_default_attributes( $create_result['first_default_attrs'] );
					$wc->save();
				}
			} else {
				$variations = $variations_legacy;
				$attr_slugs_to_values = array();
				foreach ( $variations as $v ) {
					if ( empty( $v['attributes'] ) || ! is_array( $v['attributes'] ) ) {
						continue;
					}
					foreach ( $v['attributes'] as $slug => $value_name ) {
						if ( ! isset( $attr_slugs_to_values[ $slug ] ) ) {
							$attr_slugs_to_values[ $slug ] = array();
						}
						$attr_slugs_to_values[ $slug ][ $value_name ] = true;
					}
				}
				$attr_slugs_to_values = array_map( 'array_keys', $attr_slugs_to_values );
				$wc_attributes = array();
				$term_slug_by_taxonomy_value = array();
				foreach ( $attr_slugs_to_values as $slug => $value_names ) {
					$taxonomy = $slug;
					$this->ensure_attribute_taxonomy( $taxonomy );
					$term_ids = array();
					foreach ( $value_names as $value_name ) {
						$term = $this->get_or_create_attribute_term( $taxonomy, $value_name );
						if ( $term ) {
							$term_ids[] = $term->term_id;
							$term_slug_by_taxonomy_value[ $taxonomy ][ $value_name ] = $term->slug;
						}
					}
					if ( ! empty( $term_ids ) ) {
						$wc_attr = new \WC_Product_Attribute();
						$wc_attr->set_name( $taxonomy );
						$wc_attr->set_options( $term_ids );
						$wc_attr->set_visible( true );
						$wc_attr->set_variation( true );
						$wc_attributes[] = $wc_attr;
					}
				}
				$wc->set_attributes( $wc_attributes );
				$wc->save();
				foreach ( $variations as $v ) {
					$variation = new \WC_Product_Variation();
					$variation->set_parent_id( $existing_wc_id );
					$var_attrs = array();
					if ( ! empty( $v['attributes'] ) && is_array( $v['attributes'] ) ) {
						foreach ( $v['attributes'] as $slug => $value_name ) {
							$term_slug = isset( $term_slug_by_taxonomy_value[ $slug ][ $value_name ] )
								? $term_slug_by_taxonomy_value[ $slug ][ $value_name ]
								: sanitize_title( $value_name );
							$var_attrs[ 'attribute_' . $slug ] = $term_slug;
						}
					}
					$variation->set_attributes( $var_attrs );
					$variation->set_regular_price( isset( $v['price'] ) ? (string) floatval( $v['price'] ) : '' );
					$variation->set_manage_stock( true );
					$qty = isset( $v['quantity'] ) ? (int) $v['quantity'] : 0;
					$variation->set_stock_quantity( $qty );
					$variation->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
					if ( ! empty( $v['reference'] ) ) {
						$variation->set_sku( $v['reference'] );
					}
					$variation->save();
				}
			}
			if ( function_exists( 'WC' ) && isset( WC()->product_factory ) ) {
				$variable = WC()->product_factory->get_product( $existing_wc_id );
				if ( $variable && is_callable( array( $variable, 'sync' ) ) ) {
					$variable->sync();
				}
			}
			wc_delete_product_transients( $existing_wc_id );
		} else {
			$price = isset( $product['price'] ) ? floatval( $product['price'] ) : 0;
			$wc->set_regular_price( (string) $price );
			$reference = isset( $product['reference'] ) ? $product['reference'] : '';
			$reference = is_scalar( $reference ) ? (string) $reference : self::get_first_lang( $reference );
			if ( $reference !== '' ) {
				$wc->set_sku( $reference );
			}
			$wc->set_manage_stock( true );
			$qty = 0;
			if ( isset( $product['quantity'] ) && $product['quantity'] !== '' ) {
				$qty = (int) $product['quantity'];
			}
			$wc->set_stock_quantity( $qty );
			$wc->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
			$wc->save();
		}
		update_post_meta( $existing_wc_id, '_presa_prestashop_id', (string) $product['id'] );
		$this->set_product_brand( $existing_wc_id, $product );
		return (int) $existing_wc_id;
	}

	/**
	 * Create a simple WooCommerce product (no variations).
	 *
	 * @param array    $product   PrestaShop product.
	 * @param int[]    $image_ids Attachment IDs.
	 * @param WP_Error $errors    Errors appended here.
	 * @return int|WP_Error
	 */
	private function create_wc_simple_product( $product, $image_ids, &$errors ) {
		$wc = new \WC_Product_Simple();
		$this->set_wc_product_base_data( $wc, $product, $image_ids );
		$price = isset( $product['price'] ) ? floatval( $product['price'] ) : 0;
		$wc->set_regular_price( (string) $price );
		$reference = isset( $product['reference'] ) ? $product['reference'] : '';
		$reference = is_scalar( $reference ) ? (string) $reference : self::get_first_lang( $reference );
		if ( $reference !== '' ) {
			$wc->set_sku( $reference );
		}
		$wc->set_manage_stock( true );
		$qty = 0;
		if ( isset( $product['quantity'] ) && $product['quantity'] !== '' ) {
			$qty = (int) $product['quantity'];
		}
		$wc->set_stock_quantity( $qty );
		$wc->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
		$product_id = $wc->save();
		if ( ! $product_id ) {
			return new WP_Error( 'save_failed', __( 'Čuvanje proizvoda nije uspelo.', 'presa-migrate' ) );
		}
		if ( function_exists( 'presa_migrate_log' ) ) {
			$id_product = isset( $product['id'] ) ? $product['id'] : '';
			presa_migrate_log( sprintf( 'Product %s mode=simple', $id_product ) );
		}
		$this->set_product_brand( $product_id, $product );
		return (int) $product_id;
	}

	/**
	 * Create a variable WooCommerce product with variations.
	 *
	 * @param array    $product   PrestaShop product (with variations array).
	 * @param int[]    $image_ids Attachment IDs.
	 * @param array    $variations List of variation arrays (reference, price, quantity, attributes).
	 * @param WP_Error $errors    Errors appended here.
	 * @return int|WP_Error
	 */
	private function create_wc_variable_product( $product, $image_ids, $variations, &$errors ) {
		if ( ! class_exists( 'WC_Product_Variable' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce Variable proizvod nije dostupan.', 'presa-migrate' ) );
		}
		$wc = new \WC_Product_Variable();
		$this->set_wc_product_base_data( $wc, $product, $image_ids );
		// Variable parent: no price, no stock, no SKU.
		$wc->set_manage_stock( false );

		// Collect unique attributes (slug => list of value names) and ensure taxonomy + terms exist.
		$attr_slugs_to_values = array();
		foreach ( $variations as $v ) {
			if ( empty( $v['attributes'] ) || ! is_array( $v['attributes'] ) ) {
				continue;
			}
			foreach ( $v['attributes'] as $slug => $value_name ) {
				if ( ! isset( $attr_slugs_to_values[ $slug ] ) ) {
					$attr_slugs_to_values[ $slug ] = array();
				}
				$attr_slugs_to_values[ $slug ][ $value_name ] = true;
			}
		}
		$attr_slugs_to_values = array_map( 'array_keys', $attr_slugs_to_values );

		$wc_attributes = array();
		$term_slug_by_taxonomy_value = array(); // [ 'pa_boja' ][ 'Crvena' ] => 'crvena'
		foreach ( $attr_slugs_to_values as $slug => $value_names ) {
			$taxonomy = $slug;
			$this->ensure_attribute_taxonomy( $taxonomy );
			$term_ids = array();
			foreach ( $value_names as $value_name ) {
				$term = $this->get_or_create_attribute_term( $taxonomy, $value_name );
				if ( $term ) {
					$term_ids[] = $term->term_id;
					$term_slug_by_taxonomy_value[ $taxonomy ][ $value_name ] = $term->slug;
				}
			}
			if ( ! empty( $term_ids ) ) {
				$wc_attr = new \WC_Product_Attribute();
				$wc_attr->set_name( $taxonomy );
				$wc_attr->set_options( $term_ids );
				$wc_attr->set_visible( true );
				$wc_attr->set_variation( true );
				$wc_attributes[] = $wc_attr;
			}
		}
		$wc->set_attributes( $wc_attributes );
		$product_id = $wc->save();
		if ( ! $product_id ) {
			return new WP_Error( 'save_failed', __( 'Čuvanje variable proizvoda nije uspelo.', 'presa-migrate' ) );
		}

		// Create variations.
		foreach ( $variations as $v ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $product_id );
			$var_attrs = array();
			if ( ! empty( $v['attributes'] ) && is_array( $v['attributes'] ) ) {
				foreach ( $v['attributes'] as $slug => $value_name ) {
					$term_slug = isset( $term_slug_by_taxonomy_value[ $slug ][ $value_name ] )
						? $term_slug_by_taxonomy_value[ $slug ][ $value_name ]
						: sanitize_title( $value_name );
					$var_attrs[ 'attribute_' . $slug ] = $term_slug;
				}
			}
			$variation->set_attributes( $var_attrs );
			$variation->set_regular_price( isset( $v['price'] ) ? (string) floatval( $v['price'] ) : '' );
			$variation->set_manage_stock( true );
			$qty = isset( $v['quantity'] ) ? (int) $v['quantity'] : 0;
			$variation->set_stock_quantity( $qty );
			$variation->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
			if ( ! empty( $v['reference'] ) ) {
				$variation->set_sku( $v['reference'] );
			}
			$variation->save();
		}

		$this->set_product_brand( $product_id, $product );
		return (int) $product_id;
	}

	/**
	 * Create a variable WooCommerce product from PrestaShop combinations (id_product_attribute, sku, price_impact, quantity, attributes [{ group_name, value_name }]).
	 *
	 * @param array    $product   PrestaShop product (price, combinations).
	 * @param int[]    $image_ids Attachment IDs.
	 * @param WP_Error $errors    Errors appended here.
	 * @return int|WP_Error
	 */
	private function create_wc_variable_product_from_combinations( $product, $image_ids, &$errors ) {
		if ( ! class_exists( 'WC_Product_Variable' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce Variable proizvod nije dostupan.', 'presa-migrate' ) );
		}
		$combinations = isset( $product['combinations'] ) && is_array( $product['combinations'] ) ? $product['combinations'] : array();
		if ( empty( $combinations ) ) {
			return $this->create_wc_simple_product( $product, $image_ids, $errors );
		}
		$wc = new \WC_Product_Variable();
		$this->set_wc_product_base_data( $wc, $product, $image_ids );
		$wc->set_manage_stock( false );
		$built = $this->build_attributes_from_combinations( $combinations );
		$wc->set_attributes( $built['wc_attributes'] );
		$product_id = $wc->save();
		if ( ! $product_id ) {
			return new WP_Error( 'save_failed', __( 'Čuvanje variable proizvoda nije uspelo.', 'presa-migrate' ) );
		}
		$id_product = isset( $product['id'] ) ? $product['id'] : '';
		if ( function_exists( 'presa_migrate_log' ) ) {
			presa_migrate_log( sprintf( '[DEBUG] created variable product ID=%d (Presta id_product=%s)', $product_id, $id_product ) );
			$attr_names = array();
			foreach ( $built['wc_attributes'] as $wc_attr ) {
				$attr_names[] = $wc_attr->get_name();
			}
			presa_migrate_log( sprintf( '[DEBUG] created attributes: %s', implode( ', ', $attr_names ) ) );
		}
		$create_result = $this->create_variations_from_combinations( $product_id, $product, isset( $built['term_slug_by_taxonomy_value'] ) ? $built['term_slug_by_taxonomy_value'] : array() );
		$first_default_attrs = $create_result['first_default_attrs'];
		if ( function_exists( 'presa_migrate_log' ) ) {
			presa_migrate_log( sprintf( '[DEBUG] created variations count=%d', $create_result['created_count'] ) );
			if ( $create_result['missing_attributes'] > 0 ) {
				presa_migrate_log( sprintf( '[DEBUG] missing attributes: %d variation(s) without attributes', $create_result['missing_attributes'] ) );
			}
			if ( $create_result['missing_stock'] > 0 ) {
				presa_migrate_log( sprintf( '[DEBUG] missing stock: %d variation(s) with zero or no stock', $create_result['missing_stock'] ) );
			}
			if ( $create_result['missing_images'] > 0 ) {
				presa_migrate_log( sprintf( '[DEBUG] missing images: %d variation(s) without image', $create_result['missing_images'] ) );
			}
		}
		if ( ! empty( $first_default_attrs ) ) {
			$variable = wc_get_product( $product_id );
			if ( $variable && $variable->is_type( 'variable' ) ) {
				$variable->set_default_attributes( $first_default_attrs );
				$variable->save();
			}
		}
		if ( function_exists( 'WC' ) && isset( WC()->product_factory ) ) {
			$variable = WC()->product_factory->get_product( $product_id );
			if ( $variable && is_callable( array( $variable, 'sync' ) ) ) {
				$variable->sync();
			}
		}
		wc_delete_product_transients( $product_id );
		$this->set_product_brand( $product_id, $product );
		return (int) $product_id;
	}

	/**
	 * Build global WooCommerce product attributes (pa_*) from combinations. Creates attribute taxonomy if missing, assigns to product, used for variations.
	 *
	 * @param array $combinations List of combination arrays (attributes as [{ group_name, value_name }]).
	 * @return array { 'wc_attributes' => WC_Product_Attribute[], 'term_slug_by_taxonomy_value' => [ taxonomy => [ value_name => term_slug ] ] }
	 */
	private function build_attributes_from_combinations( $combinations ) {
		$taxonomy_order = array();
		$attr_slugs_to_values = array();
		foreach ( $combinations as $c ) {
			if ( empty( $c['attributes'] ) || ! is_array( $c['attributes'] ) ) {
				continue;
			}
			foreach ( $c['attributes'] as $a ) {
				$group_name = isset( $a['group_name'] ) ? trim( (string) $a['group_name'] ) : '';
				$value_name = isset( $a['value_name'] ) ? trim( (string) $a['value_name'] ) : '';
				if ( $group_name === '' || $value_name === '' ) {
					continue;
				}
				$taxonomy = $this->normalize_attr_slug( $group_name );
				if ( ! isset( $attr_slugs_to_values[ $taxonomy ] ) ) {
					$taxonomy_order[] = $taxonomy;
					$attr_slugs_to_values[ $taxonomy ] = array();
				}
				$attr_slugs_to_values[ $taxonomy ][ $value_name ] = true;
			}
		}
		$wc_attributes = array();
		$term_slug_by_taxonomy_value = array();
		foreach ( $taxonomy_order as $taxonomy ) {
			$value_names = isset( $attr_slugs_to_values[ $taxonomy ] ) ? array_keys( $attr_slugs_to_values[ $taxonomy ] ) : array();
			if ( empty( $value_names ) ) {
				continue;
			}
			$attr_id = $this->ensure_attribute_taxonomy( $taxonomy );
			$term_ids = array();
			foreach ( $value_names as $value_name ) {
				$term = $this->get_or_create_attribute_term( $taxonomy, $value_name );
				if ( $term ) {
					$term_ids[] = $term->term_id;
					$term_slug_by_taxonomy_value[ $taxonomy ][ $value_name ] = $term->slug;
				}
			}
			if ( empty( $term_ids ) ) {
				continue;
			}
			$wc_attr = new \WC_Product_Attribute();
			if ( $attr_id > 0 ) {
				$wc_attr->set_id( $attr_id );
			}
			$wc_attr->set_name( $taxonomy );
			$wc_attr->set_options( $term_ids );
			$wc_attr->set_visible( true );
			$wc_attr->set_variation( true );
			$wc_attributes[] = $wc_attr;
		}
		return array(
			'wc_attributes'               => $wc_attributes,
			'term_slug_by_taxonomy_value' => $term_slug_by_taxonomy_value,
		);
	}

	/**
	 * Create WC variation children from product combinations. Global attributes: attribute_pa_xxx => term_slug. Price = base + impact, stock, SKU, optional image.
	 * Stores _presa_id_product_attribute on each variation. Returns first variation's attributes + debug counts.
	 *
	 * @param int   $parent_id                    WC variable product ID.
	 * @param array $product                      PrestaShop product (price, combinations).
	 * @param array $term_slug_by_taxonomy_value [ taxonomy => [ value_name => term_slug ] ] for variation attribute values.
	 * @return array { first_default_attrs, created_count, missing_attributes, missing_stock, missing_images }
	 */
	private function create_variations_from_combinations( $parent_id, $product, $term_slug_by_taxonomy_value = array() ) {
		$base_price = isset( $product['price'] ) ? floatval( $product['price'] ) : 0;
		$combinations = isset( $product['combinations'] ) && is_array( $product['combinations'] ) ? $product['combinations'] : array();
		$first_default_attrs = array();
		$created_count = 0;
		$missing_attributes = 0;
		$missing_stock = 0;
		$missing_images = 0;
		foreach ( $combinations as $combo ) {
			$id_product_attribute = isset( $combo['id_product_attribute'] ) ? (int) $combo['id_product_attribute'] : 0;
			$sku = isset( $combo['sku'] ) ? trim( (string) $combo['sku'] ) : '';
			if ( empty( $combo['attributes'] ) || ! is_array( $combo['attributes'] ) || count( array_filter( $combo['attributes'] ) ) === 0 ) {
				$missing_attributes++;
			}
			$qty = isset( $combo['quantity'] ) ? (int) $combo['quantity'] : 0;
			if ( $qty <= 0 ) {
				$missing_stock++;
			}
			if ( empty( $combo['variation_image_attachment_id'] ) && empty( $combo['image_id'] ) ) {
				$missing_images++;
			}
			if ( $sku !== '' && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$existing_id = wc_get_product_id_by_sku( $sku );
				if ( $existing_id && $existing_id > 0 ) {
					if ( function_exists( 'presa_migrate_log' ) ) {
						presa_migrate_log( sprintf( 'Variation %d skipped: duplicate SKU (%s)', $id_product_attribute, $sku ) );
					}
					continue;
				}
			}
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$var_attrs = array();
			$attrs_dump = array();
			if ( ! empty( $combo['attributes'] ) && is_array( $combo['attributes'] ) ) {
				foreach ( $combo['attributes'] as $a ) {
					$group_name = isset( $a['group_name'] ) ? trim( (string) $a['group_name'] ) : '';
					$value_name = isset( $a['value_name'] ) ? trim( (string) $a['value_name'] ) : '';
					if ( $group_name === '' || $value_name === '' ) {
						continue;
					}
					$taxonomy = $this->normalize_attr_slug( $group_name );
					$term_slug = isset( $term_slug_by_taxonomy_value[ $taxonomy ][ $value_name ] )
						? $term_slug_by_taxonomy_value[ $taxonomy ][ $value_name ]
						: $this->normalize_term_slug( $value_name );
					$var_attrs[ 'attribute_' . $taxonomy ] = $term_slug;
					$attrs_dump[] = $group_name . ':' . $value_name;
				}
			}
			$variation->set_attributes( $var_attrs );
			if ( $sku !== '' ) {
				$variation->set_sku( $sku );
			}
			$price_impact = isset( $combo['price_impact'] ) ? floatval( $combo['price_impact'] ) : 0;
			$variation->set_regular_price( (string) ( $base_price + $price_impact ) );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $qty );
			$variation->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
			if ( ! empty( $combo['variation_image_attachment_id'] ) ) {
				$variation->set_image_id( (int) $combo['variation_image_attachment_id'] );
			}
			$variation_id = $variation->save();
			if ( ! $variation_id ) {
				if ( function_exists( 'presa_migrate_log' ) ) {
					presa_migrate_log( sprintf( 'Variation %d skipped: save failed (e.g. duplicate SKU)', $id_product_attribute ) );
				}
				continue;
			}
			update_post_meta( $variation_id, '_presa_id_product_attribute', (string) $id_product_attribute );
			$final_price = $base_price + $price_impact;
			if ( function_exists( 'presa_migrate_log' ) ) {
				presa_migrate_log( sprintf( 'Variation %d created (WC %d) attrs=%s price=%s qty=%d', $id_product_attribute, $variation_id, implode( ', ', $attrs_dump ), $final_price, $qty ) );
			}
			$created_count++;
			if ( empty( $first_default_attrs ) && ! empty( $var_attrs ) ) {
				$first_default_attrs = $var_attrs;
			}
		}
		return array(
			'first_default_attrs' => $first_default_attrs,
			'created_count'       => $created_count,
			'missing_attributes'  => $missing_attributes,
			'missing_stock'       => $missing_stock,
			'missing_images'      => $missing_images,
		);
	}

	/**
	 * Custom product attribute slug (no pa_ prefix). Used for variable products from combinations.
	 *
	 * @param string $group_name Attribute group name (e.g. "Boja").
	 * @return string e.g. "boja"
	 */
	private function get_custom_attr_slug( $group_name ) {
		$slug = sanitize_title( trim( (string) $group_name ) );
		return $slug !== '' ? $slug : 'option';
	}

	/**
	 * Normalize attribute taxonomy slug from group name (pa_*). Uses client if available.
	 *
	 * @param string $group_name Attribute group name.
	 * @return string
	 */
	private function normalize_attr_slug( $group_name ) {
		if ( is_object( $this->client ) && method_exists( $this->client, 'normalize_attr_slug' ) ) {
			return $this->client->normalize_attr_slug( $group_name );
		}
		$slug = sanitize_title( $group_name );
		return $slug !== '' ? 'pa_' . $slug : 'pa_option';
	}

	/**
	 * Normalize term slug from value name. Uses client if available.
	 *
	 * @param string $value_name Attribute value name.
	 * @return string
	 */
	private function normalize_term_slug( $value_name ) {
		if ( is_object( $this->client ) && method_exists( $this->client, 'normalize_term_slug' ) ) {
			return $this->client->normalize_term_slug( $value_name );
		}
		$slug = sanitize_title( $value_name );
		return $slug !== '' ? $slug : 'term-' . md5( $value_name );
	}

	/**
	 * Set common product data (name, description, status, categories, images, dimensions, meta).
	 *
	 * @param WC_Product $wc        WooCommerce product object.
	 * @param array      $product  PrestaShop product.
	 * @param int[]      $image_ids Attachment IDs.
	 */
	private function set_wc_product_base_data( $wc, $product, $image_ids ) {
		$name = self::get_first_lang( isset( $product['name'] ) ? $product['name'] : '' );
		if ( ! $name ) {
			$name = sprintf( __( 'Proizvod #%s', 'presa-migrate' ), isset( $product['id'] ) ? $product['id'] : '' );
		}
		$wc->set_name( $name );
		$desc = self::get_first_lang( isset( $product['description'] ) ? $product['description'] : '' );
		$short = self::get_first_lang( isset( $product['description_short'] ) ? $product['description_short'] : '' );
		$wc->set_description( $desc );
		$wc->set_short_description( $short );
		$wc->set_status( ( ! empty( $product['active'] ) && $product['active'] !== '0' ) ? 'publish' : 'draft' );
		if ( isset( $product['weight'] ) && $product['weight'] !== '' ) {
			$wc->set_weight( (string) floatval( $product['weight'] ) );
		}
		if ( ! empty( $product['width'] ) ) {
			$wc->set_width( (string) floatval( $product['width'] ) );
		}
		if ( ! empty( $product['height'] ) ) {
			$wc->set_height( (string) floatval( $product['height'] ) );
		}
		if ( ! empty( $product['depth'] ) ) {
			$wc->set_length( (string) floatval( $product['depth'] ) );
		}
		$cat_ids = $this->get_wc_category_ids( $product );
		if ( ! empty( $cat_ids ) ) {
			$wc->set_category_ids( $cat_ids );
		}
		if ( ! empty( $image_ids ) ) {
			$wc->set_image_id( $image_ids[0] );
			if ( count( $image_ids ) > 1 ) {
				$wc->set_gallery_image_ids( array_slice( $image_ids, 1 ) );
			}
		}
		if ( isset( $product['ean13'] ) && $product['ean13'] !== '' ) {
			$wc->update_meta_data( '_ean13', $product['ean13'] );
		}
		if ( isset( $product['upc'] ) && $product['upc'] !== '' ) {
			$wc->update_meta_data( '_upc', $product['upc'] );
		}
		if ( isset( $product['isbn'] ) && $product['isbn'] !== '' ) {
			$wc->update_meta_data( '_isbn', $product['isbn'] );
		}
	}

	/**
	 * Assign brand to product from manufacturer_name. Always called after product save (create simple, update existing, variable parent).
	 * Uses WooCommerce core taxonomy product_brand when it exists; creates term if missing (term_exists check); logs manufacturer id and taxonomy per product.
	 *
	 * @param int   $product_id WC product ID.
	 * @param array $product   PrestaShop product (id, id_manufacturer, manufacturer_name).
	 */
	public function set_product_brand( $product_id, $product ) {
		$id_product       = isset( $product['id'] ) ? $product['id'] : '';
		$id_manufacturer  = isset( $product['id_manufacturer'] ) ? (int) $product['id_manufacturer'] : 0;
		$manufacturer_name = isset( $product['manufacturer_name'] ) ? (string) $product['manufacturer_name'] : '';

		if ( $id_manufacturer > 0 && $manufacturer_name === '' ) {
			if ( function_exists( 'presa_migrate_brand_log' ) ) {
				presa_migrate_brand_log( 'Product ' . $id_product . ' → brand skipped – manufacturer name missing (id_manufacturer=' . $id_manufacturer . ')' );
			}
			return;
		}
		if ( $manufacturer_name === '' ) {
			return;
		}

		$name = $this->normalize_brand_name( $manufacturer_name );
		if ( $name === '' ) {
			if ( function_exists( 'presa_migrate_brand_log' ) ) {
				presa_migrate_brand_log( 'Product ' . $id_product . ' → brand skipped (name empty after normalize)' );
			}
			return;
		}

		$taxonomy = $this->get_brand_taxonomy();
		if ( $taxonomy === '' ) {
			return;
		}

		$term = $this->get_or_create_brand_term( $name, $taxonomy );
		if ( ! $term ) {
			return;
		}
		wp_set_object_terms( (int) $product_id, array( $term->term_id ), $taxonomy, false );

		$manufacturer_logo_urls = isset( $product['manufacturer_logo_urls'] ) && is_array( $product['manufacturer_logo_urls'] ) ? $product['manufacturer_logo_urls'] : array();
		$this->maybe_set_brand_image( (int) $term->term_id, $manufacturer_logo_urls, $id_manufacturer, $name );

		if ( function_exists( 'presa_migrate_log' ) ) {
			presa_migrate_log( 'Product ' . $id_product . ' manufacturer ' . $id_manufacturer . ' → taxonomy ' . $taxonomy );
		}
		if ( function_exists( 'presa_migrate_brand_log' ) ) {
			presa_migrate_brand_log( 'Product ' . $id_product . ' → manufacturer ' . $id_manufacturer . ' → brand "' . $name . '" → taxonomy ' . $taxonomy );
		}
	}

	/**
	 * Set brand term thumbnail (logo) from PrestaShop manufacturer logo URLs if not already set.
	 * Uses cache (manufacturer_id => attachment_id) to avoid repeated downloads in same batch.
	 *
	 * @param int   $term_id                 Brand term ID.
	 * @param array $manufacturer_logo_urls  Candidate URLs (e.g. {base_url}/img/m/{id}.jpg, .png).
	 * @param int   $manufacturer_id         PrestaShop id_manufacturer (for cache key).
	 * @param string $brand_name             Brand name (for log).
	 */
	private function maybe_set_brand_image( $term_id, $manufacturer_logo_urls, $manufacturer_id, $brand_name ) {
		if ( get_term_meta( $term_id, 'thumbnail_id', true ) ) {
			return;
		}
		if ( $manufacturer_id > 0 && isset( $this->brand_image_cache[ $manufacturer_id ] ) ) {
			$attach_id = (int) $this->brand_image_cache[ $manufacturer_id ];
			if ( $attach_id > 0 ) {
				update_term_meta( $term_id, 'thumbnail_id', $attach_id );
				if ( function_exists( 'presa_migrate_log' ) ) {
					presa_migrate_log( 'Brand ' . $brand_name . ' (' . $manufacturer_id . ') image set attachment ' . $attach_id . ' (cached)' );
				}
			}
			return;
		}
		if ( empty( $manufacturer_logo_urls ) || ! is_array( $manufacturer_logo_urls ) ) {
			if ( function_exists( 'presa_migrate_log' ) ) {
				presa_migrate_log( 'Brand ' . $brand_name . ' (' . $manufacturer_id . ') image skipped: no logo URLs' );
			}
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		foreach ( $manufacturer_logo_urls as $url ) {
			$url = esc_url_raw( $url );
			if ( ! $url ) {
				continue;
			}
			$head = wp_remote_head( $url, array( 'timeout' => 15, 'redirection' => 3 ) );
			if ( is_wp_error( $head ) ) {
				if ( function_exists( 'presa_migrate_log' ) ) {
					presa_migrate_log( 'Brand ' . $brand_name . ' (' . $manufacturer_id . ') image skipped: ' . $url . ' – ' . $head->get_error_message() );
				}
				continue;
			}
			$code  = wp_remote_retrieve_response_code( $head );
			$ctype = wp_remote_retrieve_header( $head, 'content-type' );
			if ( $code !== 200 ) {
				if ( function_exists( 'presa_migrate_log' ) ) {
					presa_migrate_log( 'Brand ' . $brand_name . ' (' . $manufacturer_id . ') image skipped: ' . $url . ' – HTTP ' . $code );
				}
				continue;
			}
			if ( ! $ctype || strpos( strtolower( $ctype ), 'image/' ) !== 0 ) {
				if ( function_exists( 'presa_migrate_log' ) ) {
					presa_migrate_log( 'Brand ' . $brand_name . ' (' . $manufacturer_id . ') image skipped: ' . $url . ' – Content-Type not image' );
				}
				continue;
			}
			$tmp = download_url( $url );
			if ( is_wp_error( $tmp ) ) {
				if ( function_exists( 'presa_migrate_log' ) ) {
					presa_migrate_log( 'Brand ' . $brand_name . ' (' . $manufacturer_id . ') image skipped: download failed – ' . $tmp->get_error_message() );
				}
				continue;
			}
			$ext = pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg';
			$file_array = array(
				'name'     => 'brand-' . $manufacturer_id . '.' . $ext,
				'tmp_name' => $tmp,
			);
			$attach_id = media_handle_sideload( $file_array, 0 );
			if ( is_wp_error( $attach_id ) ) {
				@unlink( $tmp );
				if ( function_exists( 'presa_migrate_log' ) ) {
					presa_migrate_log( 'Brand ' . $brand_name . ' (' . $manufacturer_id . ') image skipped: sideload failed – ' . $attach_id->get_error_message() );
				}
				continue;
			}
			update_term_meta( $term_id, 'thumbnail_id', (int) $attach_id );
			if ( $manufacturer_id > 0 ) {
				$this->brand_image_cache[ $manufacturer_id ] = (int) $attach_id;
			}
			if ( function_exists( 'presa_migrate_log' ) ) {
				presa_migrate_log( 'Brand ' . $brand_name . ' (' . $manufacturer_id . ') image set attachment ' . $attach_id );
			}
			return;
		}
		if ( function_exists( 'presa_migrate_log' ) ) {
			presa_migrate_log( 'Brand ' . $brand_name . ' (' . $manufacturer_id . ') image skipped: no valid URL (tried ' . count( $manufacturer_logo_urls ) . ')' );
		}
	}

	/**
	 * Normalize brand name: trim, collapse multiple spaces, strip HTML. Prevents duplicates.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private function normalize_brand_name( $name ) {
		$name = trim( (string) $name );
		if ( $name === '' ) {
			return '';
		}
		$name = wp_strip_all_tags( $name );
		$name = preg_replace( '/\s+/', ' ', $name );
		return trim( $name );
	}

	/**
	 * Get brand taxonomy once per import: product_brand if exists, else presa_product_brand (registered on init). Not changed mid-import.
	 *
	 * @return string Taxonomy name or empty.
	 */
	private function get_brand_taxonomy() {
		if ( $this->brand_taxonomy_cache !== null ) {
			return $this->brand_taxonomy_cache;
		}
		if ( taxonomy_exists( 'product_brand' ) ) {
			$this->brand_taxonomy_cache = 'product_brand';
			return $this->brand_taxonomy_cache;
		}
		$this->brand_taxonomy_cache = taxonomy_exists( 'presa_product_brand' ) ? 'presa_product_brand' : '';
		return $this->brand_taxonomy_cache;
	}

	/**
	 * Get or create brand term. Uses term_exists($slug, $taxonomy) before insert to reuse and avoid duplicates.
	 *
	 * @param string $name     Normalized brand name (trim, collapse spaces, no HTML).
	 * @param string $taxonomy Taxonomy (product_brand or presa_product_brand).
	 * @return WP_Term|null
	 */
	private function get_or_create_brand_term( $name, $taxonomy ) {
		if ( $name === '' || $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}
		$slug = sanitize_title( $name );
		if ( $slug === '' ) {
			$slug = 'brand-' . md5( $name );
		}
		$exists = term_exists( $slug, $taxonomy );
		if ( $exists ) {
			$term = get_term( (int) $exists['term_id'], $taxonomy );
			return ( $term && ! is_wp_error( $term ) ) ? $term : null;
		}
		$t = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
		if ( is_wp_error( $t ) ) {
			return null;
		}
		return get_term( (int) $t['term_id'], $taxonomy );
	}

	/**
	 * Ensure WooCommerce global attribute taxonomy exists (pa_*). Registers if missing.
	 *
	 * @param string $taxonomy Taxonomy name (e.g. pa_boja).
	 * @return int Attribute taxonomy ID (for set_id on WC_Product_Attribute), or 0.
	 */
	private function ensure_attribute_taxonomy( $taxonomy ) {
		$existing_id = $this->get_attribute_taxonomy_id( $taxonomy );
		if ( $existing_id > 0 ) {
			return $existing_id;
		}
		if ( function_exists( 'wc_create_attribute' ) ) {
			$slug = str_replace( 'pa_', '', $taxonomy );
			$label = ucfirst( str_replace( array( '-', '_' ), ' ', $slug ) );
			$id = wc_create_attribute( array(
				'name'         => $label,
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			) );
			if ( ! is_wp_error( $id ) ) {
				register_taxonomy( $taxonomy, apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, array( 'product' ) ), apply_filters( 'woocommerce_taxonomy_args_' . $taxonomy, array(
					'labels'       => array( 'name' => $label ),
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
				) ) );
				return (int) $id;
			}
		}
		// If WC didn't register it, register a minimal taxonomy so terms can be added.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			$slug = str_replace( 'pa_', '', $taxonomy );
			$label = ucfirst( str_replace( array( '-', '_' ), ' ', $slug ) );
			register_taxonomy( $taxonomy, 'product', array(
				'labels'       => array( 'name' => $label ),
				'hierarchical' => false,
				'show_ui'      => false,
				'query_var'    => true,
				'rewrite'      => false,
			) );
		}
		return $this->get_attribute_taxonomy_id( $taxonomy );
	}

	/**
	 * Get WooCommerce global attribute taxonomy ID by name (for set_id on WC_Product_Attribute).
	 *
	 * @param string $taxonomy Taxonomy name (e.g. pa_boja).
	 * @return int Attribute ID or 0.
	 */
	private function get_attribute_taxonomy_id( $taxonomy ) {
		if ( function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
			return (int) wc_attribute_taxonomy_id_by_name( $taxonomy );
		}
		return 0;
	}

	/**
	 * Get or create attribute term by name; return WP_Term or null.
	 *
	 * @param string $taxonomy   Taxonomy (e.g. pa_boja).
	 * @param string $value_name Display name (e.g. Crvena).
	 * @return WP_Term|null
	 */
	private function get_or_create_attribute_term( $taxonomy, $value_name ) {
		$value_name = trim( (string) $value_name );
		if ( $value_name === '' ) {
			return null;
		}
		$slug = sanitize_title( $value_name );
		if ( $slug === '' ) {
			$slug = 'term-' . md5( $value_name );
		}
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term;
		}
		$term = get_term_by( 'name', $value_name, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term;
		}
		$t = wp_insert_term( $value_name, $taxonomy, array( 'slug' => $slug ) );
		if ( is_wp_error( $t ) ) {
			return null;
		}
		return get_term( (int) $t['term_id'], $taxonomy );
	}
}
