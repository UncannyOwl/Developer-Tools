#!/usr/bin/env php
<?php

/**
 * CRAP Score Calculator for Uncanny Automator
 * 
 * Calculates CRAP (Change Risk Anti-Patterns) score for PHP files
 * and optionally posts results as PR comments
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if we're running in PR mode
$isPrMode = in_array('--pr', $argv);

// Get the script directory
$scriptDir = dirname(__FILE__);
$baseDir = realpath(dirname(dirname($scriptDir))); // automator-dev-tools
$projectRootDir = dirname(dirname($baseDir)); // Go up to plugin root

// Change to the project root directory
chdir($projectRootDir);

// Get the vendor directory path
$vendorDir = $projectRootDir . DIRECTORY_SEPARATOR . 'vendor';

// Get the PHPMD binary path
$phpmdBin = $vendorDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpmd';
if (!file_exists($phpmdBin)) {
    // Try the path with UOCS
    $phpmdBin = $vendorDir . DIRECTORY_SEPARATOR . 'uocs' . DIRECTORY_SEPARATOR . 
               'uncanny-owl-coding-standards' . DIRECTORY_SEPARATOR . 'vendor' . 
               DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpmd';
}

// Get the PHPCBF binary path
$phpcpdBin = $vendorDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpcpd';
if (!file_exists($phpcpdBin)) {
    // Try the path with UOCS
    $phpcpdBin = $vendorDir . DIRECTORY_SEPARATOR . 'uocs' . DIRECTORY_SEPARATOR . 
                'uncanny-owl-coding-standards' . DIRECTORY_SEPARATOR . 'vendor' . 
                DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpcpd';
}

echo "=== CRAP Score Analysis ===\n";
echo "Project root: $projectRootDir\n";
echo "PHPMD binary: $phpmdBin (exists: " . (file_exists($phpmdBin) ? "Yes" : "No") . ")\n";
echo "PHPCBF binary: $phpcpdBin (exists: " . (file_exists($phpcpdBin) ? "Yes" : "No") . ")\n";

if (!file_exists($phpmdBin) || !file_exists($phpcpdBin)) {
    echo "Error: Required binaries not found. Please run 'composer install' in the developer tools directory.\n";
    exit(1);
}

// Get files to analyze
$filesToAnalyze = [];
if ($isPrMode) {
    // Get changed PHP files from git diff
    try {
        $command = 'git diff --name-only origin/pre-release...';
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception("Failed to execute git diff command. Error code: $returnVar");
        }
        
        // Filter PHP files and exclude tests, vendor, and node_modules
        $filesToAnalyze = array_filter($output, function($file) {
            if (!preg_match('/\.php$/', $file)) {
                return false;
            }
            
            $excludePatterns = [
                '/\/tests\//',
                '/\/vendor\//',
                '/\/node_modules\//'
            ];
            
            foreach ($excludePatterns as $pattern) {
                if (preg_match($pattern, $file)) {
                    return false;
                }
            }
            
            return true;
        });
        
        echo "Found " . count($filesToAnalyze) . " changed PHP files to analyze.\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        echo "Falling back to analyzing all PHP files.\n";
        $filesToAnalyze = [];
    }
}

// If no specific files or not in PR mode, analyze all PHP files
if (empty($filesToAnalyze)) {
    echo "Analyzing all PHP files in the project...\n";
    $filesToAnalyze = ['.'];
}

// Determine memory limit based on environment
$isLocal = !isset($_ENV['CI']) && !isset($_ENV['GITHUB_ACTIONS']) && !isset($_ENV['BUDDY']);
$memoryLimit = $isLocal ? '2G' : '512M';

    // Run PHPMD analysis
    echo "\n=== Running PHPMD Analysis ===\n";
    $phpmdCommand = 'php -d memory_limit=' . $memoryLimit . ' ' . escapeshellarg($phpmdBin) . ' ' .
                    implode(' ', array_map('escapeshellarg', $filesToAnalyze)) .
                    ' xml cleancode,codesize,controversial,design,naming,unusedcode --exclude vendor,tests,node_modules';

    echo "Command: $phpmdCommand\n";
    $phpmdOutput = [];
    $phpmdReturnVar = 0;
    exec($phpmdCommand . ' 2>&1', $phpmdOutput, $phpmdReturnVar);

// Run PHPCBF analysis for code duplication (commented out - only focusing on PHPMD)
// echo "\n=== Running PHPCBF Analysis ===\n";
// $phpcpdCommand = 'php -d memory_limit=' . $memoryLimit . ' ' . escapeshellarg($phpcpdBin) . ' ' .
//                 implode(' ', array_map('escapeshellarg', $filesToAnalyze)) .
//                 ' --exclude vendor --exclude tests --exclude node_modules --min-lines 5 --min-tokens 70';

// echo "Command: $phpcpdCommand\n";
// $phpcpdOutput = [];
// $phpcpdReturnVar = 0;
// exec($phpcpdCommand . ' 2>/dev/null', $phpcpdOutput, $phpcpdReturnVar);

    // Parse PHPMD XML output for CRAP score calculation
    $crapScores = [];
    $complexityIssues = [];
    $totalMethods = 0;
    $highCrapMethods = 0;

    // Filter out warning messages and extract only XML content
    $xmlLines = [];
    $inXml = false;
    foreach ($phpmdOutput as $line) {
        if (strpos($line, '<?xml') === 0) {
            $inXml = true;
        }
        if ($inXml) {
            $xmlLines[] = $line;
        }
        if ($inXml && strpos($line, '</pmd>') !== false) {
            break;
        }
    }

    $xmlOutput = implode("\n", $xmlLines);

    // Parse XML if we have output
    if (!empty($xmlOutput) && strpos($xmlOutput, '<?xml') !== false) {
        try {
            $xml = simplexml_load_string($xmlOutput);
            if ($xml !== false) {
                // Look for files with violations
                foreach ($xml->file as $file) {
                    $fileName = (string)$file['name'];

                    foreach ($file->violation as $violation) {
                        $lineNumber = (int)$violation['beginline'];
                        $message = (string)$violation;

                        // Extract cyclomatic complexity
                        if (preg_match('/cyclomatic complexity of (\d+)/', $message, $complexityMatches)) {
                            $complexity = (int)$complexityMatches[1];
                            $totalMethods++;

                            // Calculate CRAP score (simplified: complexity^2 * (1 - coverage/100))
                            // For now, assume 0% coverage if not provided
                            $coverage = 0; // This would need to be calculated from test coverage
                            $crapScore = pow($complexity, 2) * (1 - $coverage / 100);

                            $crapScores[] = [
                                'file' => $fileName,
                                'line' => $lineNumber,
                                'complexity' => $complexity,
                                'coverage' => $coverage,
                                'crap_score' => $crapScore,
                                'message' => $message
                            ];

                            if ($crapScore > 30) { // High CRAP score threshold
                                $highCrapMethods++;
                                $complexityIssues[] = [
                                    'file' => $fileName,
                                    'line' => $lineNumber,
                                    'crap_score' => $crapScore,
                                    'message' => $message
                                ];
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            echo "Error parsing PHPMD XML: " . $e->getMessage() . "\n";
        }
    } else {
        // Fallback to text parsing if XML parsing fails
        echo "PHPMD output (first 10 lines):\n";
        foreach (array_slice($phpmdOutput, 0, 10) as $line) {
            echo "  $line\n";
        }

        foreach ($phpmdOutput as $line) {
            if (preg_match('/^(.+):(\d+)\s+(.+)$/', $line, $matches)) {
                $file = $matches[1];
                $lineNumber = $matches[2];
                $message = $matches[3];

                // Extract cyclomatic complexity
                if (preg_match('/cyclomatic complexity of (\d+)/', $message, $complexityMatches)) {
                    $complexity = (int)$complexityMatches[1];
                    $totalMethods++;

                    // Calculate CRAP score (simplified: complexity^2 * (1 - coverage/100))
                    // For now, assume 0% coverage if not provided
                    $coverage = 0; // This would need to be calculated from test coverage
                    $crapScore = pow($complexity, 2) * (1 - $coverage / 100);

                    $crapScores[] = [
                        'file' => $file,
                        'line' => $lineNumber,
                        'complexity' => $complexity,
                        'coverage' => $coverage,
                        'crap_score' => $crapScore,
                        'message' => $message
                    ];

                    if ($crapScore > 30) { // High CRAP score threshold
                        $highCrapMethods++;
                        $complexityIssues[] = [
                            'file' => $file,
                            'line' => $lineNumber,
                            'crap_score' => $crapScore,
                            'message' => $message
                        ];
                    }
                }
            }
        }
    }

// Calculate overall metrics
$averageCrapScore = $totalMethods > 0 ? array_sum(array_column($crapScores, 'crap_score')) / $totalMethods : 0;
$maxCrapScore = $totalMethods > 0 ? max(array_column($crapScores, 'crap_score')) : 0;

// Generate report
echo "\n=== CRAP Score Report ===\n";
echo "Total methods analyzed: $totalMethods\n";
echo "Average CRAP score: " . number_format($averageCrapScore, 2) . "\n";
echo "Maximum CRAP score: " . number_format($maxCrapScore, 2) . "\n";
echo "Methods with high CRAP score (>30): $highCrapMethods\n";

if (!empty($complexityIssues)) {
    echo "\n=== High CRAP Score Methods ===\n";
    foreach ($complexityIssues as $issue) {
        echo "File: {$issue['file']}:{$issue['line']}\n";
        echo "CRAP Score: " . number_format($issue['crap_score'], 2) . "\n";
        echo "Issue: {$issue['message']}\n\n";
    }
}

// Code duplication report (commented out - only focusing on PHPMD)
// if (!empty($phpcpdOutput)) {
//     echo "\n=== Code Duplication Report ===\n";
//     foreach ($phpcpdOutput as $line) {
//         echo "$line\n";
//     }
// }

// Generate GitHub comment if in PR mode
if ($isPrMode) {
    $comment = generateGitHubComment($crapScores, $complexityIssues, $averageCrapScore, $maxCrapScore, $totalMethods, $highCrapMethods, []);
    
    // Save comment to file for GitHub Action to use
    file_put_contents($projectRootDir . '/crap-score-comment.md', $comment);
    echo "\n=== GitHub Comment Generated ===\n";
    echo "Comment saved to: crap-score-comment.md\n";
    echo "\nComment preview:\n";
    echo "---\n";
    echo $comment;
    echo "\n---\n";
}

/**
 * Generate GitHub comment for PR
 */
