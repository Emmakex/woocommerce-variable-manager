<?php
/**
 * Plugin Name: WooCommerce Variable Manager
 * Description: Reusable sleep-product templates and simple, safe size-based price matrices for WooCommerce.
 * Version: 0.1.0
 * Author: Eduardo Yauri
 * License: Proprietary - see LICENSE (third-party components retain their own licenses)
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: woocommerce-variable-manager
 */

defined( 'ABSPATH' ) || exit;

define( 'WVM_VERSION', '0.1.0' );
define( 'WVM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WVM_URL', plugin_dir_url( __FILE__ ) );

add_action( 'before_woocommerce_init', static function (): void {
    if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

add_action( 'plugins_loaded', static function (): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function (): void {
            if ( current_user_can( 'activate_plugins' ) ) {
                echo '<div class="notice notice-error"><p>' .
                    esc_html__( 'WooCommerce Variable Manager requires WooCommerce to be active.', 'woocommerce-variable-manager' ) .
                    '</p></div>';
            }
        } );
        return;
    }

    require_once WVM_PATH . 'includes/class-wvm-templates.php';
    require_once WVM_PATH . 'includes/class-wvm-pricing.php';
    require_once WVM_PATH . 'includes/class-wvm-products.php';
    require_once WVM_PATH . 'includes/class-wvm-storefront.php';

    WVM_Templates::init();
    WVM_Products::init();
    WVM_Storefront::init();
} );
