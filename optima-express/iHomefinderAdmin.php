<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


class iHomefinderAdmin {

    // Throttle for retryBlogProvisioningIfPending(). Per blog id, so one site's attempt
    // does not suppress another's on multisite.
    const BLOG_PROVISION_RETRY_TRANSIENT = "ihf_blog_provision_retry_";
    const BLOG_PROVISION_RETRY_INTERVAL = 900; // 15 minutes

    // 8.7.x throttle of the credential-existence check, which now runs inside the hourly claimed
    // tick (runHeadlessBlogChecks). The name is kept so an 8.7.x row left in the options table is
    // recognizable; 8.8.0 neither reads nor writes it.
    const BLOG_CREDENTIAL_VERIFY_TRANSIENT = "ihf_blog_credential_verify_";
    const BLOG_CREDENTIAL_VERIFY_INTERVAL = 3600;

    // Per blog id: one signed-ping re-activation per window (replaces the nudge transient).
    const SIGNED_REACTIVATE_TRANSIENT = "ihf_signed_reactivate_";
    const SIGNED_REACTIVATE_INTERVAL = 300;

    // Network-wide (site transient): the daily retirement event was scheduled on the main site,
    // or found already scheduled there, within the last hour; sub-site ticks skip the switch.
    const SHARED_RETIRE_SCHEDULED_MEMO = "ihf_oe_shared_retire_scheduled";
    const SHARED_RETIRE_SCHEDULED_MEMO_TTL = 3600;

    // Per user id: outcome of a Register page action, carried across the redirect.
    const ACTIVATE_RESULT_TRANSIENT = "ihf_activate_result_";
    const ACTIVATE_RESULT_INTERVAL = 60;

    // Per-site credential model timings, in seconds.
    const BLOG_PROVISION_LOCK_TTL = 600;        // a lock row older than this is stale and may be broken
    const SHARED_DEMOTE_GRACE = 600;            // confirm to demotion of the shared user on that blog
    const HEADLESS_CHECK_INTERVAL = 3600;       // claimed tick cadence per blog
    const REVOKE_AGE_FLOOR = 800;               // an application password younger than this may be in flight
    const DRIFT_FLAP_WINDOW = 86400;            // a scheme-only REST base change within this of the last send is flapping
    const SHARED_RETIRE_CLAIM_INTERVAL = 86400; // one retirement evaluation per network per day
    const SHARED_RETIRE_LOCK_TTL = 600;         // a network retirement lock row older than this is stale
    const RETIRE_SUMMARY_MAX_ENTRIES = 200;     // per-password triples kept in the redacted pre-purge summary
    const RETIRE_LOG_MAX_BLOG_IDS = 20;         // holder blog ids listed in the daily retire-count log line

    // 8.8.0 application password name family on the per-site user: prefix + blog id + "-" + 8 hex.
    const SERVICE_PASSWORD_NAME_PREFIX = "optima-express-blog-";
    // Route under get_rest_url() that ihf-root posts to; the REST base sent with the credential.
    const BLOG_POST_REST_PATH = "optima-express/v1/blog-post/";

    // Outcomes of activateAuthenticationToken(). "rejected" is a 401 or 403 only; any other
    // failure (WP_Error, 5xx, a WAF page, a 200 without a token) is "unreachable".
    const ACTIVATE_ACTIVATED = "activated";
    const ACTIVATE_REJECTED = "rejected";
    const ACTIVATE_UNREACHABLE = "unreachable";
    const ACTIVATE_NO_KEY = "no-key";

    // admin-post action name and nonce action of the Reset Blog Connection button.
    const RESET_BLOG_CONNECTION_ACTION = "ihf_reset_blog_connection";
    // admin-post action names (and nonce actions) of the three Register page actions. The page
    // registers no settings group, so options.php can never touch either token (MAX-4432).
    const REGISTER_KEY_ACTION = "ihf_register_key";
    const REFRESH_REGISTRATION_ACTION = "ihf_refresh_registration";
    const UNREGISTER_ACTION = "ihf_unregister";
    // Posted field carrying the candidate registration key (deliberately not the option name).
    const REGISTRATION_KEY_FIELD = "ihf_registration_key";
    // Fifth value stored in the ACTIVATE_RESULT_TRANSIENT beside the four ACTIVATE_* outcomes.
    const RESULT_UNREGISTERED = "unregistered";
    // Nonce action of the authenticated ihf_clear_cache ajax branch.
    const CLEAR_CACHE_NONCE_ACTION = "ihf_clear_cache";
    // Query parameters ihf-root adds to its cache-clear ping: unix time and
    // lowercase hex HMAC-SHA256 of that decimal string, keyed with the activation key.
    const SIGNED_PING_TS_PARAM = "ihf_ts";
    const SIGNED_PING_SIG_PARAM = "ihf_sig";
    const SHARED_RETIRE_QUIET_PERIOD = 7776000; // 90 days: operator-armed retirement telemetry gate
    const SHARED_RETIRE_RECENT_USE = 604800;    // 7 days: no shared password created or used this recently

    // Stage vocabulary for BLOG_PROVISION_LAST_ERROR_OPTION and log lines.
    const STAGE_LOCK = "lock";
    const STAGE_RESOLVE_USER = "resolve-user";
    const STAGE_CREATE_USER = "create-user";
    const STAGE_MEMBERSHIP = "membership";
    const STAGE_CREATE_PASSWORD = "create-password";
    const STAGE_PROVISION_POST = "provision-post";
    const STAGE_CONFIRM = "confirm";
    const STAGE_DEMOTE = "demote";
    const STAGE_RETIRE = "retire";
    const STAGE_SITE_RETIRED = "site-retired";
    const STAGE_RETIRE_COUNT = "retire-count";
    const STAGE_RETIRE_COUNT_ERROR = "retire-count-error";
    const STAGE_RETIRE_HOLD = "retire-hold";
    const STAGE_RETIRE_UNOWNED = "retire-unowned";
    const STAGE_RETIRE_SUPERADMIN = "retire-superadmin";
    const STAGE_RETIRE_YOUNG = "retire-young";
    const STAGE_RETIRE_USED = "retire-used";
    const STAGE_RETIRE_CAS_MISS = "retire-cas-miss";
    const STAGE_RETIRE_LATE_MEMBER = "retire-late-member";
    const STAGE_RETIRE_PURGED = "retire-purged";
    const STAGE_PING_REACTIVATE = "ping-reactivate";
    const STAGE_PING_BAD_SIGNATURE = "ping-bad-signature";
    const STAGE_DRIFT_FLAPPING = "drift-flapping";
    const STAGE_SQUATTED_CLEARED = "squatted-cleared";

    private static $instance;
    // Per-request memo of blogHasLegacySharedUser(), keyed by blog id.
    private static $legacyMemo = array();
    private $utility;
    private $displayRules;
    // Fixed-vocabulary detail of the last activateAuthenticationToken() failure (see
    // iHomefinderRemoteResponse::getFailureDetail()); '' after a success or before any call.
    private $lastActivateDetail = '';

