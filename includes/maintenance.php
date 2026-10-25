<?php
/* ============================================================
   Maintenance / Upgrade mode (task 4)
   - Blocks the SPA shell AND mutating/reading APIs for visitors
   - Whitelist keeps critical endpoints alive:
       ping            → health checks
       bot             → Telegram webhook (bot must never go down)
       bot_selftest    → diagnostics
       maintenance_check → the maintenance page auto-reload poll
   - Logged-in panel admins bypass everything and see a warning bar
   - Settings: maintenance_mode (0/1), maintenance_message
   ============================================================ */

/* ---------------- state ---------------- */

function maintenance_is_active(): bool
{
    static $cache = null;
    if ($cache === null) {
        $cache = setting('maintenance_mode', '0') === '1';
    }
    return $cache;
}

function maintenance_message(): string
{
    return (string)setting('maintenance_message', 'سایت در حال بروزرسانی است.');
}

/** Panel admins (logged into /admin/) bypass the gate so they can turn it off. */
function maintenance_bypassed(): bool
{
    return !empty($_SESSION['admin']['id']);
}

/** API actions that stay reachable during maintenance. */
function maintenance_api_whitelist(): array
{
    return ['ping', 'bot', 'bot_selftest', 'maintenance_check'];
}

function maintenance_api_blocked(string $action): bool
{
    return !in_array($action, maintenance_api_whitelist(), true);
}

/* ---------------- 503 JSON for blocked API calls ---------------- */

