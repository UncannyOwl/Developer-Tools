<?php
/**
 * Windows-specific wrapper for the pr-code-check.php script
 * This is used to avoid issues with Windows trying to open PHP files instead of executing them
 */

// If no arguments provided, show usage
if ($argc < 2) {
    echo "Usage: php pr-code-check-windows.php [phpcs|phpcbf]\n";
    exit(1);
}

// Get the command type from arguments
$commandType = $argv[1];

// Validate command type
if (!in_array($commandType, ['phpcs', 'phpcbf'])) {
    echo "Error: First argument must be either 'phpcs' or 'phpcbf'\n";
    echo "Usage: php pr-code-check-windows.php [phpcs|phpcbf]\n";
    exit(1);
}

// Get the script directory
$scriptDir = dirname(__FILE__);

// Build the command to execute the main script
$command = sprintf('php -f "%s\\pr-code-check.php" %s', $scriptDir, $commandType);

// Diagnostic output
echo "Executing command: {$command}\n";

// Just include the script directly to avoid shell escaping issues
require_once($scriptDir . DIRECTORY_SEPARATOR . 'pr-code-check.php'); 