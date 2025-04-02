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

// Determine if we're on Windows
$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

// Get the script directory
$scriptDir = dirname(__FILE__);

// Get the base directory (project root)
$baseDir = dirname(dirname($scriptDir));

// Change to the project root directory
chdir($baseDir);

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

// On Windows, ensure we have .bat extension for the binaries
$phpcsExt = $isWindows ? '.bat' : '';
$phpcbfExt = $isWindows ? '.bat' : '';

$phpcsBin = $binPath . 'phpcs' . $phpcsExt;
$phpcbfBin = $binPath . 'phpcbf' . $phpcbfExt;

// Verify tool paths exist
if (!file_exists($phpcsBin)) {
    echo "Error: PHPCS binary not found at {$phpcsBin}\n";
    exit(1);
}

if (!file_exists($phpcbfBin)) {
    echo "Error: PHPCBF binary not found at {$phpcbfBin}\n";
    exit(1);
}

// Get changed PHP files
try {
    $output = [];
    $returnVar = 0;
    exec('git diff --name-only origin/pre-release...', $output, $returnVar);

    if ($returnVar !== 0) {
        throw new Exception("Failed to execute git diff command. Error code: $returnVar");
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Make sure git is installed and available.\n";
    exit(1);
}

// Filter PHP files and exclude tests, vendor, and node_modules
$phpFiles = array_filter($output, function($file) {
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

echo "Found " . count($phpFiles) . " PHP files to check.\n";

// Build the command
$bin = $commandType === 'phpcs' ? $phpcsBin : $phpcbfBin;
$args = $commandType === 'phpcs' ? '--standard=Uncanny-Automator --warning-severity=1' : '--standard=Uncanny-Automator';
$files = implode(' ', array_map('escapeshellarg', $phpFiles));

// Command can be too long on Windows, so process in batches if needed
if ($isWindows && strlen($files) > 8000) {
    $batches = array_chunk($phpFiles, 50);
    $returnVar = 0;
    
    foreach ($batches as $batchFiles) {
        $batchFileArgs = implode(' ', array_map('escapeshellarg', $batchFiles));
        $command = sprintf('%s %s %s', $bin, $args, $batchFileArgs);
        passthru($command, $batchReturnVar);
        
        if ($batchReturnVar > $returnVar) {
            $returnVar = $batchReturnVar;
        }
    }
} else {
    $command = sprintf('%s %s %s', $bin, $args, $files);
    echo "Running: " . $commandType . " on changed files...\n";
    passthru($command, $returnVar);
}

exit($returnVar); 