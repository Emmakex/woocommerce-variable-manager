<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stored in WordPress as an internal post type; edited only through the
 * dedicated Variable Manager dashboard (never Gutenberg or a second menu).
 */
final class WVM_Templates {
    public const META_KEY = '_wvm_options';
    public const ATTRIBUTES = array(
        'size'      => 'Medidas',
        'color'     => 'Colores',
        'thickness' => 'Espesores / alturas',
        'opening'   => 'Aperturas',
    );

    public static function init(): void {
        add_action( 'init', array( __CLASS__, 'register_type' ) );
    }

    public static function register_type(): void {
        register_post_type( 'wvm_template', array(
            'labels' => array(
                'name'          => 'Plantillas Variable Manager',
                'singular_name' => 'Plantilla',
            ),
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'show_in_menu'        => false,
            'exclude_from_search' => true,
            'supports'            => array( 'title' ),
            'capability_type'     => 'product',
            'map_meta_cap'        => true,
        ) );
    }

    public static function normalize( $raw ): array {
        $clean = array();
        $raw   = is_array( $raw ) ? $raw : array();
        foreach ( self::ATTRIBUTES as $key => $label ) {
            $input = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? $raw[ $key ] : '';
            $values = array_map( 'trim', explode( ',', $input ) );
            $values = array_map( 'sanitize_text_field', $values );
            $values = array_filter( $values, static function ( string $value ): bool {
                return '' !== $value && strlen( $value ) <= 200;
            } );
            $clean[ $key ] = array_slice( array_values( array_unique( $values ) ), 0, 40 );
        }
        return $clean;
    }

    public static function options( int $template_id ): array {
        if ( 'wvm_template' !== get_post_type( $template_id ) ) {
            return array();
        }
        $raw = get_post_meta( $template_id, self::META_KEY, true );
        if ( ! is_array( $raw ) ) {
            return array();
        }
        $clean = array();
        foreach ( self::ATTRIBUTES as $key => $label ) {
            $clean[ $key ] = isset( $raw[ $key ] ) && is_array( $raw[ $key ] )
                ? array_values( array_filter( array_map( 'sanitize_text_field', $raw[ $key ] ) ) ) : array();
        }
        return $clean;
    }

    public static function active_options( int $template_id ): array {
        if ( ! $template_id || 'wvm_template' !== get_post_type( $template_id ) ||
             'publish' !== get_post_status( $template_id ) ) {
            return array();
        }
        return self::options( $template_id );
    }

    public static function all(): array {
        return get_posts( array(
            'post_type'      => 'wvm_template',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
    }

    public static function is_in_use( int $template_id ): bool {
        if ( ! $template_id ) {
            return false;
        }
        $products = get_posts( array(
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => WVM_Products::TEMPLATE_META,
            'meta_value'     => $template_id,
        ) );
        return ! empty( $products );
    }
}
