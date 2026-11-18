<?php
declare(strict_types=1);

function school_support_schema(): array
{
    static $schema;
    if ($schema !== null) {
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
    return $schema;
}

function school_support_has_column(array $columns, string $column): bool
{
    return isset($columns[$column]);
}
