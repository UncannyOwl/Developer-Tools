<?php
/**
 * Windows-specific wrapper for the pr-code-check.php script
 * This is used to avoid issues with Windows trying to open PHP files instead of executing them
 */

// Get the script directory
$scriptDir = dirname(__FILE__);

// Get the command type from arguments
$commandType = isset($argv[1]) ? $argv[1] : '';

if (!in_array($commandType, ['phpcs', 'phpcbf'])) {
    echo "Usage: php pr-code-check-windows.php [phpcs|phpcbf]\n";
    exit(1);
}

// Build the command to execute the main script
$command = sprintf('php "%s\\pr-code-check.php" %s', $scriptDir, $commandType);

// Execute the command and pass the exit code
$returnCode = 0;
passthru($command, $returnCode);
exit($returnCode); 