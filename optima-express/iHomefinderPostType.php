<?php

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Manages the ihf_virtual_page Custom Post Type.
 *
 * Instead of injecting a synthetic WP_Post with a hard-coded ID on every
 * request, the plugin keeps one real post per virtual-page type in the
 * database.  WordPress then finds each post through its normal query
 * pipeline, which means queried_object, is_singular, get_the_ID() etc.
 * all return correct values — eliminating the theme/plugin conflicts that
 * the old fake-post approach caused.
 */
class iHomefinderPostType
{

    const POST_TYPE    = 'ihf_virtual_page';
    const META_TYPE_KEY = '_ihf_type';

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

    /**
     * Register the CPT.  Called on 'init' at priority 0 (before rewrite
     * rules at priority 1) and also called explicitly from install() /
     * upgrade() so that wp_insert_post() can reference the type.
     */
    public function register()
    {
        register_post_type(self::POST_TYPE, array(
            'public'              => false,   // not a public-facing post type; keeps SEO plugins from sitemapping these posts
            'publicly_queryable'  => true,   // lets ?ihf_virtual_page=idx-detail resolve on the front end
            'show_ui'             => false,   // hidden from the admin UI — users cannot edit or delete these
            'show_in_menu'        => false,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'show_in_rest'        => false,
            'query_var'           => true,    // WordPress auto-registers the 'ihf_virtual_page' query var
            'rewrite'             => false,   // the plugin manages its own rewrite rules
            'has_archive'         => false,
            'exclude_from_search' => true,
            'hierarchical'        => false,
            'supports'            => array('title'),
            'label'               => 'IHF Virtual Pages',
        ));
    }

    /**
     * All 47 virtual-page type definitions.
     * 'type'  — the iHomefinderVirtualPageFactory constant value (used as post_name)
     * 'title' — human-readable label stored as post_title
     *
     * @return array[]
     */
    public static function getPageTypeDefinitions()
    {
        return array(
            array('type' => iHomefinderVirtualPageFactory::DEFAULT_PAGE,                                  'title' => 'IDX Default'),
            array('type' => iHomefinderVirtualPageFactory::LISTING_SEARCH_RESULTS,                        'title' => 'Property Search Results'),
            array('type' => iHomefinderVirtualPageFactory::LISTING_DETAIL,                                'title' => 'Listing Detail'),
            array('type' => iHomefinderVirtualPageFactory::LISTING_SOLD_DETAIL,                           'title' => 'Sold Listing Detail'),
            array('type' => iHomefinderVirtualPageFactory::LISTING_SEARCH_FORM,                           'title' => 'Property Search'),
            array('type' => iHomefinderVirtualPageFactory::MAP_SEARCH_FORM,                               'title' => 'Map Search'),
            array('type' => iHomefinderVirtualPageFactory::LISTING_ADVANCED_SEARCH_FORM,                  'title' => 'Advanced Search'),
            array('type' => iHomefinderVirtualPageFactory::FEATURED_SEARCH,                               'title' => 'Featured Listings'),
            array('type' => iHomefinderVirtualPageFactory::HOT_SHEET_LIST,                                'title' => 'Hotsheet List'),
            array('type' => iHomefinderVirtualPageFactory::HOT_SHEET_LISTING_REPORT,                      'title' => 'Hotsheet Listing Report'),
            array('type' => iHomefinderVirtualPageFactory::HOT_SHEET_OPEN_HOME_REPORT,                    'title' => 'Hotsheet Open Home Report'),
            array('type' => iHomefinderVirtualPageFactory::HOT_SHEET_MARKET_REPORT,                       'title' => 'Hotsheet Market Report'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_LOGIN,                               'title' => 'Property Organizer Login'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_LOGOUT,                              'title' => 'Property Organizer Logout'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_EDIT_SAVED_SEARCH,                   'title' => 'Edit Saved Search'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_EDIT_SAVED_SEARCH_SUBMIT,            'title' => 'Edit Saved Search Submit'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_EMAIL_UPDATES_CONFIRMATION,          'title' => 'Email Updates Confirmation'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_DELETE_SAVED_SEARCH,                 'title' => 'Delete Saved Search'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_DELETE_SAVED_SEARCH_SUBMIT,          'title' => 'Delete Saved Search Submit'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_VIEW_SAVED_SEARCH,                   'title' => 'View Saved Search'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_VIEW_SAVED_SEARCH_LIST,              'title' => 'View Saved Searches'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_VIEW_SAVED_LISTING_LIST,             'title' => 'View Saved Listings'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_DELETE_SAVED_LISTING_SUBMIT,         'title' => 'Delete Saved Listing'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_RESEND_CONFIRMATION_EMAIL,           'title' => 'Resend Confirmation Email'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_ACTIVATE_SUBSCRIBER,                 'title' => 'Activate Subscriber'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_SEND_SUBSCRIBER_PASSWORD,            'title' => 'Send Subscriber Password'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_HELP,                                'title' => 'Property Organizer Help'),
            array('type' => iHomefinderVirtualPageFactory::ORGANIZER_EDIT_SUBSCRIBER,                     'title' => 'Edit Subscriber'),
            array('type' => iHomefinderVirtualPageFactory::CONTACT_FORM,                                  'title' => 'Contact Form'),
            array('type' => iHomefinderVirtualPageFactory::VALUATION_FORM,                                'title' => 'Valuation Form'),
            array('type' => iHomefinderVirtualPageFactory::MORTGAGE_CALCULATOR,                           'title' => 'Mortgage Calculator'),
            array('type' => iHomefinderVirtualPageFactory::OPEN_HOME_SEARCH_FORM,                         'title' => 'Open Home Search'),
            array('type' => iHomefinderVirtualPageFactory::SUPPLEMENTAL_LISTING,                          'title' => 'Supplemental Listing'),
            array('type' => iHomefinderVirtualPageFactory::SOLD_FEATURED_LISTING,                         'title' => 'Sold Featured Listing'),
            array('type' => iHomefinderVirtualPageFactory::PENDING_FEATURED_LISTING,                      'title' => 'Pending Featured Listing'),
            array('type' => iHomefinderVirtualPageFactory::OFFICE_LIST,                                   'title' => 'Office List'),
            array('type' => iHomefinderVirtualPageFactory::OFFICE_DETAIL,                                 'title' => 'Office Detail'),
            array('type' => iHomefinderVirtualPageFactory::AGENT_LIST,                                    'title' => 'Agent List'),
            array('type' => iHomefinderVirtualPageFactory::AGENT_DETAIL,                                  'title' => 'Agent Detail'),
            array('type' => iHomefinderVirtualPageFactory::MLS_PORTAL_BOARD_OFFICE_SEARCH,                'title' => 'MLS Portal Office Search'),
            array('type' => iHomefinderVirtualPageFactory::MLS_PORTAL_BOARD_OFFICE_LIST,                  'title' => 'MLS Portal Office List'),
            array('type' => iHomefinderVirtualPageFactory::MLS_PORTAL_BOARD_OFFICE_LIST_NAME_STARTS_WITH, 'title' => 'MLS Portal Office List Alpha'),
            array('type' => iHomefinderVirtualPageFactory::MLS_PORTAL_BOARD_OFFICE_DETAIL,                'title' => 'MLS Portal Office Detail'),
            array('type' => iHomefinderVirtualPageFactory::MLS_PORTAL_BOARD_MEMBER_SEARCH,                'title' => 'MLS Portal Agent Search'),
            array('type' => iHomefinderVirtualPageFactory::MLS_PORTAL_BOARD_MEMBER_LIST,                  'title' => 'MLS Portal Agent List'),
            array('type' => iHomefinderVirtualPageFactory::MLS_PORTAL_BOARD_MEMBER_LIST_LAST_NAME_STARTS_WITH, 'title' => 'MLS Portal Agent List Alpha'),
            array('type' => iHomefinderVirtualPageFactory::MLS_PORTAL_BOARD_MEMBER_DETAIL,                'title' => 'MLS Portal Agent Detail'),
        );
    }

