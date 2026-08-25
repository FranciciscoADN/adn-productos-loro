<?php
/**
 * Plugin Name: ADN - Productos WooCommerce
 * Plugin URI:  https://distribuidoraelloro.com
 * Description: Gestión de stock por almacén sincronizado desde el sistema ADN
 * Version:     1.0.0
 * Author:      Ing. Francisco Ramirez
 * Text Domain: adn-productos
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ADN_PRODUCTOS_VERSION', '1.0.2' );
define( 'ADN_PRODUCTOS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADN_PRODUCTOS_URL', plugin_dir_url( __FILE__ ) );

class ADN_Productos_Plugin {

    /** @var array Args extra capturados desde BeRocket AJAX Products Filter */
    private $berocket_extra_args = array();

    public function __construct() {
        add_shortcode( 'adn_productos',             array( $this, 'render_shortcode' ) );
        add_shortcode( 'product_brand_thumbnails', array( $this, 'render_brands_slider' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );

        // Compatibilidad con BeRocket AJAX Products Filter:
        // captura los args que BeRocket inyecta en el query principal
        add_filter( 'berocket_aapf_widget_products_query_args', array( $this, 'capture_berocket_args' ), 10, 1 );
        add_filter( 'berocket_filter_product_query_args',        array( $this, 'capture_berocket_args' ), 10, 1 );

        // Sincronización ADN ← → WP
        add_action( 'rest_api_init',                       array( $this, 'register_sync_endpoints' ) );
        add_action( 'woocommerce_order_status_changed',    array( $this, 'enqueue_order_for_adn' ), 10, 4 );

        // ADN es la fuente de verdad para stock — WooCommerce NO debe descontar
        add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );

        // Imagen por defecto para productos sin foto (página de detalle y en todo WooCommerce)
        add_filter( 'woocommerce_placeholder_img_src', array( $this, 'custom_placeholder_img' ) );

        // Columnas en páginas de tienda/categoría
        add_filter( 'loop_shop_columns', array( $this, 'set_shop_columns' ), 999 );

        // Filtrado AJAX sin recarga
        add_action( 'wp_ajax_adn_filter_products',        array( $this, 'ajax_filter_products' ) );
        add_action( 'wp_ajax_nopriv_adn_filter_products', array( $this, 'ajax_filter_products' ) );

        // Filtro de marcas: shortcode + widget
        add_shortcode( 'adn_filtro_marcas', array( $this, 'render_filtro_marcas' ) );
        add_action( 'widgets_init', function () {
            register_widget( 'ADN_Marcas_Filter_Widget' );
        } );

        // Checkout: copiar billing → shipping meta cuando envío está vacío (compatible con Blocks)
        add_action( 'template_redirect', array( $this, 'sync_shipping_from_billing' ) );

        // Código postal opcional (clientes ADN tienen 0000001 por defecto, no válido)
        add_filter( 'woocommerce_default_address_fields', array( $this, 'make_postcode_optional' ) );
    }

    /**
     * Copia los campos de facturación al meta de envío cuando están vacíos.
     * Compatible con WooCommerce Blocks (Block Checkout) ya que actúa sobre
     * el user meta que Blocks lee vía Store API.
     * Solo se ejecuta en la página de checkout con usuario logueado.
     */
    public function sync_shipping_from_billing() {
        if ( ! is_checkout() || ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $fields  = array(
            'first_name', 'last_name', 'company',
            'address_1', 'address_2',
            'city', 'state', 'postcode', 'country', 'phone',
        );

        foreach ( $fields as $field ) {
            $shipping_val = get_user_meta( $user_id, 'shipping_' . $field, true );
            if ( ! empty( $shipping_val ) ) {
                continue; // ya tiene valor, no sobreescribir
            }
            $billing_val = get_user_meta( $user_id, 'billing_' . $field, true );
            if ( ! empty( $billing_val ) ) {
                update_user_meta( $user_id, 'shipping_' . $field, $billing_val );
            }
        }

        // Fallback desde perfil WordPress si billing también está vacío
        $user = wp_get_current_user();
        if ( empty( get_user_meta( $user_id, 'shipping_first_name', true ) ) && ! empty( $user->first_name ) ) {
            update_user_meta( $user_id, 'shipping_first_name', $user->first_name );
            update_user_meta( $user_id, 'billing_first_name',  $user->first_name );
        }
        if ( empty( get_user_meta( $user_id, 'shipping_last_name', true ) ) && ! empty( $user->last_name ) ) {
            update_user_meta( $user_id, 'shipping_last_name', $user->last_name );
            update_user_meta( $user_id, 'billing_last_name',  $user->last_name );
        }
    }

    public function make_postcode_optional( $fields ) {
        $fields['postcode']['required'] = false;
        return $fields;
    }

    public function set_shop_columns( $columns ) {
        return 3;
    }

    public function custom_placeholder_img( $src ) {
        return content_url( 'uploads/2026/08/logo_varela_insta.jpg' );
    }

    /**
     * Detecta la taxonomía de marcas activa en WooCommerce.
     * Prueba nombres conocidos y luego busca automáticamente cualquier taxonomía
     * de productos cuyo nombre o etiqueta contenga "brand" o "marca".
     */
    private function detect_brand_taxonomy(): string {
        $known = array(
            'product_brand',       // WooCommerce oficial / Perfect Brands
            'pwb-brand',           // Perfect Brands for WooCommerce
            'berocket_brand',      // BeRocket Brands
            'wc_product_brands',   // WooCommerce Brands (antiguo)
            'brand',               // genérico
            'brands',              // genérico plural
            'yith_product_brand',  // YITH Brands
            'pa_brand',            // Atributo WooCommerce
        );
        foreach ( $known as $tax ) {
            if ( taxonomy_exists( $tax ) ) { return $tax; }
        }
        // Fallback: cualquier taxonomía de productos con "brand" o "marca" en el nombre
        foreach ( get_object_taxonomies( 'product', 'objects' ) as $tax_obj ) {
            $n = strtolower( $tax_obj->name );
            $l = strtolower( $tax_obj->label );
            if ( strpos( $n, 'brand' ) !== false || strpos( $l, 'brand' ) !== false
                 || strpos( $n, 'marca' ) !== false || strpos( $l, 'marca' ) !== false ) {
                return $tax_obj->name;
            }
        }
        return '';
    }

    /**
     * Shortcode [adn_filtro_marcas] — lista de marcas con checkboxes para filtrar productos.
     */
    public function render_filtro_marcas( $atts ) {
        $atts = shortcode_atts( array( 'titulo' => '' ), $atts, 'adn_filtro_marcas' );

        $brand_tax = $this->detect_brand_taxonomy();
        if ( ! $brand_tax ) { return '<!-- adn_filtro_marcas: no se encontró taxonomía de marcas -->'; }

        $terms = get_terms( array(
            'taxonomy'   => $brand_tax,
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
        if ( empty( $terms ) || is_wp_error( $terms ) ) { return '<!-- adn_filtro_marcas: sin términos en ' . esc_attr( $brand_tax ) . ' -->'; }

        ob_start();
        if ( ! empty( $atts['titulo'] ) ) {
            echo '<h4 class="adn-marcas-titulo">' . esc_html( $atts['titulo'] ) . '</h4>';
        }
        ?>
        <ul class="adn-marcas-filter-list" data-brand-tax="<?php echo esc_attr( $brand_tax ); ?>">
            <?php foreach ( $terms as $term ) : ?>
            <li class="adn-marca-item">
                <label>
                    <input type="checkbox"
                           class="adn-marca-check"
                           data-taxonomy="<?php echo esc_attr( $brand_tax ); ?>"
                           data-slug="<?php echo esc_attr( $term->slug ); ?>"
                           value="<?php echo esc_attr( $term->slug ); ?>">
                    <span class="adn-marca-nombre"><?php echo esc_html( $term->name ); ?></span>
                    <span class="adn-marca-count">(<?php echo (int) $term->count; ?>)</span>
                </label>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php
        return ob_get_clean();
    }

    /**
     * Almacena los args que BeRocket calcula para su query propio.
     * Los fusionamos luego en nuestro WP_Query.
     */
    public function capture_berocket_args( $args ) {
        if ( ! empty( $args ) ) {
            $this->berocket_extra_args = $args;
        }
        return $args;
    }

    /**
     * Encola los estilos del plugin.
     */
    public function enqueue_assets() {
        global $post;
        $has_productos     = is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'adn_productos' );
        $has_brands        = is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'product_brand_thumbnails' );
        $has_filtro_marcas = is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'adn_filtro_marcas' );
        // También cargar si hay un widget activo en la página
        $has_filtro_marcas = $has_filtro_marcas || is_active_widget( false, false, 'adn_marcas_filter', true );
        // Cargar en páginas de tienda/categoría donde BeRocket muestra sus filtros
        $has_berocket = is_shop() || is_product_category() || is_product_tag();

        if ( ! $has_productos && ! $has_brands && ! $has_filtro_marcas && ! $has_berocket ) {
            return;
        }

        wp_enqueue_style(
            'adn-productos-style',
            ADN_PRODUCTOS_URL . 'assets/css/style.css',
            array(),
            ADN_PRODUCTOS_VERSION
        );

        if ( $has_brands ) {
            wp_enqueue_script(
                'adn-brands-slider',
                ADN_PRODUCTOS_URL . 'assets/js/adn-slider.js',
                array(),
                ADN_PRODUCTOS_VERSION,
                true
            );
        }

        if ( $has_productos ) {
            wp_enqueue_script(
                'adn-productos-js',
                ADN_PRODUCTOS_URL . 'assets/js/adn-productos.js',
                array( 'jquery' ),
                ADN_PRODUCTOS_VERSION,
                true
            );
            wp_localize_script( 'adn-productos-js', 'adnAjax', array(
                'url'   => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'adn_filter_nonce' ),
            ) );
        }

        // Encolar scripts de WooCommerce para el botón añadir al carrito por AJAX
        if ( $has_productos && class_exists( 'WooCommerce' ) ) {
            wp_enqueue_script( 'wc-add-to-cart' );
        }

        // Toggle colapsable para secciones de filtros BeRocket
        wp_add_inline_style( 'adn-productos-style', '
            .bapf_head,
            .adn-marcas-titulo { position: relative; cursor: pointer; user-select: none; padding-right: 32px; }
            .bapf_head h3, .bapf_head h5, .bapf_head * { pointer-events: none; }
            .bapf_toggle_btn {
                position: absolute;
                right: 4px;
                top: 50%;
                transform: translateY(-50%);
                width: 22px;
                height: 22px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                color: #444;
                line-height: 1;
                pointer-events: none;
            }
            .bapf_sfilter.adn-collapsed .bapf_body { display: none !important; }
            .adn-marcas-titulo.adn-collapsed + .adn-marcas-filter-list { display: none !important; }
        ' );
        wp_add_inline_script( 'jquery', '
            jQuery(function($){
                var svgUp   = \'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#333" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="8 14 12 9 16 14"/></svg>\';
                var svgDown = \'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#333" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="8 10 12 15 16 10"/></svg>\';
                function initBapfToggle() {
                    $(".bapf_head").each(function(){
                        if ($(this).find(".bapf_toggle_btn").length) return;
                        $(this).append($("<span class=\"bapf_toggle_btn\"></span>").html(svgUp));
                    });
                    $(".adn-marcas-titulo").each(function(){
                        if ($(this).find(".bapf_toggle_btn").length) return;
                        $(this).append($("<span class=\"bapf_toggle_btn\"></span>").html(svgUp));
                    });
                }
                initBapfToggle();
                $(document).on("click", ".bapf_head", function(){
                    var $filter = $(this).closest(".bapf_sfilter");
                    var $btn    = $(this).find(".bapf_toggle_btn");
                    $filter.toggleClass("adn-collapsed");
                    $btn.html($filter.hasClass("adn-collapsed") ? svgDown : svgUp);
                });
                $(document).on("click", ".adn-marcas-titulo", function(){
                    var $btn = $(this).find(".bapf_toggle_btn");
                    $(this).toggleClass("adn-collapsed");
                    $btn.html($(this).hasClass("adn-collapsed") ? svgDown : svgUp);
                });
                $(document).on("berocket_ajax_products_loaded berocket_products_loaded", function(){
                    initBapfToggle();
                });
            });
        ' );
    }

    /**
     * Renderiza el shortcode [adn_productos].
     *
     * Atributos disponibles:
     *  - columns  : número de columnas (default 3)
     *  - orderby  : date | title | price | rand | menu_order (default date)
     *  - order    : DESC | ASC (default DESC)
     *  - limit    : cantidad de productos a mostrar (default 12)
     *  - category : slug(s) de categoría separados por coma (default vacío = todas)
     *  - ids      : IDs de productos separados por coma (default vacío = todos)
     *
     * Ejemplo:
     *  [adn_productos columns="3" orderby="date" limit="12"]
     *  [adn_productos columns="4" category="ropa" orderby="price" order="ASC"]
     */
    public function render_shortcode( $atts ) {

        if ( ! class_exists( 'WooCommerce' ) ) {
            return '<p class="adn-error">WooCommerce no está activo. Este shortcode requiere WooCommerce.</p>';
        }

        $atts = shortcode_atts(
            array(
                'columns'  => 3,
                'orderby'  => 'date',
                'order'    => 'DESC',
                'limit'    => 12,
                'category' => '',
                'ids'      => '',
            ),
            $atts,
            'adn_productos'
        );

        $columns = max( 1, intval( $atts['columns'] ) );
        $limit   = max( 1, intval( $atts['limit'] ) );

        // Construir args base + aplicar filtros de URL + hook de plugins de filtro
        $args  = $this->build_query_args( $atts );
        $args  = $this->apply_url_filters( $args );
        $args  = apply_filters( 'woocommerce_shortcode_products_query', $args, $atts, 'adn_productos' );

        // Proteger posts_per_page y no_found_rows: filtros externos (ej. BeRocket)
        // pueden anular posts_per_page a -1, lo que impide calcular max_num_pages.
        $args['no_found_rows'] = false;
        if ( ! isset( $args['posts_per_page'] ) || (int) $args['posts_per_page'] <= 0 ) {
            $args['posts_per_page'] = $limit;
        }

        // Fusionar tax_query / meta_query capturados desde BeRocket
        if ( ! empty( $this->berocket_extra_args['tax_query'] ) ) {
            $args['tax_query'] = array_merge(
                isset( $args['tax_query'] ) ? (array) $args['tax_query'] : array(),
                (array) $this->berocket_extra_args['tax_query']
            );
        }
        if ( ! empty( $this->berocket_extra_args['meta_query'] ) ) {
            $args['meta_query'] = array_merge(
                isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(),
                (array) $this->berocket_extra_args['meta_query']
            );
        }

        $query = new WP_Query( $args );

        if ( ! $query->have_posts() ) {
            wp_reset_postdata();
            return '<p class="adn-sin-productos">No se encontraron productos.</p>';
        }

        $current_search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $current_orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
        $current_brand   = isset( $_GET['filter_brand'] ) ? sanitize_key( wp_unslash( $_GET['filter_brand'] ) ) : '';

        // Detectar taxonomía de marcas activa
        $brand_tax   = $this->detect_brand_taxonomy();
        $brand_terms = $brand_tax
            ? get_terms( array( 'taxonomy' => $brand_tax, 'hide_empty' => true, 'orderby' => 'name', 'order' => 'ASC' ) )
            : array();

        ob_start();
        ?>
        <div class="adn-productos-wrapper woocommerce" data-columns="<?php echo esc_attr( $columns ); ?>" data-per-page="<?php echo esc_attr( $args['posts_per_page'] ); ?>" data-brand-tax="<?php echo esc_attr( $brand_tax ); ?>">

            <div class="adn-filtros-bar">
                <div class="adn-filtro-busqueda">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text"
                           id="adn-search-input"
                           class="adn-search-input"
                           placeholder="<?php esc_attr_e( 'Buscar producto...', 'adn-productos' ); ?>"
                           value="<?php echo esc_attr( $current_search ); ?>"
                           autocomplete="off" />
                </div>
                <div class="adn-filtro-orden">
                    <select id="adn-orderby-select" class="adn-orderby-select" aria-label="<?php esc_attr_e( 'Ordenar por', 'adn-productos' ); ?>">
                        <option value=""          <?php selected( $current_orderby, '' ); ?>><?php esc_html_e( 'Orden por defecto', 'adn-productos' ); ?></option>
                        <option value="date"      <?php selected( $current_orderby, 'date' ); ?>><?php esc_html_e( 'Más recientes', 'adn-productos' ); ?></option>
                        <option value="title"     <?php selected( $current_orderby, 'title' ); ?>><?php esc_html_e( 'Nombre A–Z', 'adn-productos' ); ?></option>
                        <option value="price"     <?php selected( $current_orderby, 'price' ); ?>><?php esc_html_e( 'Precio: menor a mayor', 'adn-productos' ); ?></option>
                        <option value="price-desc"<?php selected( $current_orderby, 'price-desc' ); ?>><?php esc_html_e( 'Precio: mayor a menor', 'adn-productos' ); ?></option>
                        <option value="popularity"<?php selected( $current_orderby, 'popularity' ); ?>><?php esc_html_e( 'Más populares', 'adn-productos' ); ?></option>
                    </select>
                </div>
                <?php if ( ! empty( $brand_terms ) && ! is_wp_error( $brand_terms ) ) : ?>
                <div class="adn-filtro-marca">
                    <select id="adn-brand-select" class="adn-brand-select" aria-label="<?php esc_attr_e( 'Filtrar por marca', 'adn-productos' ); ?>">
                        <option value=""><?php esc_html_e( 'Todas las marcas', 'adn-productos' ); ?></option>
                        <?php foreach ( $brand_terms as $bt ) : ?>
                        <option value="<?php echo esc_attr( $bt->slug ); ?>" <?php selected( $current_brand, $bt->slug ); ?>>
                            <?php echo esc_html( $bt->name ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <p class="adn-resultado-count"><?php
                $total = $query->found_posts;
                /* translators: %d: número de productos */
                printf( esc_html( _n( '%d producto encontrado', '%d productos encontrados', $total, 'adn-productos' ) ), $total );
            ?></p>

            <ul class="products adn-productos-grid columns-<?php echo esc_attr( $columns ); ?>" style="--adn-columns:<?php echo esc_attr( $columns ); ?>">
                <?php
                while ( $query->have_posts() ) :
                    $query->the_post();
                    $product = wc_get_product( get_the_ID() );
                    if ( ! $product ) { continue; }
                    echo $this->render_product_card( $product ); // phpcs:ignore
                endwhile; ?>
            </ul>
            <?php echo $this->render_pagination( $query ); ?>
        </div>
        <?php
        wp_reset_postdata();
        return ob_get_clean();
    }

    /**
     * Renderiza la tarjeta HTML de un producto individual.
     * Funciona dentro y fuera del loop de WP.
     */
    private function render_product_card( $product ) {
        $product_id  = $product->get_id();
        $permalink   = get_permalink( $product_id );
        $title       = $product->get_name();
        $price_html  = $product->get_price_html();
        $in_stock    = $product->is_in_stock();
        $cart_url    = $product->add_to_cart_url();
        $cart_text   = $product->add_to_cart_text();
        $product_sku = $product->get_sku();
        $is_variable = $product->is_type( 'variable' );
        $cat_list    = wc_get_product_category_list( $product_id, ', ' );
        $brand_tax   = '';
        foreach ( array( 'product_brand', 'pwb-brand', 'berocket_brand' ) as $_bt ) {
            if ( taxonomy_exists( $_bt ) ) { $brand_tax = $_bt; break; }
        }
        $brand_terms = $brand_tax ? wc_get_product_terms( $product_id, $brand_tax, array( 'fields' => 'names' ) ) : array();
        $brand_str   = implode( ', ', $brand_terms );
        $thumb_url   = get_the_post_thumbnail_url( $product_id, 'woocommerce_thumbnail' );
        if ( ! $thumb_url ) {
            $thumb_url = content_url( 'uploads/2026/08/logo_varela_insta.jpg' );
        }

        ob_start();
        ?>
        <li class="product type-product adn-producto-item">
            <a href="<?php echo esc_url( $permalink ); ?>" class="adn-producto-link" aria-label="<?php echo esc_attr( $title ); ?>">
                <div class="adn-producto-imagen">
                    <img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $title ); ?>" class="adn-img" />
                    <?php if ( ! $in_stock ) : ?>
                        <span class="adn-badge adn-badge-agotado">Agotado</span>
                    <?php elseif ( $product->is_on_sale() ) : ?>
                        <span class="adn-badge adn-badge-oferta">Oferta</span>
                    <?php endif; ?>
                </div>
                <div class="adn-producto-info">
                    <h3 class="adn-producto-titulo"><?php echo esc_html( $title ); ?></h3>
                    <?php if ( $cat_list ) : ?>
                        <div class="adn-producto-categorias"><?php echo wp_kses_post( $cat_list ); ?></div>
                    <?php endif; ?>
                    <?php if ( $brand_str ) : ?>
                        <div class="adn-producto-marcas"><?php echo esc_html( $brand_str ); ?></div>
                    <?php endif; ?>
                    <div class="adn-producto-precio"><?php echo wp_kses_post( $price_html ); ?></div>
                </div>
            </a>
            <div class="adn-producto-accion">
                <?php if ( $is_variable ) : ?>
                    <a href="<?php echo esc_url( $permalink ); ?>" class="adn-btn-carrito button"><?php echo esc_html( $cart_text ); ?></a>
                <?php elseif ( $in_stock ) : ?>
                    <a href="<?php echo esc_url( $cart_url ); ?>"
                       rel="nofollow"
                       data-product_id="<?php echo esc_attr( $product_id ); ?>"
                       data-product_sku="<?php echo esc_attr( $product_sku ); ?>"
                       data-quantity="1"
                       class="adn-btn-carrito button add_to_cart_button ajax_add_to_cart"><?php echo esc_html( $cart_text ); ?></a>
                <?php else : ?>
                    <span class="adn-btn-agotado"><?php esc_html_e( 'Sin stock', 'adn-productos' ); ?></span>
                <?php endif; ?>
            </div>
        </li>
        <?php
        return ob_get_clean();
    }

    /**
     * Handler AJAX: filtra productos sin recargar la página.
     */
    public function ajax_filter_products() {
        check_ajax_referer( 'adn_filter_nonce', 'nonce' );

        $columns = max( 1, intval( $_POST['columns'] ?? 3 ) );
        $limit   = max( 1, intval( $_POST['limit']   ?? 12 ) );

        $atts = array(
            'columns'  => $columns,
            'orderby'  => sanitize_key( $_POST['orderby_key'] ?? 'date' ),
            'order'    => 'DESC',
            'limit'    => $limit,
            'category' => '',
            'ids'      => '',
        );

        $args = $this->build_query_args( $atts );

        // Búsqueda por texto
        if ( ! empty( $_POST['s'] ) ) {
            $args['s'] = sanitize_text_field( wp_unslash( $_POST['s'] ) );
        }

        // Ordenamiento
        $orderby_val = sanitize_key( $_POST['orderby_key'] ?? '' );
        switch ( $orderby_val ) {
            case 'price':
                $args['orderby'] = 'meta_value_num'; $args['meta_key'] = '_price'; $args['order'] = 'ASC'; break;
            case 'price-desc':
                $args['orderby'] = 'meta_value_num'; $args['meta_key'] = '_price'; $args['order'] = 'DESC'; break;
            case 'popularity':
                $args['orderby'] = 'meta_value_num'; $args['meta_key'] = 'total_sales'; $args['order'] = 'DESC'; break;
            case 'title':
                $args['orderby'] = 'title'; $args['order'] = 'ASC'; break;
            case 'date':
                $args['orderby'] = 'date'; $args['order'] = 'DESC'; break;
        }

        // Rango de precio
        if ( isset( $_POST['min_price'] ) && '' !== $_POST['min_price'] ) {
            $args['meta_query'][] = array( 'key' => '_price', 'value' => floatval( $_POST['min_price'] ), 'compare' => '>=', 'type' => 'NUMERIC' );
        }
        if ( isset( $_POST['max_price'] ) && '' !== $_POST['max_price'] ) {
            $args['meta_query'][] = array( 'key' => '_price', 'value' => floatval( $_POST['max_price'] ), 'compare' => '<=', 'type' => 'NUMERIC' );
        }

        // ── Método primario: filters_json (JSON string enviado por JS) ──────────
        if ( ! empty( $_POST['filters_json'] ) ) {
            $fj = json_decode( wp_unslash( $_POST['filters_json'] ), true );
            if ( is_array( $fj ) ) {
                // Por term_id
                if ( ! empty( $fj['term_ids'] ) && is_array( $fj['term_ids'] ) ) {
                    foreach ( $fj['term_ids'] as $fj_tax => $fj_ids ) {
                        $fj_tax = sanitize_key( $fj_tax );
                        if ( taxonomy_exists( $fj_tax ) && ! empty( $fj_ids ) ) {
                            $args['tax_query'][] = array(
                                'taxonomy' => $fj_tax,
                                'field'    => 'term_id',
                                'terms'    => array_map( 'intval', (array) $fj_ids ),
                            );
                        }
                    }
                }
                // Por slug
                if ( ! empty( $fj['slugs'] ) && is_array( $fj['slugs'] ) ) {
                    foreach ( $fj['slugs'] as $fj_tax => $fj_slugs ) {
                        $fj_tax = sanitize_key( $fj_tax );
                        if ( taxonomy_exists( $fj_tax ) && ! empty( $fj_slugs ) ) {
                            $args['tax_query'][] = array(
                                'taxonomy' => $fj_tax,
                                'field'    => 'slug',
                                'terms'    => array_map( 'sanitize_title', (array) $fj_slugs ),
                            );
                        }
                    }
                }
            }
        }

        // Filtros de taxonomía por slug (filter_pa_*)
        if ( ! empty( $_POST['tax_filters'] ) && is_array( $_POST['tax_filters'] ) ) {
            foreach ( $_POST['tax_filters'] as $taxonomy => $slugs ) {
                $taxonomy = sanitize_key( $taxonomy );
                if ( taxonomy_exists( $taxonomy ) ) {
                    $args['tax_query'][] = array(
                        'taxonomy' => $taxonomy,
                        'field'    => 'slug',
                        'terms'    => array_map( 'sanitize_title', (array) $slugs ),
                    );
                }
            }
        }

        // Filtros BeRocket por term_id (tax_term_ids: { product_cat: [71, 52] })
        if ( ! empty( $_POST['tax_term_ids'] ) && is_array( $_POST['tax_term_ids'] ) ) {
            foreach ( $_POST['tax_term_ids'] as $taxonomy => $term_ids ) {
                $taxonomy = sanitize_key( $taxonomy );
                if ( taxonomy_exists( $taxonomy ) ) {
                    $args['tax_query'][] = array(
                        'taxonomy' => $taxonomy,
                        'field'    => 'term_id',
                        'terms'    => array_map( 'intval', (array) $term_ids ),
                    );
                }
            }
        }

        // Parseo directo del query string completo (máxima robustez)
        if ( ! empty( $_POST['location_search'] ) ) {
            $qs_raw = sanitize_text_field( wp_unslash( $_POST['location_search'] ) );
            $qs_raw = ltrim( $qs_raw, '?' );
            parse_str( $qs_raw, $qs_parsed );
            if ( ! empty( $qs_parsed['filters'] ) ) {
                $qs_filters = $qs_parsed['filters'];
                preg_match_all( '/([a-zA-Z_][a-zA-Z0-9_]*)\[([\d][\d\s\-]*)\]/', $qs_filters, $qs_matches, PREG_SET_ORDER );
                foreach ( $qs_matches as $qs_match ) {
                    $qs_tax  = sanitize_key( $qs_match[1] );
                    $qs_ids  = preg_split( '/[-\s]+/', trim( $qs_match[2] ), -1, PREG_SPLIT_NO_EMPTY );
                    if ( ! taxonomy_exists( $qs_tax ) || empty( $qs_ids ) ) { continue; }
                    // Verificar si ya fue agregado
                    $qs_already = false;
                    foreach ( $args['tax_query'] as $tq ) {
                        if ( isset( $tq['taxonomy'] ) && $tq['taxonomy'] === $qs_tax ) {
                            $qs_already = true; break;
                        }
                    }
                    if ( ! $qs_already ) {
                        $args['tax_query'][] = array(
                            'taxonomy' => $qs_tax,
                            'field'    => 'term_id',
                            'terms'    => array_map( 'intval', $qs_ids ),
                        );
                    }
                }
            }
        }

        // Fallback: parsear raw_filters del formato BeRocket "product_cat[71 134]" o "product_cat[71]product_cat[134]"
        if ( ! empty( $_POST['raw_filters'] ) ) {
            $raw = sanitize_text_field( wp_unslash( $_POST['raw_filters'] ) );
            preg_match_all( '/([a-zA-Z_][a-zA-Z0-9_]*)\[([\d][\d\s,\-]*)\]/', $raw, $rf_matches, PREG_SET_ORDER );
            $rf_by_tax = array();
            foreach ( $rf_matches as $rf_match ) {
                $rf_tax  = sanitize_key( $rf_match[1] );
                $rf_ids_raw = preg_split( '/[-\s,]+/', trim( $rf_match[2] ), -1, PREG_SPLIT_NO_EMPTY );
                foreach ( $rf_ids_raw as $rf_id_str ) {
                    $rf_id = intval( $rf_id_str );
                    if ( taxonomy_exists( $rf_tax ) && $rf_id > 0 ) {
                        $rf_by_tax[ $rf_tax ][] = $rf_id;
                    }
                }
            }
            foreach ( $rf_by_tax as $rf_tax => $rf_ids ) {
                // Solo agregar si no fue incluido ya por tax_term_ids
                $already = false;
                foreach ( $args['tax_query'] as $tq ) {
                    if ( isset( $tq['taxonomy'] ) && $tq['taxonomy'] === $rf_tax && isset( $tq['field'] ) && $tq['field'] === 'term_id' ) {
                        $already = true; break;
                    }
                }
                if ( ! $already ) {
                    $args['tax_query'][] = array(
                        'taxonomy' => $rf_tax,
                        'field'    => 'term_id',
                        'terms'    => array_unique( $rf_ids ),
                    );
                }
            }
        }

        // Asegurar relación AND cuando hay múltiples tax_query
        if ( count( $args['tax_query'] ) > 1 && ! isset( $args['tax_query']['relation'] ) ) {
            $args['tax_query']['relation'] = 'AND';
        }

        // Página actual desde POST (location_search o adn_paged directo)
        $ajax_paged = 1;
        if ( ! empty( $_POST['adn_paged'] ) ) {
            $ajax_paged = max( 1, intval( $_POST['adn_paged'] ) );
        } elseif ( ! empty( $_POST['location_search'] ) ) {
            $ls_raw = ltrim( sanitize_text_field( wp_unslash( $_POST['location_search'] ) ), '?' );
            parse_str( $ls_raw, $ls_parsed );
            if ( ! empty( $ls_parsed['adn_paged'] ) ) {
                $ajax_paged = max( 1, intval( $ls_parsed['adn_paged'] ) );
            }
        }
        $args['paged'] = $ajax_paged;

        $location_href = ! empty( $_POST['location_href'] )
            ? sanitize_url( wp_unslash( $_POST['location_href'] ) )
            : '';

        $query = new WP_Query( $args );

        ob_start();
        if ( ! $query->have_posts() ) {
            echo '<p class="adn-sin-productos">No se encontraron productos.</p>';
        } else {
            echo '<ul class="products adn-productos-grid columns-' . esc_attr( $columns ) . '" style="--adn-columns:' . esc_attr( $columns ) . '">';
            while ( $query->have_posts() ) {
                $query->the_post();
                $product = wc_get_product( get_the_ID() );
                if ( ! $product ) { continue; }
                echo $this->render_product_card( $product ); // phpcs:ignore
            }
            echo '</ul>';
            echo $this->render_pagination( $query, $location_href ); // phpcs:ignore
        }
        wp_reset_postdata();

        wp_send_json_success( array(
            'html'  => ob_get_clean(),
            'count' => $query->found_posts,
            'debug' => array(
                'tax_query'       => $args['tax_query'],
                'filters_json_in' => isset( $_POST['filters_json'] ) ? substr( $_POST['filters_json'], 0, 500 ) : null,
                'raw_filters_in'  => isset( $_POST['raw_filters'] )  ? substr( $_POST['raw_filters'],  0, 200 ) : null,
                's_in'            => isset( $_POST['s'] )             ? $_POST['s']                             : null,
            ),
        ) );
    }

    /**
     * Renderiza el shortcode [product_brand_thumbnails].
     * Muestra un slider de marcas/categorías con imagen y nombre.
     *
     * Atributos:
     *  - number      : cantidad de marcas a mostrar (default 12)
     *  - columns     : columnas visibles en desktop (default 4; mobile siempre 2)
     *  - show_empty  : mostrar términos sin productos — true | false (default false)
     *  - taxonomy    : taxonomía — product_brand | pwb-brand | product_cat (default: auto-detecta)
     *  - orderby     : name | count | id | slug (default name)
     *
     * Ejemplo:
     *  [product_brand_thumbnails number="12" columns="4" show_empty="false"]
     */
    public function render_brands_slider( $atts ) {

        if ( ! class_exists( 'WooCommerce' ) ) {
            return '';
        }

        $atts = shortcode_atts(
            array(
                'number'     => 12,
                'columns'    => 4,
                'show_empty' => 'false',
                'taxonomy'   => '',
                'orderby'    => 'name',
            ),
            $atts,
            'product_brand_thumbnails'
        );

        // Detectar taxonomía automáticamente si no se especifica
        $taxonomy = sanitize_key( $atts['taxonomy'] );
        if ( empty( $taxonomy ) ) {
            foreach ( array( 'product_brand', 'pwb-brand', 'berocket_brand' ) as $candidate ) {
                if ( taxonomy_exists( $candidate ) ) {
                    $taxonomy = $candidate;
                    break;
                }
            }
            if ( empty( $taxonomy ) ) {
                $taxonomy = 'product_cat';
            }
        }

        $hide_empty = ( 'false' === strtolower( trim( $atts['show_empty'] ) ) );
        $number     = max( 1, intval( $atts['number'] ) );
        $columns    = max( 1, intval( $atts['columns'] ) );

        $terms = get_terms(
            array(
                'taxonomy'   => $taxonomy,
                'number'     => $number,
                'hide_empty' => $hide_empty,
                'orderby'    => sanitize_key( $atts['orderby'] ),
            )
        );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return '';
        }

        $total     = count( $terms );
        $slider_id = 'adn-brands-' . wp_unique_id();

        ob_start();
        ?>
        <div class="adn-brands-slider-outer">
            <div class="adn-brands-slider-wrapper"
                 id="<?php echo esc_attr( $slider_id ); ?>"
                 data-columns="<?php echo esc_attr( $columns ); ?>"
                 style="--adn-slide-cols:<?php echo esc_attr( $columns ); ?>">

                <button class="adn-brands-nav adn-brands-prev" aria-label="Anterior" disabled>
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                </button>

                <div class="adn-brands-viewport">
                    <div class="adn-brands-track"
                         data-columns="<?php echo esc_attr( $columns ); ?>"
                         data-total="<?php echo esc_attr( $total ); ?>">
                        <?php foreach ( $terms as $term ) :
                            $link    = get_term_link( $term );
                            $img_url = $this->get_term_image_url( $term );
                        ?>
                        <div class="adn-brand-slide">
                            <a href="<?php echo esc_url( is_wp_error( $link ) ? '#' : $link ); ?>"
                               class="adn-brand-link"
                               title="<?php echo esc_attr( $term->name ); ?>">
                                <div class="adn-brand-img-wrap">
                                    <img src="<?php echo esc_url( $img_url ); ?>"
                                         alt="<?php echo esc_attr( $term->name ); ?>"
                                         loading="lazy" />
                                </div>
                                <span class="adn-brand-name"><?php echo esc_html( $term->name ); ?></span>
                                <?php if ( $term->count > 0 ) : ?>
                                <span class="adn-brand-count"><?php echo esc_html( $term->count ); ?></span>
                                <?php endif; ?>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button class="adn-brands-nav adn-brands-next" aria-label="Siguiente">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>
            </div>

            <div class="adn-brands-dots" data-slider="<?php echo esc_attr( $slider_id ); ?>"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Obtiene la URL de la imagen de un término (marca/categoría).
     * Compatible con: WooCommerce product_cat, Perfect Brands (pwb-brand),
     * WooCommerce Brands (product_brand) y BeRocket Brand.
     *
     * @param WP_Term $term
     * @param string  $size Tamaño de imagen WordPress (default 'medium')
     * @return string URL de la imagen o placeholder
     */
    private function get_term_image_url( $term, $size = 'medium' ) {
        $meta_keys = array(
            'thumbnail_id',
            'product_brand_image',
            'pwb-brand-image',
            'berocket_brand_image',
        );

        foreach ( $meta_keys as $key ) {
            $thumbnail_id = get_term_meta( $term->term_id, $key, true );
            if ( $thumbnail_id ) {
                $img = wp_get_attachment_image_src( intval( $thumbnail_id ), $size );
                if ( $img ) {
                    return $img[0];
                }
            }
        }

        return wc_placeholder_img_src( $size );
    }

    /**
     * Construye los args base de WP_Query a partir de los atributos del shortcode.
     */
    private function build_query_args( $atts ) {
        $limit   = max( 1, intval( $atts['limit'] ) );
        $orderby = sanitize_key( $atts['orderby'] );
        $order   = in_array( strtoupper( $atts['order'] ), array( 'ASC', 'DESC' ), true )
                   ? strtoupper( $atts['order'] )
                   : 'DESC';

        $args = array(
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'posts_per_page'      => $limit,
            'orderby'             => $orderby,
            'order'               => $order,
            'ignore_sticky_posts' => 1,
            'paged'               => 1,
            'no_found_rows'       => false,
            'tax_query'           => array( 'relation' => 'AND' ),
            'meta_query'          => array(
                'relation' => 'AND',
                array(
                    'key'     => '_stock_status',
                    'value'   => 'instock',
                    'compare' => '=',
                ),
            ),
        );

        if ( $orderby === 'price' ) {
            $args['orderby']  = 'meta_value_num';
            $args['meta_key'] = '_price';
        }

        if ( ! empty( $atts['category'] ) ) {
            $args['tax_query'][] = array(
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => array_map( 'trim', explode( ',', $atts['category'] ) ),
            );
        }

        if ( ! empty( $atts['ids'] ) ) {
            $args['post__in'] = array_map( 'intval', explode( ',', $atts['ids'] ) );
        }

        return $args;
    }

    /**
     * Aplica los parámetros GET establecidos por plugins de filtros AJAX de WooCommerce.
     * Compatible con: WOOF, WooCommerce AJAX Products Filter, FiboFilters y similares.
     *
     * Parámetros soportados:
     *  - orderby            : criterio de orden (price, price-desc, popularity, rating, date, title)
     *  - min_price          : precio mínimo
     *  - max_price          : precio máximo
     *  - filter_pa_*        : filtros de atributos (ej: filter_pa_color=rojo,azul)
     *  - query_type_pa_*    : operador AND/OR para cada atributo
     *  - product_cat        : slug(s) de categoría separados por coma
     *  - product_tag        : slug(s) de etiqueta separados por coma
     *  - s                  : búsqueda por texto
     */

    /**
     * Genera el HTML de paginación para el shortcode [adn_productos].
     * Preserva todos los parámetros GET actuales (filtros, orderby, etc.)
     * y solo varía el parámetro ?adn_paged=N.
     *
     * Para que BeRocket AJAX actualice también la paginación, cambia su
     * "Products Selector" de `ul.products` a `.adn-productos-wrapper`.
     *
     * @param WP_Query $query Query ejecutado.
     * @return string HTML de la paginación o cadena vacía.
     */
    private function render_pagination( $query, $base_override = '' ) {
        $max_pages = (int) $query->max_num_pages;
        if ( $max_pages <= 1 ) {
            return '';
        }

        if ( $base_override ) {
            // AJAX: extraer adn_paged de la URL enviada por JS
            $bo_parts = wp_parse_url( $base_override );
            $bo_qs    = array();
            if ( ! empty( $bo_parts['query'] ) ) {
                parse_str( $bo_parts['query'], $bo_qs );
            }
            $paged    = isset( $bo_qs['adn_paged'] ) ? max( 1, intval( $bo_qs['adn_paged'] ) ) : 1;
            $base_url = remove_query_arg( 'adn_paged', sanitize_url( $base_override ) );
        } else {
            // Carga normal: usar REQUEST_URI
            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
            $scheme      = is_ssl() ? 'https' : 'http';
            $host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
            $full_url    = $scheme . '://' . $host . $request_uri;
            $base_url    = remove_query_arg( 'adn_paged', $full_url );
        }
        $sep         = strpos( $base_url, '?' ) !== false ? '&' : '?';

        $links = paginate_links( array(
            'base'      => $base_url . '%_%',
            'format'    => $sep . 'adn_paged=%#%',
            'current'   => $paged,
            'total'     => $max_pages,
            'type'      => 'array',
            'end_size'  => 1,
            'mid_size'  => 2,
            'prev_text' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>',
            'next_text' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>',
        ) );

        if ( empty( $links ) ) {
            return '';
        }

        $html  = '<nav class="adn-pagination" aria-label="Paginación de productos">';
        $html .= '<ul class="adn-pagination-list">';
        foreach ( $links as $link ) {
            $is_current = strpos( $link, 'current' ) !== false;
            $is_dots    = strpos( $link, 'dots' )    !== false;
            $cls = 'adn-pagination-item';
            if ( $is_current ) $cls .= ' is-current';
            if ( $is_dots )    $cls .= ' is-dots';
            $html .= '<li class="' . esc_attr( $cls ) . '">' . $link . '</li>';
        }
        $html .= '</ul></nav>';
        return $html;
    }

    private function apply_url_filters( $args ) {

        // Paginación (?adn_paged=2) — resetea a pág. 1 si llega un filtro nuevo
        $paged         = isset( $_GET['adn_paged'] ) ? max( 1, intval( $_GET['adn_paged'] ) ) : 1;
        $args['paged'] = $paged;

        // Ordenamiento desde URL (selector del plugin de filtros)
        if ( ! empty( $_GET['orderby'] ) ) {
            $orderby_val = wc_clean( wp_unslash( $_GET['orderby'] ) );
            switch ( $orderby_val ) {
                case 'price':
                    $args['orderby']  = 'meta_value_num';
                    $args['meta_key'] = '_price';
                    $args['order']    = 'ASC';
                    break;
                case 'price-desc':
                    $args['orderby']  = 'meta_value_num';
                    $args['meta_key'] = '_price';
                    $args['order']    = 'DESC';
                    break;
                case 'popularity':
                    $args['orderby']  = 'meta_value_num';
                    $args['meta_key'] = 'total_sales';
                    $args['order']    = 'DESC';
                    break;
                case 'rating':
                    $args['orderby']  = 'meta_value_num';
                    $args['meta_key'] = '_wc_average_rating';
                    $args['order']    = 'DESC';
                    break;
                case 'date':
                    $args['orderby'] = 'date';
                    $args['order']   = 'DESC';
                    break;
                case 'title':
                    $args['orderby'] = 'title';
                    $args['order']   = 'ASC';
                    break;
            }
        }

        // Filtro de rango de precio (?min_price=X&max_price=Y)
        $min_price = isset( $_GET['min_price'] ) ? floatval( $_GET['min_price'] ) : null;
        $max_price = isset( $_GET['max_price'] ) ? floatval( $_GET['max_price'] ) : null;

        if ( null !== $min_price && null !== $max_price ) {
            $args['meta_query'][] = array(
                'key'     => '_price',
                'value'   => array( $min_price, $max_price ),
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            );
        } elseif ( null !== $min_price ) {
            $args['meta_query'][] = array(
                'key'     => '_price',
                'value'   => $min_price,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            );
        } elseif ( null !== $max_price ) {
            $args['meta_query'][] = array(
                'key'     => '_price',
                'value'   => $max_price,
                'compare' => '<=',
                'type'    => 'NUMERIC',
            );
        }

        // Filtros de atributos (?filter_pa_color=rojo,azul&query_type_pa_color=or)
        foreach ( $_GET as $key => $value ) {
            if ( 0 !== strpos( $key, 'filter_' ) || empty( $value ) ) {
                continue;
            }
            $taxonomy = sanitize_key( str_replace( 'filter_', '', $key ) );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $query_type_key = 'query_type_' . $taxonomy;
            $operator       = ( isset( $_GET[ $query_type_key ] ) && 'and' === wc_clean( wp_unslash( $_GET[ $query_type_key ] ) ) )
                              ? 'AND'
                              : 'IN';

            $args['tax_query'][] = array(
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => array_map( 'sanitize_title', explode( ',', wc_clean( wp_unslash( $value ) ) ) ),
                'operator' => $operator,
            );
        }

        // Filtro de categoría desde URL (?product_cat=ropa,calzado)
        if ( ! empty( $_GET['product_cat'] ) ) {
            $args['tax_query'][] = array(
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => array_map( 'sanitize_title', explode( ',', wc_clean( wp_unslash( $_GET['product_cat'] ) ) ) ),
            );
        }

        // Filtro de etiqueta desde URL (?product_tag=nueva,oferta)
        if ( ! empty( $_GET['product_tag'] ) ) {
            $args['tax_query'][] = array(
                'taxonomy' => 'product_tag',
                'field'    => 'slug',
                'terms'    => array_map( 'sanitize_title', explode( ',', wc_clean( wp_unslash( $_GET['product_tag'] ) ) ) ),
            );
        }

        // Búsqueda por texto (?s=zapatilla)
        if ( ! empty( $_GET['s'] ) ) {
            $args['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
        }

        return $args;
    }

    // =========================================================================
    // SINCRONIZACIÓN ADN ←→ WORDPRESS
    // =========================================================================

    /**
     * Verifica la clave secreta en X-ADN-Key (header) o key (param).
     */
    private function adn_auth_check( WP_REST_Request $request ): bool {
        $key   = sanitize_text_field(
            $request->get_header( 'X-ADN-Key' ) ?: $request->get_param( 'key' ) ?: ''
        );
        $saved = get_option( 'adn_loro_key', '' );
        return ! empty( $saved ) && hash_equals( $saved, $key );
    }

    /**
     * Escribe en el log diario de sincronización.
     */
    private function adn_log( string $type, string $msg ): void {
        $dir = WP_CONTENT_DIR . '/uploads/adn-logs';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        @file_put_contents(
            $dir . '/loro_' . $type . '_' . date( 'Y-m-d' ) . '.log',
            '[' . date( 'Y-m-d H:i:s' ) . '] ' . $msg . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Registra todos los endpoints REST bajo el namespace adn-loro/v1.
     */
    public function register_sync_endpoints(): void {
        $ns   = 'adn-loro/v1';
        $self = $this;
        $auth = function ( WP_REST_Request $req ) use ( $self ) {
            return $self->adn_auth_check( $req ) || current_user_can( 'manage_woocommerce' );
        };
        $admin = function () {
            return current_user_can( 'manage_options' );
        };

        register_rest_route( $ns, '/ingest-products', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_ingest_products' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( $ns, '/ingest-brands', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_ingest_brands' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( $ns, '/ingest-categories', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_ingest_categories' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( $ns, '/upload-image', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_upload_image' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( $ns, '/pending-images', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_pending_images' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( $ns, '/orders', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_get_orders' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( $ns, '/orders/mark-synced', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_mark_orders_synced' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( $ns, '/ingest-customers', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_ingest_customers' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( $ns, '/set-key', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_set_key' ],
            'permission_callback' => $admin,
        ] );

        register_rest_route( $ns, '/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_status' ],
            'permission_callback' => $auth,
        ] );
    }

    // ─── Endpoint: Ingestar productos (crear / actualizar) ────────────────────

    public function handle_ingest_products( WP_REST_Request $request ): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce no activo' ], 500 );
        }

        $body       = $request->get_json_params();
        $products   = $body['products'] ?? [];
        $only_stock = ! empty( $body['only_stock'] );
        if ( empty( $products ) || ! is_array( $products ) ) {
            return new WP_REST_Response( [ 'error' => 'Falta el campo products (array)' ], 400 );
        }

        $created = 0;
        $updated = 0;
        $errors  = 0;
        $log     = [];

        foreach ( $products as $item ) {
            $sku = sanitize_text_field( trim( $item['sku'] ?? '' ) );
            if ( empty( $sku ) ) {
                $errors++;
                continue;
            }

            $name   = sanitize_text_field( $item['name']        ?? $sku );
            $price  = (float) ( $item['price']                  ?? 0 );
            $stock  = (int)   ( $item['stock']                  ?? 0 );
            $brand  = sanitize_text_field( $item['brand']       ?? '' );
            $cat    = sanitize_text_field( $item['category']    ?? '' );
            $desc   = wp_kses_post( $item['description']        ?? '' );
            $status = in_array( $item['status'] ?? 'publish', [ 'publish', 'draft' ], true )
                      ? $item['status'] : 'publish';

            $product_id = wc_get_product_id_by_sku( $sku );

            if ( $product_id ) {
                $product = wc_get_product( $product_id );
                $is_new  = false;
            } else {
                $product = new WC_Product_Simple();
                $product->set_sku( $sku );
                $is_new  = true;
            }

            if ( ! $product ) {
                $errors++;
                continue;
            }

            if ( $only_stock ) {
                // Modo existencias: solo actualizar stock en productos ya existentes
                if ( $is_new ) { $errors++; continue; }
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $stock );
                $product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
                $saved_id = $product->save();
                if ( ! $saved_id || is_wp_error( $saved_id ) ) { $errors++; continue; }
                $updated++;
                continue;
            }

            if ( ! empty( $name ) && $name !== $sku ) {
                $product->set_name( $name );
            } elseif ( $is_new ) {
                $product->set_name( $sku );
            }
            $product->set_status( $status );
            if ( $price > 0 ) {
                $product->set_regular_price( $price );
                $product->set_price( $price );
            }
            if ( ! empty( $desc ) ) {
                $product->set_description( $desc );
            }
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $stock );
            $product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );

            $saved_id = $product->save();
            if ( ! $saved_id || is_wp_error( $saved_id ) ) {
                $errors++;
                continue;
            }

            // Categoría
            if ( ! empty( $cat ) ) {
                $cat_term = get_term_by( 'name', $cat, 'product_cat' );
                if ( ! $cat_term ) {
                    $ins = wp_insert_term( $cat, 'product_cat' );
                    $tid = is_wp_error( $ins ) ? 0 : (int) $ins['term_id'];
                } else {
                    $tid = $cat_term->term_id;
                }
                if ( $tid ) {
                    wp_set_object_terms( $saved_id, [ $tid ], 'product_cat', false );
                }
            }

            // Marca
            if ( ! empty( $brand ) && strtolower( $brand ) !== 'indefinido' ) {
                foreach ( [ 'product_brand', 'pwb-brand' ] as $tax ) {
                    if ( ! taxonomy_exists( $tax ) ) {
                        continue;
                    }
                    $b_term = get_term_by( 'name', $brand, $tax );
                    if ( ! $b_term ) {
                        $ins = wp_insert_term( $brand, $tax );
                        $bid = is_wp_error( $ins ) ? 0 : (int) $ins['term_id'];
                    } else {
                        $bid = $b_term->term_id;
                    }
                    if ( $bid ) {
                        wp_set_object_terms( $saved_id, [ $bid ], $tax );
                    }
                    break;
                }
            }

            // Imagen: soporte image_url en el JSON
            $image_url = esc_url_raw( trim( $item['image_url'] ?? '' ) );
            $force_img = ! empty( $item['force_image'] );

            if ( ! empty( $image_url ) && ( $force_img || ! has_post_thumbnail( $saved_id ) ) ) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                if ( $force_img ) {
                    $old_att = get_post_thumbnail_id( $saved_id );
                    if ( $old_att ) {
                        delete_post_meta( $saved_id, '_thumbnail_id' );
                        wp_delete_attachment( $old_att, true );
                    }
                }

                $att_id = media_sideload_image( $image_url, $saved_id, $name, 'id' );
                if ( ! is_wp_error( $att_id ) ) {
                    set_post_thumbnail( $saved_id, $att_id );
                    wc_delete_product_transients( $saved_id );
                }
            } elseif ( ! has_post_thumbnail( $saved_id ) ) {
                // Sin image_url: encolar para subir después via /upload-image
                $queue         = get_option( 'adn_loro_pending_images', [] );
                $queue[ $sku ] = $sku . '.jpg';
                update_option( 'adn_loro_pending_images', $queue, false );
            }

            if ( $is_new ) {
                $created++;
                $log[] = 'NEW ' . $sku;
            } else {
                $updated++;
                $log[] = 'UPD ' . $sku;
            }
        }

        update_option( 'adn_loro_last_sync', current_time( 'mysql' ) );
        $this->adn_log(
            'productos',
            "created=$created updated=$updated errors=$errors | " . implode( ', ', array_slice( $log, 0, 20 ) )
        );

        return new WP_REST_Response( [
            'created' => $created,
            'updated' => $updated,
            'errors'  => $errors,
            'total'   => count( $products ),
        ], 200 );
    }

    // ─── Endpoint: Ingestar marcas ────────────────────────────────────────────

    public function handle_ingest_brands( WP_REST_Request $request ): WP_REST_Response {
        $body   = $request->get_json_params();
        $brands = $body['brands'] ?? [];
        if ( empty( $brands ) || ! is_array( $brands ) ) {
            return new WP_REST_Response( [ 'error' => 'Falta el campo brands (array)' ], 400 );
        }

        $tax = '';
        foreach ( [ 'product_brand', 'pwb-brand' ] as $candidate ) {
            if ( taxonomy_exists( $candidate ) ) {
                $tax = $candidate;
                break;
            }
        }
        if ( empty( $tax ) ) {
            return new WP_REST_Response( [ 'error' => 'No hay taxonomía de marcas activa (product_brand / pwb-brand)' ], 500 );
        }

        $synced = 0;
        $errors = 0;

        foreach ( $brands as $b ) {
            $nombre = sanitize_text_field( trim( $b['nombre'] ?? $b['name'] ?? '' ) );
            $codigo = sanitize_text_field( trim( $b['codigo'] ?? $b['code'] ?? '' ) );
            if ( empty( $nombre ) || strtolower( $nombre ) === 'indefinido' ) {
                continue;
            }

            $term = get_term_by( 'name', $nombre, $tax );
            if ( $term ) {
                $term_id = $term->term_id;
            } else {
                $ins = wp_insert_term( $nombre, $tax );
                if ( is_wp_error( $ins ) ) {
                    $errors++;
                    continue;
                }
                $term_id = (int) $ins['term_id'];
            }

            if ( ! empty( $codigo ) ) {
                update_term_meta( $term_id, '_adn_codigo', $codigo );
            }
            $synced++;
        }

        $this->adn_log( 'brands', "synced=$synced errors=$errors total=" . count( $brands ) );
        return new WP_REST_Response( [
            'synced' => $synced,
            'errors' => $errors,
            'total'  => count( $brands ),
        ], 200 );
    }

    // ─── Endpoint: Ingestar categorías ───────────────────────────────────────

    public function handle_ingest_categories( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();
        $cats = $body['categories'] ?? [];
        if ( empty( $cats ) || ! is_array( $cats ) ) {
            return new WP_REST_Response( [ 'error' => 'Falta el campo categories (array)' ], 400 );
        }

        $synced = 0;
        $errors = 0;

        foreach ( $cats as $c ) {
            $nombre = sanitize_text_field( trim( $c['name'] ?? '' ) );
            $parent = sanitize_text_field( trim( $c['parent'] ?? '' ) );
            if ( empty( $nombre ) ) {
                continue;
            }

            $parent_id = 0;
            if ( ! empty( $parent ) ) {
                $p_term = get_term_by( 'name', $parent, 'product_cat' );
                if ( $p_term ) {
                    $parent_id = $p_term->term_id;
                }
            }

            $existing = get_term_by( 'name', $nombre, 'product_cat' );
            if ( $existing ) {
                if ( $parent_id ) {
                    wp_update_term( $existing->term_id, 'product_cat', [ 'parent' => $parent_id ] );
                }
                $synced++;
            } else {
                $args = $parent_id ? [ 'parent' => $parent_id ] : [];
                $ins  = wp_insert_term( $nombre, 'product_cat', $args );
                if ( is_wp_error( $ins ) ) {
                    $errors++;
                    continue;
                }
                $synced++;
            }
        }

        $this->adn_log( 'categories', "synced=$synced errors=$errors total=" . count( $cats ) );
        return new WP_REST_Response( [
            'synced' => $synced,
            'errors' => $errors,
            'total'  => count( $cats ),
        ], 200 );
    }

    // ─── Endpoint: Subir imagen de producto (multipart) ──────────────────────

    public function handle_upload_image( WP_REST_Request $request ): WP_REST_Response {
        $sku = sanitize_text_field( trim( $request->get_param( 'sku' ) ?? '' ) );
        if ( empty( $sku ) ) {
            return new WP_REST_Response( [ 'error' => 'Falta el parámetro sku' ], 400 );
        }

        $files = $request->get_file_params();
        if ( empty( $files['image'] ) || ! empty( $files['image']['error'] ) ) {
            return new WP_REST_Response( [ 'error' => 'No se recibió imagen válida' ], 400 );
        }

        $product_id = wc_get_product_id_by_sku( $sku );
        if ( ! $product_id ) {
            return new WP_REST_Response( [ 'error' => "Producto no encontrado para SKU: $sku" ], 404 );
        }

        $force = filter_var( $request->get_param( 'force' ) ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( ! $force && has_post_thumbnail( $product_id ) ) {
            return new WP_REST_Response( [ 'skipped' => true, 'sku' => $sku, 'product_id' => $product_id ], 200 );
        }

        if ( $force ) {
            $old_att = get_post_thumbnail_id( $product_id );
            if ( $old_att ) {
                delete_post_meta( $product_id, '_thumbnail_id' );
                wp_delete_attachment( $old_att, true );
            }
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $file         = $files['image'];
        $ext          = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ?: 'jpg';
        $file['name'] = sanitize_file_name( $sku ) . '_' . time() . '.' . $ext;
        $upload       = wp_handle_upload( $file, [ 'test_form' => false ] );

        if ( isset( $upload['error'] ) ) {
            return new WP_REST_Response( [ 'error' => $upload['error'] ], 500 );
        }

        $att_id = wp_insert_attachment( [
            'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
            'post_status'    => 'inherit',
            'post_mime_type' => $upload['type'],
        ], $upload['file'], $product_id );

        if ( is_wp_error( $att_id ) ) {
            return new WP_REST_Response( [ 'error' => $att_id->get_error_message() ], 500 );
        }

        wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $upload['file'] ) );
        set_post_thumbnail( $product_id, $att_id );
        wc_delete_product_transients( $product_id );

        // Remover de cola de pendientes
        $queue = get_option( 'adn_loro_pending_images', [] );
        unset( $queue[ $sku ] );
        update_option( 'adn_loro_pending_images', $queue, false );

        $this->adn_log( 'images', "OK sku=$sku att_id=$att_id" );

        return new WP_REST_Response( [
            'success'       => true,
            'sku'           => $sku,
            'product_id'    => $product_id,
            'attachment_id' => $att_id,
        ], 200 );
    }

    // ─── Endpoint: Consultar cola de imágenes pendientes ─────────────────────

    public function handle_pending_images( WP_REST_Request $request ): WP_REST_Response {
        $queue = get_option( 'adn_loro_pending_images', [] );
        $clear = filter_var( $request->get_param( 'clear' ) ?? false, FILTER_VALIDATE_BOOLEAN );
        if ( $clear && ! empty( $queue ) ) {
            delete_option( 'adn_loro_pending_images' );
        }
        return new WP_REST_Response( [
            'pending' => $queue,
            'count'   => count( $queue ),
        ], 200 );
    }

    // ─── Endpoint: Obtener pedidos web no sincronizados con ADN ──────────────

    public function handle_get_orders( WP_REST_Request $request ): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce no activo' ], 500 );
        }

        $limit        = min( 100, max( 1, (int) ( $request->get_param( 'limit' ) ?? 50 ) ) );
        $status_raw   = sanitize_text_field( $request->get_param( 'status' ) ?? 'processing,on-hold' );
        $status       = array_map( 'trim', explode( ',', $status_raw ) );
        $only_pending = filter_var( $request->get_param( 'only_pending' ) ?? true, FILTER_VALIDATE_BOOLEAN );

        $orders = wc_get_orders( [
            'limit'  => $limit,
            'status' => $status,
        ] );

        $result = [];
        foreach ( $orders as $order ) {
            if ( $only_pending && get_post_meta( $order->get_id(), '_adn_synced', true ) ) {
                continue;
            }

            $items = [];
            foreach ( $order->get_items() as $item ) {
                $product   = $item->get_product();
                $qty       = (float) $item->get_quantity();
                $subtotal  = (float) $item->get_subtotal();
                $items[] = [
                    'sku'        => $product ? $product->get_sku() : '',
                    'name'       => $item->get_name(),
                    'qty'        => $qty,
                    'unit_price' => $qty > 0 ? round( $subtotal / $qty, 4 ) : 0,
                    'subtotal'   => $subtotal,
                    'total'      => (float) $item->get_total(),
                ];
            }

            $user_id      = $order->get_user_id();
            $adn_code     = $user_id ? get_user_meta( $user_id, '_adn_cli_codigo', true ) : '';

            $result[] = [
                'order_id'          => $order->get_id(),
                'order_number'      => $order->get_order_number(),
                'date'              => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : '',
                'status'            => $order->get_status(),
                'total'             => (float) $order->get_total(),
                'currency'          => $order->get_currency(),
                'customer_name'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'customer_first_name' => $order->get_billing_first_name(),
                'customer_last_name'  => $order->get_billing_last_name(),
                'customer_email'      => $order->get_billing_email(),
                'customer_phone'      => $order->get_billing_phone(),
                'customer_adn_code' => (string) $adn_code,
                'address'           => $order->get_billing_address_1(),
                'address2'          => $order->get_billing_address_2(),
                'city'              => $order->get_billing_city(),
                'note'              => $order->get_customer_note(),
                'items'             => $items,
            ];
        }

        return new WP_REST_Response( [
            'orders' => $result,
            'total'  => count( $result ),
        ], 200 );
    }

    // ─── Endpoint: Marcar pedidos como sincronizados en ADN ──────────────────

    public function handle_mark_orders_synced( WP_REST_Request $request ): WP_REST_Response {
        $body      = $request->get_json_params();
        $order_ids = array_map( 'intval', $body['order_ids'] ?? [] );
        if ( empty( $order_ids ) ) {
            return new WP_REST_Response( [ 'error' => 'Falta el campo order_ids (array)' ], 400 );
        }

        $done   = 0;
        $failed = 0;
        foreach ( $order_ids as $oid ) {
            $order = wc_get_order( $oid );
            if ( ! $order ) {
                $failed++;
                continue;
            }
            update_post_meta( $oid, '_adn_synced', current_time( 'mysql' ) );
            $order->add_order_note( __( 'Pedido sincronizado con ADN ✓', 'adn-productos' ) );
            $done++;
        }

        return new WP_REST_Response( [
            'marked' => $done,
            'failed' => $failed,
        ], 200 );
    }

    // ─── Endpoint: Guardar clave secreta ─────────────────────────────────────

    public function handle_set_key( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params() ?? [];
        $key  = sanitize_text_field( trim( $body['key'] ?? '' ) );
        if ( empty( $key ) ) {
            return new WP_REST_Response( [ 'error' => 'Falta el campo key' ], 400 );
        }
        update_option( 'adn_loro_key', $key );
        return new WP_REST_Response( [
            'saved'   => true,
            'message' => 'Clave guardada. Envíala en el header X-ADN-Key en cada petición.',
        ], 200 );
    }

    // ─── Endpoint: Ingestar clientes ADN como usuarios WooCommerce ─────────────────

    public function handle_ingest_customers( WP_REST_Request $request ): WP_REST_Response {
        $body      = $request->get_json_params();
        $customers = $body['customers'] ?? [];
        if ( empty( $customers ) || ! is_array( $customers ) ) {
            return new WP_REST_Response( [ 'error' => 'Falta el campo customers (array)' ], 400 );
        }

        $created = 0;
        $updated = 0;
        $errors  = 0;

        foreach ( $customers as $item ) {
            $codigo       = sanitize_text_field( trim( $item['codigo']        ?? '' ) );
            $nombre       = sanitize_text_field( trim( $item['nombre']        ?? '' ) );
            $email_raw    = sanitize_email( trim( $item['email']         ?? '' ) );
            $telefono     = sanitize_text_field( trim( $item['telefono']      ?? '' ) );
            $direccion    = sanitize_text_field( trim( $item['direccion']     ?? '' ) );
            $rif          = sanitize_text_field( trim( $item['rif']           ?? '' ) );
            $ciudad       = sanitize_text_field( trim( $item['ciudad']        ?? '' ) );
            $codigo_postal= sanitize_text_field( trim( $item['codigo_postal'] ?? '' ) );
            $clave_adn    = trim( $item['clave_adn'] ?? '' );
            // Nombre/apellido compuestos vienen separados desde ADN
            $first_name_adn = sanitize_text_field( trim( $item['primer_nombre'] ?? '' ) );
            $last_name_adn  = sanitize_text_field( trim( $item['apellido']      ?? '' ) );

            if ( empty( $codigo ) ) {
                $errors++;
                continue;
            }

            // RIF sin guiones: mayúsculas para username/password, minúsculas para email
            $rif_upper = strtoupper( str_replace( '-', '', $rif ) );
            $rif_upper = ! empty( $rif_upper ) ? $rif_upper : strtoupper( $codigo );
            $rif_clean = strtolower( $rif_upper ); // solo para email

            // Email: usar el real de ADN solo si no es un fallback @correo.com (ej: 0000001@correo.com)
            // Los emails @correo.com en ADN son generados, no reales — usamos rif@correo.com
            $email_is_real = ! empty( $email_raw ) &&
                             strtolower( substr( $email_raw, -11 ) ) !== '@correo.com';
            $email = $email_is_real ? $email_raw : ( $rif_clean . '@correo.com' );

            // Usar nombres separados de ADN; si vacíos, partir del nombre completo
            if ( ! empty( $first_name_adn ) || ! empty( $last_name_adn ) ) {
                $first_name = $first_name_adn;
                $last_name  = $last_name_adn;
            } else {
                $parts      = explode( ' ', $nombre, 2 );
                $first_name = $parts[0];
                $last_name  = $parts[1] ?? '';
            }

            // Buscar usuario existente por meta ADN o por email
            $existing_id = null;
            $by_meta = get_users( [
                'meta_key'   => '_adn_cli_codigo',
                'meta_value' => $codigo,
                'number'     => 1,
                'fields'     => 'ID',
            ] );
            if ( ! empty( $by_meta ) ) {
                $existing_id = (int) $by_meta[0];
            } elseif ( ! empty( $email_raw ) ) {
                $by_email = get_user_by( 'email', $email_raw );
                if ( $by_email ) { $existing_id = $by_email->ID; }
            }

            if ( $existing_id ) {
                // Actualizar datos del usuario existente
                $result = wp_update_user( [
                    'ID'           => $existing_id,
                    'first_name'   => $first_name,
                    'last_name'    => $last_name,
                    'user_email'   => $email,
                    'display_name' => ! empty( $rif ) ? $rif : $nombre,
                    'nickname'     => $codigo,
                ] );
                if ( is_wp_error( $result ) ) {
                    $errors++;
                    $this->adn_log( 'customers', 'ERROR update ' . $codigo . ': ' . $result->get_error_message() );
                    continue;
                }
                $user_id = $existing_id;
                $updated++;
            } else {
                // Crear usuario nuevo
                // Username = RIF sin guiones en MAYÚSCULAS (ej: C410413840)
                $username = $this->unique_username( $rif_upper );
                // Contraseña = RIF sin guiones en MAYÚSCULAS
                $password = ! empty( $rif_upper ) ? $rif_upper : strtoupper( $codigo );

                $result = wp_insert_user( [
                    'user_login'   => $username,
                    'user_email'   => $email,
                    'user_pass'    => $password,
                    'first_name'   => $first_name,
                    'last_name'    => $last_name,
                    'role'         => 'customer',
                    'display_name' => ! empty( $rif ) ? $rif : $nombre,
                    'nickname'     => $codigo,
                ] );
                if ( is_wp_error( $result ) ) {
                    $errors++;
                    $this->adn_log( 'customers', 'ERROR create ' . $codigo . ': ' . $result->get_error_message() );
                    continue;
                }
                $user_id = $result;
                $created++;
            }

            // Meta ADN y billing WooCommerce
            update_user_meta( $user_id, '_adn_cli_codigo',          $codigo );
            update_user_meta( $user_id, '_adn_cli_rif',             $rif );
            update_user_meta( $user_id, 'billing_first_name',       ! empty( $rif_upper ) ? $rif_upper : $first_name );
            update_user_meta( $user_id, 'billing_last_name',        $nombre );
            update_user_meta( $user_id, 'billing_company',          $nombre );
            update_user_meta( $user_id, 'billing_email',            $email );
            update_user_meta( $user_id, 'billing_phone',            $telefono );
            update_user_meta( $user_id, 'billing_address_1',        $direccion );
            update_user_meta( $user_id, 'billing_city',             $ciudad );
            update_user_meta( $user_id, 'billing_postcode',         $codigo_postal );

            $this->adn_log( 'customers', ( $existing_id ? 'UPDATE' : 'CREATE' ) . ' ' . $codigo . ' ' . $nombre );
        }

        update_option( 'adn_loro_last_sync', date( 'Y-m-d H:i:s' ) );

        return new WP_REST_Response( [
            'created' => $created,
            'updated' => $updated,
            'errors'  => $errors,
            'total'   => $created + $updated + $errors,
        ], 200 );
    }

    private function normalize_username( string $name ): string {
        $clean = remove_accents( $name );           // quitar tildes
        $clean = strtolower( $clean );              // minúsculas
        $clean = str_replace( ' ', '.', $clean );   // espacios → puntos
        $clean = preg_replace( '/[^a-z0-9.\_\-]/', '', $clean ); // solo chars válidos
        $clean = trim( $clean, '.-_' );
        return ! empty( $clean ) ? $clean : 'cliente';
    }

    private function unique_username( string $base ): string {
        $username = $base;
        $i = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $i;
            $i++;
        }
        return $username;
    }

    // ─── Endpoint: Estado de la sincronización ────────────────────────────────────────

    public function handle_status( WP_REST_Request $request ): WP_REST_Response {
        $pending_images = count( get_option( 'adn_loro_pending_images', [] ) );
        $pending_orders = count( get_option( 'adn_loro_order_queue',    [] ) );
        return new WP_REST_Response( [
            'plugin_version'  => ADN_PRODUCTOS_VERSION,
            'key_configured'  => ! empty( get_option( 'adn_loro_key', '' ) ),
            'last_sync'       => get_option( 'adn_loro_last_sync', 'nunca' ),
            'pending_images'  => $pending_images,
            'pending_orders'  => $pending_orders,
            'woocommerce'     => class_exists( 'WooCommerce' ) ? WC()->version : 'inactivo',
        ], 200 );
    }

    // ─── Hook: Encolar pedido al cambiar de estado ────────────────────────────

    public function enqueue_order_for_adn( int $order_id, string $old_status, string $new_status ): void {
        if ( get_post_meta( $order_id, '_adn_synced', true ) ) {
            return;
        }
        $queue = (array) get_option( 'adn_loro_order_queue', [] );
        if ( ! in_array( $order_id, $queue, true ) ) {
            $queue[] = $order_id;
            update_option( 'adn_loro_order_queue', $queue, false );
        }
    }
}

add_action( 'plugins_loaded', function () {
    new ADN_Productos_Plugin();
} );

/**
 * Widget: Filtro de Marcas ADN
 * Aparece en Apariencia → Widgets como "ADN - Filtro por Marcas".
 */
class ADN_Marcas_Filter_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'adn_marcas_filter',
            __( 'ADN - Filtro por Marcas', 'adn-productos' ),
            array( 'description' => __( 'Lista de marcas con checkboxes para filtrar productos ADN por AJAX.', 'adn-productos' ) )
        );
    }

    public function widget( $args, $instance ) {
        $title = apply_filters( 'widget_title', $instance['title'] ?? __( 'Marcas', 'adn-productos' ) );
        echo $args['before_widget'];
        if ( ! empty( $title ) ) {
            echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
        }
        echo do_shortcode( '[adn_filtro_marcas]' );
        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $title = $instance['title'] ?? __( 'Marcas', 'adn-productos' );
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Título:', 'adn-productos' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        return array( 'title' => sanitize_text_field( $new_instance['title'] ?? '' ) );
    }
}
