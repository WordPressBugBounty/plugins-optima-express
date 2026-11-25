<?php

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


class iHomefinderLogger
{
    
    private static $instance;
    
    private function __construct()
    {
    }
    
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Debug logging needs WP_DEBUG, plus either the DEBUG constant or a site administrator who
     * sent debug=true. A visitor's request flag alone never turns it on.
     */
    private function isDebug()
    {
        if (!defined("WP_DEBUG") || !WP_DEBUG) {
            return false;
        }
        if (iHomefinderConstants::DEBUG) {
            return true;
        }
        if (!function_exists("current_user_can") || !current_user_can("manage_options")) {
            return false;
        }
        return iHomefinderUtility::getInstance()->getRequestVar("debug") === "true";
    }

    /**
     * Writes messages to the PHP error log if debugging is on. Debug output never goes to the
     * page, not even for an administrator.
     *
     * @param mixed $message The message to be logged
     *
     * @return void
     */
    public function debug($message)
    {
        if ($this->isDebug()) {
            if (!is_string($message)) {
                $message = print_r($message, true);
            }
            error_log("[Optima Express debug] " . microtime(true) . ": " . $message);
        }
    }
}
