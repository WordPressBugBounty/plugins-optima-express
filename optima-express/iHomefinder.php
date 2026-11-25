<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Plugin Name: Optima Express IDX
 * Plugin URI: http://wordpress.org/extend/plugins/optima-express/
 * Description: Adds MLS / IDX property search and listings to your site.
 * Includes search and listing pages, widgets and shortcodes.
 * Requires an IDX account from iHomefinder.
 * Get a paid account with data from your MLS.
 * Version: 9.0.3
 * Author: ihomefinder
 * Author URI: http://www.ihomefinder.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * PHP Version: 7.4
 *
 * @category WordPress_Plugin
 * @package  OptimaExpress
 * @author   iHomefinder <support@ihomefinder.com>
 * @license  GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link     http://www.ihomefinder.com
 */

require "iHomefinderAutoloader.php";

// Operator tunables for the per-site blog credential model. Each may be set in wp-config.php;
// these are the defaults. IHF_BLOG_MIGRATION_DISABLED (the absolute kill switch), IHF_SERVICE_HOST
// (dev-only host override) and IHF_SSLVERIFY (certificate verification opt-out for a host with a
// broken CA bundle; see iHomefinderRequestor::sslVerify()) have no default and are only ever
// read with defined().
if (!defined('IHF_SHARED_RETIRE_ZERO_DAYS')) {
    // Consecutive daily zero-membership readings before the shared user's passwords are purged.
    define('IHF_SHARED_RETIRE_ZERO_DAYS', 7);
}
if (!defined('IHF_SIGNED_PING_WINDOW')) {
    // Seconds either side of now within which a signed cache-clear ping's timestamp is accepted.
    define('IHF_SIGNED_PING_WINDOW', 300);
}

$autoloader = iHomefinderAutoloader::getInstance();
$installer = iHomefinderInstaller::getInstance();
$rewriteRules = iHomefinderRewriteRules::getInstance();
$admin = iHomefinderAdmin::getInstance();
$shortcodeSelector = iHomefinderShortcodeSelectorTinyMce::getInstance();
$shortcodeDispatcher = iHomefinderShortcodeDispatcher::getInstance();
$stateManager = iHomefinderStateManager::getInstance();
$enqueueResource = iHomefinderEnqueueResource::getInstance();
$virtualPageDispatcher = iHomefinderVirtualPageDispatcher::getInstance();
$displayRules = iHomefinderDisplayRules::getInstance();
$ajaxHandler = iHomefinderAjaxHandler::getInstance();

//Runs when plugin is activated
register_activation_hook(__FILE__, array($installer, "install"));
//Runs on plugin deactivation
register_deactivation_hook(__FILE__, array($installer, "remove"));

//Runs just before the auto upgrader installs the plugin
add_filter("upgrader_post_install", array($installer, "upgrade"), 10, 2);

// Repairs a blog whose persisted rewrite rules no longer route IDX URLs (MAX-4695).
// Registered unconditionally: the same check runs from the admin_init tick, but that one
// wants a signed-in admin and an hourly claim, so a customer site nobody signs into would
// serve 404s to visitors until someone did. wp_loaded is after the init hook that registers
// the post type and the current rules, so a flush from there writes a complete rule set.
// Late priority on purpose: WP_Rewrite::flush_rules() defers any flush requested before
// wp_loaded to wp_loaded itself, and those deferred callbacks land at priority 10. Running
// after them means this reads the rules a flush earlier in the request has already written,
// rather than the pre-request copy, and so does not repeat a flush that is already queued.
add_action("wp_loaded", array($admin, "healRewriteRulesOnLoad"), 99);

// Deferred blog-provisioning retry, scheduled from front-end requests so the blocking
// round trip runs in the cron request rather than in front of a visitor's page render.
// Registered unconditionally: WP-Cron fires in its own request, which is not is_admin().
// Bound to the cron runner, not the admin_init scheduler: a cron request has no user, and the
// scheduler's login test would have made the event a no-op.
add_action(iHomefinderConstants::BLOG_PROVISION_RETRY_CRON_HOOK, array($admin, 'runBlogProvisioningRetry'));

