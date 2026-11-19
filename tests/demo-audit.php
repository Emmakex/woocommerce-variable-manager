<?php
/**
 * Validate the guarded synthetic WP-CLI seed lifecycle in disposable WordPress.
 * Called with WVM_EXPECT_DEMO=8 after seeding and 0 after purging.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'wc_get_product' ) ) {
    throw new RuntimeException( 'WooCommerce is required for demo audit.' );
}
$expected = (int) getenv( 'WVM_EXPECT_DEMO' );
if ( ! in_array( $expected, array( 0, 8 ), true ) ) {
    throw new RuntimeException( 'WVM_EXPECT_DEMO must be 0 or 8.' );
}
$marker = '_wvm_demo_synthetic';
$products = get_posts( array(
    'post_type'      => 'product',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'meta_key'       => $marker,
    'meta_compare'   => 'EXISTS',
) );
$templates = get_posts( array(
    'post_type'      => 'wvm_template',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'meta_key'       => $marker,
    'meta_compare'   => 'EXISTS',
) );
if ( count( $products ) !== $expected || count( $templates ) !== $expected ) {
    throw new RuntimeException(
        sprintf( 'Demo lifecycle mismatch: %d products and %d templates (expected %d each)',
            count( $products ), count( $templates ), $expected )
    );
}
foreach ( $products as $id ) {
    $p = wc_get_product( (int) $id );
    if ( ! $p || $p->get_status() !== 'draft' || $p->get_catalog_visibility() !== 'hidden' ||
         $p->get_stock_status() !== 'outofstock' || ! WVM_Products::configuration( $p ) ) {
        throw new RuntimeException( 'Synthetic product was published, sellable or invalid: ' . $id );
    }
}
echo sprintf( "Demo audit PASS: %d isolated draft products and templates, no unintended publication.\n", $expected );
