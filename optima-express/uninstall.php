<?php
/**
 * Runs only when the plugin is fully uninstalled (Plugins → Delete),
 * NOT on deactivation.  Permanently removes all ihf_virtual_page CPT posts.
 */
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'iHomefinderAutoloader.php';
iHomefinderAutoloader::getInstance();

iHomefinderPostType::getInstance()->deletePosts();
