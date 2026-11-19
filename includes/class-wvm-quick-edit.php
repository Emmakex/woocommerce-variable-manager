<?php
defined( 'ABSPATH' ) || exit;

/**
 * Inline tariff editing from Variable Manager. No browser-side price authority.
 * Each form carries an optimistic concurrency fingerprint and every product
 * is rechecked using WooCommerce CRUD before any prices are written.
 */
final class WVM_Quick_Edit {
    private const PAGE_SIZE = 10;
    private const MAX_EDITED_PRODUCTS = 20;
    private const MAX_ROWS = 400;

    public static function init(): void {
        add_action( 'admin_post_wvm_quick_save', array( __CLASS__, 'save' ) );
        add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( __CLASS__, 'filter_managed_query' ), 10, 2 );
    }

    /** WC_Product_Query custom variable, transformed by the WC data store. */
    public static function filter_managed_query( array $wp_query_args, array $query_vars ): array {
        if ( ! empty( $query_vars['wvm_managed'] ) ) {
            if ( ! isset( $wp_query_args['meta_query'] ) || ! is_array( $wp_query_args['meta_query'] ) ) {
                $wp_query_args['meta_query'] = array();
            }
            $wp_query_args['meta_query'][] = array(
                'key' => WVM_Products::TEMPLATE_META,
                'compare' => 'EXISTS',
            );
        }
        return $wp_query_args;
    }

    private static function url( array $extra = array() ): string {
        return add_query_arg( array_merge( array( 'page' => 'wvm-quick' ), $extra ), admin_url( 'admin.php' ) );
    }

    private static function redirect( string $status, array $extra = array() ): void {
        wp_safe_redirect( self::url( array_merge( array( 'wvm_status' => $status ), $extra ) ) );
        exit;
    }

    private static function parse_price( $raw ): ?string {
        if ( ! is_string( $raw ) || ! preg_match( '/^\d{1,9}([.,]\d{1,4})?$/D', trim( $raw ) ) ) {
            return null;
        }
        $formatted = wc_format_decimal( trim( $raw ), wc_get_price_decimals() );
        return is_numeric( $formatted ) && (float) $formatted > 0 ? (string) $formatted : null;
    }

    /**
     * Validate the *complete* submitted matrix for each product, then return
     * only edited items in the same plan shape as WVM_Bulk::apply().
     *
     * @return array|WP_Error
     */
    public static function plan( array $submitted, array $original_fingerprints ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new WP_Error( 'wvm_permission', 'No tienes permiso para modificar tarifas.' );
        }
        if ( ! $submitted || count( $submitted ) > self::MAX_EDITED_PRODUCTS ) {
            return new WP_Error( 'wvm_limit', 'Selecciona hasta 20 productos por guardado.' );
        }
        $prepared = array();
        $changed = 0;
        $rows_seen = 0;
        foreach ( $submitted as $raw_id => $rows ) {
            $product_id = filter_var( $raw_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
            if ( ! $product_id || ! is_array( $rows ) || ! $rows || count( $rows ) > 40 ||
                 ! current_user_can( 'edit_post', $product_id ) ) {
                return new WP_Error( 'wvm_product', 'Producto o filas sin permisos o no válidas.' );
            }
            $rows_seen += count( $rows );
            if ( $rows_seen > self::MAX_ROWS ) {
                return new WP_Error( 'wvm_limit', 'Límite de 400 precios por guardado.' );
            }
            $product = wc_get_product( $product_id );
            if ( ! $product instanceof WC_Product_Simple || ! WVM_Products::is_managed( $product ) ||
                 '' !== $product->get_sale_price() ) {
                return new WP_Error( 'wvm_unmanaged', 'El producto ' . $product_id . ' no admite edición rápida de tarifas.' );
            }
            $config = WVM_Products::configuration( $product );
            if ( ! $config ) {
                return new WP_Error( 'wvm_invalid', 'El producto ' . $product_id . ' tiene una configuración incompleta.' );
            }
            $sizes = $config['options']['size'];
            $values = array();
            $seen = array();
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) || ! isset( $row['size'], $row['price'] ) || ! is_string( $row['size'] ) ) {
                    return new WP_Error( 'wvm_row', 'Hay una fila de tarifa incompleta.' );
                }
                $size = $row['size'];
                $price = self::parse_price( $row['price'] );
                if ( null === $price || ! in_array( $size, $sizes, true ) || isset( $seen[ $size ] ) ) {
                    return new WP_Error( 'wvm_row', 'Medida duplicada, desconocida o importe incorrecto.' );
                }
                $seen[ $size ] = true;
                $values[ $size ] = $price;
            }
            if ( count( $values ) !== count( $sizes ) ||
                 null === WVM_Pricing::validate_matrix( $sizes, array_keys( $values ), array_values( $values ) ) ) {
                return new WP_Error( 'wvm_matrix', 'Debes conservar todas las medidas de la plantilla.' );
            }
            $before = $config['prices'];
            $diffs = 0;
            foreach ( $values as $size => $price ) {
                if ( abs( (float) $price - (float) $before[ $size ] ) > 0.000001 ) {
                    ++$diffs;
                }
            }
            if ( 0 === $diffs ) {
                continue; // Avoid unnecessary product saves and invalidating untouched rows.
            }
            $expected = $original_fingerprints[ $product_id ] ?? '';
            if ( ! is_string( $expected ) ||
                 ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ||
                 ! hash_equals( WVM_Bulk::fingerprint( $product, $config ), $expected ) ) {
                return new WP_Error( 'wvm_conflict', 'Otro usuario ha modificado un producto. Recarga las tarifas y revisa los cambios.' );
            }
            $prepared[] = array(
                'id'          => $product_id,
                'sku'         => $product->get_sku(),
                'name'        => $product->get_name(),
                'fingerprint' => $expected,
                'before'      => $before,
                'after'       => $values,
                'selected'    => count( $values ),
            );
            $changed += $diffs;
        }
        if ( ! $prepared ) {
            return new WP_Error( 'wvm_unchanged', 'No has modificado ningún precio.' );
        }
        return array(
            'products'      => $prepared,
            'rows'          => $rows_seen,
            'changed_prices'=> $changed,
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Acceso denegado.' );
        }
        WVM_Dashboard::start( 'Variable Manager · Edición rápida', 'wvm-quick' );
        $search = isset( $_GET['wvm_search'] ) && is_string( $_GET['wvm_search'] )
            ? sanitize_text_field( wp_unslash( $_GET['wvm_search'] ) ) : '';
        $page = isset( $_GET['wvm_page'] ) ? max( 1, absint( $_GET['wvm_page'] ) ) : 1;

        echo '<section class="wvm-card wvm-quick-editor">';
        echo '<h2>Tarifas de varios productos, en una sola pantalla</h2>';
        echo '<p>Modifica el precio base de cada medida. Los suplementos, reglas de compatibilidad y stock permanecen intactos.</p>';
        echo '<form method="get" class="wvm-search wvm-quick-search">';
        echo '<input type="hidden" name="page" value="wvm-quick">';
        echo '<label class="screen-reader-text" for="wvm_quick_search">Buscar producto por nombre o SKU</label>';
        echo '<input id="wvm_quick_search" type="search" name="wvm_search" value="' . esc_attr( $search ) . '" placeholder="Nombre o SKU exacto">';
        echo '<button class="button" type="submit">Buscar</button></form>';

        $query = array(
            'wvm_managed' => true,
            'type'        => 'simple',
            'limit'       => self::PAGE_SIZE,
            'page'        => $page,
            'paginate'    => true,
            'status'      => array( 'publish', 'draft', 'pending', 'private' ),
            'orderby'     => 'date',
            'order'       => 'DESC',
        );
        if ( '' !== $search ) {
            $sku_id = wc_get_product_id_by_sku( $search );
            $matched = $sku_id ? wc_get_product( $sku_id ) : null;
            if ( $matched instanceof WC_Product_Simple && WVM_Products::is_managed( $matched ) ) {
                $query['include'] = array( (int) $sku_id );
            } else {
                $query['search'] = $search;
            }
        }
        $result = wc_get_products( $query );

        $rendered = 0;
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="wvm-quick-form">';
        echo '<input type="hidden" name="action" value="wvm_quick_save">';
        wp_nonce_field( 'wvm_quick_save', 'wvm_nonce' );
        echo '<div class="wvm-quick-table-wrap"><table class="widefat striped wvm-quick-table">';
        echo '<thead><tr><th scope="col">Producto</th><th scope="col">Medida</th><th scope="col">Precio actual</th><th scope="col">Nuevo precio (' .
            esc_html( get_woocommerce_currency() ) . ')</th></tr></thead>';
        foreach ( $result->products as $product ) {
            if ( ! $product instanceof WC_Product_Simple || ! current_user_can( 'edit_post', $product->get_id() ) ) {
                continue;
            }
            $config = WVM_Products::configuration( $product );
            $editable = $config && '' === $product->get_sale_price();
            $count = $config ? count( $config['options']['size'] ) : 1;
            echo '<tbody class="wvm-quick-product">';
            if ( ! $config ) {
                echo '<tr><th scope="row">' . esc_html( $product->get_name() ) . '</th><td colspan="3">Configuración incompleta: revisa el producto antes de editar sus tarifas.</td></tr>';
                echo '</tbody>';
                continue;
            }
            $pos = 0;
            foreach ( $config['options']['size'] as $size ) {
                echo '<tr>';
                if ( 0 === $pos ) {
                    echo '<th scope="rowgroup" rowspan="' . esc_attr( (string) $count ) . '">';
                    if ( $editable ) {
                        echo '<input type="hidden" name="wvm_fingerprint[' .
                            esc_attr( (string) $product->get_id() ) . ']" value="' .
                            esc_attr( WVM_Bulk::fingerprint( $product, $config ) ) . '">';
                    }
                    echo '<strong>' . esc_html( $product->get_name() ) . '</strong>';
                    echo '<span class="wvm-catalog-sku">' . esc_html( $product->get_sku() ?: 'Sin SKU' ) . '</span>';
                    echo '<a class="wvm-quick-open" href="' . esc_url( admin_url( 'admin.php?page=wvm-products&id=' . $product->get_id() ) ) .
                        '">Ver ficha</a>';
                    if ( ! $editable ) {
                        echo '<span class="wvm-quick-locked">Solo lectura: oferta activa</span>';
                    }
                    echo '</th>';
                }
                $base = 'wvm_price[' . (int) $product->get_id() . '][' . $pos . ']';
                echo '<td>' . esc_html( $size ) . '</td>';
                echo '<td>' . esc_html( wc_format_localized_price( $config['prices'][ $size ] ) ) . '</td>';
                echo '<td>';
                if ( $editable ) {
                    echo '<input type="hidden" name="' . esc_attr( $base . '[size]' ) . '" value="' . esc_attr( $size ) . '">';
                    echo '<input class="wvm-quick-price" type="text" inputmode="decimal" autocomplete="off" required name="' .
                        esc_attr( $base . '[price]' ) . '" value="' . esc_attr( (string) $config['prices'][ $size ] ) .
                        '" data-original="' . esc_attr( (string) $config['prices'][ $size ] ) . '" aria-label="Precio para ' .
                        esc_attr( $product->get_name() . ', ' . $size ) . '">';
                } else {
                    echo '<span aria-label="Tarifa protegida">—</span>';
                }
                echo '</td></tr>';
                ++$pos;
            }
            echo '</tbody>';
            ++$rendered;
        }
        echo '</table></div>';
        if ( $rendered ) {
            echo '<div class="wvm-quick-toolbar">';
            echo '<p class="wvm-quick-count" role="status" aria-live="polite"><strong data-wvm-changed-count>0</strong> precios modificados</p>';
            echo '<button type="submit" class="button button-primary button-large">Guardar precios modificados</button>';
            echo '</div>';
            echo '<p class="description">Si alguien modifica el mismo producto antes de guardar, se cancelará el lote sin sobrescribir su trabajo. Las ofertas activas se muestran en modo lectura.</p>';
        } else {
            echo '<p>Sin productos configurados en esta página. Prueba otra búsqueda o crea un producto desde «Productos y tarifas».</p>';
        }
        echo '</form>';

        if ( $result->max_num_pages > 1 ) {
            echo '<div class="wvm-pagination">';
            if ( $page > 1 ) {
                echo '<a class="button" href="' . esc_url( self::url( array( 'wvm_search' => $search, 'wvm_page' => $page - 1 ) ) ) . '">Anterior</a> ';
            }
            echo 'Página ' . esc_html( (string) $page ) . ' de ' . esc_html( (string) $result->max_num_pages );
            if ( $page < $result->max_num_pages ) {
                echo ' <a class="button" href="' . esc_url( self::url( array( 'wvm_search' => $search, 'wvm_page' => $page + 1 ) ) ) . '">Siguiente</a>';
            }
            echo '</div>';
        }
        echo '</section></div>';
    }

    public static function save(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            wp_die( 'Acceso denegado.' );
        }
        check_admin_referer( 'wvm_quick_save', 'wvm_nonce' );
        $submitted = isset( $_POST['wvm_price'] ) && is_array( $_POST['wvm_price'] )
            ? wp_unslash( $_POST['wvm_price'] ) : array();
        $fingerprints = isset( $_POST['wvm_fingerprint'] ) && is_array( $_POST['wvm_fingerprint'] )
            ? wp_unslash( $_POST['wvm_fingerprint'] ) : array();
        $plan = self::plan( $submitted, $fingerprints );
        if ( is_wp_error( $plan ) ) {
            self::redirect( 'wvm_unchanged' === $plan->get_error_code() ? 'quick_unchanged' : 'quick_error' );
        }
        $result = WVM_Bulk::apply( $plan );
        if ( is_wp_error( $result ) ) {
            self::redirect( 'quick_error' );
        }
        self::redirect( 'quick_saved', array(
            'wvm_updated' => (int) $result['changed_prices'],
        ) );
    }
}
