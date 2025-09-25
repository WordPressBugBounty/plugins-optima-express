<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


class iHomefinderMlsPortalBoardMemberDetailVirtualPageImpl extends iHomefinderAbstractVirtualPage
{
    
    public function getTitle()
    {
        $default = null;
        if (is_object($this->remoteResponse) && $this->remoteResponse->hasTitle()) {
            $default = $this->remoteResponse->getTitle();
        }
        return $this->getText(iHomefinderConstants::OPTION_VIRTUAL_PAGE_TITLE_MLS_PORTAL_BOARD_MEMBER_DETAIL, $default);
    }

    public function getPageTemplate()
    {
        return get_option(iHomefinderConstants::OPTION_VIRTUAL_PAGE_TEMPLATE_MLS_PORTAL_BOARD_MEMBER_DETAIL, null);
    }
    
    public function getPermalink()
    {
        return $this->getText(iHomefinderConstants::OPTION_VIRTUAL_PAGE_PERMALINK_TEXT_MLS_PORTAL_BOARD_MEMBER_DETAIL, "mls-portal-agent");
    }
    
    public function getAvailableVariables()
    {
        $variableUtility = iHomefinderVariableUtility::getInstance();
        return array(
        $variableUtility->getAgentName(),
        $variableUtility->getAgentDesignation(),
        $variableUtility->getAgentPhoto(),
        $variableUtility->getAgentEmail(),
        $variableUtility->getAgentCellPhone(),
        $variableUtility->getAgentOfficePhone(),
        );
    }

    public function getMetaTags()
    {
        $default = "<meta property=\"og:image\" content=\"{agentPhoto}\" />
        \n<meta property=\"og:title\" content=\"{agentName}, {agentDesignation}\" />
        \n<meta name=\"description\" content=\"Contact - Mobile: {agentCellPhone} | Office: {agentOfficePhone} | Email: {agentEmail}\" />\n";
        return $this->getText(iHomefinderConstants::OPTION_VIRTUAL_PAGE_META_TAGS_MLS_PORTAL_BOARD_MEMBER_DETAIL, $default);
    }
    
    public function getContent()
    {
        iHomefinderStateManager::getInstance()->setLastSearchUrl();
        $this->remoteRequest
            ->addParameter("requestType", "mls-portal-agent-detail")
            ->addParameter("includeSearchSummary", false);
        $boardMemberId = iHomefinderUtility::getInstance()->getQueryVar("boardMemberId");
        if (is_numeric($boardMemberId)) {
            $this->remoteRequest->addParameter("boardMemberId", $boardMemberId);
        }
        $this->remoteRequest->setCacheExpiration(60*60);
        $this->remoteResponse = $this->remoteRequest->remoteGetRequest();
    }
}
