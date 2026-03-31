<?php

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


class iHomefinderShortcodeSelector
{
    private $displayRules;
    private $formData;
    private $buttonText;

    public function __construct()
    {
        $this->displayRules = iHomefinderDisplayRules::getInstance();
        $this->formData = iHomefinderFormData::getInstance();
    }

    public function getButtonText()
    {
        return $this->buttonText;
    }

    public function setButtonText($buttonText)
    {
        $this->buttonText = $buttonText;
    }

    public function getNotification()
    {
        return $this->notification;
    }

    public function setNotification($notification)
    {
        $this->notification = $notification;
    }

    public function getHeadContent()
    {
        ?>
        <link type="text/css" rel="stylesheet" href="<?php echo esc_url(
            plugins_url("css/bootstrap.css", __FILE__)
        ); ?>" />
        <script type="text/javascript" src="<?php echo esc_url(
            plugins_url("js/bootstrap.js", __FILE__)
        ); ?>"></script>
        <script type="text/javascript" src="<?php echo esc_url(
            plugins_url("js/iHomefinderShortcodeSelector.js", __FILE__)
        ); ?>"></script>
        <script type="text/javascript">
            jQuery(document).on("submit", "form", function(event) {
                event.preventDefault();
                var $form = jQuery(this);
                var action = $form.find("input[name='action']").val();
                iHomefinderShortcodeSelector[action](this);
            });
        </script>
        <style type="text/css">
            .menu {
                display: none;
            }
        </style>
        <?php
    }

    public function getShortcodeSelectorContent()
    {
        ?>
        <div>
            <ul class="nav nav-tabs" id="ihf-dialog-tabs">
                <li class="nav-item">
                    <a class="nav-link" href="#Leads" data-bs-toggle="tab">Leads</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="#Listings" data-bs-toggle="tab">Listings</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#Search" data-bs-toggle="tab">Search</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#IdxPages" data-bs-toggle="tab">IDX Pages</a>
                </li>
        <?php if ($this->displayRules->isAgentBioEnabled() ||
            $this->displayRules->isOfficeEnabled()
        ) { ?>
                <li class="nav-item">
                    <a class="nav-link" href="#Broker" data-bs-toggle="tab">Broker</a>
                </li>
        <?php } ?>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade" id="Leads">
                    <h4></h4>
                    <div class="col-5">
                        <div class="form-group">
                            <?php if ($this->displayRules->isEmailSignupShortcodeEnabled()) { ?>
                                <div class="form-check">
                                    <label class="control-label">
                                        <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#hotSheetReportSignup').toggle();">
                                        Alert Signup
                                    </label>
                                </div>
                            <?php } ?>
                            <div class="form-check">
                                <label class="control-label">
                                    <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#valuationWidget').toggle();">
                                    Sell My House
                                </label>
                            </div>
                            <?php if ($this->displayRules->isOrganizerEnabled()) { ?>
                                <div class="form-check">
                                    <label class="control-label">
                                        <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#organizerLoginWidget').toggle();">
                                        Property Organizer Widget
                                    </label>
                                </div>
                            <?php } ?>
                            <?php if ($this->displayRules->isOrganizerEnabled()) { ?>
                                <div class="form-check">
                                    <label class="control-label">
                                        <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#registrationFormWidget').toggle();">
                                        Registration Form
                                    </label>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                    <div class="col-7">
                        <div id="hotSheetReportSignup" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertHotSheetReportSignup" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::HOT_SHEET_REPORT_SIGNUP_SHORTCODE
                                ); ?>" />
                                <div class="form-group">
                                    <label class="control-label">Market</label>
                                    <div>
                                        <?php $this->createHotSheetsSelect(
                                            true
                                        ); ?>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Report Type</label>
                                    <div>
                                        <?php $this->createHotSheetReportTypeSelect(
                                            true
                                        ); ?>
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="valuationWidget" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertValuationWidget" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::VALUATION_WIDGET_SHORTCODE
                                ); ?>" />
                                <div class="form-group">
                                    <label class="control-label">Style</label>
                                    <div>
                                        <select class="form-control" name="style" required="required">
                                            <option value="">Select One</option>
                                            <option value="twoline">Two Line</option>
                                            <option value="vertical">Vertical</option>
                                        </select>
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="organizerLoginWidget" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertOrganizerLoginWidget" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::ORGANIZER_LOGIN_WIGET_SHORTCODE
                                ); ?>" />
                                <div class="form-group">
                                    <label class="control-label">Style</label>
                                    <div>
                                        <select class="form-control" name="style" required="required">
                                            <option value="">Select One</option>
                                            <option value="vertical">Vertical</option>
                                            <option value="horizontal">Horizontal</option>
                                        </select>
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="registrationFormWidget" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertRegistrationWidget" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::REGISTRATION_FORM_SHORTCODE
                                ); ?>" />
                                <div class="form-group">
                                    <label class="control-label">Redirect URL</label>
                                    <div>
                                        <input class="form-control" name="url" type="url" placeholder="e.g., http://www.mydestination.com/content">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Button Text</label>
                                    <div>
                                        <input class="form-control" name="buttonText" type="text" placeholder="e.g., View Now!">
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade show active" id="Listings">
                    <h4></h4>
                    <div class="col-4">
                        <div class="form-group">
                            <div class="form-check">
                                <label class="control-label">
                                    <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.listingGalleryMenu').show(); jQuery('.menu').hide();">
                                    Listing Gallery
                                </label>
                            </div>
                            <div class="form-group listingGalleryMenu" style="display: none;">
                                <select class="form-control" name="header" onchange="jQuery('.menu').hide(); jQuery('#' + this.value).toggle();">
                                    <option value="">Select One</option>
                                    <option value="featuredMenu">Featured Listings</option>
                                    <?php if ($this->displayRules->isAgentBioEnabled()
                                    ) { ?>
                                        <option value="agentMenu">Agent Listing</option>
                                    <?php } ?>
                                    <?php if ($this->displayRules->isOfficeEnabled()
                                    ) { ?>
                                        <option value="officeMenu">Office Listing</option>
                                    <?php } ?>
                                    <?php if ($this->displayRules->isHotSheetEnabled()
                                    ) { ?>
                                        <option value="listingReportMenu">Listing Report</option>
                                    <?php } ?>
                                    <?php if ($this->displayRules->isHotSheetOpenHomeReportEnabled()
                                    ) { ?>
                                        <option value="openHomeReportMenu">Open Houses Report</option>
                                    <?php } ?>
                                    <?php if ($this->displayRules->isNamedSearchEnabled()
                                    ) { ?>
                                        <option value="searchMenu">Search</option>
                                    <?php } ?>
                                </select>
                            </div>

                            <?php if ($this->displayRules->supportsGallerySlider()
                            ) { ?> 
                                <div class="form-check">
                                    <label class="control-label">
                                        <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.listingGalleryMenu').hide(); jQuery('.menu').hide(); jQuery('#gallerySliderMenu').toggle();">
                                        Gallery Slider
                                    </label>
                                </div>
                            <?php } ?>
                <?php if ($this->displayRules->isHotSheetMarketReportEnabled()) { ?>
                                <div class="form-check">
                                    <label class="control-label">
                                        <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.listingGalleryMenu').hide(); jQuery('.menu').hide(); jQuery('#marketReportMenu').toggle();">
                                        Market Report
                                    </label>                                
                                </div>
                <?php } ?>
                        </div>
                    </div>
                    <div class="col-8">
                        <div id="featuredMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertFeaturedListings" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::FEATURED_SHORTCODE
                                ); ?>" />
                                <div class="mb-3">
                                    <label class="form-label">Property Type</label>
                                    <div>
                                        <?php $this->createPropertyTypeSelect(
                                            false
                                        ); ?>
                                    </div>
                                </div>
                                <?php if ($this->displayRules->isSoldListingsInWidgets()
                                ) { ?>
                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <div>
                                            <?php $this->createStatusSelect(
                                                true
                                            ); ?>
                                        </div>
                                    </div>
                                <?php } ?>
                                <div class="mb-3">
                                    <label class="form-label">Sort</label>
                                    <div>
                                        <?php $this->createSortSelect(); ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Results Per Page</label>
                                    <div>
                                        <input class="form-control" type="number" min="1" name="resultsPerPage" />
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Display Type</label>
                                    <div>
                                        <?php $this->createDisplayTypeSelect(); ?>
                                    </div>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input radio-input" value="true" name="includeMap" checked="checked" />
                                    <label class="form-check-label">Include Map</label>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Display Header</label>
                                    <div>
                                        <?php $this->createHeaderSelect(
                                            true
                                        ); ?>
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="listingReportMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertHotSheetListingReport" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::HOT_SHEETS_SHORTCODE
                                ); ?>" />
                                <div class="mb-3">
                                    <label class="form-label">Market</label>
                                    <div>
                                        <?php $this->createHotSheetsSelect(
                                            true
                                        ); ?>
                                    </div>
                                </div>
                                <?php if ($this->displayRules->isSoldListingsInWidgets()
                                ) { ?>
                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <div>
                                            <?php $this->createStatusSelect(
                                                true
                                            ); ?>
                                        </div>
                                    </div>
                                <?php } ?>
                                <div class="mb-3">
                                    <label class="form-label">Sort</label>
                                    <div>
                                        <?php $this->createSortSelect(); ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Results Per Page</label>
                                    <div>
                                        <input class="form-control" type="number" min="1" name="resultsPerPage" />
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Display Type</label>
                                    <div>
                                        <?php $this->createDisplayTypeSelect(); ?>
                                    </div>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input radio-input" value="true" name="includeMap" checked="checked" />
                                    <label class="form-check-label">Include Map</label>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Display Header</label>
                                    <div>
                                        <?php $this->createHeaderSelect(
                                            true
                                        ); ?>
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="openHomeReportMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertHotSheetOpenHomeReport" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::HOT_SHEET_OPEN_HOME_REPORT
                                ); ?>" />
                                <div class="mb-3">
                                    <label class="form-label">Market</label>
                                    <div>
                                        <?php $this->createHotSheetsSelect(
                                            true
                                        ); ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Sort</label>
                                    <div>
                                        <?php $this->createSortSelect(); ?>
                                    </div>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input radio-input" value="true" name="includeMap" checked="checked" />
                                    <label class="form-check-label">Include Map</label>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Display Header</label>
                                    <div>
                                        <?php $this->createHeaderSelect(
                                            true
                                        ); ?>
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="marketReportMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertHotSheetMarketReport" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::HOT_SHEET_MARKET_REPORT
                                ); ?>" />
                                <div class="mb-3">
                                    <label class="form-label">Market</label>
                                    <div>
                                        <?php $this->createHotSheetsSelect(
                                            true
                                        ); ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Display Header</label>
                                    <div>
                                        <?php $this->createHeaderSelect(
                                            true
                                        ); ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label"> Slider Columns</label>
                                    <div>
                                        <input class="form-control" type="number" min="1" name="columns" required="required" />
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>                       
                        <div id="searchMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertSearchResults" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::SEARCH_RESULTS_SHORTCODE
                                ); ?>" />
                                <?php if (!$this->displayRules->isKestrelAll()
                                ) { ?>
                                    <div class="form-group">
                                        <label class="control-label">Cities</label>
                                        <div>
                                                                            <?php $this->createCitySelect(true); ?>
                                        </div>
                                    </div>
                                <?php } ?>
                                <div class="form-group">
                                    <label class="control-label">Property Type</label>
                                    <div>
                                        <?php $this->createPropertyTypeSelect(
                                            true
                                        ); ?>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Bed</label>
                                    <div>
                                        <input class="form-control" type="number" min="0" name="bed" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Bath</label>
                                    <div>
                                        <input class="form-control" type="number" min="0" name="bath" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Min Price</label>
                                    <div>
                                        <input class="form-control" type="number" min="0" name="minPrice" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Max Price</label>
                                    <div>
                                        <input class="form-control" type="number" min="0" name="maxPrice" />
                                    </div>
                                </div>
                                <?php if ($this->displayRules->isSoldListingsInWidgets()
                                ) { ?>
                                    <div class="form-group">
                                        <label class="control-label">Status</label>
                                        <div>
                                    <?php $this->createStatusSelect(true); ?>
                                        </div>
                                    </div>
                                <?php } ?>
                                <div class="form-group">
                                    <label class="control-label">Sort</label>
                                    <div>
                                        <?php $this->createSortSelect(); ?>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Results Per Page</label>
                                    <div>
                                        <input class="form-control" type="number" min="1" name="resultsPerPage" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Display Type</label>
                                    <div>
                                        <?php $this->createDisplayTypeSelect(); ?>
                                    </div>
                                </div>
                                <div class="checkbox">
                                    <label class="control-label">
                                        <input type="checkbox" value="true" name="includeMap" checked="checked" />
                                        Include Map
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Display Header</label>
                                    <div>
                                        <?php $this->createHeaderSelect(
                                            true
                                        ); ?>
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="agentMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertAgentListings" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::AGENT_LISTINGS_SHORTCODE
                                ); ?>" />
                                <div class="form-group">
                                    <label class="control-label">Agent</label>
                                    <div>
                                        <?php $this->createAgentSelect(true); ?>
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="officeMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertOfficeListings" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::OFFICE_LISTINGS_SHORTCODE
                                ); ?>" />
                                <div class="form-group">
                                    <label class="control-label">Office</label>
                                    <div>
                                        <?php $this->createOfficeSelect(
                                            true
                                        ); ?>
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="gallerySliderMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertGallerySlider" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::GALLERY_SLIDER_SHORTCODE
                                ); ?>" />
                                <div class="form-group">
                                    <?php if ($this->displayRules->isHotSheetEnabled()
                                    ) { ?>
                                        <label class="radio-inline">
                                            <input type="radio" name="type" checked onclick="jQuery('#HotSheetsSelect').hide(); jQuery('select#hotSheetId').prop('selectedIndex', 0); jQuery('select#hotSheetId').removeAttr('required');" />
                                            Featured
                                        </label>
                                        <label class="radio-inline">
                                            <input type="radio" name="type" onclick="jQuery('#HotSheetsSelect').show(); jQuery('select#hotSheetId').attr('required', 'required');" />
                                            Market
                                        </label>
                                    <?php } ?>
                                </div>
                                <div id="HotSheetsSelect" class="form-group" style="display: none;">
                                    <?php $this->createHotSheetsSelect(); ?>
                                </div>

                                <?php if (!$this->displayRules->isKestrelAll()
                                ) { ?>
                                <div class="form-group">
                                    <label class="control-label">
                                        <input style="margin: 0;" type="checkbox" name="fitToWidth" checked onchange="var $input = jQuery('#listingGalleryWidth').toggle().find('input'); $input.prop('required', !$input.prop('required')); ">
                                        Fit width to column
                                    </label>
                                    <div id="listingGalleryWidth" class="input-group" >
                                        <input class="form-control" type="number" min="1" name="width" required="required" />
                                        <span class="input-group-addon">px</span>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="control-label">Height</label>
                                    <div class="input-group">
                                        <input class="form-control" type="number" min="1" name="height" placeholder="Default" />
                                        <span class="input-group-addon">px</span>
                                    </div>
                                </div>
                                <?php } ?>

                                <div class="form-group">
                                    <label class="control-label">Rows</label>
                                    <div>
                                        <input class="form-control" type="number" min="1" name="rows" required="required" />
                                    </div>
                                </div>
                                <?php if (!$this->displayRules->isKestrelAll()
                                ) { ?>
                                <div class="form-group">
                                    <label class="control-label">Columns</label>
                                    <div>
                                        <input class="form-control" type="number" min="1" name="columns" required="required" />
                                    </div>
                                </div>
                                <?php } ?>


                                <div class="form-group">
                                <label class="control-label">Navigation Position</label>
                                    <div>
                                        <select class="form-control" name="nav" required="required">
                                            <option value="top">Top</option>
                                            <option value="bottom">Bottom</option>
                                            <option value="sides">Sides</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Style</label>
                                    <div>
                                        <select class="form-control" name="style" required="required">
                                            <option value="grid">Color</option>
                                            <option value="plain">Plain</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Effect</label>
                                    <div>
                                        <select class="form-control" name="effect" required="required">
                                            <option value="slide">Slide</option>
                                            <option value="fade">Fade</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Auto Advance</label>
                                    <div>
                                        <select class="form-control" name="auto" required="required">
                                            <option value="true">Yes</option>
                                            <option value="false">No</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Speed</label>
                                    <div class="input-group">
                                        <input class="form-control" type="number" min="1" name="interval" />
                                        <span class="input-group-addon">seconds</span>
                                    </div>
                                </div>
                                <?php if ($this->displayRules->isSoldListingsInWidgets()
                                ) { ?>
                                    <div class="form-group">
                                        <label class="control-label">Status</label>
                                        <div>
                                    <?php $this->createStatusSelect(true); ?>
                                        </div>
                                    </div>
                                <?php } ?>
                                <div class="form-group">
                                    <label class="control-label">Sort</label>
                                    <div>
                                        <?php $this->createSortSelect(); ?>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label">Max. Results</label>
                                    <div>
                                        <input class="form-control" type="number" min="1" name="maxResults" value="25" />
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="Search">
                    <h4></h4>
                    <div class="col-4">
                        <div class="mb-3">
                            <div class="form-check">
                                <label class="form-check-label">
                                    <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#quickSearchMenu').toggle();">
                                    Quick Search
                                </label>
                            </div>
                    <?php if ($this->displayRules->isMapSearchEnabled() &&
                        !$this->displayRules->isEurekaSearch()
                    ) { ?>
                        <div class="form-check">
                            <label class="form-check-label">
                                <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#mapSearchMenu').toggle();">
                                Map Search
                            </label>
                        </div>
                    <?php } ?>
                        <?php if ($this->displayRules->isEurekaSearch()) { ?>
                            <div class="form-check">
                                <label class="form-check-label">
                                    <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#eurekaSearchMenu').toggle();">
                                    Search
                                </label>
                            </div>
                        <?php } ?>
                    <?php if ($this->displayRules->isSearchByAddressEnabled() &&
                        !$this->displayRules->isEurekaSearch()
                    ) { ?>
                        <div class="form-check">
                            <label class="form-check-label">
                                <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#searchByAddressMenu').toggle();">
                                Address Search
                            </label>
                        </div>
                    <?php } ?>
                    <?php if ($this->displayRules->isSearchByListingIdEnabled() &&
                        !$this->displayRules->isEurekaSearch()
                    ) { ?>
                        <div class="form-check">
                            <label class="form-check-label">
                                <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#searchByListingIdMenu').toggle();">
                                Listing ID Search
                            </label>
                        </div>
                    <?php } ?>
                        </div>
                    </div>
                    <div class="col-8">
                        <div id="quickSearchMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertQuickSearch" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::QUICK_SEARCH_SHORTCODE
                                ); ?>" />
                                <div class="mb-3">
                                    <label class="form-label">Style</label>
                                    <div>
                                        <select class="form-control" name="style" required="required" onchange="this.value && this.value !== 'universal' ? jQuery('#quickSearchMenu .propertyType').show() : jQuery('#quickSearchMenu .propertyType').hide()">
                                            <option value="">Select One</option>
                                            <?php if ($this->displayRules->supportsUniversalQuickSearchLayout()
                                            ) { ?>
                                                <option value="universal">Universal</option>
                                            <?php } ?>
                                            <option value="horizontal">Horizontal</option>
                                            <option value="twoline">Two Line</option>
                                            <option value="vertical">Vertical</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3 propertyType" style="display: none;">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input radio-input" name="showPropertyType" value="true" checked />
                                        <label class="form-check-label">
                                            Show Property Type
                                        </label>
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="mapSearchMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertMapSearch" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::MAP_SEARCH_SHORTCODE
                                ); ?>" />

                                <div class="mb-3">
                                    <label class="form-label">Width</label>
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input radio-input" name="fitToWidth" checked onchange="jQuery('#mapSearchWidth').toggle();">
                                        <label class="form-check-label">Fit to column</label>
                                    </div>
                                    <div class="input-group" style="display: none;" id="mapSearchWidth">
                                        <input class="form-control" type="text" name="width" />
                                        <span class="input-group-text">px</span>
                                    </div>
                                </div>
                                <?php if (!$this->displayRules->isKestrelAll()) { ?>
                                <div class="mb-3">
                                    <label class="form-label">Height</label>
                                    <div class="input-group">
                                        <input class="form-control" type="number" min="1" name="height" />
                                        <span class="input-group-text">px</span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Center Address</label>
                                    <div>
                                        <input class="form-control" type="text" name="address" placeholder="e.g., 1900 Addison Street, Berkeley, CA" />
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Zoom Level</label>
                                    <div>
                                        <select class="form-control" name="zoom" required="required">
                                            <?php for ($i = 1; $i <= 20; $i++) { ?>
                                                <option value="<?php echo esc_attr($i); ?>"<?php echo ($i === 10) ? ' selected="selected"' : ''; ?>><?php echo esc_html($i); ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>
                                <?php } ?>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="eurekaSearchMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertMapSearch" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::EUREKA_SEARCH_SHORTCODE
                                ); ?>" />
                                <?php if (!$this->displayRules->isKestrelAll()) { ?>
                                <div class="mb-3">
                                    <label class="form-label">Height (optional)</label>
                                    <div class="input-group">
                                        <input class="form-control" type="number" min="1" name="height" />
                                        <span class="input-group-text">px</span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Center Address</label>
                                    <div>
                                        <input class="form-control" type="text" name="address" placeholder="e.g., 1900 Addison Street, Berkeley, CA" />
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Zoom Level</label>
                                    <div>
                                        <select class="form-control" name="zoom" required="required">
                                            <?php for ($i = 1; $i <= 20; $i++) { ?>
                                                <option value="<?php echo esc_attr($i); ?>"<?php echo ($i === 10) ? ' selected="selected"' : ''; ?>><?php echo esc_html($i); ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>
                                <?php } ?>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="searchByAddressMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertSearchByAddress" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::SEARCH_BY_ADDRESS_SHORTCODE
                                ); ?>" />
                                <div class="mb-3">
                                    <label class="form-label">Style</label>
                                    <div>
                                        <select class="form-control" name="style" required="required">
                                            <option value="">Select One</option>
                                            <option value="horizontal">Horizontal</option>
                                            <option value="vertical">Vertical</option>
                                        </select>
                                    </div>
                                </div>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="searchByListingIdMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertSearchByListingId" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::SEARCH_BY_LISTING_ID_SHORTCODE
                                ); ?>" />
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="IdxPages">
                    <h4></h4>
                    <div class="col-4">
                        <div class="mb-3">
                    <?php if ($this->displayRules->isBasicSearchEnabled() &&
                        !$this->displayRules->isEurekaSearch()
                    ) { ?>
                        <div class="form-check">
                            <label class="form-check-label">
                                <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#basicSearchMenu').toggle();">
                                Search Form
                            </label>
                        </div>
                    <?php } ?>
                    <?php if ($this->displayRules->isAdvancedSearchEnabled() &&
                        !$this->displayRules->isEurekaSearch()
                    ) { ?>
                        <div class="form-check">
                            <label class="form-check-label">
                                <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#advancedSearchMenu').toggle();">
                                Advanced Search Form
                            </label>
                        </div>
                    <?php } ?>
                    <?php if ($this->displayRules->isOrganizerEnabled()) { ?>
                        <div class="form-check">
                            <label class="form-check-label">
                                <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#organizerLoginMenu').toggle();">
                                Property Organizer Login
                            </label>
                        </div>
                    <?php } ?>
                    <?php if ($this->displayRules->isEmailUpdatesEnabled() &&
                        !$this->displayRules->isEurekaSearch()
                    ) { ?>
                        <div class="form-check">
                            <label class="form-check-label">
                                <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#emailAlertsMenu').toggle();">
                                Email Alerts
                            </label>
                        </div>
                    <?php } ?>
                    <?php if ($this->displayRules->isValuationEnabled()) { ?>
                        <div class="form-check">
                            <label class="form-check-label">
                                <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#valuationFormMenu').toggle();">
                                Valuation Form
                            </label>
                        </div>
                    <?php } ?>
                    <?php if ($this->displayRules->isContactFormEnabled()) { ?>
                        <div class="form-check">
                            <label class="form-check-label">
                                <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#contactFormMenu').toggle();">
                                Contact Form
                            </label>
                        </div>
                    <?php } ?>
                    <?php if ($this->displayRules->isMortgageCalculatorEnabled()) { ?>
                        <div class="form-check">
                            <label class="form-check-label">
                                <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#mortgageCalculatorMenu').toggle();">
                                Mortgage Calculator
                            </label>
                        </div>
                    <?php } ?>
                        </div>
                    </div>
                    
                    <div class="col-8">
                        <div id="basicSearchMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertBasicSearch" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::BASIC_SEARCH_SHORTCODE
                                ); ?>" />
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="advancedSearchMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertAdvancedSearch" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::ADVANCED_SEARCH_SHORTCODE
                                ); ?>" />
                                    <?php if ($this->getBoardCount() > 1) { ?>
                                        <div class="mb-3">
                                            <label class="form-label">Board</label>
                                            <div>
                                                <?php $this->createBoardSelect(
                                                    false
                                                ); ?>
                                            </div>
                                        </div>
                                    <?php } elseif ($this->getBoardCount() === 1
                                    ) { ?>
                                        <?php $this->createBoardSelect(
                                            false
                                        ); ?>
                                    <?php } ?>
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="organizerLoginMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertOrganizerLogin" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::ORGANIZER_LOGIN_SHORTCODE
                                ); ?>" />
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="emailAlertsMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertEmailAlerts" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::EMAIL_ALERTS_SHORTCODE
                                ); ?>" />
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="valuationFormMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertValuationForm" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::VALUATION_FORM_SHORTCODE
                                ); ?>" />
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="mortgageCalculatorMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertMortgageCalculator" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::MORTGAGE_CALCULATOR_SHORTCODE
                                ); ?>" />
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                        <div id="contactFormMenu" class="menu">
                            <form>
                                <input type="hidden" name="action" value="insertContactForm" />
                                <input type="hidden" name="slug" value="<?php echo esc_html(
                                    iHomefinderShortcodeDispatcher::CONTACT_FORM_SHORTCODE
                                ); ?>" />
                                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                                    $this->buttonText
                                ); ?></button>
                            </form>
                        </div>
                    </div>
                </div>                
