<?php

namespace UncannyOwl\DevTools;

/**
 * Utility class for detecting test environments
 */
class TestDetection {
    
    /**
     * Check if tests are running
     * 
     * @return bool Whether tests are running
     */
    public static function isTestRunning(): bool {
        // Check environment variable
        if (isset($_ENV['DOING_AUTOMATOR_TEST'])) {
            return true;
        }
        
        // Check if our prefixed class file exists
        $prefixed_file = dirname(__DIR__) . '/build/lucatume/wp-browser/src/Codeception/TestCase/WPTestCase.php';
        if (file_exists($prefixed_file)) {
            // The prefixed class exists in our build
            return true;
        }
        
        // Finally check for the global Codeception class
        if (class_exists('\Codeception\TestCase\WPTestCase')) {
            return true;
        }
        
        return false;
    }
} 