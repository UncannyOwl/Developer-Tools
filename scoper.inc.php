<?php

declare(strict_types=1);

// For more information about the configuration:
// https://github.com/humbug/php-scoper/blob/master/docs/configuration.md

return [
    'prefix' => 'UncannyOwl\\DevTools\\Vendor',
    
    'patchers' => [],
    
    'exclude-namespaces' => [
        'UncannyOwl\\DevTools\\*',
    ],
    
    'exclude-files' => [
        // No files to exclude
    ],
    
    'expose-global-constants' => false,
    'expose-global-classes' => false,
    'expose-global-functions' => false,
    
    // Explicitly find and prefix Codeception classes
    'finders' => [
        \Isolated\Symfony\Component\Finder\Finder::create()
            ->files()
            ->in('vendor/codeception')
            ->in('vendor/lucatume'),
    ],
]; 