<?php
defined( 'ABSPATH' ) || exit;

/**
 * CSV creation of synthetic/testing products in the existing WVM dashboard.
 * This is intentionally separate from the existing SKU;size;price price updater.
 * Safe defaults: drafts, hidden catalog visibility, no stock, no payments.
 */
final class WVM_Catalog_Import {
    public const MAX_BYTES = 1048576;
    public const MAX_PRODUCTS = 80;
    private const PREVIEW_PREFIX = 'wvm_catalog_import_';
    private const TEMPLATE_META = '_wvm_csv_template_signature';
    private const CREATED_META = '_wvm_csv_catalog_import';

    private const HEADER = array(
        'sku', 'nombre', 'familia', 'descripcion', 'medidas', 'precios',
        'colores', 'alturas', 'aperturas', 'suplementos', 'incompatibilidades',
    );

    public static function init(): void {
        add_action( 'admin_post_wvm_catalog_preview', array( __CLASS__, 'preview' ) );
        add_action( 'admin_post_wvm_catalog_apply', array( __CLASS__, 'apply_preview' ) );
    }

    private static function error( string $msg ): WP_Error {
        return new WP_Error( 'wvm_catalog_import', $msg );
    }

    private static function options( string $raw ): ?array {
        if ( '' === $raw ) {
            return array();
        }
        $values = array_map( 'trim', explode( '|', $raw ) );
        if ( count( $values ) > 40 || in_array( '', $values, true ) ||
             count( array_unique( $values ) ) !== count( $values ) ) {
            return null;
        }
        foreach ( $values as $value ) {
            if ( strlen( $value ) > 80 || sanitize_text_field( $value ) !== $value ) {
                return null;
            }
        }
        return $values;
    }

