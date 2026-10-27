<?php

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


class iHomefinderInstaller
{
    
    private static $instance;
    private $utility;
    private $rewriteRules;
    private $admin;
    private $displayRules;
    
    private function __construct()
    {
        $this->utility = iHomefinderUtility::getInstance();
        $this->rewriteRules = iHomefinderRewriteRules::getInstance();
        $this->admin = iHomefinderAdmin::getInstance();
        $this->displayRules = iHomefinderDisplayRules::getInstance();
    }
    
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Installs the Optima Express plugin and initializes rewrite rules.
     *
     * @return void
     */
    public function install()
    {
        $this->deleteOldOptions();
        $postType = iHomefinderPostType::getInstance();
        $postType->register();
        $postType->createPosts();
        $this->rewriteRules->initialize();
        $this->rewriteRules->flushRules();
    }
    
    /**
     * Removes Optima Express plugin related information on deactivation.
     *
     * Clears every cron event the plugin schedules (the retry, the shared user demotion, the
     * daily retirement evaluation and the legacy network sweep) and every per-blog transient it
     * owns, and revokes this blog's service credential so a deactivated plugin leaves no live
     * application password behind. The shared user's password row is never written here.
     * Runs for the current blog only: a network-wide deactivation fires once, from the network
     * admin, and does not visit every sub-site.
     *
     * @param bool $networkWide passed by WordPress; unused
     * @return void
     */
    public function remove($networkWide = false)
    {
        //Clear out any rewrite rules associated with the plugin
        $this->rewriteRules->flushRules();
        //Drop queued events rather than leaving them in the cron array
        wp_clear_scheduled_hook(iHomefinderConstants::BLOG_PROVISION_RETRY_CRON_HOOK);
        wp_clear_scheduled_hook(iHomefinderConstants::SHARED_DEMOTE_CRON_HOOK);
        wp_clear_scheduled_hook(iHomefinderConstants::SHARED_RETIRE_CRON_HOOK);
        wp_clear_scheduled_hook(iHomefinderConstants::NETWORK_PROVISION_CRON_HOOK);
        $blogId = get_current_blog_id();
        delete_transient(iHomefinderAdmin::BLOG_PROVISION_RETRY_TRANSIENT . $blogId);
        delete_transient(iHomefinderAdmin::BLOG_CREDENTIAL_VERIFY_TRANSIENT . $blogId);
        delete_transient(iHomefinderAdmin::SIGNED_REACTIVATE_TRANSIENT . $blogId);
        $this->admin->revokeServiceCredentials('deactivation');
    }

