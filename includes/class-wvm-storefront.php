<?php
defined( 'ABSPATH' ) || exit;

/**
 * Classic WooCommerce single-product form -> cart -> order integration.
 * All prices and combinations are resolved from server-side product metadata.
 */
final class WVM_Storefront {
    private const FIELDS = array(
        'size'      => 'Medida',
        'color'     => 'Color',
        'thickness' => 'Espesor / altura',
        'opening'   => 'Apertura',
    );

    public static function init(): void {
        add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 10, 2 );
        add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 10, 2 );
        add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'catalog_link' ), 10, 2 );
        add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add' ), 10, 3 );
        add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'cart_data' ), 10, 3 );
        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'reprice' ), 20 );
        add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_meta' ), 10, 4 );
        add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
    }

    /**
     * Product archives must direct customers to the configurator rather than
     * performing WooCommerce's unconfigured AJAX/direct add-to-cart.
     */
    public static function catalog_link( string $html, WC_Product $product ): string {
        if ( ! WVM_Products::is_managed( $product ) ) {
            return $html;
        }
        $label = __( 'Configurar opciones', 'woocommerce-variable-manager' );
        return sprintf(
            '<a class="button wvm-configure-button" href="%s" aria-label="%s">%s</a>',
            esc_url( get_permalink( $product->get_id() ) ),
            esc_attr( $label . ': ' . $product->get_name() ),
            esc_html( $label )
        );
    }

    public static function price_html( string $html, WC_Product $product ): string {
        $config = WVM_Products::configuration( $product );
        if ( ! $config ) {
            return $html;
        }
        if ( ! empty( $config['exclusions'] ) || array_filter( array_merge( array_values( $config['surcharges']['color'] ?? array() ), array_values( $config['surcharges']['thickness'] ?? array() ), array_values( $config['surcharges']['opening'] ?? array() ) ), 'floatval' ) ) {
            return esc_html__( 'Precio según configuración', 'woocommerce-variable-manager' );
        }
        $minimum = WVM_Pricing::minimum( $config['prices'] );
        return $minimum ? sprintf(
            /* translators: %s is a WooCommerce formatted price. */
            __( 'Desde %s', 'woocommerce-variable-manager' ),
            wc_price( wc_get_price_to_display( $product, array( 'price' => (float) $minimum ) ) )
        ) : $html;
    }

    public static function purchasable( bool $purchasable, WC_Product $product ): bool {
        if ( WVM_Products::is_managed( $product ) && ! WVM_Products::configuration( $product ) ) {
            return false;
        }
        return $purchasable;
    }

    public static function assets(): void {
        if ( ! is_product() ) {
            return;
        }
        $product = wc_get_product( get_queried_object_id() );
        if ( ! $product instanceof WC_Product || ! WVM_Products::is_managed( $product ) ) {
            return;
        }
        wp_enqueue_script( 'wvm-storefront', WVM_URL . 'assets/storefront.js', array(), WVM_VERSION, true );
        wp_enqueue_style( 'wvm-storefront', WVM_URL . 'assets/storefront.css', array(), WVM_VERSION );
    }

    public static function render(): void {
        global $product;
        if ( ! $product instanceof WC_Product || ! WVM_Products::is_managed( $product ) ) {
            return;
        }
        $config = WVM_Products::configuration( $product );
        if ( ! $config ) {
            echo '<p role="alert">Este producto necesita completar su matriz de precios antes de venderse.</p>';
            return;
        }
        $json = wp_json_encode( array(
            'prices'     => $config['prices'],
            'surcharges' => $config['surcharges'],
            'exclusions' => $config['exclusions'],
            'currency'   => get_woocommerce_currency(),
        ) );

        echo '<div class="wvm-configurator" data-wvm-config="' . esc_attr( $json ) . '">';
        foreach ( self::FIELDS as $key => $label ) {
            $values = $config['options'][ $key ] ?? array();
            if ( ! $values ) {
                continue;
            }
            echo '<p class="wvm-field"><label for="wvm_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
            echo '<select id="wvm_' . esc_attr( $key ) . '" name="wvm_' . esc_attr( $key ) . '" required>';
            echo '<option value="">Seleccionar ' . esc_html( strtolower( $label ) ) . '</option>';
            foreach ( $values as $value ) {
                echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $value ) . '</option>';
            }
            echo '</select></p>';
        }
        echo '<p class="wvm-selected-price" data-wvm-selected-price aria-live="polite">Selecciona una medida para ver su precio</p>';
        echo '<p class="wvm-warning" data-wvm-warning role="alert" hidden></p>';
        echo '<p class="wvm-note">El importe definitivo se confirma en el carrito según los impuestos de la tienda.</p>';
        echo '</div>';
    }

    private static function posted_selection(): array {
        $selection = array();
        foreach ( self::FIELDS as $key => $label ) {
            $field = 'wvm_' . $key;
            $raw   = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
            $selection[ $key ] = is_string( $raw ) ? sanitize_text_field( $raw ) : '';
        }
        return $selection;
    }

    private static function validate_selection( array $config, array $selection ): bool {
        foreach ( self::FIELDS as $key => $label ) {
            $allowed = $config['options'][ $key ] ?? array();
            if ( ! $allowed ) {
                // Disallow arbitrary client-supplied attributes not in the template.
                if ( ! empty( $selection[ $key ] ) ) {
                    return false;
                }
                continue;
            }
            if ( empty( $selection[ $key ] ) || ! in_array( $selection[ $key ], $allowed, true ) ) {
                return false;
            }
        }
        return WVM_Rules::allowed( $selection, $config['exclusions'] ) &&
            null !== WVM_Rules::total( $config['prices'], $config['surcharges'], $selection );
    }

    public static function validate_add( bool $passed, int $product_id, int $quantity ): bool {
        $product = wc_get_product( $product_id );
        if ( ! $product instanceof WC_Product || ! WVM_Products::is_managed( $product ) ) {
            return $passed;
        }
        $config = WVM_Products::configuration( $product );
        if ( ! $config || ! self::validate_selection( $config, self::posted_selection() ) ) {
            wc_add_notice( 'Selecciona una configuración válida antes de añadir el producto al carrito.', 'error' );
            return false;
        }
        return $passed;
    }

    public static function cart_data( array $data, int $product_id, int $variation_id ): array {
        $product = wc_get_product( $product_id );
        if ( ! $product instanceof WC_Product || ! WVM_Products::is_managed( $product ) ) {
            return $data;
        }
        $config    = WVM_Products::configuration( $product );
        $selection = self::posted_selection();
        if ( $config && self::validate_selection( $config, $selection ) ) {
            $data['wvm_config'] = array(
                'template_id' => $config['template_id'],
                'selection'   => $selection,
            );
        }
        return $data;
    }

    private static function cart_selection( array $cart_item, array $config ): ?array {
        $saved = $cart_item['wvm_config'] ?? null;
        if ( ! is_array( $saved ) || (int) ( $saved['template_id'] ?? 0 ) !== $config['template_id'] ||
             ! isset( $saved['selection'] ) || ! is_array( $saved['selection'] ) ) {
            return null;
        }
        return self::validate_selection( $config, $saved['selection'] ) ? $saved['selection'] : null;
    }

    public static function reprice( WC_Cart $cart ): void {
        foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
            $product = $cart_item['data'] ?? null;
            if ( ! $product instanceof WC_Product ) {
                continue;
            }
            if ( ! WVM_Products::is_managed( $product ) && ! isset( $cart_item['wvm_config'] ) ) {
                continue;
            }
            $config    = WVM_Products::configuration( $product );
            $selection = $config ? self::cart_selection( $cart_item, $config ) : null;
            if ( ! $selection ) {
                // Fail closed even if a checkout integration skips classic notice validation.
                $cart->remove_cart_item( $cart_key );
                wc_add_notice( 'Se ha retirado del carrito una configuración que ya no está disponible.', 'notice' );
                continue;
            }
            $price = WVM_Rules::total( $config['prices'], $config['surcharges'], $selection );
            $product->set_price( wc_format_decimal( $price, wc_get_price_decimals() ) );
        }
    }

    public static function check_cart(): void {
        if ( ! WC()->cart ) {
            return;
        }
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'] ?? null;
            if ( ! $product instanceof WC_Product || ! WVM_Products::is_managed( $product ) ) {
                continue;
            }
            $config = WVM_Products::configuration( $product );
            if ( ! $config || ! self::cart_selection( $cart_item, $config ) ) {
                wc_add_notice( 'Hay una configuración no válida en el carrito. Elimina el producto y vuelve a configurarlo.', 'error' );
                return;
            }
        }
    }

    public static function display_cart( array $item_data, array $cart_item ): array {
        $selection = $cart_item['wvm_config']['selection'] ?? null;
        if ( ! is_array( $selection ) ) {
            return $item_data;
        }
        foreach ( self::FIELDS as $key => $label ) {
            if ( ! empty( $selection[ $key ] ) && is_string( $selection[ $key ] ) ) {
                $item_data[] = array(
                    'key'   => $label,
                    'value' => sanitize_text_field( $selection[ $key ] ),
                );
            }
        }
        return $item_data;
    }

    public static function order_meta( WC_Order_Item_Product $item, string $cart_key, array $values, WC_Order $order ): void {
        $selection = $values['wvm_config']['selection'] ?? null;
        if ( ! is_array( $selection ) ) {
            return;
        }
        foreach ( self::FIELDS as $key => $label ) {
            if ( ! empty( $selection[ $key ] ) && is_string( $selection[ $key ] ) ) {
                $item->add_meta_data( $label, sanitize_text_field( $selection[ $key ] ), true );
            }
        }
    }
}