<div class="tab-pane fade" id="Broker">
    <h4></h4>
    <div class="col-5">
        <div class="mb-3">
            <?php if ($this->displayRules->isAgentBioEnabled()) { ?>
                <div class="form-check">
                    <label class="form-check-label">
                        <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#agentDetailMenu').toggle();">
                        Agent Bio
                    </label>
                </div>
            <?php } ?>
            <?php if ($this->displayRules->isAgentBioEnabled()) { ?>
                <div class="form-check">
                    <label class="form-check-label">
                        <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#agentListMenu').toggle();">
                        Agent List
                    </label>
                </div>
            <?php } ?>
            <?php if ($this->displayRules->isOfficeEnabled()) { ?>
                <div class="form-check">
                    <label class="form-check-label">
                        <input name="shortcodeType" type="radio" class="form-check-input radio-input" onclick="jQuery('.menu').hide(); jQuery('#officeListMenu').toggle();">
                        Office List
                    </label>
                </div>
            <?php } ?>
        </div>
    </div>
    <div class="col-7">
        <div id="agentDetailMenu" class="menu">
            <form>
                <input type="hidden" name="action" value="insertAgentDetail" />
                <input type="hidden" name="slug" value="<?php echo esc_html(
                    iHomefinderShortcodeDispatcher::AGENT_DETAIL_SHORTCODE
                ); ?>" />
                <div class="mb-3">
                    <label class="form-label">Agent</label>
                    <div>
                        <?php $this->createAgentSelect(true); ?>
                    </div>
                </div>
                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                    $this->buttonText
                ); ?></button>
            </form>
        </div>
        <div id="agentListMenu" class="menu">
            <form>
                <input type="hidden" name="action" value="insertAgentList" />
                <input type="hidden" name="slug" value="<?php echo esc_html(
                    iHomefinderShortcodeDispatcher::AGENT_LIST_SHORTCODE
                ); ?>" />
                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                    $this->buttonText
                ); ?></button>
            </form>
        </div>
        <div id="officeListMenu" class="menu">
            <form>
                <input type="hidden" name="action" value="insertOfficeList" />
                <input type="hidden" name="slug" value="<?php echo esc_html(
                    iHomefinderShortcodeDispatcher::OFFICE_LIST_SHORTCODE
                ); ?>" />
                <button class="btn btn-primary clipboard-button"><?php echo esc_html(
                    $this->buttonText
                ); ?></button>
            </form>
        </div>
    </div>
