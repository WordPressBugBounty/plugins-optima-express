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

        // With real CPT posts WordPress sets is_singular, is_404=false, queried_object
        // etc. naturally — no manual flag overrides needed here.

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
        if ( isset( $query->post ) && $query->post instanceof WP_Post ) {
            wp_cache_delete( $query->post->ID, 'posts' );
            wp_cache_delete( $query->post->ID, 'post_meta' );
        }
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
        "DONOTCACHEOBJECT"
        );
        foreach ($constants as $constant) {
            if (!defined($constant)) {
                define($constant, true);
            }
        }
    }
    
    /**
     * Defines caching-suppression constants as early as possible so that
     * caching plugins (WP Super Cache, W3 Total Cache, etc.) that inspect
     * DONOTCACHEPAGE during 'init' or 'template_redirect' see the flag in time.
     *
     * Hooked to 'parse_request' (priority 1) — fires after rewrite rules have
     * been applied and query vars are populated, but before any caching plugin
     * template_redirect checks run.
     *
     * @param WP $wp
     */
    public function earlyRemoveCaching($wp)
    {
        if (empty($wp->query_vars[iHomefinderConstants::IHF_TYPE_URL_VAR])) {
            return;
        }

        $this->removeCaching(); // DONOTCACHEPAGE etc.

        // LiteSpeed Cache (6M+ installs) — has its own action-based suppression API;
        // ignores both DONOTCACHEPAGE constants and HTTP headers set via PHP.
        do_action('litespeed_control_set_nocache', 'ihf_virtual_page');

        // Sets Cache-Control: no-cache, no-store, must-revalidate + Pragma/Expires.
        // Respected by WP Engine (Nginx/EverCache), Kinsta, SiteGround (Nginx FastCGI),
        // and CDN edge caches. PHP constants are invisible to these server-level caches.
        nocache_headers();

        // Cloudflare APO serves pages from edge nodes and does not reliably respect
        // Cache-Control from origin — this header is its explicit cache bypass signal.
        header('CF-Edge-Cache: no-cache');

        // Fastly and some Varnish-based hosts use Surrogate-Control instead of
        // Cache-Control to control edge/proxy caching behavior.
        header('Surrogate-Control: no-store');

        // Nginx proxy_cache respects X-Accel-Expires; setting it to 0 prevents
        // caching on hosts running Nginx as a reverse proxy.
        header('X-Accel-Expires: 0');
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
            // Include single.php because ihf_virtual_page is a CPT, and block
            // themes default to single-{post_type}.html → single.html hierarchy
            // rather than page.html for custom post types.
            $candidates[] = 'page.php';
            $candidates[] = 'single.php';
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

                // Prefer page.html (most themes have this and it gives the right layout).
                $maybe = locate_block_template( $phpTemplate, $blockCandidateSlug, $candidates );
                if ( ! empty( $maybe ) ) {
                    return $maybe;
                }

                // CPT fallback: block themes that don't have page.html will have
                // single.html (the default for custom post types).
                $maybe = locate_block_template( $phpTemplate, 'single', $candidates );
                if ( ! empty( $maybe ) ) {
                    return $maybe;
                }
            }

            // Fallback: use the best PHP template we found (or the original template).
            return $phpTemplate ?: $template;
        }

        // Classic theme behavior.
        if ( ! empty( $virtualPageTemplate ) ) {
            // A plugin-supplied template (templates/ihf-divi-full-width.php) is
            // selected by file name like a theme template, but locate_template()
            // only searches the child and parent theme, so resolve it here first.
            // Classic themes only: the template calls get_header()/get_footer(),
            // which a block theme can only satisfy with WordPress's theme-compat
            // files, and the dropdown offers it on Divi alone.
            $pluginTemplate = $this->locatePluginTemplate( $virtualPageTemplate );
            if ( ! empty( $pluginTemplate ) ) {
                return $pluginTemplate;
            }

            $found = locate_template( array( $virtualPageTemplate ) );
            if ( ! empty( $found ) ) {
                return $found;
            }
        }

        // Prefer page.php over single.php: classic themes render post-entry
        // metadata (date, author, categories) on singles but not on pages.
        // singular.php sits between them because it is what WordPress itself
        // picks for a page on a theme with no page.php (Twenty Twenty, for one);
        // without it such a theme fell through to index.php.
        $found = locate_template( array( 'page.php', 'singular.php', 'single.php', 'index.php' ) );
        return $found ?: $template;
    }

    /**
     * Resolves the admin-selected template name to a file shipped in the plugin's
     * templates/ directory, or '' when the plugin ships no file by that name.
     *
     * basename() drops any directory component of the stored option value, and
     * is_file() rejects what basename() lets through ('.', '..', ''), so only a
     * regular file inside templates/ can be returned.
     *
     * @param  string $virtualPageTemplate  Template file name from the OE page-template setting.
     * @return string
     */
    private function locatePluginTemplate( $virtualPageTemplate ) {
        if ( empty( $virtualPageTemplate ) ) {
            return '';
        }

        $pluginTemplate = plugin_dir_path( __FILE__ ) . 'templates/' . basename( $virtualPageTemplate );
        return is_file( $pluginTemplate ) ? $pluginTemplate : '';
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

        // WordPress has already resolved the real ihf_virtual_page CPT post and
        // populated $wp_query->post.  We set $GLOBALS['post'] for theme compatibility
        // and update the title so get_the_title() returns the dynamic value.
        if ( isset( $wp_query->post ) && $wp_query->post instanceof WP_Post ) {
            if ( ! empty( $this->title ) ) {
                $wp_query->post->post_title = $this->title;
                if ( $wp_query->queried_object instanceof WP_Post ) {
                    $wp_query->queried_object->post_title = $this->title;
                }
            }
            $GLOBALS['post'] = $wp_query->post;
        }

        // Classic theme compatibility: CPT posts are is_singular but not is_page.
        // Many classic themes gate their singular layout on is_page(), so patch it.
        $wp_query->is_page = true;
    }

    /**
     * Returns the dynamic virtual-page title (e.g. the listing address) for
     * any call to the_title() / get_the_title().  Belt-and-suspenders on top
     * of the post_title mutation in setupVirtualGlobals() — covers SEO plugins
     * and themes that call get_the_title() before the 'wp' action runs.
     *
     * @param  string $title
     * @param  int    $postId
     * @return string
     */
    public function filterTitle( $title, $postId ) {
        if ( ! get_query_var( iHomefinderConstants::IHF_TYPE_URL_VAR ) ) {
            return $title;
        }

        // Only replace the title for the actual queried IDX post, not for
        // menu items, widgets, or other posts rendered on the same page.
        if ( (int) $postId !== (int) get_queried_object_id() ) {
            return $title;
        }

        global $wp_query;
        $this->initialize( $wp_query );

        return ( $this->initialized && ! empty( $this->title ) ) ? $this->title : $title;
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
