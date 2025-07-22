<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

abstract class iHomefinderAbstractVirtualPage implements iHomefinderVirtualPageInterface
{
    
    protected $remoteResponse;
    protected $remoteRequest;
    protected $displayRules;
    private $stateManager;
    
    public function __construct()
    {
        $this->remoteRequest = new iHomefinderRequestor();
        $this->displayRules = iHomefinderDisplayRules::getInstance();
        $this->stateManager = iHomefinderStateManager::getInstance();
    }
    
    public function getPageTemplate()
    {
        return get_option(iHomefinderConstants::OPTION_VIRTUAL_PAGE_TEMPLATE_DEFAULT, null);
    }
    
    public function getPermalink()
    {
        return null;
    }
    
    public function getHead()
    {
        $result = null;
        if (is_object($this->remoteResponse)) {
            $result = $this->remoteResponse->getHead();
        }
        return $result;
    }
    
    public function getFooterContent()
    {
        $result = null;
        if (is_object($this->remoteResponse)) {
            if ($this->remoteResponse->hasFooterContent()) {
                $result = $this->remoteResponse->getFooterContent();
            }
        }
        return $result ;
    }
    
    public function getTitle()
    {
        return null;
    }
    
    public function getMetaTags()
    {
        return null;
    }
    
    public function getAvailableVariables()
    {
        return null;
    }
    
    public function getVariables()
    {
        $result = array();
        //if only one node exists, this is returning one object instead an array of objects
        if (is_object($this->remoteResponse) && $this->remoteResponse->hasVariables()) {
            $variables = json_decode(json_encode($this->remoteResponse->getVariables()));
            if (is_object($variables) && property_exists($variables, "variable")) {
                foreach ($variables->variable as $variable) {
                    if (property_exists($variable, "name") && property_exists($variable, "value")) {
                        $result[] = new iHomefinderVariable($variable->name, $variable->value, null);
                    }
                }
            }
        }
        return $result;
    }
    
    public function getContent()
    {
        return null;
    }
    
    public function getBody()
    {
        $result = null;
        if (is_object($this->remoteResponse)) {
            $result = $this->remoteResponse->getBody();
        }
        return $result;
    }
    
    public function addParameter($name, $value)
    {
        $this->remoteRequest->addParameter($name, $value);
    }
    
    /**
     *
     * @param  string $optionName the name of the option
     * @param  string $default    the default value if the option value cannot be found or is empty
     * @return string variables replaced
     */
    protected function getText($optionName, $default = null)
    {
        $result = get_option($optionName, null);
        // If the option value is '__NONE__', the user has explicitly chosen to suppress meta tags or text output.
        // This prevents fallback/default content from being shown when the user wants a truly blank value.
        if ( $result === '__NONE__' ) {
            return '';
        }
        if (empty($result)) {
            $result = $default;
        }
        $result = iHomefinderVariableUtility::getInstance()->replaceVariable($result, $this->getVariables());
        return $result;
    }
    
    /**
     * Used in active and sold detail pages
     *
     * @return string
     */
    protected function getPreviousSearchLink()
    {
        $previousUrl = $this->stateManager->getLastSearchUrl();
        $text = null;
        if (empty($previousUrl)) {
            $previousUrl = iHomefinderUrlFactory::getInstance()->getListingsSearchFormUrl(true);
            $text = "New Search";
        } elseif (strpos($previousUrl, "map-search") !== false) {
            $text = "Return To Map Search";
        } else {
            $text = "Return To Results";
        }
        $result = null;
        if (!empty($text) and !empty($previousUrl)) {
            $result = "<a href=\"" . $previousUrl . "\">&lt;&nbsp;" . $text . "</a>";
        }
        return $result;
    }
}
