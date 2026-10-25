<?php
/* Send test message + verify end-to-end */
require_once __DIR__ . '/_common.php';
botmgr_require_admin();
botmgr_header('پیام تست');

$token = (string)Env::get('TELEGRAM_BOT_TOKEN', '');
$adminChat = (string)Env::get('TELEGRAM_ADMIN_CHAT_ID', '');
$apiResp = null;
$sentTo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $target = trim((string)($_POST['chat_id'] ?? $adminChat));
    if ($target === '') {
        $apiResp = ['ok' => false, 'description' => 'TELEGRAM_ADMIN_CHAT_ID در .env تنظیم نشده و Chat ID وارد نشده'];
    } else {
        $text = "🧪 <b>پیام تست وب‌هوک SSAEE</b>\n\n"
              . "زمان: " . date('Y-m-d H:i:s') . "\n"
              . "اگر این پیام را می‌بینید، ارسال پیام از سرور به تلگرام سالم است.";
        $apiResp = botmgr_api('sendMessage', ['chat_id' => $target, 'text' => $text, 'parse_mode' => 'HTML']);
        $sentTo = $target;
        audit_log(null, 'test_message', 'telegram', $target, ['ok' => ($apiResp['ok'] ?? false)]);
    }
}

$csrf = csrf_token();
?>

<h1><i data-lucide="flask-conical"></i> پیام تست</h1>
<p class="sub">تست مسیر ارسال پیام از سرور به تلگرام (مستقل از وب‌هوک)</p>

<div class="card">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= botmgr_h($csrf) ?>">
    <div style="margin-bottom:14px">
      <label style="display:flex;flex-direction:column;gap:6px;font-size:.8rem;color:var(--muted)">
        Chat ID مقصد
        <input name="chat_id" value="<?= botmgr_h($adminChat) ?>" placeholder="مثلاً 123456789" style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:11px 13px;color:var(--text);font-family:inherit;width:100%;direction:ltr;text-align:left">
      </label>
      <p style="font-size:.72rem;color:var(--muted);margin-top:6px">
        خالی بگذارید تا از TELEGRAM_ADMIN_CHAT_ID در .env استفاده شود. آیدی خود را از @userinfobot بگیرید.
      </p>
    </div>
    <button class="btn btn-primary" type="submit" style="width:100%">📤 ارسال پیام تست</button>
  </form>
</div>

<?php if ($apiResp !== null): ?>
<div class="card">
  <h2><?= ($apiResp['ok'] ?? false) ? '✅ ارسال شد' : '❌ ناموفق' ?></h2>
  <?php if (($apiResp['ok'] ?? false)): ?>
    <p style="color:var(--success);font-size:.86rem;line-height:2">پیام به <b style="direction:ltr"><?= botmgr_h($sentTo) ?></b> ارسال شد — چت تلگرام خود را چک کنید.</p>
  <?php else: ?>
    <p style="color:var(--danger);font-size:.85rem"><?= botmgr_h($apiResp['description'] ?? '') ?></p>
  <?php endif; ?>
  <pre><?= botmgr_h(json_encode($apiResp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php endif; ?>

<div class="btns">
  <a class="btn btn-ghost" href="status.php">← بازگشت به وضعیت</a>
</div>

<?php botmgr_footer(); ?>
