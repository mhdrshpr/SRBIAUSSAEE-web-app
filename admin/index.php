<?php
/* Admin entry: serves the admin SPA shell + all /api/* endpoints. */

try {
    require_once __DIR__ . '/../includes/bootstrap.php';
    require_once __DIR__ . '/../includes/admin.php';
} catch (ParseError | Error $ex) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $msg = 'PHP Load Error: ' . $ex->getMessage() . ' in ' . basename($ex->getFile()) . ':' . $ex->getLine();
    error_log('[LOAD] ' . $msg);
    echo json_encode(['status' => 'error', 'err' => 1005, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

app_boot();

if (isset($_GET['api'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
        exit;
    }
    admin_api_handle();
}

boot_session();
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none';");

$html = file_get_contents(__DIR__ . '/app.html');
$boot = 'window.ADMIN = { csrf: ' . json_encode(csrf_token()) . ' };';
echo str_replace('__ADMIN_BOOT__', $boot, $html);
