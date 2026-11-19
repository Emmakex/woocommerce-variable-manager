<?php
defined( 'ABSPATH' ) || exit;

/** Reusable product-option templates, intentionally independent from Site Architect. */
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
        add_action( 'add_meta_boxes_wvm_template', array( __CLASS__, 'register_metabox' ) );
        add_action( 'save_post_wvm_template', array( __CLASS__, 'save' ) );
    }

    public static function register_type(): void {
        register_post_type(
            'wvm_template',
            array(
                'labels' => array(
                    'name'               => 'Variable Manager',
                    'singular_name'      => 'Plantilla',
                    'menu_name'          => 'Variable Manager',
                    'all_items'          => 'Plantillas',
                    'add_new_item'       => 'Nueva plantilla',
                    'edit_item'          => 'Editar plantilla',
                    'not_found'          => 'No hay plantillas todavía',
                ),
                'public'              => false,
                'publicly_queryable'  => false,
                'show_ui'             => true,
                'show_in_rest'        => false,
                'show_in_menu'        => true,
                'menu_icon'           => 'dashicons-screenoptions',
                'menu_position'       => 56,
                'supports'            => array( 'title' ),
                'map_meta_cap'        => true,
                'capability_type'     => 'post',
                'exclude_from_search' => true,
            )
        );
    }

    public static function register_metabox(): void {
        add_meta_box( 'wvm_template_options', 'Opciones reutilizables', array( __CLASS__, 'render' ), 'wvm_template', 'normal', 'high' );
    }

    public static function render( WP_Post $post ): void {
        wp_nonce_field( 'wvm_template_save', 'wvm_template_nonce' );
        $options = self::options( $post->ID );
        echo '<p>Escribe valores separados por comas. Ejemplo: <code>90x190, 135x190, 150x190</code>. Publica la plantilla para utilizarla en productos.</p>';
        foreach ( self::ATTRIBUTES as $key => $label ) {
            $values = isset( $options[ $key ] ) ? implode( ', ', $options[ $key ] ) : '';
            echo '<p><label for="wvm_attr_' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
            echo '<input type="text" class="widefat" id="wvm_attr_' . esc_attr( $key ) . '" name="wvm_attributes[' . esc_attr( $key ) . ']" value="' . esc_attr( $values ) . '" placeholder="Separados por comas"></p>';
        }
        echo '<p class="description">Las medidas son obligatorias; los demás atributos son opcionales. Los precios se editan en cada producto y nunca se inventan combinaciones de stock.</p>';
    }

    public static function normalize( $raw ): array {
        $clean = array();
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

    public static function save( int $post_id ): void {
        if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( ! isset( $_POST['wvm_template_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wvm_template_nonce'] ) ), 'wvm_template_save' ) ) {
            return;
        }
        $raw = isset( $_POST['wvm_attributes'] ) && is_array( $_POST['wvm_attributes'] )
            ? wp_unslash( $_POST['wvm_attributes'] ) : array();
        update_post_meta( $post_id, self::META_KEY, self::normalize( $raw ) );
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
        if ( ! $template_id || 'wvm_template' !== get_post_type( $template_id ) || 'publish' !== get_post_status( $template_id ) ) {
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
}