// Shared user demotion (per blog, once its per-site credential is confirmed and the grace has
// passed) and the daily network retirement evaluation. Both are cron handlers; the hourly tick
// schedules them and the demoter also runs as the tick's fallback.
add_action(iHomefinderConstants::SHARED_DEMOTE_CRON_HOOK, array($admin, 'demoteSharedUserIfDue'));
add_action(iHomefinderConstants::SHARED_RETIRE_CRON_HOOK, array($admin, 'runSharedRetireCron'));

// A changed home or site URL marks the blog for a REST base re-sync; the hourly tick's
// get_rest_url() comparison is the primary detector, these are the belt.
add_action('update_option_home', array($admin, 'markBlogResyncPending'));
add_action('update_option_siteurl', array($admin, 'markBlogResyncPending'));

// The one sanctioned option hook: clearing the registration key to an empty value, by any
// writer, revokes this blog's service credential. It never activates anything.
add_action('update_option_' . iHomefinderConstants::ACTIVATION_TOKEN_OPTION, array($admin, 'handleActivationTokenUpdated'), 10, 2);

if (is_multisite()) {
    // A sub-site whose domain or path changed is marked for a REST base re-sync.
    add_action('wp_update_site', array($admin, 'handleSiteUpdated'), 10, 2);

    // A sub-site restored to service (un-archived, un-spammed, undeleted) that still runs on
    // the shared credential is flagged for rotation now: while retired it was excluded from
    // the retirement count and its shared password may already be gone.
    add_action('unarchive_blog', array($admin, 'handleBlogRestored'));
    add_action('make_ham_blog', array($admin, 'handleBlogRestored'));
    add_action('make_undelete_blog', array($admin, 'handleBlogRestored'));

    // Provision new sub-sites when they are created, if an activation token is already set.
    add_action('wp_initialize_site', function ($new_site) {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!is_plugin_active_for_network(plugin_basename(__FILE__))) {
            return;
        }
        switch_to_blog($new_site->blog_id);
        try {
            $activationToken = get_option(iHomefinderConstants::ACTIVATION_TOKEN_OPTION);
            if (!empty($activationToken)) {
                iHomefinderAdmin::getInstance()->activateAuthenticationToken();
            }
        } catch (Exception $e) {
            error_log(sprintf(
                '[Optima Express] New site provisioning failed for site %d: %s',
                $new_site->blog_id,
                $e->getMessage()
            ));
        } finally {
            restore_current_blog();
        }
    });
}

//disable JetPack"s OG tags
add_filter("jetpack_enable_open_graph", "__return_false");

//uncomment during development, so rule changes can be viewed.
//in production this should not run, because it is a slow operation.
//add_action("init", array($rewriteRules, "flushRules"));

//Rewrite Rules
add_action("init", array($rewriteRules, "initialize"), 1);

// REST API — registered outside is_admin() because WordPress sets is_admin()
// to false for REST requests, so the hook would never fire if placed inside it.
add_action("rest_api_init", function() {
    iHomefinderRestController::getInstance()->registerRoutes();
});

// Service account guard: scopes every user carrying ihf_service_account (this site's own
// Optima Express service user, and the legacy shared one until the site migrates) to the four
// REST calls blog posting needs, closes its login surfaces, restricts its uploads to images and
// reserves its login names. Registered unconditionally: REST, XML-RPC and the login form are
// not is_admin(), and the hooks are read-only tests that cost one user meta read.
iHomefinderServiceAccountGuard::getInstance()->register();

// CPT — registered outside is_admin() for two reasons:
// 1. register_post_type() must run on every request (admin and front-end) so
//    WordPress's query pipeline, get_posts(), and wp_insert_post() all know the type.
// 2. deleted_post and trashed_post fire in WP-CLI (which sets is_admin()=true),
//    the primary path by which a developer would accidentally delete a virtual page.
$postType = iHomefinderPostType::getInstance();
add_action("init", array($postType, "register"), 0);
add_action("deleted_post", array($postType, "recreateDeletedPost"), 10, 2);
add_action("trashed_post", array($postType, "handleTrashedPost"), 10, 1);

