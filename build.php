<?php
/**
 * Build script to create a prefixed version of the library
 * 
 * Usage: php build.php
 */

echo "Starting build process...\n";

// Step 1: Run php-scoper to prefix the Codeception classes
echo "Running PHP-Scoper...\n";
exec('vendor/bin/php-scoper add-prefix --output-dir=./build --force', $output, $return_var);
if ($return_var !== 0) {
    echo "Error running PHP-Scoper\n";
    exit(1);
}

// Step 2: Copy our src directory to the build directory
echo "Copying src directory...\n";
if (!file_exists('build/src')) {
    mkdir('build/src', 0755, true);
}
exec('cp -R src/* build/src/', $output, $return_var);
if ($return_var !== 0) {
    echo "Error copying src directory\n";
    exit(1);
}

// Step 3: Create composer.json in the build directory
echo "Creating composer.json in build directory...\n";
$composer_json = [
    'name' => 'uncanny-owl/developer-tools',
    'description' => 'Centralized development tools for Uncanny Owl plugins (Prefixed version)',
    'type' => 'library',
    'license' => 'GPL-3.0-or-later',
    'authors' => [
        [
            'name' => 'Uncanny Automator',
            'email' => 'support@uncannyautomator.com'
        ]
    ],
    'autoload' => [
        'psr-4' => [
            'UncannyOwl\\DevTools\\' => 'src/',
            'UncannyOwl\\DevTools\\Vendor\\Codeception\\' => 'codeception/codeception/',
            'UncannyOwl\\DevTools\\Vendor\\tad\\WPBrowser\\' => 'lucatume/wp-browser/src/'
        ]
    ],
    'minimum-stability' => 'dev',
    'prefer-stable' => true
];
file_put_contents('build/composer.json', json_encode($composer_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Step 4: Generate autoload files
echo "Generating autoload files...\n";
exec('composer dump-autoload -d build', $output, $return_var);
if ($return_var !== 0) {
    echo "Error generating autoload files\n";
    exit(1);
}

// Step 5: Create a bootstrap file
echo "Creating bootstrap file...\n";
$bootstrap_content = <<<EOT
<?php
/**
 * Bootstrap file for prefixed dependencies
 */

// Define the base directory
define('UNCANNY_DEVTOOLS_PREFIXED_DIR', __DIR__);

// Function to load a prefixed class
function uncanny_devtools_load_prefixed_class(\$class) {
    // Only handle our prefixed namespace
    if (strpos(\$class, 'UncannyOwl\\\\DevTools\\\\Vendor\\\\') === 0) {
        // Remove the prefix
        \$relative_class = str_replace('UncannyOwl\\\\DevTools\\\\Vendor\\\\', '', \$class);
        
        // Convert namespace separators to directory separators
        \$file = str_replace('\\\\', '/', \$relative_class) . '.php';
        
        // Look in potential directories
        \$potential_paths = [
            UNCANNY_DEVTOOLS_PREFIXED_DIR . '/codeception/codeception/' . \$file,
            UNCANNY_DEVTOOLS_PREFIXED_DIR . '/lucatume/wp-browser/src/' . \$file,
        ];
        
        foreach (\$potential_paths as \$path) {
            if (file_exists(\$path)) {
                require_once \$path;
                return true;
            }
        }
    }
    
    return false;
}

// Register the autoloader
spl_autoload_register('uncanny_devtools_load_prefixed_class');

// Load the standard autoloader for the UncannyOwl\DevTools namespace
require_once __DIR__ . '/vendor/autoload.php';

EOT;
file_put_contents('build/bootstrap.php', $bootstrap_content);

echo "Build completed successfully!\n";
echo "To use the prefixed version, require 'build/bootstrap.php' in your project.\n"; 