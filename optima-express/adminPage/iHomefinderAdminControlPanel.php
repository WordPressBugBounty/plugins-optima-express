<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class iHomefinderAdminControlPanel extends iHomefinderAdminAbstractPage
{
    
    private static $instance;
    
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    protected function getContent()
    {
        // POSTs the stored registration key to the Control Panel sign-in endpoint (never in a URL),
        // or links to the sign-in page when no usable key is stored (MAX-4708, D53).
        ?>
        <h2>IDX Control Panel</h2>
        <p>Open your IDX Control Panel to manage your account, leads and settings.</p>
        <?php $this->admin->renderControlPanelLink("Open the IDX Control Panel", "button button-primary"); ?>
        <?php
    }
}
