<?php
/**
 * REST API controller for the optima-express blog post creation endpoint.
 *
 * Exposes: POST /wp-json/optima-express/v1/blog-post
 *
 * Authentication: WP Application Passwords (Basic Auth). WordPress validates
 * credentials before the permission_callback runs. The dedicated `optima-express`
 * WP user (minimum role: author with publish_posts capability) is created once
 * per site during provisioning. Requires WordPress 5.6+ (Application Passwords
 * were introduced in 5.6).
 *
 * SEO plugin support (priority order):
 *   1. Yoast SEO    — detected via class_exists('WPSEO_Meta')
 *   2. Rank Math    — detected via defined('RANK_MATH_VERSION')
 *   3. AIOSEO       — detected via function_exists('aioseo')
 *   4. None/unknown — fallback writes to _oe_seo_* postmeta keys
 *
 * @category WordPress_Plugin
 * @package  OptimaExpress
 * @author   iHomefinder <support@ihomefinder.com>
 * @license  GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link     http://www.ihomefinder.com
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class iHomefinderRestController
{
    // ---------------------------------------------------------------------------
    // Singleton infrastructure
    // ---------------------------------------------------------------------------

    /** @var iHomefinderRestController|null */
    private static $instance;

    private function __construct()
    {
        // intentionally empty
    }

    /**
     * @return iHomefinderRestController
     */
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ---------------------------------------------------------------------------
    // Route registration
    // ---------------------------------------------------------------------------

    /**
     * Registers the REST route with WordPress.
     * Called from iHomefinder.php on the `rest_api_init` action.
     *
     * @return void
     */
    public function registerRoutes()
    {
        register_rest_route(
            "optima-express/v1",
            "/blog-post",
            array(
                "methods"             => WP_REST_Server::CREATABLE,
                "callback"            => array($this, "createBlogPost"),
                "permission_callback" => array($this, "checkPermission"),
            )
        );

        register_rest_route(
            "optima-express/v1",
            "/authors",
            array(
                "methods"             => WP_REST_Server::READABLE,
                "callback"            => array($this, "getAuthors"),
                "permission_callback" => array($this, "checkPermission"),
            )
        );
    }

    // ---------------------------------------------------------------------------
    // Permission callback
    // ---------------------------------------------------------------------------

    /**
     * @param WP_REST_Request $request
     * @return bool
     */
    public function checkPermission($request)
    {
        return current_user_can("publish_posts");
    }

    // ---------------------------------------------------------------------------
    // Primary callback: create blog post
    // ---------------------------------------------------------------------------

    /**
     * Creates a WordPress blog post and writes SEO metadata.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function createBlogPost($request)
    {
        // 1. Extract and sanitize top-level fields
        $title     = sanitize_text_field($request->get_param("title"));
        $content   = wp_kses_post($request->get_param("content"));
        $slug      = sanitize_title($request->get_param("slug"));
        $faqScript = $this->sanitizeFaqScript($request->get_param("faq_script"));

        $allowedStatuses = array("draft", "pending", "private", "publish");
        $status = $request->get_param("status");
        if (!in_array($status, $allowedStatuses, true)) {
            $status = "draft";
        }

        $seoParam = $request->get_param("seo");
        $seo = (is_array($seoParam)) ? $seoParam : array();

        $authorId   = intval($request->get_param("author"));
        $postAuthor = ($authorId > 0) ? $authorId : get_current_user_id();

        // 2. Validate required fields
        if (empty($title)) {
            return new WP_Error(
                "missing_title",
                "The 'title' field is required.",
                array("status" => 400)
            );
        }

        if (empty($content)) {
            return new WP_Error(
                "missing_content",
                "The 'content' field is required.",
                array("status" => 400)
            );
        }

        // 3. Build wp_insert_post() arguments
        $postData = array(
            "comment_status" => "closed",
            "ping_status"    => "closed",
            "post_author"    => $postAuthor,
            "post_content"   => $content,
            "post_status"    => $status,
            "post_title"     => $title,
            "post_type"      => "post",
        );

        if (!empty($slug)) {
            $postData["post_name"] = $slug;
        }

        // Resolve market category — look up by name, auto-create if not found
        $marketName = sanitize_text_field($request->get_param("market_name"));
        if (!empty($marketName)) {
            $termId = null;
            $term   = get_term_by("name", $marketName, "category");
            if ($term) {
                $termId = $term->term_id;
            } else {
                $result = wp_insert_term($marketName, "category");
                if (is_wp_error($result)) {
                    if ($result->get_error_code() === "term_exists") {
                        // Race condition or slug collision — use the existing term
                        $termId = (int) $result->get_error_data("term_exists");
                    } else {
                        error_log("IHF: Failed to create WP category \"" . $marketName . "\": " . $result->get_error_message());
                    }
                } else {
                    $termId = $result["term_id"];
                }
            }
            if ($termId) {
                $postData["post_category"] = array($termId);
            }
        }

        // 4. Insert the post
        $postId = wp_insert_post($postData, true);

        if (is_wp_error($postId)) {
            return new WP_Error(
                "insert_failed",
                "wp_insert_post failed: " . $postId->get_error_message(),
                array("status" => 500)
            );
        }

        // 5. Store FAQ JSON-LD for wp_head injection
        if ($faqScript !== null) {
            update_post_meta($postId, "_oe_faq_json_ld", $faqScript);
        }

        // 6. Set featured image if provided
        $featuredMediaId = $request->get_param('featured_media_id');
        if ($featuredMediaId !== null) {
            $featuredMediaId = (int) $featuredMediaId;
            if ($featuredMediaId > 0) {
                $result = set_post_thumbnail($postId, $featuredMediaId);
                if ($result === false) {
                    error_log(sprintf(
                        '[OE] set_post_thumbnail failed for post %d with media ID %d. Post created without featured image.',
                        $postId,
                        $featuredMediaId
                    ));
                }
            }
        }

        // 7. Write SEO metadata
        $seoFieldsWritten = false;
        $seoPlugin        = "none";

        if (!empty($seo)) {
            $seoPlugin        = $this->writeSeoMeta($postId, $seo);
            $seoFieldsWritten = true;
        }

        // 8. Build and return the success response
        $draftUrl = $this->getDraftUrl($postId);
        $editUrl  = get_edit_post_link($postId, "raw");

        return new WP_REST_Response(
            array(
                "post_id"            => $postId,
                "draft_url"          => $draftUrl,
                "edit_url"           => $editUrl,
                "seo_plugin"         => $seoPlugin,
                "seo_fields_written" => $seoFieldsWritten,
            ),
            201
        );
    }

    // ---------------------------------------------------------------------------
    // Authors endpoint
    // ---------------------------------------------------------------------------

    /**
     * Returns a list of WordPress users capable of publishing posts on the current subsite.
     * The Optima Express system user is always included with a fixed display name.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function getAuthors($request)
    {
        $blogId = get_current_blog_id();

        $users = get_users(array(
            'capability' => 'publish_posts',
            'blog_id'    => $blogId,
        ));

        $systemUserLogin = 'optima-express';
        $systemUserFound = false;
        $result          = array();

        foreach ($users as $user) {
            // Exclude network Super Admins who have no explicit role on this subsite.
            if (is_super_admin($user->ID)) {
                $wpUser = new WP_User($user->ID, '', $blogId);
                if (empty($wpUser->roles)) {
                    continue;
                }
            }

            $displayName = $user->display_name;

            if ($user->user_login === $systemUserLogin) {
                $displayName     = 'Optima Express (System Account)';
                $systemUserFound = true;
            }

            $result[] = array(
                'id'           => (int) $user->ID,
                'display_name' => $displayName,
            );
        }

        if (!$systemUserFound) {
            $systemUser = get_user_by('login', $systemUserLogin);
            if ($systemUser !== false) {
                $result[] = array(
                    'id'           => (int) $systemUser->ID,
                    'display_name' => 'Optima Express (System Account)',
                );
            } else {
                error_log('[OE] getAuthors: optima-express system user not found. Site may not be provisioned correctly.');
            }
        }

        return new WP_REST_Response($result, 200);
    }

    // ---------------------------------------------------------------------------
    // SEO dispatch
    // ---------------------------------------------------------------------------

    /**
     * Detects the active SEO plugin and dispatches to the appropriate writer.
     * Priority: Yoast > Rank Math > AIOSEO > fallback.
     *
     * @param int   $postId
     * @param array $seo
     * @return string "yoast", "rankmath", "aioseo", or "none"
     */
    private function writeSeoMeta($postId, array $seo)
    {
        if (class_exists("WPSEO_Meta")) {
            $this->writeYoastMeta($postId, $seo);
            return "yoast";
        }

        if (defined("RANK_MATH_VERSION")) {
            $this->writeRankMathMeta($postId, $seo);
            return "rankmath";
        }

        if (function_exists("aioseo")) {
            $this->writeAioseoMeta($postId, $seo);
            return "aioseo";
        }

        $this->writeFallbackMeta($postId, $seo);
        return "none";
    }

    // ---------------------------------------------------------------------------
    // Yoast SEO writer
    // ---------------------------------------------------------------------------

    /**
     * @param int   $postId
     * @param array $seo
     * @return void
     */
    private function writeYoastMeta($postId, array $seo)
    {
        $set = function ($key, $value) use ($postId) {
            if ($value !== null && $value !== "") {
                WPSEO_Meta::set_value($key, $value, $postId);
            }
        };

        $set("title",     isset($seo["title"])         ? $seo["title"]         : null);
        $set("metadesc",  isset($seo["description"])   ? $seo["description"]   : null);
        $set("focuskw",   isset($seo["focus_keyword"]) ? $seo["focus_keyword"] : null);
        $set("canonical", isset($seo["canonical_url"]) ? $seo["canonical_url"] : null);

        $robots = isset($seo["robots"]) && is_array($seo["robots"]) ? $seo["robots"] : null;
        if ($robots !== null) {
            $noindex  = !empty($robots["noindex"])  ? "1" : "0";
            $nofollow = !empty($robots["nofollow"]) ? "1" : "0";
            WPSEO_Meta::set_value("meta-robots-noindex",  $noindex,  $postId);
            WPSEO_Meta::set_value("meta-robots-nofollow", $nofollow, $postId);

            $advParts = array();
            if (!empty($robots["noarchive"]))    $advParts[] = "noarchive";
            if (!empty($robots["nosnippet"]))    $advParts[] = "nosnippet";
            if (!empty($robots["noimageindex"])) $advParts[] = "noimageindex";
            $advValue = empty($advParts) ? "none" : implode(",", $advParts);
            WPSEO_Meta::set_value("meta-robots-adv", $advValue, $postId);
        }

        $set("opengraph-title",       isset($seo["og_title"])       ? $seo["og_title"]       : null);
        $set("opengraph-description", isset($seo["og_description"]) ? $seo["og_description"] : null);
        $set("opengraph-image",       isset($seo["og_image_url"])   ? $seo["og_image_url"]   : null);

        $set("twitter-title",       isset($seo["twitter_title"])       ? $seo["twitter_title"]       : null);
        $set("twitter-description", isset($seo["twitter_description"]) ? $seo["twitter_description"] : null);
        $set("twitter-image",       isset($seo["twitter_image_url"])   ? $seo["twitter_image_url"]   : null);

        if (!empty($seo["schema_article_type"])) {
            WPSEO_Meta::set_value("schema_article_type", sanitize_text_field($seo["schema_article_type"]), $postId);
        }
    }

    // ---------------------------------------------------------------------------
    // Rank Math writer
    // ---------------------------------------------------------------------------

    /**
     * @param int   $postId
     * @param array $seo
     * @return void
     */
    private function writeRankMathMeta($postId, array $seo)
    {
        $set = function ($key, $value) use ($postId) {
            if ($value !== null && $value !== "") {
                update_post_meta($postId, $key, $value);
            }
        };

        $set("rank_math_title",         isset($seo["title"])         ? $seo["title"]         : null);
        $set("rank_math_description",   isset($seo["description"])   ? $seo["description"]   : null);
        $set("rank_math_focus_keyword", isset($seo["focus_keyword"]) ? $seo["focus_keyword"] : null);
        $set("rank_math_canonical_url", isset($seo["canonical_url"]) ? $seo["canonical_url"] : null);

        $robots = isset($seo["robots"]) && is_array($seo["robots"]) ? $seo["robots"] : null;
        if ($robots !== null) {
            $robotsArray = array();
            if (!empty($robots["noindex"]))      $robotsArray[] = "noindex";
            if (!empty($robots["nofollow"]))     $robotsArray[] = "nofollow";
            if (!empty($robots["noarchive"]))    $robotsArray[] = "noarchive";
            if (!empty($robots["nosnippet"]))    $robotsArray[] = "nosnippet";
            if (!empty($robots["noimageindex"])) $robotsArray[] = "noimageindex";
            update_post_meta($postId, "rank_math_robots", $robotsArray);
        }

        $set("rank_math_facebook_title",       isset($seo["og_title"])       ? $seo["og_title"]       : null);
        $set("rank_math_facebook_description", isset($seo["og_description"]) ? $seo["og_description"] : null);
        $set("rank_math_facebook_image",       isset($seo["og_image_url"])   ? $seo["og_image_url"]   : null);

        update_post_meta($postId, "rank_math_twitter_use_facebook", "");
        $set("rank_math_twitter_title",       isset($seo["twitter_title"])       ? $seo["twitter_title"]       : null);
        $set("rank_math_twitter_description", isset($seo["twitter_description"]) ? $seo["twitter_description"] : null);
        $set("rank_math_twitter_image",       isset($seo["twitter_image_url"])   ? $seo["twitter_image_url"]   : null);

        if (!empty($seo["schema_article_type"])) {
            $schemaType = preg_replace('/[^a-zA-Z0-9]/', '', $seo["schema_article_type"]);
            $schemaData = array(
                "@type"    => $schemaType,
                "metadata" => array(
                    "title"     => "Article",
                    "type"      => "template",
                    "isPrimary" => true,
                ),
            );
            update_post_meta($postId, "rank_math_rich_snippet", "article");
            update_post_meta($postId, "rank_math_schema_" . $schemaType, $schemaData);
        }
    }

    // ---------------------------------------------------------------------------
    // AIOSEO writer
    // ---------------------------------------------------------------------------

    /**
     * @param int   $postId
     * @param array $seo
     * @return void
     */
    private function writeAioseoMeta($postId, array $seo)
    {
        if (!class_exists("AIOSEO\\Plugin\\Common\\Models\\Post")) {
            return;
        }

        $robots = isset($seo["robots"]) && is_array($seo["robots"]) ? $seo["robots"] : null;
        $robotsDefault = ($robots === null) ? true : false;

        $data = array(
            "title"        => isset($seo["title"])         ? $seo["title"]         : "",
            "description"  => isset($seo["description"])   ? $seo["description"]   : "",
            "canonicalUrl" => isset($seo["canonical_url"]) ? $seo["canonical_url"] : "",

            "default"      => $robotsDefault,
            "noindex"      => ($robots !== null && !empty($robots["noindex"]))      ? true : false,
            "nofollow"     => ($robots !== null && !empty($robots["nofollow"]))     ? true : false,
            "noarchive"    => ($robots !== null && !empty($robots["noarchive"]))    ? true : false,
            "nosnippet"    => ($robots !== null && !empty($robots["nosnippet"]))    ? true : false,
            "noimageindex" => ($robots !== null && !empty($robots["noimageindex"])) ? true : false,

            "og_title"            => isset($seo["og_title"])       ? $seo["og_title"]       : "",
            "og_description"      => isset($seo["og_description"]) ? $seo["og_description"] : "",
            "og_image_type"       => !empty($seo["og_image_url"]) ? "custom" : "default",
            "og_image_custom_url" => isset($seo["og_image_url"])  ? $seo["og_image_url"]   : "",

            "twitter_use_og"           => false,
            "twitter_title"            => isset($seo["twitter_title"])       ? $seo["twitter_title"]       : "",
            "twitter_description"      => isset($seo["twitter_description"]) ? $seo["twitter_description"] : "",
            "twitter_image_type"       => !empty($seo["twitter_image_url"]) ? "custom" : "default",
            "twitter_image_custom_url" => isset($seo["twitter_image_url"])  ? $seo["twitter_image_url"]   : "",

            "keyphrases" => array(
                "focus" => array(
                    "keyphrase" => isset($seo["focus_keyword"]) ? $seo["focus_keyword"] : "",
                    "score"     => 0,
                    "analysis"  => new stdClass(),
                ),
                "additional" => array(),
            ),
        );

        if (!empty($seo["schema_article_type"])) {
            $data["schema"] = array(
                "graphs" => array(
                    array(
                        "@type"     => sanitize_text_field($seo["schema_article_type"]),
                        "graphName" => "Article",
                        "isDefault" => true,
                    ),
                ),
            );
        }

        \AIOSEO\Plugin\Common\Models\Post::savePost($postId, $data);
    }

    // ---------------------------------------------------------------------------
    // Fallback writer
    // ---------------------------------------------------------------------------

    /**
     * Writes SEO data to namespaced _oe_seo_* postmeta keys when no recognised
     * SEO plugin is active.
     *
     * @param int   $postId
     * @param array $seo
     * @return void
     */
    private function writeFallbackMeta($postId, array $seo)
    {
        $robots = isset($seo["robots"]) && is_array($seo["robots"]) ? $seo["robots"] : array();

        $fields = array(
            "_oe_seo_title"           => isset($seo["title"])               ? $seo["title"]               : "",
            "_oe_seo_description"     => isset($seo["description"])         ? $seo["description"]         : "",
            "_oe_seo_focus_keyword"   => isset($seo["focus_keyword"])       ? $seo["focus_keyword"]       : "",
            "_oe_seo_canonical"       => isset($seo["canonical_url"])       ? $seo["canonical_url"]       : "",
            "_oe_og_title"            => isset($seo["og_title"])            ? $seo["og_title"]            : "",
            "_oe_og_description"      => isset($seo["og_description"])      ? $seo["og_description"]      : "",
            "_oe_og_image_url"        => isset($seo["og_image_url"])        ? $seo["og_image_url"]        : "",
            "_oe_twitter_title"       => isset($seo["twitter_title"])       ? $seo["twitter_title"]       : "",
            "_oe_twitter_description" => isset($seo["twitter_description"]) ? $seo["twitter_description"] : "",
            "_oe_twitter_image_url"   => isset($seo["twitter_image_url"])   ? $seo["twitter_image_url"]   : "",
            "_oe_schema_article_type" => isset($seo["schema_article_type"]) ? sanitize_text_field($seo["schema_article_type"]) : "",
            "_oe_robots_noindex"      => !empty($robots["noindex"])      ? "1" : "0",
            "_oe_robots_nofollow"     => !empty($robots["nofollow"])     ? "1" : "0",
            "_oe_robots_noarchive"    => !empty($robots["noarchive"])    ? "1" : "0",
            "_oe_robots_nosnippet"    => !empty($robots["nosnippet"])    ? "1" : "0",
            "_oe_robots_noimageindex" => !empty($robots["noimageindex"]) ? "1" : "0",
        );

        foreach ($fields as $key => $value) {
            if ($value !== "" && $value !== null) {
                update_post_meta($postId, $key, sanitize_text_field($value));
            }
        }
    }

    // ---------------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------------

    /**
     * Validates and returns the faq_script value if it is a well-formed
     * JSON-LD script block, or null if absent or invalid.
     *
     * Accepts only <script type="application/ld+json">...</script> blocks
     * containing valid JSON. Rejects anything else to prevent arbitrary
     * script injection.
     *
     * @param mixed $value
     * @return string|null
     */
    private function sanitizeFaqScript($value)
    {
        if (empty($value) || !is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if (!preg_match('/^<script\s+type=["\']application\/ld\+json["\'][^>]*>(.*)<\/script>$/si', $trimmed, $matches)) {
            error_log('[OE] faq_script rejected: does not match expected JSON-LD script block format');
            return null;
        }

        $json = trim($matches[1]);
        if (json_decode($json) === null) {
            error_log('[OE] faq_script rejected: inner content is not valid JSON');
            return null;
        }

        return $trimmed;
    }

    /**
     * Returns a viewable URL for a post regardless of its publish status.
     * Uses ?p=N for drafts since get_permalink() returns false for unpublished posts.
     *
     * @param int $postId
     * @return string
     */
    private function getDraftUrl($postId)
    {
        $permalink = get_permalink($postId);
        if ($permalink !== false) {
            return $permalink;
        }
        return add_query_arg("p", $postId, home_url("/"));
    }
}
