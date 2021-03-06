<?php
/**
 * Plugin Name: WooCommerce Cart Notices
 * Plugin URI: http://www.woothemes.com/products/cart-notices/
 * Description: Add dynamic notices above the cart and checkout to help increase your sales!
 * Author: SkyVerge
 * Author URI: http://www.skyverge.com
 * Version: 1.1
 * Text Domain: wc-cart-notices
 * Domain Path: /languages/
 *
 * Copyright: (c) 2012-2013 SkyVerge, Inc. (info@skyverge.com)
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Cart-Notices
 * @author    SkyVerge
 * @category  Plugin
 * @copyright Copyright (c) 2013, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) )
	require_once( 'woo-includes/woo-functions.php' );

/**
 * Plugin updates
 */
woothemes_queue_update( plugin_basename( __FILE__ ), 'cf25b8df1ffe2fa1880b076aa137f8d7', '18706' );

// Check if WooCommerce is active and deactivate extension if it's not
if ( ! is_woocommerce_active() )
	return;

/**
 * The WC_Cart_Notices global object
 * @name $wc_cart_notices
 * @global WC_Cart_Notices $GLOBALS['wc_cart_notices']
 */
$GLOBALS['wc_cart_notices'] = new WC_Cart_Notices();

/**
 * This plugin provides a set of configurable cart notices which can be
 * displayed on the cart/checkout page, or anywhere shortcodes are enabled.
 * This plugin adds a WooCommerce sub menu item named 'Cart Notices.'
 * The following cart notice types are available:
 *
 * * minimum amount - when the cart total is below a threshold
 * * deadline - before a certain time of day
 * * referer - when the visitor originated from a given site
 * * products - when the customer has certain products in their cart
 * * caregories - when the customer has products from certain categories in their cart
 *
 * The notice settings are stored in a custom table named 'cart_notices'.
 * There is a special 'data' column which will contain a serialized array of
 * values which depend on the notice type, this is the data that is specific
 * to each type of notice, and is the following:
 *
 * * minimum amount - 'minimum_order_amount' => float
 * * deadline - 'deadline_hour' => int (1-24), 'deadline_days' => array(0..6 => bool)
 * * referer - 'referer' => string (url)
 * * products - 'product_ids' => array(int)
 * * categories - 'category_ids' => array(int)
 */
class WC_Cart_Notices {

	const VERSION = '1.1';

	const VERSION_OPTION_NAME = "wc_cart_notices_db_version";

	const TEXT_DOMAIN = 'wc-cart-notices';

	/** @var string the plugin path */
	private $plugin_path;

	/**
	 * The plugin's id, used for various slugs and such
	 * @var string
	 */
	public $id = 'wc-cart-notices';

	/**
	 * array of notices objects.  @see WC_Cart_Notices::get_notices()
	 * @var array
	 */
	private $notices;

	/**
	 * @var WP_Admin_Message_Handler wordpress admin message handler class
	 */
	public $admin_message_handler;

	/** @var WC_Cart_Notices_Admin the admin class */
	public $admin;

	/**
	 * Initialize the main plugin class
	 */
	public function __construct() {

		// include required files
		$this->includes();

		// for uninstallation: see uninstall.php

		// store the client's referer, if needed
		add_action( 'woocommerce_init', array( $this, 'store_referer' ) );
		add_action( 'init',             array( $this, 'init' ) );

		// add the notices to the top of the cart/checkout pages
		add_action( 'woocommerce_before_cart_contents', array( $this, 'add_cart_notice' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'add_cart_notice' ) );

		// add the notices shortcodes
		add_shortcode( 'woocommerce_cart_notice', array( $this, 'woocommerce_cart_notices_shortcode' ) );

		// allow shortcodes within notice text
		add_filter( 'woocommerce_cart_notice_minimum_amount_notice', 'do_shortcode' );
		add_filter( 'woocommerce_cart_notice_deadline_notice',       'do_shortcode' );
		add_filter( 'woocommerce_cart_notice_referer_notice',        'do_shortcode' );
		add_filter( 'woocommerce_cart_notice_products_notice',       'do_shortcode' );
		add_filter( 'woocommerce_cart_notice_categories_notice',     'do_shortcode' );

		if ( is_admin() ) {

			// add a 'Configure' link to the plugin action links
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_manage_link' ), 10, 4 );	// remember, __FILE__ derefs symlinks :(

			// Installation/Upgrade
			if ( ! defined( 'DOING_AJAX' ) ) $this->install();
		}
	}


