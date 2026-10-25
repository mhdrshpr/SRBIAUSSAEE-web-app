<?php
/* Main site entry: serves the SPA HTML, all API endpoints (?api=<name>)
   and the universal error page for unknown API actions. */

try {
    require_once __DIR__ . '/includes/bootstrap.php';
    require_once __DIR__ . '/includes/site.php';
    require_once __DIR__ . '/includes/bot.php';
    require_once __DIR__ . '/includes/maintenance.php';
} catch (ParseError | Error $ex) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $msg = 'PHP Load Error: ' . $ex->getMessage() . ' in ' . basename($ex->getFile()) . ':' . $ex->getLine();
    error_log('[LOAD] ' . $msg);
    echo json_encode(['status' => 'error', 'err' => 1005, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

app_boot();

/* Maintenance gate — blocks the SPA shell AND non-whitelisted API calls.
   Whitelist: ping · bot (Telegram webhook) · bot_selftest · maintenance_check
   Logged-in panel admins bypass (so they can turn it off). */
try {
    boot_session();
    maintenance_gate();
} catch (Throwable $ex) {
    error_log('[maint-gate] ' . $ex->getMessage());
    // fail-open — site's own error handling covers DB-down scenarios
}

/* API routes: index.php?api=<name> */
if (isset($_GET['api'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        exit;
    }
    site_api_handle();
}

/* SPA shell */
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: frame-ancestors 'self' https://web.telegram.org https://*.telegram.org;");

try {
    $shell = file_get_contents(__DIR__ . '/app.html');
    // Inject the admin-only maintenance warning bar before </body>
    $bar = maintenance_admin_bar();
    if ($bar !== '') {
        $shell = str_ireplace('</body>', $bar . '</body>', $shell);
    }
    echo $shell;
} catch (Throwable $ex) {
    error_log('[shell] ' . $ex->getMessage());
    http_response_code(500);
    header('Location: /error.php?code=500');
    echo 'Server error - redirecting.';
}
