<?php
defined( 'ABSPATH' ) || exit;

/**
 * Focused CSV price update service.
 * File format: sku;medida;precio (semicolon separated, UTF-8).
 * Only existing, configured simple products with SKU may be edited.
 */
final class WVM_Bulk {
    public const MAX_BYTES = 1048576;
    public const MAX_ROWS = 1000;
    public const MAX_PRODUCTS = 80;

    private static function error( string $message ): WP_Error {
        return new WP_Error( 'wvm_bulk', $message );
    }

    private static function spreadsheet_value( string $value ): string {
        // Counterpart to export's spreadsheet formula-injection escaping.
        if ( strlen( $value ) > 1 && "'" === $value[0] && preg_match( '/^[=+\-@\t\r]/', substr( $value, 1 ) ) ) {
            return substr( $value, 1 );
        }
        return $value;
    }

    /** @return array|WP_Error */
    public static function parse_file( string $path ) {
        if ( ! is_readable( $path ) || ! is_file( $path ) ) {
            return self::error( 'El archivo CSV no se puede leer.' );
        }
        $size = filesize( $path );
        if ( false === $size || 0 === $size || $size > self::MAX_BYTES ) {
            return self::error( 'El CSV debe tener contenido y no superar 1 MB.' );
        }
        $stream = fopen( $path, 'rb' );
        if ( false === $stream ) {
            return self::error( 'No se pudo abrir el CSV.' );
        }
        try {
            $header = fgetcsv( $stream, 0, ';', '"', '' );
            if ( ! is_array( $header ) || 3 !== count( $header ) ) {
                return self::error( 'Cabecera inválida. Usa: sku;medida;precio.' );
            }
            $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
            $header = array_map( static fn( $value ): string => strtolower( trim( (string) $value ) ), $header );
            if ( array( 'sku', 'medida', 'precio' ) !== $header ) {
                return self::error( 'Cabecera inválida. Usa: sku;medida;precio.' );
            }

            $rows = array();
            $line = 1;
            while ( false !== ( $data = fgetcsv( $stream, 0, ';', '"', '' ) ) ) {
                ++$line;
                if ( 1 === count( $data ) && ( null === $data[0] || '' === trim( $data[0] ) ) ) {
                    continue;
                }
                if ( count( $rows ) >= self::MAX_ROWS ) {
                    return self::error( 'Límite de 1.000 filas por importación. Divide el CSV.' );
                }
                if ( 3 !== count( $data ) || ! is_string( $data[0] ) || ! is_string( $data[1] ) || ! is_string( $data[2] ) ) {
                    return self::error( 'Fila ' . $line . ': deben existir exactamente SKU, medida y precio.' );
                }
                $sku  = self::spreadsheet_value( trim( $data[0] ) );
                $size = self::spreadsheet_value( trim( $data[1] ) );
                $price = trim( $data[2] );
                if ( '' === $sku || '' === $size || '' === $price ||
                     strlen( $sku ) > 200 || strlen( $size ) > 200 ||
                     ! preg_match( '/^\d{1,9}([.,]\d{1,4})?$/D', $price ) ||
                     (float) str_replace( ',', '.', $price ) <= 0 ) {
                    return self::error( 'Fila ' . $line . ': SKU, medida o precio no válido.' );
                }
                $rows[] = array( 'sku' => $sku, 'size' => $size, 'price' => str_replace( ',', '.', $price ) );
            }
            if ( ! $rows ) {
                return self::error( 'El CSV no contiene filas de tarifas.' );
            }
            return $rows;
        } finally {
            fclose( $stream );
        }
    }

    public static function fingerprint( WC_Product $product, array $config ): string {
        return hash( 'sha256', wp_json_encode( array(
            'id'        => $product->get_id(),
            'sku'       => $product->get_sku(),
            'template'  => $config['template_id'],
            'options'   => $config['options'],
            'prices'    => $config['prices'],
            'extras'    => $config['surcharges'],
            'rules'     => $config['exclusions'],
            'regular'   => $product->get_regular_price(),
            'sale'      => $product->get_sale_price(),
            'status'    => $product->get_status(),
        ) ) );
    }

