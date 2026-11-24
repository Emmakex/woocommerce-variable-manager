<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin-only, two-step CSV update inside the existing Variable Manager menu.
 * 1) Upload and preview. 2) Confirm against the exact current WooCommerce data.
 */
final class WVM_Bulk_Admin {
    private const CAPABILITY = 'manage_woocommerce';
    private const TRANSIENT_PREFIX = 'wvm_bulk_plan_';

    public static function init(): void {
        add_action( 'admin_post_wvm_bulk_preview', array( __CLASS__, 'preview' ) );
        add_action( 'admin_post_wvm_bulk_apply', array( __CLASS__, 'apply' ) );
        add_action( 'admin_post_wvm_bulk_export', array( __CLASS__, 'export' ) );
    }

    private static function page_url( array $args = array() ): string {
        return add_query_arg( array_merge( array( 'page' => 'wvm-bulk' ), $args ), admin_url( 'admin.php' ) );
    }

    private static function guard( string $nonce_action, string $nonce_field = 'wvm_nonce' ): void {
        if ( ! current_user_can( self::CAPABILITY ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            wp_die( 'Operación no permitida.' );
        }
        check_admin_referer( $nonce_action, $nonce_field );
    }

    private static function message( string $text, string $type = 'error' ): void {
        set_transient( 'wvm_bulk_notice_' . get_current_user_id(), array(
            'type' => 'success' === $type ? 'success' : 'error',
            'text' => $text,
        ), 300 );
        wp_safe_redirect( self::page_url() );
        exit;
    }

    private static function token(): string {
        $token = isset( $_REQUEST['wvm_token'] ) && is_string( $_REQUEST['wvm_token'] )
            ? sanitize_text_field( wp_unslash( $_REQUEST['wvm_token'] ) ) : '';
        return preg_match( '/^[A-Za-z0-9]{32}$/D', $token ) ? $token : '';
    }

    private static function preview_data( string $token ): ?array {
        if ( '' === $token ) {
            return null;
        }
        $data = get_transient( self::TRANSIENT_PREFIX . $token );
        if ( ! is_array( $data ) || (int) ( $data['owner'] ?? 0 ) !== get_current_user_id() ||
             ! isset( $data['plan'] ) || ! is_array( $data['plan'] ) ) {
            return null;
        }
        return $data;
    }

    public static function render(): void {
        WVM_Dashboard::start( 'Variable Manager · Importar y exportar tarifas', 'wvm-bulk' );
        $notice_key = 'wvm_bulk_notice_' . get_current_user_id();
        $notice = get_transient( $notice_key );
        if ( is_array( $notice ) ) {
            delete_transient( $notice_key );
            echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . '"><p>' . esc_html( $notice['text'] ) . '</p></div>';
        }
        $token = self::token();
        $preview = self::preview_data( $token );

        echo '<div class="wvm-card wvm-bulk-card">';
        echo '<h2>1. Exportar tarifas</h2>';
        echo '<p>Descarga un CSV de productos gestionados por Variable Manager. El archivo contiene <code>sku;medida;precio</code>, una línea por medida.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="wvm_bulk_export">';
        wp_nonce_field( 'wvm_bulk_export', 'wvm_nonce' );
        submit_button( 'Descargar CSV de tarifas', 'secondary', 'submit', false );
        echo '</form></div>';

        echo '<div class="wvm-card wvm-bulk-card"><h2>2. Importar o actualizar precios</h2>';
        echo '<p>Modifica el CSV exportado o prepara uno nuevo. El sistema solo actualizará <strong>precios de medidas de productos ya configurados</strong>.</p>';
        echo '<p>No cambiará colores, suplementos, stock, descripciones, imágenes, descuentos ni pedidos. Los productos con ofertas activas no se modifican.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="wvm_bulk_preview">';
        wp_nonce_field( 'wvm_bulk_preview', 'wvm_nonce' );
        echo '<p><label for="wvm_csv"><strong>Archivo CSV (UTF-8, separado por punto y coma, máximo 1 MB)</strong></label>';
        echo '<input id="wvm_csv" name="wvm_csv" type="file" accept=".csv,text/csv,text/plain" required></p>';
        echo '<p class="description">Cabecera: <code>sku;medida;precio</code>. Ejemplo: <code>COL-001;135x190;349,90</code>. Se permiten hasta 1.000 líneas / 80 productos por lote.</p>';
        submit_button( 'Analizar cambios (sin guardar)', 'primary', 'submit', false );
        echo '</form></div>';

        if ( $token && ! $preview ) {
            echo '<div class="notice notice-warning"><p>La vista previa ha caducado o no pertenece a tu usuario. Vuelve a cargar el CSV.</p></div>';
        }
        if ( $preview ) {
            $plan = $preview['plan'];
            echo '<div class="wvm-card wvm-bulk-card"><h2>3. Revisar y confirmar</h2>';
            echo '<p><strong>' . esc_html( (string) count( $plan['products'] ) ) . ' productos, ' .
                esc_html( (string) $plan['rows'] ) . ' filas y ' .
                esc_html( (string) $plan['changed_prices'] ) . ' precios modificados.</strong></p>';
            echo '<p>Esto es solo una vista previa: todavía no se ha cambiado nada.</p>';
            echo '<div class="wvm-bulk-scroll"><table class="widefat striped"><thead><tr><th>SKU</th><th>Producto</th><th>Medida</th><th>Antes</th><th>Después</th></tr></thead><tbody>';
            $shown = 0;
            foreach ( $plan['products'] as $item ) {
                foreach ( $item['after'] as $size => $amount ) {
                    if ( abs( (float) $amount - (float) $item['before'][ $size ] ) < 0.000001 ) {
                        continue;
                    }
                    if ( ++$shown > 60 ) {
                        break 2;
                    }
                    echo '<tr><td>' . esc_html( $item['sku'] ) . '</td><td>' . esc_html( $item['name'] ) . '</td><td>' .
                        esc_html( (string) $size ) . '</td><td>' .
                        esc_html( wc_format_localized_price( $item['before'][ $size ] ) ) . '</td><td><strong>' .
                        esc_html( wc_format_localized_price( $amount ) ) . '</strong></td></tr>';
                }
            }
            echo '</tbody></table></div>';
            if ( $plan['changed_prices'] > 60 ) {
                echo '<p>Se muestran las primeras 60 diferencias; las demás también se comprobarán antes de guardar.</p>';
            }
            echo '<p class="description">Esta vista previa caduca a los 15 minutos y queda asociada a tu usuario. Si otro administrador cambia un producto mientras tanto, se cancelará la importación.</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="wvm_bulk_apply">';
            echo '<input type="hidden" name="wvm_token" value="' . esc_attr( $token ) . '">';
            wp_nonce_field( 'wvm_bulk_apply_' . $token, 'wvm_nonce' );
            submit_button( 'Confirmar y guardar tarifas', 'primary', 'submit', false );
            echo ' <a class="button button-secondary" href="' . esc_url( self::page_url() ) . '">Cancelar</a></form></div>';
        }
        WVM_Catalog_Import::render();
        echo '</div>';
    }

    public static function preview(): void {
        self::guard( 'wvm_bulk_preview' );
        $upload = $_FILES['wvm_csv'] ?? null;
        if ( ! is_array( $upload ) || UPLOAD_ERR_OK !== (int) ( $upload['error'] ?? -1 ) ||
             ! isset( $upload['tmp_name'] ) || ! is_string( $upload['tmp_name'] ) ||
             ! is_uploaded_file( $upload['tmp_name'] ) || (int) ( $upload['size'] ?? 0 ) > WVM_Bulk::MAX_BYTES ) {
            self::message( 'No se ha subido un CSV válido de hasta 1 MB.' );
        }
        $rows = WVM_Bulk::parse_file( $upload['tmp_name'] );
        if ( is_wp_error( $rows ) ) {
            self::message( $rows->get_error_message() );
        }
        $plan = WVM_Bulk::plan( $rows );
        if ( is_wp_error( $plan ) ) {
            self::message( $plan->get_error_message() );
        }
        $token = wp_generate_password( 32, false, false );
        set_transient( self::TRANSIENT_PREFIX . $token, array(
            'owner' => get_current_user_id(), 'plan' => $plan,
        ), 15 * MINUTE_IN_SECONDS );
        wp_safe_redirect( self::page_url( array( 'wvm_token' => $token ) ) );
        exit;
    }

    public static function apply(): void {
        self::guard( 'wvm_bulk_apply_' . self::token() );
        $token = self::token();
        $data = self::preview_data( $token );
        if ( ! $data ) {
            self::message( 'La vista previa no está disponible. Vuelve a importar el CSV.' );
        }
        // Consume before writing to prevent accidental double submission.
        delete_transient( self::TRANSIENT_PREFIX . $token );
        $result = WVM_Bulk::apply( $data['plan'] );
        if ( is_wp_error( $result ) ) {
            self::message( $result->get_error_message() );
        }
        self::message(
            'Actualización realizada: ' . $result['updated_products'] .
            ' productos y ' . $result['changed_prices'] . ' precios modificados.',
            'success'
        );
    }

    public static function export(): void {
        self::guard( 'wvm_bulk_export' );
        if ( headers_sent() ) {
            wp_die( 'No se puede generar el CSV porque ya hay contenido en la respuesta.' );
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="variable-manager-tarifas-' . gmdate( 'Ymd' ) . '.csv"' );
        header( 'X-Content-Type-Options: nosniff' );
        $out = fopen( 'php://output', 'wb' );
        if ( false === $out ) {
            wp_die( 'No se puede abrir la descarga CSV.' );
        }
        fwrite( $out, "\xEF\xBB\xBF" );
        WVM_Bulk::write_export( $out );
        fclose( $out );
        exit;
    }
}
