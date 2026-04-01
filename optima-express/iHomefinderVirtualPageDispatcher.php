<?php


if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * This singleton class is used to filter the content of iHomefinder VirtualPages.
 * We use the iHomefinderVirtualPageFactory class to retrieve the
 * proper VirtualPage implementation.
 *
 * @author ihomefinder
 */
class iHomefinderVirtualPageDispatcher
{
    
    private static $instance;
    
    private $virtualPage = null;
    private $content = null;
    private $excerpt = null;
    private $title = null;
    private $initialized = false;
    private $enqueueResource;
    private $displayRules;

    private function __construct()
    {
        $this->enqueueResource = iHomefinderEnqueueResource::getInstance();
        $this->displayRules = iHomefinderDisplayRules::getInstance();
    }

    /**
     * Detect if the current theme is a block theme
     *
     * @return bool True if block theme, false if classic theme
     */
    private function isBlockTheme()
    {
        // Check if wp_is_block_theme exists (WP 5.9+)
        if (function_exists('wp_is_block_theme')) {
            return wp_is_block_theme();
        }

        // Fallback for older versions
        $theme = wp_get_theme();
        $templates_path = $theme->get_template_directory() . '/templates';
        return is_dir($templates_path);
    }
    
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function initialize( $query )
    {
        if ( $this->initialized ) {
            return;
        }

        // Detect virtual page strictly by query var.
        $type = get_query_var( iHomefinderConstants::IHF_TYPE_URL_VAR );
        if ( empty( $type ) ) {
            return;
        }

        $this->initialized = true;

        // Mark query as a singular page request (for block template selection).
        $query->is_404        = false;
        $query->is_home       = false;
        $query->is_front_page = false;
        $query->is_posts_page = false;
        $query->is_archive    = false;
        $query->is_search     = false;
        $query->is_single     = false;

        $query->is_page      = true;
        $query->is_singular  = true;

        // Build the virtual page implementation + content.
        $this->virtualPage = iHomefinderVirtualPageFactory::getInstance()->getVirtualPage( $type );
        $this->virtualPage->getContent();

        $this->content = (string) $this->virtualPage->getBody();
        $this->excerpt = (string) $this->virtualPage->getBody();
        $this->title   = (string) $this->virtualPage->getTitle();

        if ( ! $this->displayRules->isKestrelAll() ) {
            $this->enqueueResource->addToHeader( $this->virtualPage->getHead() );
        }
        $this->enqueueResource->addToMetaTags( $this->virtualPage->getMetaTags() );

        $this->removeFilters();
        $this->removeCaching();
    }
    /**
     * removes filters that can cause issues on virtual pages
     */
    private function removeFilters()
    {
        $tags = array(
        "the_content",
        "the_excerpt"
        );
        $functionNames = array(
        "wpautop",
        "wptexturize",
        "convert_chars"
        );
        foreach ($tags as $tag) {
            foreach ($functionNames as $functionName) {
                remove_filter($tag, $functionName);
            }
        }
    }
    
    /**
     * disables caching plugins on virtual pages
     */
    private function removeCaching()
    {
        $constants = array(
        "DONOTCACHEPAGE",
        "DONOTCACHEDB",
        "DONOTMINIFY",
        "DONOTCDN",
        "DONOTCACHCEOBJECT"
        );
        foreach ($constants as $constant) {
            if (!defined($constant)) {
                define($constant, true);
            }
        }
    }
    
    /**
     * Cleanup state after filtering. This fixes an issue
     * where widgets display different loop content, such
     * as featured posts.
     */
    private function afterFilter()
    {
        // Do not reset $this->initialized during the request.
        // Block themes may render content multiple times and we rely on initialized state.
    }
    
    /**
     * We identify iHomefinder requests based on the query_var
     * iHomefinderConstants::IHF_TYPE_URL_VAR.
     * Set the proper title and update the posts array to contain only
     * a single posts. This will get updated in another action later
     * during processing. We cannot set the post content here, because
     * WordPress does some odd formatting of the post_content, if we
     * add it here (see the getContent method below, where content is properly set)
     *
     * @param $posts
     */
    public function postCleanUp( $posts, $query )
    {
        if ( ! $query instanceof WP_Query || ! $query->is_main_query() ) {
            return $posts;
        }

        $type = get_query_var(iHomefinderConstants::IHF_TYPE_URL_VAR);
        if ( empty( $type ) ) {
            return $posts;
        }

        // primeVirtualQuery already injected the fake post.
        return $query->posts ?: $posts;
    }
    
