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

// Check if we're running in PR mode or verbose mode
$isPrMode = in_array('--pr', $argv);
$isVerboseMode = in_array('--verbose', $argv) || in_array('-v', $argv);
$showHelp = in_array('--help', $argv) || in_array('-h', $argv);

if ($showHelp) {
    echo "CRAP Score Analysis Tool\n\n";
    echo "Usage: php crap-score.php [options]\n\n";
    echo "Options:\n";
    echo "  --pr        Analyze only changed files in PR\n";
    echo "  --verbose   Show detailed method-by-method output\n";
    echo "  --help      Show this help message\n\n";
    echo "Examples:\n";
    echo "  php crap-score.php                    # Analyze all files, summary only\n";
    echo "  php crap-score.php --verbose          # Analyze all files, detailed output\n";
    echo "  php crap-score.php --pr               # Analyze PR changes only\n";
    echo "  php crap-score.php --pr --verbose     # Analyze PR changes with details\n";
    exit(0);
}

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
        // Use the same approach as phpcs:pr - compare against origin/pre-release
        $command = 'git diff --name-only origin/pre-release...';
        echo "Using git command: $command\n";
        
        $output = [];
        $returnVar = 0;
        exec($command . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0 || empty($output)) {
            echo "Warning: Could not get git diff, falling back to full analysis\n";
            $filesToAnalyze = [];
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
        if (!empty($filesToAnalyze)) {
            echo "Files to analyze:\n";
            foreach ($filesToAnalyze as $file) {
                echo "  - $file\n";
            }
        }
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
    
    // Note: We'll handle empty file lists in the PHPMD commands below
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
    if (empty($coreFiles)) {
        echo "No core files to analyze.\n";
        $corePhpmdOutput = [];
        $corePhpmdReturnVar = 0;
    } else {
        $corePhpmdCommand = 'php -d memory_limit=' . $memoryLimit . ' ' . escapeshellarg($phpmdBin) . ' ' .
                            implode(' ', array_map('escapeshellarg', $coreFiles)) .
                            ' xml cleancode,codesize,controversial,design,naming,unusedcode --exclude vendor,tests,node_modules,src/integrations';

        echo "Command: $corePhpmdCommand\n";
        $corePhpmdOutput = [];
        $corePhpmdReturnVar = 0;
        exec($corePhpmdCommand . ' 2>&1', $corePhpmdOutput, $corePhpmdReturnVar);
    }

    // Run PHPMD analysis for Integrations
    echo "\n=== Running PHPMD Analysis - Integrations ===\n";
    if (empty($integrationFiles)) {
        echo "No integration files to analyze.\n";
        $integrationPhpmdOutput = [];
        $integrationPhpmdReturnVar = 0;
    } else {
        echo "Files being analyzed: " . count($integrationFiles) . " files\n";
        $integrationPhpmdOutput = [];
        $integrationPhpmdReturnVar = 0;
        
        // Run PHPMD on each file individually to avoid command line length limits
        foreach ($integrationFiles as $file) {
            if (!file_exists($file)) {
                echo "Warning: File $file does not exist, skipping.\n";
                continue;
            }
            
            $fileCommand = 'php -d memory_limit=' . $memoryLimit . ' ' . escapeshellarg($phpmdBin) . ' ' .
                          escapeshellarg($file) .
                          ' xml cleancode,codesize,controversial,design,naming,unusedcode';
            
            echo "Analyzing: $file\n";
            $fileOutput = [];
            $fileReturnVar = 0;
            exec($fileCommand . ' 2>&1', $fileOutput, $fileReturnVar);
            
            // Debug: Show first few lines of output
            if (!empty($fileOutput)) {
                echo "  PHPMD output (first 3 lines):\n";
                foreach (array_slice($fileOutput, 0, 3) as $line) {
                    echo "    $line\n";
                }
            } else {
                echo "  No PHPMD output for this file\n";
            }
            
            // Merge output (skip XML header for subsequent files)
            if (empty($integrationPhpmdOutput)) {
                $integrationPhpmdOutput = $fileOutput;
            } else {
                // Remove XML header and footer from subsequent files
                $fileOutput = array_filter($fileOutput, function($line) {
                    return !preg_match('/^<\?xml|^<\/pmd>|^<pmd/', $line);
                });
                $integrationPhpmdOutput = array_merge($integrationPhpmdOutput, $fileOutput);
            }
        }
        
        // Add closing XML tag if we have output
        if (!empty($integrationPhpmdOutput)) {
            $integrationPhpmdOutput[] = '</pmd>';
        }
    }

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

    // Debug: Output Core XML for inspection (commented out)
    // echo "\n=== Core PHPMD Raw Output (first 20 lines) ===\n";
    // foreach (array_slice($corePhpmdOutput, 0, 20) as $line) {
    //     echo "$line\n";
    // }
    // echo "\n=== Core XML Output ===\n";
    // echo $coreXmlOutput . "\n";

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

    // Debug: Output Integration XML for inspection (commented out)
    // echo "\n=== Integration PHPMD Raw Output (first 20 lines) ===\n";
    // foreach (array_slice($integrationPhpmdOutput, 0, 20) as $line) {
    //     echo "$line\n";
    // }
    // echo "\n=== Integration XML Output ===\n";
    // echo $integrationXmlOutput . "\n";

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

                        // Debug: Output all violations to see what we're getting (commented out)
                        // echo "Core violation: $message\n";

                        // Extract cyclomatic complexity
                        if (preg_match('/has a Cyclomatic Complexity of (\d+)/', $message, $complexityMatches)) {
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

                            if ($crapScore > 100) { // High CRAP score threshold for legacy project
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

                        // Debug: Output all violations to see what we're getting (commented out)
                        // echo "Integration violation: $message\n";

                        // Extract cyclomatic complexity
                        if (preg_match('/has a Cyclomatic Complexity of (\d+)/', $message, $complexityMatches)) {
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

                            if ($crapScore > 100) { // High CRAP score threshold for legacy project
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

// Calculate total CRAP score sum
$coreTotalCrapScore = array_sum(array_column($coreCrapScores, 'crap_score'));
$integrationTotalCrapScore = array_sum(array_column($integrationCrapScores, 'crap_score'));
$overallTotalCrapScore = $coreTotalCrapScore + $integrationTotalCrapScore;

// Generate report
echo "\n=== CRAP Score Report ===\n";
echo "\n--- CORE ---\n";
echo "Core methods analyzed: $coreTotalMethods\n";
echo "Core total CRAP score: " . number_format($coreTotalCrapScore, 2) . "\n";
echo "Core average CRAP score: " . number_format($coreAverageCrapScore, 2) . "\n";
echo "Core maximum CRAP score: " . number_format($coreMaxCrapScore, 2) . "\n";
echo "Core methods with high CRAP score (>100): $coreHighCrapMethods\n";

echo "\n--- INTEGRATIONS ---\n";
echo "Integration methods analyzed: $integrationTotalMethods\n";
echo "Integration total CRAP score: " . number_format($integrationTotalCrapScore, 2) . "\n";
echo "Integration average CRAP score: " . number_format($integrationAverageCrapScore, 2) . "\n";
echo "Integration maximum CRAP score: " . number_format($integrationMaxCrapScore, 2) . "\n";
echo "Integration methods with high CRAP score (>100): $integrationHighCrapMethods\n";

echo "\n--- OVERALL ---\n";
echo "Total methods analyzed: $totalMethods\n";
echo "TOTAL CRAP SCORE: " . number_format($overallTotalCrapScore, 2) . "\n";
echo "Overall average CRAP score: " . number_format($averageCrapScore, 2) . "\n";
echo "Overall maximum CRAP score: " . number_format($maxCrapScore, 2) . "\n";
echo "Total methods with high CRAP score (>100): $totalHighCrapMethods\n";

// Show individual methods only in verbose mode
if ($isVerboseMode) {
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
} else {
    // Show summary of high CRAP methods
    if ($coreHighCrapMethods > 0 || $integrationHighCrapMethods > 0) {
        echo "\n=== High CRAP Score Methods Summary ===\n";
        echo "Core methods with high CRAP score (>100): $coreHighCrapMethods\n";
        echo "Integration methods with high CRAP score (>100): $integrationHighCrapMethods\n";
        echo "Use --verbose flag to see individual method details\n";
    }
    
    // Show cyclomatic complexity methods for AI assistance
    echo "\n=== Cyclomatic Complexity Methods (for AI refactoring) ===\n";
    
    // Core cyclomatic complexity methods
    if (!empty($coreComplexityIssues)) {
        echo "\n--- Core Methods ---\n";
        foreach ($coreComplexityIssues as $issue) {
            echo "File: {$issue['file']}\n";
            echo "Line: {$issue['line']}\n";
            echo "Method: {$issue['method']}\n";
            echo "Complexity: {$issue['complexity']}\n";
            echo "CRAP Score: {$issue['crap_score']}\n";
            echo "---\n";
        }
    }
    
    // Integration cyclomatic complexity methods
    if (!empty($integrationComplexityIssues)) {
        echo "\n--- Integration Methods ---\n";
        foreach ($integrationComplexityIssues as $issue) {
            echo "File: {$issue['file']}\n";
            echo "Line: {$issue['line']}\n";
            echo "Method: {$issue['method']}\n";
            echo "Complexity: {$issue['complexity']}\n";
            echo "CRAP Score: {$issue['crap_score']}\n";
            echo "---\n";
        }
    }
    
    if (empty($coreComplexityIssues) && empty($integrationComplexityIssues)) {
        echo "No cyclomatic complexity issues found.\n";
    }
    
    // Show before/after comparison for refactoring assessment
    if ($isPrMode && !empty($filesToAnalyze)) {
        echo "\n=== Refactoring Impact Analysis ===\n";
        echo "Comparing CRAP scores: Base Branch vs PR\n";
        echo "Files analyzed: " . count($filesToAnalyze) . "\n\n";
        
        // Get base branch CRAP scores for comparison
        $baseBranchScores = getBaseBranchCrapScores($filesToAnalyze, $phpmdBin, $memoryLimit);
        
        if (!empty($baseBranchScores)) {
            echo "--- Before/After Comparison ---\n";
            $totalImprovement = 0;
            $filesImproved = 0;
            $filesWorsened = 0;
            
            foreach ($filesToAnalyze as $file) {
                if (file_exists($file)) {
                    $currentFile = basename($file);
                    $currentScore = 0;
                    $baseScore = isset($baseBranchScores[$file]) ? $baseBranchScores[$file] : 0;
                    
                    // Find current score for this file
                    foreach (array_merge($coreComplexityIssues, $integrationComplexityIssues) as $issue) {
                        if (strpos($issue['file'], $currentFile) !== false) {
                            $currentScore += $issue['crap_score'];
                        }
                    }
                    
                    $improvement = $baseScore - $currentScore;
                    $totalImprovement += $improvement;
                    
                    if ($improvement > 0) {
                        $filesImproved++;
                        echo "✅ $currentFile: $baseScore → $currentScore (Improved by $improvement)\n";
                    } elseif ($improvement < 0) {
                        $filesWorsened++;
                        echo "❌ $currentFile: $baseScore → $currentScore (Worsened by " . abs($improvement) . ")\n";
                    } else {
                        echo "➖ $currentFile: $baseScore → $currentScore (No change)\n";
                    }
                }
            }
            
            echo "\n--- Overall Assessment ---\n";
            echo "Total CRAP score change: " . ($totalImprovement >= 0 ? "+" : "") . number_format($totalImprovement, 2) . "\n";
            echo "Files improved: $filesImproved\n";
            echo "Files worsened: $filesWorsened\n";
            echo "Files unchanged: " . (count($filesToAnalyze) - $filesImproved - $filesWorsened) . "\n";
            
            if ($totalImprovement > 0) {
                echo "🎉 Overall: Code quality IMPROVED by " . number_format($totalImprovement, 2) . " CRAP points\n";
            } elseif ($totalImprovement < 0) {
                echo "⚠️  Overall: Code quality WORSENED by " . number_format(abs($totalImprovement), 2) . " CRAP points\n";
            } else {
                echo "➖ Overall: No net change in code quality\n";
            }
        } else {
            echo "Could not retrieve base branch scores for comparison.\n";
        }
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
                                   $coreAverageCrapScore, $coreMaxCrapScore, $coreTotalMethods, $coreHighCrapMethods, $coreTotalCrapScore,
                                   $integrationAverageCrapScore, $integrationMaxCrapScore, $integrationTotalMethods, $integrationHighCrapMethods, $integrationTotalCrapScore,
                                   $averageCrapScore, $maxCrapScore, $totalMethods, $totalHighCrapMethods, $overallTotalCrapScore, []);
    
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
// Function to get base branch CRAP scores for comparison
function getBaseBranchCrapScores($filesToAnalyze, $phpmdBin, $memoryLimit) {
    echo "Analyzing base branch (origin/pre-release) for comparison...\n";
    
    $baseScores = [];
    
    // Stash current changes temporarily
    $stashOutput = [];
    $stashReturnVar = 0;
    exec('git stash push -m "temp-stash-for-crap-analysis" 2>&1', $stashOutput, $stashReturnVar);
    
    if ($stashReturnVar !== 0) {
        echo "Warning: Could not stash changes, skipping base branch analysis\n";
        return $baseScores;
    }
    
    try {
        // Analyze each file in the base branch
        foreach ($filesToAnalyze as $file) {
            if (file_exists($file)) {
                $command = 'php -d memory_limit=' . $memoryLimit . ' ' . escapeshellarg($phpmdBin) . ' ' .
                          escapeshellarg($file) . ' xml cleancode,codesize,controversial,design,naming,unusedcode';
                
                $output = [];
                $returnVar = 0;
                exec($command . ' 2>&1', $output, $returnVar);
                
                if ($returnVar === 0 && !empty($output)) {
                    $xml = implode("\n", $output);
                    if (strpos($xml, '<?xml') !== false) {
                        $fileScore = 0;
                        try {
                            $xmlObj = simplexml_load_string($xml);
                            if ($xmlObj !== false) {
                                foreach ($xmlObj->file as $fileNode) {
                                    foreach ($fileNode->violation as $violation) {
                                        $message = (string)$violation;
                                        if (preg_match('/has a Cyclomatic Complexity of (\d+)/', $message, $matches)) {
                                            $complexity = (int)$matches[1];
                                            $crapScore = pow($complexity, 2) * (1 - 0); // Assuming 0% coverage
                                            $fileScore += $crapScore;
                                        }
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            // Ignore XML parsing errors
                        }
                        $baseScores[$file] = $fileScore;
                    }
                }
            }
        }
    } catch (Exception $e) {
        echo "Error analyzing base branch: " . $e->getMessage() . "\n";
    }
    
    // Restore stashed changes
    exec('git stash pop 2>&1', $restoreOutput, $restoreReturnVar);
    
    echo "Base branch analysis complete. Found scores for " . count($baseScores) . " files.\n";
    return $baseScores;
}

function generateGitHubComment($coreCrapScores, $integrationCrapScores, $coreComplexityIssues, $integrationComplexityIssues,
                              $coreAverageCrapScore, $coreMaxCrapScore, $coreTotalMethods, $coreHighCrapMethods, $coreTotalCrapScore,
                              $integrationAverageCrapScore, $integrationMaxCrapScore, $integrationTotalMethods, $integrationHighCrapMethods, $integrationTotalCrapScore,
                              $averageCrapScore, $maxCrapScore, $totalMethods, $totalHighCrapMethods, $overallTotalCrapScore, $phpcpdOutput) {
    $comment = "## 🔍 CRAP Score Analysis\n\n";
    
    // Summary
    $comment .= "### 📊 Summary\n";
    $comment .= "#### Core\n";
    $comment .= "- **Methods analyzed:** $coreTotalMethods\n";
    $comment .= "- **Total CRAP score:** " . number_format($coreTotalCrapScore, 2) . "\n";
    $comment .= "- **Average CRAP score:** " . number_format($coreAverageCrapScore, 2) . "\n";
    $comment .= "- **Maximum CRAP score:** " . number_format($coreMaxCrapScore, 2) . "\n";
    $comment .= "- **High CRAP methods (>100):** $coreHighCrapMethods\n\n";
    
    $comment .= "#### Integrations\n";
    $comment .= "- **Methods analyzed:** $integrationTotalMethods\n";
    $comment .= "- **Total CRAP score:** " . number_format($integrationTotalCrapScore, 2) . "\n";
    $comment .= "- **Average CRAP score:** " . number_format($integrationAverageCrapScore, 2) . "\n";
    $comment .= "- **Maximum CRAP score:** " . number_format($integrationMaxCrapScore, 2) . "\n";
    $comment .= "- **High CRAP methods (>100):** $integrationHighCrapMethods\n\n";
    
    $comment .= "#### Overall\n";
    $comment .= "- **Total methods analyzed:** $totalMethods\n";
    $comment .= "- **TOTAL CRAP SCORE:** " . number_format($overallTotalCrapScore, 2) . "\n";
    $comment .= "- **Overall average CRAP score:** " . number_format($averageCrapScore, 2) . "\n";
    $comment .= "- **Overall maximum CRAP score:** " . number_format($maxCrapScore, 2) . "\n";
    $comment .= "- **Total high CRAP methods (>100):** $totalHighCrapMethods\n\n";
    
    // CRAP Score Interpretation
    $comment .= "### 📈 CRAP Score Guidelines\n";
    $comment .= "#### 🎯 Ideal Targets (New Code)\n";
    $comment .= "- **0-5:** Excellent - Low risk, well-tested\n";
    $comment .= "- **6-15:** Good - Moderate risk, acceptable\n";
    $comment .= "- **16-30:** Needs attention - Consider refactoring\n";
    $comment .= "- **30+:** High risk - Should be refactored\n\n";
    
    $comment .= "#### 📊 Acceptable Baselines (Legacy Code)\n";
    $comment .= "- **0-30:** ✅ Acceptable for legacy code\n";
    $comment .= "- **31-100:** ⚠️ Monitor - Consider refactoring when touching\n";
    $comment .= "- **101-500:** 🔶 High priority - Refactor when possible\n";
    $comment .= "- **500+:** 🚨 Critical - Refactor urgently when touched\n\n";
    
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
        $comment .= "- **New code:** Aim for CRAP scores < 30\n";
        $comment .= "- **Legacy code:** Refactor when touching methods with CRAP > 100\n";
        $comment .= "- **Critical methods (CRAP > 500):** Refactor urgently when modified\n";
        $comment .= "- Focus on reducing cyclomatic complexity in core functionality\n";
        $comment .= "- Add unit tests to improve coverage for core methods\n\n";
    }
    
    if ($integrationHighCrapMethods > 0) {
        $comment .= "#### Integration Issues\n";
        $comment .= "- **Priority:** Medium - Integration code naturally has higher complexity\n";
        $comment .= "- **New integrations:** Aim for CRAP scores < 50\n";
        $comment .= "- **Legacy integrations:** Refactor when touching methods with CRAP > 200\n";
        $comment .= "- **Critical integrations (CRAP > 1000):** Refactor urgently when modified\n";
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
