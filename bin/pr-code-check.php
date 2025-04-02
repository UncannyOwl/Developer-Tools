#!/usr/bin/env php
<?php

/**
 * Cross-platform PR code checking script
 */

// Define constants for platform detection
define('IS_WINDOWS', strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

// Get the command type (phpcs or phpcbf)
$commandType = $argv[1] ?? '';
if (!in_array($commandType, ['phpcs', 'phpcbf'])) {
    echo "Usage: php pr-code-check.php [phpcs|phpcbf]\n";
    exit(1);
}

// Get the base directory (where the script is being called from)
$baseDir = getcwd();

// Normalize directory separators for cross-platform compatibility
$baseDir = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $baseDir);

// Get the vendor directory path
$vendorDir = $baseDir . DIRECTORY_SEPARATOR . 'vendor';

// Get the PHPCS binary path
$binPath = $vendorDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR;
if (!file_exists($binPath . 'phpcs')) {
    // Try the path with UOCS
    $binPath = $vendorDir . DIRECTORY_SEPARATOR . 'uocs' . DIRECTORY_SEPARATOR . 
               'uncanny-owl-coding-standards' . DIRECTORY_SEPARATOR . 'vendor' . 
               DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR;
}

$phpcsBin = $binPath . 'phpcs';
$phpcbfBin = $binPath . 'phpcbf';

// Handle Windows-specific executable detection
if (IS_WINDOWS) {
    // In Windows, check for .bat or .cmd extensions
    if (!file_exists($phpcsBin) && file_exists($phpcsBin . '.bat')) {
        $phpcsBin .= '.bat';
    }
    if (!file_exists($phpcbfBin) && file_exists($phpcbfBin . '.bat')) {
        $phpcbfBin .= '.bat';
    }
}

// Get changed PHP files
$output = [];
$returnVar = 0;

// Windows-specific command execution
$gitCommand = 'git diff --name-only origin/pre-release...';
if (IS_WINDOWS) {
    // Use different shell escaping on Windows
    exec('cmd /c ' . $gitCommand, $output, $returnVar);
} else {
    exec($gitCommand, $output, $returnVar);
}

if ($returnVar !== 0) {
    echo "Error: Failed to get changed files from git\n";
    exit(1);
}

// Filter PHP files and exclude tests, vendor, and node_modules
$phpFiles = array_filter($output, function($file) {
    // Normalize file path separators
    $file = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $file);
    
    // Only include PHP files
    if (!preg_match('/\.php$/', $file)) {
        return false;
    }
    
    // Exclude tests, vendor, and node_modules directories
    $excludePatterns = [
        '/\/tests\//',      // any level /tests/
        '/\/vendor\//',     // any level /vendor/
        '/\/node_modules\//'// any level /node_modules/
    ];
    
    foreach ($excludePatterns as $pattern) {
        if (preg_match($pattern, $file)) {
            return false;
        }
    }
    
    return true;
});

if (empty($phpFiles)) {
    echo "No PHP files to check.\n";
    exit(0);
}

// Build the command
$bin = $commandType === 'phpcs' ? $phpcsBin : $phpcbfBin;
$args = $commandType === 'phpcs' ? '--standard=Uncanny-Automator --warning-severity=1' : '--standard=Uncanny-Automator';

// Convert file paths to the correct format and properly escape them
$files = array_map(function($file) {
    // Normalize slashes for the current platform
    $file = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $file);
    return escapeshellarg($file);
}, $phpFiles);

$filesStr = implode(' ', $files);

// Build the complete command with proper OS-specific handling
if (IS_WINDOWS) {
    // On Windows, we need to execute this through cmd
    $command = sprintf('cmd /c %s %s %s', escapeshellarg($bin), $args, $filesStr);
} else {
    $command = sprintf('%s %s %s', $bin, $args, $filesStr);
}

// Display command (for debugging)
echo "Executing: " . ($commandType === 'phpcs' ? 'PHP Code Sniffer' : 'PHP Code Beautifier and Fixer') . "\n";

// Execute the command
if (IS_WINDOWS) {
    passthru($command, $returnVar);
} else {
    passthru($command, $returnVar);
}

exit($returnVar); 