</div>

        <?php
    }

    private function createAgentSelect($required = false)
    {
        $values = $this->formData->getAgents(); ?>
        <select class="form-select" id="agentId" name="agentId"
        <?php if ($required === true) { ?>
                required="required"
        <?php } ?>
        >
            <option value="">Select One</option>
        <?php foreach ($values as $index => $value) { ?>
                <option value="<?php echo esc_attr($value->agentId); ?>">
            <?php echo esc_html($value->agentName); ?>
                </option>
        <?php } ?>
        </select>
        <?php
    }

    private function createBoardSelect($required = false)
    {
        $values = $this->formData->getBoards();
        if ($this->getBoardCount() === 1) {
            $value = $values[0]; ?>
            <input id="boardId" name="boardId" value="<?php echo esc_html(
                $value->boardId
            ); ?>" type="hidden" />
            <?php
        } elseif ($this->getBoardCount() > 1) { ?>
            <select class="form-select" id="boardId" name="boardId"
            <?php if ($required === true) { ?>
                    required="required"
            <?php } ?>
            >
                <option value="">Select One</option>
            <?php foreach ($values as $index => $value) { ?>
                    <option value="<?php echo esc_html($value->boardId); ?>">
                <?php echo esc_html($value->boardName); ?>
                    </option>
            <?php } ?>
            </select>
        <?php }
    }
    private function getBoardCount()
    {
        $values = $this->formData->getBoards();
        $result = count($values);
        return $result;
    }
    private function createOfficeSelect($required = false)
    {
        $values = $this->formData->getOffices(); ?>
        <select class="form-select" id="officeId" name="officeId"
        <?php if ($required === true) { ?>
                required="required"
        <?php } ?>
        >
            <option value="">Select One</option>
        <?php foreach ($values as $index => $value) { ?>
                <option value="<?php echo esc_html($value->officeId); ?>">
            <?php echo esc_html($value->officeName); ?>
                </option>
        <?php } ?>
        </select>
        <?php
    }

    private function createHotSheetsSelect($required = false)
    {
        $values = $this->formData->getHotSheets(); ?>
        <select class="form-select" id="hotSheetId" name="hotSheetId"
        <?php if ($required === true) { ?>
                required="required"
        <?php } ?>
        >
            <option value="">Select One</option>
        <?php foreach ($values as $index => $value) { ?>
                <option value="<?php echo esc_html($value->hotsheetId); ?>">
            <?php echo esc_html($value->displayName); ?>
                </option>
        <?php } ?>
        </select>
        <?php
    }

    private function createCitySelect($required = false)
    {
        $values = $this->formData->getCities(); ?>
        <select class="form-select" id="cityId" name="cityId"
        <?php if ($required === true) { ?>
                required="required"
        <?php } ?>
        >
            <option value="">Select One</option>
        <?php foreach ($values as $index => $value) { ?>
                <option value="<?php echo esc_html($value->cityId); ?>">
            <?php echo esc_html($value->displayName); ?>
                </option>
        <?php } ?>
        </select>
        <?php
    }

    private function createPropertyTypeSelect($required = false)
    {
        $values = $this->formData->getPropertyTypes(); ?>
        <select class="form-select" id="propertyType" name="propertyType"
        <?php if ($required === true) { ?>
                required="required"
        <?php } ?>
        >
            <option value="">Select One</option>
        <?php foreach ($values as $index => $value) { ?>
                <option value="<?php echo esc_html(
                    $value->propertyTypeCode
                ); ?>">
            <?php echo esc_html($value->displayName); ?>
                </option>
        <?php } ?>
        </select>
        <?php
    }

    private function createSortSelect($required = false)
    {
        ?>
        <select class="form-select" id="sortBy" name="sortBy"
        <?php if ($required === true) { ?>
                required="required"
        <?php } ?>
        >    
            <option value="">Select One</option>
            <option value="pd">Price (High to Low)</option>
            <option value="pa">Price (Low to High)</option>
            <option value="st">Status</option>
            <option value="cn">City</option>
            <option value="ds">Listing Date</option>
            <option value="lpd">Type / Price Descending</option>
            <option value="ln">Listing Number</option>
        </select>
        <?php
    }

    private function createDisplayTypeSelect($required = false)
    {
        ?>
        <select class="form-select" id="displayType" name="displayType"
        <?php if ($required === true) { ?>
                required="required"
        <?php } ?>
        >
            <option value="">Default</option>
            <option value="list">List</option>
            <option value="grid">Grid</option>
        </select>
        <?php
    }

    private function createHeaderSelect($required = false)
    {
        ?>
        <select class="form-select" id="header" name="header"
        <?php if ($required === true) { ?>
                required="required"
        <?php } ?>
        >
            <option value="true">Yes</option>
            <option value="false">No</option>
        </select>
        <?php
    }

    private function createHotSheetReportTypeSelect($required = false)
    {
        ?>
        <select class="form-select" id="hotSheetReportType" name="hotSheetReportType"
        <?php if ($required === true) { ?>
                required="required"
        <?php } ?>
        >
            <option value="">Select One</option>
            <option value="listing">Listing Report</option>
            <option value="openHome">Open Houses Report</option>
            <option value="market">Market Report</option>
        </select>
        <?php
    }

    private function createStatusSelect($required = false)
    {
        ?>
        <select class="form-select" id="status" name="status"
        <?php if ($required === true) { ?>
                required="required"
        <?php } ?>
        >
            <option value="active">Active</option>
            <option value="sold">Sold</option>
        </select>
        <?php
    }
}
