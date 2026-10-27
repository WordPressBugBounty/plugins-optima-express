<?php

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


class iHomefinderRequestor
{
    
    private $parameters = array();
    private $cacheExpiration = 0;
    private $remoteResponse;
    
    private $logger;
    private $displayRules;
    private $utility;
    private $stateManager;
    private $enqueueResource;
    
    public function __construct()
    {
        $this->logger = iHomefinderLogger::getInstance();
        $this->displayRules = iHomefinderDisplayRules::getInstance();
        $this->utility = iHomefinderUtility::getInstance();
        $this->stateManager = iHomefinderStateManager::getInstance();
        $this->enqueueResource = iHomefinderEnqueueResource::getInstance();
    }
    
    public function remoteGetRequest()
    {
        
        if ($this->remoteResponse === null) {
            if ($this->stateManager->isErrorMode()) {
                $responseBodyObject = null;
                $this->remoteResponse = new iHomefinderRemoteResponse();
                $this->remoteResponse->setBody("The server is temporarily unavailable due to maintenance or high demand.");
                $this->enqueueResource->setHttpStatusCode(503);
                $this->enqueueResource->addHttpHeader("Retry-After: " . iHomefinderStateManager::ERROR_MODE_TIMEOUT);
            }
        }
        
        if ($this->remoteResponse === null) {
            if ($this->isSpam()) {
                wp_die("invalid request 807a");
                //$result = "responceBodyGoesHere";
            }
        }
        
        if ($this->remoteResponse === null) {
            $requestUrl = $this->getExternalUrl();
            
            //only add user specific info if the request is not cacheable
            if (!$this->isCacheable()) {
                //add jsession id to the end of the url
                if ($this->stateManager->hasSessionId()) {
                    $sessionId = $this->stateManager->getSessionId();
                    $requestUrl .= ";jsessionid=" . $sessionId;
                }
                    
                //add subscriber id to the reqest parameters
                if ($this->stateManager->hasSubscriberId()) {
                    $subscriberId = $this->stateManager->getSubscriberId();
                    //CF cannot handle multiple parameters with the same case insensitive name
                    $this->removeParameter("subscriberId")->removeParameter("subscriberID");
                    $this->addParameter("subscriberId", $subscriberId);
                }
                    
                //add lead capture id to the reqest parameters
                if ($this->stateManager->hasLeadCaptureUserId()) {
                    $leadCaptureUserId = $this->stateManager->getLeadCaptureUserId();
                    $this->addParameter("leadCaptureId", $leadCaptureUserId);
                }
                    
                //if remember me cookie add it to the reqest parameters
                if ($this->stateManager->hasRememberMe()) {
                    $this->addParameter("rmuser", true);
                }
                    
                //add user agent to the request parameters
                if ($this->stateManager->hasUserAgent()) {
                    $userAgent = $this->stateManager->getUserAgent();
                    $this->addParameter("uagent", $userAgent);
                }

                //add IP of user to the request parameters
                $ipAddress = $this->stateManager->getUserIpAddress();
                $this->addParameter("ipAddress", $ipAddress);
            }
            
            //add authentication token to the reqest parameters
            $authenticationToken = iHomefinderAdmin::getInstance()->getAuthenticationToken();
            $this->addParameter("authenticationToken", $authenticationToken);
            
            $this->addParameter("version", iHomefinderConstants::VERSION);
            
            $this->addParameter("leadCaptureSupport", true);
            $this->addParameter("phpStyle", true);
            
            $requestUrl = $this->utility->buildUrl($requestUrl, $this->getParameters());
            
            $ihfid = iHomefinderUrlFactory::getInstance()->getBaseUrl() . ";" . "WordpressPlugin";
            $ihfUserInfo = "WordPress/" . get_bloginfo("version") . "; " . iHomefinderUrlFactory::getInstance()->getBaseUrl();
            //Modified user-agent in the request header to pass original user-agent. This information is used to determine if request came from mobile devices.
            
            $requestArgs = array(
            "timeout" => "35",
            "ihfid" => $ihfid,
            "ihfUserInfo" => $ihfUserInfo,
            "sslverify" => self::sslVerify($requestUrl),
            );

            if (isset($userAgent)) {
                $requestArgs["user-agent"] = $userAgent;
            }

            $response = null;
            if ($this->isCacheable()) {
                $response = iHomefinderCacheUtility::getInstance()->getItem($requestUrl);
            }
            if ($response === null) {
                $this->logger->debug("ihfUrl: " . self::redactForDebug($requestUrl));
                $this->logger->debug(self::redactForDebug($requestArgs));
                $this->logger->debug("before request");

                $response = wp_remote_get($requestUrl, $requestArgs);

                $this->logger->debug("after request");
                $this->logger->debug(self::redactForDebug($response));
                    
                if (!is_wp_error($response) && $this->isCacheable() && $response["response"]["code"] < 400) {
                    iHomefinderCacheUtility::getInstance()->updateItem($requestUrl, $response, $this->getCacheExpiration());
                }
            }
            
            if (is_wp_error($response)) {
                $this->remoteResponse = new iHomefinderRemoteResponse();
                $this->remoteResponse->setBody("<br />This content is currently unavailable. Please check back later or contact the site's support team for more information.<br />");
                $this->enqueueResource->setHttpStatusCode(500);
            }
            
            if (!is_wp_error($response)) {
                $responseCode = $response["response"]["code"];
                
                if ($this->stateManager->isErrorForTimeout($responseCode)) {
                    $this->stateManager->addErrorTime();
                }
                
                if ($responseCode == 404) {
                    global $wp_query;
                    $wp_query->set_404();
                    status_header(404);
                    nocache_headers();
                }
                
                if ($responseCode >= 500) {
                    $this->remoteResponse = new iHomefinderRemoteResponse();
                    $this->enqueueResource->setHttpStatusCode($responseCode);
                }
                
                if ($responseCode < 500) {
                    $responseBody = wp_remote_retrieve_body($response);
                    $contentType = wp_remote_retrieve_header($response, "content-type");
                    $responseBodyObject = null;
                    if ($contentType == "text/xml;charset=UTF-8") {
                        $responseBodyObject = simplexml_load_string($responseBody, null, LIBXML_NOCDATA);
                    } else {
                        $responseBodyObject = json_decode($responseBody);
                    }
                    $this->remoteResponse = new iHomefinderRemoteResponse();
                    $this->remoteResponse->setResponse($responseBodyObject);
                    $this->enqueueResource->setHttpStatusCode($responseCode);
                }
            }
            
            if (is_object($this->remoteResponse) && !$this->remoteResponse->hasError() && !$this->isCacheable()) {
                if ($this->remoteResponse->hasLeadCaptureUserId()) {
                    $this->stateManager->setLeadCaptureUserId($this->remoteResponse->getLeadCaptureUserId());
                }
                    
                if ($this->remoteResponse->hasSessionId()) {
                    $sessionId = $this->remoteResponse->getSessionId();
                    $this->stateManager->setSessionId($sessionId);
                }
                    
                if ($this->remoteResponse->hasListingInfo()) {
                    $listingInfo = $this->remoteResponse->getListingInfo();
                    $listingNumber = null;
                    $listingAddress = null;
                    $boardId = null;
                    $clientPropertyId = null;
                    $sold = false;
                    if (property_exists($listingInfo, "listingNumber") && property_exists($listingInfo, "boardId")) {
                        $listingNumber = $listingInfo->listingNumber;
                        $boardId = $listingInfo->boardId;
                        if (property_exists($listingInfo, "clientPropertyId")) {
                               $clientPropertyId = $listingInfo->clientPropertyId;
                        }
                        if (property_exists($listingInfo, "listingAddress")) {
                            $listingAddress = $listingInfo->listingAddress;
                        }
                        if (property_exists($listingInfo, "sold")) {
                            $sold = $listingInfo->sold;
                        }
                        $listingInfo = new iHomefinderListingInfo($listingNumber, $boardId, $listingAddress, $clientPropertyId, $sold);
                        $this->stateManager->setListingInfo($listingInfo);
                    }
                }
            
                if ($this->remoteResponse->hasSubscriberId()) {
                    $subscriberId = $this->remoteResponse->getSubscriberId();
                    $this->stateManager->setSubscriberId($subscriberId);
                }
            }
        }
        
        // as a last resort, return an instance of iHomefinderRemoteResponse
        if (!is_object($this->remoteResponse)) {
            $this->remoteResponse = new iHomefinderRemoteResponse();
        }
        
        return $this->remoteResponse;
    }
    
