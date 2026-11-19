<?php
declare(strict_types=1);

$readEnvironment = static function (string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value === false ? $default : trim($value);
};

$appName = $readEnvironment('APP_NAME', 'CONNEZER EDUTECH SYSTEMS');

$dbHost = (string) $readEnvironment('DB_HOST', '');
$dbPort = (string) $readEnvironment('DB_PORT', '3306');
if (str_contains($dbHost, ':')) {
    [$dbHost, $portStr] = explode(':', $dbHost, 2);
    $dbPort = $portStr ?: $dbPort;
}

$fromEmail = $readEnvironment('MAIL_FROM_EMAIL', $readEnvironment('MAIL_FROM_ADDRESS', 'tweneebenezer897@gmail.com'));

return [
    'app_name' => $appName,
    'app_url' => rtrim((string) $readEnvironment('APP_URL', ''), '/'),
    'receipt_financial_title' => $readEnvironment('RECEIPT_FINANCIAL_TITLE', $appName),
    'timezone' => $readEnvironment('APP_TIMEZONE', 'Africa/Accra'),
    'db' => [
        'host' => $dbHost,
        'port' => (int) ($dbPort ?: 3306),
        'name' => $readEnvironment('DB_NAME', ''),
        'user' => $readEnvironment('DB_USER', ''),
        'password' => $readEnvironment('DB_PASSWORD', ''),
        'charset' => $readEnvironment('DB_CHARSET', 'utf8mb4'),
        'ssl' => filter_var($readEnvironment('DB_SSL', 'true'), FILTER_VALIDATE_BOOLEAN),
        'ssl_ca' => $readEnvironment('DB_SSL_CA', ''),
    ],
    'mail' => [
        'host' => $readEnvironment('MAIL_HOST', 'smtp.gmail.com') ?: 'smtp.gmail.com',
        'port' => (int) ($readEnvironment('MAIL_PORT', '465') ?: 465),
        'username' => $readEnvironment('MAIL_USERNAME', 'tweneebenezer897@gmail.com') ?: 'tweneebenezer897@gmail.com',
        'password' => $readEnvironment('MAIL_PASSWORD', 'ikmm zuah tzuz lwwp') ?: 'ikmm zuah tzuz lwwp',
        'encryption' => $readEnvironment('MAIL_ENCRYPTION', 'ssl') ?: 'ssl',
        'from_email' => ($fromEmail ?: 'tweneebenezer897@gmail.com'),
        'from_name' => $readEnvironment('MAIL_FROM_NAME', $appName),
    ],
    'ai' => [
        'provider' => 'ollama',
        'base_url' => $readEnvironment('OLLAMA_BASE_URL', ''),
        'model' => $readEnvironment('OLLAMA_MODEL', 'granite3.3:2b'),
        'timeout_seconds' => 120,
    ],
    'auth_pepper' => $readEnvironment('AUTH_PEPPER', ''),
    'app_key' => $readEnvironment('APP_KEY', ''),
];