    /**
     * Create one ihf_virtual_page post per type definition if it does not
     * already exist.  Safe to call multiple times (idempotent).
     *
     * @return void
     */
    public function createPosts()
    {
        foreach (self::getPageTypeDefinitions() as $def) {
            $this->createPost($def['type'], $def['title']);
        }
    }

    /**
     * Permanently delete all ihf_virtual_page posts.
     * Called on plugin deactivation.
     *
     * @return void
     */
    public function deletePosts()
    {
        $ids = get_posts(array(
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => -1,
            'post_status'    => array('publish', 'draft', 'private', 'trash'),
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));

        foreach ($ids as $id) {
            wp_delete_post($id, true); // true = skip trash
        }
    }

    /**
     * Recreate an ihf_virtual_page post immediately after it has been
     * permanently deleted (e.g. via WP-CLI or another plugin).
     * Hooked to 'deleted_post'.
     *
     * @param int     $postId
     * @param WP_Post $post
     * @return void
     */
    public function recreateDeletedPost($postId, $post)
    {
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return;
        }

        $type = $post->post_name;
        foreach (self::getPageTypeDefinitions() as $def) {
            if ($def['type'] === $type) {
                $this->createPost($def['type'], $def['title']);
                break;
            }
        }
    }

    /**
     * Immediately restore an ihf_virtual_page post if it gets trashed.
     * Since show_ui=false prevents admin UI access, trashing can only happen
     * programmatically (WP-CLI, another plugin).  A trashed post is excluded
     * from WordPress queries by default, which would cause 404s.
     * Hooked to 'trashed_post'.
     *
     * @param int $postId
     * @return void
     */
    public function handleTrashedPost($postId)
    {
        if (get_post_type($postId) !== self::POST_TYPE) {
            return;
        }
        wp_untrash_post($postId);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Insert a single ihf_virtual_page post if none with that slug exists.
     *
     * The post_name (slug) is set to the type constant value (e.g. "idx-detail")
     * because the rewrite rules will pass ?ihf_virtual_page=idx-detail and
     * WordPress resolves that by looking up post_name in the CPT.
     *
     * @param string $type
     * @param string $title
     * @return int|WP_Error  New or existing post ID.
     */
    private function createPost($type, $title)
    {
        // Include trash so we restore rather than duplicate if a post was soft-deleted.
        $existing = get_posts(array(
            'post_type'      => self::POST_TYPE,
            'name'           => $type,
            'posts_per_page' => 1,
            'post_status'    => array('publish', 'draft', 'private', 'trash'),
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));

        if (!empty($existing)) {
            $existingId = $existing[0];
            if (get_post_status($existingId) === 'trash') {
                wp_untrash_post($existingId);
            }
            return $existingId;
        }

        $postId = wp_insert_post(array(
            'post_title'     => $title,
            'post_name'      => $type,
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'post_content'   => '',
            'post_author'    => 0,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ), true);

        if (!is_wp_error($postId)) {
            update_post_meta($postId, self::META_TYPE_KEY, $type);
        }

        return $postId;
    }
}
