<?php
/**
 * WooCommerce-backed import/export acceptance without persistent test data.
 * Runs only in GitHub Actions' disposable WordPress + MySQL environment.
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Product_Simple' ) ) {
    throw new RuntimeException( 'Requires WordPress and WooCommerce.' );
}
wp_set_current_user( 1 );

function assert_bulk( bool $ok, string $message ): void {
    if ( ! $ok ) {
        throw new RuntimeException( 'Bulk acceptance: ' . $message );
    }
}

$catalog = json_decode(
    (string) file_get_contents( __DIR__ . '/fixtures/catalog-synthetic.json' ),
    true, 512, JSON_THROW_ON_ERROR
);
$created_products = array();
$created_templates = array();
$temp_files = array();

function temporary_csv( string $csv ): string {
    global $temp_files;
    $file = tempnam( sys_get_temp_dir(), 'wvm-bulk-' );
    file_put_contents( $file, $csv );
    $temp_files[] = $file;
    return $file;
}

function temporary_managed_product( array $fixture, string $sku ): int {
    global $created_products, $created_templates;
    $template_id = wp_insert_post( array(
        'post_type' => 'wvm_template', 'post_title' => 'BULK CI synthetic ' . $sku,
        'post_status' => 'publish',
    ), true );
    assert_bulk( ! is_wp_error( $template_id ), 'template insert' );
    $created_templates[] = (int) $template_id;
    update_post_meta( (int) $template_id, WVM_Templates::META_KEY, $fixture['options'] );
    $extras = array( 'attr' => array(), 'value' => array(), 'amount' => array() );
    foreach ( $fixture['surcharges'] as $attr => $values ) {
        foreach ( $values as $name => $value ) {
            $extras['attr'][] = $attr;
            $extras['value'][] = $name;
            $extras['amount'][] = $value;
        }
    }
    $rules = array( 'a_attr' => array(), 'a_value' => array(), 'b_attr' => array(), 'b_value' => array() );
    foreach ( $fixture['exclusions'] as $rule ) {
        foreach ( array_keys( $rules ) as $key ) {
            $rules[ $key ][] = $rule[ $key ];
        }
    }
    $prepared = WVM_Products::prepare(
        (int) $template_id, array_keys( $fixture['matrix'] ), array_values( $fixture['matrix'] ),
        $extras, $rules
    );
    assert_bulk( ! is_wp_error( $prepared ), 'fixture prepare' );
    $product = new WC_Product_Simple();
    $product->set_name( '[BULK TEST] ' . $fixture['name'] );
    $product->set_sku( $sku );
    $product->set_status( 'draft' );
    $product->set_manage_stock( true );
    $product->set_stock_quantity( 11 );
    WVM_Products::apply( $product, $prepared );
    $id = $product->save();
    assert_bulk( $id > 0, 'fixture product save' );
    $created_products[] = $id;
    return $id;
}

try {
    assert_bulk( class_exists( 'WVM_Bulk' ), 'bulk class was not bootstrapped' );
    assert_bulk( current_user_can( 'manage_woocommerce' ), 'CI user missing capability' );
    $a = temporary_managed_product( $catalog['products'][4], 'SYN-BULK-A-CI' );
    $b = temporary_managed_product( $catalog['products'][0], 'SYN-BULK-B-CI' );

    $csv = "\xEF\xBB\xBFsku;medida;precio\nSYN-BULK-A-CI;150x190;499,90\nSYN-BULK-B-CI;135x190;279,90\n";
    $parsed = WVM_Bulk::parse_file( temporary_csv( $csv ) );
    assert_bulk( is_array( $parsed ) && count( $parsed ) === 2, 'CSV parsing failed' );
    $preview = WVM_Bulk::plan( $parsed );
    assert_bulk( ! is_wp_error( $preview ), 'preflight failed: ' . ( is_wp_error( $preview ) ? $preview->get_error_message() : '' ) );
    assert_bulk( $preview['changed_prices'] === 2 && count( $preview['products'] ) === 2, 'preview counts' );
    assert_bulk(
        abs( (float) wc_get_product( $a )->get_meta( WVM_Products::MATRIX_META, true )['150x190'] - 439.90 ) < 0.01,
        'dry-run altered product'
    );

    $applied = WVM_Bulk::apply( $preview );
    assert_bulk( ! is_wp_error( $applied ), 'apply rejected valid plan' );
    $aProduct = wc_get_product( $a );
    $bProduct = wc_get_product( $b );
    $aConfig = WVM_Products::configuration( $aProduct );
    $bConfig = WVM_Products::configuration( $bProduct );
    assert_bulk( abs( (float) $aConfig['prices']['150x190'] - 499.90 ) < 0.01, 'new price A not stored' );
    assert_bulk( abs( (float) $bConfig['prices']['135x190'] - 279.90 ) < 0.01, 'new price B not stored' );
    assert_bulk( abs( (float) $aConfig['prices']['90x190'] - 329.90 ) < 0.01, 'untouched measure changed' );
    assert_bulk( count( $aConfig['exclusions'] ) === 2, 'rules were lost' );
    assert_bulk( $aProduct->get_stock_quantity() === 11, 'stock changed' );

    $duplicate = WVM_Bulk::plan( array( $parsed[0], $parsed[0] ) );
    assert_bulk( is_wp_error( $duplicate ), 'duplicate SKU+measure was accepted' );
    $unknown = WVM_Bulk::plan( array( array( 'sku' => 'SYN-BULK-A-CI', 'size' => '999x999', 'price' => '200' ) ) );
    assert_bulk( is_wp_error( $unknown ), 'unknown measure was accepted' );
    $missing = WVM_Bulk::plan( array( array( 'sku' => 'NONEXISTENT-CI', 'size' => '90x190', 'price' => '100' ) ) );
    assert_bulk( is_wp_error( $missing ), 'unknown SKU was accepted' );

    $before = wc_get_product( $a )->get_meta( WVM_Products::MATRIX_META, true );
    $stale = WVM_Bulk::plan( array(
        array( 'sku' => 'SYN-BULK-A-CI', 'size' => '90x190', 'price' => '355.00' ),
    ) );
    assert_bulk( ! is_wp_error( $stale ), 'unable to build stale-plan test' );
    $aProduct = wc_get_product( $a );
    $matrix = $aProduct->get_meta( WVM_Products::MATRIX_META, true );
    $matrix['90x190'] = '345.00';
    $aProduct->update_meta_data( WVM_Products::MATRIX_META, $matrix );
    $aProduct->set_regular_price( '345.00' );
    $aProduct->set_price( '345.00' );
    $aProduct->save();

    assert_bulk( is_wp_error( WVM_Bulk::apply( $stale ) ), 'stale concurrent preview unexpectedly applied' );
    $unchanged = wc_get_product( $a )->get_meta( WVM_Products::MATRIX_META, true );
    assert_bulk( abs( (float) $unchanged['90x190'] - 345 ) < 0.01, 'stale plan overwritten newer price' );

    $aProduct = wc_get_product( $a );
    $aProduct->set_sale_price( '199.00' );
    $aProduct->save();
    $saleAttempt = WVM_Bulk::plan( array(
        array( 'sku' => 'SYN-BULK-A-CI', 'size' => '150x190', 'price' => '550.00' ),
    ) );
    assert_bulk( is_wp_error( $saleAttempt ), 'sale price protection failed' );

    $out = fopen( 'php://temp', 'w+' );
    $written = WVM_Bulk::write_export( $out );
    rewind( $out );
    $csvExport = stream_get_contents( $out );
    fclose( $out );
    assert_bulk( $written >= 7 && strpos( $csvExport, 'SYN-BULK-A-CI' ) !== false, 'export did not contain synthetic fixtures' );
    // Fresh export imports with the same header/encoding.
    $exportRows = WVM_Bulk::parse_file( temporary_csv( $csvExport ) );
    assert_bulk( is_array( $exportRows ) && count( $exportRows ) >= 7, 'export cannot be parsed back' );
    echo "WooCommerce bulk integration PASS: dry-run, two products updated, selective pricing, stock/rules preserved, stale-plan guard, sale guard and CSV export.\n";
} finally {
    foreach ( $created_products as $id ) {
        $product = wc_get_product( $id );
        if ( $product ) { $product->delete( true ); }
    }
    foreach ( $created_templates as $id ) {
        wp_delete_post( $id, true );
    }
    foreach ( $temp_files as $file ) {
        if ( is_file( $file ) ) { unlink( $file ); }
    }
}
