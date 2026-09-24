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

    $portPart = (defined('DB_PORT') && DB_PORT) ? ";port=" . DB_PORT : "";
    $dsn = "mysql:host=" . DB_HOST . $portPart . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]);
        // Authentication expirations and audit timestamps are UTC regardless
        // of the hosting provider's server timezone.
        $pdo->exec("SET time_zone = '+00:00'");

        // Auto-seed schema on first run if database is brand new (e.g. HostForge provisioned DB)
        static $schemaChecked = false;
        if (!$schemaChecked) {
            $schemaChecked = true;
            try {
                $check = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
                if (!$check) {
                    $schemaFile = dirname(__DIR__) . '/database/schema.sql';
                    if (file_exists($schemaFile) && is_readable($schemaFile)) {
                        $sql = file_get_contents($schemaFile);
                        $pdo->exec($sql);
                        error_log('Database schema successfully auto-initialized from schema.sql');
                    }
                }
            } catch (Throwable $schemaEx) {
                error_log('Schema auto-init notice: ' . $schemaEx->getMessage());
            }
        }
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        error_log('DB connection failed: ' . $e->getMessage());
        $response = [
            'success' => false,
            'error'   => 'Database connection failed. Check includes/config.php.',
        ];
        if (defined('APP_IS_LOCAL') && APP_IS_LOCAL) {
            $response['details'] = $e->getMessage();
            $response['hint'] = 'Running locally? Check if your local MySQL/XAMPP service is started and DB_HOST is set to localhost or 127.0.0.1 in .env.';
        }
        echo json_encode($response);
        exit;
    }

    return $pdo;
}