if (is_admin()) {
    // Recover sites whose blog provisioning never completed. upgrade() attempts it only on
    // a version change and stamps the version first, so a failed attempt is never retried
    // by it. Admin requests only: provisioning makes a blocking HTTP call with a 200s
    // timeout, which must never sit in front of a visitor-facing page render.
    //
    // Priority 19 is the hourly claimed tick: it notices a credential that was deleted out
    // from under us, a shared-way re-provision by an older node, REST base drift, and a
    // demotion that is due, and marks the blog, so the runner at priority 20 picks the work
    // up in this same request. Both return on ajax and anonymous requests themselves.
    add_action("admin_init", array($admin, "runHeadlessBlogChecks"), 19);
    add_action("admin_init", array($admin, "retryBlogProvisioningIfPending"), 20);

    // Register page actions (admin-post.php): register a key, refresh the registration,
    // unregister, reset the blog connection. Authenticated hooks only, never the anonymous variant;
    // each handler checks its own nonce and manage_options. The page registers no settings
    // group, so options.php never touches either token (MAX-4432).
    add_action("admin_post_" . iHomefinderAdmin::REGISTER_KEY_ACTION, array($admin, "handleRegisterKey"));
    add_action("admin_post_" . iHomefinderAdmin::REFRESH_REGISTRATION_ACTION, array($admin, "handleRefreshRegistration"));
    add_action("admin_post_" . iHomefinderAdmin::UNREGISTER_ACTION, array($admin, "handleUnregister"));
    add_action("admin_post_" . iHomefinderAdmin::RESET_BLOG_CONNECTION_ACTION, array($admin, "handleResetBlogConnection"));

    // Blog credential migration progress and controls (Step 12). The page lives under Network
    // Admin > Settings on multisite; a single site gets a section on the Register page. The four
    // controls post to admin-post.php; authenticated hooks only, each handler checks its own
    // nonce and manage_network_options (single site: manage_options).
    $migrationStatus = iHomefinderAdminMigrationStatus::getInstance();
    if (is_multisite()) {
        add_action("network_admin_menu", array($migrationStatus, "registerNetworkMenu"));
    }
    add_action("admin_post_" . iHomefinderAdminMigrationStatus::PAUSE_TOGGLE_ACTION, array($migrationStatus, "handlePauseToggle"));
    add_action("admin_post_" . iHomefinderAdminMigrationStatus::RETIRE_ARM_TOGGLE_ACTION, array($migrationStatus, "handleRetireArmToggle"));
    add_action("admin_post_" . iHomefinderAdminMigrationStatus::RETIRE_HOLD_TOGGLE_ACTION, array($migrationStatus, "handleRetireHoldToggle"));
    add_action("admin_post_" . iHomefinderAdminMigrationStatus::RETIRE_EVALUATE_ACTION, array($migrationStatus, "handleRetireEvaluateNow"));

    add_action("admin_enqueue_scripts", array($admin, "addScripts"));
    add_action("admin_menu", array($admin, "createAdminMenu"));
    add_action("admin_init", array($installer, "upgrade"));
    add_action("admin_init", array($admin, "registerSettings"));
    //Adds functionality to the text editor for pages and posts
    //Add buttons to text editor and initialize short codes
    add_action("admin_init", array($shortcodeSelector, "addButtons"));
    //add error check
    add_action("admin_notices", array($admin, "checkError"));
    add_action("admin_notices", array($admin, "showExposedAdminNotice"));
} else {
    /*
    // Call upgrade method on every non-admin page load.
    // This is for the case that the plugin is updated through
    // multisite network admin or if the plugin files were manually copied into wordpress.
    */
    add_action("setup_theme", array($installer, "upgrade"));
    add_action("setup_theme", array($stateManager, "setupLeadCaptureUser"));
    // Front-end counterpart to the admin_init retry above. A site nobody logs into would
    // otherwise never recover, but the provisioning call blocks for up to 200s, so this
    // only ever schedules it — the work happens in the cron request, not this one.
    //
    // Deliberately no DISABLE_WP_CRON branch, unlike the network sweep above. That code
    // runs synchronously when cron is off because it fires once, from an upgrade request
    // an admin is already waiting on. This fires from visitor page loads, where a 200s
    // blocking call is exactly what must not happen — so on a site with cron disabled and
    // no external runner, front-end recovery is knowingly given up and the admin path is
    // the remaining route.
    add_action("init", function () use ($admin) {
        // The hourly claimed tick runs from front-end traffic too, so a site nobody logs into
        // still notices a lost credential or a due demotion. It marks; it never calls out.
        $admin->runHeadlessBlogChecks();
        if (!$admin->blogProvisioningIsPending() || $admin->migrationHaltedFor(get_current_blog_id())) {
            return;
        }
        // Only when the runner would actually attempt: not inside the drift backoff, and not
        // while the retry throttle is set (the transient outlives the request that set it).
        if ((int) get_option(iHomefinderConstants::BLOG_RESYNC_NEXT_AT_OPTION, 0) > time()) {
            return;
        }
        if (get_transient(iHomefinderAdmin::BLOG_PROVISION_RETRY_TRANSIENT . get_current_blog_id())) {
            return;
        }
        if (!wp_next_scheduled(iHomefinderConstants::BLOG_PROVISION_RETRY_CRON_HOOK)) {
            wp_schedule_single_event(time(), iHomefinderConstants::BLOG_PROVISION_RETRY_CRON_HOOK);
        }
    }, 20);
    add_action("init", array($enqueueResource, "enqueue"));
    add_action("wp_head", array($enqueueResource, "getMetaTags"), -100);
    add_action("wp_head", array($enqueueResource, "getHeader"));
    add_action("wp_footer", array($enqueueResource, "getFooter"), -100);
    add_action("wp_head", function() {
        if (!is_singular()) {
            return;
        }
        // get_queried_object_id() is used instead of get_the_ID() because wp_head
        // fires before the loop, where get_the_ID() is not guaranteed reliable.
        $faqScript = get_post_meta(get_queried_object_id(), "_oe_faq_json_ld", true);
        if (!empty($faqScript)) {
            // Output unescaped: content was validated as a well-formed JSON-LD script
            // block by sanitizeFaqScript() on write; kses would strip the script tag.
            echo "\n" . $faqScript . "\n";
        }
    });

    add_action('parse_request', array($virtualPageDispatcher, 'earlyRemoveCaching'), 1);
    add_action( 'wp', array( $virtualPageDispatcher, 'setupVirtualGlobals' ), 0 );
    add_filter("template_include", array( $virtualPageDispatcher, 'templateInclude' ), 99);
    add_filter("render_block_data", array( $virtualPageDispatcher, 'fixTemplatePartThemeAttribute' ), 1, 3);
    add_filter('render_block_core_post_content', array( $virtualPageDispatcher, 'renderVirtualPostContentBlock' ), 10, 2);
    add_filter('render_block', array( $virtualPageDispatcher, 'injectVirtualContentIntoTemplateOnce' ), 10, 2);
    add_filter("the_content",     array($virtualPageDispatcher, "getContent"), 20);
    add_filter("get_the_content", array($virtualPageDispatcher, "getContent"), 20);
    add_filter("the_excerpt", array($virtualPageDispatcher, "getExcerpt"), 20);
    add_filter("the_title", array($virtualPageDispatcher, 'filterTitle'), 20, 2);
    
    add_filter("comments_array", array($virtualPageDispatcher, "clearComments"));
    add_action("sm_buildmap", array($admin, "addSitemapForGoogleXmlSitemaps"));
    add_filter("wpseo_sitemap_page_content", array($admin, "addSitemapForYoastWordPressSeo"));
    iHomefinderSeoCompat::initialize();
}

