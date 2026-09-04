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

        // Redirigir al home tras login (clientes); admins/editores van al dashboard
        add_filter( 'login_redirect', array( $this, 'redirect_after_login' ), 10, 3 );

        // Exigir login para carrito y checkout
        add_action( 'template_redirect', array( $this, 'require_login_for_checkout' ) );

        // Sincronización ADN ← → WP
        add_action( 'rest_api_init',                       array( $this, 'register_sync_endpoints' ) );
        add_action( 'woocommerce_order_status_changed',    array( $this, 'enqueue_order_for_adn' ), 10, 4 );

        // Tabla wp_adn_orders (crear si no existe)
        add_action( 'init', array( $this, 'maybe_create_adn_orders_table' ) );

        // My Account: pestaña "Mis Pedidos ADN"
        add_action( 'init',                                           array( $this, 'adn_orders_add_endpoint' ) );
        add_filter( 'woocommerce_account_menu_items',                 array( $this, 'adn_orders_menu_item' ) );
        add_action( 'woocommerce_account_pedidos-adn_endpoint',       array( $this, 'adn_orders_endpoint_content' ) );

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

        // ── Páginas de archivo de marca: reemplazar loop WC con grid ADN ──────
        add_action( 'wp', array( $this, 'override_brand_archive_loop' ) );

        // ── WP Menu Cart: inyectar items en el slideout ────────────────────────
        add_action( 'wp_footer', array( $this, 'wpmenucart_inject_items' ) );

        // ── Recetas ────────────────────────────────────────────────────────────
        add_action( 'init',             array( $this, 'register_receta_post_type' ) );
        add_action( 'add_meta_boxes',   array( $this, 'receta_meta_boxes' ) );
        add_action( 'save_post_receta', array( $this, 'receta_save_meta' ), 10, 2 );
        add_shortcode( 'adn_recetas',   array( $this, 'render_recetas_shortcode' ) );
        add_filter( 'the_content',      array( $this, 'receta_single_content' ) );
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

    public function require_login_for_checkout() {
        if ( is_user_logged_in() ) {
            return;
        }
        if ( is_checkout() || is_cart() ) {
            $checkout_url = wc_get_checkout_url();
            $login_url    = wc_get_page_permalink( 'myaccount' );
            wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( $checkout_url ), $login_url ) );
            exit;
        }
    }

    public function redirect_after_login( $redirect_to, $requested_redirect_to, $user ) {
        if ( is_wp_error( $user ) ) {
            return $redirect_to;
        }
        $admin_roles = array( 'administrator', 'editor', 'shop_manager' );
        foreach ( $admin_roles as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) {
                return $redirect_to; // dashboard por defecto
            }
        }
        // Si venía del checkout/carrito, volver ahí
        if ( ! empty( $requested_redirect_to ) ) {
            $checkout_url = wc_get_checkout_url();
            $cart_url     = wc_get_cart_url();
            if ( strpos( $requested_redirect_to, $checkout_url ) !== false ||
                 strpos( $requested_redirect_to, $cart_url ) !== false ) {
                return $requested_redirect_to;
            }
        }
        return home_url( '/' );
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
            $price_range = $this->get_global_price_range();
            wp_localize_script( 'adn-productos-js', 'adnAjax', array(
                'url'      => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'adn_filter_nonce' ),
                'priceMin' => $price_range['min'],
                'priceMax' => $price_range['max'],
            ) );
        }

        // Encolar scripts de WooCommerce para el botón añadir al carrito por AJAX
        if ( $has_productos && class_exists( 'WooCommerce' ) ) {
            wp_enqueue_script( 'wc-add-to-cart' );
        }

        // Asegurar que todos los filtros del sidebar (BeRocket y marcas) estén siempre visibles
        wp_add_inline_style( 'adn-productos-style', '
            .bapf_sfilter .bapf_body,
            .adn-marcas-filter-list { display: block !important; }
            .bapf_toggle_btn { display: none !important; }

            /* Mantener todas las opciones de categorías y marcas visibles aunque BeRocket las oculte por 0 resultados */
            .bapf_sfilter ul li,
            .bapf_sfilter ul li.bapf_hide,
            .widget_berocket_aapf_widget ul li,
            .widget_berocket_aapf_widget ul li.berocket_hide,
            .widget_berocket_aapf_widget ul li.bapf_hide { display: list-item !important; }
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

        $current_search  = isset( $_GET['adn_s'] ) ? sanitize_text_field( wp_unslash( $_GET['adn_s'] ) ) :
                           ( isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '' );
        $current_orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
        $brand_tax       = $this->detect_brand_taxonomy();

        ob_start();
        ?>
        <div class="adn-productos-wrapper woocommerce" data-columns="<?php echo esc_attr( $columns ); ?>" data-per-page="<?php echo esc_attr( $args['posts_per_page'] ); ?>">

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
            $thumb_url = wc_placeholder_img_src( 'woocommerce_thumbnail' );
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
     * En páginas de archivo de marca, reemplaza el loop nativo de WooCommerce
     * con el grid de tarjetas ADN (mismo formato que el shortcode [adn_productos]).
     */
    public function override_brand_archive_loop() {
        $brand_taxes = array( 'product_brand', 'pwb-brand', 'berocket_brand', 'brand' );
        $is_brand    = false;
        foreach ( $brand_taxes as $tax ) {
            if ( taxonomy_exists( $tax ) && is_tax( $tax ) ) {
                $is_brand = true;
                break;
            }
        }
        if ( ! $is_brand ) { return; }

        // Eliminar el sidebar / widgets de la plantilla del tema en páginas de marca
        add_filter( 'is_active_sidebar', function( $active, $index ) {
            return false;
        }, 99, 2 );

        // Capturar TODO el output del loop nativo de WooCommerce
        add_action( 'woocommerce_before_shop_loop', function() {
            ob_start();
        }, 1 );

        // Descartar el output de WC y emitir nuestro grid ADN
        add_action( 'woocommerce_after_shop_loop', function() {
            ob_end_clean();
            global $wp_query;
            if ( ! $wp_query || ! $wp_query->have_posts() ) { return; }

            $wp_query->rewind_posts();

            echo '<div class="adn-productos-wrapper woocommerce">';
            echo '<ul class="products adn-productos-grid columns-3" style="--adn-columns:3">';
            while ( $wp_query->have_posts() ) {
                $wp_query->the_post();
                $product = wc_get_product( get_the_ID() );
                if ( $product ) {
                    echo $this->render_product_card( $product ); // phpcs:ignore
                }
            }
            echo '</ul>';
            echo '</div>';

            $wp_query->rewind_posts();
            wp_reset_postdata();
        }, PHP_INT_MAX );
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
        } elseif ( ! empty( $_POST['adn_s'] ) ) {
            $args['s'] = sanitize_text_field( wp_unslash( $_POST['adn_s'] ) );
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

        // Filtro de precio: prioridad POST directo → formato BeRocket raw_filters → location_search
        $price_min_post = isset( $_POST['min_price'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['min_price'] ) ) ) : '';
        $price_max_post = isset( $_POST['max_price'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['max_price'] ) ) ) : '';

        if ( '' !== $price_min_post && '' !== $price_max_post ) {
            // Precio enviado explícitamente por el JS (vía adnBerocketState o URL)
            $args['meta_query'][] = array(
                'key'     => '_price',
                'value'   => array( floatval( $price_min_post ), floatval( $price_max_post ) ),
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            );
        } else {
            // Fallback: parsear formato BeRocket price[min_max] desde raw_filters o location_search
            $br_filters_str = '';
            if ( ! empty( $_POST['raw_filters'] ) ) {
                $br_filters_str = sanitize_text_field( wp_unslash( $_POST['raw_filters'] ) );
            }
            if ( empty( $br_filters_str ) && ! empty( $_POST['location_search'] ) ) {
                $ls = ltrim( sanitize_text_field( wp_unslash( $_POST['location_search'] ) ), '?' );
                parse_str( $ls, $ls_p );
                $br_filters_str = isset( $ls_p['filters'] ) ? sanitize_text_field( $ls_p['filters'] ) : '';
            }
            if ( $br_filters_str && preg_match( '/price\[(\d+(?:\.\d+)?)_(\d+(?:\.\d+)?)\]/', $br_filters_str, $pm ) ) {
                $args['meta_query'][] = array(
                    'key'     => '_price',
                    'value'   => array( floatval( $pm[1] ), floatval( $pm[2] ) ),
                    'compare' => 'BETWEEN',
                    'type'    => 'NUMERIC',
                );
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
                'meta_query'      => isset( $args['meta_query'] ) ? $args['meta_query'] : array(),
                'min_price_in'    => $price_min_post,
                'max_price_in'    => $price_max_post,
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
     * Retorna el rango global de precios { min, max } de todos los productos publicados.
     */
    private function get_global_price_range() {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT
                MIN( CAST( pm.meta_value AS DECIMAL(10,2) ) ) AS min_p,
                MAX( CAST( pm.meta_value AS DECIMAL(10,2) ) ) AS max_p
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_price'
               AND p.post_type = 'product'
               AND p.post_status = 'publish'
               AND pm.meta_value != ''
               AND CAST( pm.meta_value AS DECIMAL(10,2) ) > 0"
        );
        return array(
            'min' => $row ? (int) floor( (float) $row->min_p ) : 0,
            'max' => $row ? (int) ceil(  (float) $row->max_p ) : 10000,
        );
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
            $paged       = isset( $_GET['adn_paged'] ) ? max( 1, intval( $_GET['adn_paged'] ) ) : 1;
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

        // Búsqueda por texto (?adn_s=zapatilla — no activa el motor de búsqueda de WP)
        if ( ! empty( $_GET['adn_s'] ) ) {
            $args['s'] = sanitize_text_field( wp_unslash( $_GET['adn_s'] ) );
        } elseif ( ! empty( $_GET['s'] ) ) {
            $args['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
        }

        // Rango de precio formato BeRocket: ?filters=price[4_113]
        if ( ! empty( $_GET['filters'] ) ) {
            $filters_raw = sanitize_text_field( wp_unslash( $_GET['filters'] ) );
            if ( preg_match( '/price\[(\d+(?:\.\d+)?)_(\d+(?:\.\d+)?)\]/', $filters_raw, $pm ) ) {
                $br_min = floatval( $pm[1] );
                $br_max = floatval( $pm[2] );
                if ( null === $min_price && null === $max_price ) {
                    $args['meta_query'][] = array(
                        'key'     => '_price',
                        'value'   => array( $br_min, $br_max ),
                        'compare' => 'BETWEEN',
                        'type'    => 'NUMERIC',
                    );
                }
            }
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

        register_rest_route( $ns, '/delete-absent-products', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_delete_absent_products' ],
            'permission_callback' => $auth,
        ] );

        register_rest_route( $ns, '/ingest-adn-orders', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_ingest_adn_orders' ],
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

        $created       = 0;
        $updated       = 0;
        $errors        = 0;
        $error_details = [];

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

            // Buscar usuario existente por meta ADN o por email (real o fallback)
            $existing_id = null;
            $by_meta = get_users( [
                'meta_key'   => '_adn_cli_codigo',
                'meta_value' => $codigo,
                'number'     => 1,
                'fields'     => 'ID',
            ] );
            if ( ! empty( $by_meta ) ) {
                $existing_id = (int) $by_meta[0];
            } else {
                // Buscar por email real de ADN
                if ( ! empty( $email_raw ) ) {
                    $by_email = get_user_by( 'email', $email_raw );
                    if ( $by_email ) { $existing_id = $by_email->ID; }
                }
                // Si no encontró, buscar por el email construido (rif@correo.com) que pudo haberse guardado antes
                if ( ! $existing_id && $email !== $email_raw ) {
                    $by_email2 = get_user_by( 'email', $email );
                    if ( $by_email2 ) { $existing_id = $by_email2->ID; }
                }
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
                    $msg = 'ERROR update ' . $codigo . ': ' . $result->get_error_message();
                    $this->adn_log( 'customers', $msg );
                    $error_details[] = $msg;
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
                    $msg = 'ERROR create ' . $codigo . ': ' . $result->get_error_message();
                    $this->adn_log( 'customers', $msg );
                    $error_details[] = $msg;
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
            'created'       => $created,
            'updated'       => $updated,
            'errors'        => $errors,
            'total'         => $created + $updated + $errors,
            'error_details' => $error_details,
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

    // ─── Tabla wp_adn_orders ──────────────────────────────────────────────────

    public function maybe_create_adn_orders_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'adn_orders';
        $version = (int) get_option( 'adn_orders_table_version', 0 );
        if ( $version >= 3 ) {
            return;
        }
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `adn_tipo`       VARCHAR(10)     NOT NULL,
            `adn_numero`     VARCHAR(20)     NOT NULL,
            `adn_rif`        VARCHAR(30)     NOT NULL DEFAULT '',
            `adn_email`      VARCHAR(100)    NOT NULL DEFAULT '',
            `cliente_codigo` VARCHAR(20)     NOT NULL DEFAULT '',
            `fecha`          DATE            NOT NULL DEFAULT '0000-00-00',
            `recepcion`      DATE                     DEFAULT NULL,
            `estado`         VARCHAR(30)     NOT NULL DEFAULT '',
            `total_bs`       DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
            `total_usd`      DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
            `vendedor`       VARCHAR(100)    NOT NULL DEFAULT '',
            `dias_ven`       INT             NOT NULL DEFAULT 0,
            `doc_origin`     VARCHAR(60)     NOT NULL DEFAULT '',
            `items_json`     LONGTEXT        NOT NULL,
            `synced_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `adn_doc` (`adn_tipo`, `adn_numero`),
            KEY `idx_rif`   (`adn_rif`),
            KEY `idx_fecha` (`fecha`)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        // Migraciones incrementales para instalaciones previas
        if ( $version >= 1 ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN IF NOT EXISTS `vendedor`    VARCHAR(100) NOT NULL DEFAULT ''" );
        }
        if ( $version >= 2 ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN IF NOT EXISTS `recepcion`   DATE DEFAULT NULL" );
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN IF NOT EXISTS `dias_ven`    INT NOT NULL DEFAULT 0" );
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN IF NOT EXISTS `doc_origin`  VARCHAR(60) NOT NULL DEFAULT ''" );
        }
        update_option( 'adn_orders_table_version', 3 );
    }

    // ─── Endpoint: Archivar productos ausentes en ADN ─────────────────────────

    public function handle_delete_absent_products( WP_REST_Request $request ): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce no activo' ], 500 );
        }

        $body        = $request->get_json_params();
        $active_skus = $body['active_skus'] ?? [];
        if ( ! is_array( $active_skus ) || empty( $active_skus ) ) {
            return new WP_REST_Response( [ 'error' => 'Falta active_skus (array)' ], 400 );
        }

        $active_skus = array_map( 'sanitize_text_field', $active_skus );

        // Obtener todos los productos publicados en WooCommerce
        $wp_products = wc_get_products( [
            'status' => 'publish',
            'limit'  => -1,
            'return' => 'ids',
        ] );

        $drafted = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ( $wp_products as $product_id ) {
            $sku = get_post_meta( $product_id, '_sku', true );
            if ( empty( $sku ) ) {
                $skipped++;
                continue;
            }
            if ( in_array( $sku, $active_skus, true ) ) {
                $skipped++;
                continue;
            }
            // Producto no está en ADN → archivar como borrador
            $result = wp_update_post( [
                'ID'          => $product_id,
                'post_status' => 'draft',
            ] );
            if ( is_wp_error( $result ) || ! $result ) {
                $errors++;
            } else {
                $drafted++;
                $this->adn_log( 'eliminados', "Archivado SKU={$sku} (ID={$product_id})" );
            }
        }

        return new WP_REST_Response( [
            'drafted' => $drafted,
            'skipped' => $skipped,
            'errors'  => $errors,
        ], 200 );
    }

    // ─── Endpoint: Ingestar pedidos/facturas ADN ──────────────────────────────

    public function handle_ingest_adn_orders( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $table  = $wpdb->prefix . 'adn_orders';
        $body   = $request->get_json_params();
        $orders = $body['orders'] ?? [];

        if ( ! is_array( $orders ) || empty( $orders ) ) {
            return new WP_REST_Response( [ 'error' => 'Falta orders (array)' ], 400 );
        }

        $synced = 0;
        $errors = 0;

        foreach ( $orders as $o ) {
            $tipo   = sanitize_text_field( $o['tipo']           ?? '' );
            $numero = sanitize_text_field( $o['numero']         ?? '' );
            $rif    = sanitize_text_field( $o['rif']            ?? '' );
            $email  = sanitize_email(      $o['email']          ?? '' );
            $cod    = sanitize_text_field( $o['cliente_codigo'] ?? '' );
            $fecha      = sanitize_text_field( $o['fecha']          ?? '' );
            $recepcion  = sanitize_text_field( $o['recepcion']      ?? '' );
            $estado     = sanitize_text_field( $o['estado']         ?? '' );
            $tbs        = (float) ( $o['total_bs']  ?? 0 );
            $tusd       = (float) ( $o['total_usd'] ?? 0 );
            $dias_ven   = (int)   ( $o['dias_ven']  ?? 0 );
            $doc_origin = sanitize_text_field( $o['doc_origin']     ?? '' );
            $items      = $o['items'] ?? [];

            if ( empty( $tipo ) || empty( $numero ) || empty( $rif ) ) {
                $errors++;
                continue;
            }

            $items_json = wp_json_encode( $items );
            $vendedor   = sanitize_text_field( $o['vendedor'] ?? '' );
            $fecha_sql  = ( $fecha && $fecha !== '0000-00-00' ) ? $fecha : '0000-00-00';
            $rec_sql    = ( $recepcion && $recepcion !== '0000-00-00' ) ? $recepcion : null;

            $result = $wpdb->query( $wpdb->prepare(
                "INSERT INTO `{$table}`
                    (adn_tipo, adn_numero, adn_rif, adn_email, cliente_codigo, fecha, recepcion, estado, total_bs, total_usd, vendedor, dias_ven, doc_origin, items_json, synced_at)
                 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %f, %f, %s, %d, %s, %s, NOW())
                 ON DUPLICATE KEY UPDATE
                    adn_rif        = VALUES(adn_rif),
                    adn_email      = VALUES(adn_email),
                    fecha          = VALUES(fecha),
                    recepcion      = VALUES(recepcion),
                    estado         = VALUES(estado),
                    total_bs       = VALUES(total_bs),
                    total_usd      = VALUES(total_usd),
                    vendedor       = VALUES(vendedor),
                    dias_ven       = VALUES(dias_ven),
                    doc_origin     = VALUES(doc_origin),
                    items_json     = VALUES(items_json),
                    synced_at      = NOW()",
                $tipo, $numero, $rif, $email, $cod, $fecha_sql, $rec_sql, $estado, $tbs, $tusd, $vendedor, $dias_ven, $doc_origin, $items_json
            ) );

            if ( false === $result ) {
                $errors++;
            } else {
                $synced++;
            }
        }

        return new WP_REST_Response( [ 'synced' => $synced, 'errors' => $errors ], 200 );
    }

    // ─── My Account: pestaña "Mis Pedidos ADN" ────────────────────────────────

    public function adn_orders_add_endpoint(): void {
        add_rewrite_endpoint( 'pedidos-adn', EP_ROOT | EP_PAGES );
    }

    public function adn_orders_menu_item( array $items ): array {
        $new = [];
        foreach ( $items as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'orders' === $key ) {
                $new['pedidos-adn'] = 'Mis Pedidos ADN';
            }
        }
        return $new;
    }

    public function adn_orders_endpoint_content(): void {
        global $wpdb;

        if ( ! is_user_logged_in() ) {
            echo '<p>' . esc_html__( 'Debes iniciar sesión para ver tus pedidos.', 'adn-productos' ) . '</p>';
            return;
        }

        $user_id = get_current_user_id();
        $rif     = get_user_meta( $user_id, '_adn_cli_rif', true );

        if ( empty( $rif ) ) {
            echo '<p>No tienes un RIF registrado en el sistema ADN. Contacta al administrador.</p>';
            return;
        }

        $table  = $wpdb->prefix . 'adn_orders';
        $all    = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE adn_rif = %s ORDER BY fecha DESC LIMIT 200",
            $rif
        ) );

        // Separar en PEDIDOS y DOCUMENTOS
        $pedidos     = [];
        $documentos  = [];
        foreach ( $all as $row ) {
            if ( $row->adn_tipo === 'PED' ) {
                $pedidos[] = $row;
            } else {
                $documentos[] = $row;
            }
        }

        $tipo_labels = [
            'PEDW' => 'Pedido Web',
            'PED'  => 'Pedido',
            'DPED' => 'Devolución Pedido',
            'PEDV' => 'Pedido de Venta',
            'FAC'  => 'Factura',
            'FACO' => 'Factura (Copia)',
        ];

        ?>
        <h2>Mis Pedidos y Documentos ADN</h2>
        <p style="color:#666;font-size:.9em;">RIF registrado: <strong><?php echo esc_html( $rif ); ?></strong></p>

        <style>
        .adn-orders-table { width:100%; border-collapse:collapse; font-size:.9em; margin-bottom:2em; }
        .adn-orders-table th { background:#f0f4ff; padding:9px 10px; text-align:left; border-bottom:2px solid #c5d0f5; white-space:nowrap; }
        .adn-orders-table td { padding:8px 10px; border-bottom:1px solid #eee; vertical-align:top; }
        .adn-orders-table tr:hover td { background:#f9fbff; }
        .adn-tipo-badge { display:inline-block; padding:2px 8px; border-radius:4px; font-size:.78em; font-weight:700;
                          background:#e8f0fe; color:#1a56db; letter-spacing:.03em; }
        .adn-tipo-badge.pedv { background:#fff3cd; color:#856404; }
        .adn-tipo-badge.fac  { background:#d1e7dd; color:#0a5233; }
        .adn-tipo-badge.dped { background:#fde8e8; color:#9b1c1c; }
        .adn-orders-items { font-size:.84em; color:#555; margin:5px 0 0; padding-left:1em; }
        .adn-orders-items li { margin:3px 0; }
        .adn-neto  { font-weight:700; white-space:nowrap; color:#111; }
        .adn-estado-pen  { color:#856404; font-weight:600; }
        .adn-estado-act  { color:#0a5233; font-weight:600; }
        .adn-estado-pag  { color:#0a5233; font-weight:600; }
        .adn-estado-anu  { color:#9b1c1c; font-weight:600; }
        .adn-section-title { margin:1.5em 0 .5em; font-size:1.1em; border-bottom:2px solid #0073aa;
                             padding-bottom:6px; color:#0073aa; }
        details summary { cursor:pointer; color:#0073aa; }
        details summary:hover { text-decoration:underline; }
        .adn-vacio { color:#888; font-style:italic; }
        .adn-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; margin-bottom:2em; }
        .adn-table-wrap .adn-orders-table { margin-bottom:0; min-width:900px; }
        </style>

        <?php
        // ─── Helper: formatear fecha segura ─────────────────────────────────
        $fmt_date = function ( ?string $val ): string {
            if ( ! $val || str_starts_with( $val, '0000-' ) ) { return '—'; }
            $ts = strtotime( $val );
            return ( $ts && $ts > 0 ) ? date_i18n( 'd/m/Y', $ts ) : '—';
        };

        // ─── Tabla reutilizable ──────────────────────────────────────────────
        $render_table = function ( array $rows ) use ( $tipo_labels, $fmt_date ) {
            if ( empty( $rows ) ) {
                echo '<p class="adn-vacio">Sin registros.</p>';
                return;
            }
            echo '<div class="adn-table-wrap"><table class="adn-orders-table"><thead><tr>';
            echo '<th>Tipo</th>';
            echo '<th>Número</th>';
            echo '<th>Fecha</th>';
            echo '<th>Recepción</th>';
            echo '<th>Neto Bs</th>';
            echo '<th>Pagado</th>';
            echo '<th>Saldo</th>';
            echo '<th>Estado</th>';
            echo '<th>Artículos</th>';
            echo '</tr></thead><tbody>';

            foreach ( $rows as $order ) :
                $items      = json_decode( $order->items_json, true ) ?: [];
                $tipo_label = $tipo_labels[ $order->adn_tipo ] ?? $order->adn_tipo;
                $fecha_fmt  = $fmt_date( $order->fecha    ?? '' );
                $rec_fmt    = $fmt_date( $order->recepcion ?? '' );
                $neto       = (float) ( $order->total_bs ?? 0 );
                $pagado     = 0.00;
                $saldo      = $neto - $pagado;
                $estado     = strtoupper( $order->estado ?: '—' );
                $estado_cls = in_array( $estado, [ 'ACTIVO', 'ACT', 'A' ], true ) ? 'adn-estado-act'
                            : ( 'PAGADO' === $estado ? 'adn-estado-pag'
                            : ( 'ANULADO' === $estado ? 'adn-estado-anu'
                            : ( in_array( $estado, [ 'PEN', 'PENDIENTE', 'P' ], true ) ? 'adn-estado-pen' : '' ) ) );
                $tipo_cls   = ( 'PEDV' === $order->adn_tipo ) ? 'pedv'
                            : ( 'DPED' === $order->adn_tipo ? 'dped'
                            : ( in_array( $order->adn_tipo, [ 'FAC', 'FACO' ], true ) ? 'fac' : '' ) );
                echo '<tr>';
                echo '<td><span class="adn-tipo-badge ' . esc_attr( $tipo_cls ) . '">' . esc_html( $tipo_label ) . '</span></td>';
                echo '<td><strong>' . esc_html( $order->adn_numero ) . '</strong></td>';
                echo '<td>' . esc_html( $fecha_fmt ) . '</td>';
                echo '<td>' . esc_html( $rec_fmt ) . '</td>';
                echo '<td class="adn-neto">' . number_format( $neto,   2, ',', '.' ) . ' Bs</td>';
                echo '<td class="adn-neto">' . number_format( $pagado, 2, ',', '.' ) . ' Bs</td>';
                echo '<td class="adn-neto">' . number_format( $saldo,  2, ',', '.' ) . ' Bs</td>';
                echo '<td class="' . esc_attr( $estado_cls ) . '">' . esc_html( $estado ) . '</td>';
                echo '<td>';
                if ( ! empty( $items ) ) {
                    echo '<details><summary>' . count( $items ) . ' ítem(s)</summary><ul class="adn-orders-items">';
                    foreach ( $items as $item ) {
                        $desc = trim( $item['descripcion'] ?? '' ) ?: ( $item['sku'] ?? '—' );
                        $qty  = number_format( (float) ( $item['cantidad'] ?? 0 ), 0, ',', '.' );
                        $pr   = number_format( (float) ( $item['precio']   ?? 0 ), 2, ',', '.' );
                        echo '<li>' . esc_html( $desc ) . ' — ' . esc_html( $qty ) . ' x Bs ' . esc_html( $pr ) . '</li>';
                    }
                    echo '</ul></details>';
                } else {
                    echo '—';
                }
                echo '</td></tr>';
            endforeach;

            echo '</tbody></table></div>';
        };
        // ─────────────────────────────────────────────────────────────────────
        ?>

        <h3 class="adn-section-title">Pedidos (<?php echo count( $pedidos ); ?>)</h3>
        <?php $render_table( $pedidos ); ?>

        <h3 class="adn-section-title">Documentos de Venta (<?php echo count( $documentos ); ?>)</h3>
        <?php $render_table( $documentos ); ?>

        <?php
    }

    // ─── WP Menu Cart: mini cart items ───────────────────────────────────────────────

    public function wpmenucart_inject_items(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        ?>
        <div id="adn-wc-mini-cart-source" style="display:none!important;visibility:hidden!important;position:absolute;left:-9999px">
            <div class="widget_shopping_cart_content">
                <?php woocommerce_mini_cart(); ?>
            </div>
        </div>
        <?php
    }

    // ─── Recetas: CPT ──────────────────────────────────────────────────────────────────

    /**
     * Inyecta los campos de la receta en la página individual (single receta).
     * Layout: hero 2-col (video | info+meta) + panel 2-col (ingredientes | preparación).
     */
    public function receta_single_content( string $content ): string {
        if ( ! is_singular( 'receta' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        static $rendering = false;
        if ( $rendering ) { return $content; }
        $rendering = true;

        $post_id      = get_the_ID();
        $tiempo       = get_post_meta( $post_id, '_receta_tiempo',        true );
        $porciones    = get_post_meta( $post_id, '_receta_porciones',     true );
        $dificultad   = get_post_meta( $post_id, '_receta_dificultad',    true );
        $ingredientes = get_post_meta( $post_id, '_receta_ingredientes',  true );
        $preparacion  = get_post_meta( $post_id, '_receta_preparacion',   true );
        $youtube      = get_post_meta( $post_id, '_receta_youtube',       true );
        $excerpt      = get_post_field( 'post_excerpt', $post_id );

        // Ingredientes: líneas que terminan en ":" son encabezados de grupo
        $lineas_ingr = $ingredientes
            ? array_values( array_filter( array_map( 'trim', explode( "\n", $ingredientes ) ) ) )
            : [];
        $pasos = $preparacion
            ? array_values( array_filter( array_map( 'trim', explode( "\n", $preparacion ) ) ) )
            : [];

        $yt_id = '';
        if ( $youtube && preg_match( '/(?:v=|youtu\.be\/)([\w-]{11})/', $youtube, $m ) ) {
            $yt_id = $m[1];
        }
        $thumb = get_the_post_thumbnail_url( $post_id, 'large' );

        $terminos   = get_the_terms( $post_id, 'categoria_receta' );
        $cats_list  = ( $terminos && ! is_wp_error( $terminos ) ) ? $terminos : [];

        $author_id   = get_post_field( 'post_author', $post_id );
        $author_name = get_the_author_meta( 'display_name', $author_id );
        $author_url  = get_avatar_url( $author_id, [ 'size' => 36 ] );
        $pub_date    = get_the_date( 'd \d\e F, Y', $post_id );

        ob_start();
        ?>
        <style>
        /* ── Reset columna de contenido ────────────────────────── */
        @media(min-width:960px) {
            .neve-main > .single-post-container .nv-single-post-wrap.col,
            .neve-main > .container .col {
                max-width: 860px !important;
            }
        }
        /* Ocultar título duplicado del tema */
        .single-receta .entry-header .entry-title,
        .single-receta h1.title { display:none !important; }

        /* ── Wrapper ────────────────────────────────────────────── */
        .rfd-wrap {
            font-family: inherit; color: #222;
            max-width: 860px; margin: 0 auto 3rem;
        }

        /* ── Título ─────────────────────────────────────────────── */
        .rfd-title {
            font-size: 2.4rem; font-weight: 900; line-height: 1.15;
            margin: 0 0 .75rem; color: #111;
        }

        /* ── Meta bar (autor / fecha / cats) ────────────────────── */
        .rfd-meta {
            display: flex; align-items: center; flex-wrap: wrap;
            gap: .5rem 1.2rem; font-size: .85rem; color: #777;
            margin-bottom: 1rem;
        }
        .rfd-meta-author {
            display: flex; align-items: center; gap: .45rem; color: #444;
        }
        .rfd-meta-author img {
            width: 30px; height: 30px; border-radius: 50%;
            object-fit: cover; border: 2px solid #eee;
        }
        .rfd-meta-author span { font-weight: 600; }
        .rfd-meta-sep { color: #ddd; }
        .rfd-meta-cat {
            background: #f0f0f0; padding: 2px 10px; border-radius: 20px;
            font-size: .78rem; color: #555; text-decoration: none;
        }
        .rfd-meta-cat:hover { background: #e84248; color: #fff; }

        /* ── Excerpt ─────────────────────────────────────────────── */
        .rfd-excerpt {
            font-size: .97rem; color: #555; line-height: 1.7;
            margin: 0 0 1.6rem; max-width: 720px;
        }

        /* ── Hero imagen / video ────────────────────────────────── */
        .rfd-hero {
            position: relative; border-radius: 12px;
            overflow: hidden; background: #1a1a2e;
            aspect-ratio: 16/9; margin-bottom: 1.6rem;
        }
        .rfd-hero img {
            width: 100%; height: 100%; object-fit: cover; display: block;
        }
        .rfd-hero iframe {
            position: absolute; inset: 0;
            width: 100%; height: 100%; border: 0;
        }
        .rfd-hero-play {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; background: rgba(0,0,0,.18);
            transition: background .2s;
        }
        .rfd-hero-play:hover { background: rgba(0,0,0,.32); }
        .rfd-hero-play svg { filter: drop-shadow(0 2px 8px rgba(0,0,0,.4)); }
        .rfd-hero-no-img {
            width: 100%; height: 100%; display: flex;
            align-items: center; justify-content: center;
            font-size: 5rem; color: #555;
        }

        /* ── Barra de stats ──────────────────────────────────────── */
        .rfd-stats {
            display: flex; flex-wrap: wrap; gap: 0;
            border-top: 1px solid #eee; border-bottom: 1px solid #eee;
            margin-bottom: 2.4rem;
        }
        .rfd-stat {
            display: flex; flex-direction: column; align-items: center;
            padding: .85rem 1.6rem; gap: 2px; flex: 1; min-width: 100px;
            border-right: 1px solid #eee;
        }
        .rfd-stat:last-child { border-right: none; }
        .rfd-stat-label {
            font-size: .68rem; text-transform: uppercase;
            letter-spacing: .1em; color: #aaa; font-weight: 600;
        }
        .rfd-stat-value {
            font-size: .95rem; font-weight: 800; color: #111;
            display: flex; align-items: center; gap: 5px;
        }
        .rfd-stat-value svg { color: #888; flex-shrink: 0; }

        /* ── Sección 2 columnas: Ingredientes | Instrucciones ───── */
        .rfd-body {
            display: grid;
            grid-template-columns: 1fr 1.7fr;
            gap: 3rem;
            align-items: start;
        }
        @media(max-width:640px) {
            .rfd-body { grid-template-columns: 1fr; gap: 2rem; }
        }

        /* ── Ingredientes ────────────────────────────────────────── */
        .rfd-section-title {
            font-size: 1.4rem; font-weight: 800; color: #111;
            margin: 0 0 1.1rem; padding-bottom: .5rem;
            border-bottom: 2px solid #111;
        }
        .rfd-ingr-group-title {
            font-size: .82rem; text-transform: uppercase;
            letter-spacing: .08em; color: #777; font-weight: 700;
            margin: 1.1rem 0 .4rem;
        }
        .rfd-ingr-list {
            list-style: none; padding: 0; margin: 0;
        }
        .rfd-ingr-item {
            display: flex; align-items: flex-start; gap: .7rem;
            padding: .45rem 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: .93rem; color: #333; line-height: 1.4;
            cursor: pointer; user-select: none;
            transition: color .15s;
        }
        .rfd-ingr-item:last-child { border-bottom: none; }
        .rfd-ingr-item input[type=checkbox] { display: none; }
        .rfd-ingr-circle {
            flex-shrink: 0; width: 18px; height: 18px;
            border: 2px solid #ccc; border-radius: 50%;
            margin-top: 2px; transition: background .2s, border-color .2s;
            display: flex; align-items: center; justify-content: center;
        }
        .rfd-ingr-item.checked .rfd-ingr-circle {
            background: #e84248; border-color: #e84248;
        }
        .rfd-ingr-item.checked .rfd-ingr-circle::after {
            content: '';
            width: 7px; height: 7px; border-radius: 50%; background: #fff;
        }
        .rfd-ingr-item.checked .rfd-ingr-text {
            text-decoration: line-through; color: #bbb;
        }
        .rfd-ingr-text { flex: 1; }

        /* ── Instrucciones ───────────────────────────────────────── */
        .rfd-steps-list {
            list-style: none; padding: 0; margin: 0; counter-reset: rfd-step;
        }
        .rfd-step {
            counter-increment: rfd-step;
            display: flex; gap: 1rem;
            padding: .9rem 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: .93rem; color: #444; line-height: 1.65;
            align-items: flex-start;
        }
        .rfd-step:last-child { border-bottom: none; }
        .rfd-step-num {
            flex-shrink: 0; width: 28px; height: 28px;
            background: #e84248; color: #fff;
            border-radius: 50%; display: flex;
            align-items: center; justify-content: center;
            font-size: .8rem; font-weight: 800;
            margin-top: 2px;
        }
        .rfd-step-num::before { content: counter(rfd-step); }

        @media(max-width:480px) {
            .rfd-title { font-size: 1.7rem; }
            .rfd-stat  { padding: .6rem .8rem; }
        }
        </style>

        <div class="rfd-wrap">

            <!-- Título -->
            <h1 class="rfd-title"><?php echo esc_html( get_the_title() ); ?></h1>

            <!-- Meta bar -->
            <div class="rfd-meta">
                <span class="rfd-meta-author">
                    <?php if ( $author_url ) : ?>
                        <img src="<?php echo esc_url( $author_url ); ?>"
                             alt="<?php echo esc_attr( $author_name ); ?>">
                    <?php endif; ?>
                    <span><?php echo esc_html( $author_name ); ?></span>
                </span>
                <span class="rfd-meta-sep">|</span>
                <span>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    <?php echo esc_html( $pub_date ); ?>
                </span>
                <?php foreach ( $cats_list as $cat ) : ?>
                <a class="rfd-meta-cat"
                   href="<?php echo esc_url( get_term_link( $cat ) ); ?>">
                    <?php echo esc_html( $cat->name ); ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Excerpt -->
            <?php if ( $excerpt ) : ?>
            <p class="rfd-excerpt"><?php echo esc_html( $excerpt ); ?></p>
            <?php endif; ?>

            <!-- Hero imagen / video -->
            <div class="rfd-hero">
                <?php if ( $yt_id ) : ?>
                    <img src="<?php echo esc_url( "https://img.youtube.com/vi/{$yt_id}/maxresdefault.jpg" ); ?>"
                         alt="<?php echo esc_attr( get_the_title() ); ?>"
                         id="rfd-thumb-<?php echo esc_attr( $post_id ); ?>">
                    <div class="rfd-hero-play"
                         id="rfd-play-<?php echo esc_attr( $post_id ); ?>"
                         data-yt="<?php echo esc_attr( $yt_id ); ?>"
                         data-pid="<?php echo esc_attr( $post_id ); ?>"
                         role="button" aria-label="Reproducir video">
                        <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                            <circle cx="36" cy="36" r="36" fill="rgba(255,255,255,0.9)"/>
                            <polygon points="28,20 28,52 56,36" fill="#e84248"/>
                        </svg>
                    </div>
                    <script>
                    (function(){
                        var btn = document.getElementById('rfd-play-<?php echo esc_js( $post_id ); ?>');
                        if (!btn) return;
                        btn.addEventListener('click', function(){
                            var pid = this.dataset.pid;
                            var yt  = this.dataset.yt;
                            var hero = this.parentElement;
                            document.getElementById('rfd-thumb-' + pid).remove();
                            this.remove();
                            var iframe = document.createElement('iframe');
                            iframe.src = 'https://www.youtube-nocookie.com/embed/' + yt + '?autoplay=1&rel=0';
                            iframe.setAttribute('allowfullscreen', '');
                            iframe.setAttribute('allow', 'autoplay');
                            hero.appendChild(iframe);
                        });
                    })();
                    </script>
                <?php elseif ( $thumb ) : ?>
                    <img src="<?php echo esc_url( $thumb ); ?>"
                         alt="<?php echo esc_attr( get_the_title() ); ?>">
                <?php else : ?>
                    <div class="rfd-hero-no-img">🍽️</div>
                <?php endif; ?>
            </div>

            <!-- Barra de stats -->
            <?php if ( $tiempo || $porciones || $dificultad || $lineas_ingr ) : ?>
            <div class="rfd-stats">
                <?php if ( $tiempo ) : ?>
                <div class="rfd-stat">
                    <span class="rfd-stat-label">Tiempo</span>
                    <span class="rfd-stat-value">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                        <?php echo esc_html( $tiempo ); ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ( $porciones ) : ?>
                <div class="rfd-stat">
                    <span class="rfd-stat-label">Porciones</span>
                    <span class="rfd-stat-value">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                        </svg>
                        <?php echo esc_html( $porciones ); ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ( $dificultad ) :
                    $dif_colors = [ 'Alta' => '#e84248', 'Media' => '#ff7c3c', 'Baja' => '#1aaf9e' ];
                    $dc = $dif_colors[ $dificultad ] ?? '#555';
                ?>
                <div class="rfd-stat">
                    <span class="rfd-stat-label">Dificultad</span>
                    <span class="rfd-stat-value" style="color:<?php echo esc_attr($dc); ?>">
                        <?php echo esc_html( $dificultad ); ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ( $lineas_ingr ) :
                    $n_ingr = count( array_filter( $lineas_ingr, fn($l) => ! str_ends_with($l, ':') ) );
                ?>
                <div class="rfd-stat">
                    <span class="rfd-stat-label">Ingredientes</span>
                    <span class="rfd-stat-value"><?php echo $n_ingr; ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Cuerpo: Ingredientes | Instrucciones -->
            <?php if ( $lineas_ingr || $pasos ) : ?>
            <div class="rfd-body">

                <!-- Ingredientes -->
                <?php if ( $lineas_ingr ) : ?>
                <section>
                    <h2 class="rfd-section-title">Ingredientes</h2>
                    <ul class="rfd-ingr-list" id="rfd-ingr-<?php echo esc_attr( $post_id ); ?>">
                    <?php foreach ( $lineas_ingr as $li ) :
                        $is_header = str_ends_with( $li, ':' );
                        if ( $is_header ) : ?>
                        </ul>
                        <p class="rfd-ingr-group-title"><?php echo esc_html( rtrim( $li, ':' ) ); ?></p>
                        <ul class="rfd-ingr-list">
                        <?php else : ?>
                        <li class="rfd-ingr-item" role="checkbox" aria-checked="false" tabindex="0">
                            <span class="rfd-ingr-circle"></span>
                            <span class="rfd-ingr-text"><?php echo esc_html( $li ); ?></span>
                        </li>
                        <?php endif;
                    endforeach; ?>
                    </ul>
                </section>
                <?php endif; ?>

                <!-- Instrucciones -->
                <?php if ( $pasos ) : ?>
                <section>
                    <h2 class="rfd-section-title">Instrucciones</h2>
                    <ol class="rfd-steps-list">
                        <?php foreach ( $pasos as $paso ) : ?>
                        <li class="rfd-step">
                            <span class="rfd-step-num" aria-hidden="true"></span>
                            <span><?php echo esc_html( $paso ); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
                <?php endif; ?>

            </div>
            <?php endif; ?>

        </div>

        <script>
        (function(){
            document.querySelectorAll('.rfd-ingr-item').forEach(function(item){
                function toggle() {
                    item.classList.toggle('checked');
                    item.setAttribute('aria-checked', item.classList.contains('checked') ? 'true' : 'false');
                }
                item.addEventListener('click', toggle);
                item.addEventListener('keydown', function(e){
                    if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); toggle(); }
                });
            });
        })();
        </script>
        <?php
        $rendering = false;
        return ob_get_clean();
    }

    public function register_receta_post_type(): void {
        register_post_type( 'receta', [
            'labels' => [
                'name'               => 'Recetas',
                'singular_name'      => 'Receta',
                'add_new'            => 'Añadir nueva',
                'add_new_item'       => 'Añadir nueva receta',
                'edit_item'          => 'Editar receta',
                'new_item'           => 'Nueva receta',
                'view_item'          => 'Ver receta',
                'search_items'       => 'Buscar recetas',
                'not_found'          => 'No se encontraron recetas',
                'not_found_in_trash' => 'No hay recetas en la papelera',
                'menu_name'          => 'Recetas',
            ],
            'public'       => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-food',
            'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
            'has_archive'  => false,
            'rewrite'      => [ 'slug' => 'receta' ],
            'show_in_rest' => true,
        ] );

        register_taxonomy( 'categoria_receta', 'receta', [
            'labels'       => [
                'name'          => 'Categorías de Recetas',
                'singular_name' => 'Categoría',
                'add_new_item'  => 'Añadir categoría',
                'edit_item'     => 'Editar categoría',
            ],
            'hierarchical' => true,
            'show_ui'      => true,
            'show_in_rest' => true,
            'rewrite'      => [ 'slug' => 'categoria-receta' ],
        ] );
    }

    public function receta_meta_boxes(): void {
        add_meta_box(
            'adn_receta_datos',
            'Datos de la Receta',
            function ( $post ) {
                wp_nonce_field( 'adn_receta_save', 'adn_receta_nonce' );
                $tiempo       = get_post_meta( $post->ID, '_receta_tiempo',        true );
                $porciones    = get_post_meta( $post->ID, '_receta_porciones',     true );
                $dificultad   = get_post_meta( $post->ID, '_receta_dificultad',    true );
                $ingredientes = get_post_meta( $post->ID, '_receta_ingredientes',  true );
                $preparacion  = get_post_meta( $post->ID, '_receta_preparacion',   true );
                $youtube      = get_post_meta( $post->ID, '_receta_youtube',       true );
                ?>
                <style>
                .receta-mb td { padding: 8px 10px; vertical-align: top; }
                .receta-mb input[type=text], .receta-mb select, .receta-mb textarea { width:100%; box-sizing:border-box; }
                .receta-mb textarea { resize: vertical; }
                .receta-mb label strong { display:block; margin-bottom:4px; }
                </style>
                <table class="receta-mb" style="width:100%;border-collapse:collapse">
                    <tr>
                        <td style="width:34%">
                            <label><strong>Tiempo de preparación</strong>
                            <input type="text" name="_receta_tiempo" value="<?php echo esc_attr($tiempo); ?>"
                                   placeholder="Ej: 1:30 horas"></label>
                        </td>
                        <td style="width:33%">
                            <label><strong>Porciones</strong>
                            <input type="text" name="_receta_porciones" value="<?php echo esc_attr($porciones); ?>"
                                   placeholder="Ej: 4-6"></label>
                        </td>
                        <td style="width:33%">
                            <label><strong>Dificultad</strong>
                            <select name="_receta_dificultad">
                                <option value="">Seleccionar...</option>
                                <?php foreach ( ['Alta','Media','Baja'] as $d ) : ?>
                                <option value="<?php echo esc_attr($d); ?>" <?php selected($dificultad,$d); ?>><?php echo esc_html($d); ?></option>
                                <?php endforeach; ?>
                            </select></label>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="3">
                            <label><strong>Video de YouTube (URL)</strong>
                            <input type="text" name="_receta_youtube" value="<?php echo esc_attr($youtube); ?>"
                                   placeholder="Ej: https://www.youtube.com/watch?v=XXXXX"></label>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="3">
                            <label><strong>Ingredientes</strong> <span style="color:#888;font-weight:400">(un ingrediente por línea)</span><br>
                            <textarea name="_receta_ingredientes" rows="6"><?php echo esc_textarea($ingredientes); ?></textarea></label>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="3">
                            <label><strong>Preparación</strong> <span style="color:#888;font-weight:400">(pasos del procedimiento)</span><br>
                            <textarea name="_receta_preparacion" rows="8"><?php echo esc_textarea($preparacion); ?></textarea></label>
                        </td>
                    </tr>
                </table>
                <?php
            },
            'receta', 'normal', 'high'
        );
    }

    public function receta_save_meta( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['adn_receta_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['adn_receta_nonce'] ) ), 'adn_receta_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

        $fields = [ '_receta_tiempo', '_receta_porciones', '_receta_dificultad', '_receta_ingredientes', '_receta_preparacion', '_receta_youtube' ];
        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, $field, sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) );
            }
        }
    }

    /**
     * Shortcode [adn_recetas limit="50"]
     * Buscador pill + listado con layout alternado imagen/texto y fondos de color.
     */
    public function render_recetas_shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'limit' => 50,
        ], $atts, 'adn_recetas' );

        $limit  = max( 1, (int) $atts['limit'] );
        $search = isset( $_GET['receta_s'] ) ? sanitize_text_field( wp_unslash( $_GET['receta_s'] ) ) : '';

        $q_args = [
            'post_type'      => 'receta',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        if ( $search ) {
            $q_args['s'] = $search;
        }
        $query = new WP_Query( $q_args );

        // Colores de acento que rotan por tarjeta (fondo del lado de imagen)
        $accents = [ '#f5f5f5', '#e84248', '#1aaf9e', '#ff7c3c', '#5b4fcf', '#d6a800' ];

        ob_start();
        ?>
        <style>
        /* ── Buscador ───────────────────────────────────────────────── */
        .adn-rbuscador-wrap { text-align:center; margin:2.2rem 0 2.8rem; }
        .adn-rbuscador-form { display:inline-block; width:min(520px,92%); }
        .adn-rbuscador-inner {
            display:flex; align-items:center; gap:10px;
            border:2px solid #111; border-radius:50px;
            padding:10px 22px; background:#fff;
        }
        .adn-rbuscador-inner input[type=text] {
            flex:1; border:none; outline:none;
            font-size:1rem; background:transparent; color:#111;
            letter-spacing:.06em;
        }
        .adn-rbuscador-inner input[type=text]::placeholder { color:#888; }
        .adn-rbuscador-inner button {
            background:none; border:none; cursor:pointer;
            padding:0; display:flex; align-items:center; color:#111;
        }
        .adn-rbuscador-inner button:hover { color:#e84248; }

        /* ── Lista alternada ────────────────────────────────────────── */
        .adn-recetas-lista { display:flex; flex-direction:column; }

        .adn-receta-row {
            display:grid; grid-template-columns:1fr 1fr; min-height:340px;
        }
        .adn-receta-row-img {
            position:relative; overflow:hidden;
            display:flex; align-items:center; justify-content:center;
        }
        .adn-receta-row-img a { display:block; width:100%; height:100%; }
        .adn-receta-row-img img {
            width:100%; height:100%; object-fit:cover; display:block;
            transition:transform .4s ease;
        }
        .adn-receta-row:hover .adn-receta-row-img img { transform:scale(1.04); }
        .adn-receta-row-img .adn-yt-play {
            position:absolute; inset:0;
            display:flex; align-items:center; justify-content:center;
            pointer-events:none;
        }

        .adn-receta-row-content {
            padding:2.8rem 3.2rem; display:flex; flex-direction:column;
            justify-content:center; background:#fff;
        }
        .adn-receta-row-title {
            font-size:1.5rem; font-weight:800; margin:0 0 .9rem;
            color:#111; line-height:1.25;
        }
        .adn-receta-row-title a { color:inherit; text-decoration:none; }
        .adn-receta-row-title a:hover { color:#e84248; }
        .adn-receta-row-excerpt {
            font-size:.93rem; color:#555; line-height:1.65;
            margin:0 0 1rem; flex:1;
            display:-webkit-box; -webkit-line-clamp:4;
            -webkit-box-orient:vertical; overflow:hidden;
        }
        .adn-receta-row-cats {
            display:flex; flex-wrap:wrap; gap:.45rem; margin-bottom:1rem;
        }
        .adn-receta-row-cats span {
            font-size:.82rem; color:#888; font-weight:500;
        }
        .adn-receta-row-meta {
            display:flex; flex-wrap:wrap; gap:.9rem;
            font-size:.82rem; color:#666; margin-bottom:1.2rem;
        }
        .adn-receta-row-meta span { display:flex; align-items:center; gap:5px; }
        .adn-receta-row-btn {
            display:inline-block; align-self:flex-start;
            padding:9px 26px; border-radius:6px;
            font-size:.88rem; font-weight:700; text-decoration:none;
            color:#fff; background:#1976d2; transition:opacity .2s;
        }
        .adn-receta-row-btn:hover { opacity:.85; color:#fff; }

        /* Filas pares: imagen a la derecha */
        .adn-receta-row--reverse .adn-receta-row-img   { order:2; }
        .adn-receta-row--reverse .adn-receta-row-content { order:1; }

        /* Placeholder sin imagen */
        .adn-receta-row-no-img {
            width:100%; height:100%; min-height:340px;
            display:flex; align-items:center; justify-content:center;
            font-size:5rem; color:rgba(255,255,255,.5);
        }

        /* Vacío / no resultados */
        .adn-recetas-vacio { color:#888; font-style:italic; padding:2rem 0; text-align:center; }

        @media(max-width:768px) {
            .adn-receta-row, .adn-receta-row--reverse {
                grid-template-columns:1fr;
            }
            .adn-receta-row-img   { order:1 !important; min-height:230px; }
            .adn-receta-row-content { order:2 !important; padding:1.6rem 1.4rem; }
            .adn-receta-row-title { font-size:1.2rem; }
        }
        </style>

        <!-- Buscador -->
        <div class="adn-rbuscador-wrap">
            <form class="adn-rbuscador-form" method="get">
                <div class="adn-rbuscador-inner">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" name="receta_s"
                           placeholder="Buscar recetas..."
                           value="<?php echo esc_attr( $search ); ?>">
                    <button type="submit" aria-label="Buscar">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                            <polyline points="12 5 19 12 12 19"/>
                        </svg>
                    </button>
                </div>
            </form>
        </div>

        <!-- Listado -->
        <?php if ( ! $query->have_posts() ) : ?>
            <p class="adn-recetas-vacio">
                <?php echo $search
                    ? 'No se encontraron recetas para <strong>' . esc_html( $search ) . '</strong>.'
                    : 'No hay recetas publicadas.'; ?>
            </p>
        <?php else : ?>
        <div class="adn-recetas-lista">
        <?php
        $idx = 0;
        while ( $query->have_posts() ) :
            $query->the_post();
            $idx++;
            $post_id    = get_the_ID();
            $permalink  = get_permalink();
            $title      = get_the_title();
            $excerpt    = get_the_excerpt();
            $tiempo     = get_post_meta( $post_id, '_receta_tiempo',     true );
            $porciones  = get_post_meta( $post_id, '_receta_porciones',  true );
            $dificultad = get_post_meta( $post_id, '_receta_dificultad', true );
            $youtube    = get_post_meta( $post_id, '_receta_youtube',    true );
            $yt_id      = '';
            if ( $youtube && preg_match( '/(?:v=|youtu\.be\/)([\w-]{11})/', $youtube, $m ) ) {
                $yt_id = $m[1];
            }
            $thumb  = $yt_id
                ? "https://img.youtube.com/vi/{$yt_id}/hqdefault.jpg"
                : get_the_post_thumbnail_url( $post_id, 'large' );
            $terminos   = get_the_terms( $post_id, 'categoria_receta' );
            $categorias = ( $terminos && ! is_wp_error( $terminos ) ) ? $terminos : [];
            $accent     = $accents[ ( $idx - 1 ) % count( $accents ) ];
            $is_even    = ( $idx % 2 === 0 );
        ?>
        <article class="adn-receta-row <?php echo $is_even ? 'adn-receta-row--reverse' : ''; ?>">

            <!-- Imagen con fondo de color -->
            <div class="adn-receta-row-img" style="background:<?php echo esc_attr( $accent ); ?>">
                <?php if ( $thumb ) : ?>
                    <a href="<?php echo esc_url( $permalink ); ?>" tabindex="-1">
                        <img src="<?php echo esc_url( $thumb ); ?>"
                             alt="<?php echo esc_attr( $title ); ?>" loading="lazy">
                        <?php if ( $yt_id ) : ?>
                        <span class="adn-yt-play">
                            <svg width="60" height="42" viewBox="0 0 60 42" fill="none">
                                <rect width="60" height="42" rx="9" fill="#FF0000" fill-opacity=".88"/>
                                <polygon points="23,11 23,31 42,21" fill="white"/>
                            </svg>
                        </span>
                        <?php endif; ?>
                    </a>
                <?php else : ?>
                    <div class="adn-receta-row-no-img">🍽️</div>
                <?php endif; ?>
            </div>

            <!-- Contenido -->
            <div class="adn-receta-row-content">
                <h3 class="adn-receta-row-title">
                    <a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
                </h3>
                <?php if ( $excerpt ) : ?>
                    <p class="adn-receta-row-excerpt"><?php echo esc_html( $excerpt ); ?></p>
                <?php endif; ?>
                <?php if ( ! empty( $categorias ) ) : ?>
                <div class="adn-receta-row-cats">
                    <?php foreach ( $categorias as $cat ) : ?>
                        <span># <?php echo esc_html( $cat->name ); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ( $tiempo || $porciones || $dificultad ) : ?>
                <div class="adn-receta-row-meta">
                    <?php if ( $tiempo ) : ?>
                    <span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                        <?php echo esc_html( $tiempo ); ?>
                    </span>
                    <?php endif; ?>
                    <?php if ( $porciones ) : ?>
                    <span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                        </svg>
                        <?php echo esc_html( $porciones ); ?>
                    </span>
                    <?php endif; ?>
                    <?php if ( $dificultad ) : ?>
                    <span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                        <?php echo esc_html( $dificultad ); ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <a href="<?php echo esc_url( $permalink ); ?>" class="adn-receta-row-btn"
                   style="background:<?php echo esc_attr( $accent === '#f5f5f5' ? '#1976d2' : $accent ); ?>">
                    Ver Receta
                </a>
            </div>

        </article>
        <?php endwhile;
        wp_reset_postdata(); ?>
        </div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
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
