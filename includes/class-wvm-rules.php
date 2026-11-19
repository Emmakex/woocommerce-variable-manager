<?php
defined( 'ABSPATH' ) || exit;

/**
 * Small independent pricing/compatibility engine.
 * Options, rules and surcharges live in WooCommerce product metadata.
 * Pricing MUST be resolved on the server, never accepted from a client.
 */
final class WVM_Rules {
    private const EXTRA_ATTRIBUTES = array( 'color', 'thickness', 'opening' );
    private const ALL_ATTRIBUTES = array( 'size', 'color', 'thickness', 'opening' );
    public const SURCHARGES_META = '_wvm_surcharges';
    public const EXCLUSIONS_META = '_wvm_exclusions';

    private static function decimal( $input, bool $allow_zero = true ): ?string {
        if ( ! is_scalar( $input ) || is_bool( $input ) ) {
            return null;
        }
        $number = trim( (string) $input );
        if ( ! preg_match( '/^\\d{1,9}([.,]\\d{1,4})?$/D', $number ) ) {
            return null;
        }
        $number = str_replace( ',', '.', $number );
        if ( (float) $number < 0 || ( ! $allow_zero && (float) $number <= 0 ) ) {
            return null;
        }
        return $number;
    }

    /**
     * Accept a row for every listed non-size option. Amounts >= 0.
     * Missing rows are treated as zero, allowing older saved products.
     */
    public static function validate_surcharges( array $options, array $attrs, array $values, array $amounts ): ?array {
        if ( count( $attrs ) !== count( $values ) || count( $attrs ) !== count( $amounts ) || count( $attrs ) > 120 ) {
            return null;
        }
        $clean = array();
        foreach ( $attrs as $i => $attr ) {
            if ( ! is_string( $attr ) || ! in_array( $attr, self::EXTRA_ATTRIBUTES, true ) ||
                 ! is_string( $values[ $i ] ) || ! in_array( $values[ $i ], $options[ $attr ] ?? array(), true ) ) {
                return null;
            }
            $value = $values[ $i ];
            $amount = self::decimal( $amounts[ $i ], true );
            if ( null === $amount || isset( $clean[ $attr ][ $value ] ) ) {
                return null;
            }
            $clean[ $attr ][ $value ] = $amount;
        }
        return $clean;
    }

    /**
     * User-friendly exclusions: forbid this pair of attribute choices together.
     * Example: size=90x190 + opening=Lateral.
     */
    public static function validate_exclusions( array $options, array $a_attr, array $a_value, array $b_attr, array $b_value ): ?array {
        $count = count( $a_attr );
        if ( $count !== count( $a_value ) || $count !== count( $b_attr ) || $count !== count( $b_value ) || $count > 60 ) {
            return null;
        }
        $clean = array();
        $seen = array();
        for ( $i = 0; $i < $count; $i++ ) {
            $left_attr  = $a_attr[ $i ];
            $right_attr = $b_attr[ $i ];
            $left_value = $a_value[ $i ];
            $right_value = $b_value[ $i ];
            if ( ! is_string( $left_attr ) || ! is_string( $right_attr ) ||
                 ! is_string( $left_value ) || ! is_string( $right_value ) ||
                 ! in_array( $left_attr, self::ALL_ATTRIBUTES, true ) ||
                 ! in_array( $right_attr, self::ALL_ATTRIBUTES, true ) ||
                 $left_attr === $right_attr ||
                 ! in_array( $left_value, $options[ $left_attr ] ?? array(), true ) ||
                 ! in_array( $right_value, $options[ $right_attr ] ?? array(), true ) ) {
                return null;
            }
            $pair = array( $left_attr . ':' . $left_value, $right_attr . ':' . $right_value );
            sort( $pair, SORT_STRING );
            $key = implode( '|', $pair );
            if ( isset( $seen[ $key ] ) ) {
                return null;
            }
            $seen[ $key ] = true;
            $clean[] = array(
                'a_attr'  => $left_attr,
                'a_value' => $left_value,
                'b_attr'  => $right_attr,
                'b_value' => $right_value,
            );
        }
        return $clean;
    }

    /** Stored metadata is validated against the active template on every read. */
    public static function validate_stored( array $options, $surcharges, $exclusions ): bool {
        if ( ! is_array( $surcharges ) || ! is_array( $exclusions ) ) {
            return false;
        }
        foreach ( $surcharges as $attr => $values ) {
            if ( ! in_array( $attr, self::EXTRA_ATTRIBUTES, true ) || ! is_array( $values ) ) {
                return false;
            }
            foreach ( $values as $value => $amount ) {
                if ( ! in_array( (string) $value, $options[ $attr ] ?? array(), true ) || null === self::decimal( $amount ) ) {
                    return false;
                }
            }
        }
        $a_attr = $a_value = $b_attr = $b_value = array();
        foreach ( $exclusions as $rule ) {
            if ( ! is_array( $rule ) ) {
                return false;
            }
            $a_attr[]  = $rule['a_attr'] ?? null;
            $a_value[] = $rule['a_value'] ?? null;
            $b_attr[]  = $rule['b_attr'] ?? null;
            $b_value[] = $rule['b_value'] ?? null;
        }
        return null !== self::validate_exclusions( $options, $a_attr, $a_value, $b_attr, $b_value );
    }

    public static function allowed( array $selection, array $exclusions ): bool {
        foreach ( $exclusions as $rule ) {
            if ( ( $selection[ $rule['a_attr'] ] ?? null ) === $rule['a_value'] &&
                 ( $selection[ $rule['b_attr'] ] ?? null ) === $rule['b_value'] ) {
                return false;
            }
        }
        return true;
    }

    public static function total( array $matrix, array $surcharges, array $selection ): ?string {
        $base = WVM_Pricing::price( $matrix, (string) ( $selection['size'] ?? '' ) );
        if ( null === $base ) {
            return null;
        }
        $total = (float) $base;
        foreach ( self::EXTRA_ATTRIBUTES as $attr ) {
            $value = $selection[ $attr ] ?? '';
            if ( is_string( $value ) && array_key_exists( $value, $surcharges[ $attr ] ?? array() ) ) {
                $total += (float) $surcharges[ $attr ][ $value ];
            }
        }
        return number_format( $total, 4, '.', '' );
    }
}
