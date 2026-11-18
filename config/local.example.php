<?php
declare(strict_types=1);

return [
    'app_name' => 'CONNEZER EDUTECH SYSTEMS',
    'app_url' => 'http://localhost/public',
    'receipt_financial_title' => 'Financial Controller',
    'receipt_financial_title' => 'Financial Controller',
    'timezone' => 'Africa/Accra',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'secure_auth_system',
        'user' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'host' => '',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls',
        'from_email' => '',
        'from_name' => 'CONNEZER EDUTECH SYSTEMS',
    ],
    'ai' => [
        'provider' => 'ollama',
        'base_url' => 'http://127.0.0.1:11434',
        'model' => 'granite3.3:2b',
        'timeout_seconds' => 120,
    ],
    // Generate unique random values for both keys. Keep this file outside public/.
    'auth_pepper' => 'REPLACE_WITH_A_LONG_RANDOM_SECRET',
    'app_key' => 'REPLACE_WITH_A_DIFFERENT_32_BYTE_RANDOM_SECRET',
];
