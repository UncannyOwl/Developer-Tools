#!/usr/bin/env php
<?php

/**
 * Cross-platform PR code checking script
 */

// Windows-specific check
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Ensure PHP is in the PATH
    if (!defined('PHP_BINARY')) {
        echo "Error: PHP binary not found. Please ensure PHP is installed and in your system PATH.\n";
        exit(1);
    }
}

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

// Get changed PHP files
$output = [];
$returnVar = 0;

// Use PHP's built-in exec function with proper shell escaping
$gitCommand = 'git diff --name-only origin/pre-release...';
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows-specific command execution
    exec('cmd /c ' . escapeshellarg($gitCommand), $output, $returnVar);
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
$files = implode(' ', array_map('escapeshellarg', $phpFiles));
$command = sprintf('%s %s %s', $bin, $args, $files);

// Execute the command with proper shell handling
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows-specific command execution
    passthru('cmd /c ' . escapeshellarg($command), $returnVar);
} else {
    passthru($command, $returnVar);
}

exit($returnVar); 