<?php
/* Delete webhook */
require_once __DIR__ . '/_common.php';
botmgr_require_admin();
botmgr_header('قطع وب‌هوک');

$done = false;
$apiResp = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === '1') {
    if (!csrf_verify()) {
        $apiResp = ['ok' => false, 'description' => 'درخواست نامعتبر (CSRF)'];
    } else {
        $drop = (($_POST['drop'] ?? '') === '1');
        $apiResp = botmgr_api('deleteWebhook', ['drop_pending_updates' => $drop]);
        $done = ($apiResp['ok'] ?? false);
        audit_log(null, 'deleteWebhook', 'telegram', null, ['ok' => $done, 'drop' => $drop]);
    }
}

$hasWebhook = true;
$token = (string)Env::get('TELEGRAM_BOT_TOKEN', '');
if ($token !== '') {
    $info = botmgr_api('getWebhookInfo');
    $hasWebhook = (($info['result']['url'] ?? '') !== '');
}
?>

<h1><i data-lucide="unplug"></i> قطع وب‌هوک</h1>
<p class="sub">ربات پیام‌های تلگرام را دیگر دریافت نخواهد کرد</p>

<?php if ($apiResp !== null): ?>
<div class="card">
  <h2><?= $done ? '✅ نتیجه' : '❌ نتیجه' ?></h2>
  <pre><?= botmgr_h(json_encode($apiResp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php endif; ?>

<?php if (!$hasWebhook && $apiResp === null): ?>
<div class="card">
  <p style="color:var(--muted);font-size:.88rem">در حال حاضر وب‌هوکی ثبت نشده است.</p>
</div>
<?php else: ?>
<div class="card">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= botmgr_h(csrf_token()) ?>">
    <input type="hidden" name="confirm" value="1">
    <label style="display:flex;align-items:center;gap:8px;font-size:.84rem;margin-bottom:16px;cursor:pointer">
      <input type="checkbox" name="drop" value="1" style="width:16px;height:16px">
      پیام‌های در صف (pending) هم حذف شوند
    </label>
    <button class="btn btn-danger" type="submit" style="width:100%">✂️ قطع وب‌هوک</button>
  </form>
</div>
<?php endif; ?>

<div class="btns">
  <a class="btn btn-ghost" href="status.php">← بازگشت به وضعیت</a>
</div>

<?php botmgr_footer(); ?>
