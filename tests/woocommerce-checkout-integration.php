<?php
/**
 * Synthetic commercial acceptance: real WooCommerce cart, ES 21% VAT,
 * fixed-cart coupon and persistence of actual checkout order lines.
 *
 * Runs only in an ephemeral WordPress/MySQL environment, not on staging or
 * production. No customer PII, order emails, shipping labels or payments.
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Checkout' ) ) {
    throw new RuntimeException( 'Requires a fully booted WooCommerce installation.' );
}

function checkout_assert( bool $condition, string $reason ): void {
    if ( ! $condition ) {
        throw new RuntimeException( 'Synthetic checkout: ' . $reason );
    }
}

wp_set_current_user( 1 );
$fixture = json_decode(
    (string) file_get_contents( __DIR__ . '/fixtures/catalog-synthetic.json' ),
    true, 512, JSON_THROW_ON_ERROR
)['products'][4];

$original_settings = array();
$settings = array(
    'woocommerce_calc_taxes'        => 'yes',
    'woocommerce_prices_include_tax' => 'no',
    'woocommerce_tax_based_on'      => 'base',
    'woocommerce_default_country'   => 'ES:B',
    'woocommerce_currency'          => 'EUR',
);
$template_id = 0;
$product_id = 0;
$coupon_id = 0;
$order_id = 0;
$tax_rate_id = 0;
$old_post = $_POST;

try {
    foreach ( $settings as $key => $value ) {
        $original_settings[ $key ] = get_option( $key, false );
        update_option( $key, $value );
    }
    checkout_assert( wc_tax_enabled(), 'tax calculation not enabled in temporary installation' );

    $tax_rate_id = WC_Tax::_insert_tax_rate( array(
        'tax_rate_country' => 'ES',
        'tax_rate_state' => '',
        'tax_rate' => '21.0000',
        'tax_rate_name' => 'IVA synthetic CI',
        'tax_rate_priority' => 1,
        'tax_rate_compound' => 0,
        'tax_rate_shipping' => 0,
        'tax_rate_order' => 1,
        'tax_rate_class' => '',
    ) );
    checkout_assert( (int) $tax_rate_id > 0, 'could not insert isolated VAT rate' );

    $template_id = wp_insert_post( array(
        'post_type' => 'wvm_template',
        'post_title' => '[CI] Temporary checkout canapé',
        'post_status' => 'publish',
    ), true );
    checkout_assert( ! is_wp_error( $template_id ) && $template_id > 0, 'could not create test template' );
    update_post_meta( (int) $template_id, WVM_Templates::META_KEY, $fixture['options'] );

    $extras = array( 'attr' => array(), 'value' => array(), 'amount' => array() );
    foreach ( $fixture['surcharges'] as $attribute => $values ) {
        foreach ( $values as $name => $value ) {
            $extras['attr'][] = $attribute;
            $extras['value'][] = $name;
            $extras['amount'][] = (string) $value;
        }
    }
    $exclusions = array( 'a_attr' => array(), 'a_value' => array(), 'b_attr' => array(), 'b_value' => array() );
    foreach ( $fixture['exclusions'] as $rule ) {
        foreach ( array_keys( $exclusions ) as $key ) {
            $exclusions[ $key ][] = $rule[ $key ];
        }
    }
    $prepared = WVM_Products::prepare(
        (int) $template_id,
        array_keys( $fixture['matrix'] ), array_values( $fixture['matrix'] ),
        $extras, $exclusions
    );
    checkout_assert( ! is_wp_error( $prepared ), 'could not validate configuration' );
    $product = new WC_Product_Simple();
    $product->set_name( '[CI] Synthetic checkout canapé' );
    $product->set_status( 'publish' );
    $product->set_tax_status( 'taxable' );
    WVM_Products::apply( $product, $prepared );
    $product_id = $product->save();
    checkout_assert( $product_id > 0, 'could not save synthetic product' );

    wc_load_cart();
    WC()->cart->empty_cart();
    WC()->customer->set_billing_country( 'ES' );
    WC()->customer->set_shipping_country( 'ES' );
    WC()->customer->set_billing_state( 'B' );
    WC()->customer->set_shipping_state( 'B' );

    $configurations = array(
        array(
            'selection' => $fixture['sample']['selection'],
            'price' => 579.80,
            'size' => '150x190',
            'color' => 'Antracita',
        ),
        array(
            'selection' => array(
                'size' => '135x190', 'color' => 'Beige',
                'thickness' => '35 cm', 'opening' => 'Frontal',
            ),
            'price' => 439.80,
            'size' => '135x190',
            'color' => 'Beige',
        ),
    );
    foreach ( $configurations as $config ) {
        $_POST = array(
            'wvm_size' => $config['selection']['size'],
            'wvm_color' => $config['selection']['color'],
            'wvm_thickness' => $config['selection']['thickness'],
            'wvm_opening' => $config['selection']['opening'],
        );
        checkout_assert( WVM_Storefront::validate_add( true, $product_id, 1 ), 'valid selection rejected' );
        $key = WC()->cart->add_to_cart( $product_id, 1 );
        checkout_assert( is_string( $key ) && '' !== $key, 'could not add configured item' );
    }

    $coupon = new WC_Coupon();
    $coupon->set_code( 'wvm-synthetic-checkout' );
    $coupon->set_discount_type( 'fixed_cart' );
    $coupon->set_amount( '20' );
    $coupon->set_individual_use( false );
    $coupon_id = $coupon->save();
    checkout_assert( $coupon_id > 0, 'could not create synthetic coupon' );
    checkout_assert( WC()->cart->apply_coupon( 'wvm-synthetic-checkout' ), 'could not apply synthetic coupon' );
    WC()->cart->calculate_totals();

    $items = WC()->cart->get_cart();
    checkout_assert( count( $items ) === 2, 'configured cart lines unexpectedly merged' );
    $unit_prices = array_map( static fn( array $item ): float => (float) $item['data']->get_price(), $items );
    sort( $unit_prices );
    checkout_assert(
        abs( $unit_prices[0] - 439.80 ) < 0.01 && abs( $unit_prices[1] - 579.80 ) < 0.01,
        'selected size/surcharge prices changed after coupon'
    );

    $subtotal = (float) WC()->cart->get_subtotal();
    $discount = (float) WC()->cart->get_discount_total();
    $tax = (float) WC()->cart->get_cart_contents_tax();
    $total = (float) WC()->cart->get_total( 'edit' );
    checkout_assert( abs( $subtotal - 1019.60 ) < 0.01, 'subtotal does not reflect configured prices' );
    checkout_assert( abs( $discount - 20.00 ) < 0.01, 'fixed coupon not applied once to the cart' );
    checkout_assert( $tax > 0 && abs( $tax - ( $subtotal - $discount ) * 0.21 ) < 0.03, '21% Spanish VAT was not applied after discount' );
    checkout_assert( abs( $total - ( $subtotal - $discount + $tax ) ) < 0.03, 'cart total is inconsistent' );

    // Use WooCommerce checkout's actual order-line creation path. No gateway,
    // email or frontend HTTP checkout is triggered in this synthetic test.
    $order = wc_create_order( array( 'status' => 'pending', 'created_via' => 'wvm-synthetic-ci' ) );
    checkout_assert( $order instanceof WC_Order, 'could not create temporary order' );
    $order_id = $order->get_id();
    $order->set_currency( 'EUR' );
    $order->set_discount_total( $discount );
    $order->set_cart_tax( $tax );
    $order->set_total( $total );
    WC()->checkout()->create_order_line_items( $order, WC()->cart );
    $order->save();

    $saved = wc_get_order( $order_id );
    checkout_assert( $saved instanceof WC_Order, 'saved checkout order not readable' );
    $order_items = array_values( $saved->get_items( 'line_item' ) );
    checkout_assert( count( $order_items ) === 2, 'checkout did not persist two product lines' );
    $found = array();
    $lines_total = 0.0;
    foreach ( $order_items as $item ) {
        checkout_assert( $item->get_product_id() === $product_id, 'line item product mismatch' );
        $size = $item->get_meta( 'Medida' );
        $color = $item->get_meta( 'Color' );
        checkout_assert( is_string( $size ) && '' !== $size, 'selected size missing from saved order' );
        checkout_assert( is_string( $color ) && '' !== $color, 'selected color missing from saved order' );
        checkout_assert( $item->get_meta( 'Espesor / altura' ) === '35 cm', 'selected thickness missing' );
        checkout_assert( in_array( $item->get_meta( 'Apertura' ), array( 'Frontal', 'Lateral' ), true ), 'opening missing' );
        $found[ $size ] = $color;
        $lines_total += (float) $item->get_total();
    }
    checkout_assert( ( $found['150x190'] ?? null ) === 'Antracita', 'first configuration not preserved in persisted order' );
    checkout_assert( ( $found['135x190'] ?? null ) === 'Beige', 'second configuration not preserved in persisted order' );
    checkout_assert( abs( $lines_total - ( $subtotal - $discount ) ) < 0.03, 'saved line totals do not include coupon allocation' );

    echo sprintf(
        "WooCommerce synthetic checkout PASS: two configured lines, subtotal %.2f, coupon %.2f, 21%% VAT %.2f, total %.2f, saved order metadata (NO payments).\n",
        $subtotal, $discount, $tax, $total
    );
} finally {
    $_POST = $old_post;
    if ( WC()->cart ) {
        WC()->cart->empty_cart();
        wc_clear_notices();
    }
    if ( $order_id ) {
        $saved = wc_get_order( (int) $order_id );
        if ( $saved ) {
            $saved->delete( true );
        }
    }
    if ( $coupon_id ) {
        $c = new WC_Coupon( (int) $coupon_id );
        $c->delete( true );
    }
    if ( $product_id ) {
        $product = wc_get_product( (int) $product_id );
        if ( $product ) {
            $product->delete( true );
        }
    }
    if ( $template_id && ! is_wp_error( $template_id ) ) {
        wp_delete_post( (int) $template_id, true );
    }
    if ( $tax_rate_id ) {
        WC_Tax::_delete_tax_rate( (int) $tax_rate_id );
    }
    foreach ( $original_settings as $key => $value ) {
        if ( false === $value ) {
            delete_option( $key );
        } else {
            update_option( $key, $value );
        }
    }
}
