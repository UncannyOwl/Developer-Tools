#!/usr/bin/env php
<?php

/**
 * Cross-platform PR code checking script
 */

// Get the command type (phpcs or phpcbf)
$commandType = $argv[1] ?? '';
if (!in_array($commandType, ['phpcs', 'phpcbf'])) {
    echo "Usage: php pr-code-check.php [phpcs|phpcbf]\n";
    exit(1);
}

// Get the base directory (where the script is being called from)
$baseDir = getcwd();

// Get the vendor directory path
$vendorDir = $baseDir . DIRECTORY_SEPARATOR . 'vendor';

// Get the PHPCS binary path
$phpcsBin = $vendorDir . DIRECTORY_SEPARATOR . 'uocs' . DIRECTORY_SEPARATOR . 'uncanny-owl-coding-standards' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpcs';
$phpcbfBin = $vendorDir . DIRECTORY_SEPARATOR . 'uocs' . DIRECTORY_SEPARATOR . 'uncanny-owl-coding-standards' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpcbf';

// Get changed PHP files
$output = [];
$returnVar = 0;
exec('git diff --name-only origin/pre-release...', $output, $returnVar);

if ($returnVar !== 0) {
    echo "Error: Failed to get changed files from git\n";
    exit(1);
}

// Filter PHP files
$phpFiles = array_filter($output, function($file) {
    return preg_match('/\.php$/', $file);
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

// Execute the command
passthru($command, $returnVar);
exit($returnVar); 