    public static function parse_file( string $path ) {
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return self::error( 'No se puede leer el CSV.' );
        }
        $bytes = filesize( $path );
        if ( ! $bytes || $bytes > self::MAX_BYTES ) {
            return self::error( 'El CSV no puede superar 1 MB.' );
        }
        $fp = fopen( $path, 'rb' );
        if ( ! $fp ) {
            return self::error( 'No se pudo abrir el CSV.' );
        }
        try {
            $header = fgetcsv( $fp, 0, ';', '"', '' );
            if ( ! is_array( $header ) ) {
                return self::error( 'Falta la cabecera CSV.' );
            }
            $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
            $header = array_map( 'trim', $header );
            if ( $header !== self::HEADER ) {
                return self::error( 'Cabecera incorrecta: usa el CSV de creación con 11 columnas (no el CSV de actualización de precios).' );
            }
            $result = array();
            $line = 1;
            while ( false !== ( $raw = fgetcsv( $fp, 0, ';', '"', '' ) ) ) {
                ++$line;
                if ( 1 === count( $raw ) && ( null === $raw[0] || '' === trim( (string) $raw[0] ) ) ) {
                    continue;
                }
                if ( count( $result ) >= self::MAX_PRODUCTS || count( $raw ) !== 11 ) {
                    return self::error( 'Fila ' . $line . ': máximo 80 productos y exactamente 11 columnas.' );
                }
                $row = array_combine( self::HEADER, $raw );
                foreach ( $row as $key => $value ) {
                    if ( ! is_string( $value ) || strlen( $value ) > 2500 ) {
                        return self::error( 'Fila ' . $line . ': campo demasiado largo o incorrecto.' );
                    }
                    $row[ $key ] = trim( $value );
                }
                $sku = $row['sku'];
                $name = $row['nombre'];
                if ( ! preg_match( '/^[A-Za-z0-9_-]{3,80}$/D', $sku ) ||
                     '' === $name || strlen( $name ) > 200 ||
                     sanitize_text_field( $name ) !== $name ||
                     ! in_array( $row['familia'], array( 'colchon', 'canape', 'base', 'pack' ), true ) ||
                     strlen( $row['descripcion'] ) > 500 ||
                     sanitize_text_field( $row['descripcion'] ) !== $row['descripcion'] ) {
                    return self::error( 'Fila ' . $line . ': SKU, nombre, descripción o familia no válidos.' );
                }
                $options = array();
                foreach ( array(
                    'size' => 'medidas', 'color' => 'colores',
                    'thickness' => 'alturas', 'opening' => 'aperturas',
                ) as $key => $column ) {
                    $options[ $key ] = self::options( $row[ $column ] );
                    if ( null === $options[ $key ] ) {
                        return self::error( 'Fila ' . $line . ': opciones duplicadas o inválidas.' );
                    }
                }
                if ( ! $options['size'] ) {
                    return self::error( 'Fila ' . $line . ': añade al menos una medida.' );
                }
                $prices = self::options( $row['precios'] );
                if ( null === $prices || count( $prices ) !== count( $options['size'] ) ) {
                    return self::error( 'Fila ' . $line . ': el número de precios debe coincidir con las medidas.' );
                }
                $matrix = WVM_Pricing::validate_matrix( $options['size'], $options['size'], $prices );
                if ( null === $matrix ) {
                    return self::error( 'Fila ' . $line . ': precio no válido o duplicado.' );
                }
                foreach ( $matrix as &$price ) {
                    $price = wc_format_decimal( $price, wc_get_price_decimals() );
                    if ( ! is_numeric( $price ) || (float) $price <= 0 ) {
                        return self::error( 'Fila ' . $line . ': precio inválido tras redondear.' );
                    }
                }
                unset( $price );
                $extra_attrs = $extra_values = $extra_amounts = array();
                if ( '' !== $row['suplementos'] ) {
                    foreach ( explode( '|', $row['suplementos'] ) as $entry ) {
                        if ( ! preg_match( '/^(color|thickness|opening):([^=]+)=(\d{1,9}(?:[.,]\d{1,4})?)$/D', trim( $entry ), $m ) ) {
                            return self::error( 'Fila ' . $line . ': suplemento incorrecto.' );
                        }
                        $extra_attrs[] = $m[1];
                        $extra_values[] = trim( $m[2] );
                        $extra_amounts[] = $m[3];
                    }
                }
                $surcharges = WVM_Rules::validate_surcharges( $options, $extra_attrs, $extra_values, $extra_amounts );
                if ( null === $surcharges ) {
                    return self::error( 'Fila ' . $line . ': suplementos duplicados o sin opción válida.' );
                }
                foreach ( $surcharges as &$amounts ) {
                    foreach ( $amounts as &$amount ) {
                        $amount = wc_format_decimal( $amount, wc_get_price_decimals() );
                    }
                    unset( $amount );
                }
                unset( $amounts );

                $a_attr = $a_value = $b_attr = $b_value = array();
                if ( '' !== $row['incompatibilidades'] ) {
                    foreach ( explode( '|', $row['incompatibilidades'] ) as $entry ) {
                        $parts = explode( '>', trim( $entry ), 2 );
                        if ( 2 !== count( $parts ) ) {
                            return self::error( 'Fila ' . $line . ': regla incompatible mal formada.' );
                        }
                        $a = explode( ':', $parts[0], 2 );
                        $b = explode( ':', $parts[1], 2 );
                        if ( 2 !== count( $a ) || 2 !== count( $b ) ) {
                            return self::error( 'Fila ' . $line . ': regla incompatible incorrecta.' );
                        }
                        $a_attr[] = trim( $a[0] );
                        $a_value[] = trim( $a[1] );
                        $b_attr[] = trim( $b[0] );
                        $b_value[] = trim( $b[1] );
                    }
                }
                $exclusions = WVM_Rules::validate_exclusions( $options, $a_attr, $a_value, $b_attr, $b_value );
                if ( null === $exclusions ) {
                    return self::error( 'Fila ' . $line . ': incompatibilidades inválidas.' );
                }
                $result[] = array(
                    'sku' => $sku, 'name' => $name, 'family' => $row['familia'],
                    'description' => $row['descripcion'], 'options' => $options,
                    'matrix' => $matrix, 'surcharges' => $surcharges,
                    'exclusions' => $exclusions,
                );
            }
            if ( ! $result ) {
                return self::error( 'El CSV de creación no contiene productos.' );
            }
            return $result;
        } finally {
            fclose( $fp );
        }
    }

    /** No writes; reject all duplicates, including products from earlier imports. */
    public static function plan( array $rows ) {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_products' ) ) {
            return self::error( 'No tienes permisos para importar productos.' );
        }
        if ( ! $rows || count( $rows ) > self::MAX_PRODUCTS ) {
            return self::error( 'Límite de 80 productos por archivo.' );
        }
        $seen = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['sku'], $row['options'], $row['matrix'] ) ||
                 isset( $seen[ $row['sku'] ] ) || wc_get_product_id_by_sku( $row['sku'] ) ) {
                return self::error( 'SKU duplicado o ya existente: ' . ( $row['sku'] ?? 'desconocido' ) . '. Ningún producto creado.' );
            }
            $seen[ $row['sku'] ] = true;
        }
        return array(
            'products' => $rows,
            'total' => count( $rows ),
            'prices' => array_sum( array_map( static fn( array $row ): int => count( $row['matrix'] ), $rows ) ),
        );
    }

    private static function template( array $row, array &$created ): int {
        $signature = hash( 'sha256', wp_json_encode( $row['options'] ) );
        $existing = get_posts( array(
            'post_type' => 'wvm_template', 'post_status' => 'publish',
            'posts_per_page' => 10, 'fields' => 'ids',
            'meta_key' => self::TEMPLATE_META, 'meta_value' => $signature,
        ) );
        foreach ( $existing as $id ) {
            if ( WVM_Templates::active_options( (int) $id ) === $row['options'] ) {
                return (int) $id;
            }
        }
        $id = wp_insert_post( array(
            'post_type' => 'wvm_template', 'post_status' => 'publish',
            'post_title' => 'Plantilla CSV ' . $row['family'] . ' ' . substr( $signature, 0, 7 ),
        ), true );
        if ( is_wp_error( $id ) || ! $id ) {
            throw new RuntimeException( 'No se pudo crear la plantilla del catálogo.' );
        }
        $created[] = (int) $id;
        update_post_meta( (int) $id, WVM_Templates::META_KEY, $row['options'] );
        update_post_meta( (int) $id, self::TEMPLATE_META, $signature );
        return (int) $id;
    }

    /** Creates only hidden, out-of-stock drafts. On failure removes batch changes. */
    public static function apply( array $plan ) {
        $verified = self::plan( $plan['products'] ?? array() );
        if ( is_wp_error( $verified ) ) {
            return $verified;
        }
        $created_products = $created_templates = array();
        $template_ids = array();
        try {
            foreach ( $verified['products'] as $row ) {
                if ( wc_get_product_id_by_sku( $row['sku'] ) ) {
                    throw new RuntimeException( 'Se detectó un SKU duplicado mientras se importaba.' );
                }
                $signature = hash( 'sha256', wp_json_encode( $row['options'] ) );
                if ( ! isset( $template_ids[ $signature ] ) ) {
                    $template_ids[ $signature ] = self::template( $row, $created_templates );
                }
                $template_id = $template_ids[ $signature ];
                $extras = array( 'attr' => array(), 'value' => array(), 'amount' => array() );
                foreach ( $row['surcharges'] as $attr => $values ) {
                    foreach ( $values as $option => $price ) {
                        $extras['attr'][] = $attr;
                        $extras['value'][] = (string) $option;
                        $extras['amount'][] = $price;
                    }
                }
                $rules = array( 'a_attr' => array(), 'a_value' => array(), 'b_attr' => array(), 'b_value' => array() );
                foreach ( $row['exclusions'] as $rule ) {
                    foreach ( array_keys( $rules ) as $key ) {
                        $rules[ $key ][] = $rule[ $key ];
                    }
                }
                $prepared = WVM_Products::prepare(
                    $template_id, array_keys( $row['matrix'] ), array_values( $row['matrix'] ),
                    $extras, $rules
                );
                if ( is_wp_error( $prepared ) ) {
                    throw new RuntimeException( 'Una plantilla ha cambiado durante la importación.' );
                }
                $product = new WC_Product_Simple();
                $product->set_name( $row['name'] );
                $product->set_sku( $row['sku'] );
                $product->set_description( $row['description'] );
                $product->set_short_description( 'Producto ficticio de prueba. No disponible para compra.' );
                $product->set_status( 'draft' );
                $product->set_catalog_visibility( 'hidden' );
                $product->set_manage_stock( false );
                $product->set_stock_quantity( null );
                $product->set_stock_status( 'outofstock' );
                WVM_Products::apply( $product, $prepared );
                $product->update_meta_data( self::CREATED_META, 'test-csv' );
                $id = $product->save();
                if ( ! $id ) {
                    throw new RuntimeException( 'No se pudo crear un borrador.' );
                }
                $created_products[] = (int) $id;
            }
            return array(
                'created' => count( $created_products ),
                'templates' => count( $created_templates ),
            );
        } catch ( Throwable $error ) {
            foreach ( array_reverse( $created_products ) as $id ) {
                $item = wc_get_product( $id );
                if ( $item ) {
                    $item->delete( true );
                }
            }
            foreach ( array_reverse( $created_templates ) as $id ) {
                wp_delete_post( $id, true );
            }
            return self::error( 'Importación cancelada por error: no se conservan productos del lote. Comprueba los SKU y vuelve a intentarlo.' );
        }
    }

    private static function url( array $args = array() ): string {
        return add_query_arg( array_merge( array( 'page' => 'wvm-bulk' ), $args ), admin_url( 'admin.php' ) );
    }

    private static function guard( string $action ): void {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Operación no permitida.' );
        }
        check_admin_referer( $action, 'wvm_nonce' );
    }

    private static function notice( string $msg, string $type = 'error' ): void {
        set_transient( 'wvm_catalog_notice_' . get_current_user_id(), array(
            'message' => $msg, 'type' => 'success' === $type ? 'success' : 'error',
        ), 300 );
        wp_safe_redirect( self::url() );
        exit;
    }

    private static function token(): string {
        $raw = isset( $_REQUEST['wvm_catalog_token'] ) && is_string( $_REQUEST['wvm_catalog_token'] )
            ? sanitize_text_field( wp_unslash( $_REQUEST['wvm_catalog_token'] ) ) : '';
        return preg_match( '/^[A-Za-z0-9]{32}$/D', $raw ) ? $raw : '';
    }

    private static function preview_data( string $token ): ?array {
        $saved = $token ? get_transient( self::PREVIEW_PREFIX . $token ) : false;
        return is_array( $saved ) && (int) ( $saved['owner'] ?? 0 ) === get_current_user_id() &&
            isset( $saved['plan']['products'] ) ? $saved['plan'] : null;
    }

    public static function render(): void {
        $key = 'wvm_catalog_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( is_array( $notice ) ) {
            delete_transient( $key );
            echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . '"><p>' .
                esc_html( $notice['message'] ) . '</p></div>';
        }
        echo '<section class="wvm-card wvm-bulk-card">';
        echo '<h2>Crear productos nuevos desde CSV (pruebas)</h2>';
        echo '<p>Este formulario crea <strong>productos nuevos</strong> con medidas, precios y opciones. No es el importador de actualización de tarifas de arriba.</p>';
        echo '<p><strong>Seguridad:</strong> todos los productos se crean como <strong>borradores ocultos y agotados</strong>; ningún producto se publica ni se pone a la venta. Los SKU existentes se rechazan.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="wvm_catalog_preview">';
        wp_nonce_field( 'wvm_catalog_preview', 'wvm_nonce' );
        echo '<p><label for="wvm_catalog_csv"><strong>CSV de creación (11 columnas, hasta 80 productos)</strong></label>';
        echo '<input type="file" id="wvm_catalog_csv" name="wvm_catalog_csv" accept=".csv,text/csv,text/plain" required></p>';
        echo '<p class="description">Campos: sku;nombre;familia;descripcion;medidas;precios;colores;alturas;aperturas;suplementos;incompatibilidades. Las listas se separan con |.</p>';
        submit_button( 'Previsualizar 30 productos sin crear', 'secondary', 'submit', false );
        echo '</form>';
        $token = self::token();
        $plan = self::preview_data( $token );
        if ( $token && ! $plan ) {
            echo '<p role="alert">La vista previa ha caducado o pertenece a otro usuario.</p>';
        }
        if ( $plan ) {
            echo '<h3>Confirmar creación de borradores</h3>';
            echo '<p><strong>' . esc_html( (string) $plan['total'] ) . ' productos nuevos y ' .
                esc_html( (string) $plan['prices'] ) . ' tarifas.</strong> Todavía no se ha creado nada.</p>';
            echo '<div class="wvm-bulk-scroll"><table class="widefat striped"><thead><tr><th>SKU</th><th>Nombre</th><th>Familia</th><th>Medidas</th></tr></thead><tbody>';
            foreach ( $plan['products'] as $row ) {
                echo '<tr><td>' . esc_html( $row['sku'] ) . '</td><td>' . esc_html( $row['name'] ) . '</td><td>' .
                    esc_html( $row['family'] ) . '</td><td>' . esc_html( (string) count( $row['matrix'] ) ) . '</td></tr>';
            }
            echo '</tbody></table></div><p>Las 30 fichas quedarán ocultas y fuera de stock. Deberás revisarlas individualmente antes de publicar nada.</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="wvm_catalog_apply">';
            echo '<input type="hidden" name="wvm_catalog_token" value="' . esc_attr( $token ) . '">';
            wp_nonce_field( 'wvm_catalog_apply_' . $token, 'wvm_nonce' );
            submit_button( 'Crear los productos como borradores', 'primary', 'submit', false );
            echo ' <a class="button button-secondary" href="' . esc_url( self::url() ) . '">Cancelar</a></form>';
        }
        echo '</section>';
    }

    public static function preview(): void {
        self::guard( 'wvm_catalog_preview' );
        $upload = $_FILES['wvm_catalog_csv'] ?? null;
        if ( ! is_array( $upload ) || UPLOAD_ERR_OK !== (int) ( $upload['error'] ?? -1 ) ||
             ! isset( $upload['tmp_name'] ) || ! is_string( $upload['tmp_name'] ) ||
             ! is_uploaded_file( $upload['tmp_name'] ) || (int) ( $upload['size'] ?? 0 ) > self::MAX_BYTES ) {
            self::notice( 'Sube un CSV de creación válido de hasta 1 MB.' );
        }
        $rows = self::parse_file( $upload['tmp_name'] );
        if ( is_wp_error( $rows ) ) {
            self::notice( $rows->get_error_message() );
        }
        $plan = self::plan( $rows );
        if ( is_wp_error( $plan ) ) {
            self::notice( $plan->get_error_message() );
        }
        $token = wp_generate_password( 32, false, false );
        set_transient( self::PREVIEW_PREFIX . $token, array(
            'owner' => get_current_user_id(), 'plan' => $plan,
        ), 15 * MINUTE_IN_SECONDS );
        wp_safe_redirect( self::url( array( 'wvm_catalog_token' => $token ) ) );
        exit;
    }

    public static function apply_preview(): void {
        $token = self::token();
        self::guard( 'wvm_catalog_apply_' . $token );
        $plan = self::preview_data( $token );
        if ( ! $plan ) {
            self::notice( 'La vista previa no está disponible. Vuelve a importar el CSV.' );
        }
        delete_transient( self::PREVIEW_PREFIX . $token );
        $result = self::apply( $plan );
        if ( is_wp_error( $result ) ) {
            self::notice( $result->get_error_message() );
        }
        self::notice(
            'Se han creado ' . $result['created'] . ' productos ficticios como borradores ocultos y agotados. Revisa Productos y tarifas.',
            'success'
        );
    }
}
