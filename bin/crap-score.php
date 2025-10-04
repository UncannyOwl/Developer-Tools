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

// Separate files into core and integrations
$coreFiles = [];
$integrationFiles = [];

if ($isPrMode && !empty($filesToAnalyze)) {
    // Categorize changed files
    foreach ($filesToAnalyze as $file) {
        if (strpos($file, 'src/integrations/') === 0) {
            $integrationFiles[] = $file;
        } else {
            $coreFiles[] = $file;
        }
    }
} else {
    // For full analysis, we'll analyze core and integrations separately
    $coreFiles = ['.'];
    $integrationFiles = ['src/integrations'];
}

// Determine memory limit based on environment
$isLocal = !isset($_ENV['CI']) && !isset($_ENV['GITHUB_ACTIONS']) && !isset($_ENV['BUDDY']);
$memoryLimit = $isLocal ? '2G' : '512M';

    // Run PHPMD analysis for Core
    echo "\n=== Running PHPMD Analysis - Core ===\n";
    $corePhpmdCommand = 'php -d memory_limit=' . $memoryLimit . ' ' . escapeshellarg($phpmdBin) . ' ' .
                        implode(' ', array_map('escapeshellarg', $coreFiles)) .
                        ' xml cleancode,codesize,controversial,design,naming,unusedcode --exclude vendor,tests,node_modules,src/integrations';

    echo "Command: $corePhpmdCommand\n";
    $corePhpmdOutput = [];
    $corePhpmdReturnVar = 0;
    exec($corePhpmdCommand . ' 2>&1', $corePhpmdOutput, $corePhpmdReturnVar);

    // Run PHPMD analysis for Integrations
    echo "\n=== Running PHPMD Analysis - Integrations ===\n";
    $integrationPhpmdCommand = 'php -d memory_limit=' . $memoryLimit . ' ' . escapeshellarg($phpmdBin) . ' ' .
                              implode(' ', array_map('escapeshellarg', $integrationFiles)) .
                              ' xml cleancode,codesize,controversial,design,naming,unusedcode --exclude vendor,tests,node_modules';

    echo "Command: $integrationPhpmdCommand\n";
    $integrationPhpmdOutput = [];
    $integrationPhpmdReturnVar = 0;
    exec($integrationPhpmdCommand . ' 2>&1', $integrationPhpmdOutput, $integrationPhpmdReturnVar);

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
    $coreCrapScores = [];
    $integrationCrapScores = [];
    $coreComplexityIssues = [];
    $integrationComplexityIssues = [];
    $coreTotalMethods = 0;
    $integrationTotalMethods = 0;
    $coreHighCrapMethods = 0;
    $integrationHighCrapMethods = 0;

    // Parse Core results
    $coreXmlLines = [];
    $inXml = false;
    foreach ($corePhpmdOutput as $line) {
        if (strpos($line, '<?xml') === 0) {
            $inXml = true;
        }
        if ($inXml) {
            $coreXmlLines[] = $line;
        }
        if ($inXml && strpos($line, '</pmd>') !== false) {
            break;
        }
    }
    $coreXmlOutput = implode("\n", $coreXmlLines);

    // Debug: Output Core XML for inspection
    echo "\n=== Core PHPMD Raw Output (first 20 lines) ===\n";
    foreach (array_slice($corePhpmdOutput, 0, 20) as $line) {
        echo "$line\n";
    }
    echo "\n=== Core XML Output ===\n";
    echo $coreXmlOutput . "\n";

    // Parse Integration results
    $integrationXmlLines = [];
    $inXml = false;
    foreach ($integrationPhpmdOutput as $line) {
        if (strpos($line, '<?xml') === 0) {
            $inXml = true;
        }
        if ($inXml) {
            $integrationXmlLines[] = $line;
        }
        if ($inXml && strpos($line, '</pmd>') !== false) {
            break;
        }
    }
    $integrationXmlOutput = implode("\n", $integrationXmlLines);

    // Debug: Output Integration XML for inspection
    echo "\n=== Integration PHPMD Raw Output (first 20 lines) ===\n";
    foreach (array_slice($integrationPhpmdOutput, 0, 20) as $line) {
        echo "$line\n";
    }
    echo "\n=== Integration XML Output ===\n";
    echo $integrationXmlOutput . "\n";

    // Parse Core XML if we have output
    if (!empty($coreXmlOutput) && strpos($coreXmlOutput, '<?xml') !== false) {
        try {
            $xml = simplexml_load_string($coreXmlOutput);
            if ($xml !== false) {
                // Look for files with violations
                foreach ($xml->file as $file) {
                    $fileName = (string)$file['name'];

                    foreach ($file->violation as $violation) {
                        $lineNumber = (int)$violation['beginline'];
                        $message = (string)$violation;

                        // Debug: Output all violations to see what we're getting
                        echo "Core violation: $message\n";

                        // Extract cyclomatic complexity
                        if (preg_match('/cyclomatic complexity of (\d+)/', $message, $complexityMatches)) {
                            $complexity = (int)$complexityMatches[1];
                            $coreTotalMethods++;

                            // Calculate CRAP score (simplified: complexity^2 * (1 - coverage/100))
                            // For now, assume 0% coverage if not provided
                            $coverage = 0; // This would need to be calculated from test coverage
                            $crapScore = pow($complexity, 2) * (1 - $coverage / 100);

                            $coreCrapScores[] = [
                                'file' => $fileName,
                                'line' => $lineNumber,
                                'complexity' => $complexity,
                                'coverage' => $coverage,
                                'crap_score' => $crapScore,
                                'message' => $message
                            ];

                            if ($crapScore > 30) { // High CRAP score threshold
                                $coreHighCrapMethods++;
                                $coreComplexityIssues[] = [
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
            echo "Error parsing Core PHPMD XML: " . $e->getMessage() . "\n";
        }
    }

    // Parse Integration XML if we have output
    if (!empty($integrationXmlOutput) && strpos($integrationXmlOutput, '<?xml') !== false) {
        try {
            $xml = simplexml_load_string($integrationXmlOutput);
            if ($xml !== false) {
                // Look for files with violations
                foreach ($xml->file as $file) {
                    $fileName = (string)$file['name'];

                    foreach ($file->violation as $violation) {
                        $lineNumber = (int)$violation['beginline'];
                        $message = (string)$violation;

                        // Debug: Output all violations to see what we're getting
                        echo "Integration violation: $message\n";

                        // Extract cyclomatic complexity
                        if (preg_match('/cyclomatic complexity of (\d+)/', $message, $complexityMatches)) {
                            $complexity = (int)$complexityMatches[1];
                            $integrationTotalMethods++;

                            // Calculate CRAP score (simplified: complexity^2 * (1 - coverage/100))
                            // For now, assume 0% coverage if not provided
                            $coverage = 0; // This would need to be calculated from test coverage
                            $crapScore = pow($complexity, 2) * (1 - $coverage / 100);

                            $integrationCrapScores[] = [
                                'file' => $fileName,
                                'line' => $lineNumber,
                                'complexity' => $complexity,
                                'coverage' => $coverage,
                                'crap_score' => $crapScore,
                                'message' => $message
                            ];

                            if ($crapScore > 30) { // High CRAP score threshold
                                $integrationHighCrapMethods++;
                                $integrationComplexityIssues[] = [
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
            echo "Error parsing Integration PHPMD XML: " . $e->getMessage() . "\n";
        }
    }

// Calculate metrics for Core and Integrations separately
$coreAverageCrapScore = $coreTotalMethods > 0 ? array_sum(array_column($coreCrapScores, 'crap_score')) / $coreTotalMethods : 0;
$coreMaxCrapScore = $coreTotalMethods > 0 ? max(array_column($coreCrapScores, 'crap_score')) : 0;

$integrationAverageCrapScore = $integrationTotalMethods > 0 ? array_sum(array_column($integrationCrapScores, 'crap_score')) / $integrationTotalMethods : 0;
$integrationMaxCrapScore = $integrationTotalMethods > 0 ? max(array_column($integrationCrapScores, 'crap_score')) : 0;

// Overall metrics
$totalMethods = $coreTotalMethods + $integrationTotalMethods;
$totalHighCrapMethods = $coreHighCrapMethods + $integrationHighCrapMethods;
$allCrapScores = array_merge($coreCrapScores, $integrationCrapScores);
$averageCrapScore = $totalMethods > 0 ? array_sum(array_column($allCrapScores, 'crap_score')) / $totalMethods : 0;
$maxCrapScore = $totalMethods > 0 ? max(array_column($allCrapScores, 'crap_score')) : 0;

// Generate report
echo "\n=== CRAP Score Report ===\n";
echo "\n--- CORE ---\n";
echo "Core methods analyzed: $coreTotalMethods\n";
echo "Core average CRAP score: " . number_format($coreAverageCrapScore, 2) . "\n";
echo "Core maximum CRAP score: " . number_format($coreMaxCrapScore, 2) . "\n";
echo "Core methods with high CRAP score (>30): $coreHighCrapMethods\n";

echo "\n--- INTEGRATIONS ---\n";
echo "Integration methods analyzed: $integrationTotalMethods\n";
echo "Integration average CRAP score: " . number_format($integrationAverageCrapScore, 2) . "\n";
echo "Integration maximum CRAP score: " . number_format($integrationMaxCrapScore, 2) . "\n";
echo "Integration methods with high CRAP score (>30): $integrationHighCrapMethods\n";

echo "\n--- OVERALL ---\n";
echo "Total methods analyzed: $totalMethods\n";
echo "Overall average CRAP score: " . number_format($averageCrapScore, 2) . "\n";
echo "Overall maximum CRAP score: " . number_format($maxCrapScore, 2) . "\n";
echo "Total methods with high CRAP score (>30): $totalHighCrapMethods\n";

if (!empty($coreComplexityIssues)) {
    echo "\n=== High CRAP Score Methods - CORE ===\n";
    foreach ($coreComplexityIssues as $issue) {
        echo "File: {$issue['file']}:{$issue['line']}\n";
        echo "CRAP Score: " . number_format($issue['crap_score'], 2) . "\n";
        echo "Issue: {$issue['message']}\n\n";
    }
}

if (!empty($integrationComplexityIssues)) {
    echo "\n=== High CRAP Score Methods - INTEGRATIONS ===\n";
    foreach ($integrationComplexityIssues as $issue) {
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
    $comment = generateGitHubComment($coreCrapScores, $integrationCrapScores, $coreComplexityIssues, $integrationComplexityIssues, 
                                   $coreAverageCrapScore, $coreMaxCrapScore, $coreTotalMethods, $coreHighCrapMethods,
                                   $integrationAverageCrapScore, $integrationMaxCrapScore, $integrationTotalMethods, $integrationHighCrapMethods,
                                   $averageCrapScore, $maxCrapScore, $totalMethods, $totalHighCrapMethods, []);
    
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
function generateGitHubComment($coreCrapScores, $integrationCrapScores, $coreComplexityIssues, $integrationComplexityIssues,
                              $coreAverageCrapScore, $coreMaxCrapScore, $coreTotalMethods, $coreHighCrapMethods,
                              $integrationAverageCrapScore, $integrationMaxCrapScore, $integrationTotalMethods, $integrationHighCrapMethods,
                              $averageCrapScore, $maxCrapScore, $totalMethods, $totalHighCrapMethods, $phpcpdOutput) {
    $comment = "## 🔍 CRAP Score Analysis\n\n";
    
    // Summary
    $comment .= "### 📊 Summary\n";
    $comment .= "#### Core\n";
    $comment .= "- **Methods analyzed:** $coreTotalMethods\n";
    $comment .= "- **Average CRAP score:** " . number_format($coreAverageCrapScore, 2) . "\n";
    $comment .= "- **Maximum CRAP score:** " . number_format($coreMaxCrapScore, 2) . "\n";
    $comment .= "- **High CRAP methods (>30):** $coreHighCrapMethods\n\n";
    
    $comment .= "#### Integrations\n";
    $comment .= "- **Methods analyzed:** $integrationTotalMethods\n";
    $comment .= "- **Average CRAP score:** " . number_format($integrationAverageCrapScore, 2) . "\n";
    $comment .= "- **Maximum CRAP score:** " . number_format($integrationMaxCrapScore, 2) . "\n";
    $comment .= "- **High CRAP methods (>30):** $integrationHighCrapMethods\n\n";
    
    $comment .= "#### Overall\n";
    $comment .= "- **Total methods analyzed:** $totalMethods\n";
    $comment .= "- **Overall average CRAP score:** " . number_format($averageCrapScore, 2) . "\n";
    $comment .= "- **Overall maximum CRAP score:** " . number_format($maxCrapScore, 2) . "\n";
    $comment .= "- **Total high CRAP methods (>30):** $totalHighCrapMethods\n\n";
    
    // CRAP Score Interpretation
    $comment .= "### 📈 CRAP Score Interpretation\n";
    $comment .= "- **0-5:** Low risk, well-tested\n";
    $comment .= "- **6-15:** Moderate risk\n";
    $comment .= "- **16-30:** High risk, needs refactoring\n";
    $comment .= "- **30+:** Very high risk, urgent refactoring needed\n\n";
    
    if (!empty($coreComplexityIssues)) {
        $comment .= "### ⚠️ High CRAP Score Methods - Core\n";
        $comment .= "| File | Line | CRAP Score | Issue |\n";
        $comment .= "|------|------|------------|-------|\n";
        
        foreach ($coreComplexityIssues as $issue) {
            $file = basename($issue['file']);
            $comment .= "| `$file` | {$issue['line']} | " . number_format($issue['crap_score'], 2) . " | {$issue['message']} |\n";
        }
        $comment .= "\n";
    }
    
    if (!empty($integrationComplexityIssues)) {
        $comment .= "### ⚠️ High CRAP Score Methods - Integrations\n";
        $comment .= "| File | Line | CRAP Score | Issue |\n";
        $comment .= "|------|------|------------|-------|\n";
        
        foreach ($integrationComplexityIssues as $issue) {
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
    if ($coreHighCrapMethods > 0) {
        $comment .= "#### Core Issues\n";
        $comment .= "- **Priority:** High - Core code should have low CRAP scores\n";
        $comment .= "- Consider refactoring core methods with high CRAP scores\n";
        $comment .= "- Focus on reducing cyclomatic complexity in core functionality\n";
        $comment .= "- Add unit tests to improve coverage for core methods\n\n";
    }
    
    if ($integrationHighCrapMethods > 0) {
        $comment .= "#### Integration Issues\n";
        $comment .= "- **Priority:** Medium - Integration code often has higher complexity\n";
        $comment .= "- Consider refactoring integration methods if CRAP scores are extremely high (>100)\n";
        $comment .= "- Focus on reducing cyclomatic complexity where possible\n";
        $comment .= "- Add integration tests to improve coverage\n\n";
    }
    
    if ($coreHighCrapMethods == 0 && $integrationHighCrapMethods == 0) {
        $comment .= "- ✅ No high-risk methods detected\n";
        $comment .= "- Keep up the good work!\n";
    }
    
    return $comment;
}

echo "\n=== Analysis Complete ===\n";
