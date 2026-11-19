<?php
defined( 'ABSPATH' ) || exit;

/**
 * Convenience operations over WooCommerce catalog CRUD.
 * Never copies third-party metadata or stock quantities to a duplicate.
 */
final class WVM_Catalog {
    /**
     * Name/title search with exact SKU lookup as a fast path.
     * Always keep the main listing inside WooCommerce product APIs.
     */
    public const STATUSES = array(
        'all'     => 'Todos los estados',
        'publish' => 'Publicados',
        'draft'   => 'Borradores',
        'pending' => 'Pendientes',
        'private' => 'Privados',
    );

    public static function allowed_status( string $status ): string {
        return isset( self::STATUSES[ $status ] ) ? $status : 'all';
    }

    /**
     * Paged title or exact SKU search with optional managed-only and status
     * filters. Uses native WooCommerce product queries; no custom SQL.
     *
     * @param array $filters ['managed' => bool, 'status' => valid status, 'per_page' => int].
     */
    public static function search( string $term, int $page = 1, array $filters = array() ) {
        $page = max( 1, $page );
        $status = self::allowed_status( (string) ( $filters['status'] ?? 'all' ) );
        $allowed_statuses = array( 'publish', 'draft', 'pending', 'private' );
        $statuses = 'all' === $status ? $allowed_statuses : array( $status );
        $managed = ! empty( $filters['managed'] );
        $limit = min( 20, max( 1, (int) ( $filters['per_page'] ?? 20 ) ) );
        $args = array(
            'type'     => 'simple',
            'limit'    => $limit,
            'paginate' => true,
            'page'     => $page,
            'status'   => $statuses,
            'orderby'  => 'date',
            'order'    => 'DESC',
        );
        if ( $managed ) {
            $args['wvm_managed'] = true;
        }
        $term = trim( $term );
        if ( '' !== $term ) {
            $sku_id = wc_get_product_id_by_sku( $term );
            $sku_product = $sku_id ? wc_get_product( $sku_id ) : null;
            // An exact SKU lookup always takes precedence over text search.
            // If this SKU fails the selected status/managed filter, return no
            // records; falling back to the broad search could expose a product
            // that doesn't match the requested constraints.
            if ( $sku_product instanceof WC_Product_Simple ) {
                $matches = in_array( $sku_product->get_status(), $statuses, true ) &&
                    ( ! $managed || WVM_Products::is_managed( $sku_product ) );
                return (object) array(
                    'products'      => $matches && 1 === $page ? array( $sku_product ) : array(),
                    'total'         => $matches ? 1 : 0,
                    'max_num_pages' => 1,
                );
            }
            $args['search'] = $term;
        }
        return wc_get_products( $args );
    }

    /**
     * Clone product content and valid WVM pricing into a private DRAFT.
     * Deliberately never copy SKU, stock, sale state, demo markers or unknown metadata.
     *
     * @return int|WP_Error
     */
    public static function duplicate( int $source_id ) {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $source_id ) ) {
            return new WP_Error( 'wvm_forbidden', 'No tienes permiso para duplicar este producto.' );
        }
        $source = wc_get_product( $source_id );
        if ( ! $source instanceof WC_Product_Simple || ! WVM_Products::is_managed( $source ) ) {
            return new WP_Error( 'wvm_unmanaged', 'Este producto no está gestionado por Variable Manager.' );
        }
        $config = WVM_Products::configuration( $source );
        if ( ! $config ) {
            return new WP_Error( 'wvm_incomplete', 'La configuración de origen es inválida. No se puede duplicar.' );
        }
        $minimum = WVM_Pricing::minimum( $config['prices'] );
        if ( ! $minimum ) {
            return new WP_Error( 'wvm_price', 'El producto de origen no tiene tarifas válidas.' );
        }

        try {
            $copy = new WC_Product_Simple();
            $name = $source->get_name() . ' (copia)';
            $copy->set_name( strlen( $name ) > 200 ? substr( $name, 0, 200 ) : $name );
            $copy->set_status( 'draft' );
            $copy->set_catalog_visibility( 'hidden' );
            $copy->set_sku( '' );
            $copy->set_description( $source->get_description() );
            $copy->set_short_description( $source->get_short_description() );
            $copy->set_image_id( $source->get_image_id() );
            $copy->set_gallery_image_ids( $source->get_gallery_image_ids() );
            $copy->set_category_ids( $source->get_category_ids() );
            $copy->set_tag_ids( $source->get_tag_ids() );
            $copy->set_tax_status( $source->get_tax_status() );
            $copy->set_tax_class( $source->get_tax_class() );
            $copy->set_shipping_class_id( $source->get_shipping_class_id() );
            $copy->set_weight( $source->get_weight() );
            $copy->set_length( $source->get_length() );
            $copy->set_width( $source->get_width() );
            $copy->set_height( $source->get_height() );
            $copy->set_manage_stock( false );
            $copy->set_stock_quantity( null );
            $copy->set_stock_status( 'outofstock' );
            WVM_Products::apply( $copy, array(
                'template_id' => $config['template_id'],
                'matrix'      => $config['prices'],
                'minimum'     => $minimum,
                'surcharges'  => $config['surcharges'],
                'exclusions'  => $config['exclusions'],
            ) );
            $copy->set_sale_price( '' );
            $id = $copy->save();
            if ( ! $id ) {
                return new WP_Error( 'wvm_save', 'No se pudo crear el borrador.' );
            }
            return (int) $id;
        } catch ( Throwable $e ) {
            return new WP_Error( 'wvm_save', 'No se ha podido duplicar el producto de forma segura.' );
        }
    }
}
