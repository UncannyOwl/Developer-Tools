<?php
/**
 * Simple platform detection script
 * 
 * This script outputs whether the current platform is Windows or not.
 * It's used by composer scripts to determine which commands to run.
 */

// Check if we're on Windows
$isWindows = PHP_OS_FAMILY === 'Windows';

// Output the result - this will be captured by the composer script
echo $isWindows ? 'windows' : 'unix'; 