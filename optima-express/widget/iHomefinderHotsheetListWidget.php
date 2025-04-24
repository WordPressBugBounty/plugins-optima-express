<?php

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


class iHomefinderHotsheetListWidget extends iHomefinderWidget
{
    
    private $displayRules;
    private $enqueueResource;
    
    public function __construct()
    {
        parent::__construct(
            "iHomefinderHotsheetListWidget",
            "IDX: Listing Report Index",
            array(
            "description" => "Index of Listing Reports."
            )
        );
        $this->displayRules = iHomefinderDisplayRules::getInstance();
        $this->enqueueResource = iHomefinderEnqueueResource::getInstance();
    }
    
    public function widget($args, $instance)
    {
        $allowed_html = wp_kses_allowed_html("post");
        $instance = $this->migrate($instance);
        if ($this->isEnabled($instance)) {
            $includeAll = filter_var($instance["includeAll"], FILTER_VALIDATE_BOOLEAN);
            $beforeWidget = $args["before_widget"];
            $afterWidget = $args["after_widget"];
            $beforeTitle = $args["before_title"];
            $afterTitle = $args["after_title"];
            $title = apply_filters("widget_title", $instance["title"]);
            $customText = null;
            if (array_key_exists("customText", $instance)) {
                $customText = $instance["customText"];
            }
            $hotSheetIds = array();
            if (array_key_exists("hotsheetIds", $instance) && is_array($instance["hotsheetIds"])) {
                foreach ($instance["hotsheetIds"] as $index => $hotSheetId) {
                    $hotSheetIds[] = $hotSheetId;
                }
            }
            if ($this->displayRules->isKestrelAll()) {
                $content = iHomefinderKestrelWidget::getMarketsWidget($includeAll, $hotSheetIds);
            } else {
                $remoteRequest = new iHomefinderRequestor();
                $remoteRequest
                    ->addParameter("requestType", "hotsheet-list")
                    ->addParameter("smallView", true);
                if ($includeAll === false && array_key_exists("hotsheetIds", $instance) && is_array($instance["hotsheetIds"])) {
                    $remoteRequest->addParameter("hotsheetIds", $hotSheetIds);
                }
                if ($this->displayRules->isNoId() === true) {
                    $remoteRequest->addParameter("noId", true);
                }
                $remoteRequest->setCacheExpiration(60*60);
                $remoteResponse = $remoteRequest->remoteGetRequest();
                $content = $remoteResponse->getBody();
                $this->enqueueResource->addToFooter($remoteResponse->getHead());
            }
            echo wp_kses($beforeWidget, $allowed_html);
            if (!empty($title)) {
                echo wp_kses($beforeTitle . $title . $afterTitle, $allowed_html);
            }
            echo wp_kses($customText, $allowed_html);
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
        $instance["hotsheetIds"] = $newInstance["hotsheetIds"];
        $instance["includeAll"] = $newInstance["includeAll"];
        $instance["customText"] = $newInstance["customText"];
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
        $customText = null;
        if (array_key_exists("customText", $instance)) {
            $customText = esc_attr($instance["customText"]);
        }
        $hotSheetIds = null;
        if (array_key_exists("hotsheetIds", $instance)) {
            $hotSheetIds = $instance["hotsheetIds"];
        }
        $includeAll = true;
        if (array_key_exists("includeAll", $instance)) {
            if ($instance["includeAll"] !== null) {
                $includeAll = filter_var($instance["includeAll"], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $formData = iHomefinderFormData::getInstance();
        $clientHotsheets = $formData->getHotSheets();
        ?>
        <p>
            <label>
                Title:
                <input class="widefat" id="<?php echo esc_attr($this->get_field_id("title")); ?>" name="<?php echo esc_attr($this->get_field_name("title")); ?>" type="text" value="<?php echo esc_html($title); ?>" />
            </label>
            <label>
                Custom HTML:
                <textarea rows="12" cols="20" class="widefat" id="<?php echo esc_attr($this->get_field_id("customText")); ?>" name="<?php echo esc_attr($this->get_field_name("customText")); ?>"><?php echo esc_html($customText); ?></textarea>
            </label>
        </p>
        <p>
        <?php
        $includeAllTrueChecked = "";
        $includeAllFalseChecked = "";
        if ($includeAll === true) {
            $includeAllTrueChecked = "checked=\"checked\"";
        } else {
            $includeAllFalseChecked = "checked=\"checked\"";
        }
        ?>
            <label>
                <input type="radio" name="<?php echo esc_attr($this->get_field_name("includeAll")); ?>" value="true" onclick="jQuery(this).closest('form').find('.hotsheetList').hide()" <?php echo esc_html($includeAllTrueChecked) ?> />
                Show all Listing Reports
            </label>
            <br />
            <label>
                <input type="radio" name="<?php echo esc_attr($this->get_field_name("includeAll")); ?>" value="false" onclick="jQuery(this).closest('form').find('.hotsheetList').show()" <?php echo esc_html($includeAllFalseChecked) ?> />
                Show Selected Listing Reports
            </label>
        </p>
        <?php
        $hotSheetListStyle = "";
        if ($includeAll) {
            $hotSheetListStyle = "display: none;";
        }
        ?>
        <p class="hotsheetList" style="<?php echo esc_html($hotSheetListStyle) ?>">
            <label>
                Markets:
                <select class="widefat" name="<?php echo esc_attr($this->get_field_name("hotsheetIds")); ?>[]" multiple="multiple">
        <?php
        foreach ($clientHotsheets as $index => $clientHotsheet) {
            $hotSheetIdSelected = "";
            if (is_array($hotSheetIds) && in_array($clientHotsheet->hotsheetId, $hotSheetIds)) {
                $hotSheetIdSelected = "selected=\"selected\"";
            }
            ?>
                        <option value="<?php echo esc_html($clientHotsheet->hotsheetId) ?>" <?php echo esc_html($hotSheetIdSelected) ?>>
            <?php echo esc_html($clientHotsheet->displayName) ?>
                        </option>
            <?php
        }
        ?>
                </select>
            </label>
        </p>
        <?php
        $this->getPageSelector($instance);
        ?>
        <br />
        <?php
    }
}
