<?php

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


class iHomefinderMoreInfoWidget extends iHomefinderWidget
{
    
    private $enqueueResource;
    private $displayRules;
    
    public function __construct()
    {
        parent::__construct(
            "iHomefinderMoreInfoWidget",
            "IDX: More Info",
            array(
            "description" => "Displays a More Information form on listing detail IDX pages."
            )
        );
        $this->enqueueResource = iHomefinderEnqueueResource::getInstance();
        $this->displayRules = iHomefinderDisplayRules::getInstance();
    }
    
    public function widget($args, $instance)
    {
        $allowed_html = wp_kses_allowed_html("post");
        $instance = $this->migrate($instance);
        if ($this->isEnabled($instance)) {
            $beforeWidget = $args["before_widget"];
            $afterWidget = $args["after_widget"];
            $beforeTitle = $args["before_title"];
            $afterTitle = $args["after_title"];
            $listingInfo = iHomefinderStateManager::getInstance()->getListingInfo();
            if ($this->displayRules->isKestrelAll()) {
                $listingId = $listingInfo->getListingId();
                $content = iHomefinderKestrelWidget::getRequestMoreInfoFormWidget($listingId);
            } else {
                $remoteRequest = new iHomefinderRequestor();
                $remoteRequest
                    ->addParameter("requestType", "request-more-info-widget")
                    ->addParameter("smallView", true)
                    ->addParameter("boardId", $listingInfo->getBoardId())
                    ->addParameter("listingNumber", $listingInfo->getListingNumber())
                    ->addParameter("listingAddress", $listingInfo->getAddress())
                    ->addParameter("clientPropertyId", $listingInfo->getClientPropertyId())
                    ->addParameter("sold", $listingInfo->getSold());
                if ($this->displayRules->isNoId() === true) {
                    $remoteRequest->addParameter("noId", true);
                }
                $remoteResponse = $remoteRequest->remoteGetRequest();
                $content = $remoteResponse->getBody();
                $this->enqueueResource->addToFooter($remoteResponse->getHead());
            }
            $title = apply_filters("widget_title", $instance["title"]);
            echo wp_kses($beforeWidget, $allowed_html);
            if (!empty($title)) {
                echo wp_kses($beforeTitle . $title . $afterTitle, $allowed_html);
            }
            echo $content; // this content it's a mix of HTML and JS
            echo wp_kses($afterWidget, $allowed_html);
        }
    }
    
    protected function isEnabled($instance)
    {
        $result = false;
        $virtualPageType = get_query_var(iHomefinderConstants::IHF_TYPE_URL_VAR);
        if (iHomefinderStateManager::getInstance()->hasListingInfo()) {
            switch ($virtualPageType) {
                case iHomefinderVirtualPageFactory::LISTING_DETAIL:
                case iHomefinderVirtualPageFactory::LISTING_SOLD_DETAIL:
                    $result = true;
            }
        }
        return $result;
    }
    
    public function update($newInstance, $oldInstance)
    {
        $newInstance = $this->migrate($newInstance);
        $oldInstance = $this->migrate($oldInstance);
        $instance = $oldInstance;
        $instance["title"] = strip_tags(stripslashes($newInstance["title"]));
        return $instance;
    }
    
    public function form($instance)
    {
        $instance = $this->migrate($instance);
        $title = null;
        if (array_key_exists("title", $instance)) {
            $title = esc_attr($instance["title"]);
        }
        ?>
        <p>
            <label>
                Title:
                <input class="widefat" id="<?php echo esc_attr($this->get_field_id("title")); ?>" name="<?php echo esc_attr($this->get_field_name("title")); ?>" type="text" value="<?php echo esc_html($title); ?>" />
            </label>
        </p>
        <?php
    }
}
