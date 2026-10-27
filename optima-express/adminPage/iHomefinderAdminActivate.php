<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * The Register page. It registers no settings group: the three actions (register a key,
 * refresh the registration, unregister) are nonce-protected admin-post handlers on
 * iHomefinderAdmin, so options.php can never null a token it was not sent, a wrong key is never
 * written before the service has accepted it, and each outcome gets its own message (MAX-4432).
 * A ?reg=<key> link only pre-fills the key form; nothing on this page activates on a GET.
 */
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

    /**
     * This page has no settings form, so a settings-updated query parameter on it is never a
     * save of ours and must not trigger the base class's post-save registration sync.
     *
     * @return bool
     */
    public function isUpdated()
    {
        return false;
    }

    protected function getContent()
    {
        $section = iHomefinderUtility::getInstance()->getRequestVar("section");
        $prefilledKey = iHomefinderUtility::getInstance()->getRequestVar("reg");
        $prefilledKey = is_scalar($prefilledKey) ? sanitize_text_field((string) $prefilledKey) : "";
        $this->showActionResult();
        if ($prefilledKey !== "") {
            // The store's purchase-return link. It pre-fills the form and nothing more; the
            // nonce-protected Register button completes the registration.
            ?>
            <h2>Thanks For Signing Up</h2>
            <p>Your registration key has been filled in below. Click "Register" to finish registering your Optima Express plugin.</p>
            <?php $this->showRegisterKeyForm($prefilledKey); ?>
            <p>You will receive an email from us with IDX paperwork for your MLS. Please complete the paperwork and return it to iHomefinder promptly. Listings from your MLS will appear in Optima Express as soon as your MLS approves your IDX paperwork.</p>
        <?php } elseif ($section === "enter-reg-key") { ?>
            <?php $activationToken = get_option(iHomefinderConstants::ACTIVATION_TOKEN_OPTION, null); ?>
            <h2>Add Registration Key</h2>
            <?php if ($this->admin->isActivated()) { ?>
                <div class="updated">
                    <p>Your Optima Express plugin has been registered.</p>
                </div>
            <?php } elseif (empty($activationToken)) { ?>
                <div class="error">
                    <p>Add your Registration Key and click "Register" to get started with Optima Express.</p>
                </div>
            <?php } ?>
            <?php $this->showRegisterKeyForm(is_scalar($activationToken) ? (string) $activationToken : ""); ?>
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
                <form method="post" action="<?php echo esc_url(admin_url("admin-post.php")); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(iHomefinderAdmin::UNREGISTER_ACTION); ?>" />
                    <?php wp_nonce_field(iHomefinderAdmin::UNREGISTER_ACTION); ?>
                    <p class="submit">
                        <button type="submit" class="button-primary" onclick="return confirm('Are you sure you want to unregister Optima Express?');">Unregister</button>
                    </p>
                </form>
                <h2>Refresh Registration</h2>
                <p>Checks your registration key with the service and updates this site's registration. If the check fails, your current registration is left unchanged.</p>
                <form method="post" action="<?php echo esc_url(admin_url("admin-post.php")); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(iHomefinderAdmin::REFRESH_REGISTRATION_ACTION); ?>" />
                    <?php wp_nonce_field(iHomefinderAdmin::REFRESH_REGISTRATION_ACTION); ?>
                    <p class="submit">
                        <button type="submit" class="button">Refresh Registration</button>
                    </p>
                </form>
                <h2>Blog Connection</h2>
                <p>Optima Express publishes blog posts to this site through its own service user. If blog posting has stopped working, reset the connection to issue a new credential.</p>
                <?php if (iHomefinderUtility::getInstance()->getRequestVar("ihf_reset") === "1") { ?>
                    <div class="updated">
                        <p>The blog connection is being reset. A new credential will be issued shortly.</p>
                    </div>
                <?php } ?>
                <form method="post" action="<?php echo esc_url(admin_url("admin-post.php")); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(iHomefinderAdmin::RESET_BLOG_CONNECTION_ACTION); ?>" />
                    <?php wp_nonce_field(iHomefinderAdmin::RESET_BLOG_CONNECTION_ACTION); ?>
                    <p class="submit">
                        <button type="submit" class="button">Reset Blog Connection</button>
                    </p>
                </form>
            <?php } ?>
            <?php if (!is_multisite() && $this->admin->previouslyActivated()) { ?>
                <?php iHomefinderAdminMigrationStatus::getInstance()->renderSingleSiteSection(); ?>
            <?php } ?>
            <?php
        }
    }

    /**
     * The registration key form, posting to admin-post.php with the register-key action.
     *
     * @param string $value the key to show in the field (the stored key, or a ?reg= pre-fill)
     * @return void
     */
    private function showRegisterKeyForm($value)
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url("admin-post.php")); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(iHomefinderAdmin::REGISTER_KEY_ACTION); ?>" />
            <?php wp_nonce_field(iHomefinderAdmin::REGISTER_KEY_ACTION); ?>
            <table class="form-table">
                <tr>
                    <th>
                        <label for="<?php echo esc_attr(iHomefinderAdmin::REGISTRATION_KEY_FIELD); ?>">Registration Key</label>
                    </th>
                    <td>
                        <input id="<?php echo esc_attr(iHomefinderAdmin::REGISTRATION_KEY_FIELD); ?>" class="regular-text" type="text" name="<?php echo esc_attr(iHomefinderAdmin::REGISTRATION_KEY_FIELD); ?>" value="<?php echo esc_attr($value); ?>" required="required" />
                    </td>
                </tr>
            </table>
            <p>
            <?php echo esc_html(self::IHOMEFINDER_NOTIFICATION); ?>
            </p>
            <p class="submit">
                <button type="submit" class="button-primary">Register</button>
            </p>
        </form>
        <?php
    }

    /**
     * Renders, once, the outcome the user's last Register page action stored. Each outcome has
     * its own message; the detail of an unreachable service is the fixed vocabulary of
     * iHomefinderRemoteResponse::getFailureDetail(), never a URL, key or token.
     *
     * @return void
     */
    private function showActionResult()
    {
        $result = $this->admin->consumeActivateResult();
        if ($result === null) {
            return;
        }
        $detail = $result["detail"];
        switch ($result["outcome"]) {
            case iHomefinderAdmin::ACTIVATE_ACTIVATED:
                $class = "updated";
                $message = "Your Optima Express plugin has been registered.";
                break;
            case iHomefinderAdmin::ACTIVATE_REJECTED:
                $class = "error";
                $message = "That registration key was not recognized. Your existing registration is unchanged.";
                break;
            case iHomefinderAdmin::ACTIVATE_UNREACHABLE:
                $class = "error";
                $message = "Could not reach the registration service" . ($detail !== "" ? " (" . $detail . ")" : "") . ". Your existing registration is unchanged. Please try again in a few minutes.";
                break;
            case iHomefinderAdmin::ACTIVATE_NO_KEY:
                $class = "error";
                $message = "No registration key was found. Enter your registration key to register Optima Express.";
                break;
            case iHomefinderAdmin::RESULT_UNREGISTERED:
                $class = "updated";
                $message = "Optima Express has been unregistered.";
                break;
            default:
                return;
        }
        ?>
        <div class="<?php echo esc_attr($class); ?>">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }
}
