<?php
/* ============================================================
   Telegram Webhook Manager (browser-based, no terminal needed)
   Pages: status.php · set.php · delete.php · test.php
   ============================================================ */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

/* ---------- Shared guard: TECH_ADMIN ONLY ----------
   Webhook tools control the bot token (set/delete webhook, test messages).
   Access is deliberately limited to tech_admin — NOT even superadmin.
   Role resolution uses the panel's canonical resolver (user_roles table,
   legacy users.role fallback) so it matches the rest of the panel. */
function botmgr_require_admin(): void
{
    require_once dirname(__DIR__) . '/includes/admin.php';
    app_boot();

    $admin = null;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        boot_session();
    }
    if (!empty($_SESSION['admin']['id'])) {
        $row = db_one("SELECT id, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1", [(int)$_SESSION['admin']['id']]);
        if ($row !== null) {
            $admin = $row;
        }
    }
    if ($admin === null || !admin_is_tech($admin)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>دسترسی</title></head>';
        echo '<body style="font-family:Tahoma;background:#0b0b0d;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh">';
        echo '<div style="background:#141416;border:1px solid #26262a;padding:30px;border-radius:16px;text-align:center">';
        echo '<h2 style="color:#ff5252">⛔ فقط ادمین فنی</h2>';
        echo '<p style="color:#8b8b90">ابزارهای وب‌هوک فقط با حساب «ادمین فنی» در دسترس‌اند.</p>';
        echo '<p style="color:#8b8b90">ابتدا از <a href="../admin/" style="color:#ffcc00">پنل مدیریت</a> با حساب ادمین فنی وارد شوید.</p></div></body></html>';
        exit;
    }
}

