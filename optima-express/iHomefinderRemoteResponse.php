<?php

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


class iHomefinderRemoteResponse
{
    
    private $response;
    private $body;
    private $httpStatusCode;
    private $transportErrorCode;
    private $transportErrorMessage;

    public function getResponse()
    {
        return $this->response;
    }

    public function setResponse($response)
    {
        $this->response = $response;
    }

    /**
     * HTTP status of the remote call, or null when the transport failed (WP_Error) or the
     * response was never sent. Lets a caller tell a rejection (401/403) from an outage.
     *
     * @return int|null
     */
    public function getHttpStatusCode()
    {
        return $this->httpStatusCode;
    }

    public function setHttpStatusCode($httpStatusCode)
    {
        $this->httpStatusCode = $httpStatusCode === null ? null : (int) $httpStatusCode;
    }

    /**
     * Records the WP_Error a failed transport produced (code and message), so a caller can
     * classify the failure without ever seeing the URL or the request body.
     *
     * @param string $code
     * @param string $message
     * @return void
     */
    public function setTransportError($code, $message)
    {
        $this->transportErrorCode = is_scalar($code) ? (string) $code : '';
        $this->transportErrorMessage = is_scalar($message) ? (string) $message : '';
    }

    public function hasTransportError()
    {
        return $this->transportErrorCode !== null;
    }

    /**
     * A fixed-vocabulary description of why the call did not succeed, safe to show an
     * administrator: "HTTP <code>" for a status of 400 or more, "cURL error <n>" for a cURL
     * transport failure (the errno only; curl's text can name the host), "transport error" for
     * any other WP_Error, "unexpected response" for a success status whose body lacked what the
     * caller needed, and "no response" when nothing was sent. Never a URL, a key or a token.
     *
     * @return string
     */
    public function getFailureDetail()
    {
        if ($this->transportErrorCode !== null) {
            if (preg_match('/cURL error (\d+)/i', (string) $this->transportErrorMessage, $matches)) {
                return 'cURL error ' . $matches[1];
            }
            return 'transport error';
        }
        if ($this->httpStatusCode === null) {
            return 'no response';
        }
        if ($this->httpStatusCode >= 400) {
            return 'HTTP ' . $this->httpStatusCode;
        }
        return 'unexpected response';
    }

    public function setBody($body)
    {
        $this->body = $body;
    }
    
    public function getBody()
    {
        $content = null;
        if ($this->body !== null) {
            $content = $this->body;
        } elseif (is_null($this->response)) {
            //We could reach this code, if the iHomefinder services are down.
            $content = "<br />This content is currently unavailable. Please check back later or contact the site's support team for more information.<br />";
        } elseif (property_exists($this->response, "error")) {
            //Report the error from iHomefinder
            $content = "<br />" . $this->response->error . "<br />";
        } elseif (property_exists($this->response, "view")) {
            //success, display the view
            // Output trusted remote HTML/JS content from iHomefinder service
            $content = html_entity_decode($this->response->view, null, "UTF-8");
        }
        return $content;
    }
    
    public function getCss()
    {
        $result = $this->getProperty("css");
        $result = $this->convertItemValues($result);
        return $result;
    }
    
    public function hasCss()
    {
        return $this->hasProperty("css");
    }
    
    public function getJs()
    {
        $result = $this->getProperty("javascript");
        $result = $this->convertItemValues($result);
        return $result;
    }
    
    public function getFooterContent()
    {
        $result = $this->getProperty("footer-content");
        $result = $this->convertItemValues($result);
        return $result;
    }
    
    public function hasJs()
    {
        return $this->hasProperty("javascript");
    }
    
    public function hasFooterContent()
    {
        return $this->hasProperty("footer-content");
    }
    
    public function getError()
    {
        return $this->getProperty("error");
    }
    
    public function hasError()
    {
        return $this->hasProperty("error");
    }
    
    public function getHead()
    {
        return $this->getProperty("head");
    }
    
    public function hasHead()
    {
        return $this->hasProperty("head");
    }
    
    public function getTitle()
    {
        return $this->getProperty("title");
    }
    
    public function hasTitle()
    {
        return $this->hasProperty("title");
    }
    
    public function getJson()
    {
        return $this->getProperty("json");
    }
    
    public function hasJson()
    {
        return $this->hasProperty("json");
    }
    
    public function getVariables()
    {
        return $this->getProperty("variables");
    }
    
    public function hasVariables()
    {
        return $this->hasProperty("variables");
    }
    
    public function getLeadCaptureUserId()
    {
        $result = (string) $this->getProperty("leadCaptureId");
        return $result;
    }
    
    public function hasLeadCaptureUserId()
    {
        return $this->hasProperty("leadCaptureId");
    }
    
    public function getSessionId()
    {
        $result = (string) $this->getProperty("ihfSessionId");
        return $result;
    }
    
    public function hasSessionId()
    {
        return $this->hasProperty("ihfSessionId");
    }
    
    public function getListingInfo()
    {
        return $this->getProperty("listingInfo");
    }
    
    public function hasListingInfo()
    {
        return $this->hasProperty("listingInfo");
    }
    
    public function getSubscriberId()
    {
        $result = null;
        if ($this->hasSubscriberId()) {
            $result = (string) $this->getProperty("subscriberInfo")->subscriberId;
        }
        return $result;
    }
    
    public function hasSubscriberId()
    {
        $result = false;
        if ($this->hasProperty("subscriberInfo")) {
            $subscriber = $this->getProperty("subscriberInfo");
            if (property_exists($subscriber, "subscriberId")) {
                $result = true;
            }
        }
        return $result;
    }
    
    public function getHotSheets()
    {
        $result = $this->getProperty("hotsheetsList");
        $result = $this->convertItemValues($result);
        return $result;
    }
    
    public function getCities()
    {
        $result = $this->getProperty("citiesList");
        $result = $this->convertItemValues($result);
        return $result;
    }
    
    public function getCityZips()
    {
        $result = $this->getProperty("cityZipList");
        $result = $this->convertItemValues($result);
        return $result;
    }
    
    public function getPropertyTypes()
    {
        $result = $this->getProperty("propertyTypesList");
        $result = $this->convertItemValues($result);
        return $result;
    }
    
    public function getAgents()
    {
        $result = $this->getProperty("agentList");
        $result = $this->convertItemValues($result);
        return $result;
    }
    
    public function getBoards()
    {
        $result = $this->getProperty("boardList");
        $result = $this->convertItemValues($result);
        return $result;
    }
    
    public function getOffices()
    {
        $result = $this->getProperty("officeList");
        $result = $this->convertItemValues($result);
        return $result;
    }
    
    private function getProperty($name)
    {
        $result = null;
        if (is_object($this->response) && property_exists($this->response, $name)) {
            $result = $this->response->{"$name"};
        }
        return $result;
    }
    
    private function hasProperty($name)
    {
        return is_object($this->response) && property_exists($this->response, $name);
    }
    
    /**
     * This function was added to convert our xml based array into a proper
     * php array for use in admin search forms.
     *
     * @param unknown_type $fromValue The value to be converted into an array
     *
     * @return array
     */
    private function convertItemValues($fromValue)
    {
        $result = array();
        if (!empty($fromValue)) {
            foreach ($fromValue->item as $element) {
                $result[] = $element;
            }
        }
        return $result;
    }
}
