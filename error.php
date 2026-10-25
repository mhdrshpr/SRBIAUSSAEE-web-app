<?php
/* ============================================================
   Universal Error Page (403 / 404 / 500 / 502 / …)
   Usage:
     - Apache: ErrorDocument 404 /error.php?code=404
     - PHP:    include 'error.php'  (uses http_response_code)
   ============================================================ */

require_once __DIR__ . '/includes/bootstrap.php';

app_boot();

$code = (int)($_GET['code'] ?? http_response_code());
if ($code < 400 || $code > 599) {
    $code = 404;
}
http_response_code($code);

/* Message catalogue */
$errors = [
    400 => ['عنوان نادرست', 'درخواست ارسالی معتبر نیست.', 'refresh'],
    401 => ['دسترسی لازم را ندارید', 'برای مشاهده این بخش ابتدا وارد حساب خود شوید.', 'login'],
    403 => ['دسترسی مسدود است', 'شما اجازه دسترسی به این صفحه را ندارید.', 'lock'],
    404 => ['صفحه پیدا نشد', 'صفحه‌ای که دنبالش بودید جابه‌جا شده یا هرگز وجود نداشته است.', 'search-x'],
    405 => ['روش مجاز نیست', 'درخواست با روش نادرستی ارسال شده است.', 'file-warning'],
    408 => ['مهلت درخواست تمام شد', 'سرور بیش از حد منتظر ماند. دوباره تلاش کنید.', 'timer'],
    429 => ['درخواست‌های زیاد', 'شما بیش از حد مجاز درخواست فرستاده‌اید. کمی صبر کنید.', 'gauge'],
    500 => ['خطای داخلی سرور', 'مشکلی از سمت سرور پیش آمده است. تیم فنی در جریان است.', 'server-crash'],
    502 => ['دروازه نامعتبر', 'ارتباط سرور با سرویس پشتیبان قطع شده است.', 'unplug'],
    503 => ['سرویس در دسترس نیست', 'سرور موقتاً برای نگهداری از دسترس خارج است. بعداً مراجعه کنید.', 'wrench'],
];

[$title, $desc, $icon] = $errors[$code] ?? ['خطای غیرمنتظره', 'مشکلی پیش آمده است. بعداً دوباره تلاش کنید.', 'alert-triangle'];

/* Friendly context line */
$codeLine = match (true) {
    $code < 500 => 'خطای سمت کاربر / دسترسی',
    default     => 'خطای سمت سرور',
};

/* Detect API requests → JSON instead of HTML */
$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
    || str_contains($_SERVER['REQUEST_URI'] ?? '', 'api=');
if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'error',
        'code'    => $code,
        'title'   => $title,
        'message' => $desc,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= (int)$code ?> — <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | SSAEE</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="shortcut icon" href="assets/favicon.ico">
<link rel="stylesheet" href="assets/app.css">
<style>
.err-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; position: relative; overflow: hidden; }
.err-ghost {
  position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
  pointer-events: none; user-select: none;
}
.err-ghost svg, .err-ghost i { width: min(60vw, 420px); height: min(60vw, 420px); color: var(--accent); opacity: .05; transform: rotate(-8deg); }
.err-card {
  position: relative; z-index: 1;
  width: 100%; max-width: 440px;
  background: var(--bg-2); border: 1px solid var(--border);
  border-radius: 20px; padding: 38px 28px;
  box-shadow: var(--shadow); text-align: center;
  animation: fadeIn .35s ease;
}
.err-code {
  font-size: 4.2rem; font-weight: 800; letter-spacing: 2px;
  background: linear-gradient(135deg, var(--accent), var(--accent-dark));
  -webkit-background-clip: text; background-clip: text; color: transparent;
  line-height: 1.2;
}
.err-title { font-size: 1.15rem; font-weight: 700; margin-top: 4px; display: flex; align-items: center; justify-content: center; gap: 8px; }
.err-title i, .err-title svg { color: var(--accent); width: 1.3rem; height: 1.3rem; }
.err-desc { color: var(--muted); font-size: .88rem; line-height: 2.1; margin: 12px 0 24px; }
.err-actions { display: flex; flex-direction: column; gap: 10px; }
.err-meta { margin-top: 22px; color: var(--muted); font-size: .7rem; opacity: .7; }
.err-code-pill {
  display: inline-block; padding: 2px 12px; border-radius: 999px;
  background: rgba(255,204,0,.1); border: 1px solid var(--accent);
  color: var(--accent); font-size: .7rem; margin-top: 6px;
}
.err-auto {
  margin-top: 18px; padding-top: 14px; border-top: 1px dashed var(--border);
  display: flex; align-items: center; justify-content: center; gap: 8px;
  font-size: .75rem; color: var(--muted);
}
.err-auto i, .err-auto svg { width: 15px; height: 15px; color: var(--accent); }
.err-auto b { color: var(--accent); }
.err-cancel {
  background: none; border: none; color: var(--muted); cursor: pointer;
  font-family: inherit; font-size: .72rem; text-decoration: underline; padding: 2px 4px;
}
.err-cancel:hover { color: var(--danger); }
</style>
<script src="assets/vendor/lucide.min.js"></script>
</head>
<body>

<div class="err-wrap">
  <div class="err-ghost"><i data-lucide="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i></div>

  <div class="err-card">
    <div class="err-code"><?= (int)$code ?></div>
    <div class="err-title">
      <i data-lucide="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i>
      <span><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <p class="err-desc"><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></p>

    <div class="err-actions">
      <a class="btn btn-primary" href="/"><i data-lucide="house"></i> بازگشت به صفحه اصلی</a>
      <button class="btn btn-outline" type="button" onclick="location.reload()"><i data-lucide="refresh-cw"></i> تلاش مجدد</button>
      <a class="btn btn-outline" href="https://t.me/saee_admin" target="_blank" rel="noopener"><i data-lucide="life-buoy"></i> تماس با پشتیبانی</a>
    </div>

    <div class="err-meta">
      SSAEE — <?= htmlspecialchars($codeLine, ENT_QUOTES, 'UTF-8') ?><br>
      <span class="err-code-pill">کد خطا: <?= (int)$code ?></span>
    </div>

    <div class="err-auto" id="autoLine">
      <i data-lucide="timer"></i>
      <span>انتقال خودکار به صفحه اصلی در <b id="autoSec">۱۵</b> ثانیه</span>
      <button class="err-cancel" id="autoStop" type="button">لغو</button>
    </div>
  </div>
</div>

<script>lucide.createIcons();</script>
<script>
(() => {
  let s = 15;
  const sec = document.getElementById('autoSec');
  const fa = n => String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
  const t = setInterval(() => {
    s--;
    if (s <= 0) { clearInterval(t); location.href = '/'; return; }
    sec.textContent = fa(s);
  }, 1000);
  document.getElementById('autoStop').addEventListener('click', () => {
    clearInterval(t);
    document.getElementById('autoLine').style.display = 'none';
  });
})();
</script>
</body>
</html>
