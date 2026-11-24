<?php
/**
 * Disposable CI validation of the full 30-product catalog CSV import path.
 * Creates no orders, users, payments, or actual stock.
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Product_Simple' ) ) {
    throw new RuntimeException( 'WordPress + WooCommerce are required.' );
}
wp_set_current_user( 1 );

function catalog_csv_assert( bool $ok, string $description ): void {
    if ( ! $ok ) {
        throw new RuntimeException( 'CSV catalog import: ' . $description );
    }
}

$csv = tempnam( sys_get_temp_dir(), 'wvm_csv_30_' );
$skus = array();
$first_sku = '';
try {
    $fp = fopen( $csv, 'wb' );
    catalog_csv_assert( false !== $fp, 'could not create temporary CSV' );
    $cols = array(
        'sku', 'nombre', 'familia', 'descripcion', 'medidas', 'precios',
        'colores', 'alturas', 'aperturas', 'suplementos', 'incompatibilidades',
    );
    fputcsv( $fp, $cols, ';', '"', '' );
    for ( $i = 1; $i <= 30; $i++ ) {
        $family = array( 'colchon', 'canape', 'base', 'pack' )[ ( $i - 1 ) % 4 ];
        $sku = 'SYN-CI-CAT-' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT );
        $skus[] = $sku;
        if ( 1 === $i ) { $first_sku = $sku; }
        $sizes = '90x190|135x190|150x200|180x200';
        $base = 100 + 10 * $i;
        $prices = sprintf( '%d.90|%d.90|%d.90|%d.90', $base, $base + 55, $base + 90, $base + 145 );
        $color = '';
        $thickness = '';
        $opening = '';
        $extras = '';
        $exclude = '';
        if ( 'colchon' === $family ) {
            $thickness = '25 cm|30 cm';
            $extras = 'thickness:30 cm=40';
        } elseif ( 'canape' === $family ) {
            $color = 'Blanco|Roble';
            $opening = 'Frontal|Lateral';
            $extras = 'color:Roble=35|opening:Lateral=50';
            $exclude = 'size:90x190>opening:Lateral';
        } elseif ( 'base' === $family ) {
            $color = 'Gris|Beige';
            $extras = 'color:Beige=10';
        } else {
            $color = 'Blanco|Negro';
            $extras = 'color:Negro=15';
        }
        fputcsv( $fp, array(
            $sku, '[CI] Synthetic product ' . $i, $family,
            'Fictitious catalog item. Do not sell.', $sizes,
            $prices, $color, $thickness, $opening, $extras, $exclude,
        ), ';', '"', '' );
    }
    fclose( $fp );

    $rows = WVM_Catalog_Import::parse_file( $csv );
    catalog_csv_assert( ! is_wp_error( $rows ) && 30 === count( $rows ), '30 products could not be parsed: ' . ( is_wp_error( $rows ) ? $rows->get_error_message() : '' ) );
    $plan = WVM_Catalog_Import::plan( $rows );
    catalog_csv_assert( ! is_wp_error( $plan ), 'preflight plan rejected clean batch' );
    catalog_csv_assert( 30 === $plan['total'] && 120 === $plan['prices'], 'preflight inventory and price counts' );
    catalog_csv_assert( ! wc_get_product_id_by_sku( $first_sku ), 'preview must never write products' );

    $result = WVM_Catalog_Import::apply( $plan );
    catalog_csv_assert( ! is_wp_error( $result ), 'could not create synthetic batch: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
    catalog_csv_assert( 30 === $result['created'], 'batch did not create exactly 30 products' );
    $all_templates = array();
    foreach ( $skus as $sku ) {
        $id = wc_get_product_id_by_sku( $sku );
        catalog_csv_assert( $id > 0, 'missing SKU ' . $sku );
        $product = wc_get_product( $id );
        catalog_csv_assert( $product instanceof WC_Product_Simple, 'imported product is not simple' );
        catalog_csv_assert( 'draft' === $product->get_status(), 'imported product is not draft' );
        catalog_csv_assert( 'hidden' === $product->get_catalog_visibility(), 'imported product visible in catalog' );
        catalog_csv_assert( 'outofstock' === $product->get_stock_status(), 'imported product has purchasable stock' );
        catalog_csv_assert( false === $product->get_manage_stock(), 'imported product manages real inventory' );
        // WooCommerce may expose draft preview as purchasable to admins with
        // edit_post, so check public visitor permissions, not admin preview.
        wp_set_current_user( 0 );
        $visitor_product = wc_get_product( $id );
        catalog_csv_assert( ! $visitor_product->is_purchasable(), 'anonymous visitor can purchase draft' );
        wp_set_current_user( 1 );
        $config = WVM_Products::configuration( $product );
        catalog_csv_assert( is_array( $config ) && 4 === count( $config['prices'] ), 'imported price matrix invalid' );
        $all_templates[ $config['template_id'] ] = true;
    }
    catalog_csv_assert( count( $all_templates ) >= 4 && count( $all_templates ) <= 5, 'template reuse is not functioning' );

    $canape = wc_get_product( wc_get_product_id_by_sku( $skus[1] ) );
    $config = WVM_Products::configuration( $canape );
    catalog_csv_assert( ! WVM_Rules::allowed(
        array( 'size' => '90x190', 'color' => 'Roble', 'opening' => 'Lateral' ),
        $config['exclusions']
    ), 'canape forbidden opening not preserved' );
    catalog_csv_assert( abs( (float) $config['surcharges']['color']['Roble'] - 35.0 ) < 0.001, 'color surcharge missing' );

    catalog_csv_assert( is_wp_error( WVM_Catalog_Import::plan( $rows ) ), 'second import failed to detect existing SKU' );
    catalog_csv_assert( is_wp_error( WVM_Catalog_Import::apply( $plan ) ), 'second import modified existing products' );

    // Corrupted price, duplicated SKU, incompatible size/option should fail
    // without inserting a single new product.
    $bad = $rows;
    $bad[0]['sku'] = 'SYN-CI-CAT-999';
    $bad[0]['matrix']['90x190'] = '-1';
    catalog_csv_assert( is_wp_error( WVM_Catalog_Import::plan( $bad ) ), 'invalid or existing SKU in plan must fail' );

    $broken = tempnam( sys_get_temp_dir(), 'wvm_csv_bad_' );
    file_put_contents( $broken, "sku;nombre;familia;descripcion;medidas;precios;colores;alturas;aperturas;suplementos;incompatibilidades\n" .
        "SYN-CSV-BAD;Invalid;colchon;Test;90x190|135x190;199.90; ;25 cm;;;\n" );
    catalog_csv_assert( is_wp_error( WVM_Catalog_Import::parse_file( $broken ) ), 'broken count of price cells accepted' );
    @unlink( $broken );

    echo "WooCommerce CSV create PASS: 30 draft hidden products, 120 prices, template reuse, options/surcharges, duplicate guard, invalid rows and no shop stock.\n";
} finally {
    wp_set_current_user( 1 );
    foreach ( $skus as $sku ) {
        $id = wc_get_product_id_by_sku( $sku );
        if ( $id ) {
            $product = wc_get_product( $id );
            if ( $product ) {
                $product->delete( true );
            }
        }
    }
    $templates = get_posts( array(
        'post_type' => 'wvm_template',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_key' => '_wvm_csv_template_signature',
    ) );
    foreach ( $templates as $id ) {
        wp_delete_post( (int) $id, true );
    }
    @unlink( $csv );
}
