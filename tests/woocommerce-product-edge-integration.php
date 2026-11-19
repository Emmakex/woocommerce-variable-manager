<?php
/**
 * Real WooCommerce product-creation edge cases on disposable CI WordPress.
 * Exercises services, CRUD, duplicate SKUs, stock and template mutation.
 * No live orders, payments, products or customer data are used.
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Product_Simple' ) ) {
    throw new RuntimeException( 'Requires WordPress + WooCommerce.' );
}
wp_set_current_user( 1 );

function edge_assert( bool $condition, string $description ): void {
    if ( ! $condition ) {
        throw new RuntimeException( 'Product edge test: ' . $description );
    }
}
$fixture = json_decode(
    (string) file_get_contents( __DIR__ . '/fixtures/catalog-synthetic.json' ), true, 512, JSON_THROW_ON_ERROR
)['products'][3];
$template_id = 0;
$draft_id = 0;
$product_ids = array();
$original_post = $_POST;

try {
    $template_id = wp_insert_post( array(
        'post_type' => 'wvm_template',
        'post_title' => '[CI] Edge-case configurable canopy',
        'post_status' => 'publish',
    ), true );
    edge_assert( ! is_wp_error( $template_id ) && $template_id > 0, 'template creation' );
    update_post_meta( $template_id, WVM_Templates::META_KEY, $fixture['options'] );
    $sizes = array_keys( $fixture['matrix'] );
    $prices = array_values( $fixture['matrix'] );
    $extra = array( 'attr' => array(), 'value' => array(), 'amount' => array() );
    foreach ( $fixture['surcharges'] as $attr => $values ) {
        foreach ( $values as $value => $amount ) {
            $extra['attr'][] = $attr;
            $extra['value'][] = $value;
            $extra['amount'][] = (string) $amount;
        }
    }
    $rules = array( 'a_attr' => array(), 'a_value' => array(), 'b_attr' => array(), 'b_value' => array() );
    foreach ( $fixture['exclusions'] as $rule ) {
        foreach ( array_keys( $rules ) as $key ) {
            $rules[ $key ][] = $rule[ $key ];
        }
    }
    $prepared = WVM_Products::prepare( $template_id, $sizes, $prices, $extra, $rules );
    edge_assert( ! is_wp_error( $prepared ), 'valid product preparation' );
    edge_assert( is_wp_error( WVM_Products::prepare( 999999, $sizes, $prices ) ), 'missing template rejected' );

    $expect_invalid = array(
        'empty size' => array( $sizes, array( $prices[0], '', $prices[2] ) ),
        'unknown size' => array( array( '999x999', $sizes[1], $sizes[2] ), $prices ),
        'duplicate size' => array( array( $sizes[0], $sizes[0], $sizes[2] ), $prices ),
        'missing matrix row' => array( array_slice( $sizes, 0, 2 ), array_slice( $prices, 0, 2 ) ),
        'negative price' => array( $sizes, array( '-1', $prices[1], $prices[2] ) ),
        'zero price' => array( $sizes, array( '0', $prices[1], $prices[2] ) ),
        'exponent number' => array( $sizes, array( '1e9', $prices[1], $prices[2] ) ),
        'excess precision' => array( $sizes, array( '10.12345', $prices[1], $prices[2] ) ),
        'non numeric' => array( $sizes, array( 'free', $prices[1], $prices[2] ) ),
    );
    foreach ( $expect_invalid as $case => $values ) {
        edge_assert( is_wp_error( WVM_Products::prepare( $template_id, $values[0], $values[1] ) ), $case . ' should fail' );
    }

    $bad_extra = $extra;
    $bad_extra['amount'][0] = '-25';
    edge_assert( is_wp_error( WVM_Products::prepare( $template_id, $sizes, $prices, $bad_extra, $rules ) ), 'negative surcharge rejected' );
    $bad_extra = $extra;
    $bad_extra['value'][0] = 'Unlisted color';
    edge_assert( is_wp_error( WVM_Products::prepare( $template_id, $sizes, $prices, $bad_extra, $rules ) ), 'unknown option rejected' );
    $bad_rules = $rules;
    $bad_rules['b_attr'][0] = 'size';
    edge_assert( is_wp_error( WVM_Products::prepare( $template_id, $sizes, $prices, $extra, $bad_rules ) ), 'invalid same-attribute exclusion rejected' );

    $product = new WC_Product_Simple();
    $product->set_name( '[CI] Synthetic edge test canopy' );
    $product->set_sku( 'SYN-EDGE-CANOPY-001' );
    $product->set_status( 'draft' );
    $product->set_manage_stock( true );
    $product->set_stock_quantity( 3 );
    $product->set_description( 'Synthetic materials and dimensions.' );
    WVM_Products::apply( $product, $prepared );
    $id = $product->save();
    $product_ids[] = $id;
    edge_assert( $id > 0, 'saving draft product' );
    $persisted = wc_get_product( $id );
    edge_assert( $persisted instanceof WC_Product_Simple, 'read product' );
    edge_assert( 'draft' === $persisted->get_status(), 'draft remains draft' );
    edge_assert( $persisted->get_stock_quantity() === 3, 'quantity preserved' );
    edge_assert( $persisted->get_sku() === 'SYN-EDGE-CANOPY-001', 'SKU preserved' );
    edge_assert( count( WVM_Products::configuration( $persisted )['prices'] ) === 3, 'all size prices persisted' );

    // Duplicate SKU must fail in WooCommerce CRUD without creating a second product.
    $duplicate_rejected = false;
    try {
        $duplicate = new WC_Product_Simple();
        $duplicate->set_name( '[CI] Duplicate SKU must fail' );
        $duplicate->set_sku( 'SYN-EDGE-CANOPY-001' );
        $duplicate->set_regular_price( '10' );
        $duplicate->save();
        // Defensive cleanup if a regression somehow allows duplicate data.
        if ( $duplicate->get_id() ) {
            $product_ids[] = $duplicate->get_id();
        }
    } catch ( WC_Data_Exception $exception ) {
        $duplicate_rejected = true;
    }
    edge_assert( $duplicate_rejected, 'duplicate SKU not rejected by WooCommerce' );

    // Published but out-of-stock product cannot be sold.
    $persisted->set_status( 'publish' );
    $persisted->set_stock_quantity( 0 );
    $persisted->save();
    $reloaded = wc_get_product( $id );
    edge_assert( $reloaded->get_stock_status() === 'outofstock', 'quantity zero sets out-of-stock' );
    edge_assert( ! $reloaded->is_purchasable() || ! $reloaded->is_in_stock(), 'out of stock should not be sellable' );

    // Stale option lists must fail closed, even after an external mutation.
    $before_options = WVM_Templates::options( $template_id );
    $broken = $before_options;
    $broken['color'] = array( 'Unknown new color' );
    update_post_meta( $template_id, WVM_Templates::META_KEY, $broken );
    edge_assert( null === WVM_Products::configuration( wc_get_product( $id ) ), 'changed template invalidates stale extras/rules' );
    update_post_meta( $template_id, WVM_Templates::META_KEY, $before_options );
    edge_assert( is_array( WVM_Products::configuration( wc_get_product( $id ) ) ), 'restoring template recovers product' );

    wp_update_post( array( 'ID' => $template_id, 'post_status' => 'draft' ) );
    edge_assert( null === WVM_Products::configuration( wc_get_product( $id ) ), 'unpublished template disables product configuration' );
    wp_update_post( array( 'ID' => $template_id, 'post_status' => 'publish' ) );

    // No attempt to convert native WC variable products into our managed simple products.
    $native = new WC_Product_Variable();
    $native->set_name( '[CI] Native variable product is not managed' );
    $native->set_status( 'draft' );
    $native_id = $native->save();
    $product_ids[] = $native_id;
    edge_assert( ! WVM_Products::is_managed( wc_get_product( $native_id ) ), 'native variable products must not be claimed as managed' );

    echo "WooCommerce product edge PASS: invalid matrices, duplicate SKU, surcharges/rules, draft, inventory, stale template and native variable exclusion.\n";
} finally {
    $_POST = $original_post;
    foreach ( $product_ids as $id ) {
        $existing = wc_get_product( $id );
        if ( $existing ) { $existing->delete( true ); }
    }
    if ( $template_id && ! is_wp_error( $template_id ) ) {
        wp_delete_post( $template_id, true );
    }
}
