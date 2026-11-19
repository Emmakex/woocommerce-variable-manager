<?php
defined( 'ABSPATH' ) || exit;

/**
 * One self-contained WooCommerce Variable Manager dashboard.
 * WordPress/WooCommerce remain the data stores, but no separate editors
 * are required for templates, configured products, or their price matrices.
 */
final class WVM_Dashboard {
    private const CAPABILITY = 'manage_woocommerce';

    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_post_wvm_save_template', array( __CLASS__, 'save_template' ) );
        add_action( 'admin_post_wvm_save_product', array( __CLASS__, 'save_product' ) );
        add_action( 'admin_post_wvm_duplicate_product', array( __CLASS__, 'duplicate_product' ) );
    }

    public static function menu(): void {
        add_menu_page(
            'Variable Manager', 'Variable Manager', self::CAPABILITY,
            'wvm-dashboard', array( __CLASS__, 'overview' ),
            'dashicons-screenoptions', 56
        );
        add_submenu_page( 'wvm-dashboard', 'Resumen', 'Resumen', self::CAPABILITY, 'wvm-dashboard', array( __CLASS__, 'overview' ) );
        add_submenu_page( 'wvm-dashboard', 'Plantillas', 'Plantillas', self::CAPABILITY, 'wvm-templates', array( __CLASS__, 'templates_page' ) );
        add_submenu_page( 'wvm-dashboard', 'Productos y tarifas', 'Productos y tarifas', self::CAPABILITY, 'wvm-products', array( __CLASS__, 'products_page' ) );
        add_submenu_page( 'wvm-dashboard', 'Importar / exportar', 'Importar / exportar', self::CAPABILITY, 'wvm-bulk', array( 'WVM_Bulk_Admin', 'render' ) );
        add_submenu_page( 'wvm-dashboard', 'Edición rápida', 'Edición rápida', self::CAPABILITY, 'wvm-quick', array( 'WVM_Quick_Edit', 'render' ) );
    }

    private static function url( string $page, array $extra = array() ): string {
        return add_query_arg( array_merge( array( 'page' => $page ), $extra ), admin_url( 'admin.php' ) );
    }

    private static function ensure_permission(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar el catálogo.', 'woocommerce-variable-manager' ) );
        }
    }

    public static function assets(): void {
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] )
            ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( ! in_array( $page, array( 'wvm-dashboard', 'wvm-templates', 'wvm-products', 'wvm-bulk', 'wvm-quick' ), true ) ) {
            return;
        }
        wp_enqueue_style( 'wvm-admin', WVM_URL . 'assets/admin.css', array(), WVM_VERSION );
        if ( 'wvm-quick' === $page ) {
            wp_enqueue_script( 'wvm-quick-edit', WVM_URL . 'assets/quick-edit.js', array(), WVM_VERSION, true );
            return;
        }
        if ( 'wvm-products' !== $page ) {
            return;
        }
        wp_enqueue_media();
        $product_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $product    = $product_id ? wc_get_product( $product_id ) : null;
        $templates  = array();
        foreach ( WVM_Templates::all() as $template ) {
            $templates[ (string) $template->ID ] = WVM_Templates::active_options( (int) $template->ID );
        }
        $matrix = $product instanceof WC_Product_Simple ? $product->get_meta( WVM_Products::MATRIX_META, true ) : array();
        $surcharges = $product instanceof WC_Product_Simple ? $product->get_meta( WVM_Rules::SURCHARGES_META, true ) : array();
        $exclusions = $product instanceof WC_Product_Simple ? $product->get_meta( WVM_Rules::EXCLUSIONS_META, true ) : array();
        wp_enqueue_script( 'wvm-admin', WVM_URL . 'assets/admin.js', array(), WVM_VERSION, true );
        wp_localize_script( 'wvm-admin', 'wvmAdminData', array(
            'templates'   => $templates,
            'savedPrices'     => is_array( $matrix ) ? $matrix : array(),
            'savedSurcharges' => is_array( $surcharges ) ? $surcharges : array(),
            'savedExclusions' => is_array( $exclusions ) ? $exclusions : array(),
            'labels'          => WVM_Templates::ATTRIBUTES,
        ) );
    }

    public static function start( string $title, string $active ): void {
        self::ensure_permission();
        echo '<div class="wrap wvm-dashboard"><h1>' . esc_html( $title ) . '</h1>';
        echo '<p class="description">Gestiona plantillas, productos y precios desde un único lugar. WooCommerce sigue gestionando pedidos y pagos.</p>';
        echo '<nav class="nav-tab-wrapper" aria-label="Navegación Variable Manager">';
        foreach ( array(
            'wvm-dashboard' => 'Resumen',
            'wvm-templates' => 'Plantillas',
            'wvm-products'  => 'Productos y tarifas',
            'wvm-bulk'      => 'Importar / exportar',
            'wvm-quick'     => 'Edición rápida',
        ) as $page => $label ) {
            $class = $active === $page ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::url( $page ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
        self::notice();
    }

    private static function notice(): void {
        $status = isset( $_GET['wvm_status'] ) && is_string( $_GET['wvm_status'] )
            ? sanitize_key( wp_unslash( $_GET['wvm_status'] ) ) : '';
        $messages = array(
            'template_saved'        => array( 'success', 'Plantilla guardada.' ),
            'product_saved'         => array( 'success', 'Producto y matriz de precios guardados.' ),
            'product_duplicated'    => array( 'success', 'Borrador duplicado: revisa su SKU, stock y visibilidad antes de publicar.' ),
            'quick_saved'           => array( 'success', 'Tarifas actualizadas. Se han conservado suplementos, reglas e inventario.' ),
            'quick_unchanged'       => array( 'success', 'No hay cambios de precio que guardar.' ),
            'quick_error'           => array( 'error', 'No se han actualizado las tarifas. Comprueba los importes y recarga la página si otro administrador ha realizado cambios.' ),
            'duplicate_failed'      => array( 'error', 'No se pudo duplicar este producto. Comprueba que su configuración siga siendo válida.' ),
            'invalid_template'      => array( 'error', 'Introduce un nombre y al menos una medida válida.' ),
            'template_sizes_in_use' => array( 'error', 'Una plantilla asignada a productos no puede cambiar sus opciones. Duplica la plantilla y configura los nuevos precios para evitar errores.' ),
            'invalid_rules'         => array( 'error', 'Revisa suplementos e incompatibilidades. No se ha guardado el producto.' ),
            'invalid_product'       => array( 'error', 'Revisa el nombre, la plantilla y los precios de todas las medidas.' ),
            'save_error'            => array( 'error', 'No se ha podido guardar. Revisa si el SKU ya existe y vuelve a intentarlo.' ),
        );
        if ( ! isset( $messages[ $status ] ) ) {
            return;
        }
        $message = $messages[ $status ];
        echo '<div class="notice notice-' . esc_attr( $message[0] ) . ' is-dismissible"><p>' . esc_html( $message[1] ) . '</p></div>';
    }

    public static function overview(): void {
        self::start( 'Variable Manager · Resumen', 'wvm-dashboard' );
        $templates = WVM_Templates::all();
        $query = new WP_Query( array(
            'post_type'      => 'product',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array( array(
                'key'     => WVM_Products::TEMPLATE_META,
                'compare' => 'EXISTS',
            ) ),
        ) );
        echo '<div class="wvm-cards">';
        echo '<section class="wvm-card"><h2>Plantillas activas</h2><strong>' . esc_html( (string) count( $templates ) ) . '</strong><p>Medidas y opciones reutilizables.</p></section>';
        echo '<section class="wvm-card"><h2>Productos configurados</h2><strong>' . esc_html( (string) $query->found_posts ) . '</strong><p>Con una matriz personalizada.</p></section>';
        echo '<section class="wvm-card"><h2>Motor de ventas</h2><strong>WooCommerce</strong><p>Carrito, pedidos, pagos e impuestos.</p></section>';
        echo '</div>';
        echo '<section class="wvm-card wvm-guide"><h2>Publicar un colchón o canapé en 3 pasos</h2>';
        echo '<ol><li><a href="' . esc_url( self::url( 'wvm-templates' ) ) . '">Crea una plantilla</a> con medidas, colores y demás opciones.</li>';
        echo '<li><a href="' . esc_url( self::url( 'wvm-products' ) ) . '">Crea un producto</a> y asigna la plantilla.</li>';
        echo '<li>Rellena la matriz de precios y publica. El configurador aparece en su ficha de WooCommerce.</li></ol>';
        echo '<p class="description">En esta beta cada medida tiene su precio final; color, altura y apertura son opciones sin suplemento adicional. No hay stock por medida todavía.</p>';
        echo '</section></div>';
    }

    public static function templates_page(): void {
        self::start( 'Variable Manager · Plantillas', 'wvm-templates' );
        echo '<p class="wvm-preset-links"><strong>Empezar con una plantilla: </strong>';
        echo '<a class="button button-secondary" href="' . esc_url( self::url( 'wvm-templates', array( 'wvm_preset' => 'colchon' ) ) ) . '">Colchón</a> ';
        echo '<a class="button button-secondary" href="' . esc_url( self::url( 'wvm-templates', array( 'wvm_preset' => 'canape' ) ) ) . '">Canapé</a> ';
        echo '<a class="button button-secondary" href="' . esc_url( self::url( 'wvm-templates' ) ) . '">Desde cero</a></p>';
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $selected = $id ? get_post( $id ) : null;
        if ( $selected && 'wvm_template' !== $selected->post_type ) {
            wp_die( 'La plantilla solicitada no existe.' );
        }
        $preset = isset( $_GET['wvm_preset'] ) && is_string( $_GET['wvm_preset'] ) ? sanitize_key( wp_unslash( $_GET['wvm_preset'] ) ) : '';
        $presets = array(
            'colchon' => array( 'title' => 'Colchón estándar', 'size' => '90x190, 105x190, 135x190, 150x190, 150x200, 160x200, 180x200', 'color' => '', 'thickness' => '20 cm, 25 cm, 30 cm', 'opening' => '' ),
            'canape'  => array( 'title' => 'Canapé abatible', 'size' => '90x190, 105x190, 135x190, 150x190, 150x200, 160x200', 'color' => 'Beige, Gris, Blanco', 'thickness' => '30 cm, 35 cm', 'opening' => 'Frontal, Lateral' ),
        );
        $initial = ! $selected && isset( $presets[ $preset ] ) ? $presets[ $preset ] : array();
        $options = $selected ? WVM_Templates::options( $id ) : array();
        $templates = WVM_Templates::all();

        echo '<div class="wvm-columns"><section class="wvm-card"><div class="wvm-section-head"><h2>Plantillas guardadas</h2>';
        echo '<a class="button button-secondary" href="' . esc_url( self::url( 'wvm-templates' ) ) . '">Nueva plantilla</a></div>';
        if ( ! $templates ) {
            echo '<p>Todavía no hay plantillas. Crea la primera usando el formulario.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Nombre</th><th>Medidas</th><th>Acción</th></tr></thead><tbody>';
            foreach ( $templates as $template ) {
                $sizes = WVM_Templates::options( (int) $template->ID )['size'] ?? array();
                echo '<tr><td><strong>' . esc_html( $template->post_title ) . '</strong></td><td>' . esc_html( (string) count( $sizes ) ) . '</td>';
                echo '<td><a href="' . esc_url( self::url( 'wvm-templates', array( 'id' => $template->ID ) ) ) . '">Editar</a></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section><section class="wvm-card"><h2>' . ( $selected ? 'Editar plantilla' : 'Nueva plantilla' ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="wvm_save_template">';
        echo '<input type="hidden" name="wvm_template_id" value="' . esc_attr( (string) $id ) . '">';
        wp_nonce_field( 'wvm_save_template', 'wvm_nonce' );
        echo '<p><label for="wvm_template_name"><strong>Nombre de plantilla</strong></label>';
        echo '<input type="text" class="regular-text" id="wvm_template_name" name="wvm_template_name" required maxlength="160" value="' . esc_attr( $selected ? $selected->post_title : ( $initial['title'] ?? '' ) ) . '" placeholder="Canapé abatible tapizado"></p>';
        foreach ( WVM_Templates::ATTRIBUTES as $key => $label ) {
            $values = isset( $options[ $key ] ) ? implode( ', ', $options[ $key ] ) : ( $initial[ $key ] ?? '' );
            echo '<p><label for="wvm_attr_' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label>';
            echo '<input class="large-text" type="text" id="wvm_attr_' . esc_attr( $key ) . '" name="wvm_attributes[' . esc_attr( $key ) . ']" value="' . esc_attr( $values ) . '" placeholder="' . esc_attr( '90x190, 135x190, 150x190' ) . '"' . ( 'size' === $key ? ' required' : '' ) . '></p>';
        }
        echo '<p class="description">Separa los valores con comas. Las medidas de una plantilla ya asignada a productos quedan protegidas; crea otra plantilla si necesitas cambiarlas.</p>';
        submit_button( $selected ? 'Guardar cambios' : 'Crear plantilla', 'primary', 'submit', false );
        echo '</form></section></div></div>';
    }

    public static function products_page(): void {
        self::start( 'Variable Manager · Productos y tarifas', 'wvm-products' );
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $product = $id ? wc_get_product( $id ) : null;
        if ( $id && ( ! $product instanceof WC_Product_Simple || ! current_user_can( 'edit_post', $id ) ) ) {
            wp_die( 'Este producto no es simple o no tienes permisos para editarlo.' );
        }
        $template_id = $product ? absint( $product->get_meta( WVM_Products::TEMPLATE_META, true ) ) : 0;

        echo '<div class="wvm-columns"><section class="wvm-card"><div class="wvm-section-head"><h2>Productos existentes</h2>';
        echo '<a class="button button-secondary" href="' . esc_url( self::url( 'wvm-products' ) ) . '">Nuevo producto</a></div>';
        $search = isset( $_GET['wvm_search'] ) && is_string( $_GET['wvm_search'] ) ? sanitize_text_field( wp_unslash( $_GET['wvm_search'] ) ) : '';
        $page = isset( $_GET['wvm_page'] ) ? max( 1, absint( $_GET['wvm_page'] ) ) : 1;
        echo '<form class="wvm-search" method="get"><input type="hidden" name="page" value="wvm-products">';
        echo '<label class="screen-reader-text" for="wvm_search">Buscar producto</label><input id="wvm_search" type="search" name="wvm_search" value="' . esc_attr( $search ) . '" placeholder="Buscar por nombre o SKU exacto">';
        echo '<button class="button" type="submit">Buscar</button></form>';
        $result = WVM_Catalog::search( $search, $page );
        if ( ! $result->products ) {
            echo '<p>Sin productos en esta página.</p>';
        } else {
            echo '<table class="widefat striped wvm-catalog-table"><thead><tr><th>Producto / SKU</th><th>Estado</th><th>Tarifas</th><th>Acciones</th></tr></thead><tbody>';
            foreach ( $result->products as $entry ) {
                if ( ! current_user_can( 'edit_post', $entry->get_id() ) ) {
                    continue;
                }
                $managed = WVM_Products::is_managed( $entry );
                echo '<tr><td><a href="' . esc_url( self::url( 'wvm-products', array( 'id' => $entry->get_id() ) ) ) . '"><strong>' . esc_html( $entry->get_name() ) . '</strong></a>';
                echo '<span class="wvm-catalog-sku">' . esc_html( $entry->get_sku() ?: 'Sin SKU' ) . '</span></td>';
                echo '<td>' . esc_html( 'publish' === $entry->get_status() ? 'Publicado' : 'Borrador' ) . '</td>';
                echo '<td>' . esc_html( $managed ? 'Configuradas' : 'Sin plantilla' ) . '</td>';
                echo '<td><a href="' . esc_url( self::url( 'wvm-products', array( 'id' => $entry->get_id() ) ) ) . '">Editar</a>';
                if ( $managed && WVM_Products::configuration( $entry ) ) {
                    echo '<form class="wvm-duplicate-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                    echo '<input type="hidden" name="action" value="wvm_duplicate_product">';
                    echo '<input type="hidden" name="wvm_source_id" value="' . esc_attr( (string) $entry->get_id() ) . '">';
                    wp_nonce_field( 'wvm_duplicate_' . $entry->get_id(), 'wvm_nonce' );
                    echo '<button type="submit" class="button-link">Duplicar borrador</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        if ( $result->max_num_pages > 1 ) {
            echo '<p class="wvm-pagination">';
            if ( $page > 1 ) {
                echo '<a class="button" href="' . esc_url( self::url( 'wvm-products', array( 'wvm_search' => $search, 'wvm_page' => $page - 1 ) ) ) . '">Anterior</a> ';
            }
            echo 'Página ' . esc_html( (string) $page ) . ' de ' . esc_html( (string) $result->max_num_pages );
            if ( $page < $result->max_num_pages ) {
                echo ' <a class="button" href="' . esc_url( self::url( 'wvm-products', array( 'wvm_search' => $search, 'wvm_page' => $page + 1 ) ) ) . '">Siguiente</a>';
            }
            echo '</p>';
        }
        echo '</section><section class="wvm-card"><h2>' . ( $product ? 'Editar producto y tarifas' : 'Crear producto con tarifas' ) . '</h2>';
        if ( ! WVM_Templates::all() ) {
            echo '<p>Primero debes <a href="' . esc_url( self::url( 'wvm-templates' ) ) . '">crear una plantilla de medidas</a>.</p></section></div></div>';
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="wvm_save_product">';
        echo '<input type="hidden" name="wvm_product_id" value="' . esc_attr( (string) $id ) . '">';
        wp_nonce_field( 'wvm_save_product', 'wvm_nonce' );
        echo '<p><label for="wvm_product_name"><strong>Nombre del producto</strong></label>';
        echo '<input class="large-text" type="text" name="wvm_product_name" id="wvm_product_name" required maxlength="200" value="' . esc_attr( $product ? $product->get_name() : '' ) . '" placeholder="Canapé Premium"></p>';
        echo '<p><label for="wvm_product_short"><strong>Descripción breve</strong></label>';
        echo '<textarea class="large-text" id="wvm_product_short" name="wvm_product_short" rows="3" placeholder="Materiales, comodidad y beneficios principales">' . esc_textarea( $product ? $product->get_short_description() : '' ) . '</textarea></p>';
        echo '<p><label for="wvm_product_desc"><strong>Descripción completa</strong></label>';
        echo '<textarea class="large-text" id="wvm_product_desc" name="wvm_product_desc" rows="5" placeholder="Especificaciones, composición, materiales y cuidados">' . esc_textarea( $product ? $product->get_description() : '' ) . '</textarea></p>';
        $image_id = $product ? $product->get_image_id() : 0;
        $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
        echo '<div class="wvm-image-field"><strong>Imagen principal</strong><div class="wvm-image-preview">';
        echo '<img id="wvm_image_preview" src="' . esc_url( $image_url ?: '' ) . '" alt="Vista previa de imagen" ' . ( $image_url ? '' : 'hidden' ) . '></div>';
        echo '<input type="hidden" id="wvm_image_id" name="wvm_image_id" value="' . esc_attr( (string) $image_id ) . '">';
        echo '<button type="button" class="button" id="wvm_choose_image">Elegir imagen</button> ';
        echo '<button type="button" class="button" id="wvm_remove_image">Quitar</button></div>';
        echo '<div class="wvm-inline-fields"><p><label for="wvm_product_sku"><strong>SKU (opcional)</strong></label>';
        echo '<input type="text" id="wvm_product_sku" name="wvm_product_sku" value="' . esc_attr( $product ? $product->get_sku() : '' ) . '"></p>';
        echo '<p><label for="wvm_product_status"><strong>Publicación</strong></label><select name="wvm_product_status" id="wvm_product_status">';
        foreach ( array( 'draft' => 'Borrador', 'publish' => 'Publicado' ) as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $product ? $product->get_status() : 'draft', $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></p></div>';
        $existing_categories = $product ? array_map( 'intval', $product->get_category_ids() ) : array();
        $categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
        echo '<fieldset class="wvm-categories"><legend><strong>Categorías</strong></legend>';
        if ( ! is_wp_error( $categories ) ) {
            foreach ( $categories as $category ) {
                echo '<label><input type="checkbox" name="wvm_product_categories[]" value="' . esc_attr( (string) $category->term_id ) . '" ' .
                    checked( in_array( (int) $category->term_id, $existing_categories, true ), true, false ) . '> ' . esc_html( $category->name ) . '</label>';
            }
        }
        echo '</fieldset><div class="wvm-stock-options"><h3>Stock general</h3>';
        echo '<p><label><input type="checkbox" name="wvm_manage_stock" value="1" ' . checked( $product ? $product->get_manage_stock() : false, true, false ) . '> Controlar unidades disponibles de este producto</label></p>';
        echo '<div class="wvm-inline-fields"><p><label for="wvm_stock_qty">Unidades disponibles</label>';
        echo '<input type="number" min="0" step="1" id="wvm_stock_qty" name="wvm_stock_qty" value="' .
            esc_attr( (string) ( $product && null !== $product->get_stock_quantity() ? $product->get_stock_quantity() : 0 ) ) . '"></p>';
        echo '<p><label for="wvm_stock_status">Disponibilidad (sin gestión de unidades)</label><select id="wvm_stock_status" name="wvm_stock_status">';
        foreach ( array( 'instock' => 'Disponible', 'outofstock' => 'Agotado', 'onbackorder' => 'Bajo pedido' ) as $state => $label ) {
            echo '<option value="' . esc_attr( $state ) . '" ' .
                selected( $product ? $product->get_stock_status() : 'instock', $state, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></p></div><p class="description">El stock es del producto completo, no por medida o acabado.</p></div>';
        echo '<p><label for="wvm_template_id"><strong>Plantilla de opciones</strong></label>';
        echo '<select id="wvm_template_id" name="wvm_template_id" required>';
        echo '<option value="">Seleccionar plantilla</option>';
        foreach ( WVM_Templates::all() as $template ) {
            echo '<option value="' . esc_attr( (string) $template->ID ) . '" ' . selected( $template_id, (int) $template->ID, false ) . '>' . esc_html( $template->post_title ) . '</option>';
        }
        echo '</select></p>';
        echo '<h3>Matriz de precios por medida</h3><p class="description">Precio final de cada medida en ' . esc_html( get_woocommerce_currency() ) . '. No necesitas crear combinaciones manuales.</p>';
        echo '<table class="widefat striped wvm-matrix"><thead><tr><th>Medida</th><th>Precio</th></tr></thead><tbody id="wvm-price-rows"></tbody></table>';
        echo '<details class="wvm-bulk"><summary>Pegar precios desde Excel o CSV</summary>';
        echo '<p class="description">Una medida y precio por línea, separados por punto y coma. Ejemplo: <code>90x190;299,99</code></p>';
        echo '<textarea id="wvm_bulk_prices" class="large-text code" rows="5" placeholder="90x190;299,99&#10;135x190;384,50"></textarea>';
        echo '<p><button type="button" class="button" id="wvm_apply_bulk">Aplicar a la matriz</button> <span id="wvm_bulk_status" role="status" aria-live="polite"></span></p></details>';
        echo '<h3>Suplementos por opción</h3>';
        echo '<p class="description">Importes adicionales para cada color, altura o apertura. Introduce cero si no tiene suplemento. El precio final será medida + suplementos seleccionados.</p>';
        echo '<table class="widefat striped wvm-extras"><thead><tr><th>Opción</th><th>Suplemento (' . esc_html( get_woocommerce_currency_symbol() ) . ')</th></tr></thead><tbody id="wvm-surcharge-rows"></tbody></table>';
        echo '<h3>Compatibilidades</h3>';
        echo '<p class="description">Bloquea pares imposibles. Ejemplo: medida 90x190 no disponible con apertura lateral.</p>';
        echo '<div id="wvm-exclusion-rows"></div><p><button class="button" id="wvm-add-exclusion" type="button">Añadir incompatibilidad</button></p>';
        echo '<input type="hidden" name="wvm_rules_ready" id="wvm_rules_ready" value="0">';
        echo '<p class="description">Stock y SKU se controlan por producto. Los campos no cambian nada en WooCommerce hasta pulsar Guardar.</p>';
        submit_button( $product ? 'Guardar producto y tarifas' : 'Crear producto', 'primary', 'submit', false );
        echo '</form></section></div></div>';
    }

    private static function posted_array( string $key ): array {
        $raw = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
        return array_map( static function ( $value ): string {
            return is_string( $value ) ? sanitize_text_field( $value ) : '';
        }, $raw );
    }

    private static function redirect( string $page, string $status, int $id = 0 ): void {
        $args = array( 'wvm_status' => $status );
        if ( $id > 0 ) {
            $args['id'] = $id;
        }
        wp_safe_redirect( self::url( $page, $args ) );
        exit;
    }

    private static function authorize_save( string $nonce_action ): void {
        self::ensure_permission();
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            wp_die( 'Método no permitido.' );
        }
        check_admin_referer( $nonce_action, 'wvm_nonce' );
    }

    public static function save_template(): void {
        self::authorize_save( 'wvm_save_template' );
        $id = isset( $_POST['wvm_template_id'] ) ? absint( $_POST['wvm_template_id'] ) : 0;
        if ( $id && ( 'wvm_template' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) ) {
            wp_die( 'No puedes editar esta plantilla.' );
        }
        $name = isset( $_POST['wvm_template_name'] ) && is_string( $_POST['wvm_template_name'] )
            ? sanitize_text_field( wp_unslash( $_POST['wvm_template_name'] ) ) : '';
        $raw = isset( $_POST['wvm_attributes'] ) && is_array( $_POST['wvm_attributes'] )
            ? wp_unslash( $_POST['wvm_attributes'] ) : array();
        $options = WVM_Templates::normalize( $raw );
        if ( '' === trim( $name ) || strlen( $name ) > 200 || empty( $options['size'] ) ) {
            self::redirect( 'wvm-templates', 'invalid_template', $id );
        }
        if ( $id && WVM_Templates::is_in_use( $id ) ) {
            $old_options = WVM_Templates::options( $id );
            foreach ( WVM_Templates::ATTRIBUTES as $attr => $label ) {
                $before = $old_options[ $attr ] ?? array();
                $after  = $options[ $attr ] ?? array();
                sort( $before );
                sort( $after );
                if ( $before !== $after ) {
                    self::redirect( 'wvm-templates', 'template_sizes_in_use', $id );
                }
            }
        }
        $data = array( 'post_title' => $name, 'post_type' => 'wvm_template', 'post_status' => 'publish' );
        if ( $id ) {
            $data['ID'] = $id;
            $saved = wp_update_post( $data, true );
        } else {
            $saved = wp_insert_post( $data, true );
        }
        if ( is_wp_error( $saved ) || ! $saved ) {
            self::redirect( 'wvm-templates', 'save_error', $id );
        }
        update_post_meta( (int) $saved, WVM_Templates::META_KEY, $options );
        self::redirect( 'wvm-templates', 'template_saved', (int) $saved );
    }

    public static function duplicate_product(): void {
        self::ensure_permission();
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            wp_die( 'Método no permitido.' );
        }
        $source_id = isset( $_POST['wvm_source_id'] ) ? absint( wp_unslash( $_POST['wvm_source_id'] ) ) : 0;
        if ( ! $source_id ) {
            wp_die( 'Producto de origen no válido.' );
        }
        check_admin_referer( 'wvm_duplicate_' . $source_id, 'wvm_nonce' );
        $new_id = WVM_Catalog::duplicate( $source_id );
        if ( is_wp_error( $new_id ) ) {
            self::redirect( 'wvm-products', 'duplicate_failed', $source_id );
        }
        self::redirect( 'wvm-products', 'product_duplicated', $new_id );
    }

    public static function save_product(): void {
        self::authorize_save( 'wvm_save_product' );
        $id = isset( $_POST['wvm_product_id'] ) ? absint( $_POST['wvm_product_id'] ) : 0;
        $existing = $id ? wc_get_product( $id ) : null;
        if ( $id && ( ! $existing instanceof WC_Product_Simple || ! current_user_can( 'edit_post', $id ) ) ) {
            wp_die( 'Solo se pueden editar productos simples con permiso.' );
        }
        $name = isset( $_POST['wvm_product_name'] ) && is_string( $_POST['wvm_product_name'] )
            ? sanitize_text_field( wp_unslash( $_POST['wvm_product_name'] ) ) : '';
        $sku = isset( $_POST['wvm_product_sku'] ) && is_string( $_POST['wvm_product_sku'] )
            ? wc_clean( wp_unslash( $_POST['wvm_product_sku'] ) ) : '';
        $status = isset( $_POST['wvm_product_status'] ) && is_string( $_POST['wvm_product_status'] )
            ? sanitize_key( wp_unslash( $_POST['wvm_product_status'] ) ) : 'draft';
        $template_id = isset( $_POST['wvm_template_id'] ) ? absint( $_POST['wvm_template_id'] ) : 0;
        $short = isset( $_POST['wvm_product_short'] ) && is_string( $_POST['wvm_product_short'] )
            ? wp_kses_post( wp_unslash( $_POST['wvm_product_short'] ) ) : '';
        $description = isset( $_POST['wvm_product_desc'] ) && is_string( $_POST['wvm_product_desc'] )
            ? wp_kses_post( wp_unslash( $_POST['wvm_product_desc'] ) ) : '';
        $category_ids = array_values( array_unique( array_filter( array_map( 'absint', self::posted_array( 'wvm_product_categories' ) ) ) ) );
        foreach ( $category_ids as $category_id ) {
            if ( ! term_exists( $category_id, 'product_cat' ) ) {
                self::redirect( 'wvm-products', 'invalid_product', $id );
            }
        }
        $manage_stock = isset( $_POST['wvm_manage_stock'] ) && '1' === (string) $_POST['wvm_manage_stock'];
        $stock_raw = isset( $_POST['wvm_stock_qty'] ) && is_string( $_POST['wvm_stock_qty'] ) ? trim( wp_unslash( $_POST['wvm_stock_qty'] ) ) : '0';
        if ( ! preg_match( '/^\\d{1,8}$/D', $stock_raw ) ) {
            self::redirect( 'wvm-products', 'invalid_product', $id );
        }
        $stock_quantity = (int) $stock_raw;
        $stock_status = isset( $_POST['wvm_stock_status'] ) && is_string( $_POST['wvm_stock_status'] )
            ? sanitize_key( wp_unslash( $_POST['wvm_stock_status'] ) ) : 'instock';
        if ( ! in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
            self::redirect( 'wvm-products', 'invalid_product', $id );
        }
        $image_id = isset( $_POST['wvm_image_id'] ) ? absint( $_POST['wvm_image_id'] ) : 0;
        if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
            self::redirect( 'wvm-products', 'invalid_product', $id );
        }
        if ( ! isset( $_POST['wvm_rules_ready'] ) || '1' !== $_POST['wvm_rules_ready'] ) {
            self::redirect( 'wvm-products', 'invalid_rules', $id );
        }
        $prepared = WVM_Products::prepare(
            $template_id,
            self::posted_array( 'wvm_matrix_sizes' ), self::posted_array( 'wvm_matrix_prices' ),
            array(
                'attr'   => self::posted_array( 'wvm_extra_attr' ),
                'value'  => self::posted_array( 'wvm_extra_value' ),
                'amount' => self::posted_array( 'wvm_extra_amount' ),
            ),
            array(
                'a_attr'  => self::posted_array( 'wvm_rule_a_attr' ),
                'a_value' => self::posted_array( 'wvm_rule_a_value' ),
                'b_attr'  => self::posted_array( 'wvm_rule_b_attr' ),
                'b_value' => self::posted_array( 'wvm_rule_b_value' ),
            )
        );
        if ( is_wp_error( $prepared ) ) {
            self::redirect( 'wvm-products', in_array( $prepared->get_error_code(), array( 'wvm_rules' ), true ) ? 'invalid_rules' : 'invalid_product', $id );
        }
        if ( '' === trim( $name ) || strlen( $name ) > 200 ||
             ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
            self::redirect( 'wvm-products', 'invalid_product', $id );
        }
        try {
            $product = $existing ?: new WC_Product_Simple();
            $product->set_name( $name );
            $product->set_short_description( $short );
            $product->set_description( $description );
            $product->set_category_ids( $category_ids );
            $product->set_manage_stock( $manage_stock );
            if ( $manage_stock ) {
                $product->set_stock_quantity( $stock_quantity );
                $product->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
            } else {
                $product->set_stock_quantity( null );
                $product->set_stock_status( $stock_status );
            }
            $product->set_image_id( $image_id );
            $product->set_sku( $sku );
            $product->set_status( $status );
            WVM_Products::apply( $product, $prepared );
            $saved_id = $product->save();
            if ( ! $saved_id ) {
                self::redirect( 'wvm-products', 'save_error', $id );
            }
        } catch ( Throwable $e ) {
            self::redirect( 'wvm-products', 'save_error', $id );
        }
        self::redirect( 'wvm-products', 'product_saved', (int) $saved_id );
    }
}
