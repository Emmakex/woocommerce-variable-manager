<?php
defined( 'ABSPATH' ) || exit;

/**
 * Demo fixtures can only be written explicitly via WP-CLI on a non-production
 * WordPress installation. No synthetic products are created on activation.
 *
 * wp wvm demo preview
 * wp wvm demo seed --confirm-synthetic=YES
 * wp wvm demo purge --confirm-synthetic=YES
 */
final class WVM_Demo_CLI {
    private const MARKER = '_wvm_demo_synthetic';

    private static function fixtures(): array {
        $path = WVM_PATH . 'tests/fixtures/catalog-synthetic.json';
        if ( ! is_readable( $path ) ) {
            WP_CLI::error( 'No se encuentra el catálogo de demostración. El ZIP debe incluir tests/fixtures/catalog-synthetic.json.' );
        }
        $data = json_decode( (string) file_get_contents( $path ), true );
        if ( ! is_array( $data ) || ! isset( $data['products'] ) || ! is_array( $data['products'] ) ) {
            WP_CLI::error( 'El fichero de demostración no es válido.' );
        }
        return $data['products'];
    }

    private static function guard( array $assoc_args ): void {
        $environment = wp_get_environment_type();
        if ( ! in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) {
            WP_CLI::error( 'Bloqueado: solo se permite usar datos sintéticos en WP_ENVIRONMENT_TYPE=local, development o staging.' );
        }
        if ( ( $assoc_args['confirm-synthetic'] ?? '' ) !== 'YES' ) {
            WP_CLI::error( 'Confirma explícitamente con --confirm-synthetic=YES; no se crearán ni borrarán datos sin ello.' );
        }
    }

    private static function template( array $fixture ): int {
        $key = 'template:' . $fixture['sku'];
        $existing = get_posts( array(
            'post_type'      => 'wvm_template',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => self::MARKER,
            'meta_value'     => $key,
        ) );
        if ( $existing ) {
            return (int) $existing[0];
        }
        $id = wp_insert_post( array(
            'post_type'   => 'wvm_template',
            'post_title'  => '[DEMO] ' . $fixture['name'],
            'post_status' => 'publish',
        ), true );
        if ( is_wp_error( $id ) || ! $id ) {
            WP_CLI::error( 'No se pudo crear la plantilla sintética de ' . $fixture['sku'] );
        }
        update_post_meta( (int) $id, WVM_Templates::META_KEY, $fixture['options'] );
        update_post_meta( (int) $id, self::MARKER, $key );
        return (int) $id;
    }

    private static function prepared( int $template_id, array $fixture ) {
        $extras = array( 'attr' => array(), 'value' => array(), 'amount' => array() );
        foreach ( $fixture['surcharges'] as $attr => $values ) {
            foreach ( $values as $value => $amount ) {
                $extras['attr'][] = $attr;
                $extras['value'][] = $value;
                $extras['amount'][] = (string) $amount;
            }
        }
        $rules = array( 'a_attr' => array(), 'a_value' => array(), 'b_attr' => array(), 'b_value' => array() );
        foreach ( $fixture['exclusions'] as $rule ) {
            foreach ( array_keys( $rules ) as $key ) {
                $rules[ $key ][] = $rule[ $key ];
            }
        }
        return WVM_Products::prepare(
            $template_id,
            array_keys( $fixture['matrix'] ),
            array_values( $fixture['matrix'] ),
            $extras, $rules
        );
    }

    /**
     * Read the fictional catalog without writing data.
     *
     * ## EXAMPLES
     * wp wvm demo preview
     */
    public function preview( array $args, array $assoc_args ): void {
        $fixtures = self::fixtures();
        $lines = array();
        foreach ( $fixtures as $fixture ) {
            $lines[] = array(
                'SKU' => $fixture['sku'],
                'Familia' => $fixture['family'],
                'Producto' => $fixture['name'],
                'Medidas' => count( $fixture['options']['size'] ),
            );
        }
        WP_CLI\Utils\format_items( 'table', $lines, array( 'SKU', 'Familia', 'Producto', 'Medidas' ) );
        WP_CLI::log( count( $fixtures ) . ' productos ficticios. Vista previa sin modificaciones.' );
    }