	/**
	 * Include required files
	 *
	 * @since 1.0.7
	 */
	private function includes() {
		if ( is_admin() ) $this->admin_includes();
	}


	/**
	 * Include required admin files
	 *
	 * @since 1.0.7
	 */
	private function admin_includes() {
		require_once( 'classes/class-wp-admin-message-handler.php' );
		$this->admin_message_handler = new WP_Admin_Message_Handler( __FILE__ );

		require_once( 'classes/class-wc-cart-notices-admin.php' );
		$this->admin = new WC_Cart_Notices_Admin();
	}


	/**
	 * Invoked after woocommerce has finished loading, so we know sessions
	 * have been started.
	 */
	public function store_referer() {
		// if the referer notice is enabled, and the referer host does
		//  not match the site, record it in the session
		if ( $this->has_referer_notice() ) {
			$referer_host = parse_url( wp_get_referer(), PHP_URL_HOST );
			if ( $referer_host && $referer_host != parse_url( site_url(), PHP_URL_HOST ) ) {
				$this->session_set( 'wc_cart_notice_referer', $referer_host );
			}
		}
	}


	/**
	 * Init WooCommerce Cart Notices when WordPress initializes
	 */
	public function init() {
		// localisation
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}


	/** Admin ************************************************************/


	/**
	 * Return the plugin action links.  This will only be called if the plugin
	 * is active.
	 *
	 * @param array $actions associative array of action names to anchor tags
	 * @param string $plugin_file plugin file name, ie my-plugin/my-plugin.php
	 * @param array $plugin_data associative array of plugin data from the plugin file headers
	 * @param string $context plugin status context, ie 'all', 'active', 'inactive', 'recently_active'
	 *
	 * @return array associative array of plugin action links
	 */
	public function plugin_manage_link( $actions, $plugin_file, $plugin_data, $context ) {

		// add a 'Configure' link to the front of the actions list for this plugin
		return array_merge( array( 'configure' => '<a href="' . admin_url( 'admin.php?page=' . $this->id ) . '">' . __( 'Configure', self::TEXT_DOMAIN ) . '</a>' ),
			$actions );

	}


	/** Frontend ************************************************************/


	/**
	 * Add any available cart notices
	 *
	 * @return void
	 */
	public function add_cart_notice() {

		$messages = array();

		foreach ( $this->get_notices() as $notice ) {
			// build the notices based on the notice types.  Any notices that require arguments are handled specially
			$args = array();
			if ( 'minimum_amount' == $notice->type ) $args['cart_contents_total'] = $this->get_cart_total();

			if ( $notice->enabled && method_exists( $this, 'get_' . $notice->type . '_notice' ) && ( $message = $this->{'get_' . $notice->type . '_notice'}( $notice, $args ) ) )  $messages[] = $message;
		}

		echo implode( "\n", $messages );
	}


	/** Shortcode ************************************************************/


	/**
	 * WooCommerce Cart Notices shortcode handler
	 *
	 * @param $atts array associative array of shortcode parameters
	 * @return string shortcode content
	 */
	public function woocommerce_cart_notices_shortcode( $atts ) {
		global $woocommerce;

		extract( shortcode_atts( array(
			'type' => '',
			'name' => '',
		), $atts ) );

		if ( ! $type && ! $name ) $type = 'all';

		$messages = array();

		foreach ( $this->get_notices() as $notice ) {

			do_action( 'wc_cart_notices_process_notice_before', $notice );

			if ( 'all' == $type || $type == $notice->type || 0 == strcasecmp( $name, $notice->name ) ) {
				// build the notices based on the notice types.  Any notices that require arguments are handled specially
				$args = array();
				if ( 'minimum_amount' == $notice->type ) {
					$args['cart_contents_total'] = $woocommerce->cart->cart_contents_total;
				}

				if ( $notice->enabled && method_exists( $this, 'get_' . $notice->type . '_notice' ) && ( $message = $this->{'get_' . $notice->type . '_notice'}( $notice, $args ) ) ) {
					$messages[] = $message;
				}
			}
		}

		return implode( "\n", $messages );
	}


