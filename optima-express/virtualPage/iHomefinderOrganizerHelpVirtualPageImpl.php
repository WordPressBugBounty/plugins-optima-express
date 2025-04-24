<?php


if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class iHomefinderOrganizerHelpVirtualPageImpl extends iHomefinderAbstractPropertyOrganizerVirtualPage
{
    
    public function getTitle()
    {
        return "Organizer Help";
    }
    
    public function getPermalink()
    {
        return "property-organizer-help";
    }
    
    public function getContent()
    {
        $this->remoteRequest
            ->addParameter("requestType", "property-organizer-help");
        $this->remoteResponse = $this->remoteRequest->remoteGetRequest();
    }

    public function getBody()
    {
        if ($this->displayRules->isKestrelAll()) {
            return iHomefinderKestrelPage::getPropertyOrganizerPage();
        } else {
            return parent::getBody();
        }
    }
}
