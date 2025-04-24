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
    
    private function isDebug()
    {
        $debugValue = iHomefinderUtility::getInstance()->getRequestVar("debug");
        $debug = false;
        if ($debugValue === "true" || iHomefinderConstants::DEBUG) {
            $debug = true;
        }
        return $debug;
    }
    
    /**
     * Dumps messages to the screen if debugging on
     *
     * @param mixed $message The message to be logged
     *
     * @return void
     */
    public function debug($message)
    {
        if ($this->isDebug()) {
            echo esc_html(microtime(true)) . ": ";
            var_dump($message);
        }
    }
}
