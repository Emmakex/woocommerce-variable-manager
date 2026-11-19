<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce remains the source of truth for product and order data.
 * This module only owns the reusable configuration and size/price matrix.
 */
final class WVM_Products {
    public const TEMPLATE_META = '_wvm_template_id';
    public const MATRIX_META   = '_wvm_price_matrix';

    public static function init(): void {
        add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
        add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
    }

    public static function tab( array $tabs ): array {
        $tabs['wvm_matrix'] = array(
            'label'    => 'Variable Manager',
            'target'   => 'wvm_matrix_panel',
            'class'    => array( 'show_if_simple' ),
            'priority' => 68,
        );
        return $tabs;
    }

    public static function panel(): void {
        global $product_object;
        $product     = $product_object instanceof WC_Product ? $product_object : null;
        $template_id = $product ? absint( $product->get_meta( self::TEMPLATE_META, true ) ) : 0;

        echo '<div id="wvm_matrix_panel" class="panel woocommerce_options_panel hidden">';
        wp_nonce_field( 'wvm_product_save', 'wvm_product_nonce' );
        echo '<div class="options_group"><p class="form-field"><label for="wvm_template_id">Plantilla de opciones</label>';
        echo '<select id="wvm_template_id" name="wvm_template_id" class="select short">';
        echo '<option value="0">Sin configurador</option>';
        foreach ( WVM_Templates::all() as $template ) {
            printf(
                '<option value="%d" %s>%s</option>',
                (int) $template->ID,
                selected( $template_id, (int) $template->ID, false ),
                esc_html( $template->post_title )
            );
        }
        echo '</select></p></div>';
        echo '<div class="wvm-matrix-wrap">';
        echo '<p><strong>Matriz de precios por medida</strong></p>';
        echo '<p class="description">Una fila por medida. Introduce el precio final para cada tamaño (impuestos conforme a la configuración de WooCommerce). No se generan variaciones masivas.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Medida</th><th>Precio (' . esc_html( get_woocommerce_currency_symbol() ) . ')</th></tr></thead>';
        echo '<tbody id="wvm-price-rows"></tbody></table>';
        echo '<p class="description">Al guardar se actualiza el precio mínimo visible del producto. El precio exacto de la medida se calcula en el servidor al comprar.</p>';
        echo '<p class="description"><strong>Importante:</strong> utiliza el tipo de producto Simple. Stock diferenciado por medida y suplementos de otros atributos no están disponibles en esta primera versión.</p>';
        echo '</div></div>';
    }

    public static function assets(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'product' !== $screen->id ) {
            return;
        }
        global $post;
        $product_id = $post instanceof WP_Post ? (int) $post->ID : 0;
        $templates  = array();

        foreach ( WVM_Templates::all() as $template ) {
            $options = WVM_Templates::active_options( (int) $template->ID );
            $templates[ (string) $template->ID ] = $options;
        }
        $saved = $product_id ? get_post_meta( $product_id, self::MATRIX_META, true ) : array();

        wp_enqueue_script( 'wvm-admin', WVM_URL . 'assets/admin.js', array(), WVM_VERSION, true );
        wp_enqueue_style( 'wvm-admin', WVM_URL . 'assets/admin.css', array(), WVM_VERSION );
        wp_localize_script( 'wvm-admin', 'wvmAdminData', array(
            'templates'   => $templates,
            'savedPrices' => is_array( $saved ) ? $saved : array(),
        ) );
    }

    public static function save( WC_Product $product ): void {
        if ( 'simple' !== $product->get_type() || ! current_user_can( 'edit_post', $product->get_id() ) ) {
            return;
        }
        if ( ! isset( $_POST['wvm_product_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wvm_product_nonce'] ) ), 'wvm_product_save' ) ) {
            return;
        }

        $template_id = isset( $_POST['wvm_template_id'] ) ? absint( wp_unslash( $_POST['wvm_template_id'] ) ) : 0;
        if ( 0 === $template_id ) {
            $product->delete_meta_data( self::TEMPLATE_META );
            $product->delete_meta_data( self::MATRIX_META );
            return;
        }

        $options = WVM_Templates::active_options( $template_id );
        $sizes   = $options['size'] ?? array();
        $posted_sizes = isset( $_POST['wvm_matrix_sizes'] ) && is_array( $_POST['wvm_matrix_sizes'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['wvm_matrix_sizes'] ) ) : array();
        $posted_prices = isset( $_POST['wvm_matrix_prices'] ) && is_array( $_POST['wvm_matrix_prices'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['wvm_matrix_prices'] ) ) : array();

        $matrix = WVM_Pricing::validate_matrix( $sizes, $posted_sizes, $posted_prices );
        if ( ! $matrix ) {
            if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
                WC_Admin_Meta_Boxes::add_error( 'Variable Manager: todas las medidas deben tener un precio válido y mayor que cero. No se han actualizado las reglas.' );
            }
            return;
        }

        $normalized = array();
        foreach ( $matrix as $size => $price ) {
            $normalized[ $size ] = wc_format_decimal( $price, wc_get_price_decimals() );
            if ( (float) $normalized[ $size ] <= 0 ) {
                WC_Admin_Meta_Boxes::add_error( 'Variable Manager: el redondeo de moneda genera un precio cero. Revisa la matriz.' );
                return;
            }
        }

        $minimum = WVM_Pricing::minimum( $normalized );
        $product->update_meta_data( self::TEMPLATE_META, $template_id );
        $product->update_meta_data( self::MATRIX_META, $normalized );
        $product->set_regular_price( $minimum );
        $product->set_sale_price( '' );
        $product->set_price( $minimum );
    }

    public static function is_managed( WC_Product $product ): bool {
        return 'simple' === $product->get_type() && (int) $product->get_meta( self::TEMPLATE_META, true ) > 0;
    }

    /** Fail-closed: an incomplete configuration must never be sold at minimum price. */
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
