#!/usr/bin/env php
<?php

/**
 * Cross-platform PR code checking script
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get the command type (phpcs or phpcbf)
$commandType = $argv[1] ?? '';
if (!in_array($commandType, ['phpcs', 'phpcbf'])) {
    echo "Usage: php pr-code-check.php [phpcs|phpcbf]\n";
    exit(1);
}

// Determine if we're on Windows
$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
echo "Operating System: " . PHP_OS . " (Windows: " . ($isWindows ? "Yes" : "No") . ")\n";

// Get the script directory
$scriptDir = dirname(__FILE__);
echo "Script directory: $scriptDir\n";

// Get the base directory (project root)
$baseDir = realpath(dirname(dirname($scriptDir)));
echo "Base directory: $baseDir\n";

// Change to the project root directory
chdir($baseDir);
echo "Current working directory: " . getcwd() . "\n";

// Get the vendor directory path
$vendorDir = $baseDir . DIRECTORY_SEPARATOR . 'vendor';
echo "Vendor directory: $vendorDir\n";

// Get the PHPCS binary path
$binPath = $vendorDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR;
if (!file_exists($binPath . 'phpcs')) {
    // Try the path with UOCS
    $binPath = $vendorDir . DIRECTORY_SEPARATOR . 'uocs' . DIRECTORY_SEPARATOR . 
               'uncanny-owl-coding-standards' . DIRECTORY_SEPARATOR . 'vendor' . 
               DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR;
}
echo "Binary path: $binPath\n";

// On Windows, ensure we have .bat extension for the binaries
$phpcsExt = $isWindows ? '.bat' : '';
$phpcbfExt = $isWindows ? '.bat' : '';

$phpcsBin = $binPath . 'phpcs' . $phpcsExt;
$phpcbfBin = $binPath . 'phpcbf' . $phpcbfExt;

echo "PHPCS binary: $phpcsBin (exists: " . (file_exists($phpcsBin) ? "Yes" : "No") . ")\n";
echo "PHPCBF binary: $phpcbfBin (exists: " . (file_exists($phpcbfBin) ? "Yes" : "No") . ")\n";

// Get changed PHP files
try {
    echo "Executing git diff command...\n";
    $command = 'git diff --name-only origin/pre-release...';
    echo "Command: $command\n";
    
    $output = [];
    $returnVar = 0;
    exec($command, $output, $returnVar);

    if ($returnVar !== 0) {
        throw new Exception("Failed to execute git diff command. Error code: $returnVar");
    }
    
    echo "Git diff returned " . count($output) . " files\n";
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

// Command can be too long on Windows, so process in batches if needed
if ($isWindows && count($phpFiles) > 50) {
    $batches = array_chunk($phpFiles, 50);
    $returnVar = 0;
    
    foreach ($batches as $index => $batchFiles) {
        echo "Processing batch " . ($index + 1) . " of " . count($batches) . "...\n";
        $batchFileArgs = implode(' ', array_map('escapeshellarg', $batchFiles));
        $batchCommand = sprintf('%s %s %s', $bin, $args, $batchFileArgs);
        echo "Executing: $batchCommand\n";
        passthru($batchCommand, $batchReturnVar);
        
        if ($batchReturnVar > $returnVar) {
            $returnVar = $batchReturnVar;
        }
    }
} else {
    $files = implode(' ', array_map('escapeshellarg', $phpFiles));
    $command = sprintf('%s %s %s', $bin, $args, $files);
    echo "Executing: $command\n";
    passthru($command, $returnVar);
}

echo "Done. Exit code: $returnVar\n";
exit($returnVar); 