/* ---------- Shared HTML shell ---------- */
function botmgr_header(string $title): void
{
    $dark = !isset($_COOKIE['botmgr_theme']) || $_COOKIE['botmgr_theme'] !== 'light';
    $bg = $dark ? '#0b0b0d' : '#f4f5f7';
    $card = $dark ? '#141416' : '#ffffff';
    $border = $dark ? '#26262a' : '#e2e4e8';
    $text = $dark ? '#f2f2f3' : '#17181a';
    $muted = $dark ? '#8b8b90' : '#7a7d82';

    echo "<!DOCTYPE html><html lang='fa' dir='rtl'><head><meta charset='UTF-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
    echo "<title>{$title} | SSAEE Bot</title>";
    echo "<link rel='icon' type='image/svg+xml' href='../assets/favicon.svg'>";
    echo "<link rel='shortcut icon' href='../assets/favicon.ico'>";
    echo "<style>
    @font-face { font-family:'YekanBakh'; src:url('../assets/fonts/YekanBakh-Regular.woff2') format('woff2'); font-weight:400; font-style:normal; font-display:swap; }
    @font-face { font-family:'YekanBakh'; src:url('../assets/fonts/YekanBakh-Bold.woff2') format('woff2'); font-weight:700; font-style:normal; font-display:swap; }
    @font-face { font-family:'YekanBakh'; src:url('../assets/fonts/YekanBakh-Black.woff2') format('woff2'); font-weight:900; font-style:normal; font-display:swap; }
    :root { --bg:{$bg}; --card:{$card}; --border:{$border}; --text:{$text}; --muted:{$muted}; --accent:#ffcc00; --danger:#ff5252; --success:#4caf50; --info:#229ED9; }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'YekanBakh',Tahoma,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding:24px 14px; }
    .wrap { max-width:640px; margin:0 auto; }
    h1 { font-size:1.15rem; color:var(--accent); margin-bottom:4px; }
    .sub { color:var(--muted); font-size:.82rem; margin-bottom:22px; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:20px; margin-bottom:16px; }
    .card h2 { font-size:.95rem; margin-bottom:12px; color:var(--accent); }
    .row { display:flex; justify-content:space-between; padding:9px 0; border-bottom:1px dashed var(--border); font-size:.86rem; gap:10px; }
    .row:last-child { border-bottom:none; }
    .k { color:var(--muted); flex-shrink:0; }
    .v { text-align:left; word-break:break-all; direction:ltr; }
    .pill { padding:3px 12px; border-radius:999px; font-size:.74rem; font-weight:700; }
    .pill-ok { background:rgba(76,175,80,.15); color:var(--success); }
    .pill-err { background:rgba(255,82,82,.15); color:var(--danger); }
    .pill-warn { background:rgba(255,204,0,.15); color:var(--accent); }
    pre { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:12px; font-size:.75rem; direction:ltr; text-align:left; overflow-x:auto; white-space:pre-wrap; word-break:break-all; color:var(--muted); }
    .btns { display:flex; flex-direction:column; gap:10px; }
    .btn { display:block; text-align:center; text-decoration:none; border:none; border-radius:12px; padding:13px; font-family:inherit; font-size:.9rem; font-weight:700; cursor:pointer; transition:filter .15s, transform .1s; }
    .btn:active { transform:scale(.98); }
    .btn-primary { background:var(--accent); color:#000; }
    .btn-danger { background:rgba(255,82,82,.12); border:1px solid var(--danger); color:var(--danger); }
    .btn-ghost { background:transparent; border:1px solid var(--border); color:var(--text); }
    .btn:hover { filter:brightness(1.08); }
    .back { display:inline-block; color:var(--accent); text-decoration:none; font-size:.8rem; margin-bottom:16px; }
    .theme-toggle { position:fixed; top:16px; left:16px; background:var(--card); border:1px solid var(--border); color:var(--text); border-radius:50%; width:40px; height:40px; cursor:pointer; font-size:1.1rem; }
    .bot-brand { display:flex; align-items:center; gap:12px; margin-bottom:22px; padding-bottom:16px; border-bottom:1px solid var(--border); }
    .bot-brand img { width:44px; height:44px; object-fit:contain; }
    .bot-brand b { font-size:1.05rem; color:var(--accent); letter-spacing:2px; }
    .bot-brand small { display:block; color:var(--muted); font-size:.72rem; letter-spacing:0; font-weight:400; }
    .btns .btn { display:flex; align-items:center; justify-content:center; gap:8px; }
    .btns .btn i, .btns .btn svg { width:17px; height:17px; }
    h1 { display:flex; align-items:center; gap:8px; }
    h1 i, h1 svg { width:20px; height:20px; }
    .card h2 { display:flex; align-items:center; gap:7px; }
    .card h2 i, .card h2 svg { width:17px; height:17px; }
    </style>
    <script src='../assets/vendor/lucide.min.js'></script>
    </head><body>";
    echo "<button class='theme-toggle' onclick=\"document.cookie='botmgr_theme='+(document.cookie.includes('light')?'dark':'light')+';path=/';location.reload()\">🌙</button>";
    echo "<div class='wrap'>";
    echo "<div class='bot-brand'><img src='../assets/logo.png' alt='SSAEE' onerror=\"this.style.display='none'\"><div><b>SSAEE</b><small>مدیریت وب‌هوک تلگرام</small></div></div>";
}

function botmgr_footer(): void
{
    echo '</div><script>if(window.lucide)lucide.createIcons();</script></body></html>';
}

/* ---------- Telegram API (server-side) ---------- */
function botmgr_api(string $method, array $params = []): array
{
    $token = (string)Env::get('TELEGRAM_BOT_TOKEN', '');
    if ($token === '') {
        return ['ok' => false, 'description' => 'TELEGRAM_BOT_TOKEN در فایل .env تنظیم نشده است'];
    }

    $url = "https://api.telegram.org/bot{$token}/{$method}";
    // Auto-encode arrays (reply_markup etc) as JSON
    foreach ($params as $k => $v) {
        if (is_array($v)) {
            $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) {
        return ['ok' => false, 'description' => 'خطای اتصال: ' . $err];
    }
    $data = json_decode($res, true);
    if (!is_array($data)) {
        return ['ok' => false, 'description' => "پاسخ نامعتبر (HTTP {$code}): " . mb_substr($res, 0, 200)];
    }
    return $data;
}

function botmgr_register_commands(): array
{
    $commands = [
        ['command' => 'start',    'description' => 'شروع و نمایش منوی اصلی'],
        ['command' => 'help',     'description' => 'راهنمای ربات'],
        ['command' => 'panel',    'description' => 'باز کردن پنل هوشمند'],
        ['command' => 'support',  'description' => 'ارتباط با پشتیبانی'],
        ['command' => 'weblogin', 'description' => 'ورود سریع به پنل وب'],
        ['command' => 'cancel',   'description' => 'لغو عملیات جاری'],
    ];
    return botmgr_api('setMyCommands', ['commands' => json_encode($commands)]);
}
function botmgr_webhook_url(): string
{
    $base = rtrim((string)Env::get('APP_URL', ''), '/');
    return $base . '/bot/webhook.php';
}

function botmgr_mask_token(string $token): string
{
    if ($token === '') return '—';
    $parts = explode(':', $token, 2);
    if (count($parts) !== 2) return mb_substr($token, 0, 8) . '…';
    return $parts[0] . ':' . str_repeat('•', 8) . mb_substr($parts[1], -4);
}

function botmgr_h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
