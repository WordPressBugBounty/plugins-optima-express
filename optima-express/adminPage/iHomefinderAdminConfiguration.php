<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class iHomefinderAdminConfiguration extends iHomefinderAdminAbstractPage
{
    
    private static $instance;
    private $displayRules;
    
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        parent::__construct();
        $this->displayRules = iHomefinderDisplayRules::getInstance();
    }
    
    public function registerSettings()
    {
        register_setting(
            iHomefinderConstants::OPTION_GROUP_CONFIGURATION,
            iHomefinderConstants::CSS_OVERRIDE_OPTION,
            array($this, 'sanitize_settings')
        );
        register_setting(
            iHomefinderConstants::OPTION_GROUP_CONFIGURATION,
            iHomefinderConstants::SHADOW_DOM_HTML_OPTION,
            array($this, 'sanitize_settings')
        );
        register_setting(
            iHomefinderConstants::OPTION_GROUP_CONFIGURATION,
            iHomefinderConstants::SHADOW_DOM_CSS_OPTION,
            array($this, 'sanitize_settings')
        );
        register_setting(
            iHomefinderConstants::OPTION_GROUP_CONFIGURATION,
            iHomefinderConstants::NO_ID_OPTION,
            array($this, 'sanitize_settings')
        );
    }

    public function sanitize_settings($input)
    {
        $option = isset($_REQUEST["option"]) ? $_REQUEST["option"] : "";

        switch ($option) {
            case iHomefinderConstants::CSS_OVERRIDE_OPTION:
            case iHomefinderConstants::SHADOW_DOM_CSS_OPTION:
                return wp_strip_all_tags($input);
            case iHomefinderConstants::SHADOW_DOM_HTML_OPTION:
                return wp_kses_post($input);
            case iHomefinderConstants::NO_ID_OPTION:
                return sanitize_text_field($input);
            default:
                return $input;
        }
    }
    
    protected function getContent()
    {
        $cssOverride = get_option(iHomefinderConstants::CSS_OVERRIDE_OPTION, null);
        $shadowDomHtml = get_option(iHomefinderConstants::SHADOW_DOM_HTML_OPTION, null);
        $shadowDomCss = get_option(iHomefinderConstants::SHADOW_DOM_CSS_OPTION, null);
        if (empty($cssOverride)) {
            $cssOverride = "<style type=\"text/css\">\n\n</style>";
        }
        ?>
        <h2>Configuration</h2>
        <form method="post" action="options.php">
        <?php settings_fields(iHomefinderConstants::OPTION_GROUP_CONFIGURATION); ?>
            <table class="form-table">
        <?php if ($this->displayRules->isKestrel()) { ?>
                    <tr>
                        <th>
                            <label for="<?php echo esc_attr(iHomefinderConstants::SHADOW_DOM_CSS_OPTION); ?>">Add CSS to IDX Stylesheet</label>
                        </th>
                        <td>
                            <p>(v10 only) Add CSS overrides to the shadow DOM</p>
                            <textarea id="<?php echo esc_attr(iHomefinderConstants::SHADOW_DOM_CSS_OPTION); ?>" name="<?php echo esc_attr(iHomefinderConstants::SHADOW_DOM_CSS_OPTION); ?>" style="width: 100%; height: 200px; "><?php echo $shadowDomCss; ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <label for="<?php echo esc_attr(iHomefinderConstants::SHADOW_DOM_HTML_OPTION); ?>">Add Code to IDX Content</label>
                        </th>
                        <td>
                            <p>(v10 only) Add scripts and other HTML to the shadow DOM</p>
                            <textarea id="<?php echo esc_attr(iHomefinderConstants::SHADOW_DOM_HTML_OPTION); ?>" name="<?php echo esc_attr(iHomefinderConstants::SHADOW_DOM_HTML_OPTION); ?>" style="width: 100%; height: 200px; "><?php echo $shadowDomHtml; ?></textarea>
                        </td>
                    </tr>
        <?php } ?>
                <tr>
                    <th>
                        <label for="<?php echo esc_attr(iHomefinderConstants::CSS_OVERRIDE_OPTION); ?>">Add Code to Page Head</label>
                    </th>
                    <td>
                        <p>Add CSS overrides, scripts, and other HTML to the head of the page</p>
                        <textarea id="<?php echo esc_attr(iHomefinderConstants::CSS_OVERRIDE_OPTION); ?>" name="<?php echo esc_attr(iHomefinderConstants::CSS_OVERRIDE_OPTION); ?>" style="width: 100%; height: 200px; "><?php echo $cssOverride; ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th>Advanced</th>
                    <td>
                        <label for="<?php echo esc_attr(iHomefinderConstants::NO_ID_OPTION); ?>">Remove duplicate CSS IDs for widgets and shortcodes for improved accessibility</label>
                        <input
                            type="checkbox" 
        <?php if (get_option(iHomefinderConstants::NO_ID_OPTION, null) === "true") { ?>
                                checked="checked"
        <?php } ?>
                            value="true"
                            name="<?php echo esc_attr(iHomefinderConstants::NO_ID_OPTION); ?>" id="<?php echo esc_attr(iHomefinderConstants::NO_ID_OPTION); ?>
                        ">
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button-primary">Save Changes</button>
            </p>
        </form>
        <?php
    }
}
