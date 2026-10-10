<?php
// Usage: php tools/export_schema.php mysql > schema.sql   (or pgsql)
require __DIR__ . '/../includes/db_setup.php';
$driver = $argv[1] ?? 'mysql';
if ($driver === 'mysql') {
    echo "-- CloudDesk Web — MySQL / MariaDB schema.\n-- Optional: the site creates all of this automatically on first visit.\n-- Import via phpMyAdmin (Import tab) if you prefer to do it by hand.\n-- Demo data is added automatically by the site the first time it runs (12+ users, tickets, comments, history, chat, notifications).\n\n";
    echo "CREATE DATABASE IF NOT EXISTS clouddesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nUSE clouddesk;\n\n";
} else {
    echo "-- CloudDesk Web — PostgreSQL schema (Render). Optional: the site creates it automatically on first visit.\n\n";
}
foreach (schemaStatements($driver) as $sql) {
    $sql = preg_replace('/\n\s{4,8}/', "\n    ", $sql);
    echo $sql . ";\n\n";
}