	/** Helper methods ******************************************************/


	/**
	 * Gets the absolute plugin path without a trailing slash, e.g.
	 * /path/to/wp-content/plugins/plugin-directory
	 *
	 * @since 1.0.7
	 * @return string plugin path
	 */
	public function get_plugin_path() {

		if ( $this->plugin_path )
			return $this->plugin_path;

		return $this->plugin_path = untrailingslashit( plugin_dir_path( __FILE__ ) );
	}


	/**
	 * Gets the cart contents total (after calculation).
	 *
	 * @return string formatted price
	 */
	private function get_cart_total() {
		global $woocommerce;

		if ( ! $woocommerce->cart->prices_include_tax ) {
			// if prices don't include tax, just return the total
			$cart_contents_total = $woocommerce->cart->cart_contents_total;
		} else {
			// if prices do include tax, add the tax amount back in
			$cart_contents_total = $woocommerce->cart->cart_contents_total + $woocommerce->cart->tax_total;
		}

		return $cart_contents_total;
	}


	/**
	 * Returns the minimum amount cart notice HTML snippet
	 *
	 * TODO: Perhaps I should be checking the cart total excluding taxes.  Though, is this impossible if prices include taxes?
	 *
	 * @param object $notice the notice settings object
	 * @param array associative array of parameters, 'cart_contents_total' is required
	 *
	 * @return string minimum amount cart notice
	 */
	public function get_minimum_amount_notice( $notice, $args ) {

		// get the minimum order amount
		$minimum_order_amount = $this->get_minimum_order_amount( $notice );

		$threshold_order_amount = isset( $notice->data['threshold_order_amount'] ) ? $notice->data['threshold_order_amount'] : null;

		$order_thresholds = array(
			'minimum_order_amount' => $minimum_order_amount,
			'threshold_order_amount' => $threshold_order_amount,
		);

		$order_thresholds = apply_filters( 'wc_cart_notices_order_thresholds', $order_thresholds, $notice, $args );

		$minimum_order_amount = $order_thresholds['minimum_order_amount'];
		$threshold_order_amount = $order_thresholds['threshold_order_amount'];

		// misconfigured?
		if ( ! $notice->message ) return false;

		// they already meet the minimum order amount
		if ( is_numeric( $minimum_order_amount ) && $args['cart_contents_total'] >= $minimum_order_amount ) return false;

		// if they're below the thereshold order amount, bail with no notice
		if ( is_numeric( $threshold_order_amount ) && $args['cart_contents_total'] < $threshold_order_amount ) return false;

		$message = $notice->message;

		// get the minimum amount notice message, with the amount required, if needed
		$amount_under = woocommerce_price( $minimum_order_amount - $args['cart_contents_total'] );
		if ( false !== strpos( $message, '{amount_under}' ) ) {
			$message = str_replace( '{amount_under}', $amount_under,  $message );
		}

		// add the call to action button/text if used
		$action = '';
		if ( $notice->action && $notice->action_url ) {
			$action = ' <a class="button" href="' . esc_url( $notice->action_url ) . '">' . esc_html__( $notice->action, self::TEXT_DOMAIN ) . '</a>';
		}

		// add the message variables for the benefit of the filter
		$args['amount_under'] = $amount_under;

		// return the notice
		return apply_filters( 'woocommerce_cart_notice_minimum_amount_notice', '<div id="woocommerce-cart-notice-' . sanitize_title( $notice->name ) . '" class="woocommerce-cart-notice woocommerce-cart-notice-minimum-amount woocommerce-info">' . wp_kses_post( $message ) . $action . '</div>', $notice, $args );
	}


