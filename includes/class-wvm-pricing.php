<?php
defined( 'ABSPATH' ) || exit;

/** Server-side price matrix validation; client prices are never trusted. */
final class WVM_Pricing {
    public static function validate_matrix( array $sizes, array $posted_sizes, array $posted_prices ): ?array {
        if ( ! $sizes || count( $posted_sizes ) !== count( $posted_prices ) || count( $sizes ) !== count( $posted_sizes ) ) {
            return null;
        }

        $matrix = array();
        foreach ( $posted_sizes as $index => $size ) {
            if ( ! is_string( $size ) || ! in_array( $size, $sizes, true ) || isset( $matrix[ $size ] ) ) {
                return null;
            }
            $price = $posted_prices[ $index ] ?? null;
            if ( ! is_string( $price ) && ! is_numeric( $price ) ) {
                return null;
            }
            $price = trim( (string) $price );
            // Simple decimal inputs: 299.95 or 299,95. No thousands separators.
            if ( ! preg_match( '/^\\d{1,9}([.,]\\d{1,4})?$/D', $price ) ) {
                return null;
            }
            $decimal = str_replace( ',', '.', $price );
            if ( (float) $decimal <= 0 ) {
                return null;
            }
            $matrix[ $size ] = $decimal;
        }
        return count( $matrix ) === count( $sizes ) ? $matrix : null;
    }

    public static function price( array $matrix, string $size ): ?string {
        if ( ! array_key_exists( $size, $matrix ) || ! is_numeric( $matrix[ $size ] ) || (float) $matrix[ $size ] <= 0 ) {
            return null;
        }
        return (string) $matrix[ $size ];
    }

    public static function minimum( array $matrix ): ?string {
        if ( ! $matrix ) {
            return null;
        }
        $prices = array_map( 'floatval', array_values( $matrix ) );
        return (string) min( $prices );
    }
}