    /**
     * Update authentication and rewrite information after upgrade.
     *
     * Compares VERSION_OPTION with VERSION as every release does and, on a change, runs the
     * one-time work under the per-blog provisioning lock so two concurrent first requests after
     * an update (two admin tabs, an admin request and a visitor, two web nodes) run it once:
     * the loser returns without stamping and the next request finds the version stamped. The
     * version is stamped when the block completes. The provisioning call that 8.7.7 made from
     * here runs after the lock is released (provisionBlogIntegration() takes the lock itself)
     * and only from a logged-in, non-ajax admin request, which first records its REST base as
     * the target so the first send does not fall back to the home scheme; a front-end, REST or
     * cron request sets the pending marker only, so a migration never runs inside a visitor
     * render or inside ihf-root's own REST call.
     *
     * @return void
     */
    public function upgrade()
    {
        $currentVersion = get_option(iHomefinderConstants::VERSION_OPTION, null);
        if ($currentVersion === iHomefinderConstants::VERSION) {
            return;
        }
        $lockToken = $this->admin->acquireProvisionLock();
        if ($lockToken === null) {
            // Another request is running the one-time block (or provisioning). It stamps the
            // version; if it fails to, the next request repeats this compare.
            return;
        }
        $provision = false;
        try {
            // Re-read the version from the database now that the lock is held: the holder that
            // just released it may have stamped after this request read its cached copy.
            $this->admin->clearProvisionOptionCaches(array(iHomefinderConstants::VERSION_OPTION));
            $currentVersion = get_option(iHomefinderConstants::VERSION_OPTION, null);
            if ($currentVersion === iHomefinderConstants::VERSION) {
                return;
            }
            $this->deleteOldOptions();
            $this->addStyleTagsToCssOverride();
            $postType = iHomefinderPostType::getInstance();
            $postType->register();
            $postType->createPosts();
            flush_rewrite_rules();
            // One-time cache flush for the CPT migration. Sites upgrading from pre-CPT
            // versions may have IDX pages cached as static files (W3TC Disk Enhanced,
            // WP Super Cache Expert mode); PHP never runs for those requests so the
            // per-request suppression mechanisms are bypassed entirely. Flush here to
            // clear the slate immediately on upgrade.
            // This condition fires once when upgrading from any pre-9.0.0 version, then never again.
            if (!empty($currentVersion) && version_compare($currentVersion, '9.0.0', '<')) {
                $this->flushPageCache();
            }
            $this->clearLegacyNetworkProvisionEvent();
            if (!$this->utility->isDatabaseCached() && $this->admin->previouslyActivated()) {
                if (is_admin() && !wp_doing_ajax() && is_user_logged_in()) {
                    // Same rule as the admin_init scheduler: record this request's REST base as
                    // the target before provisioning, so the first post-update send carries it
                    // rather than the fallback built from the home scheme.
                    $this->admin->writeRestBaseTargetFromThisRequest();
                    $provision = true;
                } else {
                    // A front-end, REST or cron request marks the blog and leaves the round trip
                    // to the next admin request or the retry event. Not activated: the marker is
                    // what provisionBlogIntegration() would have written anyway.
                    update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, "1");
                }
                $this->rewriteRules->initialize();
                $this->rewriteRules->flushRules();
            }
            update_option(iHomefinderConstants::VERSION_OPTION, iHomefinderConstants::VERSION);
        } finally {
            $this->admin->releaseProvisionLock($lockToken);
        }
        if (!$provision) {
            return;
        }
        try {
            if ($this->admin->isActivated()) {
                $this->admin->provisionBlogIntegration();
            } else {
                $this->admin->activateAuthenticationToken();
            }
        } catch (Throwable $e) {
            // Throwable, not Exception: a TypeError in the provisioning path must not escape an
            // admin_init handler. The version is already stamped, so this upgrade will not run
            // again and cannot retry provisioning itself; provisionBlogIntegration() leaves its
            // pending marker set and the admin_init scheduler or the retry event takes it from
            // here. The message can name the service host but never a credential.
            error_log(sprintf('[Optima Express] upgrade provisioning failed for blog %d: %s', get_current_blog_id(), $e->getMessage()));
        }
    }

    /**
     * Clears the legacy network provisioning sweep event (ihf_oe_network_provision) once per
     * network. The event was scheduled from network admin requests, whose cron array is the
     * main site's, so it is cleared there whichever blog runs the upgrade first. Gated by a
     * network option so the switch happens once, not on every blog's first request.
     *
     * @return void
     */
    private function clearLegacyNetworkProvisionEvent()
    {
        if (!is_multisite()) {
            return;
        }
        if (!empty(get_site_option(iHomefinderConstants::NETWORK_PROVISION_CLEARED_SITE_OPTION, null))) {
            return;
        }
        $mainSiteId = (int) get_main_site_id();
        if ($mainSiteId > 0 && $mainSiteId !== get_current_blog_id()) {
            switch_to_blog($mainSiteId);
            try {
                wp_clear_scheduled_hook(iHomefinderConstants::NETWORK_PROVISION_CRON_HOOK);
            } finally {
                restore_current_blog();
            }
        } else {
            wp_clear_scheduled_hook(iHomefinderConstants::NETWORK_PROVISION_CRON_HOOK);
        }
        update_site_option(iHomefinderConstants::NETWORK_PROVISION_CLEARED_SITE_OPTION, time());
    }

    /**
     * Proactively flush all major page cache plugins on upgrade.
     * Covers static-file caches (W3TC Disk Enhanced, WP Super Cache Expert) that
     * PHP-level suppression cannot reach on subsequent requests.
     *
     * @return void
     */
    private function flushPageCache()
    {
        // W3 Total Cache
        do_action('w3tc_flush_all');

        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // LiteSpeed Cache
        do_action('litespeed_purge_all');

        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // Breeze (Cloudways)
        do_action('breeze_clear_all_cache');

        // Autoptimize — clears its own CSS/JS/HTML cache
        do_action('autoptimize_action_cachepurged');

        // SG Optimizer (Speed Optimizer by SiteGround) — no-ops silently on non-SiteGround hosts
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
        }

        // Varnish HTTP Purge (Proxy Cache Purge) — sends an HTTP PURGE request to the server
        if (class_exists('VarnishPurger') && method_exists('VarnishPurger', 'purge_url')) {
            VarnishPurger::purge_url(home_url('/?vhp-regex'));
        }

        // Hummingbird (WPMU DEV) — no-op if page cache module is disabled in settings
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }

        // Cache Enabler (KeyCDN) — clears static HTML files on disk
        if (class_exists('Cache_Enabler')) {
            do_action('cache_enabler_clear_complete_cache');
        }

        // NitroPack — makes a synchronous outbound call to the NitroPack SaaS API
        if (function_exists('nitropack_sdk_purge')) {
            nitropack_sdk_purge(null, null, 'ihf_cpt_migration');
        }

        // Comet Cache (formerly ZenCache)
        if (class_exists('comet_cache') && method_exists('comet_cache', 'clear')) {
            comet_cache::clear();
        }

        // Swift Performance (Lite and Pro)
        if (class_exists('Swift_Performance_Cache') && method_exists('Swift_Performance_Cache', 'clear_all_cache')) {
            Swift_Performance_Cache::clear_all_cache();
        }

        // WP Engine EverCache — method name is legacy ("varnish") but works on current Nginx
        // infrastructure. Does NOT flush their CDN layer (Advanced Network/GES); that
        // requires a portal action or the WP Engine REST API.
        if (class_exists('WpeCommon')) {
            if (method_exists('WpeCommon', 'purge_memcached')) {
                WpeCommon::purge_memcached();
            }
            if (method_exists('WpeCommon', 'purge_varnish_cache')) {
                WpeCommon::purge_varnish_cache();
            }
        }
    }
    
    private function deleteOldOptions()
    {
        $options = array(
        "ihf_email_updates_enabled",
        "ihf_save_listing_enabled",
        "ihf_hotsheet_enabled",
        "ihf_featured_properties_enabled",
        "ihf_organizer_enabled",
        "ihf_gallery_shortcodes_enabled",
        "ihf_office_enabled",
        "ihf_agent_bio_enabled",
        "ihf_sold_pending_enabled",
        "ihf_valuation_enabled",
        "ihf_contact_form_enabled",
        "ihf_supplemental_listings_enabled",
        "ihf_map_search_enabled",
        "ihf_seo_city_links_enabled",
        "ihf_community_pages_enabled",
        "ihf_pending_account",
        "ihf_active_trial_account",
        iHomefinderConstants::COMPATIBILITY_CHECK_ENABLED,
        );
        foreach ($options as $option) {
            delete_option($option);
        }
    }
    
    private function addStyleTagsToCssOverride()
    {
        $migrated = get_option(iHomefinderConstants::CSS_OVERRIDE_MIGRATED, false);
        if (!$migrated) {
            $cssOverride = get_option(iHomefinderConstants::CSS_OVERRIDE_OPTION, null);
            update_option(iHomefinderConstants::CSS_OVERRIDE_OPTION, "<style type=\"text/css\">\n" . $cssOverride . "\n</style>");
            update_option(iHomefinderConstants::CSS_OVERRIDE_MIGRATED, true);
        }
    }
}
