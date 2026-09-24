<?php
/**
 * health.php — Health check endpoint for container orchestrators and PaaS
 * (HostForge, Cloud Run, Kubernetes, Render, etc.).
 *
 * Always returns HTTP 200 OK so container health probes pass, while reporting
 * database connectivity status for diagnostic purposes.
 */
http_response_code(200);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/includes/config.php';

$dbStatus = 'untested';
$dbError = null;

try {
    $portPart = (defined('DB_PORT') && DB_PORT) ? ";port=" . DB_PORT : "";
    $dsn = "mysql:host=" . DB_HOST . $portPart . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3,
    ]);
    $dbStatus = 'connected';
} catch (Throwable $e) {
    $dbStatus = 'failed';
    $dbError = $e->getMessage();
}

echo json_encode([
    'status'    => 'healthy',
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'db_status' => $dbStatus,
    'db_host'   => defined('DB_HOST') ? DB_HOST : null,
    'db_port'   => defined('DB_PORT') ? DB_PORT : null,
    'db_name'   => defined('DB_NAME') ? DB_NAME : null,
    'db_user'   => defined('DB_USER') ? DB_USER : null,
    'db_error'  => $dbError,
], JSON_UNESCAPED_SLASHES);
exit;
