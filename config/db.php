<?php
// Database connection. The driver is auto-detected:
//   1. DATABASE_URL set  -> PostgreSQL (Render deployment)
//   2. CLOUDDESK_SQLITE set -> SQLite file (zero-setup demo / automated tests)
//   3. otherwise -> MySQL/MariaDB (XAMPP, WAMP, MAMP) using the settings in config.php
//
// On first run the database (MySQL only), all tables and the demo data are created
// automatically, so a fresh XAMPP install works with no manual import. Importing
// schema.sql yourself in phpMyAdmin also works and is safe to do.

require_once __DIR__ . '/config.php';

function dbDriver(): string {
    if (getenv('DATABASE_URL') !== false && getenv('DATABASE_URL') !== '') return 'pgsql';
    if (getenv('CLOUDDESK_SQLITE') !== false && getenv('CLOUDDESK_SQLITE') !== '') return 'sqlite';
    return 'mysql';
}

function isPostgres(): bool { return dbDriver() === 'pgsql'; }

function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $driver = dbDriver();

    try {
        if ($driver === 'pgsql') {
            $parts = parse_url((string)getenv('DATABASE_URL'));
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $parts['host'], $parts['port'] ?? 5432, ltrim($parts['path'], '/'));
            $pdo = new PDO($dsn, $parts['user'] ?? null, $parts['pass'] ?? null, $opts);
        } elseif ($driver === 'sqlite') {
            $pdo = new PDO('sqlite:' . getenv('CLOUDDESK_SQLITE'), null, null, $opts);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            // Create the database if it doesn't exist yet, then connect to it.
            $server = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, $opts);
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $opts);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        die('<h3>Database connection failed</h3><p>' . htmlspecialchars($e->getMessage()) .
            '</p><p>Make sure MySQL is running in XAMPP and that DB_PORT / DB_USER / DB_PASS in <code>config/config.php</code> ' .
            '(or <code>config/config.local.php</code>) match your setup.</p>');
    }

    require_once __DIR__ . '/../includes/db_setup.php';
    ensureSchemaAndSeed($pdo);
    return $pdo;
}

/** INSERT and return the new id on every driver. $sql must not contain RETURNING. */
function insertAndGetId(PDO $db, string $sql, array $params): int {
    if (isPostgres()) {
        $stmt = $db->prepare(rtrim($sql, "; \n") . ' RETURNING id');
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$db->lastInsertId();
}