// WP-CLI: read-only migration status and lists. Registered only under WP-CLI.
if (defined('WP_CLI') && WP_CLI) {
    require_once dirname(__FILE__) . '/cli/class-ihf-blog-cli.php';
    iHomefinderBlogCli::register();
}

/* shortcode */
add_action("init", array($shortcodeDispatcher, "initialize"));

/* Widgets */

function optima_express_register_widgets()
{
    $displayRules = iHomefinderDisplayRules::getInstance();
    if ($displayRules->isPropertiesGalleryEnabled()) {
        register_widget("iHomefinderPropertiesGallery");
    }
    if ($displayRules->isQuickSearchEnabled()) {
        register_widget("iHomefinderQuickSearchWidget");
    }
    if ($displayRules->isSeoCityLinksEnabled()) {
        register_widget("iHomefinderLinkWidget");
    }
    if ($displayRules->isSearchByAddressEnabled()) {
        register_widget("iHomefinderSearchByAddressWidget");
    }
    if ($displayRules->isSearchByListingIdEnabled()) {
        register_widget("iHomefinderSearchByListingIdWidget");
    }
    if ($displayRules->isContactFormWidgetEnabled()) {
        register_widget("iHomefinderContactFormWidget");
    }
    if ($displayRules->isLoginWidgetSmallEnabled()) {
        register_widget("iHomefinderLoginWidget");
    }
    if ($displayRules->isMoreInfoEnabled()) {
        register_widget("iHomefinderMoreInfoWidget");
    }
    if ($displayRules->isMoreInfoEnabled()) {
        register_widget("iHomefinderValuationWidget");
    }
    if ($displayRules->isAgentBioWidgetEnabled()) {
        register_widget("iHomefinderAgentBioWidget");
    }
    if ($displayRules->isSocialEnabled()) {
        register_widget("iHomefinderSocialWidget");
    }
    if ($displayRules->isHotsheetListWidgetEnabled()) {
        register_widget("iHomefinderHotsheetListWidget");
    }
    if ($displayRules->isEmailSignupWidgetEnabled()) {
        register_widget("iHomefinderEmailSignupFormWidget");
    }
}

