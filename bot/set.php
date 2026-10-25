<?php
/* Set webhook — one click, browser-based */
require_once __DIR__ . '/_common.php';
botmgr_require_admin();
botmgr_header('اتصال وب‌هوک');

$token = (string)Env::get('TELEGRAM_BOT_TOKEN', '');
$secret = (string)Env::get('TELEGRAM_WEBHOOK_SECRET', '');
$webhookUrl = botmgr_webhook_url();

/* Confirm step */
$confirm = isset($_POST['confirm']) && $_POST['confirm'] === '1';

$done = false;
$apiResp = null;

if ($confirm && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $apiResp = ['ok' => false, 'description' => 'درخواست نامعتبر (CSRF)'];
    } elseif ($token === '' || $secret === '') {
        $apiResp = ['ok' => false, 'description' => 'ابتدا TELEGRAM_BOT_TOKEN و TELEGRAM_WEBHOOK_SECRET را در .env تنظیم کنید'];
    } else {
        $apiResp = botmgr_api('setWebhook', [
            'url'             => $webhookUrl,
            'secret_token'    => $secret,
            'allowed_updates' => json_encode(['message', 'callback_query']),
            'drop_pending_updates' => true,
        ]);
        $done = ($apiResp['ok'] ?? false);

        // Register bot commands menu (visible in Telegram UI)
        if ($done) {
            $appUrl = rtrim((string)Env::get('APP_URL', ''), '/');
            botmgr_api('setMyCommands', ['commands' => json_encode([
                ['command' => 'start',   'description' => '🚀 شروع ربات'],
                ['command' => 'panel',   'description' => '🚀 باز کردن پنل'],
                ['command' => 'points',  'description' => '⭐ استعلام امتیاز'],
                ['command' => 'classes', 'description' => '📚 گروه‌های درسی'],
                ['command' => 'news',    'description' => '📢 اطلاعیه‌ها'],
                ['command' => 'support', 'description' => '🆘 پشتیبانی'],
                ['command' => 'help',    'description' => 'ℹ️ راهنما'],
            ], JSON_UNESCAPED_UNICODE)]);
            botmgr_api('setChatMenuButton', ['menu_button' => json_encode([
                'type' => 'web_app',
                'text' => '🚀 پنل SSAEE',
                'web_app' => ['url' => $appUrl . '/'],
            ])]);
        }
        if ($done) {
            $apiResp['verify'] = botmgr_api('getWebhookInfo')['result'] ?? null;
            $apiResp['commands'] = botmgr_register_commands();
        }
        audit_log(null, 'setWebhook', 'telegram', null, ['url' => $webhookUrl, 'ok' => $done]);
    }
}

$csrf = csrf_token();
?>

<h1><i data-lucide="satellite-dish"></i> اتصال وب‌هوک تلگرام</h1>
<p class="sub">یک کلیک — بدون ترمینال</p>

<div class="card">
  <h2>📋 تنظیماتی که ثبت می‌شود</h2>
  <p style="font-size:.75rem;color:var(--muted);margin-bottom:10px;line-height:1.9">
    💡 آدرس اختصاصی <b style="direction:ltr">/bot/webhook.php</b> استفاده می‌شود (به‌جای query string) — مطابق استاندارد تلگرام و مقاوم در برابر فیلترهای WAF هاست.
  </p>
  <div class="row"><span class="k">Webhook URL</span><span class="v"><?= botmgr_h($webhookUrl) ?></span></div>
  <div class="row"><span class="k">Secret Token</span><span class="v"><?= $secret !== '' ? '<span class="pill pill-ok">از .env خوانده شد ✓</span>' : '<span class="pill pill-err">.env خالی!</span>' ?></span></div>
  <div class="row"><span class="k">Allowed Updates</span><span class="v">message, callback_query</span></div>
</div>

<?php if ($apiResp !== null): ?>
<div class="card">
  <h2><?= $done ? '✅ نتیجه' : '❌ نتیجه' ?></h2>
  <?php if ($done): ?>
    <p style="color:var(--success);font-size:.9rem;line-height:2;margin-bottom:10px">
      وب‌هوک با موفقیت ثبت شد! حالا در تلگرام به ربات خود <b>/start</b> بدهید تا تست شود.
    </p>
  <?php else: ?>
    <p style="color:var(--danger);font-size:.85rem;line-height:2;margin-bottom:10px"><?= botmgr_h($apiResp['description'] ?? 'خطای نامشخص') ?></p>
  <?php endif; ?>
  <pre><?= botmgr_h(json_encode($apiResp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php endif; ?>

<?php if (!$done): ?>
<div class="card">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= botmgr_h(csrf_token()) ?>">
    <input type="hidden" name="confirm" value="1">
    <p style="font-size:.82rem;color:var(--muted);line-height:2;margin-bottom:14px">
      با زدن دکمه زیر، آدرس <b style="direction:ltr"><?= botmgr_h($webhookUrl) ?></b> به‌همراه secret_token به تلگرام معرفی می‌شود.
    </p>
    <button class="btn btn-primary" type="submit" style="width:100%">📡 ثبت وب‌هوک</button>
  </form>
</div>
<?php endif; ?>

<div class="btns">
  <a class="btn btn-ghost" href="status.php">← بازگشت به وضعیت</a>
  <?php if ($done): ?>
    <a class="btn btn-ghost" href="test.php">🧪 ارسال پیام تست</a>
  <?php endif; ?>
</div>

<?php botmgr_footer(); ?>
