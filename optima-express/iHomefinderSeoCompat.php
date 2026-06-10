<?php

/**
 * Suppresses conflicting SEO plugin head output on iHomefinder virtual pages.
 *
 * SEO plugins (Yoast, AIOSEO, Rank Math, Squirrly, SEOPress) inject meta tags that override
 * iHomefinder's dynamic content (listing address, photo, URL) on virtual pages.
 * This class detects iHomefinder virtual pages and suppresses each SEO plugin's
 * head output, leaving iHomefinder's own meta tags as the sole SEO output.
 *
 * Detection: get_query_var('ihf-type') is set by iHomefinder rewrite rules for all
 * virtual pages and is populated by WP core query parsing before the 'wp' action fires.
 *
 * Hook timing: registered on 'wp' at priority 0 to ensure filters are in place before
 * Rank Math initializes its frontend (priority 10) and AIOSEO registers title hooks
 * (priority 1000). Yoast's wpseo_head filter fires during wp_head — also covered.
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
            }

            // All in One SEO (AIOSEO) — canonical disable flag.
            add_filter('aioseo_disable', function () { return true; }, PHP_INT_MAX);

            // Rank Math — suppress all frontend integration (Head, OpenGraph, Twitter, Schema).
            // rank_math/frontend/disable_head does not exist; disable_integration is the correct hook.
            // Note: this also suppresses Rank Math breadcrumb/JSON-LD in page body on virtual pages.
            add_filter('rank_math/frontend/disable_integration', function () { return true; }, PHP_INT_MAX);

            // Squirrly SEO — suppress og and seo output via filters.
            // Fallback remove_action targets SQ_Models_Frontend::init on wp_head.
            // Class/method names are taken from partner documentation and have not been verified
            // against an installed Squirrly version. The class_exists/method_exists guards ensure
            // silent-but-safe failure if names differ.
            add_filter('sq_og_enabled', function () { return false; }, PHP_INT_MAX);
            add_filter('sq_seo_enabled', function () { return false; }, PHP_INT_MAX);
            if (class_exists('SQ_Classes_ObjController')) {
                $sqFrontend = SQ_Classes_ObjController::getClass('SQ_Models_Frontend');
                if (is_object($sqFrontend) && method_exists($sqFrontend, 'init')) {
                    remove_action('wp_head', array($sqFrontend, 'init'), 1);
                }
            }

            // SEOPress — sweep all frontend hooks by namespace.
            // SEOPress has no single disable filter. It registers 20+ hooks across named
            // functions (seopress_*) and OOP class methods (SEOPress\* namespace) spanning
            // wp_head (meta, canonical, schema, site verification), wp_footer/wp_body_open
            // (analytics, Matomo, custom tracking), wp_enqueue_scripts (JS/CSS assets), and
            // template_redirect (archive redirects). Sweeping by namespace catches all of
            // these — including future additions — without enumerating each hook individually.
            // Verified against SEOPress 9.8.4 source.
            $seopress_hooks = ['wp_head', 'wp_footer', 'wp_body_open', 'wp_enqueue_scripts', 'template_redirect'];
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
        }, 0);
    }
}
