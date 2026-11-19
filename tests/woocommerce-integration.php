<?php
/**
 * Run only inside disposable WordPress + WooCommerce using:
 * wp eval-file tests/woocommerce-integration.php --path=/tmp/...
 *
 * Creates real WordPress/WooCommerce synthetic rows, validates and removes them.
 * Does not create customers, payments or orders.
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Product_Simple' ) ) {
    throw new RuntimeException( 'Integration test requires fully booted WordPress + WooCommerce.' );
}

function integration_assert( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

$catalog = json_decode(
    (string) file_get_contents( __DIR__ . '/fixtures/catalog-synthetic.json' ),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$fixture = $catalog['products'][4]; // Canapé Tapizado Élite: multiple surcharges + two exclusions.
$template_id = 0;
$product_id  = 0;

try {
    integration_assert( class_exists( 'WVM_Dashboard' ), 'Dashboard did not load from bootstrap' );
    integration_assert( class_exists( 'WVM_Storefront' ), 'Storefront integration did not load' );
    integration_assert( class_exists( 'WVM_Rules' ), 'Rules engine not loaded' );

    $template_id = wp_insert_post( array(
        'post_type'   => 'wvm_template',
        'post_status' => 'publish',
        'post_title'  => '[TEST] Temporary synthetic canapé',
    ), true );
    integration_assert( ! is_wp_error( $template_id ) && (int) $template_id > 0, 'Could not persist template' );
    update_post_meta( (int) $template_id, WVM_Templates::META_KEY, $fixture['options'] );
    integration_assert( count( WVM_Templates::active_options( (int) $template_id )['size'] ) === 4, 'Template not readable' );

    $attrs = $values = $amounts = array();
    foreach ( $fixture['surcharges'] as $attr => $list ) {
        foreach ( $list as $value => $amount ) {
            $attrs[] = $attr;
            $values[] = $value;
            $amounts[] = $amount;
        }
    }
    $ruleAttrsA = $ruleValuesA = $ruleAttrsB = $ruleValuesB = array();
    foreach ( $fixture['exclusions'] as $rule ) {
        $ruleAttrsA[]  = $rule['a_attr'];
        $ruleValuesA[] = $rule['a_value'];
        $ruleAttrsB[]  = $rule['b_attr'];
        $ruleValuesB[] = $rule['b_value'];
    }
    $prepared = WVM_Products::prepare(
        (int) $template_id, array_keys( $fixture['matrix'] ), array_values( $fixture['matrix'] ),
        array( 'attr' => $attrs, 'value' => $values, 'amount' => $amounts ),
        array( 'a_attr' => $ruleAttrsA, 'a_value' => $ruleValuesA, 'b_attr' => $ruleAttrsB, 'b_value' => $ruleValuesB )
    );
    integration_assert( ! is_wp_error( $prepared ), 'WooCommerce price preparation failed' );

    $product = new WC_Product_Simple();
    $product->set_name( '[TEST] Canapé sintético temporal' );
    $product->set_status( 'publish' ); // Disposable CI site only, never the staging demo seed.
    WVM_Products::apply( $product, $prepared );
    $product_id = $product->save();
    integration_assert( (int) $product_id > 0, 'WooCommerce product CRUD save failed' );

    $loaded = wc_get_product( $product_id );
    integration_assert( $loaded instanceof WC_Product_Simple, 'WooCommerce product CRUD load failed' );
    integration_assert(
        abs( (float) $loaded->get_regular_price() - 329.90 ) < 0.001,
        'Parent minimum price mismatch: got ' . var_export( $loaded->get_regular_price(), true )
    );
    $configuration = WVM_Products::configuration( $loaded );
    integration_assert( is_array( $configuration ), 'Configured product not readable' );
    integration_assert( count( $configuration['prices'] ) === 4, 'Price matrix not persisted' );
    integration_assert( count( $configuration['exclusions'] ) === 2, 'Incompatibilities not persisted' );

    $selection = $fixture['sample']['selection'];
    $computed = WVM_Rules::total( $configuration['prices'], $configuration['surcharges'], $selection );
    integration_assert( number_format( (float) $computed, 2, '.', '' ) === '579.80', 'Synthetic WooCommerce amount mismatch' );
    integration_assert( ! WVM_Rules::allowed( $fixture['blocked'], $configuration['exclusions'] ), 'Forbidden configuration accepted' );

    // Full cart submission and order item metadata can be exercised without a gateway.
    $_POST = array(
        'wvm_size'      => $selection['size'],
        'wvm_color'     => $selection['color'],
        'wvm_thickness' => $selection['thickness'],
        'wvm_opening'   => $selection['opening'],
    );
    $cartData = WVM_Storefront::cart_data( array(), $product_id, 0 );
    integration_assert( isset( $cartData['wvm_config']['selection'] ), 'Cart selection was not captured' );
    wc_load_cart();
    WC()->cart->empty_cart();
    $cart_key = WC()->cart->add_to_cart( (int) $product_id, 1 );
    integration_assert( is_string( $cart_key ) && '' !== $cart_key, 'WooCommerce real add-to-cart failed' );
    WC()->cart->calculate_totals();
    $cart_items = WC()->cart->get_cart();
    integration_assert( isset( $cart_items[ $cart_key ] ), 'Real cart item missing' );
    integration_assert( isset( $cart_items[ $cart_key ]['wvm_config']['selection'] ), 'Real cart lost configuration' );
    integration_assert(
        abs( (float) $cart_items[ $cart_key ]['data']->get_price() - 579.80 ) < 0.001,
        'Real WooCommerce cart price mismatch'
    );
    // A second valid configuration must produce a distinct item, not overwrite the first.
    $_POST = array(
        'wvm_size'      => '135x190',
        'wvm_color'     => 'Beige',
        'wvm_thickness' => '35 cm',
        'wvm_opening'   => 'Frontal',
    );
    $second_key = WC()->cart->add_to_cart( (int) $product_id, 1 );
    integration_assert( is_string( $second_key ) && $second_key !== $cart_key, 'Distinct configurations merged in cart' );
    WC()->cart->calculate_totals();
    $items = WC()->cart->get_cart();
    integration_assert( count( $items ) === 2, 'Expected two distinct configured cart lines' );
    integration_assert( abs( (float) $items[ $cart_key ]['data']->get_price() - 579.80 ) < 0.001, 'First cart line price changed unexpectedly' );
    integration_assert( abs( (float) $items[ $second_key ]['data']->get_price() - 439.80 ) < 0.001, 'Second cart line price is incorrect' );

    $_POST = array(
        'wvm_size'      => '90x190',
        'wvm_color'     => 'Beige',
        'wvm_thickness' => '35 cm',
        'wvm_opening'   => 'Lateral',
    );
    integration_assert(
        ! WVM_Storefront::validate_add( true, (int) $product_id, 1 ),
        'Classic add-to-cart validation accepted a forbidden option pair'
    );
    // The low-level WooCommerce API may return a cart key even though our
    // defense-in-depth insertion hook removes the invalid item immediately.
    WC()->cart->add_to_cart( (int) $product_id, 1 );
    integration_assert(
        count( WC()->cart->get_cart() ) === 2,
        'Programmatic invalid insertion was not immediately removed'
    );
    WC()->cart->calculate_totals();
    integration_assert( count( WC()->cart->get_cart() ) === 2, 'Invalid cart survived totals calculation' );
    WC()->cart->empty_cart();
    $orderItem = new WC_Order_Item_Product();
    WVM_Storefront::order_meta( $orderItem, 'synthetic', $cartData, new WC_Order() );
    integration_assert( $orderItem->get_meta( 'Medida' ) === '150x190', 'Order-line measure not preserved' );
    integration_assert( $orderItem->get_meta( 'Color' ) === 'Antracita', 'Order-line color not preserved' );

    // Tampered stored configuration must make the product unavailable.
    $loaded->update_meta_data( WVM_Rules::SURCHARGES_META, array( 'color' => array( 'Morado inexistente' => '10' ) ) );
    $loaded->save();
    $tampered = wc_get_product( $product_id );
    integration_assert( null === WVM_Products::configuration( $tampered ), 'Tampered metadata not rejected' );
    integration_assert( false === WVM_Storefront::purchasable( true, $tampered ), 'Tampered product remains purchasable' );

    echo "Real WordPress + WooCommerce synthetic integration PASS: template, CRUD product, pricing, rules, cart metadata, order metadata, tamper guard.\n";
} finally {
    if ( $product_id ) {
        $cleanup = wc_get_product( (int) $product_id );
        if ( $cleanup ) {
            $cleanup->delete( true );
        }
    }
    if ( $template_id && ! is_wp_error( $template_id ) ) {
        wp_delete_post( (int) $template_id, true );
    }
}
