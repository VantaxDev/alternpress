<?php
declare(strict_types=1);

// AlternPress defaults. Put your own site settings in config.local.php.
// No build step, database, package manager, CDN or external service is needed.
return [
    'name' => 'AlternPress',
    'tagline' => ['it' => 'Solo per chi ha qualcosa da dire.', 'en' => 'For those who have something to say.'],
    'description' => ['it' => 'Un blog semplice, indipendente, tuo.', 'en' => 'A simple, independent blog of your own.'],
    'email' => '',
    'legal' => '',
    'logo_mark' => '>_',
    'languages' => ['it', 'en'],
    'default_language' => 'it',
    'timezone' => 'Europe/Rome',
    // Leave empty/null to work unchanged at / or in any subdirectory.
    'origin' => '',
    'base_path' => null,
    'site_url' => '', // Optional public canonical URL, including the site's folder.
    'custom_css' => '', // Managed from Settings; applied only to the public blog.
    'search_indexing' => true,
    'posts_per_page' => 12,
    'upload_max_mb' => 8,
    'image_max_pixels' => 24000000,
    'image_max_width' => 2400,
    'session_hours' => 8,
    'navigation' => ['/' => 'home', '/skills' => 'skills', '/archive' => 'articles', '/about' => 'contact'],
];