	/**
	 * Returns the deadline notice based on the current time and configuration
	 *
	 * @param object $notice the notice settings object
	 *
	 * @return string deadline notice snippet, or false if there is no
	 *         deadline notice at this time
	 */
	public function get_deadline_notice( $notice ) {

		// misconfigured?
		if ( ! $notice->message || ( false !== strpos( $notice->message, '{time}' ) && ! $notice->data['deadline_hour'] ) ) return false;

		$current_time = current_time( 'timestamp' );

		// enabled for today?
		$day_of_week = date( 'w', $current_time );
		if ( ! isset( $notice->data['deadline_days'][ $day_of_week ] ) || ! $notice->data['deadline_days'][ $day_of_week ] ) return false;

		$message = $notice->message;

		// get the deadline notice message, with the time remaining
		$minutes_of_day = (int) date( 'G', $current_time ) * 60 + (int) date( 'i', $current_time );
		$deadline_minutes = $notice->data['deadline_hour'] * 60;

		// already past the deadline?
		if ( $minutes_of_day > $deadline_minutes ) return false;

		$minutes_remaining = $deadline_minutes - $minutes_of_day;
		$hours = floor( $minutes_remaining / 60 );
		$minutes = $minutes_remaining % 60;

		// format the string
		$deadline_amount = '';
		if ( $hours )   $deadline_amount .= sprintf( _n( '%d hour', '%d hours', $hours, self::TEXT_DOMAIN ), $hours );
		if ( $minutes ) $deadline_amount .= ( $deadline_amount ? ' ' : '' ) . sprintf( _n( '%d minute', '%d minutes', $minutes, self::TEXT_DOMAIN ), $minutes );

		// add the time remaining, if required
		if ( false !== strpos( $message, '{time}' ) ) {
			$message = str_replace( '{time}', $deadline_amount,  $message );
		}

		// add the call to action button/text if used
		$action = '';
		if ( $notice->action && $notice->action_url ) {
			$action = ' <a class="button" href="' . esc_url( $notice->action_url ) . '">' . esc_html__( $notice->action, self::TEXT_DOMAIN ) . '</a>';
		}

		// add the message variables for the benefit of the filter
		$args['time']              = $deadline_amount;   // the formatted string
		$args['minutes_remaining'] = $minutes_remaining; // the number of minutes, for more advanced usage

		// return the notice
		return apply_filters( 'woocommerce_cart_notice_deadline_notice', '<div id="woocommerce-cart-notice-' . sanitize_title( $notice->name ) . '" class="woocommerce-cart-notice woocommerce-cart-notice-deadline woocommerce-info">' . wp_kses_post( $message ) . $action . '</div>', $notice, $args );
	}


	/**
	 * Returns the referer cart notice HTML snippet
	 *
	 * @param object $notice the notice settings object
	 *
	 * @return string referer cart notice
	 */
	public function get_referer_notice( $notice ) {

		// get the referer
		if ( ! $this->session_get( 'wc_cart_notice_referer' ) ) return false;
		$client_referer_host = $this->session_get( 'wc_cart_notice_referer' );

		// misconfigured?
		if ( ! $notice->message || ! $notice->data['referer'] ) return false;

		$referer = strpos( $notice->data['referer'], '://' ) === false ? 'http://' . $notice->data['referer'] : $notice->data['referer'];
		$referer_host = parse_url( $referer, PHP_URL_HOST );

		// referer matches?
		if ( $client_referer_host !== $referer_host ) return false;

		$message = $notice->message;

		// add the call to action button/text if used
		$action = '';
		if ( $notice->action && $notice->action_url ) {
			$action = ' <a class="button" href="' . esc_url( $notice->action_url ) . '">' . esc_html__( $notice->action, self::TEXT_DOMAIN ) . '</a>';
		}

		// return the notice (simple message, no args for this one)
		return apply_filters( 'woocommerce_cart_notice_referer_notice', '<div id="woocommerce-cart-notice-' . sanitize_title( $notice->name ) . '" class="woocommerce-cart-notice woocommerce-cart-notice-referer woocommerce-info">' . wp_kses_post( $message ) . $action . '</div>', $notice );
	}


