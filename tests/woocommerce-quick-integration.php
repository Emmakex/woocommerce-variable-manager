<?php
/**
 * Quick editor integration with temporary WordPress, MySQL and WooCommerce.
 * Tests no-SKU products, managed-only query, two-product update, and conflict
 * prevention. Cleans every created row even on failure.
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Product_Simple' ) ) {
    throw new RuntimeException( 'WooCommerce is required.' );
}
wp_set_current_user( 1 );

function quick_assert( bool $ok, string $msg ): void {
    if ( ! $ok ) {
        throw new RuntimeException( 'Quick editor: ' . $msg );
    }
}
$fixtures = json_decode(
    (string) file_get_contents( __DIR__ . '/fixtures/catalog-synthetic.json' ), true, 512, JSON_THROW_ON_ERROR
)['products'];

$ids = array();
$templates = array();
function quick_product( array $fixture, string $name, bool $sku ): int {
    global $ids, $templates;
    $template_id = wp_insert_post( array(
        'post_type' => 'wvm_template', 'post_status' => 'publish',
        'post_title' => '[CI] ' . $name,
    ), true );
    quick_assert( ! is_wp_error( $template_id ), 'template setup' );
    $templates[] = (int) $template_id;
    update_post_meta( (int) $template_id, WVM_Templates::META_KEY, $fixture['options'] );
    $extras = array( 'attr'=>array(), 'value'=>array(), 'amount'=>array() );
    foreach ( $fixture['surcharges'] as $attr => $values ) {
        foreach ( $values as $option => $amount ) {
            $extras['attr'][] = $attr;
            $extras['value'][] = $option;
            $extras['amount'][] = (string) $amount;
        }
    }
    $rules = array( 'a_attr'=>array(), 'a_value'=>array(), 'b_attr'=>array(), 'b_value'=>array() );
    foreach ( $fixture['exclusions'] as $rule ) {
        foreach ( array_keys( $rules ) as $key ) {
            $rules[ $key ][] = $rule[ $key ];
        }
    }
    $prepared = WVM_Products::prepare(
        (int) $template_id, array_keys( $fixture['matrix'] ), array_values( $fixture['matrix'] ),
        $extras, $rules
    );
    quick_assert( ! is_wp_error( $prepared ), 'product preparation' );
    $product = new WC_Product_Simple();
    $product->set_name( '[CI Quick] ' . $name );
    $product->set_status( 'draft' );
    if ( $sku ) { $product->set_sku( 'SYN-QUICK-' . $name ); }
    $product->set_manage_stock( true );
    $product->set_stock_quantity( 9 );
    WVM_Products::apply( $product, $prepared );
    $id = $product->save();
    quick_assert( (int) $id > 0, 'product persistence' );
    $ids[] = $id;
    return $id;
}

function quick_payload( int $id, array $config, array $updates ): array {
    $rows = array();
    foreach ( $config['options']['size'] as $size ) {
        $rows[] = array(
            'size' => $size,
            'price' => (string) ( $updates[ $size ] ?? $config['prices'][ $size ] ),
        );
    }
    return $rows;
}

try {
    quick_assert( class_exists( 'WVM_Quick_Edit' ), 'service loaded' );
    $a = quick_product( $fixtures[0], 'mat-sku', true );
    $b = quick_product( $fixtures[4], 'canape-no-sku', false );

    $unmanaged = new WC_Product_Simple();
    $unmanaged->set_name( '[CI Quick] Unmanaged' );
    $unmanaged->set_regular_price( '15' );
    $unmanaged->set_status( 'draft' );
    $unmanaged_id = $unmanaged->save();
    $ids[] = $unmanaged_id;

    $result = wc_get_products( array(
        'type' => 'simple', 'wvm_managed' => true,
        'status' => array( 'draft' ), 'limit' => 20, 'paginate' => true,
    ) );
    $managedIds = array_map( static function ( WC_Product $product ): int {
        return $product->get_id();
    }, $result->products );
    quick_assert( in_array( $a, $managedIds, true ) && in_array( $b, $managedIds, true ), 'managed products returned in filtered query' );
    quick_assert( ! in_array( $unmanaged_id, $managedIds, true ), 'unmanaged product improperly included in quick editor' );

    $productA = wc_get_product( $a );
    $productB = wc_get_product( $b );
    $configA = WVM_Products::configuration( $productA );
    $configB = WVM_Products::configuration( $productB );
    $posted = array(
        (string) $a => quick_payload( $a, $configA, array( '135x190' => '289,90' ) ),
        (string) $b => quick_payload( $b, $configB, array( '150x190' => '499.90', '180x200' => '599.90' ) ),
    );
    $fingerprints = array(
        $a => WVM_Bulk::fingerprint( $productA, $configA ),
        $b => WVM_Bulk::fingerprint( $productB, $configB ),
    );
    $plan = WVM_Quick_Edit::plan( $posted, $fingerprints );
    quick_assert( ! is_wp_error( $plan ), 'valid two-product quick edit plan' );
    quick_assert( $plan['changed_prices'] === 3 && count( $plan['products'] ) === 2, 'correct change counts' );
    quick_assert( abs( (float) wc_get_product( $a )->get_meta( WVM_Products::MATRIX_META, true )['135x190'] - 259.90 ) < 0.001, 'preview changed WooCommerce data' );

    $applied = WVM_Bulk::apply( $plan );
    quick_assert( ! is_wp_error( $applied ), 'saving multiple products' );
    $afterA = WVM_Products::configuration( wc_get_product( $a ) );
    $afterB = WVM_Products::configuration( wc_get_product( $b ) );
    quick_assert( abs( (float) $afterA['prices']['135x190'] - 289.90 ) < 0.001, 'first price updated' );
    quick_assert( abs( (float) $afterA['prices']['90x190'] - 189.90 ) < 0.001, 'untouched first measure retained' );
    quick_assert( abs( (float) $afterB['prices']['180x200'] - 599.90 ) < 0.001, 'second product updated' );
    quick_assert( count( $afterB['exclusions'] ) === 2, 'incompatible options preserved' );
    quick_assert( wc_get_product( $a )->get_stock_quantity() === 9, 'stock was not changed' );
    quick_assert( wc_get_product( $b )->get_sku() === '', 'empty SKU still supported' );

    $staleConfig = WVM_Products::configuration( wc_get_product( $a ) );
    $staleForm = array( $a => quick_payload( $a, $staleConfig, array( '90x190' => '210.00' ) ) );
    $staleFingerprints = array( $a => WVM_Bulk::fingerprint( wc_get_product( $a ), $staleConfig ) );
    $productA = wc_get_product( $a );
    $prices = $productA->get_meta( WVM_Products::MATRIX_META, true );
    $prices['90x190'] = '205.00';
    $productA->update_meta_data( WVM_Products::MATRIX_META, $prices );
    $productA->set_regular_price( '205.00' );
    $productA->set_price( '205.00' );
    $productA->save();

    quick_assert( is_wp_error( WVM_Quick_Edit::plan( $staleForm, $staleFingerprints ) ), 'stale concurrent edit incorrectly accepted' );
    $fresh = WVM_Products::configuration( wc_get_product( $a ) );
    quick_assert( abs( (float) $fresh['prices']['90x190'] - 205.00 ) < 0.001, 'concurrent price incorrectly overwritten' );

    $bad = array( $b => quick_payload( $b, $afterB, array( '150x190' => '-1' ) ) );
    quick_assert( is_wp_error( WVM_Quick_Edit::plan( $bad, array( $b => WVM_Bulk::fingerprint( wc_get_product( $b ), $afterB ) ) ) ), 'negative price accepted' );

    $rows = quick_payload( $b, $afterB, array( '150x190' => '505.00' ) );
    $rows[1]['size'] = $rows[0]['size'];
    quick_assert( is_wp_error( WVM_Quick_Edit::plan(
        array( $b => $rows ), array( $b => WVM_Bulk::fingerprint( wc_get_product( $b ), $afterB ) )
    ) ), 'duplicate measure accepted' );

    $productB = wc_get_product( $b );
    $productB->set_sale_price( '199' );
    $productB->save();
    quick_assert( is_wp_error( WVM_Quick_Edit::plan( array(
        $b => quick_payload( $b, $afterB, array( '90x190' => '389.90' ) ),
    ), array() ) ), 'product with WooCommerce sale price was edited' );

    echo "WooCommerce quick editor PASS: filtered query, two-product price changes, no-SKU, stock/rules unchanged, concurrency, invalid prices and sale guards.\n";
} finally {
    foreach ( $ids as $id ) {
        $product = wc_get_product( (int) $id );
        if ( $product ) { $product->delete( true ); }
    }
    foreach ( $templates as $id ) {
        wp_delete_post( $id, true );
    }
}