function generateGitHubComment($crapScores, $complexityIssues, $averageCrapScore, $maxCrapScore, $totalMethods, $highCrapMethods, $phpcpdOutput) {
    $comment = "## 🔍 CRAP Score Analysis\n\n";
    
    // Summary
    $comment .= "### 📊 Summary\n";
    $comment .= "- **Total methods analyzed:** $totalMethods\n";
    $comment .= "- **Average CRAP score:** " . number_format($averageCrapScore, 2) . "\n";
    $comment .= "- **Maximum CRAP score:** " . number_format($maxCrapScore, 2) . "\n";
    $comment .= "- **High CRAP methods (>30):** $highCrapMethods\n\n";
    
    // CRAP Score Interpretation
    $comment .= "### 📈 CRAP Score Interpretation\n";
    $comment .= "- **0-5:** Low risk, well-tested\n";
    $comment .= "- **6-15:** Moderate risk\n";
    $comment .= "- **16-30:** High risk, needs refactoring\n";
    $comment .= "- **30+:** Very high risk, urgent refactoring needed\n\n";
    
    if (!empty($complexityIssues)) {
        $comment .= "### ⚠️ High CRAP Score Methods\n";
        $comment .= "| File | Line | CRAP Score | Issue |\n";
        $comment .= "|------|------|------------|-------|\n";
        
        foreach ($complexityIssues as $issue) {
            $file = basename($issue['file']);
            $comment .= "| `$file` | {$issue['line']} | " . number_format($issue['crap_score'], 2) . " | {$issue['message']} |\n";
        }
        $comment .= "\n";
    }
    
    // Code duplication section (commented out - only focusing on PHPMD)
    // if (!empty($phpcpdOutput)) {
    //     $comment .= "### 🔄 Code Duplication\n";
    //     $comment .= "```\n";
    //     foreach ($phpcpdOutput as $line) {
    //         $comment .= "$line\n";
    //     }
    //     $comment .= "```\n\n";
    // }
    
    // Recommendations
    $comment .= "### 💡 Recommendations\n";
    if ($highCrapMethods > 0) {
        $comment .= "- Consider refactoring methods with high CRAP scores\n";
        $comment .= "- Add unit tests to improve coverage\n";
        $comment .= "- Break down complex methods into smaller, more manageable functions\n";
    } else {
        $comment .= "- ✅ No high-risk methods detected\n";
        $comment .= "- Keep up the good work!\n";
    }
    
    return $comment;
}

echo "\n=== Analysis Complete ===\n";
