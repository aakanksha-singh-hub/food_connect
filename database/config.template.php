<?php
return [
    'database' => [
        'host' => 'your_host',
        'dbname' => 'your_database_name',
        'username' => 'your_username',
        'password' => 'your_password',
        'port' => 3306,
        'charset' => 'utf8mb4'
    ],
    
    'app' => [
        'environment' => 'development', 
        'debug' => true,
        'timezone' => 'UTC'
    ],
    'security' => [
        'session_timeout' => 3600, 
        'salt' => 'your_random_salt_string'
    ]
]; 