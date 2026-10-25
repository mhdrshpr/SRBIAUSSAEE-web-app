<?php
/* ============================================================
   TEMP SETUP — Create the first superadmin account.
   This page auto-disables as soon as ANY admin exists.
   Delete this file after first login for extra safety.
   ============================================================ */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/admin.php';

app_boot();
boot_session();

/* Guard: page works only while the users table is empty */
try {
    $adminCount = (int)db_val("SELECT COUNT(*) FROM users");
} catch (Throwable $ex) {
    $error = 'اتصال به دیتابیس ناموفق است. ابتدا install/schema.sql را اجرا کرده و .env را تنظیم کنید.';
    $adminCount = -1;
}

if ($adminCount > 0) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>بسته شده</title></head>';
    echo '<body style="font-family:Tahoma;background:#0b0b0d;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh">';
    echo '<div style="background:#141416;border:1px solid #26262a;padding:30px;border-radius:16px;max-width:420px;text-align:center">';
    echo '<h2 style="color:#ff5252">⛔ این صفحه غیرفعال است</h2>';
    echo '<p style="color:#8b8b90;margin-top:10px">ادمین ارشد قبلاً تعریف شده است.</p>';
    echo '<a href="admin/" style="color:#ffcc00">ورود به پنل مدیریت</a></div></body></html>';
    exit;
}

$errors = [];
$success = false;
$old = ['username' => '', 'full_name' => '', 'telegram_id' => ''];

if ($adminCount === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'درخواست نامعتبر (CSRF). صفحه را رفرش کنید';
    } else {
        $old['username'] = trim((string)($_POST['username'] ?? ''));
        $old['full_name'] = trim((string)($_POST['full_name'] ?? ''));
        $old['telegram_id'] = only_digits((string)($_POST['telegram_id'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_]{4,50}$/', $old['username'])) {
            $errors[] = 'نام کاربری باید ۴ تا ۵۰ کاراکتر انگلیسی باشد';
        }
        if ($old['full_name'] === '') $errors[] = 'نام کامل الزامی است';
        if (strlen($password) < 8) $errors[] = 'رمز عبور باید حداقل ۸ کاراکتر باشد';
        if ($password !== $password2) $errors[] = 'تکرار رمز عبور مطابقت ندارد';

        if (!$errors) {
            db_exec(
                "INSERT INTO users (username, password, full_name, role, is_active, telegram_id)
                 VALUES (?, ?, ?, 'superadmin', 1, ?)",
                [$old['username'], password_hash($password, PASSWORD_DEFAULT), $old['full_name'], $old['telegram_id'] ?: null]
            );
            $newUserId = db_id();
            db_exec(
                "INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'superadmin')",
                [$newUserId]
            );
            audit_log(null, 'create', 'users', (string)$newUserId, ['username' => $old['username'], 'via' => 'setup']);
            $success = true;
        }
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تعریف ادمین ارشد | SSAEE</title>
<style>
:root { --bg:#0b0b0d; --card:#141416; --border:#26262a; --text:#f2f2f3; --muted:#8b8b90; --accent:#ffcc00; --danger:#ff5252; --success:#4caf50; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'YekanBakh',Tahoma,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
.card { width:100%; max-width:440px; background:var(--card); border:1px solid var(--border); border-radius:20px; padding:32px 26px; }
h1 { font-size:1.1rem; color:var(--accent); margin-bottom:6px; }
.sub { color:var(--muted); font-size:.82rem; margin-bottom:22px; line-height:1.9; }
label { display:flex; flex-direction:column; gap:6px; font-size:.8rem; color:var(--muted); margin-bottom:14px; }
input { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:12px 14px; color:var(--text); font-family:inherit; font-size:.92rem; width:100%; }
input:focus { outline:none; border-color:var(--accent); }
button { width:100%; border:none; border-radius:12px; padding:13px; background:var(--accent); color:#000; font-family:inherit; font-size:.95rem; font-weight:700; cursor:pointer; }
button:active { transform:scale(.98); }
.err { background:rgba(255,82,82,.1); border:1px solid var(--danger); color:var(--danger); border-radius:12px; padding:10px 14px; font-size:.8rem; margin-bottom:12px; }
.ok { background:rgba(76,175,80,.1); border:1px solid var(--success); color:var(--success); border-radius:12px; padding:16px; font-size:.9rem; line-height:2; text-align:center; }
a { color:var(--accent); }
.hint { color:var(--muted); font-size:.72rem; margin-top:16px; text-align:center; line-height:1.9; }
</style>
</head>
<body>
<div class="card">
  <h1>🛠 تعریف ادمین ارشد</h1>
  <div class="sub">این صفحه فقط تا وقتی هیچ ادمینی تعریف نشده کار می‌کند. پس از ثبت اولین مدیر، به‌طور خودکار غیرفعال می‌شود.</div>

  <?php if (isset($error)): ?>
    <div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php elseif ($success): ?>
    <div class="ok">
      ✅ ادمین ارشد ساخته شد!<br><br>
      <a href="admin/"><b>ورود به پنل مدیریت</b></a><br><br>
      <small>این فایل (setup.php) را از سرور حذف کنید.</small>
    </div>
  <?php else: ?>
    <?php foreach ($errors as $er): ?><div class="err"><?= htmlspecialchars($er, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <label>نام کاربری (انگلیسی)
        <input name="username" required value="<?= htmlspecialchars($old['username'], ENT_QUOTES, 'UTF-8') ?>" pattern="[a-zA-Z0-9_]{4,50}">
      </label>
      <label>نام کامل
        <input name="full_name" required value="<?= htmlspecialchars($old['full_name'], ENT_QUOTES, 'UTF-8') ?>">
      </label>
      <label>رمز عبور (حداقل ۸ کاراکتر)
        <input name="password" type="password" required minlength="8">
      </label>
      <label>تکرار رمز عبور
        <input name="password2" type="password" required minlength="8">
      </label>
      <label>تلگرام ID (اختیاری — برای کد ورود 2FA)
        <input name="telegram_id" inputmode="numeric" value="<?= htmlspecialchars($old['telegram_id'], ENT_QUOTES, 'UTF-8') ?>">
      </label>
      <button type="submit">ساخت ادمین ارشد</button>
    </form>
    <div class="hint">پس از ورود، از بخش «مدیران» پنل، بقیه مدیران با نقش‌های مختلف تعریف می‌شوند.</div>
  <?php endif; ?>
</div>
</body>
</html>
