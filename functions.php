<?php
// PixieWiki Child Theme Final Functions (SEO + A11y version)
// Final architecture using CPT, Categories for URLs, and a single, common, private Custom Taxonomy for sidebar grouping.

function pixie_wiki_child_enqueue_styles() {
    wp_enqueue_style( 'pixie-wiki-child-style', get_stylesheet_directory_uri() . '/style.css', array( 'astra-theme-css' ), '20.0.0' );
}
add_action( 'wp_enqueue_scripts', 'pixie_wiki_child_enqueue_styles', 15 );

function pixie_wiki_enqueue_active_link_script() {
    if ( is_singular('post') ) {
        wp_enqueue_script( 'pixie-wiki-active-link', get_stylesheet_directory_uri() . '/js/active-link.js', array(), '1.0.0', true );
        wp_localize_script( 'pixie-wiki-active-link', 'wikiData', array( 'currentPostId' => get_the_ID() ) );
    }
}
add_action( 'wp_enqueue_scripts', 'pixie_wiki_enqueue_active_link_script' );

function pixie_wiki_register_content_types() {
    register_post_type( 'wiki', array(
        'labels'        => array( 'name' => 'Wiki', 'singular_name' => 'Game Wiki', 'add_new_item' => 'Add New Game Wiki', 'menu_name' => 'Wiki' ),
        'public'        => true, 'has_archive' => 'wiki', 'rewrite' => array('slug' => 'wiki', 'with_front' => false),
        'supports'      => array('title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'),
        'show_in_rest'  => true, 'menu_icon'   => 'dashicons-book-alt',
    ));

    register_taxonomy( 'wiki_section', array( 'post' ), array(
        'hierarchical'      => true,
        'labels'            => array('name' => 'Wiki Sections', 'singular_name' => 'Wiki Section', 'menu_name' => 'Wiki Sections'),
        'show_ui'           => true, 'show_admin_column' => true, 'show_in_rest'      => true,
        'public'            => false, 'publicly_queryable'=> false, 'rewrite'           => false, 'show_in_menu'      => true,
    ));
}
add_action( 'init', 'pixie_wiki_register_content_types' );

function pixie_wiki_redirect_category_archives_to_cpt() {
    if ( is_category() ) {
        $category = get_queried_object();
        $wiki_page = get_page_by_path( $category->slug, OBJECT, 'wiki' );
        if ( $wiki_page ) {
            wp_safe_redirect( get_permalink( $wiki_page->ID ), 301 );
            exit();
        }
    }
}
add_action( 'template_redirect', 'pixie_wiki_redirect_category_archives_to_cpt' );

function pixie_wiki_get_grouped_post_list_html( $category_id ) {
    $cache_key = 'pixie_wiki_common_grouped_list_v_final_' . $category_id;
    $cached_list = get_transient( $cache_key );
    if ( false !== $cached_list ) { return $cached_list; }

    $list_html = '';
    $terms = get_terms( array(
        'taxonomy'   => 'wiki_section',
        'object_ids' => get_posts( array( 'fields' => 'ids', 'posts_per_page' => -1, 'category' => $category_id ) ),
        'hide_empty' => true,
    ) );

    if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
        foreach ( $terms as $term ) {
            $args = array(
                'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC',
                'tax_query'      => array( 'relation' => 'AND',
                    array( 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $category_id ),
                    array( 'taxonomy' => 'wiki_section', 'field' => 'term_id', 'terms' => $term->term_id ),
                ),
            );
            $wiki_posts = new WP_Query( $args );
            if ( $wiki_posts->have_posts() ) {
                $list_html .= '<div role="heading" aria-level="3" class="wiki-nav-section-title">' . esc_html( $term->name ) . '</div>';
                $list_html .= '<nav class="wiki-nav-menu"><ul>';
                while ( $wiki_posts->have_posts() ) {
                    $wiki_posts->the_post();
                    $list_html .= '<li data-post-id="' . get_the_ID() . '"><a href="' . get_permalink() . '">' . get_the_title() . '</a></li>';
                }
                $list_html .= '</ul></nav>';
                wp_reset_postdata();
            }
        }
    }
    set_transient( $cache_key, $list_html, 12 * HOUR_IN_SECONDS );
    return $list_html;
}