    /**
     * Only used for registration
     *
     * @return iHomefinderRemoteResponse
     */
    public function remotePostRequest()
    {
        
        $requestUrl = $this->getExternalUrl();
        $ihfid = iHomefinderUrlFactory::getInstance()->getBaseUrl() . ";" . "WordpressPlugin";
        $ihfUserInfo = "WordPress/" . get_bloginfo("version") . "; " . iHomefinderUrlFactory::getInstance()->getBaseUrl();
        // Modified user-agent in the request header to pass original user-agent. This information is used to determine if request came from mobile devices.
        
        $userAgent = $_SERVER["HTTP_USER_AGENT"];
        if ($userAgent !== null) {
            $userAgent = urlencode($userAgent);
        }
        
        $this->addParameter("version", iHomefinderConstants::VERSION);
        $this->addParameter("method", "handleRequest");
        $this->addParameter("viewType", "json");
        $this->addParameter("phpStyle", true);
        
        $requestArgs = array(
        "timeout" => "200",
        "body" => $this->getParameters(),
        "ihfid" => $ihfid,
        "ihfUserInfo" => $ihfUserInfo,
        "user-agent" => $userAgent,
        "sslverify" => self::sslVerify($requestUrl),
        );

        $this->logger->debug("ihfUrl: " . self::redactForDebug($requestUrl));
        $this->logger->debug(self::redactForDebug($requestArgs));
        $this->logger->debug("before request");
        $response = wp_remote_post($requestUrl, $requestArgs);
        $this->logger->debug("after request");
        // The registration body carries the minted authenticationToken: never dump it raw.
        $this->logger->debug(self::redactForDebug($response));

        $responseBodyObject = null;
        $httpStatusCode = null;
        $transportError = null;
        if (is_wp_error($response)) {
            $this->logger->debug("remotePostRequest failed: " . $response->get_error_message());
            $transportError = $response;
        } else {
            $responseBody = wp_remote_retrieve_body($response);
            $httpStatusCode = (int) wp_remote_retrieve_response_code($response);
            $this->logger->debug("remotePostRequest status: " . $httpStatusCode);
            if ($response["response"]["code"] >= 400) {
                $responseBodyObject = new stdClass();
                $responseBodyObject->view = $responseBody;
            } else {
                $contentType = wp_remote_retrieve_header($response, "content-type");
                if ($contentType !== null && $contentType == "text/xml;charset=UTF-8") {
                    $responseBodyObject = simplexml_load_string($responseBody, null, LIBXML_NOCDATA);
                    $responseBodyObject = json_decode(json_encode($responseBodyObject));
                } else {
                    $responseBodyObject = json_decode($responseBody);
                }
            }
        }

        // Always hand back a response object. Callers read the parsed body through it, and
        // a transport failure is reported as an absent body rather than as a null return.
        $this->remoteResponse = new iHomefinderRemoteResponse();
        $this->remoteResponse->setResponse($responseBodyObject);
        $this->remoteResponse->setHttpStatusCode($httpStatusCode);
        if ($transportError !== null) {
            $this->remoteResponse->setTransportError($transportError->get_error_code(), $transportError->get_error_message());
        }

        return $this->remoteResponse;
    }