	/**
	 * Returns the products cart notice HTML snippet
	 *
	 * @param object $notice the notice settings object
	 *
	 * @return string products cart notice
	 */
	public function get_products_notice( $notice ) {

		global $woocommerce;

		// anything in the cart?
		if ( empty( $woocommerce->cart->cart_contents ) ) return false;

		// misconfigured?
		if ( ! $notice->message || empty( $notice->data['product_ids'] ) ) return false;

		// are any of the selected products in the cart?
		$found_product_titles = array();
		$the_products = array();
		$product_quantity = 0;

		foreach ( $notice->data['product_ids'] as $product_id ) {
			foreach ( $woocommerce->cart->cart_contents as $cart_item ) {

				// check by main product id as well as variation id (if available).  That way
				//  a message can be set for a whole set of variable products, or for one individually
				$_product_id = $cart_item['product_id'];
				$_variation_id = isset( $cart_item['variation_id'] ) && $cart_item['variation_id'] ? $cart_item['variation_id'] : null;

				if ( $product_id == $_product_id || $product_id == $_variation_id ) {
					$found_product_titles[ $_product_id ] = $cart_item['data']->get_title();
					$the_products[] = $cart_item['data'];
					$product_quantity += $cart_item['quantity'];
				}
			}
		}

		if ( empty( $found_product_titles ) ) return false;

		// any minimum/maximum quantity rules?
		$quantity_met = true;

		if ( isset( $notice->data['minimum_quantity'] ) && is_numeric( $notice->data['minimum_quantity'] ) && $product_quantity < $notice->data['minimum_quantity'] ) {
			$quantity_met = false;
		}
		if ( isset( $notice->data['maximum_quantity'] ) && is_numeric( $notice->data['maximum_quantity'] ) && $product_quantity > $notice->data['maximum_quantity'] ) {
			$quantity_met = false;
		}
		if ( ! $quantity_met ) {
			return false;
		}

		$shipping_country_code = '';
		$shipping_country_name = '';
		if ( isset( $notice->data['shipping_countries'] ) && $notice->data['shipping_countries'] &&
		  isset( $woocommerce->customer ) && $woocommerce->customer && $woocommerce->customer->get_shipping_country() ) {
			if ( ! in_array( $woocommerce->customer->get_shipping_country(), $notice->data['shipping_countries'] ) ) {
				return false;
			} else {
				// grab the matching country code/name
				$shipping_country_code = $woocommerce->customer->get_shipping_country();
				$shipping_country_name = isset( $woocommerce->countries->countries[ $woocommerce->customer->get_shipping_country() ] ) ? $woocommerce->countries->countries[ $woocommerce->customer->get_shipping_country() ] : $shipping_country_code;
			}
		}

		$message = $notice->message;

		// get the products notice message, with the list of products, if needed
		$products = implode( ', ', $found_product_titles );
		if ( false !== strpos( $message, '{products}' ) ) {
			$message = str_replace( '{products}', $products,  $message );
		}
		if ( false !== strpos( $message, '{shipping_country_code}' ) ) {
			$message = str_replace( '{shipping_country_code}', $shipping_country_code,  $message );
		}
		if ( false !== strpos( $message, '{shipping_country_name}' ) ) {
			$message = str_replace( '{shipping_country_name}', $shipping_country_name,  $message );
		}
		if ( false !== strpos( $message, '{quantity}' ) ) {
			$message = str_replace( '{quantity}', $product_quantity,  $message );
		}
		if ( false !== strpos( $message, '{quantity_under}' ) ) {
			$quantity_under = isset( $notice->data['maximum_quantity'] ) && '' !== $notice->data['maximum_quantity'] ? $notice->data['maximum_quantity'] - $product_quantity + 1 : '';
			if ( $quantity_under < 0 )
				$quantity_under = '';
			$message = str_replace( '{quantity_under}', $quantity_under,  $message );
		}
		if ( false !== strpos( $message, '{quantity_over}' ) ) {
			$quantity_over = isset( $notice->data['minimum_quantity'] ) && '' !== $notice->data['minimum_quantity'] ? $product_quantity - $notice->data['minimum_quantity'] + 1 : '';
			if ( $quantity_over < 0 )
				$quantity_over = '';
			$message = str_replace( '{quantity_over}', $quantity_over,  $message );
		}

		// add the call to action button/text if used
		$action = '';
		if ( $notice->action && $notice->action_url ) {
			$action = ' <a class="button" href="' . esc_url( $notice->action_url ) . '">' . esc_html__( $notice->action, self::TEXT_DOMAIN ) . '</a>';
		}

		// add the message variables for the benefit of the filter
		$args['products']     = $products;     // the formatted string
		$args['the_products'] = $the_products; // the product objects, for more advanced usage
		$args['shipping_country_code'] = $shipping_country_code;
		$args['shipping_country_name'] = $shipping_country_name;

		// return the notice
		return apply_filters( 'woocommerce_cart_notice_products_notice', '<div id="woocommerce-cart-notice-' . sanitize_title( $notice->name ) . '" class="woocommerce-cart-notice woocommerce-cart-notice-products woocommerce-info">' . wp_kses_post( $message ) . $action . '</div>', $notice, $args );
	}


