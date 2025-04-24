<?php

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


class iHomefinderContactFormWidget extends iHomefinderWidget
{
    
    private $enqueueResource;
    private $displayRules;
    
    public function __construct()
    {
        parent::__construct(
            "iHomefinderContactFormWidget",
            "IDX: Contact Form",
            array(
            "description" => "Contact form."
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
            $title = apply_filters("widget_title", $instance["title"]);
            if ($this->displayRules->isKestrelAll()) {
                $content = iHomefinderKestrelWidget::getContactFormWidget();
            } else {
                $remoteRequest = new iHomefinderRequestor();
                $remoteRequest
                    ->addParameter("requestType", "FeatureContactForm")
                    ->addParameter("smallView", true);
                if ($this->displayRules->isNoId() === true) {
                    $remoteRequest->addParameter("noId", true);
                }
                $remoteResponse = $remoteRequest->remoteGetRequest();
                $content = $remoteResponse->getBody();
                $this->enqueueResource->addToFooter($remoteResponse->getHead());
            }
            echo wp_kses($beforeWidget, $allowed_html);
            if (!empty($title)) {
                echo wp_kses($beforeTitle . $title . $afterTitle, $allowed_html);
            }
            echo $content; // this content it's a mix of HTML and JS
            echo wp_kses($afterWidget, $allowed_html);
        }
    }
    
    public function update($newInstance, $oldInstance)
    {
        $newInstance = $this->migrate($newInstance);
        $oldInstance = $this->migrate($oldInstance);
        $instance = $oldInstance;
        $instance["title"] = strip_tags(stripslashes($newInstance["title"]));
        $instance = $this->updateContext($newInstance, $instance);
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
                <input class="widefat" id="<?php echo esc_attr($this->get_field_id("title")); ?>" name="<?php echo esc_attr($this->get_field_name("title")); ?>" type="text" value="<?php echo esc_attr($title); ?>" />
            </label>
        </p>
        <?php
        $this->getPageSelector($instance);
        ?>
        <br />
        <?php
    }
    
    protected function isEnabled($instance)
    {
        $result = parent::isEnabled($instance);
        $virtualPageType = get_query_var(iHomefinderConstants::IHF_TYPE_URL_VAR);
        switch ($virtualPageType) {
            case iHomefinderVirtualPageFactory::CONTACT_FORM:
                $result = false;
                break;
        }
        return $result;
    }
}