    /** Perform a complete preflight without writing any product. */
    public static function plan( array $rows ) {
        if ( ! $rows || count( $rows ) > self::MAX_ROWS ) {
            return self::error( 'La importación supera el tamaño permitido.' );
        }
        $requested = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['sku'], $row['size'], $row['price'] ) ||
                 ! is_string( $row['sku'] ) || ! is_string( $row['size'] ) || ! is_string( $row['price'] ) ) {
                return self::error( 'Se encontraron filas de tarifas mal formadas.' );
            }
            $sku = $row['sku'];
            $size = $row['size'];
            if ( isset( $requested[ $sku ][ $size ] ) ) {
                return self::error( "La medida {$size} está repetida para SKU {$sku}." );
            }
            $requested[ $sku ][ $size ] = $row['price'];
        }
        if ( count( $requested ) > self::MAX_PRODUCTS ) {
            return self::error( 'Límite de 80 productos por importación. Divide el CSV.' );
        }
        $plan = array();
        $changes = 0;
        foreach ( $requested as $sku => $updated ) {
            $id = wc_get_product_id_by_sku( (string) $sku );
            $product = $id ? wc_get_product( $id ) : null;
            if ( ! $product instanceof WC_Product_Simple || ! WVM_Products::is_managed( $product ) ||
                 ! current_user_can( 'edit_post', $id ) || ! in_array( $product->get_status(), array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
                return self::error( 'Producto no administrable o no encontrado: ' . $sku . '. Ningún cambio aplicado.' );
            }
            // Never remove an existing WooCommerce promotion without explicit review.
            if ( '' !== $product->get_sale_price() ) {
                return self::error( 'El SKU ' . $sku . ' tiene un precio de oferta. Exclúyelo del CSV.' );
            }
            $config = WVM_Products::configuration( $product );
            if ( ! $config ) {
                return self::error( 'El SKU ' . $sku . ' tiene una configuración incompleta.' );
            }
            $after = $config['prices'];
            $changes_here = 0;
            $sizes = $config['options']['size'];
            foreach ( $updated as $size => $price ) {
                if ( ! in_array( (string) $size, $sizes, true ) ) {
                    return self::error( 'Medida inexistente para ' . $sku . ': ' . $size . '.' );
                }
                $value = wc_format_decimal( $price, wc_get_price_decimals() );
                if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
                    return self::error( 'Precio inválido para ' . $sku . ' / ' . $size . '.' );
                }
                $after[ $size ] = $value;
                if ( abs( (float) $value - (float) $config['prices'][ $size ] ) > 0.000001 ) {
                    ++$changes;
                    ++$changes_here;
                }
            }
            if ( null === WVM_Pricing::validate_matrix( $sizes, array_keys( $after ), array_values( $after ) ) ) {
                return self::error( 'La matriz final del SKU ' . $sku . ' no es válida.' );
            }
            // Do not save unchanged products: avoids excess WooCommerce hooks.
            if ( 0 === $changes_here ) {
                continue;
            }
            $plan[] = array(
                'id'       => $id,
                'sku'      => $sku,
                'name'     => $product->get_name(),
                'fingerprint' => self::fingerprint( $product, $config ),
                'before'   => $config['prices'],
                'after'    => $after,
                'selected' => count( $updated ),
            );
        }
        if ( 0 === $changes ) {
            return self::error( 'Los precios del CSV coinciden con los actuales; no hay cambios.' );
        }
        return array( 'products' => $plan, 'rows' => count( $rows ), 'changed_prices' => $changes );
    }

    /**
     * Revalidate the full preview before applying anything. On an unexpected
     * save failure, restore previous product metadata/regular prices best-effort.
     * External hook side effects cannot be rolled back: staging validation required.
     */
    public static function apply( array $plan ) {
        if ( ! current_user_can( 'manage_woocommerce' ) || empty( $plan['products'] ) ) {
            return self::error( 'No hay una vista previa autorizada.' );
        }
        $ready = array();
        foreach ( $plan['products'] as $entry ) {
            $product = wc_get_product( (int) $entry['id'] );
            if ( ! $product instanceof WC_Product_Simple || ! current_user_can( 'edit_post', $product->get_id() ) ||
                 ! WVM_Products::is_managed( $product ) || '' !== $product->get_sale_price() ) {
                return self::error( 'Un producto ha cambiado. Importa de nuevo el archivo.' );
            }
            $config = WVM_Products::configuration( $product );
            if ( ! $config || self::fingerprint( $product, $config ) !== $entry['fingerprint'] ||
                 null === WVM_Pricing::validate_matrix(
                     $config['options']['size'], array_keys( $entry['after'] ), array_values( $entry['after'] )
                 ) ) {
                return self::error( 'Se han modificado productos o plantillas desde la vista previa. Vuelve a importar.' );
            }
            $ready[] = $product;
        }

        $written = array();
        try {
            foreach ( $ready as $index => $product ) {
                $entry = $plan['products'][ $index ];
                $written[] = array(
                    'id' => $product->get_id(),
                    'before' => $entry['before'],
                    'regular' => $product->get_regular_price(),
                    'price' => $product->get_price(),
                );
                $minimum = WVM_Pricing::minimum( $entry['after'] );
                $product->update_meta_data( WVM_Products::MATRIX_META, $entry['after'] );
                $product->set_regular_price( $minimum );
                $product->set_price( $minimum );
                $product->save();
            }
        } catch ( Throwable $e ) {
            $rollback_failed = false;
            foreach ( array_reverse( $written ) as $old ) {
                try {
                    $item = wc_get_product( $old['id'] );
                    if ( $item ) {
                        $item->update_meta_data( WVM_Products::MATRIX_META, $old['before'] );
                        $item->set_regular_price( $old['regular'] );
                        $item->set_price( $old['price'] );
                        $item->save();
                    }
                } catch ( Throwable $ignored ) {
                    $rollback_failed = true;
                }
            }
            return self::error( $rollback_failed
                ? 'Error durante el guardado y recuperación parcial. Revisa los productos antes de continuar.'
                : 'Error durante el guardado. Se ha intentado restaurar los precios anteriores.' );
        }
        return array( 'updated_products' => count( $ready ), 'changed_prices' => $plan['changed_prices'] );
    }

    public static function export_value( string $value ): string {
        return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
    }

    /** Stream UTF-8 CSV to an output resource without using direct SQL. */
    public static function write_export( $stream ): int {
        fputcsv( $stream, array( 'sku', 'medida', 'precio' ), ';', '"', '' );
        $written = 0;
        for ( $page = 1; $page <= 10000; ++$page ) {
            $products = wc_get_products( array(
                'type' => 'simple', 'limit' => 100, 'page' => $page,
                'status' => array( 'publish', 'draft', 'pending', 'private' ),
                'orderby' => 'ID', 'order' => 'ASC',
            ) );
            foreach ( $products as $product ) {
                if ( ! $product instanceof WC_Product_Simple || '' === $product->get_sku() ||
                     '' !== $product->get_sale_price() ) {
                    continue;
                }
                $config = WVM_Products::configuration( $product );
                if ( ! $config ) {
                    continue;
                }
                foreach ( $config['options']['size'] as $size ) {
                    fputcsv( $stream, array(
                        self::export_value( $product->get_sku() ),
                        self::export_value( (string) $size ),
                        $config['prices'][ $size ],
                    ), ';', '"', '' );
                    ++$written;
                }
            }
            if ( count( $products ) < 100 ) {
                break;
            }
        }
        return $written;
    }
}
