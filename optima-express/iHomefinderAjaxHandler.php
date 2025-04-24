<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


/**
 * This class is handle all iHomefinder Ajax Requests.
 * It proxies the requests and returns the proper results.
 *
 * @author ihomefinder
 */
class iHomefinderAjaxHandler {

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

    public function requestMoreInfo()
    {
        $this->basicAjaxSubmit("request-more-info");
    }

    public function contactFormRequest()
    {
        $this->basicAjaxSubmit("contact-form");
    }

    public function scheduleShowing()
    {
        $this->basicAjaxSubmit("schedule-showing");
    }

    public function photoTour()
    {
        $boardId = iHomefinderUtility::getInstance()->getRequestVar("boardID");
        $photoTourArray = array("boardId" => $boardId);
        $this->basicAjaxSubmit("photo-tour", $photoTourArray);
    }

    public function saveProperty()
    {
        $this->basicAjaxSubmit("save-property");
    }

    public function saveSearch()
    {
        $stateManager = iHomefinderStateManager::getInstance();
        $lastSearch = $stateManager->getLastSearch();
        $name = iHomefinderUtility::getInstance()->getRequestVar("name");
        $remoteRequest = new iHomefinderRequestor();
        $remoteRequest
            ->addParameters($_REQUEST)
            ->addParameters($lastSearch)
            ->addParameter("requestType", "save-search")
            ->addParameter("subscriberName", $name)
            ->addParameter("modal", true);
        $remoteResponse = $remoteRequest->remoteGetRequest();
        $content = $remoteResponse->getBody() . $remoteResponse->getHead();

        // Detect presence of script or style tags
        $hasScriptOrStyle = preg_match('/<script\b[^>]*>.*?<\/script>/is', $content) ||
                            preg_match('/<style\b[^>]*>.*?<\/style>/is', $content) ||
                            preg_match('/<link\b[^>]+rel=["\']?stylesheet["\']?/i', $content);
    
        // Hybrid content (HTML + JS/CSS): avoid sanitizing
        if ($hasScriptOrStyle) {
            header('Content-Type: text/html');
            echo $content; // this content it's a mix of HTML and JS
        } else {
            header('Content-Type: text/html');
            echo wp_kses_post($content); // Safe for simple HTML
        }
        wp_die(); //don't remove
    }
    
    public function leadCaptureLogin()
    {
        $this->basicAjaxSubmit("lead-capture-login");
    }
    
    public function addSavedListingComments()
    {
        $this->basicAjaxSubmit("saved-listing-comments");
    }
    
    public function addSavedListingRating()
    {
        $this->basicAjaxSubmit("saved-listing-rating");
    }

    public function saveListingForSubscriberInSession()
    {
        $this->basicAjaxSubmit("save-listing-subscriber-session");
    }
    
    public function saveSearchForSubscriberInSession()
    {
        $this->basicAjaxSubmit("save-search-subscriber-session");
    }
    
    public function sendPassword()
    {
        $this->basicAjaxSubmit("send-password");
    }
    
    public function emailAlertPopup()
    {
        $this->basicAjaxSubmit("email-alert-popup");
    }
    
    public function emailListing()
    {
        $this->basicAjaxSubmit("email-listing");
    }
    
    public function emailBoardMember()
    {
        $boardMemberId = iHomefinderUtility::getInstance()->getRequestVar("boardMemberId");
        $emailArray = array("boardMemberId" => $boardMemberId);
        $this->basicAjaxSubmit("email-board-member", $emailArray);
    }
    
    public function emailBoardOffice()
    {
        $boardOfficeId = iHomefinderUtility::getInstance()->getRequestVar("boardOfficeId");
        $emailArray = array("boardOfficeId" => $boardOfficeId);
        $this->basicAjaxSubmit("email-board-office", $boardOfficeId);
    }
    
    public function emailSignup()
    {
        $this->basicAjaxSubmit("email-signup");
    }
    
    public function clearCache()
    {
        iHomefinderAdmin::getInstance()->activateAuthenticationToken();
        echo true;
        wp_die(); //don't remove
    }
    
    /**
     * @deprecated not implemented
     */
    public function advancedSearchMultiSelects()
    {
        $this->basicAjaxSubmit("advanced-search-multi-select-values");
    }
    
    /**
     * @deprecated
     */
    public function getAdvancedSearchFormFields()
    {
        $this->basicAjaxSubmit("advanced-search-fields");
    }
    
    /**
     * @deprecated
     */
    public function getAutocompleteMatches()
    {
        $remoteRequest = new iHomefinderRequestor();
        $remoteRequest
            ->addParameters($_REQUEST)
            ->addParameter("requestType", "area-autocomplete");
        $remoteResponse = $remoteRequest->remoteGetRequest();
        $content = $remoteResponse->getJson();
        
        $hasScriptOrStyle = preg_match('/<script\b[^>]*>.*?<\/script>/is', $content) ||
                            preg_match('/<style\b[^>]*>.*?<\/style>/is', $content) ||
                            preg_match('/<link\b[^>]+rel=["\']?stylesheet["\']?/i', $content);
    
     
        if ($hasScriptOrStyle) {
            header('Content-Type: text/html');
            echo $content; // this content it's a mix of HTML and JS
        } else {
            header('Content-Type: text/html');
            echo wp_kses_post($content);
        }
        wp_die(); //don't remove
    }
    
    /**
     * @param string $requestType
     * @param array  $parameters
     */
    private function basicAjaxSubmit($requestType, $parameters = array())
    {
        $remoteRequest = new iHomefinderRequestor();
        $remoteRequest
            ->addParameters($_REQUEST)
            ->addParameters($parameters)
            ->addParameter("requestType", $requestType);
        $remoteResponse = $remoteRequest->remoteGetRequest();
        $content = $remoteResponse->getBody() . $remoteResponse->getHead();

   
        $hasScriptOrStyle = preg_match('/<script\b[^>]*>.*?<\/script>/is', $content) ||
                            preg_match('/<style\b[^>]*>.*?<\/style>/is', $content) ||
                            preg_match('/<link\b[^>]+rel=["\']?stylesheet["\']?/i', $content);
    
  
        if ($hasScriptOrStyle) {
            header('Content-Type: text/html');
            echo $content; // this content it's a mix of HTML and JS
        } else {
            header('Content-Type: text/html');
            echo wp_kses_post($content);
        }

        wp_die(); //don't remove
    }
}