    public function templateInclude( $template ) {
        global $wp_query;
        $this->initialize( $wp_query );

        if ( ! $this->initialized ) {
            return $template;
        }

        // Optional: support your existing "admin selected template" value.
        $virtualPageTemplate = $this->virtualPage ? $this->virtualPage->getPageTemplate() : '';

        // If block theme, let WordPress resolve block templates (page.html, etc).
        if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
            // If you have a *PHP* file coming from legacy selection, allow it as fallback.
            // Example: 'page-ihf.php' or 'ihf-template.php'
            $candidates = array();

            if ( ! empty( $virtualPageTemplate ) ) {
                $candidates[] = $virtualPageTemplate;
                // Also try page-{slug}.php if they stored just "ihf"
                $base = preg_replace( '/\.php$/', '', basename( $virtualPageTemplate ) );
                if ( $base ) {
                    $candidates[] = $base . '.php';
                    $candidates[] = 'page-' . $base . '.php';
                }
            }

            // Always provide sensible fallbacks.
            $candidates[] = 'page.php';
            $candidates[] = 'index.php';

            $phpTemplate = locate_template( $candidates );

            // This is the key for block themes: it will return template-canvas.php when a block template exists.
            if ( function_exists( 'locate_block_template' ) ) {
                // Use the page slug-ish name to help pick a block template if present (page-{slug}.html).
                $slug = '';
                if ( ! empty( $virtualPageTemplate ) ) {
                    $slug = preg_replace( '/^page-/', '', preg_replace( '/\.php$/', '', basename( $virtualPageTemplate ) ) );
                }
                $blockCandidateSlug = $slug ? 'page-' . $slug : 'page';

                $maybe = locate_block_template( $phpTemplate, $blockCandidateSlug, $candidates );
                if ( ! empty( $maybe ) ) {
                    return $maybe;
                }
            }

            // Fallback: use the best PHP template we found (or the original template).
            return $phpTemplate ?: $template;
        }

        // Classic theme behavior: keep your original logic.
        if ( ! empty( $virtualPageTemplate ) ) {
            $found = locate_template( array( $virtualPageTemplate ) );
            if ( ! empty( $found ) ) {
                return $found;
            }
        }