function maintenance_json_refuse(): void
{
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: 60');
    echo json_encode(
        ['status' => 'error', 'err' => 1030, 'message' => 'سایت در حال تعمیر و بروزرسانی است — بعداً تلاش کنید'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

/* ---------------- the maintenance page ---------------- */

function maintenance_render_page(): void
{
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 60');
    header("Content-Security-Policy: frame-ancestors 'none';");
    header('X-Content-Type-Options: nosniff');

    $msg = maintenance_message();
    $msgHtml = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');

    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1.0">';
    echo '<meta name="robots" content="noindex,nofollow">';
    echo '<title>در حال تعمیر و بروزرسانی | SSAEE</title>';
    echo '<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">';
    echo '<link rel="shortcut icon" href="assets/favicon.ico">';
    echo '<style>
@font-face { font-family:"YekanBakh"; src:url("assets/fonts/YekanBakh-Regular.woff2") format("woff2"); font-weight:400; font-display:swap; }
@font-face { font-family:"YekanBakh"; src:url("assets/fonts/YekanBakh-Bold.woff2") format("woff2"); font-weight:700; font-display:swap; }
:root { --bg:#0b0b0d; --card:#141416; --border:#26262a; --text:#f2f2f3; --muted:#8b8b90; --accent:#ffcc00; --accent-dark:#0c3569; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:"YekanBakh",Tahoma,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; overflow:hidden; }
.scene { position:fixed; inset:0; pointer-events:none; }
.grid {
  position:absolute; inset:-60px;
  background-image:linear-gradient(rgba(255,204,0,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(255,204,0,.05) 1px, transparent 1px);
  background-size:44px 44px;
  animation:mGrid 22s linear infinite;
  -webkit-mask-image:radial-gradient(ellipse 85% 75% at 50% 45%, #000 35%, transparent 100%);
  mask-image:radial-gradient(ellipse 85% 75% at 50% 45%, #000 35%, transparent 100%);
}
@keyframes mGrid { to { transform:translate(44px,44px); } }
.glow { position:absolute; width:46vmax; height:46vmax; border-radius:50%; top:-16vmax; right:-12vmax;
  background:radial-gradient(circle, rgba(255,204,0,.12), transparent 62%); animation:mGlow 16s ease-in-out infinite alternate; }
.glow2 { top:auto; right:auto; bottom:-18vmax; left:-14vmax; background:radial-gradient(circle, rgba(12,53,105,.4), transparent 62%); animation-duration:21s; animation-delay:-8s; }
@keyframes mGlow { from { transform:translate(0,0) scale(1); } to { transform:translate(-4vmax,3vmax) scale(1.12); } }
.card { position:relative; z-index:1; text-align:center; max-width:430px; width:100%; background:rgba(20,20,22,.88);
  border:1px solid var(--border); border-radius:22px; padding:44px 30px 34px;
  backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px);
  animation:mIn .5s cubic-bezier(.2,.8,.3,1); }
@keyframes mIn { from { opacity:0; transform:translateY(18px) scale(.97); } to { opacity:1; transform:none; } }
.gear { font-size:3.2rem; line-height:1; display:inline-block; animation:mSpin 6s linear infinite; filter:drop-shadow(0 0 18px rgba(255,204,0,.35)); }
@keyframes mSpin { to { transform:rotate(360deg); } }
h1 { font-size:1.22rem; color:var(--accent); margin:16px 0 10px; }
.msg { color:var(--muted); font-size:.88rem; line-height:2.1; margin-bottom:22px; word-break:break-word; }
.status { display:flex; align-items:center; justify-content:center; gap:8px; font-size:.76rem; color:var(--muted);
  background:rgba(255,255,255,.03); border:1px solid var(--border); border-radius:999px; padding:8px 16px; margin-bottom:16px; }
.dot { width:8px; height:8px; border-radius:50%; background:var(--accent); animation:mBlink 1.2s ease-in-out infinite; }
@keyframes mBlink { 0%,100% { opacity:1; } 50% { opacity:.25; } }
.btn { display:inline-block; border:1px solid var(--border); background:transparent; color:var(--text);
  font-family:inherit; font-size:.8rem; border-radius:10px; padding:9px 20px; cursor:pointer; transition:border-color .2s, color .2s; }
.btn:hover { border-color:var(--accent); color:var(--accent); }
.brand { margin-top:22px; font-size:.68rem; letter-spacing:2px; color:var(--muted); opacity:.6; }
.brand b { color:var(--accent); }
@media (prefers-reduced-motion: reduce) { .grid,.glow,.gear,.dot { animation:none !important; } }
</style></head><body>';
    echo '<div class="scene" aria-hidden="true"><div class="grid"></div><div class="glow"></div><div class="glow glow2"></div></div>';
    echo '<main class="card">';
    echo '<span class="gear">⚙️</span>';
    echo '<h1>در حال تعمیر و بروزرسانی</h1>';
    echo '<p class="msg">' . $msgHtml . '</p>';
    echo '<div class="status"><span class="dot"></span> این صفحه هر ۲۰ ثانیه به‌طور خودکار بررسی می‌شود</div>';
    echo '<button class="btn" type="button" onclick="location.reload()">بررسی مجدد</button>';
    echo '<div class="brand"><b>SSAEE</b> · انجمن علمی مهندسی برق علوم و تحقیقات</div>';
    echo '</main>';
    // Auto-reload: poll a lightweight public endpoint; reload the moment the site is back
    echo '<script>
(function poll(){
  setTimeout(function(){
    fetch("index.php?api=maintenance_check", {cache:"no-store"})
      .then(function(r){ return r.json(); })
      .then(function(j){
        var on = j && j.data && Number(j.data.maintenance) === 1;
        if (!on) { location.reload(); return; }
        poll();
      })
      .catch(function(){ poll(); });
  }, 20000);
})();
</script>';
    echo '</body></html>';
    exit;
}

/* ---------------- gate (single entry point) ---------------- */

/**
 * Call early in index.php after app_boot()/boot_session().
 * Renders the maintenance page (or refuses API calls) and exits when active.
 * Admins bypass and get a small floating warning bar injected into the SPA.
 * Never throws: if the DB is down, the site is served normally (fail-open),
 * because the site itself handles DB-down errors gracefully.
 */
function maintenance_gate(): void
{
    try {
        if (!maintenance_is_active()) return;
        if (maintenance_bypassed()) return;

        // API request?
        if (isset($_GET['api'])) {
            if (!maintenance_api_blocked((string)$_GET['api'])) return;
            maintenance_json_refuse();
        }

        maintenance_render_page();
    } catch (Throwable $ex) {
        error_log('[maintenance] ' . $ex->getMessage());
        // fail-open: never brick the site because of the gate itself
    }
}

/** Small floating bar for admins viewing the site while maintenance is on. */
function maintenance_admin_bar(): string
{
    try {
        if (!maintenance_is_active() || !maintenance_bypassed()) return '';
    } catch (Throwable $ex) {
        return '';
    }
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $js = '(function(){var b=document.createElement("div");'
        . 'b.style.cssText="position:fixed;bottom:14px;left:50%;transform:translateX(-50%);z-index:99999;'
        . 'background:#1a1a1d;border:1px solid #ffa726;color:#f2f2f3;font-family:Tahoma,sans-serif;font-size:12px;'
        . 'padding:10px 16px;border-radius:12px;display:flex;gap:10px;align-items:center;box-shadow:0 8px 24px rgba(0,0,0,.5)";'
        . 'b.innerHTML="<span style=\\"color:#ffa726\\">●</span> حالت تعمیر فعال است (فقط شما می‌بینید) '
        . '<a href=\\"/admin/\\" style=\\"color:#ffcc00;text-decoration:none\\">پنل</a>";'
        . 'document.body.appendChild(b);})();';
    return '<script>' . $js . '</script>';
}