function pixie_wiki_inject_sidebar() {
    if ( is_singular('post') ) {
        $categories = get_the_category();
        if ( ! empty( $categories ) ) {
            $main_category = $categories[0];
            $list_content = pixie_wiki_get_grouped_post_list_html( $main_category->term_id );
            if ( ! empty( $list_content ) ) {
                echo '<aside id="pixie-wiki-sidebar" class="widget-area pixie-wiki-sidebar">';
                echo '<div class="sidebar-main">';
                echo '<section id="pixie-wiki-nav-widget" class="widget">';
                echo '<div role="heading" aria-level="2" class="widget-title">' . esc_html( $main_category->name ) . ' Wiki</div>';
                echo $list_content;
                echo '</section>';
                echo '</div></aside>';
            }
        }
    }
}
add_action( 'astra_content_top', 'pixie_wiki_inject_sidebar' );

function pixie_wiki_flush_cache( $post_id ) {
    if ( wp_is_post_revision( $post_id ) ) { return; }
    $categories = get_the_category( $post_id );
    if ( ! empty( $categories ) ) {
        foreach ( $categories as $category ) {
            delete_transient( 'pixie_wiki_common_grouped_list_v_final_' . $category->term_id );
        }
    }
}
add_action( 'save_post', 'pixie_wiki_flush_cache' );
add_action( 'delete_post', 'pixie_wiki_flush_cache' );

function pixie_wiki_perfect_breadcrumbs( $links ) {
    if ( is_singular('post') ) {
        $cpt_archive_link = get_post_type_archive_link('wiki');
        if ( $cpt_archive_link ) {
            $breadcrumb_to_add = array('url' => $cpt_archive_link, 'text' => 'Wiki');
            array_splice( $links, 1, 0, array($breadcrumb_to_add) );
        }
        if ( isset($links[2]) && isset($links[2]['term_id']) ) {
            $category = get_term( $links[2]['term_id'], 'category' );
            if ( $category && ! is_wp_error( $category ) ) {
                $wiki_page = get_page_by_path( $category->slug, OBJECT, 'wiki' );
                if ( $wiki_page ) {
                    $links[2]['url'] = get_permalink( $wiki_page->ID );
                }
            }
        }
    }
    return $links;
}
add_filter( 'wpseo_breadcrumb_links', 'pixie_wiki_perfect_breadcrumbs' );

function pixie_wiki_auto_toc_en( $content ) {
    if ( is_singular('post') && in_the_loop() && is_main_query() ) {
        $headings = [];
        $content_processed = preg_replace_callback('/<h2(.*?)>(.*?)<\/h2>/i', function($matches) use (&$headings) {
            $slug = sanitize_title($matches[2]);
            $headings[] = [ 'link' => $slug, 'text' => $matches[2] ];
            return '<h2 id="' . $slug . '"' . $matches[1] . '>' . $matches[2] . '</h2>';
        }, $content);
        if (count($headings) < 2) return $content;
        $toc_html = '<div id="wiki-toc" class="wiki-box"><p class="toc-title">Table of Contents</p><ol>';
        foreach ($headings as $heading) {
            $toc_html .= '<li><a href="#' . esc_attr($heading['link']) . '">' . esc_html($heading['text']) . '</a></li>';
        }
        $toc_html .= '</ol></div>';
        $first_p_position = strpos( $content_processed, '</p>' );
        if ( false !== $first_p_position ) {
            $content_with_toc = substr_replace( $content_processed, $toc_html, $first_p_position + 4, 0 );
            return $content_with_toc;
        } else {
            return $toc_html . $content_processed;
        }
    }
    return $content;
}
add_filter('the_content', 'pixie_wiki_auto_toc_en');