        return $template;
    }

    /**
     * For the ihf plugin page, we replace the content, with data retrieved from the iHomefinder servers.
     *
     * @param $content
     */
    public function getContent( $content ) {
        $type = get_query_var( iHomefinderConstants::IHF_TYPE_URL_VAR );
        if ( empty( $type ) ) {
            return $content;
        }

        global $wp_query;
        $this->initialize( $wp_query );

        if ( $this->initialized ) {
            return $this->content;
        }

        return $content;
    }
    
    /**
     * For the ihf plugin page, we replace the excerpt, with data retrieved from the iHomefinder servers.
     *
     * @param $content
     */
    public function getExcerpt($excerpt)
    {
        global $wp_query;
        $this->initialize( $wp_query );

        if ($this->initialized) {
            $excerpt = $this->excerpt;
        }
        return $excerpt;
    }
    
    /**
     * If this is a virtual page, clear out any comments
     */
    public function clearComments($comments)
    {
        if (get_query_var(iHomefinderConstants::IHF_TYPE_URL_VAR)) {
            $comments = array();
        }
        return $comments;
    }

    public function fixTemplatePartThemeAttribute( $parsed_block, $source_block, $parent_block ) {
        // Only affect iHF virtual pages.
        $type = get_query_var(iHomefinderConstants::IHF_TYPE_URL_VAR);
        if ( empty( $type ) ) {
            return $parsed_block;
        }

        if ( empty( $parsed_block['blockName'] ) || $parsed_block['blockName'] !== 'core/template-part' ) {
            return $parsed_block;
        }

        // If theme is set, it can cause WP to look up template parts in the wrong theme context
        // and return WP_Error, which triggers the "WP_Error::$content" warning and blank header/footer.
        if ( isset( $parsed_block['attrs']['theme'] ) ) {
            unset( $parsed_block['attrs']['theme'] );
        }

        return $parsed_block;
    }

    public function primeVirtualQuery( $query ) {
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $type = $query->get( iHomefinderConstants::IHF_TYPE_URL_VAR );
        if ( empty( $type ) ) {
            return;
        }

        // flags only
        $query->set( 'post_type', 'page' );
        $query->is_page     = true;
        $query->is_singular = true;
        $query->is_home     = false;
        $query->is_archive  = false;
        $query->is_search   = false;
        $query->is_404      = false;
    }

    public function renderVirtualPostContentBlock( $block_content, $block ) {
        $type = get_query_var( iHomefinderConstants::IHF_TYPE_URL_VAR );
        if ( empty( $type ) ) {
            return $block_content;
        }

        global $wp_query;
        $this->initialize( $wp_query );

        if ( ! $this->initialized ) {
            return $block_content;
        }

        // If the virtual body contains blocks, this renders them.
        // If it's plain HTML/script, do_blocks() will just return it unchanged.
        return do_blocks( $this->content );
    }

    public function setupVirtualGlobals() {
        $type = get_query_var( iHomefinderConstants::IHF_TYPE_URL_VAR );
        if ( empty( $type ) ) {
            return;
        }

        global $wp_query;
        $this->initialize( $wp_query );

        if ( ! $this->initialized ) {
            return;
        }

        if ( isset( $wp_query->post ) && $wp_query->post instanceof WP_Post ) {
            $GLOBALS['post'] = $wp_query->post;
            return;
        }

        if ( ! empty( $wp_query->posts[0] ) && $wp_query->posts[0] instanceof WP_Post ) {
            $wp_query->post = $wp_query->posts[0];
            $wp_query->queried_object = $wp_query->post;
            $wp_query->queried_object_id = (int) $wp_query->post->ID;
            $GLOBALS['post'] = $wp_query->post;
        }
    }

    public function shortCircuitVirtualPosts( $posts, $query ) {
        if ( is_admin() || ! ( $query instanceof WP_Query ) || ! $query->is_main_query() ) {
            return $posts;
        }

        $type = $query->get( iHomefinderConstants::IHF_TYPE_URL_VAR );
        if ( empty( $type ) ) {
            return $posts;
        }

        // Ensure virtual content is loaded
        $this->initialize( $query );

        if ( ! $this->initialized ) {
            return $posts;
        }

        // Build ONE synthetic post
        $slug = sanitize_title( $this->title ?: 'ihf-virtual-page' );

        $postarr = array(
            'ID' => 999999999,
            'post_author' => 0,
            'post_name' => $slug,
            'post_type' => 'page',
            'post_title' => $this->title,
            'post_content' => $this->content,
            'post_excerpt' => $this->excerpt,
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_parent' => 0,
            'menu_order' => 0,
            'post_date' => current_time( 'mysql' ),
            'post_date_gmt' => current_time( 'mysql', 1 ),
            'post_modified' => current_time( 'mysql' ),
            'post_modified_gmt' => current_time( 'mysql', 1 ),
        );

        $post = new WP_Post( (object) $postarr );
        $post->filter = 'raw';

        // Normalize flags so themes don’t treat it as archive/search/home
        $query->is_404        = false;
        $query->is_home       = false;
        $query->is_front_page = false;
        $query->is_posts_page = false;
        $query->is_archive    = false;
        $query->is_search     = false;
        $query->is_single     = false;

        $query->is_page      = true;
        $query->is_singular  = true;

        // Populate query counts
        $query->found_posts   = 1;
        $query->post_count    = 1;
        $query->max_num_pages = 1;

        // Also set core queried object fields (helps classic themes + shortlink)
        $query->queried_object    = $post;
        $query->queried_object_id = (int) $post->ID;

        return array( $post );
    }

    private $didInjectFallback = false;

    public function injectVirtualContentIntoTemplateOnce( $block_content, $block ) {
        $type = get_query_var( iHomefinderConstants::IHF_TYPE_URL_VAR );
        if ( empty( $type ) ) {
            return $block_content;
        }

        // Only do this fallback once per request.
        if ( $this->didInjectFallback ) {
            return $block_content;
        }

        global $wp_query;
        $this->initialize( $wp_query );

        if ( ! $this->initialized ) {
            return $block_content;
        }

        // If the template already has Post Content, don't interfere.
        if ( ! empty( $block['blockName'] ) && $block['blockName'] === 'core/post-content' ) {
            return $block_content;
        }

        // Fallback target: templates that render a Query Loop inheriting the main query
        // (common in some “page templates” that behave like archives).
        if ( ! empty( $block['blockName'] ) && $block['blockName'] === 'core/query' ) {
            $attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();
            $q     = isset( $attrs['query'] ) ? $attrs['query'] : array();

            if ( ! empty( $q['inherit'] ) ) {
                $this->didInjectFallback = true;
                return do_blocks( $this->content );
            }
        }

        return $block_content;
    }
}
