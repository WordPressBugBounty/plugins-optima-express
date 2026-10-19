<?php

/**
 * Suppresses conflicting SEO plugin output on iHomefinder virtual pages.
 *
 * SEO plugins (Yoast, AIOSEO, Rank Math, Squirrly, SEOPress) inject meta tags and
 * rewrite the document title, overriding iHomefinder's dynamic content (listing
 * address, photo, URL) on virtual pages. Worse, they derive canonical/og:url from
 * the virtual page's synthetic post, producing URLs that do not exist (404s when
 * social crawlers follow them). This class detects iHomefinder virtual pages and
 * suppresses each SEO plugin's head output AND document title filtering, leaving
 * iHomefinder's own title and meta tags as the sole SEO output.
 *
 * Detection: get_query_var('ihf-type') is set by iHomefinder rewrite rules for all
 * virtual pages and is populated by WP core query parsing before the 'wp' action fires.
 *
 * Hook timing: registered on 'wp' at priority 0 to ensure filters are in place before
 * Rank Math initializes its frontend (priority 10), AIOSEO registers title hooks
 * ('wp' priority 1000), and Squirrly starts its output buffer (template_redirect).
 * Yoast's wpseo_head filter fires during wp_head — also covered.
 *
 * Note: __return_empty_string requires WP 5.1+. iHomefinder minimum is WP 4.2,
 * so inline closures are used throughout.
 */
class iHomefinderSeoCompat
{
    private static $instance;

    private function __construct()
    {
    }

    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function initialize()
    {
        add_action('wp', function () {
            if (!get_query_var(iHomefinderConstants::IHF_TYPE_URL_VAR)) {
                return;
            }

            // Respect the admin setting — default is enabled (suppress SEO plugins).
            // Stored as 'false' only when explicitly unchecked; absent or 'true' means enabled.
            if (get_option(iHomefinderConstants::SEO_COMPAT_OPTION, 'true') === 'false') {
                return;
            }

            // Yoast SEO — remove the wp_head callback entirely.
            // wpseo_head is a do_action(), not apply_filters(), so add_filter() has no effect.
            // The output comes from Front_End_Integration::present_head() hooked to the wpseo_head
            // action at priority -9999. Removing the wp_head callback prevents the action from
            // firing at all.
            if (function_exists('YoastSEO')) {
                $frontend = YoastSEO()->classes->get(
                    \Yoast\WP\SEO\Integrations\Front_End_Integration::class
                );
                remove_action('wp_head', array($frontend, 'call_wpseo_head'), 1);

                // Yoast rewrites the document <title> separately from wpseo_head, via
                // filter_title on pre_get_document_title / wp_title at priority 15.
                // Left in place, it builds the title from Yoast's indexable — which is
                // shared across all virtual pages and can carry another page's title.
                remove_filter('pre_get_document_title', array($frontend, 'filter_title'), 15);
                remove_filter('wp_title', array($frontend, 'filter_title'), 15);

                // Yoast removes WP core's title renderers in register_hooks() and renders
                // the <title> itself inside wpseo_head. With both suppressed, no <title>
                // would be output at all — restore the core renderers. _wp_render_title_tag
                // self-guards on title-tag theme support, but _block_template_render_title_tag
                // echoes unconditionally (core only hooks it while rendering a block
                // template), so it must be gated on the theme actually being a block theme.
                // Classic themes with a hardcoded <title> need neither: they render their
                // own tag via wp_title(), which is correct once Yoast's filter is removed.
                add_action('wp_head', '_wp_render_title_tag', 1);
                if (function_exists('wp_is_block_theme') && wp_is_block_theme()
                    && function_exists('_block_template_render_title_tag')
                ) {
                    add_action('wp_head', '_block_template_render_title_tag', 1);
                }
            }

            // All in One SEO (AIOSEO) — canonical disable flag. Evaluated lazily by AIOSEO
            // ('wp' priority 1000 for title hooks, wp_head priority 1 for head output), so
            // registering here at 'wp' priority 0 is in time. Covers title, description,
            // canonical, robots, og/twitter and JSON-LD.
            add_filter('aioseo_disable', function () { return true; }, PHP_INT_MAX);

            // AIOSEO's AMP head output bypasses the aioseo_disable gate (it binds
            // Head::output() directly to amp_post_template_head). Gate it separately.
            add_filter('aioseo_enable_amp_social_meta', function () { return false; }, PHP_INT_MAX);

            // Rank Math — suppress all frontend integration (Head, OpenGraph, Twitter, Schema).
            // rank_math/frontend/disable_head does not exist; disable_integration is the correct hook.
            // Note: this also suppresses Rank Math breadcrumb/JSON-LD in page body on virtual pages.
            add_filter('rank_math/frontend/disable_integration', function () { return true; }, PHP_INT_MAX);

            // Squirrly SEO — disable its page output buffer.
            // Squirrly does not emit tags via wp_head; it buffers the entire page from
            // template_redirect (SQ_Controllers_Frontend::hookBuffer -> startBuffer) and
            // rewrites the title/meta/og tags inside the buffer. The sq_load_buffer filter
            // is applied inside hookBuffer, so registering here (before template_redirect)
            // prevents the rewrite entirely. Verified against Squirrly SEO 14.1.1.
            add_filter('sq_load_buffer', function () { return false; }, PHP_INT_MAX);

            // SEOPress — sweep all frontend hooks by namespace.
            // SEOPress has no single disable filter. It registers 20+ hooks across named
            // functions (seopress_*) and OOP class methods (SEOPress\* namespace) spanning
            // wp_head (meta, canonical, schema, site verification), wp_footer/wp_body_open
            // (analytics, Matomo, custom tracking), wp_enqueue_scripts (JS/CSS assets), and
            // template_redirect (archive redirects). Sweeping by namespace catches all of
            // these — including future additions — without enumerating each hook individually.
            // pre_get_document_title / wp_title are included as hardening: SEOPress's title
            // filter (seopress_titles_the_title) currently registers from a lazy loader on
            // wp_head priority 0 that this sweep already removes, but sweeping the title
            // hooks directly protects against it registering earlier in future versions.
            // Verified against SEOPress 9.8.4 source.
            $seopress_hooks = ['wp_head', 'wp_footer', 'wp_body_open', 'wp_enqueue_scripts', 'template_redirect', 'pre_get_document_title', 'wp_title'];
            foreach ($seopress_hooks as $hook) {
                if (empty($GLOBALS['wp_filter'][$hook])) {
                    continue;
                }
                foreach ($GLOBALS['wp_filter'][$hook]->callbacks as $priority => $callbacks) {
                    foreach ($callbacks as $callback) {
                        $fn   = $callback['function'];
                        $name = is_string($fn) ? $fn
                              : (is_array($fn) && is_object($fn[0]) ? get_class($fn[0]) : '');
                        if (stripos($name, 'seopress') !== false) {
                            remove_action($hook, $fn, $priority);
                        }
                    }
                }
            }

            // Universal title guard — runs after every SEO plugin's title filter.
            // Returning '' from pre_get_document_title tells WordPress no plugin
            // short-circuited the title, so core builds the native document title from
            // the virtual page's own post title. This guarantees iHomefinder's title
            // wins even if a plugin's title filter is missed above (renamed hooks in a
            // future plugin version, or an SEO plugin not handled here at all).
            add_filter('pre_get_document_title', function () { return ''; }, PHP_INT_MAX);
        }, 0);
    }
}
