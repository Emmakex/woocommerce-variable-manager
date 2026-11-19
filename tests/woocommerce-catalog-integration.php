<?php
/**
 * CI only: test the catalog UX services against actual WC_Product_Simple CRUD.
 * Everything is synthetic and removed in finally.
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Product_Simple' ) ) {
    throw new RuntimeException( 'Requires actual WordPress/WooCommerce runtime.' );
}
wp_set_current_user( 1 );

function catalog_check( bool $ok, string $what ): void {
    if ( ! $ok ) {
        throw new RuntimeException( 'Catalog test failure: ' . $what );
    }
}
$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/catalog-synthetic.json' ), true, 512, JSON_THROW_ON_ERROR )['products'][4];
$template_id = 0;
$original_id = 0;
$duplicate_id = 0;
$unmanaged_id = 0;

try {
    catalog_check( class_exists( 'WVM_Catalog' ), 'Catalog service loaded' );
    $template_id = wp_insert_post( array(
        'post_title' => '[TEST] Catalogue canapé fixture',
        'post_type' => 'wvm_template',
        'post_status' => 'publish',
    ), true );
    catalog_check( ! is_wp_error( $template_id ) && (int) $template_id > 0, 'created test template' );
    update_post_meta( (int) $template_id, WVM_Templates::META_KEY, $fixture['options'] );

    $extra = array( 'attr' => array(), 'value' => array(), 'amount' => array() );
    foreach ( $fixture['surcharges'] as $attr => $vals ) {
        foreach ( $vals as $value => $price ) {
            $extra['attr'][] = $attr;
            $extra['value'][] = $value;
            $extra['amount'][] = $price;
        }
    }
    $excluded = array( 'a_attr' => array(), 'a_value' => array(), 'b_attr' => array(), 'b_value' => array() );
    foreach ( $fixture['exclusions'] as $rule ) {
        foreach ( array_keys( $excluded ) as $key ) {
            $excluded[ $key ][] = $rule[ $key ];
        }
    }
    $prepared = WVM_Products::prepare(
        (int) $template_id, array_keys( $fixture['matrix'] ), array_values( $fixture['matrix'] ),
        $extra, $excluded
    );
    catalog_check( ! is_wp_error( $prepared ), 'valid fixture preparation' );

    $original = new WC_Product_Simple();
    $original->set_name( '[TEST] Canapé Catalog Easy Manager' );
    $original->set_sku( 'SYN-CATALOG-CLI-001' );
    $original->set_status( 'publish' );
    $original->set_catalog_visibility( 'visible' );
    $original->set_description( 'Fictional material specification.' );
    $original->set_short_description( 'Synthetic short text.' );
    $original->set_weight( '28' );
    $original->set_length( '190' );
    $original->set_manage_stock( true );
    $original->set_stock_quantity( 37 );
    WVM_Products::apply( $original, $prepared );
    $original_id = $original->save();
    catalog_check( (int) $original_id > 0, 'created source' );

    $skuResult = WVM_Catalog::search( 'SYN-CATALOG-CLI-001', 1 );
    catalog_check( count( $skuResult->products ) === 1, 'exact SKU search count' );
    catalog_check( $skuResult->products[0]->get_id() === $original_id, 'exact SKU search ID' );

    $clone_id = WVM_Catalog::duplicate( (int) $original_id );
    catalog_check( is_int( $clone_id ) && $clone_id > 0 && $clone_id !== $original_id, 'duplicate returns new product' );
    $duplicate_id = $clone_id;
    $clone = wc_get_product( $clone_id );
    catalog_check( $clone instanceof WC_Product_Simple, 'duplicated as simple product' );
    catalog_check( 'draft' === $clone->get_status(), 'duplicate is draft only' );
    catalog_check( 'hidden' === $clone->get_catalog_visibility(), 'duplicate hidden from catalog' );
    catalog_check( '' === $clone->get_sku(), 'SKU is NOT copied' );
    catalog_check( ! $clone->get_manage_stock() && null === $clone->get_stock_quantity(), 'stock quantity NOT copied' );
    catalog_check( 'outofstock' === $clone->get_stock_status(), 'new clone has no sellable stock' );
    catalog_check( '' === $clone->get_sale_price(), 'no inherited sale price' );
    catalog_check( $clone->get_description() === $original->get_description(), 'description is copied' );
    catalog_check( $clone->get_weight() === $original->get_weight(), 'shipping metadata is copied' );
    $config = WVM_Products::configuration( $clone );
    catalog_check( is_array( $config ), 'clone has valid managed template' );
    catalog_check( $config['prices'] === $prepared['matrix'], 'full size matrix retained' );
    catalog_check( count( $config['exclusions'] ) === 2, 'compatibility exclusions retained' );
    catalog_check(
        number_format( (float) WVM_Rules::total( $config['prices'], $config['surcharges'], $fixture['sample']['selection'] ), 2, '.', '' ) === '579.80',
        'clone pricing still resolves correct total'
    );
    $sourceNow = wc_get_product( $original_id );
    catalog_check( $sourceNow->get_stock_quantity() === 37, 'source stock unchanged' );
    catalog_check( $sourceNow->get_status() === 'publish', 'source status unchanged' );

    $unmanaged = new WC_Product_Simple();
    $unmanaged->set_name( '[TEST] Unmanaged ordinary product' );
    $unmanaged->set_regular_price( '10' );
    $unmanaged->set_status( 'draft' );
    $unmanaged_id = $unmanaged->save();
    catalog_check( is_wp_error( WVM_Catalog::duplicate( $unmanaged_id ) ), 'unmanaged source rejected' );

    catalog_check( WVM_Catalog::allowed_status( 'unexpected' ) === 'all', 'invalid status sanitized' );
    $managedList = WVM_Catalog::search( '', 1, array( 'managed' => true, 'per_page' => 20 ) );
    $managedIds = array_map( static fn( WC_Product $item ): int => $item->get_id(), $managedList->products );
    catalog_check( in_array( $original_id, $managedIds, true ), 'published managed source appears in filtered list' );
    catalog_check( in_array( $duplicate_id, $managedIds, true ), 'draft clone appears in managed list' );
    catalog_check( ! in_array( $unmanaged_id, $managedIds, true ), 'unmanaged WooCommerce product excluded from managed list' );
    $published = WVM_Catalog::search( 'SYN-CATALOG-CLI-001', 1, array( 'managed' => true, 'status' => 'publish' ) );
    catalog_check( count( $published->products ) === 1 && $published->products[0]->get_id() === $original_id, 'published SKU/status match' );
    $draftSku = WVM_Catalog::search( 'SYN-CATALOG-CLI-001', 1, array( 'managed' => true, 'status' => 'draft' ) );
    catalog_check( empty( $draftSku->products ), 'published SKU incorrectly matched draft filter' );
    $draftProducts = WVM_Catalog::search( '', 1, array( 'managed' => true, 'status' => 'draft' ) );
    $draftIds = array_map( static fn( WC_Product $item ): int => $item->get_id(), $draftProducts->products );
    catalog_check( in_array( $duplicate_id, $draftIds, true ) && ! in_array( $original_id, $draftIds, true ), 'managed draft filter' );


    $sourceNow->update_meta_data( WVM_Rules::SURCHARGES_META, array( 'color' => array( 'Color no disponible' => '12' ) ) );
    $sourceNow->save();
    catalog_check( is_wp_error( WVM_Catalog::duplicate( (int) $original_id ) ), 'tampered source rejected' );

    echo "WooCommerce catalog integration PASS: exact SKU lookup, safe draft duplication, full matrix/rules, no stock/SKU leakage, unmanaged/tampered rejection.\n";
} finally {
    foreach ( array( $duplicate_id, $original_id, $unmanaged_id ) as $id ) {
        if ( $id ) {
            $item = wc_get_product( (int) $id );
            if ( $item ) { $item->delete( true ); }
        }
    }
    if ( $template_id && ! is_wp_error( $template_id ) ) {
        wp_delete_post( (int) $template_id, true );
    }
}
