<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


class iHomefinderAdmin {

    private static $instance;
    private $utility;
    private $displayRules;
    
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
        //admin pages
        iHomefinderAdminActivate::getInstance()->registerSettings();
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
     */
    public function updateAuthenticationToken()
    {
        $this->activateAuthenticationToken();
    }

    public function activateAuthenticationToken($activationToken = null)
    {
        if (!empty($activationToken)) {
            update_option(iHomefinderConstants::ACTIVATION_TOKEN_OPTION, $activationToken);
        }
        $authenticationInfo = $this->getAuthenticationInfo();
        if ($authenticationInfo !== null) {
            if (property_exists($authenticationInfo, "authenticationToken")) {
                $authenticationToken = (string) $authenticationInfo->authenticationToken;
                $this->setAuthenticationToken($authenticationToken);
            }
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
        }
    }
    
    public function deleteAuthenticationToken()
    {
        delete_option(iHomefinderConstants::AUTHENTICATION_TOKEN_OPTION);
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
    
    private function getAuthenticationInfo()
    {
        $activationToken = $this->getActivationToken();
        if (empty($activationToken)) {
            return null;
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
        $blogCredentials = $this->provisionBlogCredentials();

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

        if ($blogCredentials !== null) {
            $remoteRequest
                ->addParameter("wpAppUsername", $blogCredentials["username"])
                ->addParameter("wpAppPassword", $blogCredentials["password"])
                ->addParameter("wpRestBase", $blogCredentials["restBase"]);
        }

        $remoteResponse = $remoteRequest->remotePostRequest();
        $response = $remoteResponse->getResponse();

        // A populated authenticationToken is only rendered by the registration view, which
        // runs after the credential has been committed on the iHomefinder side. Anything
        // else - an error status, an unreachable host, a rolled back transaction - leaves
        // the previous credential in place and the new one unconfirmed.
        if ($blogCredentials !== null && $this->hasAuthenticationToken($response)) {
            $this->confirmBlogCredentials($blogCredentials["uuid"]);
        }

        return $response;
    }
    
    public function provisionBlogIntegration()
    {
        $authenticationToken = get_option(iHomefinderConstants::AUTHENTICATION_TOKEN_OPTION);
        if (empty($authenticationToken)) {
            error_log(sprintf('[Optima Express] provisionBlogIntegration skipped for blog %d — site not yet activated', get_current_blog_id()));
            return;
        }
        $blogCredentials = $this->provisionBlogCredentials();
        if ($blogCredentials === null) {
            error_log(sprintf('[Optima Express] provisionBlogIntegration skipped for blog %d — provisionBlogCredentials returned null', get_current_blog_id()));
            return;
        }
        $activationToken = get_option(iHomefinderConstants::ACTIVATION_TOKEN_OPTION);
        $scheme = is_ssl() ? 'https' : 'http';
        $url = $scheme . '://' . iHomefinderConstants::EXTERNAL_URL;
        $response = wp_remote_post($url, array(
            'timeout' => 200,
            'body'    => array(
                'requestType'    => 'provision-blog-integration',
                'activationToken' => $activationToken,
                'wpAppUsername'  => $blogCredentials['username'],
                'wpAppPassword'  => $blogCredentials['password'],
                'wpRestBase'     => $blogCredentials['restBase'],
            ),
        ));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Leave both credentials alone: the previously confirmed one still works, and
            // the unconfirmed one is cleaned up by the next provisioning attempt.
            $message = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_message($response);
            throw new Exception(sprintf('[Optima Express] provision-blog-integration failed for blog %d: %s', get_current_blog_id(), $message));
        }
        $this->confirmBlogCredentials($blogCredentials['uuid']);
    }

    private function provisionBlogCredentials()
    {
        if (!class_exists("WP_Application_Passwords")) {
            error_log(sprintf('[Optima Express] provisionBlogCredentials: WP_Application_Passwords not available (WordPress < 5.6?) for blog %d', get_current_blog_id()));
            return null;
        }

        $user = get_user_by("login", "optima-express");
        if (!$user) {
            $userId = wp_create_user(
                "optima-express",
                wp_generate_password(24, true, true),
                "optima-express@noreply.ihomefinder.com"
            );
            if (is_wp_error($userId)) {
                error_log(sprintf('[Optima Express] provisionBlogCredentials: wp_create_user failed for blog %d: %s', get_current_blog_id(), $userId->get_error_message()));
                return null;
            }
            $user = get_user_by("ID", $userId);
        }

        if (is_multisite() && !is_user_member_of_blog($user->ID)) {
            $result = add_user_to_blog(get_current_blog_id(), $user->ID, "author");
            if (is_wp_error($result)) {
                error_log(sprintf('[Optima Express] provisionBlogCredentials: add_user_to_blog failed for blog %d: %s', get_current_blog_id(), $result->get_error_message()));
                return null;
            }
        } else {
            $user->set_role("author");
        }

        // Discard credentials left behind by earlier attempts that were never confirmed.
        // The confirmed one is kept: it is the only credential iHomefinder can still post
        // with, and its plaintext is unrecoverable, so it must outlive its replacement
        // until that replacement has itself been confirmed.
        //
        // Before the first confirmation there is no record of which password iHomefinder
        // holds, so nothing is revoked here. Sites provisioned by an earlier version reach
        // this branch once, and their live password must survive until the next
        // confirmation replaces it.
        $confirmedUuid = $this->getConfirmedBlogAppPasswordUuid();
        if ($confirmedUuid !== null) {
            $this->revokeBlogAppPasswords($user->ID, $confirmedUuid);
        }

        $result = WP_Application_Passwords::create_new_application_password(
            $user->ID,
            array("name" => $this->getBlogAppPasswordName())
        );
        if (is_wp_error($result)) {
            error_log(sprintf('[Optima Express] provisionBlogCredentials: create_new_application_password failed for blog %d: %s', get_current_blog_id(), $result->get_error_message()));
            return null;
        }

        return array(
            "username" => "optima-express",
            "password" => $result[0],
            "uuid"     => $result[1]["uuid"],
            "restBase" => get_rest_url(null, "optima-express/v1/blog-post/"),
        );
    }

    /**
     * Records the credential iHomefinder confirmed it stored, then revokes every other
     * blog application password. Called only once the round trip has succeeded, so the
     * credential being replaced stays usable for the whole of the exchange.
     */
    private function confirmBlogCredentials($uuid)
    {
        update_option(iHomefinderConstants::BLOG_APP_PASSWORD_UUID_OPTION, $uuid);
        $user = get_user_by("login", "optima-express");
        if ($user) {
            $this->revokeBlogAppPasswords($user->ID, $uuid);
        }
    }

    private function getConfirmedBlogAppPasswordUuid()
    {
        return get_option(iHomefinderConstants::BLOG_APP_PASSWORD_UUID_OPTION, null);
    }

    /**
     * Per-site password name on multisite, so refreshing one site does not invalidate others.
     */
    private function getBlogAppPasswordName()
    {
        return is_multisite() ? "ihomefinder-blog-" . get_current_blog_id() : "ihomefinder-blog";
    }

    /**
     * Revokes this site's blog application passwords, except the one named by $keepUuid.
     * Passing a uuid that is not present revokes all of them.
     */
    private function revokeBlogAppPasswords($userId, $keepUuid)
    {
        $appPasswordName = $this->getBlogAppPasswordName();
        $existing = WP_Application_Passwords::get_user_application_passwords($userId);
        foreach ($existing as $appPassword) {
            if ($appPassword["name"] === $appPasswordName && $appPassword["uuid"] !== $keepUuid) {
                WP_Application_Passwords::delete_application_password($userId, $appPassword["uuid"]);
            }
        }
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
