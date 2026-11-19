<?php
defined( 'ABSPATH' ) || exit;

/** Central product configuration service. No admin UI or theme ownership. */
final class WVM_Products {
    public const TEMPLATE_META = '_wvm_template_id';
    public const MATRIX_META   = '_wvm_price_matrix';

    public static function is_managed( WC_Product $product ): bool {
        return 'simple' === $product->get_type() && (int) $product->get_meta( self::TEMPLATE_META, true ) > 0;
    }

    /** Server-side, strict validation before any WooCommerce product save. */
    public static function prepare( int $template_id, array $posted_sizes, array $posted_prices ) {
        $options = WVM_Templates::active_options( $template_id );
        $sizes   = $options['size'] ?? array();
        if ( ! $sizes ) {
            return new WP_Error( 'wvm_template', 'Selecciona una plantilla publicada que tenga medidas.' );
        }
        $matrix = WVM_Pricing::validate_matrix( $sizes, $posted_sizes, $posted_prices );
        if ( null === $matrix ) {
            return new WP_Error( 'wvm_prices', 'Introduce un precio válido para cada medida, sin duplicados ni valores vacíos.' );
        }
        $normalized = array();
        foreach ( $matrix as $size => $price ) {
            $value = wc_format_decimal( $price, wc_get_price_decimals() );
            if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
                return new WP_Error( 'wvm_rounding', 'El redondeo deja un importe inválido. Revisa los precios.' );
            }
            $normalized[ $size ] = $value;
        }
        return array(
            'template_id' => $template_id,
            'matrix'      => $normalized,
            'minimum'     => WVM_Pricing::minimum( $normalized ),
        );
    }

    public static function apply( WC_Product_Simple $product, array $prepared ): void {
        $product->update_meta_data( self::TEMPLATE_META, $prepared['template_id'] );
        $product->update_meta_data( self::MATRIX_META, $prepared['matrix'] );
        $product->set_regular_price( $prepared['minimum'] );
        $product->set_sale_price( '' );
        $product->set_price( $prepared['minimum'] );
    }

    /** Fail closed: never sell a product whose current template and matrix differ. */
    public static function configuration( WC_Product $product ): ?array {
        if ( ! self::is_managed( $product ) ) {
            return null;
        }
        $template_id = absint( $product->get_meta( self::TEMPLATE_META, true ) );
        $options     = WVM_Templates::active_options( $template_id );
        $matrix      = $product->get_meta( self::MATRIX_META, true );
        if ( empty( $options['size'] ) || ! is_array( $matrix ) || count( $matrix ) !== count( $options['size'] ) ) {
            return null;
        }
        foreach ( $options['size'] as $size ) {
            if ( null === WVM_Pricing::price( $matrix, $size ) ) {
                return null;
            }
        }
        return array( 'template_id' => $template_id, 'options' => $options, 'prices' => $matrix );
    }
}