    /**
     * Create synthetically configured products as DRAFTS. Existing demo SKUs
     * are skipped rather than overwritten; conflicting real SKUs fail closed.
     *
     * ## OPTIONS
     * [--confirm-synthetic=<confirmation>]
     * : Must be the literal string YES.
     *
     * ## EXAMPLES
     * wp wvm demo seed --confirm-synthetic=YES
     */
    public function seed( array $args, array $assoc_args ): void {
        self::guard( $assoc_args );
        $fixtures = self::fixtures();
        $count = 0;
        $skipped = 0;
        // Validate SKU ownership before creating anything.
        foreach ( $fixtures as $fixture ) {
            $id = wc_get_product_id_by_sku( $fixture['sku'] );
            if ( $id ) {
                $existing = wc_get_product( $id );
                if ( ! $existing || $existing->get_meta( self::MARKER, true ) !== $fixture['sku'] ) {
                    WP_CLI::error( 'SKU ya utilizado por un producto no sintético: ' . $fixture['sku'] );
                }
            }
        }
        foreach ( $fixtures as $fixture ) {
            $id = wc_get_product_id_by_sku( $fixture['sku'] );
            if ( $id ) {
                $skipped++;
                continue;
            }
            $template_id = self::template( $fixture );
            $prepared = self::prepared( $template_id, $fixture );
            if ( is_wp_error( $prepared ) ) {
                WP_CLI::error( 'Datos sintéticos no válidos para ' . $fixture['sku'] . ': ' . $prepared->get_error_message() );
            }
            $product = new WC_Product_Simple();
            $product->set_name( $fixture['name'] );
            $product->set_sku( $fixture['sku'] );
            $product->set_description( 'Producto y tarifa sintéticos para pruebas de Variable Manager. NO COMERCIALIZAR.' );
            $product->set_short_description( 'DEMO / SIN INVENTARIO REAL.' );
            $product->set_status( 'draft' );
            $product->set_catalog_visibility( 'hidden' );
            $product->set_manage_stock( false );
            $product->set_stock_status( 'outofstock' );
            WVM_Products::apply( $product, $prepared );
            $product->update_meta_data( self::MARKER, $fixture['sku'] );
            $product->save();
            $count++;
        }
        WP_CLI::success( "{$count} productos de prueba creados como borradores no vendibles, {$skipped} omitidos (ya existían). Ningún pedido ni pago creado." );
    }

    /**
     * Remove only products and templates carrying the synthetic marker.
     *
     * ## OPTIONS
     * [--confirm-synthetic=<confirmation>]
     * : Must be the literal string YES.
     *
     * ## EXAMPLES
     * wp wvm demo purge --confirm-synthetic=YES
     */
    public function purge( array $args, array $assoc_args ): void {
        self::guard( $assoc_args );
        $products = get_posts( array(
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => self::MARKER,
            'meta_compare'   => 'EXISTS',
        ) );
        $removed = 0;
        foreach ( $products as $id ) {
            $product = wc_get_product( (int) $id );
            if ( $product && $product->get_meta( self::MARKER, true ) ) {
                $product->delete( true );
                $removed++;
            }
        }
        $templates = get_posts( array(
            'post_type'      => 'wvm_template',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => self::MARKER,
            'meta_compare'   => 'EXISTS',
        ) );
        $removedTemplates = 0;
        foreach ( $templates as $id ) {
            if ( get_post_meta( (int) $id, self::MARKER, true ) ) {
                wp_delete_post( (int) $id, true );
                $removedTemplates++;
            }
        }
        WP_CLI::success( "{$removed} productos y {$removedTemplates} plantillas sintéticas eliminados. Solo se borraron registros etiquetados como demo." );
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'wvm demo', 'WVM_Demo_CLI' );
}
