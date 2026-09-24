<?php
/**
 * health.php — Health check endpoint for container orchestrators and PaaS
 * (HostForge, Cloud Run, Kubernetes, Render, etc.).
 *
 * Returns HTTP 200 OK without database dependencies to reliably confirm
 * the container web server and PHP engine are alive and accepting requests.
 */
http_response_code(200);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo json_encode([
    'status'    => 'healthy',
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
], JSON_UNESCAPED_SLASHES);
exit;
