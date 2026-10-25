<?php
/* Webhook status — shows current config + live getWebhookInfo */
require_once __DIR__ . '/_common.php';
botmgr_require_admin();
botmgr_header('وضعیت وب‌هوک');

$token = (string)Env::get('TELEGRAM_BOT_TOKEN', '');
$secret = (string)Env::get('TELEGRAM_WEBHOOK_SECRET', '');
$appUrl = (string)Env::get('APP_URL', '');
$webhookUrl = botmgr_webhook_url();

$info = ($token !== '') ? botmgr_api('getWebhookInfo') : null;
$botMe = ($token !== '') ? botmgr_api('getMe') : null;
?>

<h1><i data-lucide="satellite-dish"></i> وضعیت وب‌هوک تلگرام</h1>
<p class="sub">تنظیمات فعلی و وضعیت اتصال زنده — بدون نیاز به ترمینال</p>

<div class="card">
  <h2><i data-lucide="settings"></i> تنظیمات .env</h2>
  <div class="row"><span class="k">توکن ربات</span><span class="v"><?= botmgr_h(botmgr_mask_token($token)) ?></span></div>
  <div class="row"><span class="k">Webhook Secret</span><span class="v"><?= $secret !== '' ? '<span class="pill pill-ok">تنظیم شده ✓</span>' : '<span class="pill pill-err">خالی!</span>' ?></span></div>
  <div class="row"><span class="k">APP_URL</span><span class="v"><?= botmgr_h($appUrl ?: '—') ?></span></div>
  <div class="row"><span class="k">آدرس وب‌هوک</span><span class="v"><?= botmgr_h($webhookUrl) ?></span></div>
  <div class="row"><span class="k">هویت ربات</span>
    <span class="v">
      <?php if ($botMe && ($botMe['ok'] ?? false)): ?>
        <span class="pill pill-ok">✓ @<?= botmgr_h($botMe['result']['username']) ?></span>
      <?php elseif ($token !== ''): ?>
        <span class="pill pill-err">توکن نامعتبر</span>
      <?php else: ?>
        <span class="pill pill-err">توکن تنظیم نشده</span>
      <?php endif; ?>
    </span>
  </div>
</div>

<div class="card">
  <h2><i data-lucide="link"></i> وضعیت اتصال (getWebhookInfo)</h2>
  <?php if ($info === null): ?>
    <p style="color:var(--danger);font-size:.85rem">توکن تنظیم نشده — امکان بررسی وجود ندارد.</p>
  <?php else: ?>
    <div class="row"><span class="k">آدرس ثبت‌شده</span><span class="v"><?= botmgr_h($info['result']['url'] ?? '—') ?></span></div>
    <div class="row"><span class="k">وضعیت</span>
      <span class="v">
        <?php if (($info['result']['url'] ?? '') !== ''): ?>
          <span class="pill pill-ok">متصل</span>
        <?php else: ?>
          <span class="pill pill-warn">ثبت نشده</span>
        <?php endif; ?>
      </span>
    </div>
    <div class="row"><span class="k">آخرین خطا</span>
      <span class="v"><?= !empty($info['result']['last_error_message']) ? '<span class="pill pill-err">' . botmgr_h($info['result']['last_error_message']) . '</span>' : '<span class="pill pill-ok">بدون خطا ✓</span>' ?></span>
    </div>
    <?php if (!empty($info['result']['last_error_date'])): ?>
      <div class="row"><span class="k">زمان آخرین خطا</span><span class="v"><?= botmgr_h(date('Y-m-d H:i:s', (int)$info['result']['last_error_date'])) ?></span></div>
    <?php endif; ?>
    <div class="row"><span class="k">پیام‌های در صف</span><span class="v"><?= (int)($info['result']['pending_update_count'] ?? 0) ?></span></div>
    <?php if (!empty($info['result']['ip_address'])): ?>
      <div class="row"><span class="k">IP ثبت‌شده</span><span class="v"><?= botmgr_h($info['result']['ip_address']) ?></span></div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h2>🔬 تست کامل اتصال (توکن + وب‌هوک + ارسال)</h2>
  <p style="font-size:.78rem;color:var(--muted);margin-bottom:10px">سرور خودت را مثل تلگرام صدا می‌زند تا مسیر کامل بررسی شود.</p>
  <button class="btn btn-ghost" id="selfTest" type="button" style="width:100%">اجرای تست داخلی</button>
  <pre id="selfTestOut" class="hidden" style="margin-top:10px"></pre>
</div>
<script>
document.getElementById('selfTest').addEventListener('click', async () => {
  const out = document.getElementById('selfTestOut');
  out.classList.remove('hidden');
  out.textContent = 'در حال اجرا…';
  try {
    const r = await fetch('../?api=bot_selftest', { headers: { Accept: 'application/json' } });
    const j = await r.json();
    out.textContent = JSON.stringify(j, null, 2);
  } catch (e) { out.textContent = 'خطا: ' + e.message; }
});
</script>
<?php if (isset($info['result'])): ?>
<div class="card">
  <h2><i data-lucide="braces"></i> پاسخ خام تلگرام</h2>
  <pre><?= botmgr_h(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php endif; ?>

<div class="card">
  <h2><i data-lucide="scroll-text"></i> لاگ وب‌هوک (۳۰ مورد اخیر)</h2>
  <?php
  $logFile = dirname(__DIR__) . '/storage/logs/telegram.log';
  if (file_exists($logFile)) {
      $logLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      $recent = array_slice($logLines, -30);
      if ($recent) {
          echo '<pre>' . botmgr_h(implode("\n", array_reverse($recent))) . '</pre>';
      } else {
          echo '<p style="color:var(--muted);font-size:.84rem">لاگ خالی است.</p>';
      }
  } else {
      echo '<p style="color:var(--muted);font-size:.84rem">هنوز درخواستی از تلگرام نرسیده. پس از ثبت وب‌هوک و ارسال /start اینجا پر می‌شود.</p>';
  }
  ?>
</div>

<div class="card">
  <h2>🔁 تست رفت‌وبرگشت وب‌هوک</h2>
  <p style="font-size:.76rem;color:var(--muted);margin-bottom:10px;line-height:1.9">
    سرور به وب‌هوک خودش درخواست شبیه تلگرام می‌فرستد — اگر موفق باشد، مسیر ورودی سالم است و مشکل از سمت تلگرام یا تنظیمات است.
  </p>
  <button class="btn btn-ghost" id="selfPOST" type="button" style="width:100%">🔁 اجرای رفت‌وبرگشت</button>
  <pre id="selfPOSTOut" class="hidden" style="margin-top:10px"></pre>
</div>
<script>
document.getElementById('selfPOST').addEventListener('click', async () => {
  const out = document.getElementById('selfPOSTOut');
  out.classList.remove('hidden');
  out.textContent = 'در حال اجرا…';
  try {
    const r = await fetch('../?api=bot_selftest', { headers: { Accept: 'application/json' } });
    const j = await r.json();
    out.textContent = JSON.stringify(j, null, 2);
  } catch (e) { out.textContent = 'خطا: ' + e.message; }
});
</script>
<div class="btns">
  <a class="btn btn-primary" href="set.php">📡 اتصال وب‌هوک (setWebhook)</a>
  <a class="btn btn-ghost" href="test.php">🧪 ارسال پیام تست</a>
  <?php if (($info['result']['url'] ?? '') !== ''): ?>
    <a class="btn btn-danger" href="delete.php">✂️ قطع وب‌هوک (deleteWebhook)</a>
  <?php endif; ?>
</div>

<?php botmgr_footer(); ?>
