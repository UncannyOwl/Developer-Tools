<?php
/**
 * Universal platform-specific script runner
 * 
 * Usage: php run-platform-specific.php [phpcs|phpcbf]
 */

// Check arguments
if ($argc < 2) {
    echo "Usage: php run-platform-specific.php [phpcs|phpcbf]\n";
    exit(1);
}

// Get the command type
$commandType = $argv[1];

// Validate command type
if (!in_array($commandType, ['phpcs', 'phpcbf'])) {
    echo "Error: First argument must be either 'phpcs' or 'phpcbf'\n";
    echo "Usage: php run-platform-specific.php [phpcs|phpcbf]\n";
    exit(1);
}

// Check if we're on Windows
$isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
echo "Detected platform: " . ($isWindows ? "Windows" : "Unix") . "\n";

// Get the directory where this script is located
$scriptDir = dirname(__FILE__);

if ($isWindows) {
    // On Windows, use the Windows-specific wrapper
    echo "Using Windows-specific script\n";
    require_once($scriptDir . DIRECTORY_SEPARATOR . 'pr-code-check-windows.php');
} else {
    // On Unix systems, use the standard script
    echo "Using standard script\n";
    require_once($scriptDir . DIRECTORY_SEPARATOR . 'pr-code-check.php');
} 