	/**
	 * Returns the categories cart notice HTML snippet
	 *
	 * @param object $notice the notice settings object
	 *
	 * @return string categories cart notice
	 */
	public function get_categories_notice( $notice ) {

		global $woocommerce;

		// anything in the cart?
		if ( empty( $woocommerce->cart->cart_contents ) ) return false;

		// misconfigured?
		if ( ! $notice->message || empty( $notice->data['category_ids'] ) ) return false;

		// are any of the selected categories in the cart?
		$found_category_ids = array();
		$product_names      = array();
		$the_products       = array();

		foreach ( $notice->data['category_ids'] as $category_id ) {
			foreach ( $woocommerce->cart->cart_contents as $cart_item ) {
				if ( has_term( $category_id, 'product_cat', $cart_item['product_id'] ) ) {
					if ( ! in_array( $category_id, $found_category_ids ) ) $found_category_ids[] = $category_id;
					$product_names[ $cart_item['product_id'] ] = $cart_item['data']->get_title();
					$the_products[] = $cart_item['data'];
				}
			}
		}
		if ( empty( $found_category_ids ) ) return false;

		$message = $notice->message;

		// get the categories notice message, with the list of products, if needed
		$products = implode( ', ', $product_names );
		if ( false !== strpos( $message, '{products}' ) ) {
			$message = str_replace( '{products}', $products,  $message );
		}

		// get the categories notice message, with the list of categories, if needed
		$category_names = array();
		$the_categories = array();
		foreach ( $found_category_ids as $category_id ) {
			$category = get_term( $category_id, 'product_cat' );
			$category_names[] = $category->name;
			$the_categories[] = $category;
		}
		$category_names = array_unique( $category_names );
		$categories = implode( ', ', $category_names );
		if ( strpos( $message, '{categories}' ) !== false ) {
			$message = str_replace( '{categories}', $categories,  $message );
		}

		// add the call to action button/text if used
		$action = '';
		if ( $notice->action && $notice->action_url ) {
			$action = ' <a class="button" href="' . esc_url( $notice->action_url ) . '">' . esc_html__( $notice->action, self::TEXT_DOMAIN ) . '</a>';
		}

		// add the message variables for the benefit of the filter
		$args['products']       = $products;       // the formatted string
		$args['the_products']   = $the_products;   // the product objects, for more advanced usage
		$args['categories']     = $categories;     // the formatted string
		$args['the_categories'] = $the_categories; // the category objects, for more advanced usage

		// return the notice
		return apply_filters( 'woocommerce_cart_notice_categories_notice', '<div id="woocommerce-cart-notice-' . sanitize_title( $notice->name ) . '" class="woocommerce-cart-notice woocommerce-cart-notice-categories woocommerce-info">' . wp_kses_post( $message ) . $action . '</div>', $notice, $args );
	}


	/**
	 * Get the minimum order amount.  This is returned from the Cart
	 * Notices plugin settings, if set, otherwise it is returned from
	 * the Free Shipping gateway if enabled and configured.
	 *
	 * @param object $notice the notice settings object
	 *
	 * @return float minimum order amount configured, for free shipping, or false otherwise
	 */
	public function get_minimum_order_amount( $notice ) {
		global $woocommerce;

		// configured minimum order amount?
		if ( $notice->data['minimum_order_amount'] ) {
			return $notice->data['minimum_order_amount'];
		}

		// load the shipping methods if not already available
		if ( 0 == count( $shipping_methods = $woocommerce->shipping->get_shipping_methods() ) ) {
			$shipping_methods = $woocommerce->shipping->load_shipping_methods();
		}

		// minimum order amount set for free shipping method?
		foreach ( $shipping_methods as $method ) {
			if ( "free_shipping" == $method->id && "yes" == $method->enabled && isset( $method->min_amount ) && $method->min_amount ) {
				return $method->min_amount;
			}
		}

		// no minimum amount configured, return false
		return false;
	}