add_action("widgets_init", "optima_express_register_widgets");

/* AJAX */
add_action("wp_ajax_nopriv_ihf_more_info_request", array($ajaxHandler, "requestMoreInfo"));
add_action("wp_ajax_nopriv_ihf_schedule_showing", array($ajaxHandler, "scheduleShowing"));
add_action("wp_ajax_nopriv_ihf_save_property", array($ajaxHandler, "saveProperty"));
add_action("wp_ajax_nopriv_ihf_photo_tour", array($ajaxHandler, "photoTour"));
add_action("wp_ajax_nopriv_ihf_save_search", array($ajaxHandler, "saveSearch"));
add_action("wp_ajax_nopriv_ihf_lead_capture_login", array($ajaxHandler, "leadCaptureLogin"));
add_action("wp_ajax_nopriv_ihf_saved_listing_comments", array($ajaxHandler, "addSavedListingComments"));
add_action("wp_ajax_nopriv_ihf_saved_listing_rating", array($ajaxHandler, "addSavedListingRating"));
add_action("wp_ajax_nopriv_ihf_save_listing_subscriber_session", array($ajaxHandler, "saveListingForSubscriberInSession"));
add_action("wp_ajax_nopriv_ihf_save_search_subscriber_session", array($ajaxHandler, "saveSearchForSubscriberInSession"));
add_action("wp_ajax_nopriv_ihf_contact_form_request", array($ajaxHandler, "contactFormRequest"));
add_action("wp_ajax_nopriv_ihf_send_password", array($ajaxHandler, "sendPassword"));
add_action("wp_ajax_nopriv_ihf_email_alert_popup", array($ajaxHandler, "emailAlertPopup"));
add_action("wp_ajax_nopriv_ihf_email_listing", array($ajaxHandler, "emailListing"));
add_action("wp_ajax_nopriv_ihf_email_board_member", array($ajaxHandler, "emailBoardMember"));
add_action("wp_ajax_nopriv_ihf_email_board_office", array($ajaxHandler, "emailBoardOffice"));
add_action("wp_ajax_nopriv_ihf_email_signup", array($ajaxHandler, "emailSignup"));
add_action("wp_ajax_nopriv_ihf_clear_cache", array($ajaxHandler, "clearCache"));
add_action("wp_ajax_nopriv_ihf_advanced_search_multi_selects", array($ajaxHandler, "advancedSearchMultiSelects")); //@deprecated
add_action("wp_ajax_nopriv_ihf_advanced_search_fields", array($ajaxHandler, "getAdvancedSearchFormFields")); //@deprecated
add_action("wp_ajax_nopriv_ihf_area_autocomplete", array($ajaxHandler, "getAutocompleteMatches")); //@deprecated

