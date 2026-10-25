<?php
/* ============================================================
   Dedicated Telegram Webhook Endpoint
   Why a dedicated file instead of ?api=bot:
   - Immune to WAF/CDN rules that block query-string POSTs
   - No session overhead; Telegram expects fast 200 "OK"
   - Standard Telegram Bot API practice
   ============================================================ */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/site.php';
require_once dirname(__DIR__) . '/includes/bot.php';

app_boot();

// Full Telegram update pipeline (secret validation + processing)
bot_handle_webhook();
