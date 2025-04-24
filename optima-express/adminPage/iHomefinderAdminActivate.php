<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class iHomefinderAdminActivate extends iHomefinderAdminAbstractPage
{
    
    const IHOMEFINDER_NOTIFICATION = "By registering this plugin, you consent to allow downloads of IDX listings that include images, attribution of iHomefinder as the IDX provider, and other MLS-specified compliance requirements.";
    
    private static $instance;
    
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function registerSettings()
    {
        register_setting(
        iHomefinderConstants::OPTION_GROUP_ACTIVATE,
        iHomefinderConstants::ACTIVATION_TOKEN_OPTION,
        array($this, "sanitize_settings")
        );

        register_setting(
        iHomefinderConstants::OPTION_GROUP_ACTIVATE,
        iHomefinderConstants::AUTHENTICATION_TOKEN_OPTION,
        array($this, "sanitize_settings")
        );
    }

    public function sanitize_settings($input)
    {
        $option = isset($_REQUEST["option"]) ? $_REQUEST["option"] : "";

        switch ($option) {
            case iHomefinderConstants::ACTIVATION_TOKEN_OPTION:
            case iHomefinderConstants::AUTHENTICATION_TOKEN_OPTION:
                return sanitize_text_field($input);

            default:
                return $input;
        }
    }
    
    protected function getContent()
    {
        $section = iHomefinderUtility::getInstance()->getRequestVar("section");
        //if the activationToken is passed in the url, we manually update the option
        $activationToken = iHomefinderUtility::getInstance()->getRequestVar("reg");
        if (!empty($activationToken)) {
            $this->admin->activateAuthenticationToken($activationToken);
            ?>
            <h2>Thanks For Signing Up</h2>
            <div class="updated">
                <p>Your Optima Express plugin has been registered.</p>
            </div>
            <p>You will receive an email from us with IDX paperwork for your MLS. Please complete the paperwork and return it to iHomefinder promptly. Listings from your MLS will appear in Optima Express as soon as your MLS approves your IDX paperwork.</p>
        <?php } elseif ($section === "enter-reg-key") { ?>
            <?php $activationToken = get_option(iHomefinderConstants::ACTIVATION_TOKEN_OPTION, null); ?>
            <h2>Add Registration Key</h2>
            <?php if (empty($activationToken)) { ?>
                <div class="error">
                    <p>Add your Registration Key and click "Save Changes" to get started with Optima Express.</p>
                </div>
            <?php } elseif ($this->admin->isActivated()) { ?>
                <div class="updated">
                    <p>Your Optima Express plugin has been registered.</p>
                </div>
            <?php } else { ?>
                <div class="error">
                    <p>Incorrect Registration Key.</p>
                </div>
            <?php } ?>
            <form method="post" action="options.php">
            <?php settings_fields(iHomefinderConstants::OPTION_GROUP_ACTIVATE); ?>
                <table class="form-table">
                    <tr>
                        <th>
                            <label for="<?php echo esc_attr(iHomefinderConstants::ACTIVATION_TOKEN_OPTION); ?>">Registration Key</label>
                        </th>
                        <td>
                            <input id="<?php echo esc_attr(iHomefinderConstants::ACTIVATION_TOKEN_OPTION); ?>" class="regular-text" type="text" name="<?php echo esc_attr(iHomefinderConstants::ACTIVATION_TOKEN_OPTION); ?>" value="<?php echo esc_attr(get_option(iHomefinderConstants::ACTIVATION_TOKEN_OPTION, null)); ?>" required="required" />
                        </td>
                    </tr>
                </table>
                <p>
            <?php echo esc_html(self::IHOMEFINDER_NOTIFICATION); ?>
                </p>
                <p class="submit">
                    <button type="submit" class="button-primary">Save Changes</button>
                </p>
            </form>
        <?php } else { ?>
            <?php if (!$this->admin->isActivated()) { ?>
                <h2>Register Optima Express</h2>
                <p>
                    <a href="<?php echo esc_url(admin_url("admin.php?page=" . iHomefinderConstants::PAGE_ACTIVATE . "&section=enter-reg-key")); ?>">I already have a registration key</a>
                </p>
                <p>
                    <a href="https://www.ihomefinder.com/pricing/agent-plugin-pricing/" class="button button-primary ihf-button-large">Sign Up for an<br /> Account</a>
                </p>
                <p><b>Optima Express from iHomefinder</b> adds MLS/IDX search and listings directly into your WordPress site.</p>
                <p>Signing up for an account provides access to listings in your MLS® System and full support from iHomefinder. You must be a member of an MLS to qualify for IDX service <a target="_blank" href="http://www.ihomefinder.com/mls-coverage/">Learn More</a></p>
                <p>
                <?php echo esc_html(self::IHOMEFINDER_NOTIFICATION); ?>
                </p>
            <?php } else { ?>
                <h2>Unregister Optima Express</h2>
                <p>Optima Express is currently registered. Clicking the below button will unregister the IDX plugin.<p>
                <form method="post" action="options.php">
                <?php settings_fields(iHomefinderConstants::OPTION_GROUP_ACTIVATE); ?>
                    <input type="hidden" name="<?php echo esc_attr(iHomefinderConstants::ACTIVATION_TOKEN_OPTION) ?>" value="" />
                    <input type="hidden" name="<?php echo esc_attr(iHomefinderConstants::AUTHENTICATION_TOKEN_OPTION) ?>" value="" />
                    <p class="submit">
                        <button type="submit" class="button-primary" onclick="return confirm('Are you sure you want to unregister Optima Express?');">Unregister</button>
                    </p>
                </form>
                <form method="post" action="options.php" name="refreshRegistration">
                <?php settings_fields(iHomefinderConstants::OPTION_GROUP_ACTIVATE); ?>
                    <input type="hidden" name="<?php echo esc_attr(iHomefinderConstants::ACTIVATION_TOKEN_OPTION); ?>" value="<?php echo esc_html(get_option(iHomefinderConstants::ACTIVATION_TOKEN_OPTION, null)); ?>" />
                    <a href="#" onclick="document.refreshRegistration.submit();">Refresh Registration</a>
                </form>
            <?php } ?>
            <?php
        }
    }
}