add_action("wp_ajax_ihf_more_info_request", array($ajaxHandler, "requestMoreInfo"));
add_action("wp_ajax_ihf_schedule_showing", array($ajaxHandler, "scheduleShowing"));
add_action("wp_ajax_ihf_save_property", array($ajaxHandler, "saveProperty"));
add_action("wp_ajax_ihf_photo_tour", array($ajaxHandler, "photoTour"));
add_action("wp_ajax_ihf_save_search", array($ajaxHandler, "saveSearch"));
add_action("wp_ajax_ihf_lead_capture_login", array($ajaxHandler, "leadCaptureLogin"));
add_action("wp_ajax_ihf_saved_listing_comments", array($ajaxHandler, "addSavedListingComments"));
add_action("wp_ajax_ihf_saved_listing_rating", array($ajaxHandler, "addSavedListingRating"));
add_action("wp_ajax_ihf_save_listing_subscriber_session", array($ajaxHandler, "saveListingForSubscriberInSession"));
add_action("wp_ajax_ihf_save_search_subscriber_session", array($ajaxHandler, "saveSearchForSubscriberInSession"));
add_action("wp_ajax_ihf_contact_form_request", array($ajaxHandler, "contactFormRequest"));
add_action("wp_ajax_ihf_send_password", array($ajaxHandler, "sendPassword"));
add_action("wp_ajax_ihf_email_alert_popup", array($ajaxHandler, "emailAlertPopup"));
add_action("wp_ajax_ihf_email_listing", array($ajaxHandler, "emailListing"));
add_action("wp_ajax_ihf_email_board_member", array($ajaxHandler, "emailBoardMember"));
add_action("wp_ajax_ihf_email_board_office", array($ajaxHandler, "emailBoardOffice"));
add_action("wp_ajax_ihf_email_signup", array($ajaxHandler, "emailSignup"));
add_action("wp_ajax_ihf_clear_cache", array($ajaxHandler, "clearCache"));
add_action("wp_ajax_ihf_tiny_mce_shortcode_dialog", array($shortcodeSelector, "getShortcodeSelectorContent"));
add_action("wp_ajax_ihf_advanced_search_multi_selects", array($ajaxHandler, "advancedSearchMultiSelects")); //@deprecated
add_action("wp_ajax_ihf_advanced_search_fields", array($ajaxHandler, "getAdvancedSearchFormFields")); //@deprecated
add_action("wp_ajax_ihf_area_autocomplete", array($ajaxHandler, "getAutocompleteMatches")); //@deprecated

// Register IHF full-width template for Divi theme only
add_filter('theme_page_templates', 'ihf_register_divi_full_width_template', 10, 3);

/**
 * Register the IHF Full Width template in the template dropdown,
 * but only when the active theme is Divi.
 *
 * @param array    $templates  Existing page templates.
 * @param WP_Theme $theme      Current theme object.
 * @param WP_Post  $post       Current post object.
 * @return array
 */
function ihf_register_divi_full_width_template($templates, $theme, $post)
{
    if (wp_get_theme()->get_template() === 'Divi') {
        $templates['ihf-divi-full-width.php'] = 'Custom Blank (Full Width)';
    }
    return $templates;
}

// When the IHF full-width template is selected, serve our plugin's template file.
add_filter('template_include', 'ihf_load_divi_full_width_template', 10);

/**
 * Load the plugin's IHF full-width template file when it is selected.
 *
 * @param string $template  Path to the template file WordPress resolved.
 * @return string
 */
function ihf_load_divi_full_width_template($template)
{
    if (is_singular() && get_page_template_slug() === 'ihf-divi-full-width.php') {
        $plugin_template = plugin_dir_path(__FILE__) . 'templates/ihf-divi-full-width.php';
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
    }
    return $template;
}

// Disable canonical urls, because we use a single page to display all results and WordPress creates a single canonical url for all of the virtual urls like the detail page and featured results.
remove_action("wp_head", "rel_canonical");

add_action("template_redirect", array($enqueueResource, "outputHttpHeaders"));
add_action("template_redirect", array($enqueueResource, "outputHttpsStatus"));

