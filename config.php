<?php
// Environment configuration
$environment = getenv('ENV') ?: 'development';

// Database configuration
$config = [
    'development' => [
        'db_host' => 'sql12.freesqldatabase.com',
        'db_name' => 'sql12773816',
        'db_user' => 'sql12773816',
        'db_pass' => '2lTRg4j3fH',
        'db_port' => 3306,
        'base_url' => 'http://localhost/Project'
    ],
    'production' => [
        'db_host' => getenv('DB_HOST') ?: 'sql12.freesqldatabase.com',
        'db_name' => getenv('DB_NAME') ?: 'sql12773816',
        'db_user' => getenv('DB_USER') ?: 'sql12773816',
        'db_pass' => getenv('DB_PASS') ?: '2lTRg4j3fH',
        'db_port' => getenv('DB_PORT') ?: 3306,
        'base_url' => getenv('BASE_URL') ?: 'https://food-connect-ten.vercel.app/'
    ]
];

// Get current environment configuration
$current_config = $config[$environment];

// Constants
define('DB_HOST', $current_config['db_host']);
define('DB_NAME', $current_config['db_name']);
define('DB_USER', $current_config['db_user']);
define('DB_PASS', $current_config['db_pass']);
define('DB_PORT', $current_config['db_port']);
define('BASE_URL', $current_config['base_url']);

// Error reporting
if ($environment === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
?> 