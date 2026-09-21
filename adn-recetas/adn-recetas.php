<?php
/**
 * Plugin Name: ADN Recetas
 * Description: Gestión de recetas para Distribuidora El Loro. CPT receta, rol Editor de Recetas, shortcode [adn_recetas].
 * Version: 1.0.0
 * Author: ADN Software
 * Text Domain: adn-recetas
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ADN_RECETAS_VERSION', '1.0.0' );

class ADN_Recetas_Plugin {

    public function __construct() {
        add_action( 'init',             [ $this, 'register_post_type' ] );
        add_action( 'init',             [ $this, 'setup_roles' ] );
        add_action( 'add_meta_boxes',   [ $this, 'meta_boxes' ] );
        add_action( 'save_post_receta', [ $this, 'save_meta' ], 10, 2 );
        add_shortcode( 'adn_recetas',   [ $this, 'shortcode_lista' ] );
        add_filter( 'the_content',      [ $this, 'single_content' ] );
    }

    // ─── CPT + Taxonomía ─────────────────────────────────────────────────────

    public function register_post_type(): void {
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
            'public'          => true,
            'show_in_menu'    => true,
            'menu_icon'       => 'dashicons-food',
            'supports'        => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
            'has_archive'     => false,
            'rewrite'         => [ 'slug' => 'receta' ],
            'show_in_rest'    => true,
            'capability_type' => [ 'receta', 'recetas' ],
            'map_meta_cap'    => true,
        ] );

        register_taxonomy( 'categoria_receta', 'receta', [
            'labels' => [
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

    // ─── Rol Editor de Recetas ────────────────────────────────────────────────

    public function setup_roles(): void {
        if ( get_option( 'adn_recetas_plugin_roles_ver' ) === '1' ) {
            return;
        }

        $caps_receta = [
            'edit_recetas',
            'edit_others_recetas',
            'edit_published_recetas',
            'edit_private_recetas',
            'publish_recetas',
            'read_private_recetas',
            'delete_recetas',
            'delete_others_recetas',
            'delete_published_recetas',
            'delete_private_recetas',
            'create_recetas',
        ];

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( $caps_receta as $cap ) {
                $admin->add_cap( $cap );
            }
        }

        remove_role( 'editor_recetas' );
        add_role( 'editor_recetas', 'Editor de Recetas', [
            'read'                     => true,
            'upload_files'             => true,
            'manage_categories'        => true,
            'edit_recetas'             => true,
            'edit_published_recetas'   => true,
            'publish_recetas'          => true,
            'delete_recetas'           => true,
            'delete_published_recetas' => true,
            'create_recetas'           => true,
        ] );

        update_option( 'adn_recetas_plugin_roles_ver', '1' );
    }

    // ─── Meta Boxes ──────────────────────────────────────────────────────────

    public function meta_boxes(): void {
        add_meta_box(
            'adn_receta_datos',
            'Datos de la Receta',
            function ( $post ) {
                wp_nonce_field( 'adn_receta_save', 'adn_receta_nonce' );
                $tiempo       = get_post_meta( $post->ID, '_receta_tiempo',       true );
                $porciones    = get_post_meta( $post->ID, '_receta_porciones',    true );
                $dificultad   = get_post_meta( $post->ID, '_receta_dificultad',   true );
                $ingredientes = get_post_meta( $post->ID, '_receta_ingredientes', true );
                $preparacion  = get_post_meta( $post->ID, '_receta_preparacion',  true );
                $youtube      = get_post_meta( $post->ID, '_receta_youtube',      true );
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
                            <input type="text" name="_receta_tiempo" value="<?php echo esc_attr( $tiempo ); ?>"
                                   placeholder="Ej: 1:30 horas"></label>
                        </td>
                        <td style="width:33%">
                            <label><strong>Porciones</strong>
                            <input type="text" name="_receta_porciones" value="<?php echo esc_attr( $porciones ); ?>"
                                   placeholder="Ej: 4-6"></label>
                        </td>
                        <td style="width:33%">
                            <label><strong>Dificultad</strong>
                            <select name="_receta_dificultad">
                                <option value="">Seleccionar...</option>
                                <?php foreach ( [ 'Alta', 'Media', 'Baja' ] as $d ) : ?>
                                <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $dificultad, $d ); ?>><?php echo esc_html( $d ); ?></option>
                                <?php endforeach; ?>
                            </select></label>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="3">
                            <label><strong>Video de YouTube (URL)</strong>
                            <input type="text" name="_receta_youtube" value="<?php echo esc_attr( $youtube ); ?>"
                                   placeholder="Ej: https://www.youtube.com/watch?v=XXXXX"></label>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="3">
                            <label><strong>Ingredientes</strong> <span style="color:#888;font-weight:400">(un ingrediente por línea; líneas que terminan en ":" son encabezados de grupo)</span><br>
                            <textarea name="_receta_ingredientes" rows="6"><?php echo esc_textarea( $ingredientes ); ?></textarea></label>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="3">
                            <label><strong>Preparación</strong> <span style="color:#888;font-weight:400">(un paso por línea)</span><br>
                            <textarea name="_receta_preparacion" rows="8"><?php echo esc_textarea( $preparacion ); ?></textarea></label>
                        </td>
                    </tr>
                </table>
                <?php
            },
            'receta', 'normal', 'high'
        );
    }

    // ─── Guardar Meta ─────────────────────────────────────────────────────────

    public function save_meta( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['adn_receta_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['adn_receta_nonce'] ) ), 'adn_receta_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $fields = [
            '_receta_tiempo',
            '_receta_porciones',
            '_receta_dificultad',
            '_receta_ingredientes',
            '_receta_preparacion',
            '_receta_youtube',
        ];
        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, $field, sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) );
            }
        }
    }

    // ─── Shortcode [adn_recetas] ──────────────────────────────────────────────

    public function shortcode_lista( $atts ): string {
        $atts   = shortcode_atts( [ 'limit' => 50 ], $atts, 'adn_recetas' );
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
        $query   = new WP_Query( $q_args );
        $accents = [ '#f5f5f5', '#e84248', '#1aaf9e', '#ff7c3c', '#5b4fcf', '#d6a800' ];

        ob_start();
        ?>
        <style>
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
        }
        .adn-receta-row-excerpt p { margin:0 0 .7rem; }
        .adn-receta-row-excerpt p:last-child { margin-bottom:0; }
        .adn-receta-row-cats {
            display:flex; flex-wrap:wrap; gap:.45rem; margin-bottom:1rem;
        }
        .adn-receta-row-cats span { font-size:.82rem; color:#888; font-weight:500; }
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
        .adn-receta-row--reverse .adn-receta-row-img   { order:2; }
        .adn-receta-row--reverse .adn-receta-row-content { order:1; }
        .adn-receta-row-no-img {
            width:100%; height:100%; min-height:340px;
            display:flex; align-items:center; justify-content:center;
            font-size:5rem; color:rgba(255,255,255,.5);
        }
        .adn-recetas-vacio { color:#888; font-style:italic; padding:2rem 0; text-align:center; }
        @media(max-width:768px) {
            .adn-receta-row, .adn-receta-row--reverse { grid-template-columns:1fr; }
            .adn-receta-row-img   { order:1 !important; min-height:230px; }
            .adn-receta-row-content { order:2 !important; padding:1.6rem 1.4rem; }
            .adn-receta-row-title { font-size:1.2rem; }
        }
        </style>

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
            $post_id   = get_the_ID();
            $permalink = get_permalink();
            $title     = get_the_title();
            $excerpt   = get_post_field( 'post_excerpt', $post_id );
            if ( ! $excerpt ) {
                $excerpt = wp_strip_all_tags( strip_shortcodes( get_post_field( 'post_content', $post_id ) ) );
            }
            $tiempo     = get_post_meta( $post_id, '_receta_tiempo',     true );
            $porciones  = get_post_meta( $post_id, '_receta_porciones',  true );
            $dificultad = get_post_meta( $post_id, '_receta_dificultad', true );
            $youtube    = get_post_meta( $post_id, '_receta_youtube',    true );
            $yt_id      = '';
            if ( $youtube && preg_match( '/(?:v=|youtu\.be\/)([\w-]{11})/', $youtube, $m ) ) {
                $yt_id = $m[1];
            }
            $thumb      = $yt_id
                ? "https://img.youtube.com/vi/{$yt_id}/hqdefault.jpg"
                : get_the_post_thumbnail_url( $post_id, 'large' );
            $terminos   = get_the_terms( $post_id, 'categoria_receta' );
            $categorias = ( $terminos && ! is_wp_error( $terminos ) ) ? $terminos : [];
            $accent     = $accents[ ( $idx - 1 ) % count( $accents ) ];
            $is_even    = ( $idx % 2 === 0 );
        ?>
        <article class="adn-receta-row <?php echo $is_even ? 'adn-receta-row--reverse' : ''; ?>">
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
            <div class="adn-receta-row-content">
                <h3 class="adn-receta-row-title">
                    <a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
                </h3>
                <?php if ( $excerpt ) : ?>
                    <div class="adn-receta-row-excerpt"><?php echo wp_kses_post( wpautop( $excerpt ) ); ?></div>
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
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                        <?php echo esc_html( $tiempo ); ?>
                    </span>
                    <?php endif; ?>
                    <?php if ( $porciones ) : ?>
                    <span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                        </svg>
                        <?php echo esc_html( $porciones ); ?>
                    </span>
                    <?php endif; ?>
                    <?php if ( $dificultad ) : ?>
                    <span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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

    // ─── Página individual de receta ──────────────────────────────────────────

    public function single_content( string $content ): string {
        if ( ! is_singular( 'receta' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        static $rendering = false;
        if ( $rendering ) { return $content; }
        $rendering = true;

        $post_id      = get_the_ID();
        $tiempo       = get_post_meta( $post_id, '_receta_tiempo',       true );
        $porciones    = get_post_meta( $post_id, '_receta_porciones',    true );
        $dificultad   = get_post_meta( $post_id, '_receta_dificultad',   true );
        $ingredientes = get_post_meta( $post_id, '_receta_ingredientes', true );
        $preparacion  = get_post_meta( $post_id, '_receta_preparacion',  true );
        $youtube      = get_post_meta( $post_id, '_receta_youtube',      true );
        $excerpt      = get_post_field( 'post_excerpt', $post_id );
        if ( ! $excerpt ) {
            $excerpt = wp_strip_all_tags( strip_shortcodes( get_post_field( 'post_content', $post_id ) ) );
        }

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

        $terminos    = get_the_terms( $post_id, 'categoria_receta' );
        $cats_list   = ( $terminos && ! is_wp_error( $terminos ) ) ? $terminos : [];
        $author_id   = get_post_field( 'post_author', $post_id );
        $author_name = get_the_author_meta( 'display_name', $author_id );
        $author_url  = get_avatar_url( $author_id, [ 'size' => 36 ] );
        $pub_date    = get_the_date( 'd \d\e F, Y', $post_id );

        ob_start();
        ?>
        <style>
        @media(min-width:960px) {
            .neve-main > .single-post-container .nv-single-post-wrap.col,
            .neve-main > .container .col { max-width: 100% !important; }
        }
        .single-receta .entry-header .entry-title,
        .single-receta h1.title { display:none !important; }
        .rfd-wrap { font-family: inherit; color: #222; max-width: 100%; margin: 0 auto 3rem; }
        .rfd-title { font-size: 2.4rem; font-weight: 900; line-height: 1.15; margin: 0 0 .75rem; color: #111; }
        .rfd-meta { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem 1.2rem; font-size: .85rem; color: #777; margin-bottom: 1rem; }
        .rfd-meta-author { display: flex; align-items: center; gap: .45rem; color: #444; }
        .rfd-meta-author img { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 2px solid #eee; }
        .rfd-meta-author span { font-weight: 600; }
        .rfd-meta-sep { color: #ddd; }
        .rfd-meta-cat { background: #f0f0f0; padding: 2px 10px; border-radius: 20px; font-size: .78rem; color: #555; text-decoration: none; }
        .rfd-meta-cat:hover { background: #e84248; color: #fff; }
        .rfd-excerpt { font-size: .97rem; color: #555; line-height: 1.7; margin: 0 0 1.6rem; max-width: 720px; }
        .rfd-excerpt p { margin: 0 0 .8rem; }
        .rfd-excerpt p:last-child { margin-bottom: 0; }
        .rfd-hero { position: relative; border-radius: 12px; overflow: hidden; background: #1a1a2e; aspect-ratio: 16/9; margin-bottom: 1.6rem; }
        .rfd-hero img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .rfd-hero iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
        .rfd-hero-play { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; cursor: pointer; background: rgba(0,0,0,.18); transition: background .2s; }
        .rfd-hero-play:hover { background: rgba(0,0,0,.32); }
        .rfd-hero-play svg { filter: drop-shadow(0 2px 8px rgba(0,0,0,.4)); }
        .rfd-hero-no-img { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 5rem; color: #555; }
        .rfd-stats { display: flex; flex-wrap: wrap; gap: 0; border-top: 1px solid #eee; border-bottom: 1px solid #eee; margin-bottom: 2.4rem; }
        .rfd-stat { display: flex; flex-direction: column; align-items: center; padding: .85rem 1.6rem; gap: 2px; flex: 1; min-width: 100px; border-right: 1px solid #eee; }
        .rfd-stat:last-child { border-right: none; }
        .rfd-stat-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .1em; color: #aaa; font-weight: 600; }
        .rfd-stat-value { font-size: .95rem; font-weight: 800; color: #111; display: flex; align-items: center; gap: 5px; }
        .rfd-stat-value svg { color: #888; flex-shrink: 0; }
        .rfd-body { display: grid; grid-template-columns: 1fr 1.7fr; gap: 3rem; align-items: start; }
        @media(max-width:640px) { .rfd-body { grid-template-columns: 1fr; gap: 2rem; } }
        .rfd-section-title { font-size: 1.4rem; font-weight: 800; color: #111; margin: 0 0 1.1rem; padding-bottom: .5rem; border-bottom: 2px solid #111; }
        .rfd-ingr-group-title { font-size: .82rem; text-transform: uppercase; letter-spacing: .08em; color: #777; font-weight: 700; margin: 1.1rem 0 .4rem; }
        .rfd-ingr-list { list-style: none; padding: 0; margin: 0; }
        .rfd-ingr-item { display: flex; align-items: flex-start; gap: .7rem; padding: .45rem 0; border-bottom: 1px solid #f0f0f0; font-size: .93rem; color: #333; line-height: 1.4; cursor: pointer; user-select: none; transition: color .15s; }
        .rfd-ingr-item:last-child { border-bottom: none; }
        .rfd-ingr-item input[type=checkbox] { display: none; }
        .rfd-ingr-circle { flex-shrink: 0; width: 18px; height: 18px; border: 2px solid #ccc; border-radius: 50%; margin-top: 2px; transition: background .2s, border-color .2s; display: flex; align-items: center; justify-content: center; }
        .rfd-ingr-item.checked .rfd-ingr-circle { background: #e84248; border-color: #e84248; }
        .rfd-ingr-item.checked .rfd-ingr-circle::after { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #fff; }
        .rfd-ingr-item.checked .rfd-ingr-text { text-decoration: line-through; color: #bbb; }
        .rfd-ingr-text { flex: 1; }
        .rfd-steps-list { list-style: none; padding: 0; margin: 0; counter-reset: rfd-step; }
        .rfd-step { counter-increment: rfd-step; display: flex; gap: 1rem; padding: .9rem 0; border-bottom: 1px solid #f0f0f0; font-size: .93rem; color: #444; line-height: 1.65; align-items: flex-start; }
        .rfd-step:last-child { border-bottom: none; }
        .rfd-step-num { flex-shrink: 0; width: 28px; height: 28px; background: #e84248; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 800; margin-top: 2px; }
        .rfd-step-num::before { content: counter(rfd-step); }
        @media(max-width:480px) { .rfd-title { font-size: 1.7rem; } .rfd-stat { padding: .6rem .8rem; } }
        </style>

        <div class="rfd-wrap">
            <h1 class="rfd-title"><?php echo esc_html( get_the_title() ); ?></h1>

            <div class="rfd-meta">
                <span class="rfd-meta-author">
                    <?php if ( $author_url ) : ?>
                        <img src="<?php echo esc_url( $author_url ); ?>" alt="<?php echo esc_attr( $author_name ); ?>">
                    <?php endif; ?>
                    <span><?php echo esc_html( $author_name ); ?></span>
                </span>
                <span class="rfd-meta-sep">|</span>
                <span>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    <?php echo esc_html( $pub_date ); ?>
                </span>
                <?php foreach ( $cats_list as $cat ) : ?>
                <a class="rfd-meta-cat" href="<?php echo esc_url( get_term_link( $cat ) ); ?>">
                    <?php echo esc_html( $cat->name ); ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if ( $excerpt ) : ?>
            <div class="rfd-excerpt"><?php echo wp_kses_post( wpautop( $excerpt ) ); ?></div>
            <?php endif; ?>

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
                            var pid = this.dataset.pid, yt = this.dataset.yt;
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
                    <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>">
                <?php else : ?>
                    <div class="rfd-hero-no-img">🍽️</div>
                <?php endif; ?>
            </div>

            <?php if ( $tiempo || $porciones || $dificultad || $lineas_ingr ) : ?>
            <div class="rfd-stats">
                <?php if ( $tiempo ) : ?>
                <div class="rfd-stat">
                    <span class="rfd-stat-label">Tiempo</span>
                    <span class="rfd-stat-value">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                        <?php echo esc_html( $tiempo ); ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ( $porciones ) : ?>
                <div class="rfd-stat">
                    <span class="rfd-stat-label">Porciones</span>
                    <span class="rfd-stat-value">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
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
                    <span class="rfd-stat-value" style="color:<?php echo esc_attr( $dc ); ?>">
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

            <?php if ( $lineas_ingr || $pasos ) : ?>
            <div class="rfd-body">
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
}

add_action( 'plugins_loaded', function () {
    new ADN_Recetas_Plugin();
} );
