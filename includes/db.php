<?php
/**
 * db.php — single shared PDO connection.
 *
 * Uses prepared statements everywhere in this codebase (never string-
 * concatenated SQL) to prevent SQL injection.
 */

require_once __DIR__ . '/config.php';

function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        // Don't leak DB credentials/connection details in the response —
        // log the real error server-side, show a generic message to the client.
        error_log('DB connection failed: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error'   => 'Database connection failed. Check includes/config.php.',
        ]);
        exit;
    }

    return $pdo;
}