	/**
	 * Load any notices from the database table and into the notices
	 * member
	 *
	 * @return array of notice objects
	 */
	public function get_notices() {
		global $wpdb;

		if ( ! $this->notices ) {

			$results = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}cart_notices ORDER BY name ASC" );

			foreach ( $results as $key => $result ) {
				$results[ $key ]->data = maybe_unserialize( $results[ $key ]->data );
			}

			$this->notices = $results;
		}

		return $this->notices;
	}


	/**
	 * Returns true if at least one referer notice is enabled
	 *
	 * @return boolean true if at least one referer notice is enabled, false otherwise
	 */
	private function has_referer_notice() {
		foreach ( $this->get_notices() as $notice ) {
			if ( 'referer' == $notice->type && $notice->enabled ) return true;
		}
		return false;
	}


	/** Helper methods ******************************************************/


	/**
	 * Safely store data into the session.  Compatible with WC 2.0 and
	 * backwards compatible with previous versions.
	 *
	 * @param string $name the name
	 * @param mixed $value the value to set
	 */
	private function session_set( $name, $value ) {
		global $woocommerce;

		if ( isset( $woocommerce->session ) ) {
			// WC 2.0
			$woocommerce->session->$name = $value;
		} else {
			// old style
			$_SESSION[ $name ] = $value;
		}
	}


	/**
	 * Safely retrieve data from the session.  Compatible with WC 2.0 and
	 * backwards compatible with previous versions.
	 *
	 * @param string $name the name
	 * @return mixed the data, or null
	 */
	private function session_get( $name ) {
		global $woocommerce;

		if ( isset( $woocommerce->session ) ) {
			// WC 2.0
			if ( isset( $woocommerce->session->$name ) ) return $woocommerce->session->$name;
		} else {
			// old style
			if ( isset( $_SESSION[ $name ] ) ) return $_SESSION[ $name ];
		}
	}


	/** Lifecycle methods ******************************************************/


	/**
	 * Run every time.  Used since the activation hook is not executed when updating a plugin
	 */
	private function install() {
		global $wpdb;

		$wpdb->hide_errors();

		$installed_version = get_option( self::VERSION_OPTION_NAME );

		if ( ! $installed_version ) {
			// initial install
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

			$collate = '';
			if ( $wpdb->has_cap( 'collation' ) ) {
				if ( ! empty( $wpdb->charset ) ) $collate .= "DEFAULT CHARACTER SET $wpdb->charset";
				if ( ! empty( $wpdb->collate ) ) $collate .= " COLLATE $wpdb->collate";
			}

			$table = $wpdb->prefix . 'cart_notices';
			$sql =
			  "CREATE TABLE $table (
			  id bigint(20) NOT NULL AUTO_INCREMENT,
			  name varchar(100) NOT NULL,
			  enabled boolean NOT NULL default false,
			  type varchar(50) NOT NULL,
			  message TEXT NOT NULL,
			  action varchar(256) NOT NULL,
			  action_url varchar(256) NOT NULL,
			  data TEXT NOT NULL,
			  date_added DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			  PRIMARY KEY  (id)
			) " . $collate;
			dbDelta( $sql );

			// version number
			add_option( self::VERSION_OPTION_NAME, self::VERSION );
			$installed_version = self::VERSION;
		}

		// installed version lower than plugin version?
		if ( -1 === version_compare( $installed_version, self::VERSION ) ) {
			$this->upgrade( $installed_version );

			// new version number
			update_option( self::VERSION_OPTION_NAME, self::VERSION );
		}
	}


	/**
	 * Run when plugin version number changes
	 */
	private function upgrade( $installed_version ) {
		global $wpdb;

		if ( -1 === version_compare( $installed_version, "1.0.3" ) ) {
			// if installed version is less than 1.0.3, make the table charset utf8 to support non-latin languages
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}cart_notices CONVERT TO CHARACTER SET utf8" );
		}
	}

} // WC_Cart_Notices
