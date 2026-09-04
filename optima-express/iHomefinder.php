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
 * Version: 8.7.2
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

// Multisite network provisioning — ensures all sub-sites get registered with ihf-root
// on network-level plugin upgrade or activation, without requiring per-site admin action.
if (is_multisite()) {
    // Cron action handler — registered in the main bootstrap path so it is available
    // when WP-Cron fires the event in a separate HTTP context.
    add_action('ihf_oe_network_provision', array($installer, 'upgradeNetwork'));

    // Schedules a network provisioning sweep via WP-Cron, or runs it synchronously
    // when WP-Cron is disabled (e.g. DISABLE_WP_CRON = true with an external cron runner).
    $scheduleOrRunNetworkProvision = function () use ($installer) {
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $installer->upgradeNetwork();
        } elseif (!wp_next_scheduled('ihf_oe_network_provision')) {
            wp_schedule_single_event(time(), 'ihf_oe_network_provision');
        }
    };

    // Schedule a network provisioning sweep when this plugin is updated via the network admin.
    add_action('upgrader_process_complete', function ($upgrader, $hook_extra) use ($scheduleOrRunNetworkProvision) {
        if (
            !isset($hook_extra['type'], $hook_extra['action']) ||
            $hook_extra['type'] !== 'plugin' ||
            $hook_extra['action'] !== 'update'
        ) {
            return;
        }
        $slug = plugin_basename(__FILE__);
        $inSingular = isset($hook_extra['plugin']) && $hook_extra['plugin'] === $slug;
        $inPlural   = isset($hook_extra['plugins']) && in_array($slug, $hook_extra['plugins'], true);
        if ($inSingular || $inPlural) {
            $scheduleOrRunNetworkProvision();
        }
    }, 10, 2);

    // Schedule a network provisioning sweep when this plugin is network-activated.
    add_action('activated_plugin', function ($plugin) use ($scheduleOrRunNetworkProvision) {
        if (is_network_admin() && $plugin === plugin_basename(__FILE__)) {
            $scheduleOrRunNetworkProvision();
        }
    });

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

if (is_admin()) {
    add_action("admin_enqueue_scripts", array($admin, "addScripts"));
    add_action("admin_menu", array($admin, "createAdminMenu"));
    add_action("admin_init", array($installer, "upgrade"));
    add_action("admin_init", array($admin, "registerSettings"));
    //Adds functionality to the text editor for pages and posts
    //Add buttons to text editor and initialize short codes
    add_action("admin_init", array($shortcodeSelector, "addButtons"));
    //add error check
    add_action("admin_notices", array($admin, "checkError"));
} else {
    /*
    // Call upgrade method on every non-admin page load.
    // This is for the case that the plugin is updated through
    // multisite network admin or if the plugin files were manually copied into wordpress.
    */
    add_action("setup_theme", array($installer, "upgrade"));
    add_action("setup_theme", array($stateManager, "setupLeadCaptureUser"));
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
    
    add_action( 'wp', array( $virtualPageDispatcher, 'setupVirtualGlobals' ), 0 );
    add_filter( 'posts_pre_query', array( $virtualPageDispatcher, 'shortCircuitVirtualPosts' ), 10, 2 );
    add_filter("template_include", array( $virtualPageDispatcher, 'templateInclude' ), 99);
    add_filter("render_block_data", array( $virtualPageDispatcher, 'fixTemplatePartThemeAttribute' ), 1, 3);
    add_filter("render_block_core_post_content", array( $virtualPageDispatcher, 'renderVirtualPostContentBlock' ), 10, 2 );
    add_action("pre_get_posts", array( $virtualPageDispatcher, 'primeVirtualQuery' ), 0 );
    add_filter("the_content", array($virtualPageDispatcher, "getContent"), 20);
    add_filter("the_excerpt", array($virtualPageDispatcher, "getExcerpt"), 20);
    add_filter('render_block_core_post_content', array( $virtualPageDispatcher, 'renderVirtualPostContentBlock' ), 10, 2);
    add_filter('render_block', array( $virtualPageDispatcher, 'injectVirtualContentIntoTemplateOnce' ), 10, 2);
    
    add_filter("comments_array", array($virtualPageDispatcher, "clearComments"));
    add_action("sm_buildmap", array($admin, "addSitemapForGoogleXmlSitemaps"));
    add_filter("wpseo_sitemap_page_content", array($admin, "addSitemapForYoastWordPressSeo"));
    iHomefinderSeoCompat::initialize();
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