    private function __construct()
    {
        $this->utility = iHomefinderUtility::getInstance();
        $this->displayRules = iHomefinderDisplayRules::getInstance();
    }

    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function checkError()
    {
        
        $pageName = $this->utility->getRequestVar("page");
        
        //Check for valid plugin registration
        //Do not check for registration on the registration page.
        if ($pageName !== iHomefinderConstants::PAGE_ACTIVATE && !$this->isActivated()) {
            ?>
            <div id="ihf-main-container">
                <p class="ihf-green-bar">
                    <a href="admin.php?page=<?php echo esc_html(iHomefinderConstants::PAGE_ACTIVATE) ?>" class="button button-primary">Activate Your Optima Express Account</a>
                    &nbsp;&nbsp;&nbsp;Get a paid subscription for your MLS
                </p>
            </div>
            <?php
        }
        
        if (get_option(iHomefinderConstants::COMPATIBILITY_CHECK_ENABLED, true) !== "false") {
            $errors = array();
            //check if permalink structure is set
            $permalinkStructure = get_option("permalink_structure", null);
            if (empty($permalinkStructure)) {
                $errors[] = "<a href=\"" . admin_url("options-permalink.php") . "\">WordPress permalink settings are set as default (Error 404)</a>";
            }
            $remoteRequest = new iHomefinderRequestor();
            $remoteRequest
            ->addParameter("requestType", "compatibility-check");
            $remoteRequest->setCacheExpiration(60*60*24);
            $remoteResponse = $remoteRequest->remoteGetRequest();
            if (!empty($remoteResponse)) {
                $content = null;
                $content = (string) $remoteResponse->getJson();
                if (!empty($content)) {
                    $compatibility = json_decode($content, true);
                    if (!empty($compatibility) && is_array($compatibility)) {
                        //check plugins
                        if (array_key_exists("Plugin", $compatibility)) {
                            $incompatiblePlugins = $compatibility["Plugin"];
                            if (is_array($incompatiblePlugins)) {
                                $plugins = get_plugins();
                                foreach ($plugins as $pluginPath => $plugin) {
                                    if (is_plugin_active($pluginPath)) {
                                           $pluginName = $plugin["Name"];
                                        if (array_key_exists($pluginName, $incompatiblePlugins)) {
                                            $message = $incompatiblePlugins[$pluginName];
                                            if ($message !== null) {
                                                            $errors[] = "<a href=\"" . admin_url("plugins.php") . "?s=" . urlencode($pluginName) . "\">" . $pluginName . "</a> (" . $message . ")";
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        //check theme
                        if (array_key_exists("Theme", $compatibility)) {
                            $theme = wp_get_theme();
                            $themeName = $theme->get("Name");
                            $incompatibleThemes = $compatibility["Theme"];
                            if (is_array($incompatibleThemes) && array_key_exists($themeName, $incompatibleThemes)) {
                                $message = $incompatibleThemes[$themeName];
                                if ($message !== null) {
                                    $errors[] = "<a href=\"" . admin_url("themes.php") . "\">" . $themeName . "</a> (" . $message . ")";
                                }
                            }
                        }
                    }
                }
            }
            if ($this->utility->isDatabaseCached()) {
                $errors[] = "Database caching is enabled, which prevents updated IDX results from displaying.";
            }
            //check error count
            if (count($errors) > 0) {
                ?>
                <div class="error">
                    <h4 style="float: left;">
                <?php echo esc_html(count($errors)) ?> compatibility issue<?php if (count($errors) > 1) {
                    ?>s<?php
                } ?>
                    </h4>
                    <form style="float: right; margin-top: 5px; display: none;" method="post" action="options.php">
                <?php settings_fields(iHomefinderConstants::OPTION_GROUP_COMPATIBILITY_CHECK); ?>
                        <input type="hidden" value="false" name="<?php echo esc_attr(iHomefinderConstants::COMPATIBILITY_CHECK_ENABLED); ?>" />
                        <button class="button-secondary" type="submit">Dismiss compatibility warnings</button>
                    </form>
                    <div style="clear: both;">
                <?php foreach ($errors as $error) { ?>
                            <p>
                    <?php echo esc_html($error); ?>
                            </p>
                <?php } ?>
                    </div>
                </div>
                <?php
            }
        }
    }

    public function createAdminMenu()
    {
        $displayRules = iHomefinderDisplayRules::getInstance();
        add_menu_page("Optima Express", "Optima Express", "manage_options", iHomefinderConstants::PAGE_INFORMATION, array(iHomefinderAdminInformation::getInstance(), "getPage"));
        add_submenu_page(iHomefinderConstants::PAGE_INFORMATION, "Information", "Information", "manage_options", iHomefinderConstants::PAGE_INFORMATION, array(iHomefinderAdminInformation::getInstance(), "getPage"));
        add_submenu_page(iHomefinderConstants::PAGE_INFORMATION, "Shortcodes", "Shortcodes", "manage_options", iHomefinderConstants::PAGE_SHORTCODES, array(iHomefinderAdminShortcodes::getInstance(), "getPage"));
        add_submenu_page(iHomefinderConstants::PAGE_INFORMATION, "Register", "Register", "manage_options", iHomefinderConstants::PAGE_ACTIVATE, array(iHomefinderAdminActivate::getInstance(), "getPage"));
        if ($this->isActivated()) {
            add_submenu_page(iHomefinderConstants::PAGE_INFORMATION, "IDX Control Panel", "IDX Control Panel", "manage_options", iHomefinderConstants::PAGE_IDX_CONTROL_PANEL, array(iHomefinderAdminControlPanel::getInstance(), "getPage"));
        }
        add_submenu_page(iHomefinderConstants::PAGE_INFORMATION, "IDX Pages", "IDX Pages", "manage_options", iHomefinderConstants::PAGE_IDX_PAGES, array(iHomefinderAdminPageConfig::getInstance(), "getPage"));
        add_submenu_page(iHomefinderConstants::PAGE_INFORMATION, "Configuration", "Configuration", "manage_options", iHomefinderConstants::PAGE_CONFIGURATION, array(iHomefinderAdminConfiguration::getInstance(), "getPage"));
        if ($displayRules->isAgentBioWidgetEnabled()) {
            add_submenu_page(iHomefinderConstants::PAGE_INFORMATION, "Bio Widget", "Bio Widget", "manage_options", iHomefinderConstants::PAGE_BIO, array(iHomefinderAdminBio::getInstance(), "getPage"));
        }
        if ($displayRules->isSocialEnabled()) {
            add_submenu_page(iHomefinderConstants::PAGE_INFORMATION, "Social Widget", "Social Widget", "manage_options", iHomefinderConstants::PAGE_SOCIAL, array(iHomefinderAdminSocial::getInstance(), "getPage"));
        }
        add_submenu_page(iHomefinderConstants::PAGE_INFORMATION, "Email Branding", "Email Branding", "manage_options", iHomefinderConstants::PAGE_EMAIL_BRANDING, array(iHomefinderAdminEmail::getInstance(), "getPage"));
        if ($displayRules->isCommunityPagesEnabled()) {
            add_submenu_page(iHomefinderConstants::PAGE_INFORMATION, "Community Pages", "Community Pages", "manage_options", iHomefinderConstants::PAGE_COMMUNITY_PAGES, array(iHomefinderAdminCommunityPages::getInstance(), "getPage"));
        }
        if ($displayRules->isSeoCityLinksEnabled()) {
            add_submenu_page(iHomefinderConstants::PAGE_INFORMATION, "SEO City Links", "SEO City Links", "manage_options", iHomefinderConstants::PAGE_SEO_CITY_LINKS, array(iHomefinderAdminSeoCityLinks::getInstance(), "getPage"));
        }
    }
    
    /**
     * Create register option groups and associated options.
     * Later use settings_fields in the forms to populate the options.
     */
    public function registerSettings()
    {
        //compatibility check shows on all dashboard pages
        register_setting(
            iHomefinderConstants::OPTION_GROUP_COMPATIBILITY_CHECK,
            iHomefinderConstants::COMPATIBILITY_CHECK_ENABLED,
            array($this, 'sanitize_compatibility_check_enabled')
        );
        //admin pages. The Register page registers nothing: its three actions are admin-post
        //handlers, so options.php can never null a token it was not sent (MAX-4432).
        iHomefinderAdminPageConfig::getInstance()->registerSettings();
        iHomefinderAdminConfiguration::getInstance()->registerSettings();
        iHomefinderAdminBio::getInstance()->registerSettings();
        iHomefinderAdminSocial::getInstance()->registerSettings();
        iHomefinderAdminEmail::getInstance()->registerSettings();
        iHomefinderAdminSeoCityLinks::getInstance()->registerSettings();
    }
    
    public function sanitize_compatibility_check_enabled($input)
    {
        return isset($input) ? true : false;
    }
    public function addScripts() {
        $pages = array(
            iHomefinderConstants::PAGE_INFORMATION,
            iHomefinderConstants::PAGE_ACTIVATE,
            iHomefinderConstants::PAGE_IDX_CONTROL_PANEL,
            iHomefinderConstants::PAGE_IDX_PAGES,
            iHomefinderConstants::PAGE_CONFIGURATION,
            iHomefinderConstants::PAGE_BIO,
            iHomefinderConstants::PAGE_SOCIAL,
            iHomefinderConstants::PAGE_EMAIL_BRANDING,
            iHomefinderConstants::PAGE_COMMUNITY_PAGES,
            iHomefinderConstants::PAGE_SEO_CITY_LINKS
        );

        $should_load = isset($_GET['page']) && array_search($_GET['page'], $pages, true) !== false;

        if ( $should_load ) {
            // jQuery + UI
            wp_enqueue_script('jquery');
            wp_enqueue_script('jquery-ui-core');
            wp_enqueue_script('jquery-ui-widget');
            wp_enqueue_script('jquery-ui-position');
            wp_enqueue_script('jquery-ui-accordion');
            wp_enqueue_script('jquery-ui-autocomplete');

            // Thickbox (style + script)
            wp_enqueue_style('thickbox');
            wp_enqueue_script('thickbox');

            // Other deps
            wp_enqueue_script(
                'jquery-textrange',
                plugins_url('js/jquery-textrange.js', __FILE__),
                array('jquery'),
                iHomefinderConstants::VERSION,
                true
            );

            // Your admin script (footer, and depends on thickbox so order is guaranteed)
            wp_enqueue_script(
                'oe-dashboard',
                plugins_url('js/dashboard.js', __FILE__),
                array('jquery', 'editor', 'media-upload', 'thickbox'),
                iHomefinderConstants::VERSION,
                true
            );

            // Inject the self-healing Thickbox patch AFTER oe-dashboard so it can't be overwritten
            $tb_fix = <<<'JS'
            (function($){
            (function installThickboxFix(){
                var $html = $('html');
                var $body = $('body');

                function rememberScrollState(){
                if (typeof $body.data('tb-prev-overflow') === 'undefined') {
                    $body.data('tb-prev-overflow', $body[0].style.overflow || null);
                    $body.data('tb-prev-height',   $body[0].style.height   || null);
                    $body.data('tb-prev-position', $body[0].style.position || null);
                }
                if (typeof $html.data('tb-prev-overflow') === 'undefined') {
                    $html.data('tb-prev-overflow', $html[0].style.overflow || null);
                    $html.data('tb-prev-height',   $html[0].style.height   || null);
                }
                }

                function restoreScrollState(){
                // Remove modal classes that might pin scroll via CSS
                $body.removeClass('thickbox-open tb-open modal-open');
                $html.removeClass('thickbox-open tb-open modal-open');

                // Restore inline styles if we recorded them; otherwise clear
                var bo = $body.data('tb-prev-overflow'); $body.css('overflow', bo == null ? '' : bo);
                var bh = $body.data('tb-prev-height');   $body.css('height',   bh == null ? '' : bh);
                var bp = $body.data('tb-prev-position'); $body.css('position', bp == null ? '' : bp);

                var ho = $html.data('tb-prev-overflow'); $html.css('overflow', ho == null ? '' : ho);
                var hh = $html.data('tb-prev-height');   $html.css('height',   hh == null ? '' : hh);

                // Clear our saved data
                $body.removeData('tb-prev-overflow tb-prev-height tb-prev-position');
                $html.removeData('tb-prev-overflow tb-prev-height');

                // If something still hijacked scroll, force-enable as a fallback
                // (kept last to avoid fighting legitimate page styles)
                if ( (getComputedStyle(document.documentElement).overflow || '').includes('hidden') ||
                    (getComputedStyle(document.body).overflow || '').includes('hidden') ) {
                    $html.css('overflow', 'auto');
                    $body.css('overflow', 'auto');
                }
                }

                function hardClose(){
                try { $('#TB_window').trigger('tb_unload'); } catch(e){}
                $('#TB_window, #TB_overlay, #TB_HideSelect, #TB_iframeContent').remove();
                restoreScrollState();
                }

                function applyOnce(){
                // Patch tb_remove
                if (typeof window.tb_remove !== 'function' || !window.tb_remove.__tbPatched){
                    function patchedRemove(){ hardClose(); }
                    patchedRemove.__tbPatched = true;
                    window.tb_remove = patchedRemove;
                }

                // Patch tb_show: start clean *and* remember scroll state before Thickbox locks it
                if (typeof window.tb_show === 'function' && !window.tb_show.__tbPatched){
                    var _show = window.tb_show;
                    function patchedShow(){
                    // If a previous TB is lingering, clean it first
                    if ($('#TB_overlay').length || $('#TB_window').length) {
                        hardClose();
                    }
                    rememberScrollState(); // capture html/body inline styles before TB sets overflow:hidden
                    return _show.apply(this, arguments);
                    }
                    patchedShow.__tbPatched = true;
                    window.tb_show = patchedShow;
                }

                // Bind overlay / X once
                if (!window.__tbFixBound){
                    $(document)
                    .off('click.tbfix', '#TB_overlay, #TB_closeWindowButton, .tb-close, .tb-close-icon')
                    .on('click.tbfix', '#TB_overlay, #TB_closeWindowButton, .tb-close, .tb-close-icon', function(e){
                        e.preventDefault();
                        hardClose();
                        return false;
                    });

                    // Finish cleanup after TB does its removals
                    $(document).off('tb_unload.tbfix').on('tb_unload.tbfix', function(){
                    setTimeout(hardClose, 0);
                    });

                    window.__tbFixBound = true;
                }
                }

                applyOnce();

                // Re-apply if tb_show/tb_remove get overwritten
                if (!window.__tbFixWatch){
                try{
                    var mo = new MutationObserver(function(){ applyOnce(); });
                    mo.observe(document.documentElement, { childList:true, subtree:true });
                    window.__tbFixWatch = mo;
                }catch(e){
                    window.__tbFixTimer = setInterval(applyOnce, 1500);
                }
                }

                // Only nuke leftovers if an overlay is actually present
                if ($('#TB_overlay').length || $('#TB_window').length) {
                hardClose();
                } else {
                // Ensure scroll is normal on page load
                restoreScrollState();
                }
            })();
            })(jQuery);
            JS;

            wp_add_inline_script('oe-dashboard', $tb_fix, 'after');

            // Admin CSS
            wp_enqueue_style('oe-dashboard', plugins_url('css/dashboard.css', __FILE__), array(), iHomefinderConstants::VERSION);
        }
    }
    
    /**
     * @deprecated - use activateAuthenticationToken instead
     * @return string one of the ACTIVATE_* outcomes
     */
    public function updateAuthenticationToken()
    {
        return $this->activateAuthenticationToken();
    }

    /**
     * Sends the "activate" bundle (registration key, URLs, branding) to ihf-root and stores the
     * authentication token it returns. Credential-free in every context on 8.8.0: it never
     * mints or ships a blog credential; when a rotation is owed it marks the blog pending and
     * provisionBlogIntegration() does the work from the next admin request or the retry event.
     *
     * A candidate key is passed through to the request and written to ACTIVATION_TOKEN_OPTION
     * only when the outcome is ACTIVATE_ACTIVATED: a rejected or unreachable attempt leaves the
     * previous key and the previous token in place, so the site stays connected under the
     * previous key and the front-end config never carries an unvalidated one (MAX-4432).
     *
     * Called from every settings-page save, the three Register page actions, wp_initialize_site,
     * and the signed cache-clear ping (under the provisioning lock, see
     * handleSignedCacheClearPing()). getLastActivateDetail() describes a failure afterwards.
     *
     * @param string|null $activationToken a candidate key to try; the stored key is used when empty
     * @return string ACTIVATE_ACTIVATED (a token came back), ACTIVATE_REJECTED (HTTP 401 or 403),
     *                ACTIVATE_UNREACHABLE (WP_Error or any other failure), ACTIVATE_NO_KEY
     */
    public function activateAuthenticationToken($activationToken = null)
    {
        $this->lastActivateDetail = '';
        $candidate = is_scalar($activationToken) ? trim((string) $activationToken) : '';
        $storedKey = (string) $this->getActivationToken();
        $remoteResponse = $this->getAuthenticationInfo($candidate !== '' ? $candidate : $storedKey);
        if ($remoteResponse === null) {
            return self::ACTIVATE_NO_KEY;
        }
        $authenticationInfo = $remoteResponse->getResponse();
        // Success is a response carrying a non-empty authentication token, not merely a
        // non-null body: remotePostRequest() hands back a {view} object for any HTTP status
        // of 400 or more, and treating that as activated used to mark a rejected or failed
        // registration as live. A WP_Error arrives here as null; an HTTP rejection as an
        // object without a token. Neither runs the post-activation block.
        if ($this->hasAuthenticationToken($authenticationInfo)) {
            if ($candidate !== '' && $candidate !== $storedKey) {
                update_option(iHomefinderConstants::ACTIVATION_TOKEN_OPTION, $candidate);
                if ($storedKey !== '' && $this->previouslyActivated()) {
                    // The site is now bound to a different account, which holds no blog
                    // credential for it yet: rotate so the runner ships one under the new key.
                    update_option(iHomefinderConstants::BLOG_FORCE_ROTATE_OPTION, '1');
                    update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, '1');
                }
            }
            $this->setAuthenticationToken((string) $authenticationInfo->authenticationToken);
            if (property_exists($authenticationInfo, "permissions")) {
                $permissions = $authenticationInfo->permissions;
                iHomefinderDisplayRules::getInstance()->setPermissions($permissions);
            }
            update_option(iHomefinderConstants::IS_ACTIVATED_OPTION, "true");
            //We need to flush the rewrite rules, if any permalinks have been updated.
            //Only flush in the admin screens, because that is the only point where urls patterns may change.
            iHomefinderRewriteRules::getInstance()->flushRules();
            //clear the cache
            iHomefinderCacheUtility::getInstance()->deleteItems();
            //update menu with any new available menu items
            iHomefinderMenu::getInstance()->updateMenu();
            return self::ACTIVATE_ACTIVATED;
        }
        // ihf-root answers 401 only when no ClientAuthentication row matches the key, so 401
        // and 403 are the only statuses that mean "bad key"; everything else is an outage.
        $status = $remoteResponse->getHttpStatusCode();
        $this->lastActivateDetail = $remoteResponse->getFailureDetail();
        if ($status === 401 || $status === 403) {
            return self::ACTIVATE_REJECTED;
        }
        return self::ACTIVATE_UNREACHABLE;
    }

    /**
     * Why the last activateAuthenticationToken() call did not succeed, in the fixed vocabulary
     * of iHomefinderRemoteResponse::getFailureDetail() ("HTTP 500", "cURL error 28", ...);
     * '' after a success, a no-key return, or before any call. Never a URL, key or token.
     *
     * @return string
     */
    public function getLastActivateDetail()
    {
        return $this->lastActivateDetail;
    }
    
    public function deleteAuthenticationToken()
    {
        delete_option(iHomefinderConstants::AUTHENTICATION_TOKEN_OPTION);
    }

    /**
     * Unregister: durable revocation of this blog's service credential (the per-site
     * password, uuid and demotion grace; a no-op on a never-migrated blog, and the shared user's
     * row is never written), then both tokens and the activated flag are deleted. Called by the
     * Unregister button's handler directly rather than through the activation key hook, because
     * delete_option() fires delete_option_*, not update_option_*. Idempotent. After it the key
     * is empty, so a signed ping cannot verify and stays cache-only, and nothing schedules or
     * provisions until the site registers again.
     *
     * @return void
     */
    public function unregister()
    {
        $blogId = get_current_blog_id();
        $this->revokeServiceCredentials('unregister');
        $this->deleteAuthenticationToken();
        delete_option(iHomefinderConstants::ACTIVATION_TOKEN_OPTION);
        delete_option(iHomefinderConstants::IS_ACTIVATED_OPTION);
        error_log(sprintf('[Optima Express] unregistered blog %d', $blogId));
    }

    /**
     * admin-post handler of the Register page's "Register" button (action REGISTER_KEY_ACTION):
     * nonce, manage_options, then the posted candidate key goes through
     * activateAuthenticationToken() and is stored only on success. The outcome is carried to
     * the page in ACTIVATE_RESULT_TRANSIENT; a failure returns to the key form, a success to the
     * registered view. A ?reg= link only pre-fills the form; this is the one path that registers.
     *
     * @return void
     */
    public function handleRegisterKey()
    {
        check_admin_referer(self::REGISTER_KEY_ACTION);
        $this->requireManageOptions('register Optima Express');
        $candidate = '';
        if (isset($_POST[self::REGISTRATION_KEY_FIELD]) && is_scalar($_POST[self::REGISTRATION_KEY_FIELD])) {
            $candidate = sanitize_text_field(wp_unslash((string) $_POST[self::REGISTRATION_KEY_FIELD]));
        }
        if ($candidate === '') {
            $this->storeActivateResult(self::ACTIVATE_NO_KEY, '');
            $this->redirectToRegisterPage('enter-reg-key');
        }
        $outcome = $this->activateAuthenticationToken($candidate);
        $this->storeActivateResult($outcome, $this->getLastActivateDetail());
        error_log(sprintf('[Optima Express] register key for blog %d by user %d: %s', get_current_blog_id(), get_current_user_id(), $outcome));
        $this->redirectToRegisterPage($outcome === self::ACTIVATE_ACTIVATED ? null : 'enter-reg-key');
    }

    /**
     * admin-post handler of "Refresh Registration" (action REFRESH_REGISTRATION_ACTION): nonce,
     * manage_options, activateAuthenticationToken() with the stored key. Writes no option itself;
     * a rejected or unreachable response leaves both tokens exactly as they were.
     *
     * @return void
     */
    public function handleRefreshRegistration()
    {
        check_admin_referer(self::REFRESH_REGISTRATION_ACTION);
        $this->requireManageOptions('refresh the Optima Express registration');
        $outcome = $this->activateAuthenticationToken();
        $this->storeActivateResult($outcome, $this->getLastActivateDetail());
        error_log(sprintf('[Optima Express] refresh registration for blog %d by user %d: %s', get_current_blog_id(), get_current_user_id(), $outcome));
        $this->redirectToRegisterPage(null);
    }

    /**
     * admin-post handler of "Unregister" (action UNREGISTER_ACTION): nonce, manage_options,
     * unregister(), then back to the page.
     *
     * @return void
     */
    public function handleUnregister()
    {
        check_admin_referer(self::UNREGISTER_ACTION);
        $this->requireManageOptions('unregister Optima Express');
        $this->unregister();
        $this->storeActivateResult(self::RESULT_UNREGISTERED, '');
        $this->redirectToRegisterPage(null);
    }

    /**
     * The Register page reads (and deletes) the outcome its last action stored for this user,
     * or null when there is none. Only the three admin-post handlers write it, so a settings
     * save on another page can never surface a message here.
     *
     * @return array|null {outcome, detail}
     */
    public function consumeActivateResult()
    {
        $key = self::ACTIVATE_RESULT_TRANSIENT . get_current_user_id();
        $result = get_transient($key);
        if (!is_array($result) || empty($result['outcome'])) {
            return null;
        }
        delete_transient($key);
        return array(
            'outcome' => (string) $result['outcome'],
            'detail' => isset($result['detail']) && is_scalar($result['detail']) ? (string) $result['detail'] : '',
        );
    }

    private function storeActivateResult($outcome, $detail)
    {
        set_transient(
            self::ACTIVATE_RESULT_TRANSIENT . get_current_user_id(),
            array('outcome' => (string) $outcome, 'detail' => (string) $detail),
            self::ACTIVATE_RESULT_INTERVAL
        );
    }

    private function requireManageOptions($what)
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to ' . $what . '.');
        }
    }

    /**
     * Redirects to this blog's Register page (per sub-site on multisite) and exits.
     *
     * @param string|null $section a section of the page, or null for its main view
     * @return void
     */
    private function redirectToRegisterPage($section)
    {
        $url = admin_url('admin.php?page=' . iHomefinderConstants::PAGE_ACTIVATE);
        if ($section !== null && $section !== '') {
            $url .= '&section=' . rawurlencode($section);
        }
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Durable revocation of this blog's service credential: every application password on the
     * pinned per-site service user is deleted and the confirmed uuid and the demotion grace are
     * forgotten, so the next provisioning attempt (trigger 1) mints afresh. The shared user's
     * password row is never written (P1). Called on deactivation (iHomefinderInstaller::remove()),
     * from the Unregister routine, and as the belt when any writer clears the activation key
     * (handleActivationTokenUpdated()). A blog with no pinned service user only drops the two
     * options.
     *
     * @param string $reason for the log line
     * @return int number of application passwords removed
     */
    public function revokeServiceCredentials($reason = '')
    {
        $blogId = get_current_blog_id();
        $removed = 0;
        $serviceUserId = $this->getPinnedServiceUserId();
        if ($serviceUserId > 0 && class_exists('WP_Application_Passwords')) {
            $this->clearUserMetaCache($serviceUserId);
            $removed = count(WP_Application_Passwords::get_user_application_passwords($serviceUserId));
            if ($removed > 0) {
                WP_Application_Passwords::delete_all_application_passwords($serviceUserId);
                $this->clearUserMetaCache($serviceUserId);
            }
        }
        delete_option(iHomefinderConstants::BLOG_SERVICE_PASSWORD_UUID_OPTION);
        delete_option(iHomefinderConstants::SHARED_DEMOTE_AFTER_OPTION);
        error_log(sprintf('[Optima Express] revoked %d service password(s) for blog %d (%s)', $removed, $blogId, $reason === '' ? 'revoke' : $reason));
        return $removed;
    }

    /**
     * update_option_ihf_activation_token handler, the one sanctioned option hook: when any
     * writer clears the registration key to an empty value the service credential is revoked.
     * It never activates anything. update_option() does not fire when old and new are equal,
     * so a repeated clear is a no-op; delete_option() fires delete_option_*, not this, which is
     * why the Unregister routine calls revokeServiceCredentials() directly.
     *
     * @param mixed $oldValue
     * @param mixed $newValue
     * @return void
     */
    public function handleActivationTokenUpdated($oldValue, $newValue)
    {
        if (is_scalar($newValue) && trim((string) $newValue) !== '') {
            return;
        }
        if (!is_scalar($newValue) && !empty($newValue)) {
            return;
        }
        $this->revokeServiceCredentials('activation key cleared');
    }
    
    public function getActivationToken()
    {
        $activationToken = get_option(iHomefinderConstants::ACTIVATION_TOKEN_OPTION, null);
        return $activationToken;
    }
    
    public function getAuthenticationToken()
    {
        $authenticationToken = get_option(iHomefinderConstants::AUTHENTICATION_TOKEN_OPTION, null);
        return $authenticationToken;
    }
    
    public function setAuthenticationToken($authenticationToken)
    {
        update_option(iHomefinderConstants::AUTHENTICATION_TOKEN_OPTION, $authenticationToken);
    }
    
    public function previouslyActivated()
    {
        return get_option(iHomefinderConstants::IS_ACTIVATED_OPTION, false);
    }
    
    public function isActivated()
    {
        $result = false;
        $authenticationToken = $this->getAuthenticationToken();
        if (!empty($authenticationToken)) {
            $result = true;
        }
        return $result;
    }
    
    /**
     * Builds and sends the "activate" request. Returns the transport response object (its
     * getResponse() is the decoded body, getHttpStatusCode() the status or null on WP_Error),
     * or null when no registration key is available.
     *
     * @param string|null $activationToken the key to send; the stored key when empty
     * @return iHomefinderRemoteResponse|null
     */
    private function getAuthenticationInfo($activationToken = null)
    {
        if (!is_scalar($activationToken) || trim((string) $activationToken) === '') {
            $activationToken = $this->getActivationToken();
        }
        if (empty($activationToken)) {
            return null;
        }
        // Rotation policy (D4): when one of the four triggers holds and this blog is not
        // halted, mark it pending and nothing more. The credential is minted and shipped by
        // provisionBlogIntegration() under the lock, never by this bundle. A paused blog is
        // not marked here; the tick marks it after un-pause.
        if ($this->previouslyActivated() && $this->shouldRotate() && !$this->migrationHaltedFor(get_current_blog_id())) {
            update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, "1");
        }
        $urlFactory = iHomefinderUrlFactory::getInstance();
        $ajaxBaseUrl = urlencode($urlFactory->getAjaxBaseUrl());
        $listingsSearchResultsUrl = urlencode($urlFactory->getListingsSearchResultsUrl(true));
        $listingsSearchFormUrl = urlencode($urlFactory->getListingsSearchFormUrl(true));
        $listingDetailUrl = urlencode($urlFactory->getListingDetailUrl(true));
        $featuredSearchResultsUrl = urlencode($urlFactory->getFeaturedSearchResultsUrl(true));
        $hotSheetListingReportUrl = urlencode($urlFactory->getHotSheetListingReportUrl(true));
        $hotSheetOpenHomeReportUrl = urlencode($urlFactory->getHotSheetOpenHomeReportUrl(true));
        $hotSheetMarketReportUrl = urlencode($urlFactory->getHotSheetMarketReportUrl(true));
        $organizerLoginUrl = urlencode($urlFactory->getOrganizerLoginUrl(true));
        $organizerLogoutUrl = urlencode($urlFactory->getOrganizerLogoutUrl(true));
        $organizerLoginSubmitUrl = urlencode($urlFactory->getOrganizerLoginSubmitUrl(true));
        $organizerEditSavedSearchUrl = urlencode($urlFactory->getOrganizerEditSavedSearchUrl(true));
        $organizerEditSavedSearchSubmitUrl = urlencode($urlFactory->getOrganizerEditSavedSearchSubmitUrl(true));
        $organizerDeleteSavedSearchSubmitUrl = urlencode($urlFactory->getOrganizerDeleteSavedSearchSubmitUrl(true));
        $organizerViewSavedSearchUrl = urlencode($urlFactory->getOrganizerViewSavedSearchUrl(true));
        $organizerViewSavedSearchListUrl = urlencode($urlFactory->getOrganizerViewSavedSearchListUrl(true));
        $organizerViewSavedListingListUrl = urlencode($urlFactory->getOrganizerViewSavedListingListUrl(true));
        $organizerDeleteSavedListingUrl = urlencode($urlFactory->getOrganizerDeleteSavedListingUrl(true));
        $organizerResendConfirmationEmailUrl = urlencode($urlFactory->getOrganizerResendConfirmationEmailUrl(true));
        $organizerActivateSubscriberUrl = urlencode($urlFactory->getOrganizerActivateSubscriberUrl(true));
        $organizerSendSubscriberPasswordUrl = urlencode($urlFactory->getOrganizerSendSubscriberPasswordUrl(true));
        $listingsAdvancedSearchFormUrl = urlencode($urlFactory->getListingsAdvancedSearchFormUrl(true));
        $organizerHelpUrl = urlencode($urlFactory->getOrganizerHelpUrl(true));
        $organizerEditSubscriberUrl = urlencode($urlFactory->getOrganizerEditSubscriberUrl(true));
        $contactFormUrl = urlencode($urlFactory->getContactFormUrl(true));
        $valuationFormUrl = urlencode($urlFactory->getValuationFormUrl(true));
        $mortgageCalculatorUrl = urlencode($urlFactory->getMortgageCalculatorUrl(true));
        $listingSoldDetailUrl = urlencode($urlFactory->getListingSoldDetailUrl(true));
        $openHomeSearchFormUrl = urlencode($urlFactory->getOpenHomeSearchFormUrl(true));
        $soldFeaturedListingUrl = urlencode($urlFactory->getSoldFeaturedListingUrl(true));
        $pendingFeaturedListingUrl = urlencode($urlFactory->getPendingFeaturedListingUrl(true));
        $supplementalListingUrl = urlencode($urlFactory->getSupplementalListingUrl(true));
        $officeListUrl = urlencode($urlFactory->getOfficeListUrl(true));
        $officeDetailUrl = urlencode($urlFactory->getOfficeDetailUrl(true));
        $agentBioListUrl = urlencode($urlFactory->getAgentListUrl(true));
        $agentBioDetailUrl = urlencode($urlFactory->getAgentDetailUrl(true));
        $mapSearchUrl = urlencode($urlFactory->getMapSearchFormUrl(true));
        $mlsPortalBoardOfficeListUrl = urlencode($urlFactory->getMlsPortalBoardOfficeListUrl(true));
        $mlsPortalBoardOfficeListNameStartsWithUrl = urlencode($urlFactory->getMlsPortalBoardOfficeListNameStartsWithUrl(true));
        $mlsPortalBoardOfficeDetailUrl = urlencode($urlFactory->getMlsPortalBoardOfficeDetailUrl(true));
        $mlsPortalBoardMemberListUrl = urlencode($urlFactory->getMlsPortalBoardMemberListUrl(true));
        $mlsPortalBoardMemberListLastNameStartsWithUrl = urlencode($urlFactory->getMlsPortalBoardMemberListLastNameStartsWithUrl(true));
        $mlsPortalBoardMemberDetailUrl = urlencode($urlFactory->getMlsPortalBoardMemberDetailUrl(true));
        $mlsPortalBoardMemberSearchUrl = urlencode($urlFactory->getMlsPortalBoardMemberSearchUrl(true));
        $mlsPortalBoardOfficeSearchUrl = urlencode($urlFactory->getMlsPortalBoardOfficeSearchUrl(true));
        $cssOverride = urlencode(get_option(iHomefinderConstants::CSS_OVERRIDE_OPTION, null));
        $shadowDomHtml = urlencode(get_option(iHomefinderConstants::SHADOW_DOM_HTML_OPTION, null));
        $shadowDomCss = urlencode(get_option(iHomefinderConstants::SHADOW_DOM_CSS_OPTION, null));
        $mobileSiteYn = get_option(iHomefinderConstants::OPTION_MOBILE_SITE_YN, null);
        $emailDisplayType = get_option(iHomefinderConstants::EMAIL_DISPLAY_TYPE_OPTION, null);
        $emailHeader = urlencode(iHomefinderAdminEmail::getInstance()->getHeader());
        $emailFooter = urlencode(iHomefinderAdminEmail::getInstance()->getFooter());
        $emailPhotoUrl = get_option(iHomefinderConstants::EMAIL_PHOTO_OPTION, null);
        $emailLogoUrl = get_option(iHomefinderConstants::EMAIL_LOGO_OPTION, null);
        $emailName = get_option(iHomefinderConstants::EMAIL_NAME_OPTION, null);
        $emailCompany = get_option(iHomefinderConstants::EMAIL_COMPANY_OPTION, null);
        $emailPhone = get_option(iHomefinderConstants::EMAIL_PHONE_OPTION, null);
        $emailAddressLine1 = get_option(iHomefinderConstants::EMAIL_ADDRESS_LINE1_OPTION, null);
        $emailAddressLine2 = get_option(iHomefinderConstants::EMAIL_ADDRESS_LINE2_OPTION, null);

        $emailBrandingType = null;
        switch ($emailDisplayType) {
            case iHomefinderAdminEmail::EMAIL_DISPLAY_TYPE_CUSTOM_IMAGES_VALUE;
            case iHomefinderAdminEmail::EMAIL_DISPLAY_TYPE_DEFAULT_VALUE;
                $emailBrandingType = "basic";
            break;
            case iHomefinderAdminEmail::EMAIL_DISPLAY_TYPE_CUSTOM_HTML_VALUE;
                $emailBrandingType = "custom";
            break;
        }
        
        $remoteRequest = new iHomefinderRequestor();
        $remoteRequest
            ->addParameter("requestType", "activate")
            ->addParameter("activationToken", $activationToken)
            ->addParameter("ajaxBaseUrl", $ajaxBaseUrl)
            ->addParameter("type", "WordPress")
            ->addParameter("layoutType", "responsive")
            ->addParameter("listingSearchResultsUrl", $listingsSearchResultsUrl)
            ->addParameter("listingSearchByAddressResultsUrl", $listingsSearchResultsUrl)
            ->addParameter("listingSearchByListingIdResultsUrl", $listingsSearchResultsUrl)
            ->addParameter("listingSearchFormUrl", $listingsSearchFormUrl)
            ->addParameter("listingDetailUrl", $listingDetailUrl)
            ->addParameter("featuredSearchResultsUrl", $featuredSearchResultsUrl)
            ->addParameter("hotsheetSearchResultsUrl", $hotSheetListingReportUrl)
            ->addParameter("hotSheetOpenHomeReportUrl", $hotSheetOpenHomeReportUrl)
            ->addParameter("hotSheetMarketReportUrl", $hotSheetMarketReportUrl)
            ->addParameter("organizerLoginUrl", $organizerLoginUrl)
            ->addParameter("organizerLogoutUrl", $organizerLogoutUrl)
            ->addParameter("organizerLoginSubmitUrl", $organizerLoginSubmitUrl)
            ->addParameter("organizerEditSavedSearchUrl", $organizerEditSavedSearchUrl)
            ->addParameter("organizerEditSavedSearchSubmitUrl", $organizerEditSavedSearchSubmitUrl)
            ->addParameter("organizerDeleteSavedSearchSubmitUrl", $organizerDeleteSavedSearchSubmitUrl)
            ->addParameter("organizerViewSavedSearchUrl", $organizerViewSavedSearchUrl)
            ->addParameter("organizerViewSavedSearchListUrl", $organizerViewSavedSearchListUrl)
            ->addParameter("organizerViewSavedListingListUrl", $organizerViewSavedListingListUrl)
            ->addParameter("organizerDeleteSavedListingUrl", $organizerDeleteSavedListingUrl)
            ->addParameter("organizerResendConfirmationEmailUrl", $organizerResendConfirmationEmailUrl)
            ->addParameter("organizerActivateSubscriberUrl", $organizerActivateSubscriberUrl)
            ->addParameter("organizerSendSubscriberPasswordUrl", $organizerSendSubscriberPasswordUrl)
            ->addParameter("listingAdvancedSearchFormUrl", $listingsAdvancedSearchFormUrl)
            ->addParameter("organizerHelpUrl", $organizerHelpUrl)
            ->addParameter("organizerEditSubscriberUrl", $organizerEditSubscriberUrl)
            ->addParameter("contactFormUrl", $contactFormUrl)
            ->addParameter("valuationFormUrl", $valuationFormUrl)
            ->addParameter("mortgageCalculatorUrl", $mortgageCalculatorUrl)
            ->addParameter("listingSoldDetailUrl", $listingSoldDetailUrl)
            ->addParameter("openHomeSearchFormUrl", $openHomeSearchFormUrl)
            ->addParameter("soldFeaturedListingUrl", $soldFeaturedListingUrl)
            ->addParameter("pendingFeaturedListingUrl", $pendingFeaturedListingUrl)
            ->addParameter("supplementalListingUrl", $supplementalListingUrl)
            ->addParameter("officeListUrl", $officeListUrl)
            ->addParameter("officeDetailUrl", $officeDetailUrl)
            ->addParameter("agentBioListUrl", $agentBioListUrl)
            ->addParameter("agentBioDetailUrl", $agentBioDetailUrl)
            ->addParameter("mapSearchUrl", $mapSearchUrl)
            ->addParameter("cssOverride", $cssOverride)
            ->addParameter("shadowDomHtml", $shadowDomHtml)
            ->addParameter("shadowDomCss", $shadowDomCss)
            ->addParameter("mlsPortalBoardOfficeResultsUrl", $mlsPortalBoardOfficeListUrl)
            ->addParameter("mlsPortalBoardOfficeResultsLastNameStartsWithUrl", $mlsPortalBoardOfficeListNameStartsWithUrl)
            ->addParameter("mlsPortalBoardOfficeDetailUrl", $mlsPortalBoardOfficeDetailUrl)
            ->addParameter("mlsPortalBoardMemberResultsUrl", $mlsPortalBoardMemberListUrl)
            ->addParameter("mlsPortalBoardMemberResultsLastNameStartsWithUrl", $mlsPortalBoardMemberListLastNameStartsWithUrl)
            ->addParameter("mlsPortalBoardMemberDetailUrl", $mlsPortalBoardMemberDetailUrl)
            ->addParameter("mlsPortalBoardMemberSearchUrl", $mlsPortalBoardMemberSearchUrl)
            ->addParameter("mlsPortalBoardOfficeSearchUrl", $mlsPortalBoardOfficeSearchUrl)
            ->addParameter("mobileSiteYn", $mobileSiteYn)
            ->addParameter("emailBrandingType", $emailBrandingType)
            ->addParameter("emailHeader", $emailHeader)
            ->addParameter("emailFooter", $emailFooter)
            ->addParameter("emailPhotoUrl", $emailPhotoUrl)
            ->addParameter("emailLogoUrl", $emailLogoUrl)
            ->addParameter("emailName", $emailName)
            ->addParameter("emailCompany", $emailCompany)
            ->addParameter("emailPhone", $emailPhone)
            ->addParameter("emailAddressLine1", $emailAddressLine1)
            ->addParameter("emailAddressLine2", $emailAddressLine2);

        // No wpAppUsername / wpAppPassword / wpRestBase: the bundle is credential-free. ihf-root
        // touches the integration only when all three are present, so their absence is a no-op
        // there (CVE-2026-93901 lived in this bundle's credential minting; it is gone).
        return $remoteRequest->remotePostRequest();
    }
    
    /**
     * Provisions this blog's per-site credential and delivers it to ihf-root: resolve the
     * service user, mint one application password, POST provision-blog-integration, confirm.
     * The only function that mints or ships a blog credential; the whole round trip runs under
     * the per-blog lock. Called from retryBlogProvisioningIfPending() (admin_init, inline, as
     * on 8.7.7), from the retry cron event, and from the installer.
     *
     * Every exit that leaves the blog without a confirmed credential keeps the pending marker
     * set, except the halted gate (a paused blog must not be marked pending) and the retired
     * gate (which removes it), so the next admin request or retry event picks the work up.
     * Only a completed round trip clears it. Throws on a failed POST or a lost lock so the
     * existing catch sites (the retry runner and upgrade()) record and swallow
     * it exactly as they did before.
     *
     * @return void
     * @throws Exception
     */
    public function provisionBlogIntegration()
    {
        $blogId = get_current_blog_id();
        // (a) Halted: the wp-config kill switch, or the network pause on a blog that still has
        // the legacy shared user. Nothing is written; the tick marks it pending after un-pause.
        if ($this->migrationHaltedFor($blogId)) {
            error_log(sprintf('[Optima Express] provisionBlogIntegration skipped for blog %d: migration halted', $blogId));
            return;
        }
        // (b) A deleted, archived or spam sub-site never provisions. Drop the marker so nothing
        // keeps scheduling work for it.
        if ($this->blogIsRetired($blogId)) {
            $this->recordBlogProvisionError('site is deleted, archived or spam', self::STAGE_SITE_RETIRED);
            delete_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION);
            error_log(sprintf('[Optima Express] provisionBlogIntegration skipped for blog %d: site retired', $blogId));
            return;
        }
        $authenticationToken = get_option(iHomefinderConstants::AUTHENTICATION_TOKEN_OPTION);
        if (empty($authenticationToken)) {
            update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, "1");
            error_log(sprintf('[Optima Express] provisionBlogIntegration skipped for blog %d: site not yet activated', $blogId));
            return;
        }
        // (c) The whole round trip (mint, POST, confirm) runs under the per-blog lock, so two
        // requests on one blog (two admin tabs, an admin request and the cron retry, two web
        // nodes) cannot both mint a password and race ihf-root for which one it keeps.
        $lockToken = $this->acquireProvisionLock();
        if ($lockToken === null) {
            $this->recordBlogProvisionError('another provisioning attempt holds the lock', self::STAGE_LOCK);
            error_log(sprintf('[Optima Express] provisionBlogIntegration skipped for blog %d: provisioning lock held', $blogId));
            return;
        }
        try {
            // Throttle first, marker second: a front-end scheduler that sees pending must also
            // see the transient, or it queues a second run behind this one.
            set_transient(self::BLOG_PROVISION_RETRY_TRANSIENT . $blogId, 1, self::BLOG_PROVISION_RETRY_INTERVAL);
            update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, "1");
            // Re-read state from the database, not from what this request cached before it
            // took the lock: another holder may have confirmed or rotated in between.
            $this->clearProvisionOptionCaches();
            // A forced rotation is consumed by the first locked attempt whatever its outcome. A
            // flag that survived a non-200 would strip every throttle from the runner until a
            // 200 arrived.
            $forced = !empty(get_option(iHomefinderConstants::BLOG_FORCE_ROTATE_OPTION, null));
            if ($forced) {
                delete_option(iHomefinderConstants::BLOG_FORCE_ROTATE_OPTION);
            }
            $activationToken = get_option(iHomefinderConstants::ACTIVATION_TOKEN_OPTION);
            $restBase = $this->resolveRestBaseToSend();

            // (d) Steps 4 and 5: the user and one password on it. Nothing is revoked here.
            $blogCredentials = $this->provisionBlogCredentials($restBase['restBase']);
            if ($blogCredentials === null) {
                // provisionBlogCredentials() has already recorded the specific stage.
                error_log(sprintf('[Optima Express] provisionBlogIntegration skipped for blog %d: no credential minted', $blogId));
                return;
            }

            // (e) Ship it, but only while the lock is still ours.
            if (!$this->lockStillOurs($lockToken)) {
                // The lock was broken as stale (or the row removed) while we were minting:
                // another holder may now be mid round trip, so do not ship this credential.
                $this->recordBlogProvisionError('provisioning lock lost before the credential was sent', self::STAGE_LOCK);
                throw new Exception(sprintf('[Optima Express] provisioning lock lost for blog %d before the credential was sent', $blogId));
            }
            // Always https (the body carries a plaintext application password); certificate
            // verification is wp_remote_post()'s default and is left in force. Timeout as on 8.7.7.
            $url = iHomefinderRequestor::getServiceUrl();
            $response = wp_remote_post($url, array(
                'timeout' => 200,
                'body'    => array(
                    'requestType'    => iHomefinderConstants::PROVISION_BLOG_INTEGRATION_REQUEST_TYPE,
                    'activationToken' => $activationToken,
                    'wpAppUsername'  => $blogCredentials['username'],
                    'wpAppPassword'  => $blogCredentials['password'],
                    'wpRestBase'     => $blogCredentials['restBase'],
                ),
            ));
            // (g) Anything but a 200 leaves both credentials alone: the previously confirmed
            // one still works, and the unconfirmed one is removed by the next confirm.
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                if (is_wp_error($response)) {
                    $detail = 'WP_Error ' . $response->get_error_code() . ': ' . $response->get_error_message();
                } else {
                    $detail = 'HTTP ' . (int) wp_remote_retrieve_response_code($response) . ' ' . wp_remote_retrieve_response_message($response);
                }
                // Record here, not only in the retry's catch: upgrade() catches
                // this too and would otherwise leave the site's last-error empty.
                $this->recordBlogProvisionError('provision-blog-integration failed: ' . $detail, self::STAGE_PROVISION_POST);
                throw new Exception(sprintf('[Optima Express] provision-blog-integration failed for blog %d: %s', $blogId, $detail));
            }

            // (f) ihf-root holds the credential. Confirm only while the lock is still ours.
            if (!$this->lockStillOurs($lockToken)) {
                // Another holder may be confirming its own. Leave pending set; the next attempt
                // under the lock reconciles rather than two confirms interleaving.
                $this->recordBlogProvisionError('provisioning lock lost before confirm', self::STAGE_CONFIRM);
                throw new Exception(sprintf('[Optima Express] provisioning lock lost for blog %d before confirm', $blogId));
            }
            if (!$this->ensureServicePasswordPersisted($blogCredentials['userId'], $blogCredentials['uuid'], $blogCredentials['item'])) {
                // The password ihf-root now holds is not on the user and could not be put back.
                // Leave pending so the next attempt rotates again, and drop the throttle so that
                // attempt is not 900s away.
                $this->recordBlogProvisionError('minted password missing from the service user after the POST', self::STAGE_CONFIRM);
                delete_transient(self::BLOG_PROVISION_RETRY_TRANSIENT . $blogId);
                error_log(sprintf('[Optima Express] confirm: minted password missing from service user %d on blog %d after the POST; left pending', (int) $blogCredentials['userId'], $blogId));
                return;
            }
            $this->confirmBlogCredentials($blogCredentials['uuid'], $blogCredentials['userId']);
            $this->recordBlogCredentialConfirmed($blogCredentials['uuid'], $restBase['restBase'], $restBase['source'], $blogId);
            error_log(sprintf('[Optima Express] confirm: per-site credential confirmed for blog %d on service user %d (rest base %s%s)', $blogId, (int) $blogCredentials['userId'], $restBase['source'], $forced ? ', forced' : ''));
        } finally {
            // (h)
            $this->releaseProvisionLock($lockToken);
        }
    }

    /*
     * Shared predicates and helpers of the per-site model. Defined once here and used by the
     * runner, the tick (Step 8), demotion (Step 10), the installer (Step 11) and the pages.
     */

    /**
     * True when migration must not proceed on this blog: the absolute kill switch
     * (IHF_BLOG_MIGRATION_DISABLED in wp-config.php), or the operator's network pause on a blog
     * that still has the legacy shared user. A no-legacy blog keeps provisioning through a
     * pause: it has nothing to lose to it. Never gates a network-scope action; see Step 10's
     * retirementHalted() for that.
     *
     * @param int|null $blogId the current blog; callers on another blog switch_to_blog() first.
     *                         Kept in the signature so call sites read as the plan writes them.
     * @return bool
     */
    public function migrationHaltedFor($blogId = null)
    {
        if (defined('IHF_BLOG_MIGRATION_DISABLED') && IHF_BLOG_MIGRATION_DISABLED) {
            return true;
        }
        if (empty(get_site_option(iHomefinderConstants::BLOG_MIGRATION_PAUSED_SITE_OPTION, null))) {
            return false;
        }
        return $this->blogHasLegacySharedUser();
    }

    /**
     * The four rotation triggers (D4, A11), the only reasons a new per-site credential is ever
     * minted: (1) nothing confirmed; (2) the confirmed password is verifiably gone from the
     * service user; (3) an explicit admin reset (BLOG_FORCE_ROTATE_OPTION); (4) the legacy
     * confirmed uuid differs from the snapshot taken at confirm time, meaning an 8.7.7 node
     * re-provisioned the shared way since. Settings saves, cache clears and upgrade() are not
     * triggers. Read-only; the caller decides what to do with a true.
     *
     * @return bool
     */
    public function shouldRotate()
    {
        if ($this->getConfirmedServicePasswordUuid() === null) {
            return true;
        }
        if (!empty(get_option(iHomefinderConstants::BLOG_FORCE_ROTATE_OPTION, null))) {
            return true;
        }
        $snapshot = get_option(iHomefinderConstants::LEGACY_UUID_SNAPSHOT_OPTION, '');
        if ($this->getLegacyConfirmedUuid() !== (is_scalar($snapshot) ? (string) $snapshot : '')) {
            return true;
        }
        // Last because it is the only trigger that reads the service user's usermeta row.
        return $this->confirmedBlogCredentialIsMissing();
    }

    /**
     * admin-post.php handler of the Reset Blog Connection button on the Register page
     * (RESET_BLOG_CONNECTION_ACTION; the authenticated admin_post_ hook only). Trigger 3 of
     * shouldRotate(): flags a forced rotation, marks the blog pending, records the REST base
     * as this interactive request sees it, clears the drift backoff and the demotion throttle,
     * queues the retry event and kicks cron, then redirects back to the page. The retry
     * transient is deliberately left alone: deleting it would hand a fresh window to any older
     * node, and the runner ignores it while the force flag is set anyway.
     *
     * @return void
     */
    public function handleResetBlogConnection()
    {
        check_admin_referer(self::RESET_BLOG_CONNECTION_ACTION);
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to reset the blog connection.');
        }
        $redirect = admin_url('admin.php?page=' . iHomefinderConstants::PAGE_ACTIVATE);
        if (!$this->previouslyActivated() || !$this->isActivated()) {
            wp_safe_redirect($redirect);
            exit;
        }
        $blogId = get_current_blog_id();
        update_option(iHomefinderConstants::BLOG_FORCE_ROTATE_OPTION, '1');
        update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, '1');
        update_option(iHomefinderConstants::BLOG_REST_BASE_TARGET_OPTION, $this->restBaseFromThisRequest(), false);
        delete_option(iHomefinderConstants::BLOG_RESYNC_FAILURES_OPTION);
        delete_option(iHomefinderConstants::BLOG_RESYNC_NEXT_AT_OPTION);
        delete_option(iHomefinderConstants::SHARED_DEMOTE_NEXT_AT_OPTION);
        if (!wp_next_scheduled(iHomefinderConstants::BLOG_PROVISION_RETRY_CRON_HOOK)) {
            wp_schedule_single_event(time(), iHomefinderConstants::BLOG_PROVISION_RETRY_CRON_HOOK);
        }
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
        error_log(sprintf('[Optima Express] reset blog connection requested for blog %d by user %d', $blogId, get_current_user_id()));
        wp_safe_redirect(add_query_arg('ihf_reset', '1', $redirect));
        exit;
    }

    /**
     * The signed half of ihf-root's cache-clear ping (MAX-4440 item 1, restored remote
     * re-activation). Called from the nopriv ihf_clear_cache branch after the cache flush, only
     * when the request carries both SIGNED_PING_TS_PARAM and SIGNED_PING_SIG_PARAM.
     *
     * Verifies, in order: the site is registered and holds a key ("not-activated"); $ts is a
     * unix time within IHF_SIGNED_PING_WINDOW of now ("window"); $sig equals, in constant time,
     * hash_hmac('sha256', $ts, activation key) as lowercase hex ("signature"). The key never
     * leaves either side, so only ihf-root can produce a valid signature, and it is valid for
     * one site and 300s. On success, at most once per SIGNED_REACTIVATE_INTERVAL per blog and
     * only when the provisioning lock is free, activateAuthenticationToken() runs inline
     * (credential-free; success only via a returned token). The throttle is consumed only by a
     * ping that acquires the lock: a ping dropped because a provisioning round trip holds it
     * leaves the throttle unset, so the next ping is free to run. The caller's response body is
     * the same in every case, so nothing here leaks a status.
     *
     * @param mixed $ts
     * @param mixed $sig
     * @return bool true only when re-activation ran and returned a token
     */
    public function handleSignedCacheClearPing($ts, $sig)
    {
        $blogId = get_current_blog_id();
        $ts = is_scalar($ts) ? trim((string) $ts) : '';
        $sig = is_scalar($sig) ? strtolower(trim((string) $sig)) : '';
        $activationKey = $this->getActivationToken();
        if (!$this->previouslyActivated() || empty($activationKey)) {
            error_log(sprintf('[Optima Express] ping-bad-signature for blog %d: not-activated', $blogId));
            return false;
        }
        $window = defined('IHF_SIGNED_PING_WINDOW') ? (int) IHF_SIGNED_PING_WINDOW : 300;
        if ($ts === '' || !ctype_digit($ts) || strlen($ts) > 12 || abs(time() - (int) $ts) > $window) {
            error_log(sprintf('[Optima Express] ping-bad-signature for blog %d: window', $blogId));
            return false;
        }
        $expected = hash_hmac('sha256', $ts, (string) $activationKey);
        if ($sig === '' || !hash_equals($expected, $sig)) {
            error_log(sprintf('[Optima Express] ping-bad-signature for blog %d: signature', $blogId));
            return false;
        }
        $throttleKey = self::SIGNED_REACTIVATE_TRANSIENT . $blogId;
        if (get_transient($throttleKey)) {
            error_log(sprintf('[Optima Express] ping-reactivate for blog %d skipped: throttled', $blogId));
            return false;
        }
        $lockToken = $this->acquireProvisionLock();
        if ($lockToken === null) {
            // Deliberately no throttle here: this ping did not run, so the next one must not
            // have to wait out a window that nothing consumed.
            error_log(sprintf('[Optima Express] ping-reactivate for blog %d skipped: provisioning lock held', $blogId));
            return false;
        }
        try {
            // The throttle is set only once the lock is ours, immediately before the activate.
            set_transient($throttleKey, 1, self::SIGNED_REACTIVATE_INTERVAL);
            $outcome = $this->activateAuthenticationToken();
        } finally {
            $this->releaseProvisionLock($lockToken);
        }
        error_log(sprintf('[Optima Express] ping-reactivate for blog %d: %s', $blogId, $outcome));
        return $outcome === self::ACTIVATE_ACTIVATED;
    }

    /**
     * Whether this blog "has a legacy user": the legacy confirmed uuid is non-empty, or a user
     * with the shared login holds a role here (multisite: is a member of the blog; single site:
     * has a non-empty role list, since is_user_member_of_blog() is true for any user there).
     * Membership, not ownership: an unowned member is still legacy, and ownership decides only
     * whether that user is demoted or recorded as unverifiable (Step 10).
     *
     * Memoized per request per blog, and once a confirmed per-site uuid exists read from the
     * persisted BLOG_HAD_LEGACY_OPTION, because the live test constructs the shared user and
     * primes its very large usermeta row. Pass $fresh to force the live test (the confirm path
     * does, so a rollback that re-added the shared user is seen when the blog re-confirms).
     *
     * @param bool $fresh
     * @return bool
     */
    public function blogHasLegacySharedUser($fresh = false)
    {
        $blogId = get_current_blog_id();
        if (!$fresh && isset(self::$legacyMemo[$blogId])) {
            return self::$legacyMemo[$blogId];
        }
        $result = null;
        if (!$fresh && $this->getConfirmedServicePasswordUuid() !== null) {
            $persisted = get_option(iHomefinderConstants::BLOG_HAD_LEGACY_OPTION, null);
            if ($persisted === '1') {
                $result = true;
            } elseif ($persisted === '0') {
                $result = false;
            }
        }
        if ($result === null) {
            $result = $this->getLegacyConfirmedUuid() !== '';
            if (!$result) {
                $shared = $this->findSharedLogin();
                if ($shared) {
                    if (is_multisite()) {
                        $result = is_user_member_of_blog($shared->ID, $blogId);
                    } else {
                        $result = !empty($shared->roles);
                    }
                }
            }
        }
        self::$legacyMemo[$blogId] = (bool) $result;
        return self::$legacyMemo[$blogId];
    }

    /**
     * Multisite only: true when the site's wp_blogs row is deleted, archived or spam. Always
     * false on single site.
     *
     * @param int|null $blogId
     * @return bool
     */
    public function blogIsRetired($blogId = null)
    {
        if (!is_multisite() || !function_exists('get_site')) {
            return false;
        }
        $site = get_site($blogId === null ? get_current_blog_id() : (int) $blogId);
        if (!$site) {
            return false;
        }
        return !empty((int) $site->deleted) || !empty((int) $site->archived) || !empty((int) $site->spam);
    }

    /**
     * The REST base to send with the credential. The target written by the deciding interactive
     * request (the Reset action, the drift check, the admin_init scheduler) wins when set. When
     * unset (tick triggers, wp_initialize_site, pure cron) the base is built here without ever
     * consulting is_ssl(), which describes the cron request and not the site: the scheme of the
     * last base sent, else the home scheme. In either branch an http base is never produced for
     * a blog whose home is https.
     *
     * @return array {restBase: string, source: REST_BASE_SOURCE_INTERACTIVE|REST_BASE_SOURCE_FALLBACK}
     */
    public function resolveRestBaseToSend()
    {
        $homeScheme = strtolower((string) parse_url(home_url(), PHP_URL_SCHEME));
        $target = get_option(iHomefinderConstants::BLOG_REST_BASE_TARGET_OPTION, '');
        if (is_string($target) && $target !== '') {
            if ($homeScheme === 'https') {
                $target = set_url_scheme($target, 'https');
            }
            return array('restBase' => $target, 'source' => iHomefinderConstants::REST_BASE_SOURCE_INTERACTIVE);
        }
        $scheme = '';
        $sent = get_option(iHomefinderConstants::BLOG_REST_BASE_SENT_OPTION, '');
        if (is_string($sent) && $sent !== '') {
            $scheme = strtolower((string) parse_url($sent, PHP_URL_SCHEME));
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = $homeScheme;
        }
        if ($homeScheme === 'https') {
            $scheme = 'https';
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'http';
        }
        $restBase = set_url_scheme(get_rest_url(null, self::BLOG_POST_REST_PATH), $scheme);
        return array('restBase' => $restBase, 'source' => iHomefinderConstants::REST_BASE_SOURCE_FALLBACK);
    }

    /**
     * The REST base as this (interactive) request sees it, for writers of
     * BLOG_REST_BASE_TARGET_OPTION: the Reset action (Step 7), the drift check and the admin_init
     * scheduler (Step 8). Never http when home is https.
     *
     * @return string
     */
    public function restBaseFromThisRequest()
    {
        $restBase = get_rest_url(null, self::BLOG_POST_REST_PATH);
        if (strtolower((string) parse_url(home_url(), PHP_URL_SCHEME)) === 'https') {
            $restBase = set_url_scheme($restBase, 'https');
        }
        return $restBase;
    }

    /**
     * Records this interactive request's REST base as BLOG_REST_BASE_TARGET_OPTION. The one
     * rule shared by the admin_init scheduler and the installer's inline provisioning after an
     * update: written when no target exists yet, or when application passwords are available
     * on this request (so an https request refreshes an older http target, and an http request
     * never overwrites a usable one). The runner then sends the target instead of the fallback
     * built from the home scheme.
     *
     * @return void
     */
    public function writeRestBaseTargetFromThisRequest()
    {
        $target = get_option(iHomefinderConstants::BLOG_REST_BASE_TARGET_OPTION, '');
        $available = function_exists('wp_is_application_passwords_available') && wp_is_application_passwords_available();
        if (!is_string($target) || $target === '' || $available) {
            update_option(iHomefinderConstants::BLOG_REST_BASE_TARGET_OPTION, $this->restBaseFromThisRequest(), false);
        }
    }

    /**
     * Whether application passwords can authenticate against this blog. When the request is
     * for this blog's own host (not cron, not a switched context) core's own answer is used;
     * otherwise the home scheme decides, since core's test is is_ssl() on the current request.
     *
     * @return bool
     */
    private function applicationPasswordsAvailableForBlog()
    {
        $home = home_url();
        $homeHost = strtolower((string) parse_url($home, PHP_URL_HOST));
        if (!wp_doing_cron() && isset($_SERVER['HTTP_HOST']) && function_exists('wp_is_application_passwords_available')) {
            $requestHost = strtolower((string) parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST));
            if ($requestHost !== '' && $requestHost === $homeHost) {
                return (bool) wp_is_application_passwords_available();
            }
        }
        return strtolower((string) parse_url($home, PHP_URL_SCHEME)) === 'https';
    }

    /**
     * After a 200: make sure the password ihf-root now holds is on the service user's row. A
     * concurrent writer of the same usermeta row could have dropped it between the mint and
     * the POST; if so the item is appended and the row re-read. True when the uuid is present.
     *
     * @param int $userId
     * @param string $uuid
     * @param array $item the second element create_new_application_password() returned
     * @return bool
     */
    private function ensureServicePasswordPersisted($userId, $uuid, $item)
    {
        $userId = (int) $userId;
        $this->clearUserMetaCache($userId);
        $row = WP_Application_Passwords::get_user_application_passwords($userId);
        if ($this->rowHasPasswordUuid($row, $uuid)) {
            return true;
        }
        if (!is_array($item) || !isset($item['uuid'])) {
            return false;
        }
        $row = is_array($row) ? $row : array();
        $row[] = $item;
        update_user_meta($userId, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $row);
        error_log(sprintf('[Optima Express] confirm: re-appended the minted password to service user %d on blog %d', $userId, get_current_blog_id()));
        $this->clearUserMetaCache($userId);
        return $this->rowHasPasswordUuid(WP_Application_Passwords::get_user_application_passwords($userId), $uuid);
    }

    /**
     * @param array $row
     * @param string $uuid
     * @return bool
     */
    private function rowHasPasswordUuid($row, $uuid)
    {
        if (!is_array($row)) {
            return false;
        }
        foreach ($row as $appPassword) {
            if (isset($appPassword['uuid']) && $appPassword['uuid'] === $uuid) {
                return true;
            }
        }
        return false;
    }

    /**
     * The option writes that record a confirmation, in this order. Each update_option() is its
     * own autocommit, so the order is what a concurrent reader can observe: on a legacy blog
     * SHARED_DEMOTE_AFTER is written and SHARED_USER_DEMOTED cleared before the per-site uuid,
     * so the tick's re-evaluation rule (Step 8) never sees the uuid without demote_after.
     *
     * @param string $uuid
     * @param string $restBase
     * @param string $restBaseSource
     * @param int $blogId
     * @return void
     */
    private function recordBlogCredentialConfirmed($uuid, $restBase, $restBaseSource, $blogId)
    {
        $hadLegacy = $this->blogHasLegacySharedUser(true);
        if ($hadLegacy) {
            update_option(iHomefinderConstants::SHARED_DEMOTE_AFTER_OPTION, time() + self::SHARED_DEMOTE_GRACE);
            delete_option(iHomefinderConstants::SHARED_USER_DEMOTED_OPTION);
        }
        update_option(iHomefinderConstants::BLOG_SERVICE_PASSWORD_UUID_OPTION, (string) $uuid);
        update_option(iHomefinderConstants::BLOG_REST_BASE_SENT_OPTION, (string) $restBase, false);
        update_option(iHomefinderConstants::BLOG_REST_BASE_SOURCE_OPTION, (string) $restBaseSource, false);
        if ($this->applicationPasswordsAvailableForBlog()) {
            delete_option(iHomefinderConstants::BLOG_AWAITING_HTTPS_OPTION);
        } else {
            update_option(iHomefinderConstants::BLOG_AWAITING_HTTPS_OPTION, '1', false);
        }
        update_option(iHomefinderConstants::LEGACY_UUID_SNAPSHOT_OPTION, $this->getLegacyConfirmedUuid(), false);
        update_option(iHomefinderConstants::BLOG_HAD_LEGACY_OPTION, $hadLegacy ? '1' : '0', false);
        delete_option(iHomefinderConstants::BLOG_RESYNC_PENDING_OPTION);
        delete_option(iHomefinderConstants::BLOG_RESYNC_FAILURES_OPTION);
        delete_option(iHomefinderConstants::BLOG_RESYNC_NEXT_AT_OPTION);
        delete_option(iHomefinderConstants::SHARED_DEMOTE_NEXT_AT_OPTION);
        delete_transient(self::BLOG_PROVISION_RETRY_TRANSIENT . $blogId);
        delete_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION);
        delete_option(iHomefinderConstants::BLOG_PROVISION_LAST_ERROR_OPTION);
        // First confirmation anywhere on the network; add_site_option() never overwrites.
        add_site_option(iHomefinderConstants::FIRST_MIGRATION_AT_SITE_OPTION, time());
    }

    /*
     * Per-blog provisioning lock.
     *
     * A row in this blog's options table, written with INSERT IGNORE so that of two writers
     * exactly one sees an affected-row count of 1. The value is "<token>:<unix time>"; the token
     * identifies the holder and the time lets a lock left behind by a crashed request be broken
     * once it is older than BLOG_PROVISION_LOCK_TTL. Every read and write of the row is raw SQL:
     * the options cache is per process and per node and would hide another node's row.
     *
     * Holders: provisionBlogIntegration() (the whole round trip), and, in later steps,
     * activateAuthenticationToken() when re-run from a signed ping, demoteSharedUserIfDue()
     * and the installer's one-time upgrade block. A holder calls lockStillOurs() immediately
     * before the POST and immediately before confirm, and releases in finally; the shutdown
     * hook registered on acquire is the backstop for a fatal error between the two.
     */

    /**
     * Takes the lock for this blog. Returns the holder token, or null when another request
     * holds it (and its row is younger than the TTL) or the database refused the write.
     *
     * @return string|null
     */
    public function acquireProvisionLock()
    {
        global $wpdb;
        $lockName = iHomefinderConstants::BLOG_PROVISION_LOCK_OPTION;
        $token = bin2hex(random_bytes(16));
        $value = $token . ':' . time();
        // Two passes at most: the first insert, and one retry after breaking a stale row.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $lockName,
                $value
            ));
            if ($inserted === 1) {
                // A get_option() elsewhere in this request must not answer from a cached miss.
                wp_cache_delete('notoptions', 'options');
                wp_cache_delete($lockName, 'options');
                $this->registerProvisionLockShutdownRelease($wpdb->options, $token);
                return $token;
            }
            if ($inserted === false) {
                error_log(sprintf('[Optima Express] provisioning lock insert failed for blog %d', get_current_blog_id()));
                return null;
            }
            // 0 rows: the row exists. Break it only when it is provably stale, and only that
            // exact value, so a lock a live holder took a moment ago is never removed.
            $current = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $lockName
            ));
            if ($current === null) {
                // Released between our insert and our read; try the insert again.
                continue;
            }
            $heldAt = $this->provisionLockTime($current);
            if ($heldAt !== null && (time() - $heldAt) < self::BLOG_PROVISION_LOCK_TTL) {
                return null;
            }
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $lockName,
                $current
            ));
            error_log(sprintf('[Optima Express] broke a stale provisioning lock for blog %d', get_current_blog_id()));
        }
        return null;
    }

    /**
     * Releases the lock identified by $token. Deleting by token, not by name, means a holder
     * that has already lost the lock to a stale-break cannot remove the new holder's row.
     * Safe to call more than once and with a token that no longer holds the lock.
     *
     * @param string|null $token
     * @return void
     */
    public function releaseProvisionLock($token)
    {
        global $wpdb;
        if (!is_string($token) || $token === '') {
            return;
        }
        self::deleteProvisionLockRow($wpdb->options, $token);
    }

    /**
     * Unix time a lock row value was written, or null when it is not of the form
     * "<32 hex>:<unix time>". For read-only reporting of stale locks (Step 12); the lock itself
     * is only ever taken and broken by acquireProvisionLock().
     *
     * @param mixed $value
     * @return int|null
     */
    public function provisionLockHeldAt($value)
    {
        return $this->provisionLockTime($value);
    }

    /**
     * True while the row still carries our token. Call immediately before the POST and
     * immediately before confirm: a holder that overran the TTL may have been broken by
     * another request, and must not ship or confirm a credential over that request's work.
     *
     * @param string|null $token
     * @return bool
     */
    public function lockStillOurs($token)
    {
        global $wpdb;
        if (!is_string($token) || $token === '') {
            return false;
        }
        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            iHomefinderConstants::BLOG_PROVISION_LOCK_OPTION
        ));
        return is_string($current) && strpos($current, $token . ':') === 0;
    }

    /**
     * Drops this request's cached view of the provisioning options so the next get_option()
     * goes back to the database. Call after taking the lock and before reading state:
     * alloptions and notoptions were primed before the lock and may predate another
     * holder's writes. With no arguments every per-blog option of the credential model is
     * cleared; pass names to clear only those.
     *
     * @param string[] $optionNames
     * @return void
     */
    public function clearProvisionOptionCaches(array $optionNames = array())
    {
        if (empty($optionNames)) {
            $optionNames = $this->provisionOptionNames();
        }
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
        foreach ($optionNames as $optionName) {
            wp_cache_delete($optionName, 'options');
        }
    }

    /**
     * Drops the cached usermeta row of $userId. Must precede each application-password read
     * or write on that user: WP_Application_Passwords::get_user_application_passwords()
     * re-primes the cache, so one clear at the top of a routine does not cover a later read.
     *
     * @param int $userId
     * @return void
     */
    public function clearUserMetaCache($userId)
    {
        wp_cache_delete((int) $userId, 'user_meta');
    }

    /**
     * Every per-blog option the credential model reads or writes (legacy trio included).
     *
     * @return string[]
     */
    private function provisionOptionNames()
    {
        return array(
            iHomefinderConstants::ACTIVATION_TOKEN_OPTION,
            iHomefinderConstants::AUTHENTICATION_TOKEN_OPTION,
            iHomefinderConstants::IS_ACTIVATED_OPTION,
            iHomefinderConstants::BLOG_APP_PASSWORD_UUID_OPTION,
            iHomefinderConstants::BLOG_USER_ID_OPTION,
            iHomefinderConstants::BLOG_PROVISION_SQUATTED_OPTION,
            iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION,
            iHomefinderConstants::BLOG_PROVISION_LAST_ERROR_OPTION,
            iHomefinderConstants::BLOG_RESYNC_PENDING_OPTION,
            iHomefinderConstants::BLOG_SERVICE_PASSWORD_UUID_OPTION,
            iHomefinderConstants::SHARED_DEMOTE_AFTER_OPTION,
            iHomefinderConstants::SHARED_USER_DEMOTED_OPTION,
            iHomefinderConstants::BLOG_NEXT_CHECK_AT_OPTION,
            iHomefinderConstants::BLOG_RESYNC_NEXT_AT_OPTION,
            iHomefinderConstants::BLOG_FORCE_ROTATE_OPTION,
            iHomefinderConstants::BLOG_SERVICE_USER_ID_OPTION,
            iHomefinderConstants::BLOG_REST_BASE_SENT_OPTION,
            iHomefinderConstants::BLOG_REST_BASE_TARGET_OPTION,
            iHomefinderConstants::BLOG_REST_BASE_SOURCE_OPTION,
            iHomefinderConstants::BLOG_AWAITING_HTTPS_OPTION,
            iHomefinderConstants::BLOG_RESYNC_FAILURES_OPTION,
            iHomefinderConstants::LEGACY_UUID_SNAPSHOT_OPTION,
            iHomefinderConstants::BLOG_PROVISION_LOCK_OPTION,
            iHomefinderConstants::SHARED_DEMOTE_NEXT_AT_OPTION,
            iHomefinderConstants::BLOG_HAD_LEGACY_OPTION,
        );
    }

    /**
     * Unix time embedded in a lock value, or null when the value is not "<token>:<time>".
     * An unparseable row is treated as stale by the caller so a corrupt value cannot hold
     * the blog locked forever.
     *
     * @param string $value
     * @return int|null
     */
    private function provisionLockTime($value)
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{32}:(\d{1,12})$/', $value, $matches) !== 1) {
            return null;
        }
        return (int) $matches[1];
    }

    /**
     * Registers a shutdown backstop that removes this holder's row. The table name is
     * captured as a string because $wpdb->options follows switch_to_blog(), and by shutdown
     * the current blog may not be the one that took the lock.
     *
     * @param string $optionsTable
     * @param string $token
     * @return void
     */
    private function registerProvisionLockShutdownRelease($optionsTable, $token)
    {
        $optionsTable = (string) $optionsTable;
        $token = (string) $token;
        add_action('shutdown', function () use ($optionsTable, $token) {
            self::deleteProvisionLockRow($optionsTable, $token);
        });
    }

    /**
     * The release itself: delete by name and by token prefix (LIKE '<token>%', the token is
     * 32 hex characters so the prefix cannot match another holder), on the given table.
     *
     * @param string $optionsTable
     * @param string $token
     * @return void
     */
    private static function deleteProvisionLockRow($optionsTable, $token)
    {
        global $wpdb;
        if ($optionsTable === '' || $token === '') {
            return;
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$optionsTable} WHERE option_name = %s AND option_value LIKE %s",
            iHomefinderConstants::BLOG_PROVISION_LOCK_OPTION,
            $wpdb->esc_like($token) . '%'
        ));
        if ($optionsTable === $wpdb->options) {
            // Only when the table is the current blog's: the options cache is keyed by the
            // current blog, so clearing it for another blog's table would miss anyway.
            wp_cache_delete(iHomefinderConstants::BLOG_PROVISION_LOCK_OPTION, 'options');
        }
    }

    /**
     * Cheap read-only test for whether a retry is owed, for callers that must decide
     * without doing any work. Option reads only; both are autoloaded.
     *
     * @return bool
     */
    public function blogProvisioningIsPending()
    {
        if (!$this->previouslyActivated() || !$this->isActivated()) {
            return false;
        }
        if ($this->blogIsRetired()) {
            // A deleted, archived or spam sub-site owes nothing; the scheduler must not queue it.
            return false;
        }
        if (!empty(get_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, null))) {
            return true;
        }
        // Pre-8.7.2 site: the uuid option did not exist, so its absence is the only
        // remaining signal that this site has never confirmed a credential.
        return empty($this->getConfirmedServicePasswordUuid());
    }

    /**
     * Deterministic, local check for whether the per-site credential ihf-root confirmed still
     * exists on this blog's service user. A confirmed uuid that is no longer among the pinned
     * user's application passwords, a pinned user that is gone, or a pin that no longer points
     * at an owned user, all mean the credential was invalidated out from under us (a security
     * or user-cleanup plugin pruning it, a manual revoke in wp-admin, a DB restore). ihf-root
     * still holds it, so every post 401s until we re-provision. Presence of the uuid is
     * authoritative and needs no HTTP call.
     *
     * Only meaningful once a per-site credential has been confirmed. A blog that has never
     * confirmed one is trigger 1 of shouldRotate(), so this returns false there and does not
     * double-flag it.
     *
     * @return bool true when a previously confirmed credential is now missing
     */
    private function confirmedBlogCredentialIsMissing()
    {
        $confirmedUuid = $this->getConfirmedServicePasswordUuid();
        if (empty($confirmedUuid)) {
            return false;
        }
        if (!class_exists("WP_Application_Passwords")) {
            return false;
        }
        // By id through the pin, never by login: findServiceUser() clears the user-meta cache
        // before its read and applies the two-meta ownership test.
        $user = $this->findServiceUser();
        if ($user === null) {
            return true;
        }
        $existing = WP_Application_Passwords::get_user_application_passwords($user->ID);
        foreach ($existing as $appPassword) {
            if (isset($appPassword["uuid"]) && $appPassword["uuid"] === $confirmedUuid) {
                return false;
            }
        }
        return true;
    }

    /*
     * Headless checks, drift and scheduling (Step 8).
     *
     * Provisioning is reached from two places that share one predicate and one attempt, and
     * differ only in who is calling: retryBlogProvisioningIfPending() on admin_init (a logged-in,
     * non-ajax request, inline as on 8.7.7) and runBlogProvisioningRetry() from the
     * ihf_oe_blog_provision_retry cron event (no user, so no login test). The hourly claimed
     * tick runHeadlessBlogChecks() is what notices that something is owed: an 8.7.7 node
     * re-provisioned the shared way, the confirmed credential is gone, the shared user's grace
     * has passed, the REST base drifted, the rewrite rules were lost. It marks and schedules;
     * it never makes the round trip and never revokes anything.
     */

    /**
     * admin_init (priority 20) half of provisioning. Returns for ajax requests and anonymous
     * requests, for a halted blog, when nothing is pending, and while the drift backoff
     * (BLOG_RESYNC_NEXT_AT_OPTION) is in the future. Records the REST base as this interactive
     * request sees it when no target is set yet, or when application passwords are available
     * here (an https request on the blog's own host is the best evidence of the base ihf-root
     * should use), then provisions inline under the lock as 8.7.7 did.
     *
     * @return void
     */
    public function retryBlogProvisioningIfPending()
    {
        if (wp_doing_ajax() || !is_user_logged_in()) {
            return;
        }
        if (!$this->provisioningRetryOwed()) {
            return;
        }
        $this->writeRestBaseTargetFromThisRequest();
        $this->attemptBlogProvisioning();
    }

    /**
     * Cron half of provisioning: the handler of ihf_oe_blog_provision_retry. Same predicate as
     * the admin path without the login test (a cron request has no user, which is why the old
     * binding to retryBlogProvisioningIfPending() could never run), same attempt. Writes no
     * REST base target: a cron request cannot see the site's scheme, so the runner builds the
     * base from what was last sent or from home (A20).
     *
     * @return void
     */
    public function runBlogProvisioningRetry()
    {
        if (!$this->provisioningRetryOwed()) {
            return;
        }
        $this->attemptBlogProvisioning();
    }

    /**
     * Whether a provisioning attempt is owed on this blog right now. Marks a site that has
     * never confirmed a per-site credential pending (trigger 1 of shouldRotate(); this is also
     * where a pre-8.7.2 site, whose uuid option never existed, starts out).
     *
     * BLOG_PROVISION_SQUATTED_OPTION is deliberately not consulted: the per-site model never
     * touches the shared login, so a squatter on it cannot block this blog (MAX-4440 item 2).
     *
     * @return bool
     */
    private function provisioningRetryOwed()
    {
        if (!$this->previouslyActivated() || !$this->isActivated()) {
            return false;
        }
        if ($this->migrationHaltedFor(get_current_blog_id())) {
            // A halted blog is neither marked pending nor provisioned.
            return false;
        }
        $pending = get_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, null);
        if (empty($pending) && $this->getConfirmedServicePasswordUuid() === null) {
            $pending = "1";
            update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, $pending);
        }
        if (empty($pending)) {
            return false;
        }
        // Drift backoff (Step 9): a drift-triggered rotation that keeps failing waits longer
        // each time. Reset and confirm clear it.
        if ((int) get_option(iHomefinderConstants::BLOG_RESYNC_NEXT_AT_OPTION, 0) > time()) {
            return false;
        }
        return true;
    }

    /**
     * One provisioning attempt: honor the retry transient unless a forced rotation is flagged
     * (one bypass, since provisionBlogIntegration() consumes the flag on its first locked
     * attempt whatever the outcome), call provisionBlogIntegration() (which sets the transient
     * itself inside the lock), record a drift-triggered failure for the backoff, and queue the
     * retry event when the blog is still pending afterwards.
     *
     * Provisioning makes a blocking HTTP call with a 200s timeout, and the early returns in
     * provisionBlogIntegration() neither clear the marker nor throw, so without the transient a
     * site in that state would attempt on every single admin request. It is also what bounds
     * application password growth on a site whose round trip keeps failing.
     *
     * @return void
     */
    private function attemptBlogProvisioning()
    {
        $blogId = get_current_blog_id();
        $throttleKey = self::BLOG_PROVISION_RETRY_TRANSIENT . $blogId;
        if (get_transient($throttleKey) && empty(get_option(iHomefinderConstants::BLOG_FORCE_ROTATE_OPTION, null))) {
            return;
        }
        $startedAt = time();
        try {
            $this->provisionBlogIntegration();
        } catch (Throwable $e) {
            // Leave the marker set and try again in the next window. The previously confirmed
            // credential, if any, is untouched by a failed attempt. provisionBlogIntegration()
            // records a staged last_error before every throw; only fill in one it did not write.
            $lastError = get_option(iHomefinderConstants::BLOG_PROVISION_LAST_ERROR_OPTION, null);
            if (!is_array($lastError) || !isset($lastError['time']) || (int) $lastError['time'] < $startedAt) {
                $this->recordBlogProvisionError($e->getMessage());
            }
            $this->recordDriftFailure($startedAt);
            error_log($e->getMessage());
        }
        if (!empty(get_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, null))
            && !wp_next_scheduled(iHomefinderConstants::BLOG_PROVISION_RETRY_CRON_HOOK)) {
            // Still pending: retry from cron once the throttle (and any backoff) has expired.
            $nextAt = max(time() + self::BLOG_PROVISION_RETRY_INTERVAL, (int) get_option(iHomefinderConstants::BLOG_RESYNC_NEXT_AT_OPTION, 0));
            wp_schedule_single_event($nextAt, iHomefinderConstants::BLOG_PROVISION_RETRY_CRON_HOOK);
        }
    }

    /**
     * Drift backoff (Step 9). When a rotation that the drift check asked for (BLOG_RESYNC_PENDING
     * set) fails at the POST (non-200 or WP_Error, recorded as STAGE_PROVISION_POST by this
     * attempt), count the failure and push the next attempt out: 900s x 2^min(failures, 6), so
     * 30 minutes after the first failure and 16 hours from the sixth on. Confirm and Reset
     * clear the counter and the next-at. A failure that is not the POST (lock lost, no user)
     * is not drift's fault and does not back off.
     *
     * @param int $attemptStartedAt
     * @return void
     */
    private function recordDriftFailure($attemptStartedAt)
    {
        if (empty(get_option(iHomefinderConstants::BLOG_RESYNC_PENDING_OPTION, null))) {
            return;
        }
        $lastError = get_option(iHomefinderConstants::BLOG_PROVISION_LAST_ERROR_OPTION, null);
        if (!is_array($lastError) || !isset($lastError['stage'], $lastError['time'])
            || $lastError['stage'] !== self::STAGE_PROVISION_POST || (int) $lastError['time'] < $attemptStartedAt) {
            return;
        }
        $failures = (int) get_option(iHomefinderConstants::BLOG_RESYNC_FAILURES_OPTION, 0) + 1;
        $delay = self::BLOG_PROVISION_RETRY_INTERVAL * (1 << min($failures, 6));
        update_option(iHomefinderConstants::BLOG_RESYNC_FAILURES_OPTION, $failures, false);
        update_option(iHomefinderConstants::BLOG_RESYNC_NEXT_AT_OPTION, time() + $delay);
        error_log(sprintf('[Optima Express] drift rotation failed for blog %d (%d consecutive); next attempt in %ds', get_current_blog_id(), $failures, $delay));
    }

    /**
     * The hourly claimed tick. Bound to admin_init (priority 19; logged-in, non-ajax requests
     * only) and to front-end init (priority 20). At most one request per blog per
     * HEADLESS_CHECK_INTERVAL runs it, decided by an atomic conditional UPDATE on
     * BLOG_NEXT_CHECK_AT_OPTION, so two web nodes or two tabs cannot both run it.
     *
     * In order: retired-site check; the claim; schedule the daily retirement event from the
     * main site; return when halted; (1) an 8.7.7 node re-provisioned the shared way (legacy
     * uuid moved off the snapshot) -> pending and a forced rotation, the retry transient
     * untouched so that node is not handed a fresh window; (2) the confirmed credential is gone
     * -> pending; (2b) a legacy blog whose demotion was skipped and later cleared gets a new
     * grace; (2c) the demotion fallback; (3) REST base drift; (4) schedule the demote event
     * when the grace has passed; (5) rewrite self-heal. Nothing here calls ihf-root or revokes
     * a password. There is no autonomous re-activation: a site with a key and no token waits
     * for Java's signed ping or an admin (A16).
     *
     * @return void
     */
    public function runHeadlessBlogChecks()
    {
        if (wp_doing_ajax()) {
            return;
        }
        if (is_admin() && !is_user_logged_in()) {
            return;
        }
        if (!$this->previouslyActivated()) {
            return;
        }
        $blogId = get_current_blog_id();
        if ($this->blogIsRetired($blogId)) {
            return;
        }
        if (!$this->claimHeadlessTick()) {
            return;
        }
        $this->scheduleSharedRetirementIfNeeded();
        if ($this->migrationHaltedFor($blogId)) {
            return;
        }
        $confirmedUuid = $this->getConfirmedServicePasswordUuid();
        if ($confirmedUuid !== null) {
            $snapshot = get_option(iHomefinderConstants::LEGACY_UUID_SNAPSHOT_OPTION, '');
            if ($this->getLegacyConfirmedUuid() !== (is_scalar($snapshot) ? (string) $snapshot : '')) {
                // (1) Trigger 4. Never delete the retry transient here.
                update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, '1');
                update_option(iHomefinderConstants::BLOG_FORCE_ROTATE_OPTION, '1');
                error_log(sprintf('[Optima Express] tick: legacy credential changed on blog %d; forced rotation flagged', $blogId));
            } elseif ($this->confirmedBlogCredentialIsMissing()) {
                // (2) Trigger 2.
                update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, '1');
                error_log(sprintf('[Optima Express] tick: confirmed credential missing on blog %d; rotation flagged', $blogId));
            }
            if ($this->blogHasLegacySharedUser()) {
                $demoted = get_option(iHomefinderConstants::SHARED_USER_DEMOTED_OPTION, null);
                $demoteAfter = (int) get_option(iHomefinderConstants::SHARED_DEMOTE_AFTER_OPTION, 0);
                if (empty($demoted) && $demoteAfter <= 0) {
                    // (2b) A skipped demotion that a human cleared: a fresh grace, never time().
                    $demoteAfter = time() + self::SHARED_DEMOTE_GRACE;
                    update_option(iHomefinderConstants::SHARED_DEMOTE_AFTER_OPTION, $demoteAfter);
                }
                // (2c) Demotion fallback, from the claimed tick only.
                $this->demoteSharedUserIfDue();
                // (4) The cron event is the primary demoter; queue it when the grace has passed.
                if (empty(get_option(iHomefinderConstants::SHARED_USER_DEMOTED_OPTION, null))
                    && $demoteAfter > 0 && time() >= $demoteAfter
                    && !wp_next_scheduled(iHomefinderConstants::SHARED_DEMOTE_CRON_HOOK)) {
                    wp_schedule_single_event(time(), iHomefinderConstants::SHARED_DEMOTE_CRON_HOOK);
                }
            }
        }
        // (3)
        $this->checkRestBaseDrift();
        // (5)
        $this->healRewriteRules();
    }

    /**
     * Takes this blog's hourly tick. The row is seeded with a raw INSERT IGNORE of '0' (never
     * add_option(), whose ON DUPLICATE KEY UPDATE would reset another node's claim; the insert
     * is never the claim), then claimed with UPDATE ... WHERE option_value <= now. Exactly one
     * of any number of concurrent callers sees affected rows = 1. The options cache is
     * cleared after the raw write so this request's later reads see the row.
     *
     * @return bool true when this request owns the tick
     */
    private function claimHeadlessTick()
    {
        global $wpdb;
        $optionName = iHomefinderConstants::BLOG_NEXT_CHECK_AT_OPTION;
        $now = time();
        $cached = get_option($optionName, null);
        if ($cached === null || $cached === false) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'yes')",
                $optionName
            ));
        } elseif ((int) $cached > $now) {
            // The cached view may lag another node's claim, but it can only make us wait.
            return false;
        }
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = %s AND option_value <= %d",
            $now + self::HEADLESS_CHECK_INTERVAL,
            $optionName,
            $now
        ));
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete($optionName, 'options');
        return $claimed === 1;
    }

    /**
     * (3) REST base drift. Only an interactive request on the blog's own host, with
     * application passwords available, can judge the base ihf-root should post to; cron,
     * WP-CLI and a request for another host cannot. When the base this request computes
     * differs from the one last sent (or a URL-change hook left BLOG_RESYNC_PENDING set) and
     * the backoff has expired, the flap breaker runs first: a change of scheme alone, on a
     * base that an interactive request chose less than DRIFT_FLAP_WINDOW ago, is two requests
     * disagreeing about https, not a moved site, and is recorded as drift-flapping rather than
     * rotated. Otherwise the target is written from this request and a forced rotation is
     * flagged; the admin_init runner in this same request, or the retry event, ships it.
     *
     * @return void
     */
    private function checkRestBaseDrift()
    {
        if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI) || !isset($_SERVER['HTTP_HOST'])) {
            return;
        }
        $homeHost = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
        $requestHost = strtolower((string) parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST));
        if ($requestHost === '' || $requestHost !== $homeHost) {
            return;
        }
        if (!function_exists('wp_is_application_passwords_available') || !wp_is_application_passwords_available()) {
            return;
        }
        delete_option(iHomefinderConstants::BLOG_AWAITING_HTTPS_OPTION);
        $sent = get_option(iHomefinderConstants::BLOG_REST_BASE_SENT_OPTION, '');
        if (!is_string($sent) || $sent === '') {
            // Nothing has been sent yet, so there is nothing to drift from.
            return;
        }
        $blogId = get_current_blog_id();
        $current = $this->restBaseFromThisRequest();
        if ($current === $sent) {
            if (!empty(get_option(iHomefinderConstants::BLOG_RESYNC_PENDING_OPTION, null))) {
                // A URL-change hook marked us, but the base ihf-root holds is still right.
                delete_option(iHomefinderConstants::BLOG_RESYNC_PENDING_OPTION);
            }
            return;
        }
        if ((int) get_option(iHomefinderConstants::BLOG_RESYNC_NEXT_AT_OPTION, 0) > time()) {
            return;
        }
        $source = get_option(iHomefinderConstants::BLOG_REST_BASE_SOURCE_OPTION, '');
        $schemeOnly = set_url_scheme($current, 'http') === set_url_scheme($sent, 'http');
        if ($schemeOnly && $source === iHomefinderConstants::REST_BASE_SOURCE_INTERACTIVE) {
            $sentAt = $this->confirmedServicePasswordCreatedAt();
            if ($sentAt !== null && (time() - $sentAt) < self::DRIFT_FLAP_WINDOW) {
                $this->recordBlogProvisionError('REST base scheme changed again within a day of the last send; not rotating', self::STAGE_DRIFT_FLAPPING);
                error_log(sprintf('[Optima Express] tick: drift-flapping on blog %d; no rotation', $blogId));
                return;
            }
        }
        update_option(iHomefinderConstants::BLOG_REST_BASE_TARGET_OPTION, $current, false);
        update_option(iHomefinderConstants::BLOG_RESYNC_PENDING_OPTION, '1');
        update_option(iHomefinderConstants::BLOG_FORCE_ROTATE_OPTION, '1');
        update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, '1');
        error_log(sprintf('[Optima Express] tick: REST base drift on blog %d (%s); rotation flagged', $blogId, $schemeOnly ? 'scheme' : 'host or path'));
    }

    /**
     * When the confirmed per-site password was minted, which is within seconds of when its
     * base was sent to ihf-root. Null when no credential is confirmed or it is not on the user.
     *
     * @return int|null
     */
    private function confirmedServicePasswordCreatedAt()
    {
        $confirmedUuid = $this->getConfirmedServicePasswordUuid();
        if ($confirmedUuid === null || !class_exists('WP_Application_Passwords')) {
            return null;
        }
        $user = $this->findServiceUser();
        if ($user === null) {
            return null;
        }
        foreach (WP_Application_Passwords::get_user_application_passwords($user->ID) as $appPassword) {
            if (isset($appPassword['uuid']) && $appPassword['uuid'] === $confirmedUuid) {
                return isset($appPassword['created']) ? (int) $appPassword['created'] : null;
            }
        }
        return null;
    }

    /**
     * Fallback drift marker for the update_option_home / update_option_siteurl hooks and
     * wp_update_site: the hourly get_rest_url() comparison is primary, this only makes sure a
     * changed URL is looked at even if the comparison is somehow blind to it. Writes the
     * marker only on a blog that has something to re-sync.
     *
     * @return void
     */
    public function markBlogResyncPending()
    {
        if (!$this->previouslyActivated() || $this->getConfirmedServicePasswordUuid() === null) {
            return;
        }
        update_option(iHomefinderConstants::BLOG_RESYNC_PENDING_OPTION, '1');
    }

    /**
     * wp_update_site handler: a changed domain or path marks that blog for a re-sync.
     *
     * @param WP_Site $newSite
     * @param WP_Site $oldSite
     * @return void
     */
    public function handleSiteUpdated($newSite, $oldSite)
    {
        if (!is_object($newSite) || !is_object($oldSite) || empty($newSite->blog_id)) {
            return;
        }
        if ((string) $newSite->domain === (string) $oldSite->domain && (string) $newSite->path === (string) $oldSite->path) {
            return;
        }
        switch_to_blog((int) $newSite->blog_id);
        try {
            $this->markBlogResyncPending();
        } finally {
            restore_current_blog();
        }
    }

    /**
     * (5) Rewrite self-heal. A downgrade and re-upgrade, or a plugin that flushed the rules
     * while ours were not registered, leaves the persisted rules without a single IDX rule
     * and every IDX URL 404s. Our rules are registered on init priority 1 in every request,
     * so a flush rebuilds them. Skipped on plain permalinks, where no rules exist to lose.
     *
     * Public so it can also run from wp_loaded through healRewriteRulesOnLoad(). The tick
     * that calls this is an admin_init hook that additionally wants a signed-in admin and an
     * hourly claim, and a customer site nobody signs into would otherwise serve 404s to
     * visitors for as long as that took.
     *
     * @return void
     */
    public function healRewriteRules()
    {
        if (empty(get_option('permalink_structure'))) {
            return;
        }
        if (!$this->idxRewriteRulesAreStale()) {
            return;
        }
        flush_rewrite_rules();
        error_log(sprintf('[Optima Express] IDX rewrite rules were missing or stale on blog %d; rebuilt', get_current_blog_id()));
    }

    /**
     * Whether this blog's persisted rewrite rules have lost the IDX routes.
     *
     * The marker is the post type the current rules route to, not the ihf-type query var.
     * 8.x rules carry ihf-type too (index.php?pagename=non_existent_page&ihf-type=...), so
     * testing for that read them as healthy -- which is why this check, whose docblock has
     * always claimed to cover a downgrade and re-upgrade, looked straight past exactly the
     * blogs MAX-4695 describes: stamped 9.x, still routed 8.x, 404 on every IDX page.
     *
     * Testing for the current format being present rather than the old format being absent
     * also catches a rule set that lost the IDX rules outright instead of having them
     * replaced, which is the shape a flush leaves when it runs before they are registered.
     *
     * An absent or empty rule set is not stale. WordPress reads that as "rebuild on the
     * next request" and does so itself, and deleting the option is the usual way to ask
     * for that cheap rebuild instead of paying for a hard flush. Answering "stale" there
     * would race ahead of it with a hard flush, and would also flush forever on a blog
     * where no rules can be generated at all.
     *
     * @return bool
     */
    private function idxRewriteRulesAreStale()
    {
        $rules = get_option('rewrite_rules');
        if (!is_array($rules) || empty($rules)) {
            return false;
        }
        $marker = iHomefinderPostType::POST_TYPE . '=';
        foreach ($rules as $redirect) {
            if (is_string($redirect) && strpos($redirect, $marker) !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * wp_loaded entry point for the rewrite self-heal, so a blog repairs itself for visitors
     * rather than waiting for an administrator to sign in (MAX-4695).
     *
     * wp_loaded is after init, where the post type (priority 0) and the current IDX rules
     * (priority 1) are registered, so a flush from here writes a complete, current rule set.
     * This cannot live in upgrade() instead: the blogs it repairs are already stamped at the
     * running version and have spent their upgrade event, which is the whole reason they do
     * not recover on their own.
     *
     * The marker records the version that checked, so a healthy blog pays one option read
     * and one in-memory scan per release, and every release re-arms the check. The hourly
     * tick keeps covering a blog that breaks later in the same release.
     *
     * The lock is taken only when there is something to repair, and keeps a burst of first
     * requests after a deploy down to a single flush. A request that loses it leaves the
     * marker unset, so the next request retries rather than the repair being dropped.
     *
     * @return void
     */
    public function healRewriteRulesOnLoad()
    {
        if (get_option(iHomefinderConstants::REWRITE_HEAL_OPTION, null) === iHomefinderConstants::VERSION) {
            return;
        }
        $installer = iHomefinderInstaller::getInstance();
        if ($installer->isForwardUpgrade(get_option(iHomefinderConstants::VERSION_OPTION, null))) {
            // An upgrade is pending for this blog and flushes as part of its own one-time
            // block, so leave it to that rather than flushing here and again moments later.
            // On an admin request this hook runs before admin_init, which is where that
            // upgrade happens. The marker stays unset, so the next request re-checks, by
            // which point the version is stamped and this takes over again.
            return;
        }
        if (empty(get_option('permalink_structure')) || !$this->idxRewriteRulesAreStale()) {
            update_option(iHomefinderConstants::REWRITE_HEAL_OPTION, iHomefinderConstants::VERSION);
            return;
        }
        $lockToken = $this->acquireProvisionLock();
        if ($lockToken === null) {
            return;
        }
        try {
            // Re-read behind the lock: the holder that just released it may have flushed
            // already, and this request's copy of the option predates that.
            $this->clearProvisionOptionCaches(array('rewrite_rules'));
            $this->healRewriteRules();
            update_option(iHomefinderConstants::REWRITE_HEAL_OPTION, iHomefinderConstants::VERSION);
        } finally {
            $this->releaseProvisionLock($lockToken);
        }
    }

    /**
     * Schedules the daily ihf_oe_shared_retire event in the main site's cron array (the single
     * site's on a standalone install) once the network has pinned a shared user, until
     * retirement is recorded. remove() clears it (Step 11).
     *
     * Any blog's claimed tick may do the scheduling, because the tick only runs on blogs that
     * are registered with the plugin and a network's main site frequently is not: waiting for
     * the main site's own tick would leave the automatic retirement unscheduled for the life
     * of that network. The event still lives on the main site, so the handler runs there and
     * a network-wide deactivation (which fires from the network admin) still clears it.
     *
     * A sub-site's tick switches to the main site at most once an hour network-wide: the site
     * transient SHARED_RETIRE_SCHEDULED_MEMO is set after the event was scheduled (or found
     * already scheduled) and short-circuits every other blog's tick until it expires. Without
     * it a large network paid one switch_to_blog() per registered blog per hour. The main site
     * and a single site schedule locally and are unchanged.
     *
     * @return void
     */
    private function scheduleSharedRetirementIfNeeded()
    {
        if (empty(get_site_option(iHomefinderConstants::SHARED_USER_ID_SITE_OPTION, null))) {
            return;
        }
        if (!empty(get_site_option(iHomefinderConstants::SHARED_RETIRED_AT_SITE_OPTION, null))) {
            return;
        }
        if (!is_multisite() || is_main_site()) {
            $this->scheduleSharedRetireEventHere();
            return;
        }
        $mainSiteId = (int) get_main_site_id();
        if ($mainSiteId <= 0) {
            return;
        }
        if (get_site_transient(self::SHARED_RETIRE_SCHEDULED_MEMO)) {
            return;
        }
        switch_to_blog($mainSiteId);
        try {
            $this->scheduleSharedRetireEventHere();
        } finally {
            restore_current_blog();
        }
        // Set only after the switch completed, so a failure inside it is retried by the next tick.
        set_site_transient(self::SHARED_RETIRE_SCHEDULED_MEMO, 1, self::SHARED_RETIRE_SCHEDULED_MEMO_TTL);
    }

    /**
     * Queues the daily retirement event in the current blog's cron array when it is absent.
     *
     * @return void
     */
    private function scheduleSharedRetireEventHere()
    {
        if (!wp_next_scheduled(iHomefinderConstants::SHARED_RETIRE_CRON_HOOK)) {
            wp_schedule_event(time() + self::HEADLESS_CHECK_INTERVAL, 'daily', iHomefinderConstants::SHARED_RETIRE_CRON_HOOK);
        }
    }

    /**
     * unarchive_blog / make_ham_blog / make_undelete_blog handler. A blog restored to service
     * that still runs on the shared credential may find it purged (it was excluded from the
     * live count while retired): flag it now so it recovers in one retry window instead of
     * waiting for the hourly tick.
     *
     * @param int $siteId
     * @return void
     */
    public function handleBlogRestored($siteId)
    {
        $siteId = (int) $siteId;
        if ($siteId <= 0) {
            return;
        }
        switch_to_blog($siteId);
        try {
            if (!$this->previouslyActivated() || !$this->isActivated()) {
                return;
            }
            if ($this->getConfirmedServicePasswordUuid() !== null || !$this->blogHasLegacySharedUser(true)) {
                return;
            }
            update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, '1');
            update_option(iHomefinderConstants::BLOG_FORCE_ROTATE_OPTION, '1');
            if (!wp_next_scheduled(iHomefinderConstants::BLOG_PROVISION_RETRY_CRON_HOOK)) {
                wp_schedule_single_event(time(), iHomefinderConstants::BLOG_PROVISION_RETRY_CRON_HOOK);
            }
            error_log(sprintf('[Optima Express] restored blog %d still on the shared credential; rotation flagged', $siteId));
        } finally {
            restore_current_blog();
        }
    }

    /*
     * The per-site service user.
     *
     * One WordPress user per blog, login "optima-express-<blogid>-<8 hex>", display name
     * "Optima Express", role Author on that blog only. Two user-meta rows prove ownership:
     * BLOG_SERVICE_ACCOUNT_META ("1", the same marker the shared user carried) and
     * SERVICE_ACCOUNT_BLOG_META (the blog id as a string, binding the user to one blog). Both
     * live in wp_usermeta, which is network-global on multisite, so the ownership test does
     * not depend on the blog's own options. The per-blog pin BLOG_SERVICE_USER_ID_OPTION is a
     * cache of the id, never a proof: a pinned user must still carry both metas.
     *
     * Logins and emails are never logged; a log line carries the blog id, the user id and the
     * stage. Display strings say Optima Express, never iHomefinder.
     */

    /**
     * Id pinned for this blog's service user, or 0 when none is pinned.
     *
     * @return int
     */
    public function getPinnedServiceUserId()
    {
        return (int) get_option(iHomefinderConstants::BLOG_SERVICE_USER_ID_OPTION, 0);
    }

    /**
     * True when $user carries both ownership metas for the current blog. Read-only.
     *
     * @param WP_User|null $user
     * @return bool
     */
    public function serviceUserIsOwned($user)
    {
        if (!($user instanceof WP_User) || (int) $user->ID <= 0) {
            return false;
        }
        if (get_user_meta($user->ID, iHomefinderConstants::BLOG_SERVICE_ACCOUNT_META, true) !== '1') {
            return false;
        }
        $boundBlog = get_user_meta($user->ID, iHomefinderConstants::SERVICE_ACCOUNT_BLOG_META, true);
        return (string) $boundBlog === (string) get_current_blog_id();
    }

    /**
     * Read-only lookup of this blog's service user through the pin: the pinned user, when it
     * exists and passes serviceUserIsOwned(). Never creates, never writes an option or a meta
     * row, so it is safe outside the provisioning lock (REST authors list, status pages,
     * the credential-missing check). Returns null when nothing usable is pinned.
     *
     * @return WP_User|null
     */
    public function findServiceUser()
    {
        $pinnedId = $this->getPinnedServiceUserId();
        if ($pinnedId <= 0) {
            return null;
        }
        $this->clearUserMetaCache($pinnedId);
        $user = get_user_by('ID', $pinnedId);
        if (!$user || !$this->serviceUserIsOwned($user)) {
            return null;
        }
        return $user;
    }

    /**
     * Resolves this blog's service user, creating it when none exists, and makes sure it holds
     * the Author role here. Call only inside the provisioning lock: it writes the pin, may
     * insert a user, and may change a role.
     *
     * Resolution order:
     *  1. the pinned id, when that user exists and carries both metas for this blog. A pinned
     *     user that fails the test is logged by id and un-pinned but otherwise left alone: it
     *     is either another blog's service user or a stranger, and neither is ours to touch;
     *  2. adoption by the protected blog-binding meta, for a blog whose pin was lost (an
     *     options restore, a node that never saw the pin). The candidate must also carry
     *     BLOG_SERVICE_ACCOUNT_META;
     *  3. creation, up to three attempts with a fresh suffix when the login collides. Both
     *     metas go in through meta_input so the insert and the ownership proof are one write
     *     and a concurrent reader can never see the user without them.
     *
     * A role is assigned only to a user that passed the two-meta test, was adopted by the
     * protected meta, or was inserted here. Records the stage on failure and returns null.
     *
     * @return WP_User|null
     */
    public function resolveServiceUser()
    {
        $blogId = get_current_blog_id();

        // 1. Pinned id.
        $pinnedId = $this->getPinnedServiceUserId();
        $user = null;
        if ($pinnedId > 0) {
            $this->clearUserMetaCache($pinnedId);
            $pinned = get_user_by('ID', $pinnedId);
            if (!$pinned) {
                error_log(sprintf('[Optima Express] resolve-user: pinned service user %d is gone on blog %d; dropping the pin', $pinnedId, $blogId));
                delete_option(iHomefinderConstants::BLOG_SERVICE_USER_ID_OPTION);
            } elseif (!$this->serviceUserIsOwned($pinned)) {
                error_log(sprintf('[Optima Express] resolve-user: pinned user %d is not the service user of blog %d; dropping the pin, user left alone', $pinnedId, $blogId));
                delete_option(iHomefinderConstants::BLOG_SERVICE_USER_ID_OPTION);
            } else {
                $user = $pinned;
            }
        }

        // 2. Adopt by the blog-binding meta.
        if ($user === null) {
            $user = $this->adoptServiceUser($blogId);
        }

        // 3. Create.
        if ($user === null) {
            $user = $this->createServiceUser($blogId);
            if ($user === null) {
                return null;
            }
        }

        // Pin (idempotent when already pinned to this id).
        if ($this->getPinnedServiceUserId() !== (int) $user->ID) {
            update_option(iHomefinderConstants::BLOG_SERVICE_USER_ID_OPTION, (int) $user->ID, false);
        }

        // 4. Membership: Author on this blog, asserted only when it is not already so.
        if (!$this->ensureServiceUserMembership($user, $blogId)) {
            return null;
        }
        return $user;
    }

    /**
     * Step 2 of resolveServiceUser(): the user bound to $blogId by SERVICE_ACCOUNT_BLOG_META
     * that also carries BLOG_SERVICE_ACCOUNT_META. Queries the network-wide user table
     * (blog_id 0), asks for two rows so a duplicate is visible, and on a duplicate takes the
     * lowest id that passes the two-meta test rather than creating a third user. Read-only.
     *
     * @param int $blogId
     * @return WP_User|null
     */
    private function adoptServiceUser($blogId)
    {
        $ids = get_users(array(
            'meta_key'   => iHomefinderConstants::SERVICE_ACCOUNT_BLOG_META,
            'meta_value' => (string) $blogId,
            'blog_id'    => 0,
            'fields'     => 'ID',
            'number'     => 2,
            'orderby'    => 'ID',
            'order'      => 'ASC',
        ));
        if (!is_array($ids) || empty($ids)) {
            return null;
        }
        if (count($ids) > 1) {
            error_log(sprintf('[Optima Express] resolve-user: %d users are bound to blog %d; adopting the lowest id that proves ownership', count($ids), $blogId));
        }
        foreach ($ids as $id) {
            $id = (int) $id;
            $this->clearUserMetaCache($id);
            $candidate = get_user_by('ID', $id);
            if ($candidate && $this->serviceUserIsOwned($candidate)) {
                error_log(sprintf('[Optima Express] resolve-user: adopted service user %d for blog %d by its blog-binding meta', $id, $blogId));
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Step 3 of resolveServiceUser(): insert the per-site user. Up to three attempts; only an
     * existing_user_login (or existing_user_email, which follows from the login) collision
     * earns another attempt with a fresh suffix. Any other error is recorded under
     * STAGE_CREATE_USER and ends the attempt. The Author role is set by the insert itself so
     * the user never exists here with the site's default role.
     *
     * @param int $blogId
     * @return WP_User|null
     */
    private function createServiceUser($blogId)
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $login = $this->newServiceUserLogin($blogId);
            $userId = wp_insert_user(array(
                'user_login'    => $login,
                'user_pass'     => wp_generate_password(24, true, true),
                'user_email'    => $login . '@' . iHomefinderConstants::SERVICE_USER_EMAIL_DOMAIN,
                'user_nicename' => iHomefinderConstants::SERVICE_USER_LOGIN_PREFIX . $blogId,
                'display_name'  => iHomefinderConstants::SERVICE_USER_DISPLAY_NAME,
                'nickname'      => iHomefinderConstants::SERVICE_USER_DISPLAY_NAME,
                'role'          => 'author',
                'meta_input'    => array(
                    iHomefinderConstants::BLOG_SERVICE_ACCOUNT_META => '1',
                    iHomefinderConstants::SERVICE_ACCOUNT_BLOG_META => (string) $blogId,
                ),
            ));
            if (!is_wp_error($userId)) {
                $this->clearUserMetaCache($userId);
                $user = get_user_by('ID', (int) $userId);
                if (!$user) {
                    $this->recordBlogProvisionError('service user inserted but could not be read back', self::STAGE_CREATE_USER);
                    error_log(sprintf('[Optima Express] create-user: user %d inserted for blog %d but could not be read back', (int) $userId, $blogId));
                    return null;
                }
                error_log(sprintf('[Optima Express] create-user: created service user %d for blog %d', (int) $user->ID, $blogId));
                return $user;
            }
            $lastError = $userId;
            $code = $userId->get_error_code();
            if ($code !== 'existing_user_login' && $code !== 'existing_user_email') {
                break;
            }
        }
        $reason = $lastError instanceof WP_Error ? $lastError->get_error_code() : 'unknown';
        $this->recordBlogProvisionError('wp_insert_user failed: ' . $reason, self::STAGE_CREATE_USER);
        error_log(sprintf('[Optima Express] create-user: wp_insert_user failed for blog %d: %s', $blogId, $reason));
        return null;
    }

    /**
     * "optima-express-<blogid>-<8 hex>": unique per attempt, so a collision with a login that
     * already exists is resolved by trying again rather than by adopting it.
     *
     * @param int $blogId
     * @return string
     */
    private function newServiceUserLogin($blogId)
    {
        return iHomefinderConstants::SERVICE_USER_LOGIN_PREFIX . $blogId . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
    }

    /**
     * Step 4 of resolveServiceUser(): Author on $blogId. Multisite adds the user to the blog
     * when it is not a member and otherwise re-asserts the role only when the roles differ;
     * single site does the same comparison against the one blog. Records STAGE_MEMBERSHIP on
     * failure.
     *
     * @param WP_User $user
     * @param int $blogId
     * @return bool
     */
    private function ensureServiceUserMembership($user, $blogId)
    {
        if (is_multisite() && !is_user_member_of_blog($user->ID, $blogId)) {
            $result = add_user_to_blog($blogId, $user->ID, 'author');
            if (is_wp_error($result)) {
                $this->recordBlogProvisionError('add_user_to_blog failed: ' . $result->get_error_code(), self::STAGE_MEMBERSHIP);
                error_log(sprintf('[Optima Express] membership: add_user_to_blog failed for user %d on blog %d: %s', (int) $user->ID, $blogId, $result->get_error_code()));
                return false;
            }
            return true;
        }
        $roles = is_array($user->roles) ? array_values($user->roles) : array();
        if ($roles !== array('author')) {
            $user->set_role('author');
            error_log(sprintf('[Optima Express] membership: set service user %d to author on blog %d', (int) $user->ID, $blogId));
        }
        return true;
    }

    /**
     * Mints one application password on this blog's service user (resolveServiceUser()) and
     * returns what the provisioning POST needs. Call only inside the provisioning lock.
     *
     * Nothing is revoked here. Earlier attempts' passwords stay on the user until a confirm
     * removes them (confirmBlogCredentials()), because a password minted by a stalled runner
     * may still be the one ihf-root ends up holding; this function cannot know. Names are
     * unique per attempt so a leftover can never make core reject the new one as a duplicate
     * (MAX-4375).
     *
     * @param string $restBase the REST base the caller will send, see resolveRestBaseToSend()
     * @return array|null {username, password, uuid, item, restBase, userId}; null on failure,
     *                    with the stage already recorded
     */
    private function provisionBlogCredentials($restBase)
    {
        if (!class_exists("WP_Application_Passwords")) {
            error_log(sprintf('[Optima Express] create-password: WP_Application_Passwords not available (WordPress < 5.6?) for blog %d', get_current_blog_id()));
            $this->recordBlogProvisionError('WP_Application_Passwords not available', self::STAGE_CREATE_PASSWORD);
            return null;
        }

        $user = $this->resolveServiceUser();
        if ($user === null) {
            // resolveServiceUser() has recorded the specific stage.
            return null;
        }

        // Fresh read of the row: resolveServiceUser() and the callers before it primed it.
        $this->clearUserMetaCache($user->ID);
        $result = WP_Application_Passwords::create_new_application_password(
            $user->ID,
            array("name" => $this->newServicePasswordName())
        );
        if (is_wp_error($result)) {
            $this->recordBlogProvisionError('create_new_application_password failed: ' . $result->get_error_code(), self::STAGE_CREATE_PASSWORD);
            error_log(sprintf('[Optima Express] create-password: create_new_application_password failed for user %d on blog %d: %s', (int) $user->ID, get_current_blog_id(), $result->get_error_code()));
            return null;
        }

        return array(
            "username" => $user->user_login,
            "password" => $result[0],
            "uuid"     => $result[1]["uuid"],
            "item"     => $result[1],
            "restBase" => (string) $restBase,
            "userId"   => (int) $user->ID,
        );
    }

    /**
     * The only revoker in the per-site model. Runs inside the lock once ihf-root has returned
     * 200 for $uuid: every other application password on the service user goes, except one
     * created less than REVOKE_AGE_FLOOR seconds ago, which a stalled runner may have POSTed
     * and may yet confirm (after 800s ihf-root provably holds a later one, so it is safe to
     * remove on the next confirm). There is no adopt-by-last_used rule: $uuid, the credential
     * ihf-root just accepted, is always the one kept.
     *
     * The option writes that record the confirmation are the caller's (Step 6 order); this
     * function touches only the user's password row.
     *
     * @param string $uuid
     * @param int $serviceUserId the pinned per-site user; falls back to the pin when 0
     * @return void
     */
    private function confirmBlogCredentials($uuid, $serviceUserId = 0)
    {
        $serviceUserId = (int) $serviceUserId;
        if ($serviceUserId <= 0) {
            $serviceUserId = $this->getPinnedServiceUserId();
        }
        if ($serviceUserId <= 0 || !class_exists("WP_Application_Passwords")) {
            return;
        }
        // Read the row as it is now, not as this request cached it.
        $this->clearUserMetaCache($serviceUserId);
        $existing = WP_Application_Passwords::get_user_application_passwords($serviceUserId);
        $now = time();
        $revoked = 0;
        foreach ($existing as $appPassword) {
            if (!isset($appPassword["uuid"]) || $appPassword["uuid"] === $uuid) {
                continue;
            }
            $created = isset($appPassword["created"]) ? (int) $appPassword["created"] : 0;
            if ($created > 0 && ($now - $created) < self::REVOKE_AGE_FLOOR) {
                continue;
            }
            WP_Application_Passwords::delete_application_password($serviceUserId, $appPassword["uuid"]);
            $revoked++;
        }
        if ($revoked > 0) {
            error_log(sprintf('[Optima Express] confirm: revoked %d superseded application password(s) on service user %d for blog %d', $revoked, $serviceUserId, get_current_blog_id()));
        }
    }

    /**
     * Records why the last provisioning attempt failed, so the state is readable from
     * wp-admin or a REST read of the option. error_log() alone proved invisible in practice.
     */
    private function recordBlogProvisionError($message, $stage = null)
    {
        $lastError = array(
            "time"    => time(),
            "message" => (string) $message,
        );
        if ($stage !== null) {
            // One of the STAGE_* constants: which step of the round trip failed.
            $lastError["stage"] = (string) $stage;
        }
        update_option(iHomefinderConstants::BLOG_PROVISION_LAST_ERROR_OPTION, $lastError, false);
    }

    /**
     * The legacy shared user, by login. No ownership test: whether that account is ours is
     * sharedUserIsOwned()'s question, and whether it holds a role here is
     * blogHasLegacySharedUser()'s. Returns false when no such login exists.
     *
     * @return WP_User|false
     */
    public function findSharedLogin()
    {
        return get_user_by('login', iHomefinderConstants::SHARED_USER_LOGIN);
    }

    /**
     * Returns true when $user is verifiably the plugin's own legacy shared "optima-express"
     * account. Read-only: the retroactive add_user_meta() / update_option() backfills of the
     * 8.7.6 test are gone, so evaluating ownership never writes a proof (the legacy trio is
     * never written by the per-site model).
     *
     * Three proofs are checked, in order:
     *   1. User meta (BLOG_SERVICE_ACCOUNT_META === '1'), written at creation by 8.7.6+.
     *      wp_usermeta is network-global, so any blog can verify without its own pin.
     *   2. The legacy per-blog pin (BLOG_USER_ID_OPTION) names this user.
     *   3. The legacy confirmed uuid (BLOG_APP_PASSWORD_UUID_OPTION) is among this user's
     *      application passwords. Covers pre-8.7.6 sites that confirmed at least once.
     *
     * Email is deliberately NOT a proof: any logged-in user can PATCH /wp-json/wp/v2/users/me
     * to adopt our canonical address with no confirmation required (CVE-2026-93901).
     *
     * @param WP_User|null $user
     * @return bool
     */
    public function sharedUserIsOwned($user)
    {
        if (!($user instanceof WP_User) || (int) $user->ID <= 0) {
            return false;
        }
        $this->clearUserMetaCache($user->ID);
        // Proof 1: user meta, global on multisite, not forgeable via REST.
        if (get_user_meta($user->ID, iHomefinderConstants::BLOG_SERVICE_ACCOUNT_META, true) === '1') {
            return true;
        }
        // Proof 2: the legacy per-blog pin.
        $pinnedId = (int) get_option(iHomefinderConstants::BLOG_USER_ID_OPTION, 0);
        if ($pinnedId > 0 && (int) $user->ID === $pinnedId) {
            return true;
        }
        // Proof 3: the legacy confirmed uuid among its passwords.
        if (!class_exists("WP_Application_Passwords")) {
            return false;
        }
        $legacyUuid = $this->getLegacyConfirmedUuid();
        if ($legacyUuid === '') {
            return false;
        }
        $passwords = WP_Application_Passwords::get_user_application_passwords($user->ID);
        foreach ($passwords as $appPassword) {
            if (isset($appPassword["uuid"]) && $appPassword["uuid"] === $legacyUuid) {
                return true;
            }
        }
        return false;
    }

    /*
     * Shared user demotion per blog and network retirement (Step 10).
     *
     * Demotion removes the legacy shared user's role on one blog once that blog's per-site
     * credential has been confirmed and the grace has passed; it never touches the shared
     * user's password row. Retirement purges the shared user's plugin-named passwords once no
     * live blog has granted it a role for IHF_SHARED_RETIRE_ZERO_DAYS consecutive daily
     * readings (or earlier through the operator arm), under a network-scope lock, with a
     * compare-and-swap write. Neither ever deletes the user.
     */

    /**
     * The legacy shared user this network can act on, or null. The pinned
     * SHARED_USER_ID_SITE_OPTION wins when it names an existing user (a pin is only written
     * after ownership passed); a pin whose user is gone is dropped and the login lookup runs.
     * Otherwise findSharedLogin() plus the read-only sharedUserIsOwned(): ownership is evaluated
     * before the squatted flag is consulted (MAX-4440 item 2), so an owned user clears a stale
     * flag and is pinned, and an unowned one returns null for the caller to record as skipped,
     * whether or not the flag was ever set.
     *
     * @return WP_User|null
     */
    public function resolveSharedUser()
    {
        $blogId = get_current_blog_id();
        $pinnedId = (int) get_site_option(iHomefinderConstants::SHARED_USER_ID_SITE_OPTION, 0);
        if ($pinnedId > 0) {
            $pinned = get_user_by('ID', $pinnedId);
            if ($pinned instanceof WP_User) {
                $this->clearStaleSquattedFlag($blogId, $pinnedId);
                return $pinned;
            }
            delete_site_option(iHomefinderConstants::SHARED_USER_ID_SITE_OPTION);
            error_log(sprintf('[Optima Express] pinned shared user %d no longer exists; pin dropped', $pinnedId));
        }
        $shared = $this->findSharedLogin();
        if (!($shared instanceof WP_User)) {
            return null;
        }
        if (!$this->sharedUserIsOwned($shared)) {
            error_log(sprintf('[Optima Express] shared user %d on blog %d: ownership unverifiable', (int) $shared->ID, $blogId));
            return null;
        }
        $this->clearStaleSquattedFlag($blogId, (int) $shared->ID);
        update_site_option(iHomefinderConstants::SHARED_USER_ID_SITE_OPTION, (int) $shared->ID);
        return $shared;
    }

    /**
     * An 8.7.6 node that could not prove ownership wrote BLOG_PROVISION_SQUATTED_OPTION; once
     * the shared user is verified (here, or on any blog before this one, which is what the pin
     * records) that flag is stale and is removed so the blog leaves the legacy-squatted list.
     *
     * @param int $blogId
     * @param int $sharedId
     * @return void
     */
    private function clearStaleSquattedFlag($blogId, $sharedId)
    {
        if (empty(get_option(iHomefinderConstants::BLOG_PROVISION_SQUATTED_OPTION, null))) {
            return;
        }
        delete_option(iHomefinderConstants::BLOG_PROVISION_SQUATTED_OPTION);
        error_log(sprintf('[Optima Express] squatted-cleared for blog %d: shared user %d verified', (int) $blogId, (int) $sharedId));
    }

    /**
     * Removes the shared user's role on this blog once it is due: the blog has a legacy user,
     * is not halted, SHARED_DEMOTE_AFTER has passed, nothing is recorded in
     * SHARED_USER_DEMOTED, and SHARED_DEMOTE_NEXT_AT (a throttle written on every deferral) has
     * passed. Runs under the per-blog lock (skipped when held). Handler of ihf_oe_shared_demote
     * and the claimed tick's fallback.
     *
     * Guards, inside the lock: (a) provisioning pending -> defer; (b) legacy uuid off the
     * snapshot (an 8.7.7 node re-provisioned) -> pending and a forced rotation, defer; (c) no
     * confirmed per-site uuid -> defer; ownership unverifiable -> demoted = "skipped", stop;
     * (c2) a shared-user password younger than REVOKE_AGE_FLOOR may be a round trip in flight
     * -> defer; (d) the evidence that ihf-root holds the per-site credential is the 200 it
     * returned at confirm time plus the grace for its per-node cache, so with the uuid still
     * present the role is removed, after one last pending check; (e) if pending, the legacy
     * uuid or the shared user's password set changed during the removal, the role is restored
     * and the blog marked pending. Writes nothing on a blog with no legacy user.
     *
     * @return void
     */
    public function demoteSharedUserIfDue()
    {
        $blogId = get_current_blog_id();
        if (!$this->blogHasLegacySharedUser()) {
            return;
        }
        if ($this->migrationHaltedFor($blogId) || !$this->sharedDemotionDue()) {
            return;
        }
        $lockToken = $this->acquireProvisionLock();
        if ($lockToken === null) {
            error_log(sprintf('[Optima Express] demote skipped for blog %d: provisioning lock held', $blogId));
            return;
        }
        try {
            $this->clearProvisionOptionCaches();
            if (!$this->sharedDemotionDue()) {
                return;
            }
            // (a)
            if (!empty(get_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, null))) {
                $this->deferSharedDemotion($blogId, 'provisioning pending');
                return;
            }
            // (b)
            $snapshot = get_option(iHomefinderConstants::LEGACY_UUID_SNAPSHOT_OPTION, '');
            if ($this->getLegacyConfirmedUuid() !== (is_scalar($snapshot) ? (string) $snapshot : '')) {
                update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, '1');
                update_option(iHomefinderConstants::BLOG_FORCE_ROTATE_OPTION, '1');
                $this->deferSharedDemotion($blogId, 'legacy credential changed; forced rotation flagged');
                return;
            }
            // (c)
            if ($this->getConfirmedServicePasswordUuid() === null) {
                $this->deferSharedDemotion($blogId, 'no confirmed per-site credential');
                return;
            }
            $shared = $this->resolveSharedUser();
            if ($shared === null) {
                update_option(iHomefinderConstants::SHARED_USER_DEMOTED_OPTION, iHomefinderConstants::SHARED_USER_DEMOTED_SKIPPED);
                delete_option(iHomefinderConstants::SHARED_DEMOTE_NEXT_AT_OPTION);
                $this->recordBlogProvisionError('legacy-unverifiable: the shared user could not be verified as ours; demotion skipped', self::STAGE_DEMOTE);
                error_log(sprintf('[Optima Express] demote skipped for blog %d: legacy-unverifiable', $blogId));
                return;
            }
            $sharedId = (int) $shared->ID;
            // (c2)
            $passwordsBefore = $this->sharedUserPasswords($sharedId);
            foreach ($passwordsBefore as $appPassword) {
                $created = isset($appPassword['created']) ? (int) $appPassword['created'] : 0;
                if ($created > time() - self::REVOKE_AGE_FLOOR) {
                    $this->deferSharedDemotion($blogId, 'a shared-user password younger than the age floor may be in flight');
                    return;
                }
            }
            $uuidsBefore = $this->passwordUuidSet($passwordsBefore);
            $legacyBefore = $this->getLegacyConfirmedUuid();
            // (d) One last look at pending immediately before the role goes.
            $this->clearProvisionOptionCaches(array(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION));
            if (!empty(get_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, null))) {
                $this->deferSharedDemotion($blogId, 'provisioning pending');
                return;
            }
            $result = $this->removeSharedRole($shared, $blogId);
            if (is_wp_error($result) && $result->get_error_code() !== 'user_does_not_exist') {
                $this->recordBlogProvisionError('demotion failed: ' . $result->get_error_code(), self::STAGE_DEMOTE);
                $this->deferSharedDemotion($blogId, 'role removal failed');
                return;
            }
            update_option(iHomefinderConstants::SHARED_USER_DEMOTED_OPTION, time());
            delete_option(iHomefinderConstants::SHARED_DEMOTE_NEXT_AT_OPTION);
            error_log(sprintf('[Optima Express] demoted shared user %d on blog %d', $sharedId, $blogId));
            // (e)
            $this->clearProvisionOptionCaches(array(
                iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION,
                iHomefinderConstants::BLOG_APP_PASSWORD_UUID_OPTION,
            ));
            $pendingNow = !empty(get_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, null));
            $legacyChanged = $this->getLegacyConfirmedUuid() !== $legacyBefore;
            $uuidsChanged = $this->passwordUuidSet($this->sharedUserPasswords($sharedId)) !== $uuidsBefore;
            if ($pendingNow || $legacyChanged || $uuidsChanged) {
                $this->restoreSharedRole($shared, $blogId);
                delete_option(iHomefinderConstants::SHARED_USER_DEMOTED_OPTION);
                update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, '1');
                error_log(sprintf('[Optima Express] demotion reverted on blog %d: state changed during removal (%s)', $blogId, $pendingNow ? 'pending' : ($legacyChanged ? 'legacy credential' : 'shared password set')));
            }
        } finally {
            $this->releaseProvisionLock($lockToken);
        }
    }

    /**
     * SHARED_DEMOTE_AFTER set and passed, nothing recorded in SHARED_USER_DEMOTED, and the
     * deferral throttle SHARED_DEMOTE_NEXT_AT passed. Option reads only.
     *
     * @return bool
     */
    private function sharedDemotionDue()
    {
        $after = (int) get_option(iHomefinderConstants::SHARED_DEMOTE_AFTER_OPTION, 0);
        if ($after <= 0 || time() < $after) {
            return false;
        }
        if (!empty(get_option(iHomefinderConstants::SHARED_USER_DEMOTED_OPTION, null))) {
            return false;
        }
        return time() >= (int) get_option(iHomefinderConstants::SHARED_DEMOTE_NEXT_AT_OPTION, 0);
    }

    /**
     * Pushes the next demotion attempt out by one grace period and says why.
     *
     * @param int $blogId
     * @param string $reason
     * @return void
     */
    private function deferSharedDemotion($blogId, $reason)
    {
        update_option(iHomefinderConstants::SHARED_DEMOTE_NEXT_AT_OPTION, time() + self::SHARED_DEMOTE_GRACE, false);
        error_log(sprintf('[Optima Express] demote deferred for blog %d: %s', $blogId, $reason));
    }

    /**
     * @param WP_User $shared
     * @param int $blogId
     * @return true|WP_Error
     */
    private function removeSharedRole($shared, $blogId)
    {
        if (is_multisite()) {
            return remove_user_from_blog((int) $shared->ID, (int) $blogId);
        }
        $shared->set_role('');
        return true;
    }

    /**
     * @param WP_User $shared
     * @param int $blogId
     * @return void
     */
    private function restoreSharedRole($shared, $blogId)
    {
        if (is_multisite()) {
            add_user_to_blog((int) $blogId, (int) $shared->ID, 'author');
            return;
        }
        $shared->set_role('author');
    }

    /**
     * The shared user's application passwords, read fresh (cache cleared first). Empty when
     * the class is missing.
     *
     * @param int $userId
     * @return array
     */
    private function sharedUserPasswords($userId)
    {
        if (!class_exists('WP_Application_Passwords')) {
            return array();
        }
        $this->clearUserMetaCache($userId);
        $row = WP_Application_Passwords::get_user_application_passwords((int) $userId);
        return is_array($row) ? $row : array();
    }

    /**
     * Sorted uuid list of a password row, for before/after comparison.
     *
     * @param array $passwords
     * @return string[]
     */
    private function passwordUuidSet($passwords)
    {
        $uuids = array();
        foreach ($passwords as $appPassword) {
            if (isset($appPassword['uuid'])) {
                $uuids[] = (string) $appPassword['uuid'];
            }
        }
        sort($uuids);
        return $uuids;
    }

    /**
     * Blog-independent halt predicate for the network-scope retirement: the kill switch, the
     * network pause, or the retirement hold. migrationHaltedFor() is false on a no-legacy blog
     * and must not gate a network action. The hold blocks only the purge; the daily count
     * still runs under it (retireSharedUserIfDue() tells the three apart).
     *
     * @return bool
     */
    public function retirementHalted()
    {
        if (defined('IHF_BLOG_MIGRATION_DISABLED') && IHF_BLOG_MIGRATION_DISABLED) {
            return true;
        }
        if (!empty(get_site_option(iHomefinderConstants::BLOG_MIGRATION_PAUSED_SITE_OPTION, null))) {
            return true;
        }
        return !empty(get_site_option(iHomefinderConstants::SHARED_RETIRE_HOLD_SITE_OPTION, null));
    }

    /**
     * Handler of the daily ihf_oe_shared_retire cron event.
     *
     * @return void
     */
    public function runSharedRetireCron()
    {
        $this->retireSharedUserIfDue('cron');
    }

    /**
     * One retirement evaluation for the network (or the single site). Runs only from the cron
     * handler or from an admin request by a user with manage_network_options (single site:
     * manage_options); never from ajax. The daily claim is an atomic conditional UPDATE on
     * SHARED_RETIRE_NEXT_AT (a Step 12 control may pass $skipDailyClaim to evaluate now); the
     * work runs under the network lock SHARED_RETIRE_LOCK, skipped when held. Returns true
     * only when a purge was written.
     *
     * @param string $context "cron" or "admin", for the log
     * @param bool $skipDailyClaim
     * @return bool
     */
    public function retireSharedUserIfDue($context = 'cron', $skipDailyClaim = false)
    {
        if (wp_doing_ajax()) {
            return false;
        }
        if ($context !== 'cron' && !current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            return false;
        }
        if (defined('IHF_BLOG_MIGRATION_DISABLED') && IHF_BLOG_MIGRATION_DISABLED) {
            error_log('[Optima Express] retire skipped: migration disabled by constant');
            return false;
        }
        if (!empty(get_site_option(iHomefinderConstants::BLOG_MIGRATION_PAUSED_SITE_OPTION, null))) {
            error_log('[Optima Express] retire skipped: network paused');
            return false;
        }
        if (!$skipDailyClaim && !$this->claimSharedRetireDaily()) {
            return false;
        }
        $lockToken = $this->acquireSharedRetireLock();
        if ($lockToken === null) {
            error_log('[Optima Express] retire skipped: network retirement lock held');
            return false;
        }
        try {
            return $this->runSharedRetirement((string) $context);
        } finally {
            $this->releaseSharedRetireLock($lockToken);
        }
    }

    /**
     * The retirement routine proper; one body for the automatic and the operator-armed gate.
     * Runs under the network lock.
     *
     * @param string $context
     * @return bool true when a purge was written
     */
    private function runSharedRetirement($context)
    {
        global $wpdb;
        $sharedId = (int) get_site_option(iHomefinderConstants::SHARED_USER_ID_SITE_OPTION, 0);
        if ($sharedId <= 0) {
            error_log('[Optima Express] retire-count skipped: no shared user pinned for this network');
            return false;
        }
        $hold = !empty(get_site_option(iHomefinderConstants::SHARED_RETIRE_HOLD_SITE_OPTION, null));
        $armed = !empty(get_site_option(iHomefinderConstants::SHARED_RETIRE_ARMED_SITE_OPTION, null));
        wp_cache_delete($sharedId, 'users');
        $this->clearUserMetaCache($sharedId);
        $shared = get_userdata($sharedId);
        if (!($shared instanceof WP_User)) {
            $this->recordSharedCountError($sharedId, 'pinned shared user does not exist');
            return false;
        }
        // A super admin passes every capability check on every blog with no membership row, so
        // memberships prove nothing; a filter short-circuiting the membership list is an
        // unverifiable count.
        if (is_super_admin($sharedId) || has_filter('pre_get_blogs_of_user')) {
            update_site_option(iHomefinderConstants::SHARED_LIVE_COUNT_SITE_OPTION, -1);
            update_site_option(iHomefinderConstants::SHARED_LIVE_COUNT_AT_SITE_OPTION, time());
            delete_site_option(iHomefinderConstants::SHARED_ZERO_SINCE_SITE_OPTION);
            error_log(sprintf('[Optima Express] retire-superadmin: shared user %d is a super admin or a membership filter is present; no purge', $sharedId));
            return false;
        }
        $count = $this->countSharedMemberships($sharedId, $shared);
        if ($count === null) {
            $this->recordSharedCountError($sharedId, $wpdb->last_error !== '' ? 'database error' : 'zero total memberships');
            return false;
        }
        $now = time();
        update_site_option(iHomefinderConstants::SHARED_LIVE_COUNT_SITE_OPTION, $count['live']);
        update_site_option(iHomefinderConstants::SHARED_LIVE_COUNT_AT_SITE_OPTION, $now);
        if ($count['live'] === 0) {
            if (empty(get_site_option(iHomefinderConstants::SHARED_ZERO_SINCE_SITE_OPTION, null))) {
                update_site_option(iHomefinderConstants::SHARED_ZERO_SINCE_SITE_OPTION, $now);
            }
        } else {
            delete_site_option(iHomefinderConstants::SHARED_ZERO_SINCE_SITE_OPTION);
        }
        $zeroSince = (int) get_site_option(iHomefinderConstants::SHARED_ZERO_SINCE_SITE_OPTION, 0);
        $zeroDays = defined('IHF_SHARED_RETIRE_ZERO_DAYS') ? (int) IHF_SHARED_RETIRE_ZERO_DAYS : 7;
        $countdownDone = $count['live'] === 0 && $zeroSince > 0 && ($now - $zeroSince) >= $zeroDays * 86400;

        // The row is read before the gates: the operator arm's proof 3 lives in it.
        $row = $this->readSharedPasswordRow($sharedId);
        $entries = array();
        if ($row !== null) {
            $entries = maybe_unserialize($row->meta_value);
            if (!is_array($entries)) {
                $this->recordSharedCountError($sharedId, 'password row unreadable');
                return false;
            }
        }
        $proof1 = get_user_meta($sharedId, iHomefinderConstants::BLOG_SERVICE_ACCOUNT_META, true) === '1';

        $mode = null;
        $reason = null;
        if ($hold) {
            $reason = self::STAGE_RETIRE_HOLD;
        } elseif ($armed) {
            if ($proof1 || $this->sharedOwnedByLegacyPinAndUuid($sharedId, $entries)) {
                $mode = iHomefinderConstants::SHARED_RETIRED_BY_OPERATOR;
            } else {
                $reason = self::STAGE_RETIRE_UNOWNED;
            }
        } elseif ($count['live'] > 0) {
            $reason = 'live-members';
        } elseif (!$countdownDone) {
            $reason = 'countdown';
        } elseif (!$proof1) {
            $reason = self::STAGE_RETIRE_UNOWNED;
        } else {
            $mode = iHomefinderConstants::SHARED_RETIRED_BY_AUTO;
        }
        error_log(sprintf(
            '[Optima Express] retire-count for shared user %d (%s): live %d, all %d, zero_since %d, passwords %d, %s%s',
            $sharedId,
            $context,
            $count['live'],
            $count['all'],
            $zeroSince,
            count($entries),
            $mode !== null ? 'purge by ' . $mode : 'blocked: ' . $reason,
            !empty($count['ids']) ? ', holders ' . implode(',', array_slice($count['ids'], 0, self::RETIRE_LOG_MAX_BLOG_IDS)) : ''
        ));
        if ($mode === null) {
            return false;
        }

        // Password-age gates on every entry, plugin-named or not: a young mint is an 8.7.7 node
        // still provisioning the shared way; a recent use is an integration that still holds a
        // shared credential, which a zero count cannot explain.
        $recent = $mode === iHomefinderConstants::SHARED_RETIRED_BY_AUTO ? self::SHARED_RETIRE_RECENT_USE : self::SHARED_RETIRE_QUIET_PERIOD;
        foreach ($entries as $entry) {
            $created = isset($entry['created']) ? (int) $entry['created'] : 0;
            $lastUsed = isset($entry['last_used']) ? (int) $entry['last_used'] : 0;
            if ($created > $now - self::REVOKE_AGE_FLOOR) {
                error_log(sprintf('[Optima Express] retire-young: a shared-user password was created within %ds; no purge', self::REVOKE_AGE_FLOOR));
                return false;
            }
            $createdRecently = $mode === iHomefinderConstants::SHARED_RETIRED_BY_AUTO && $created > $now - $recent;
            if ($createdRecently || $lastUsed > $now - $recent) {
                error_log(sprintf('[Optima Express] retire-used: a shared-user password was %s within %d days; no purge', $createdRecently ? 'created' : 'used', (int) ($recent / 86400)));
                return false;
            }
        }

        // Scope: the automatic path removes only the plugin's own name families; the operator
        // arm removes everything.
        $removed = array();
        $remaining = array();
        foreach ($entries as $entry) {
            $name = isset($entry['name']) ? (string) $entry['name'] : '';
            if ($mode === iHomefinderConstants::SHARED_RETIRED_BY_OPERATOR || $this->isPluginFamilyPasswordName($name)) {
                $removed[] = $entry;
            } else {
                $remaining[] = $entry;
            }
        }
        $foreign = count($remaining);
        if (empty($removed)) {
            if ($foreign > 0) {
                error_log(sprintf('[Optima Express] retire: no plugin-named passwords on shared user %d; %d foreign passwords left for the operator arm', $sharedId, $foreign));
            }
            $this->recordSharedRetired(0, $mode);
            return false;
        }

        // The redacted record goes in before the row is rewritten: names encode blog ids and
        // last_used is what MAX-4375 was diagnosed from.
        $this->writeNetworkOption(
            iHomefinderConstants::SHARED_RETIRED_SUMMARY_SITE_OPTION,
            $this->buildRetireSummary($entries, count($removed), $foreign, $mode, $context, $now),
            false
        );

        // Compare-and-swap on the exact bytes read: an 8.7.7 mint that landed after our read
        // makes this a miss, never a lost password.
        $newValue = serialize(array_values($remaining));
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE umeta_id = %d AND MD5(meta_value) = %s",
            $newValue,
            (int) $row->umeta_id,
            md5((string) $row->meta_value)
        ));
        $this->clearUserMetaCache($sharedId);
        if ($affected !== 1) {
            error_log(sprintf('[Optima Express] retire-cas-miss: shared user %d password row changed under the purge; will retry on the next daily claim', $sharedId));
            return false;
        }
        foreach ($removed as $item) {
            do_action('wp_delete_application_password', $sharedId, $item);
        }

        // Post-purge recount: a blog that joined between the count and the write is on a
        // credential that no longer exists; rotate it now rather than at its next tick.
        $recount = $this->countSharedMemberships($sharedId, $shared);
        if ($recount !== null && $recount['live'] > 0) {
            update_site_option(iHomefinderConstants::SHARED_LIVE_COUNT_SITE_OPTION, $recount['live']);
            update_site_option(iHomefinderConstants::SHARED_LIVE_COUNT_AT_SITE_OPTION, time());
            delete_site_option(iHomefinderConstants::SHARED_ZERO_SINCE_SITE_OPTION);
            foreach ($recount['ids'] as $lateBlogId) {
                $this->flagLateSharedMember((int) $lateBlogId);
            }
        }

        $familyLeft = 0;
        $after = $this->readSharedPasswordRow($sharedId);
        if ($after !== null) {
            $afterEntries = maybe_unserialize($after->meta_value);
            if (is_array($afterEntries)) {
                foreach ($afterEntries as $entry) {
                    $name = isset($entry['name']) ? (string) $entry['name'] : '';
                    if ($mode === iHomefinderConstants::SHARED_RETIRED_BY_OPERATOR || $this->isPluginFamilyPasswordName($name)) {
                        $familyLeft++;
                    }
                }
            }
        }
        if ($familyLeft === 0) {
            $this->recordSharedRetired(count($removed), $mode);
        }
        error_log(sprintf('[Optima Express] retire-purged: %d passwords removed from shared user %d by %s (%s); %d foreign left, %d in scope left', count($removed), $sharedId, $mode, $context, $foreign, $familyLeft));
        return true;
    }

    /**
     * Writes the durable retirement record once: only when nothing is recorded yet.
     *
     * @param int $count
     * @param string $by
     * @return void
     */
    private function recordSharedRetired($count, $by)
    {
        if (!empty(get_site_option(iHomefinderConstants::SHARED_RETIRED_AT_SITE_OPTION, null))) {
            return;
        }
        update_site_option(iHomefinderConstants::SHARED_RETIRED_AT_SITE_OPTION, time());
        update_site_option(iHomefinderConstants::SHARED_RETIRED_COUNT_SITE_OPTION, (int) $count);
        update_site_option(iHomefinderConstants::SHARED_RETIRED_BY_SITE_OPTION, (string) $by);
    }

    /**
     * A count that cannot be trusted: record -1, drop the countdown, log retire-count-error.
     *
     * @param int $sharedId
     * @param string $detail
     * @return void
     */
    private function recordSharedCountError($sharedId, $detail)
    {
        update_site_option(iHomefinderConstants::SHARED_LIVE_COUNT_SITE_OPTION, -1);
        update_site_option(iHomefinderConstants::SHARED_LIVE_COUNT_AT_SITE_OPTION, time());
        delete_site_option(iHomefinderConstants::SHARED_ZERO_SINCE_SITE_OPTION);
        error_log(sprintf('[Optima Express] retire-count-error for shared user %d: %s', (int) $sharedId, $detail));
    }

    /**
     * The shared user's memberships, counted with $wpdb directly so that an error is
     * distinguishable from zero (get_blogs_of_user() returns [] for both, and caches the
     * failure). Multisite: "all" is every <prefix>_<n>_capabilities row, "live" the subset whose
     * wp_blogs row is not deleted, archived or spam, with those blog ids. Zero total memberships
     * is treated as an error (the shared user always holds retired memberships). Single site:
     * live is whether the user still has a role; get_blogs_of_user() never returns zero there.
     *
     * @param int $sharedId
     * @param WP_User $shared
     * @return array|null {all: int, live: int, ids: int[]} or null on error
     */
    private function countSharedMemberships($sharedId, $shared)
    {
        global $wpdb;
        if (!is_multisite()) {
            $live = !empty($shared->roles) ? 1 : 0;
            return array('all' => 1, 'live' => $live, 'ids' => $live ? array(get_current_blog_id()) : array());
        }
        $base = $wpdb->base_prefix;
        $all = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key REGEXP %s",
            $sharedId,
            '^' . preg_quote($base) . '([0-9]+_)?capabilities$'
        ));
        if ($all === null || $wpdb->last_error !== '' || (int) $all === 0) {
            return null;
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT b.blog_id FROM {$wpdb->blogs} b INNER JOIN {$wpdb->usermeta} um ON um.user_id = %d AND um.meta_key = IF(b.blog_id = 1, %s, CONCAT(%s, b.blog_id, '_capabilities')) WHERE b.deleted = '0' AND b.archived = '0' AND b.spam = '0' ORDER BY b.blog_id ASC",
            $sharedId,
            $base . 'capabilities',
            $base
        ));
        if (!is_array($ids) || $wpdb->last_error !== '') {
            return null;
        }
        $ids = array_map('intval', $ids);
        return array('all' => (int) $all, 'live' => count($ids), 'ids' => $ids);
    }

    /**
     * The shared user's raw application-password row: umeta_id and the serialized value, read
     * with the cache cleared so the compare-and-swap hashes what is actually in the table.
     *
     * @param int $sharedId
     * @return object|null
     */
    private function readSharedPasswordRow($sharedId)
    {
        global $wpdb;
        if (!class_exists('WP_Application_Passwords')) {
            return null;
        }
        $this->clearUserMetaCache($sharedId);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id ASC LIMIT 1",
            (int) $sharedId,
            WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS
        ));
        return is_object($row) && isset($row->umeta_id, $row->meta_value) ? $row : null;
    }

    /**
     * Operator-arm ownership without the global meta: the legacy pin of the current blog names
     * the user and the legacy confirmed uuid is among its passwords (proofs 2 and 3 together).
     *
     * @param int $sharedId
     * @param array $entries
     * @return bool
     */
    private function sharedOwnedByLegacyPinAndUuid($sharedId, $entries)
    {
        if ((int) get_option(iHomefinderConstants::BLOG_USER_ID_OPTION, 0) !== (int) $sharedId) {
            return false;
        }
        $legacyUuid = $this->getLegacyConfirmedUuid();
        if ($legacyUuid === '') {
            return false;
        }
        foreach ($entries as $entry) {
            if (isset($entry['uuid']) && (string) $entry['uuid'] === $legacyUuid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether $name is in either plugin family for any blog: the 8.7.x names
     * ("ihomefinder-blog", "ihomefinder-blog-<n>", either plus "-<8 hex>") or the 8.8.0 names
     * ("optima-express-blog-<n>-<8 hex>"). isPluginPasswordName() answers for the current blog
     * only; the network purge needs every blog's family.
     *
     * @param string $name
     * @return bool
     */
    public function isPluginFamilyPasswordName($name)
    {
        if (!is_string($name) || $name === '') {
            return false;
        }
        if (preg_match('/^ihomefinder-blog(-[0-9]+)?(-[0-9a-f]{8})?$/', $name) === 1) {
            return true;
        }
        return preg_match('/^' . preg_quote(self::SERVICE_PASSWORD_NAME_PREFIX, '/') . '[0-9]+-[0-9a-f]{8}$/', $name) === 1;
    }

    /**
     * Blog id encoded in a plugin-family password name, or 0 (the un-numbered single-site name
     * and foreign names).
     *
     * @param string $name
     * @return int
     */
    private function blogIdFromPasswordName($name)
    {
        if (preg_match('/^ihomefinder-blog-([0-9]+)(-[0-9a-f]{8})?$/', $name, $m) === 1) {
            return (int) $m[1];
        }
        if (preg_match('/^' . preg_quote(self::SERVICE_PASSWORD_NAME_PREFIX, '/') . '([0-9]+)-[0-9a-f]{8}$/', $name, $m) === 1) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * The redacted pre-purge summary: counts, created and last_used ranges, recent-use buckets,
     * distinct blog ids parsed from names, and at most RETIRE_SUMMARY_MAX_ENTRIES
     * (blog_id, created, last_used) triples. Never a hash, uuid, app_id or last_ip.
     *
     * @param array $entries
     * @param int $removedCount
     * @param int $foreign
     * @param string $mode
     * @param string $context
     * @param int $now
     * @return array
     */
    private function buildRetireSummary($entries, $removedCount, $foreign, $mode, $context, $now)
    {
        $summary = array(
            'at' => $now,
            'by' => $mode,
            'context' => $context,
            'total' => count($entries),
            'family' => 0,
            'foreign' => (int) $foreign,
            'removed' => (int) $removedCount,
            'created_min' => null,
            'created_max' => null,
            'last_used_min' => null,
            'last_used_max' => null,
            'used_7d' => 0,
            'used_30d' => 0,
            'used_90d' => 0,
            'blog_ids' => 0,
            'entries' => array(),
        );
        $blogIds = array();
        foreach ($entries as $entry) {
            $name = isset($entry['name']) ? (string) $entry['name'] : '';
            $created = isset($entry['created']) ? (int) $entry['created'] : 0;
            $lastUsed = isset($entry['last_used']) ? (int) $entry['last_used'] : 0;
            if ($this->isPluginFamilyPasswordName($name)) {
                $summary['family']++;
            }
            if ($created > 0) {
                $summary['created_min'] = $summary['created_min'] === null ? $created : min($summary['created_min'], $created);
                $summary['created_max'] = $summary['created_max'] === null ? $created : max($summary['created_max'], $created);
            }
            if ($lastUsed > 0) {
                $summary['last_used_min'] = $summary['last_used_min'] === null ? $lastUsed : min($summary['last_used_min'], $lastUsed);
                $summary['last_used_max'] = $summary['last_used_max'] === null ? $lastUsed : max($summary['last_used_max'], $lastUsed);
                if ($lastUsed > $now - 7 * 86400) {
                    $summary['used_7d']++;
                }
                if ($lastUsed > $now - 30 * 86400) {
                    $summary['used_30d']++;
                }
                if ($lastUsed > $now - 90 * 86400) {
                    $summary['used_90d']++;
                }
            }
            $blogId = $this->blogIdFromPasswordName($name);
            if ($blogId > 0) {
                $blogIds[$blogId] = true;
            }
            if (count($summary['entries']) < self::RETIRE_SUMMARY_MAX_ENTRIES) {
                $summary['entries'][] = array($blogId, $created, $lastUsed);
            }
        }
        $summary['blog_ids'] = count($blogIds);
        return $summary;
    }

    /**
     * A blog found holding a shared membership after the purge: its credential is gone, so
     * mark it pending with a forced rotation and queue its runner. Logged as retire-late-member.
     *
     * @param int $blogId
     * @return void
     */
    private function flagLateSharedMember($blogId)
    {
        switch_to_blog($blogId);
        try {
            if ($this->blogIsRetired($blogId)) {
                return;
            }
            update_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, '1');
            update_option(iHomefinderConstants::BLOG_FORCE_ROTATE_OPTION, '1');
            if (!wp_next_scheduled(iHomefinderConstants::BLOG_PROVISION_RETRY_CRON_HOOK)) {
                wp_schedule_single_event(time(), iHomefinderConstants::BLOG_PROVISION_RETRY_CRON_HOOK);
            }
            error_log(sprintf('[Optima Express] retire-late-member: blog %d still holds a shared membership after the purge; rotation flagged', $blogId));
        } finally {
            restore_current_blog();
        }
    }

    /**
     * Network option write with an autoload choice on single site (update_site_option() has
     * none there and would autoload the large summary).
     *
     * @param string $name
     * @param mixed $value
     * @param bool $autoload
     * @return void
     */
    private function writeNetworkOption($name, $value, $autoload)
    {
        if (is_multisite()) {
            update_site_option($name, $value);
            return;
        }
        update_option($name, $value, $autoload);
    }

    /*
     * Network-scope claim and lock for retirement. Both live in wp_sitemeta on multisite (the
     * per-blog options table is the wrong scope) and in wp_options on single site.
     *
     * wp_sitemeta has no unique key on meta_key, so INSERT IGNORE cannot arbitrate two writers
     * there the way it does in wp_options. The lock therefore inserts its row unconditionally
     * and then asks which row for the key has the lowest id: that holder owns the lock, every
     * other writer deletes its own row. The same code runs on wp_options, where the ignored
     * duplicate simply never appears in the select.
     */

    /**
     * Takes the daily retirement claim: seed the row (add_site_option() on multisite, which
     * never overwrites; INSERT IGNORE on single site), then UPDATE ... WHERE value <= now.
     * Exactly one caller per SHARED_RETIRE_CLAIM_INTERVAL sees a row change.
     *
     * @return bool
     */
    private function claimSharedRetireDaily()
    {
        global $wpdb;
        $key = iHomefinderConstants::SHARED_RETIRE_NEXT_AT_SITE_OPTION;
        $now = time();
        $next = $now + self::SHARED_RETIRE_CLAIM_INTERVAL;
        if (is_multisite()) {
            $networkId = get_current_network_id();
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 1",
                $networkId,
                $key
            ));
            if ($existing === null) {
                add_site_option($key, '0');
            } elseif ((int) $existing > $now) {
                return false;
            }
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->sitemeta} SET meta_value = %d WHERE site_id = %d AND meta_key = %s AND meta_value <= %d",
                $next,
                $networkId,
                $key,
                $now
            ));
            wp_cache_delete($networkId . ':' . $key, 'site-options');
            wp_cache_delete($networkId . ':notoptions', 'site-options');
            return is_int($claimed) && $claimed >= 1;
        }
        $existing = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
        if ($existing === null) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')",
                $key
            ));
        } elseif ((int) $existing > $now) {
            return false;
        }
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = %s AND option_value <= %d",
            $next,
            $key,
            $now
        ));
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete($key, 'options');
        return $claimed === 1;
    }

    /**
     * Takes the network retirement lock. Returns the holder token, or null when another holder's
     * row is younger than SHARED_RETIRE_LOCK_TTL or the write failed. A stale or unparseable
     * holder row is deleted by exact value and the insert retried once.
     *
     * @return string|null
     */
    private function acquireSharedRetireLock()
    {
        $token = bin2hex(random_bytes(16));
        $value = $token . ':' . time();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if (!$this->insertSharedRetireLockRow($value)) {
                error_log('[Optima Express] network retirement lock insert failed');
                return null;
            }
            $holder = $this->selectSharedRetireLockHolder();
            if ($holder === null) {
                // Deleted between our insert and our read; try again.
                continue;
            }
            if (strpos($holder, $token . ':') === 0) {
                $this->registerSharedRetireLockShutdownRelease($token);
                return $token;
            }
            // Another row owns the key. Ours must go regardless (on wp_options it was never
            // written); the holder's goes only when it is provably stale.
            $this->deleteSharedRetireLockRow($value);
            $heldAt = $this->provisionLockTime($holder);
            if ($heldAt !== null && (time() - $heldAt) < self::SHARED_RETIRE_LOCK_TTL) {
                return null;
            }
            $this->deleteSharedRetireLockRow($holder);
            error_log('[Optima Express] broke a stale network retirement lock');
        }
        return null;
    }

    /**
     * Releases the network lock identified by $token; idempotent.
     *
     * @param string|null $token
     * @return void
     */
    private function releaseSharedRetireLock($token)
    {
        global $wpdb;
        if (!is_string($token) || $token === '') {
            return;
        }
        $key = iHomefinderConstants::SHARED_RETIRE_LOCK_SITE_OPTION;
        if (is_multisite()) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s AND meta_value LIKE %s",
                get_current_network_id(),
                $key,
                $wpdb->esc_like($token) . '%'
            ));
            wp_cache_delete(get_current_network_id() . ':' . $key, 'site-options');
            return;
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
            $key,
            $wpdb->esc_like($token) . '%'
        ));
        wp_cache_delete($key, 'options');
    }

    /**
     * @param string $value "<token>:<time>"
     * @return bool false only when the database refused the write
     */
    private function insertSharedRetireLockRow($value)
    {
        global $wpdb;
        $key = iHomefinderConstants::SHARED_RETIRE_LOCK_SITE_OPTION;
        if (is_multisite()) {
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->sitemeta} (site_id, meta_key, meta_value) VALUES (%d, %s, %s)",
                get_current_network_id(),
                $key,
                $value
            ));
            return $result !== false;
        }
        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $key,
            $value
        ));
        return $result !== false;
    }

    /**
     * The value of the row that owns the lock key (lowest id), or null when none.
     *
     * @return string|null
     */
    private function selectSharedRetireLockHolder()
    {
        global $wpdb;
        $key = iHomefinderConstants::SHARED_RETIRE_LOCK_SITE_OPTION;
        if (is_multisite()) {
            $holder = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
                get_current_network_id(),
                $key
            ));
        } else {
            $holder = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $key
            ));
        }
        return is_string($holder) ? $holder : null;
    }

    /**
     * Deletes a lock row by its exact value.
     *
     * @param string $value
     * @return void
     */
    private function deleteSharedRetireLockRow($value)
    {
        global $wpdb;
        $key = iHomefinderConstants::SHARED_RETIRE_LOCK_SITE_OPTION;
        if (is_multisite()) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s AND meta_value = %s",
                get_current_network_id(),
                $key,
                $value
            ));
            return;
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $key,
            $value
        ));
    }

    /**
     * @param string $token
     * @return void
     */
    private function registerSharedRetireLockShutdownRelease($token)
    {
        $token = (string) $token;
        add_action('shutdown', function () use ($token) {
            $this->releaseSharedRetireLock($token);
        });
    }

    /**
     * Uuid of the per-site application password ihf-root confirmed it stored, or null. This
     * is the only "confirmed uuid" the per-site model reads; the legacy option is read
     * separately by getLegacyConfirmedUuid() for the snapshot comparison and proof 3.
     *
     * @return string|null
     */
    private function getConfirmedServicePasswordUuid()
    {
        $uuid = get_option(iHomefinderConstants::BLOG_SERVICE_PASSWORD_UUID_OPTION, null);
        return (is_string($uuid) && $uuid !== '') ? $uuid : null;
    }

    /**
     * BLOG_APP_PASSWORD_UUID_OPTION as a string, '' when absent. Normalized so that it can be
     * compared with LEGACY_UUID_SNAPSHOT_OPTION, which is always written as a string.
     *
     * @return string
     */
    private function getLegacyConfirmedUuid()
    {
        $uuid = get_option(iHomefinderConstants::BLOG_APP_PASSWORD_UUID_OPTION, '');
        return is_scalar($uuid) ? (string) $uuid : '';
    }

    /**
     * Base of the legacy (8.7.x) password name family on the shared user. Per-site on
     * multisite. Versions before 8.7.5 used this exact string as the name; 8.7.5 to 8.7.7
     * append a per-attempt 8-hex suffix. 8.8.0 never mints a name in this family; it is kept
     * so the shared user's plugin-named passwords can be recognized (retirement, Step 10).
     */
    private function getBlogAppPasswordName()
    {
        return is_multisite() ? "ihomefinder-blog-" . get_current_blog_id() : "ihomefinder-blog";
    }

    /**
     * Whether $name belongs to this site's legacy family: the exact name, or the base plus
     * exactly an 8-hex suffix. Requiring the full suffix shape keeps blog 27's family from
     * matching blog 2702's, and keeps a password a customer named themselves (say
     * "ihomefinder-blog-notes") out of the plugin's reach.
     */
    private function isBlogAppPasswordName($name)
    {
        $base = $this->getBlogAppPasswordName();
        return $name === $base || preg_match('/^' . preg_quote($base, '/') . '-[0-9a-f]{8}$/', $name) === 1;
    }

    /**
     * Base of the 8.8.0 password name family on the per-site user: "optima-express-blog-<blogid>".
     * The blog id is always present (1 on single site). Visible on the service user's profile
     * screen, hence the product name rather than the vendor's.
     */
    private function getServicePasswordName()
    {
        return self::SERVICE_PASSWORD_NAME_PREFIX . get_current_blog_id();
    }

    /**
     * A name that is unique per attempt, so a leftover from an earlier attempt can never make
     * WordPress reject the new credential as a duplicate.
     */
    private function newServicePasswordName()
    {
        return $this->getServicePasswordName() . "-" . substr(str_replace("-", "", wp_generate_uuid4()), 0, 8);
    }

    /**
     * Whether $name is an 8.8.0 per-site password name for this blog.
     */
    private function isServicePasswordName($name)
    {
        return preg_match('/^' . preg_quote($this->getServicePasswordName(), '/') . '-[0-9a-f]{8}$/', $name) === 1;
    }

    /**
     * Whether $name is in either plugin family for this blog (legacy 8.7.x or 8.8.0). This is
     * the "plugin's name family" the automatic retirement purge (Step 10) is limited to.
     *
     * @param string $name
     * @return bool
     */
    public function isPluginPasswordName($name)
    {
        return is_string($name) && ($this->isBlogAppPasswordName($name) || $this->isServicePasswordName($name));
    }

    /**
     * True when iHomefinder returned a registration response carrying a non-empty
     * authentication token, which is the only signal that the request was committed.
     */
    private function hasAuthenticationToken($response)
    {
        if (!is_object($response) || !property_exists($response, "authenticationToken")) {
            return false;
        }
        // An empty XML element decodes to an empty object rather than an empty string.
        return is_scalar($response->authenticationToken) && $response->authenticationToken !== "";
    }

    private function getSitemap()
    {
        $remoteRequest = new iHomefinderRequestor();
        $remoteRequest
            ->addParameter("requestType", "sitemap")
            ->setCacheExpiration(60*60);
        $remoteResponse = $remoteRequest->remoteGetRequest();
        return $remoteResponse;
    }
    
    public function addSitemapForGoogleXmlSitemaps()
    {
        $generatorObject = GoogleSitemapGenerator::GetInstance();
        if ($generatorObject != null) {
            $urls = $this->getSitemap()->getResponse()->sitemap->urlset->url;
            foreach ($urls as $url) {
                $location = null;
                if (property_exists($url, "loc") && !empty($url->loc)) {
                    $location = $url->loc;
                }
                $modified = null;
                if (property_exists($url, "lastmod") && !empty($url->lastmod)) {
                    $modified = new DateTime($url->lastmod);
                    $modified = $modified->format("U");
                }
                $frequency = null;
                if (property_exists($url, "changefreq") && !empty($url->changefreq)) {
                    $frequency = $url->changefreq;
                }
                $priority = null;
                if (property_exists($url, "priority") && !empty($url->priority)) {
                    $priority = $url->priority;
                }
                $generatorObject->AddUrl($location, $modified, $frequency, $priority);
            }
        }
    }
    
    public function addSitemapForYoastWordPressSeo($content)
    {
        global $wpseo_sitemaps;
        if ($wpseo_sitemaps != null) {
            $urls = $this->getSitemap()->getResponse()->sitemap->urlset->url;
            foreach ($urls as $url) {
                $data = array();
                if (property_exists($url, "loc") && !empty($url->loc)) {
                    $data["loc"] = $url->loc;
                }
                if (property_exists($url, "lastmod") && !empty($url->lastmod)) {
                    $data["mod"] = new DateTime($url->lastmod);
                    $data["mod"] = $data["mod"]->format("U");
                }
                if (property_exists($url, "changefreq") && !empty($url->changefreq)) {
                    $data["chf"] = $url->changefreq;
                }
                if (property_exists($url, "priority") && !empty($url->priority)) {
                    $data["pri"] = $url->priority;
                }
                if ($wpseo_sitemaps->renderer != null) {
                    $content .= $wpseo_sitemaps->renderer->sitemap_url($data);
                } else {
                    $content .= $wpseo_sitemaps->sitemap_url($data);
                }
            }
        }
        return $content;
    }
}
