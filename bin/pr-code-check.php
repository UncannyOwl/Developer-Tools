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

// Check if the binary exists - if not, try to find it elsewhere
if (!file_exists($bin)) {
    echo "Warning: Binary not found at expected location. Attempting to find it elsewhere...\n";
    
    // Try to find the binary in standard locations
    if ($isWindows) {
        // Look in vendor/bin directory
        $altBinPath = $baseDir . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR;
        $altBin = $altBinPath . ($commandType === 'phpcs' ? 'phpcs' : 'phpcbf') . $phpcsExt;
        
        if (file_exists($altBin)) {
            echo "Found binary at alternate location: $altBin\n";
            $bin = $altBin;
        } else {
            // Try to locate it using 'where' command
            $whereBin = trim(shell_exec('where ' . ($commandType === 'phpcs' ? 'phpcs' : 'phpcbf') . ' 2> nul'));
            if (!empty($whereBin)) {
                echo "Found binary using 'where' command: $whereBin\n";
                $bin = $whereBin;
            } else {
                echo "Error: Cannot find " . ($commandType === 'phpcs' ? 'phpcs' : 'phpcbf') . " binary.\n";
                exit(1);
            }
        }
    } else {
        // Unix systems
        $whichBin = trim(shell_exec('which ' . ($commandType === 'phpcs' ? 'phpcs' : 'phpcbf') . ' 2>/dev/null'));
        if (!empty($whichBin)) {
            echo "Found binary using 'which' command: $whichBin\n";
            $bin = $whichBin;
        } else {
            echo "Error: Cannot find " . ($commandType === 'phpcs' ? 'phpcs' : 'phpcbf') . " binary.\n";
            exit(1);
        }
    }
}

// Command can be too long on Windows, so process in batches if needed
if ($isWindows && count($phpFiles) > 50) {
    $batches = array_chunk($phpFiles, 50);
    $returnVar = 0;
    
    foreach ($batches as $index => $batchFiles) {
        echo "Processing batch " . ($index + 1) . " of " . count($batches) . "...\n";
        
        // Create a temporary file with the list of files to process
        $tmpFile = tempnam(sys_get_temp_dir(), 'phpcs_files_');
        file_put_contents($tmpFile, implode(PHP_EOL, $batchFiles));
        
        // Use the file list instead of command line arguments
        $command = sprintf('"%s" %s --file-list="%s"', $bin, $args, $tmpFile);
        echo "Executing: $command\n";
        passthru($command, $batchReturnVar);
        
        // Clean up
        unlink($tmpFile);
        
        if ($batchReturnVar > $returnVar) {
            $returnVar = $batchReturnVar;
        }
    }
} else {
    // For non-Windows or smaller file lists, use a temporary file approach for safety
    $tmpFile = tempnam(sys_get_temp_dir(), 'phpcs_files_');
    file_put_contents($tmpFile, implode(PHP_EOL, $phpFiles));
    
    // Use the file list instead of command line arguments
    $command = sprintf('"%s" %s --file-list="%s"', $bin, $args, $tmpFile);
    echo "Executing: $command\n";
    passthru($command, $returnVar);
    
    // Clean up
    unlink($tmpFile);
}

echo "Done. Exit code: $returnVar\n";
exit($returnVar); 