<?php
/* SSAEE Panel v2 — Installer (run once, then delete this folder) */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Env::load(dirname(__DIR__) . '/.env');

$lockFile = __DIR__ . '/install.lock';
$locked = is_file($lockFile);

$errors = [];
$success = false;

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminUser = trim((string)($_POST['admin_user'] ?? ''));
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $adminName = trim((string)($_POST['admin_name'] ?? ''));
    $adminTg = preg_replace('/\D/', '', (string)($_POST['admin_telegram'] ?? ''));
    $botToken = trim((string)($_POST['bot_token'] ?? ''));
    $botUser = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_POST['bot_username'] ?? ''));
    $appUrl = rtrim(trim((string)($_POST['app_url'] ?? '')), '/');

    if ($dbName === '' || $dbUser === '') $errors[] = 'اطلاعات دیتابیس کامل نیست';
    if (!preg_match('/^[a-zA-Z0-9_]{4,50}$/', $adminUser)) $errors[] = 'نام کاربری مدیر نامعتبر است';
    if (strlen($adminPass) < 8) $errors[] = 'رمز مدیر باید حداقل ۸ کاراکتر باشد';

    $pdo = null;
    if (empty($errors)) {
        try {
            $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            $errors[] = 'اتصال به دیتابیس ناموفق: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        $env = "APP_ENV=production\nAPP_DEBUG=0\n";
        $env .= "APP_URL={$appUrl}\n";
        $env .= "APP_TIMEZONE=Asia/Tehran\n\n";
        $env .= "DB_HOST={$dbHost}\nDB_PORT=3306\nDB_NAME={$dbName}\nDB_USER={$dbUser}\nDB_PASS={$dbPass}\n\n";
        $env .= "JWT_SECRET=" . bin2hex(random_bytes(32)) . "\n";
        $env .= "APP_SECRET=" . bin2hex(random_bytes(32)) . "\n\n";
        $env .= "TELEGRAM_BOT_TOKEN={$botToken}\n";
        $env .= "TELEGRAM_ADMIN_CHAT_ID={$adminTg}\n";
        $env .= "TELEGRAM_WEBHOOK_SECRET=" . bin2hex(random_bytes(24)) . "\n";
        $env .= "TELEGRAM_BOT_USERNAME={$botUser}\n\n";
        $env .= "SESSION_NAME=SSAEE_SESSION\nCOOKIE_SECURE=1\nCOOKIE_HTTPONLY=1\n\n";
        $env .= "RATE_LIMIT_ENABLED=1\nLOGIN_MAX_ATTEMPTS=5\nLOGIN_LOCKOUT_MINUTES=15\n\n";
        $env .= "ADMIN_2FA_ENABLED=" . ($adminTg !== '' && $botToken !== '' ? '1' : '0') . "\nADMIN_2FA_TTL=300\n";

        if (@file_put_contents(dirname(__DIR__) . '/.env', $env) === false) {
            $errors[] = 'نوشتن .env ناموفق بود. دسترسی پوشه را بررسی کنید';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
        } catch (PDOException $e) {
            $errors[] = 'اجرای اسکیما ناموفق: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        try {
            $pdo->prepare(
                "INSERT INTO users (username, password, full_name, role, is_active, telegram_id)
                 VALUES (?, ?, ?, 'superadmin', 1, ?)"
            )->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $adminName ?: $adminUser, $adminTg ?: null]);

            $uid = $pdo->lastInsertId();
            $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'superadmin')")
                ->execute([$uid]);
        } catch (PDOException $e) {
            $errors[] = 'ایجاد مدیر ناموفق: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        file_put_contents($lockFile, date('c'));
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>نصب SSAEE Panel v2</title>
<style>
:root { --bg:#0b0b0d; --card:#141416; --border:#26262a; --text:#f2f2f3; --muted:#8b8b90; --accent:#ffcc00; --accent-text:#000; --danger:#ff5252; --success:#4caf50; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'YekanBakh',Tahoma,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
.card { width:100%; max-width:540px; background:var(--card); border:1px solid var(--border); border-radius:20px; padding:30px 26px; }
h1 { font-size:1.2rem; color:var(--accent); margin-bottom:6px; }
p.sub { color:var(--muted); font-size:.85rem; margin-bottom:22px; }
label { display:flex; flex-direction:column; gap:6px; font-size:.8rem; color:var(--muted); margin-bottom:12px; }
input { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:11px 13px; color:var(--text); font-family:inherit; font-size:.9rem; width:100%; }
input:focus { outline:none; border-color:var(--accent); }
button { width:100%; border:none; border-radius:12px; padding:13px; background:var(--accent); color:var(--accent-text); font-family:inherit; font-size:.95rem; font-weight:700; cursor:pointer; margin-top:8px; }
.err { background:rgba(255,82,82,.1); border:1px solid var(--danger); color:var(--danger); border-radius:12px; padding:10px 14px; font-size:.8rem; margin-bottom:12px; }
.ok { background:rgba(76,175,80,.1); border:1px solid var(--success); color:var(--success); border-radius:12px; padding:16px; font-size:.9rem; line-height:2; text-align:center; }
h2.sec { font-size:.9rem; color:var(--accent); margin:16px 0 10px; border-top:1px dashed var(--border); padding-top:14px; }
.grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.grid2 label { margin-bottom:0; }
a { color:var(--accent); }
</style>
</head>
<body>
<div class="card">
  <h1>⚙️ نصب SSAEE Panel v2</h1>
  <p class="sub">تنظیمات اولیه دیتابیس، حساب مدیر ارشد و ربات تلگرام</p>

  <?php if ($locked): ?>
    <div class="ok">✅ نصب قبلاً انجام شده است.<br><br>برای امنیت، پوشه <b>install</b> را از سرور حذف کنید.<br><br><a href="../admin/">ورود به پنل مدیریت</a></div>
  <?php elseif ($success): ?>
    <div class="ok">
      ✅ نصب با موفقیت انجام شد!<br><br>
      ۱) پوشه <b>install</b> را حذف کنید<br>
      ۲) Webhook ربات را تنظیم کنید (دستور در README)<br>
      ۳) <a href="../admin/"><b>ورود به پنل مدیریت</b></a>
    </div>
  <?php else: ?>
    <?php foreach ($errors as $er): ?><div class="err"><?= htmlspecialchars($er) ?></div><?php endforeach; ?>
    <form method="post">
      <h2 class="sec">🗄 دیتابیس</h2>
      <div class="grid2">
        <label>هاست<input name="db_host" value="localhost" required></label>
        <label>نام دیتابیس<input name="db_name" required></label>
      </div>
      <div class="grid2">
        <label>کاربر دیتابیس<input name="db_user" required></label>
        <label>رمز دیتابیس<input name="db_pass" type="password"></label>
      </div>

      <h2 class="sec">👤 مدیر ارشد</h2>
      <div class="grid2">
        <label>نام کاربری<input name="admin_user" required></label>
        <label>رمز عبور<input name="admin_pass" type="password" required minlength="8"></label>
      </div>
      <div class="grid2">
        <label>نام کامل<input name="admin_name"></label>
        <label>تلگرام ID (برای 2FA)<input name="admin_telegram" inputmode="numeric" placeholder="اختیاری"></label>
      </div>

      <h2 class="sec">🤖 تلگرام و سایت</h2>
      <label>آدرس سایت<input name="app_url" placeholder="https://ssaee.example.ir" required></label>
      <div class="grid2">
        <label>توکن ربات<input name="bot_token" placeholder="اختیاری"></label>
        <label>یوزرنیم ربات (بدون @)<input name="bot_username" placeholder="ssaee_bot"></label>
      </div>

      <button type="submit">شروع نصب</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
