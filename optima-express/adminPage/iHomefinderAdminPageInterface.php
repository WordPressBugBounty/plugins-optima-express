<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

interface iHomefinderAdminPageInterface
{
    
    public function getPage();
    
    public function registerSettings();
}