    /**
     * Copy of $value safe to hand to the debug logger: the parameter names that carry a
     * credential (activation key, authentication token, application password) are blanked
     * wherever they appear, whether as an array key, a query-string pair, a JSON member or an
     * XML element. Nested arrays and objects are walked; everything else is returned as is.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function redactForDebug($value)
    {
        $names = array("activationToken", "authenticationToken", "wpAppPassword");
        if (is_string($value)) {
            $alternation = implode("|", $names);
            $patterns = array(
                '/\b(' . $alternation . ')=[^&\s"\'<>]*/i',
                '/("(?:' . $alternation . ')"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i',
                '/(<(' . $alternation . ')(?:\s[^>]*)?>)[^<]*(<\/\2>)/i',
            );
            $replacements = array('$1=[redacted]', '$1"[redacted]"', '$1[redacted]$3');
            return preg_replace($patterns, $replacements, $value);
        }
        if (is_array($value)) {
            $result = array();
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array($key, $names, true)) {
                    $result[$key] = "[redacted]";
                } else {
                    $result[$key] = self::redactForDebug($item);
                }
            }
            return $result;
        }
        if (is_object($value) && !($value instanceof WP_Error)) {
            $result = new stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                if (in_array($key, $names, true)) {
                    $result->$key = "[redacted]";
                } else {
                    $result->$key = self::redactForDebug($item);
                }
            }
            return $result;
        }
        return $value;
    }

    /**
     * Whether the certificate is verified on a request to the service. True by default on every
     * GET and on the activate POST. Two escape hatches for a host whose CA bundle cannot verify
     * the service certificate, so a broken bundle costs a support call and not all IDX content:
     * define('IHF_SSLVERIFY', false) in wp-config.php, or the filter
     * add_filter('ihf_sslverify', function ($verify, $url) { ... }, 10, 2), which runs after the
     * constant so either can be used. Neither is set by default and neither is logged.
     *
     * @param string $url the request URL, passed to the filter and never logged here
     * @return bool
     */
    private static function sslVerify($url)
    {
        $verify = defined('IHF_SSLVERIFY') ? (bool) IHF_SSLVERIFY : true;
        return (bool) apply_filters('ihf_sslverify', $verify, $url);
    }

    /**
     * The one place the plugin's service endpoint is spelled out. Always https: the request
     * carries the activation key, the authentication token and, on the provisioning path, a
     * freshly minted application password, so the scheme is never taken from is_ssl() (an http
     * site used to send all three in the clear). IHF_SERVICE_HOST, when defined in wp-config.php,
     * replaces the host only, and only outside a production environment.
     *
     * @return string
     */
    public static function getServiceUrl()
    {
        $externalUrl = iHomefinderConstants::EXTERNAL_URL;
        $slash = strpos($externalUrl, "/");
        $host = $slash === false ? $externalUrl : substr($externalUrl, 0, $slash);
        $path = $slash === false ? "" : substr($externalUrl, $slash);
        if (defined("IHF_SERVICE_HOST") && is_string(IHF_SERVICE_HOST) && IHF_SERVICE_HOST !== "") {
            $environment = function_exists("wp_get_environment_type") ? wp_get_environment_type() : "production";
            if ($environment !== "production") {
                $host = IHF_SERVICE_HOST;
            }
        }
        return "https://" . $host . $path;
    }
    
    public function hasParameter($name)
    {
        $result = false;
        if (array_key_exists($name, $this->parameters)) {
            $result = true;
        }
        return $result;
    }
    
    public function addParameter($name, $value)
    {
        $this->parameters[$name] = $value;
        return $this;
    }
    
    public function removeParameter($name)
    {
        if ($this->hasParameter($name)) {
            unset($this->parameters[$name]);
        }
        return $this;
    }
    
    public function addParameters($parameters)
    {
        if (is_array($parameters)) {
            foreach ($parameters as $name => $value) {
                $this->addParameter($name, $value);
            }
        }
        return $this;
    }
    
    public function getParameters()
    {
        return $this->parameters;
    }
    
    public function setCacheExpiration($cacheExpiration)
    {
        $this->cacheExpiration = $cacheExpiration;
    }
    
    public function getCacheExpiration()
    {
        return $this->cacheExpiration;
    }
    
    public function isCacheable()
    {
        $result = false;
        if (is_int($this->cacheExpiration) && $this->cacheExpiration > 0) {
            $result = true;
        }
        return $result;
    }
    
    public function getRemoteResponse()
    {
        return $this->remoteResponse;
    }
    
    private function isSpam()
    {
        $spam = false;
        $honeyPotValue = $this->utility->getRequestVar("JKGH00920");
        if (!empty($honeyPotValue)) {
            $spam = true;
        }
        return $spam;
    }
    
    private function getExternalUrl()
    {
        return self::getServiceUrl();
    }
}
