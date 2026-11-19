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
    public static function prepare(
        int $template_id, array $posted_sizes, array $posted_prices,
        array $posted_surcharges = array(), array $posted_exclusions = array()
    ) {
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
        $surcharges = WVM_Rules::validate_surcharges(
            $options, $posted_surcharges['attr'] ?? array(),
            $posted_surcharges['value'] ?? array(), $posted_surcharges['amount'] ?? array()
        );
        $exclusions = WVM_Rules::validate_exclusions(
            $options, $posted_exclusions['a_attr'] ?? array(),
            $posted_exclusions['a_value'] ?? array(),
            $posted_exclusions['b_attr'] ?? array(), $posted_exclusions['b_value'] ?? array()
        );
        if ( null === $surcharges || null === $exclusions ) {
            return new WP_Error( 'wvm_rules', 'Los suplementos o las incompatibilidades contienen opciones inválidas.' );
        }
        foreach ( $surcharges as $attribute => &$values ) {
            foreach ( $values as $option => &$amount ) {
                $amount = wc_format_decimal( $amount, wc_get_price_decimals() );
            }
            unset( $amount );
        }
        unset( $values );
        return array(
            'template_id' => $template_id,
            'matrix'      => $normalized,
            'minimum'     => WVM_Pricing::minimum( $normalized ),
            'surcharges'  => $surcharges,
            'exclusions'  => $exclusions,
        );
    }

    public static function apply( WC_Product_Simple $product, array $prepared ): void {
        $product->update_meta_data( self::TEMPLATE_META, $prepared['template_id'] );
        $product->update_meta_data( self::MATRIX_META, $prepared['matrix'] );
        $product->update_meta_data( WVM_Rules::SURCHARGES_META, $prepared['surcharges'] );
        $product->update_meta_data( WVM_Rules::EXCLUSIONS_META, $prepared['exclusions'] );
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
        $surcharges = $product->get_meta( WVM_Rules::SURCHARGES_META, true );
        $exclusions = $product->get_meta( WVM_Rules::EXCLUSIONS_META, true );
        $surcharges = '' === $surcharges ? array() : $surcharges;
        $exclusions = '' === $exclusions ? array() : $exclusions;
        if ( ! WVM_Rules::validate_stored( $options, $surcharges, $exclusions ) ) {
            return null;
        }
        return array(
            'template_id' => $template_id,
            'options'     => $options,
            'prices'      => $matrix,
            'surcharges'  => $surcharges,
            'exclusions'  => $exclusions,
        );
    }
}
