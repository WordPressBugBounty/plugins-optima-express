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
 * Version: 8.3.1
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

//disable JetPack"s OG tags
add_filter("jetpack_enable_open_graph", "__return_false");

//uncomment during development, so rule changes can be viewed.
//in production this should not run, because it is a slow operation.
//add_action("init", array($rewriteRules, "flushRules"));

//Rewrite Rules
add_action("init", array($rewriteRules, "initialize"), 1);

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

// Disable canonical urls, because we use a single page to display all results and WordPress creates a single canonical url for all of the virtual urls like the detail page and featured results.
remove_action("wp_head", "rel_canonical");

// Fix Yoast SEO canonical and og:url for virtual pages
add_filter("wpseo_canonical", "ihf_fix_seo_canonical_url", 10, 1);
add_filter("wpseo_opengraph_url", "ihf_fix_seo_canonical_url", 10, 1);

// Fix All in One SEO canonical and og:url for virtual pages
add_filter("aioseo_canonical_url", "ihf_fix_seo_canonical_url", 10, 1);
add_filter("aioseo_facebook_tags", "ihf_fix_aioseo_facebook_tags", 10, 1);
add_filter("aioseo_twitter_tags", "ihf_fix_aioseo_twitter_tags", 10, 1);

// Fix Rank Math SEO canonical and og:url for virtual pages
add_filter("rank_math/frontend/canonical", "ihf_fix_seo_canonical_url", 10, 1);
add_filter("rank_math/opengraph/url", "ihf_fix_seo_canonical_url", 10, 1);

// Fix SEOPress canonical and og:url for virtual pages
add_filter("seopress_titles_canonical", "ihf_fix_seo_canonical_url", 10, 1);
add_filter("seopress_social_og_url", "ihf_fix_seo_canonical_url", 10, 1);

add_action("template_redirect", array($enqueueResource, "outputHttpHeaders"));
add_action("template_redirect", array($enqueueResource, "outputHttpsStatus"));

/**
 * Fix SEO plugin canonical and og:url for virtual pages
 *
 * SEO plugins use the synthetic post's post_name to build URLs, which only contains
 * the sanitized title. This function returns the actual request URL for virtual pages.
 *
 * @param string $url The URL generated by the SEO plugin
 * @return string The corrected URL for virtual pages
 */
function ihf_fix_seo_canonical_url($url)
{
    // Only fix URLs for iHomefinder virtual pages
    $ihfType = get_query_var(iHomefinderConstants::IHF_TYPE_URL_VAR);

    if (empty($ihfType)) {
        return $url;
    }

    // Get the actual request URL
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

    if (empty($host) || empty($requestUri)) {
        return $url;
    }

    // Build the full actual URL
    $actualUrl = $scheme . '://' . $host . $requestUri;

    // Remove query string if present (keep only the clean URL)
    $actualUrl = strtok($actualUrl, '?');

    return $actualUrl;
}

/**
 * Fix All in One SEO Facebook og:url tag for virtual pages
 *
 * @param array $tags The Facebook tags array from AIOSEO
 * @return array The corrected tags array
 */
function ihf_fix_aioseo_facebook_tags($tags)
{
    // Only fix URLs for iHomefinder virtual pages
    $ihfType = get_query_var(iHomefinderConstants::IHF_TYPE_URL_VAR);

    if (empty($ihfType) || !is_array($tags)) {
        return $tags;
    }

    // Get the actual request URL
    $actualUrl = ihf_get_actual_request_url();

    if (!empty($actualUrl)) {
        // Update og:url in the tags array
        foreach ($tags as &$tag) {
            if (isset($tag['property']) && $tag['property'] === 'og:url') {
                $tag['content'] = $actualUrl;
                break;
            }
        }
    }

    return $tags;
}

/**
 * Fix All in One SEO Twitter URL tag for virtual pages
 *
 * @param array $tags The Twitter tags array from AIOSEO
 * @return array The corrected tags array
 */
function ihf_fix_aioseo_twitter_tags($tags)
{
    // Only fix URLs for iHomefinder virtual pages
    $ihfType = get_query_var(iHomefinderConstants::IHF_TYPE_URL_VAR);

    if (empty($ihfType) || !is_array($tags)) {
        return $tags;
    }

    // Get the actual request URL
    $actualUrl = ihf_get_actual_request_url();

    if (!empty($actualUrl)) {
        // Update twitter:url in the tags array
        foreach ($tags as &$tag) {
            if (isset($tag['name']) && $tag['name'] === 'twitter:url') {
                $tag['content'] = $actualUrl;
                break;
            }
        }
    }

    return $tags;
}

/**
 * Get the actual request URL for the current page
 *
 * @return string|null The actual URL or null if unable to determine
 */
function ihf_get_actual_request_url()
{
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

    if (empty($host) || empty($requestUri)) {
        return null;
    }

    // Build the full actual URL
    $actualUrl = $scheme . '://' . $host . $requestUri;

    // Remove query string if present (keep only the clean URL)
    $actualUrl = strtok($actualUrl, '?');

    return $actualUrl;
}
