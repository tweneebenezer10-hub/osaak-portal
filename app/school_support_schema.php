<?php
declare(strict_types=1);

function school_support_schema(): array
{
    static $schema;
    if ($schema !== null) {
        return $schema;
    }
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['_schema_support']) && is_array($_SESSION['_schema_support'])) {
        $schema = $_SESSION['_schema_support'];
        return $schema;
    }

    $schema = [];
    foreach ([
        'tickets' => 'school_support_tickets',
        'messages' => 'school_support_messages',
    ] as $key => $table) {
        $columns = db()->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
        $schema[$key] = array_fill_keys(array_map('strval', $columns), true);
    }
    if (session_status() === PHP_SESSION_ACTIVE
        && isset($schema['tickets']['read_at'], $schema['tickets']['replied_at'], $schema['messages']['read_at'], $schema['messages']['read_by_user_id'], $schema['messages']['request_key'])) {
        $_SESSION['_schema_support'] = $schema;
    }
    return $schema;
}

function school_support_has_column(array $columns, string $column): bool
{
    return isset($columns[$column]);
}
