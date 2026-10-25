<?php
/* ============================================================
   SSAEE Telegram Bot — clean rewrite per official Bot API docs
   https://core.telegram.org/bots/api

   Entry: bot/webhook.php or ?api=bot → bot_handle_webhook()
   All DB queries use EXPLICIT column names and log the exact
   failing SQL to storage/logs/telegram.log on error.
   ============================================================ */

/* ------------------------------------------------------------
   Low-level DB helper with precise error reporting
   ------------------------------------------------------------ */

function bot_db(string $sql, array $params = [], string $step = ''): mixed
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $ex) {
        $detail = '[bot:' . $step . '] SQL: ' . $sql . ' | PARAMS: ' . json_encode($params) . ' | ERROR: ' . $ex->getMessage();
        file_put_contents(dirname(__DIR__) . '/storage/logs/telegram.log', date('Y-m-d H:i:s') . ' | DB-ERROR | ' . $detail . "\n", FILE_APPEND);
        throw $ex;
    }
}

function bot_log(string $msg): void
{
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    file_put_contents($dir . '/telegram.log', date('Y-m-d H:i:s') . ' | ' . $msg . "\n", FILE_APPEND);
}

/* ------------------------------------------------------------
   Webhook entry — called from bot/webhook.php or ?api=bot
   ------------------------------------------------------------ */

function bot_handle_webhook(): void
{
    // Per Telegram docs: respond 200 "OK" fast; heavy work after
    // We process inline but keep it lightweight (single sends).

    $secret = (string)Env::get('TELEGRAM_WEBHOOK_SECRET', '');
    $got = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

    if ($secret === '' || !hash_equals($secret, $got)) {
        bot_log('REJECTED: secret mismatch (expected len=' . strlen($secret) . ', got len=' . strlen($got) . ')');
        http_response_code(403);
        exit('Forbidden');
    }

    $raw = file_get_contents('php://input');
    $update = json_decode($raw, true);

    if (!is_array($update)) {
        bot_log('INVALID JSON body: ' . mb_substr($raw, 0, 200));
        echo 'OK';
        exit;
    }

    // Skip stale updates (>24h) — prevents replay of pending backlog
    $updateTs = (int)($update['message']['date'] ?? $update['callback_query']['message']['date'] ?? 0);
    if ($updateTs > 0 && (time() - $updateTs) > 86400) {
        bot_log('SKIPPED stale update (age ' . (time() - $updateTs) . 's)');
        echo 'OK';
        exit;
    }

    // Build compact log summary
    $kind = 'unknown';
    $summary = '';
    if (isset($update['message'])) {
        $kind = 'message';
        $m = $update['message'];
        $summary = 'from ' . ($m['from']['id'] ?? '?') . ': ' . mb_substr($m['text'] ?? '(no text)', 0, 60);
        if (isset($m['contact'])) $summary = 'contact ' . ($m['contact']['phone_number'] ?? '');
    } elseif (isset($update['callback_query'])) {
        $kind = 'callback';
        $summary = 'data: ' . mb_substr($update['callback_query']['data'] ?? '?', 0, 60);
    }
    bot_log('RECV ' . $kind . ' | ' . $summary);

    // Process — full wrap so nothing escapes as HTML error
    try {
        if ($kind === 'message') {
            bot_process_message($update['message']);
        } elseif ($kind === 'callback') {
            bot_process_callback($update['callback_query']);
        }
        bot_log('DONE ' . $kind);
    } catch (Throwable $ex) {
        bot_log('ERROR | ' . get_class($ex) . ' | ' . $ex->getMessage() . ' | ' . $ex->getFile() . ':' . $ex->getLine());
    }

    echo 'OK';
    exit;
}

/* ============================================================
   Telegram API method wrappers (params auto-JSON via tg_api)
   ============================================================ */

function bot_send(int $chatId, string $html, ?array $inlineKb = null, mixed $replyKb = null): ?array
{
    $p = ['chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML'];
    if ($inlineKb !== null) {
        $p['reply_markup'] = ['inline_keyboard' => $inlineKb];
    } elseif ($replyKb === false) {
        $p['reply_markup'] = ['remove_keyboard' => true];
    } elseif ($replyKb !== null && is_array($replyKb)) {
        $p['reply_markup'] = ['keyboard' => $replyKb, 'resize_keyboard' => true, 'is_persistent' => true];
    }
    return tg_api('sendMessage', $p);
}

function bot_send_photo(int $chatId, string $photo, string $caption = '', ?array $inlineKb = null, mixed $replyKb = null): ?array
{
    $token = (string)Env::get('TELEGRAM_BOT_TOKEN', '');
    if ($token === '') return null;

    $appUrl = rtrim((string)Env::get('APP_URL', ''), '/');
    $photoParam = $photo;
    $localFile = null;

    if (!preg_match('/^https?:\/\//i', $photo)) {
        $baseDir = dirname(__DIR__);
        $candidate = $baseDir . '/' . ltrim($photo, '/\\');
        if (file_exists($candidate)) {
            $localFile = realpath($candidate);
        } elseif (file_exists($photo)) {
            $localFile = realpath($photo);
        }
        if ($appUrl !== '') {
            $photoParam = $appUrl . '/' . ltrim($photo, '/');
        }
    }

    $replyMarkup = null;
    if ($inlineKb !== null) {
        $replyMarkup = json_encode(['inline_keyboard' => $inlineKb]);
    } elseif ($replyKb === false) {
        $replyMarkup = json_encode(['remove_keyboard' => true]);
    } elseif ($replyKb !== null && is_array($replyKb)) {
        $replyMarkup = json_encode(['keyboard' => $replyKb, 'resize_keyboard' => true, 'is_persistent' => true]);
    }

    // 1) Try local multipart upload via CURLFile
    if ($localFile !== null && class_exists('CURLFile')) {
        try {
            $postFields = [
                'chat_id'    => $chatId,
                'photo'      => new CURLFile($localFile),
                'caption'    => $caption,
                'parse_mode' => 'HTML',
            ];
            if ($replyMarkup !== null) $postFields['reply_markup'] = $replyMarkup;

            $ch = curl_init("https://api.telegram.org/bot{$token}/sendPhoto");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postFields,
                CURLOPT_TIMEOUT        => 25,
            ]);
            $raw = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if (!$err && $raw) {
                $res = json_decode($raw, true);
                if (!empty($res['ok'])) return $res;
                bot_log('sendPhoto raw response: ' . $raw);
            }
        } catch (Throwable $ex) {
            bot_log('sendPhoto exception: ' . $ex->getMessage());
        }
    }

    // 2) Fallback to URL-based sendPhoto via tg_api
    if (preg_match('/^https?:\/\//i', $photoParam)) {
        $params = [
            'chat_id'    => $chatId,
            'photo'      => $photoParam,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ];
        if ($replyMarkup !== null) $params['reply_markup'] = json_decode($replyMarkup, true);
        $res = tg_api('sendPhoto', $params);
        if (!empty($res['ok'])) return $res;
    }

    // 3) Fallback to text message
    return bot_send($chatId, $caption, $inlineKb, $replyKb);
}

function bot_answer_callback(string $cbId, string $text = '', bool $alert = false): void
{
    tg_api('answerCallbackQuery', [
        'callback_query_id' => $cbId,
        'text'              => $text,
        'show_alert'        => $alert,
    ]);
}

/* ============================================================
   Keyboard builders (return inline_keyboard arrays; tg_api JSON-encodes)
   ============================================================ */

function bot_kb_main(?string $customAppUrl = null): array
{
    $appUrl = $customAppUrl ?: (rtrim((string)Env::get('APP_URL', ''), '/') . '/');
    return [
        [['text' => '🚀 SSAEE Panel', 'web_app' => ['url' => $appUrl]]],
        [['text' => '🆘 ارسال پیام پشتیبانی', 'callback_data' => 'bot:support:menu']],
        [['text' => '📱 ارسال شماره تماس', 'callback_data' => 'bot:send_phone']],
    ];
}

/**
 * دکمه‌های منوی کیبورد اصلی (Reply Keyboard) در پایین صفحه:
 * ۱. استعلام گواهینامه
 * ۲. راهنمای ربات (راهنمای پنل)
 * ۳. ارتباط با پشتیبانی
 * ۴. ارسال شماره تماس (ارسال مستقیم شماره کاربر با request_contact)
 */
function bot_kb_reply_menu(): array
{
    return [
        [
            ['text' => '📜 استعلام گواهینامه'],
            ['text' => 'ℹ️ راهنمای ربات'],
        ],
        [
            ['text' => '🆘 ارتباط با پشتیبانی'],
            ['text' => '📱 ارسال شماره تماس', 'request_contact' => true],
        ],
    ];
}

/**
 * بازگشت به منوی اول با ارسال دو پیام متوالی:
 * ۱. پیام راهنما با سه دکمه شیشه‌ای (Inline Keyboard)
 * ۲. پیام فعال‌سازی کیبورد منوی اصلی (Reply Keyboard)
 */
function bot_return_to_main_menu(int $chatId, string $customMsg = ''): void
{
    bot_state_clear($chatId);
    $text = $customMsg !== '' 
        ? $customMsg 
        : "به منوی اول بازگشتید. برای ادامه و استفاده از خدمات، دکمه های زیر استفاده کنید:";
    bot_send($chatId, $text, bot_kb_main());
    bot_send($chatId, "منوی اصلی کیبورد فعال شد 👇", null, bot_kb_reply_menu());
}



function bot_kb_support(string $stage): array
{
    $appUrl = rtrim((string)Env::get('APP_URL', ''), '/');
    return [
        [['text' => '🆘 درخواست پشتیبانی', 'callback_data' => 'bot:support:' . $stage]],
        [['text' => '🚀 SSAEE Panel', 'web_app' => ['url' => $appUrl . '/']]],
    ];
}

function bot_kb_cancel(string $cb = 'bot:cancel'): array
{
    return [[['text' => '❌ انصراف', 'callback_data' => $cb]]];
}

function bot_kb_support_confirm(): array
{
    return [
        [['text' => '✅ تایید و ارسال', 'callback_data' => 'bot:support:send']],
        [['text' => '✏️ نوشتن مجدد', 'callback_data' => 'bot:support:edit'], ['text' => '❌ انصراف', 'callback_data' => 'bot:cancel']],
    ];
}

function bot_kb_reply(int $ticketId): array
{
    return [[['text' => '💬 پاسخ به کاربر', 'callback_data' => 'bot:reply:' . $ticketId]]];
}

function bot_kb_panel(): array
{
    $appUrl = rtrim((string)Env::get('APP_URL', ''), '/');
    return [
        [['text' => '🚀 SSAEE Panel', 'web_app' => ['url' => $appUrl . '/']]],
        [['text' => '📩 ارسال پیام', 'url' => 'https://t.me/saee_admin']],
    ];
}

function bot_kb_direct_support(): array
{
    return [
        [['text' => '📩 ارسال پیام', 'url' => 'https://t.me/saee_admin']],
    ];
}

/* ============================================================
   Texts
   ============================================================ */

function bot_stage_label(string $stage): string
{
    if (str_starts_with($stage, 'cert:')) {
        return 'استعلام گواهی‌نامه (شماره: ' . substr($stage, 5) . ')';
    }
    return [
        'menu'  => 'منوی اصلی ربات',
        'help'  => 'بخش راهنما',
        'panel' => 'بخش پنل',
        'link'  => 'اتصال حساب تلگرام',
        'cert'  => 'استعلام گواهی‌نامه',
    ][$stage] ?? 'ربات تلگرام';
}

function bot_welcome_text(string $firstName): string
{
    $name = tg_esc($firstName ?: 'دوست عزیز');
    return "👋 سلام <b>{$name}</b>!\n\n"
        . "به ربات رسمی <b>انجمن علمی مهندسی برق علوم و تحقیقات (SSAEE)</b> خوش آمدید.\n\n"
        . "🤖 ثبت‌نام کارگاه‌ها و دریافت خودکار لینک گروه‌ها\n"
        . "📜 استعلام اصالت گواهینامه‌ها و مدارک\n"
        . "📢 دریافت اطلاعیه‌ها و بیانیه‌های مهم انجمن\n"
        . "🆘 ارتباط مستقیم با پشتیبانی انجمن\n\n"
        . "برای شروع و استفاده از خدمات، از دکمه‌های زیر استفاده فرمایید 👇";
}

/**
 * راهنمای یکپارچه پنل هوشمند و ربات تلگرام:
 * در صورت ثبت موارد توسط ادمین در setting('site_help_content')، همان موارد را قالب‌بندی کرده و نمایش می‌دهد؛
 * در غیر این صورت، راهنمای کامل، یکپارچه و ساختاریافته بخش‌های پنل ارسال می‌گردد.
 */
function bot_panel_help_text(): string
{
    $customItems = json_decode((string)setting('site_help_content', '[]'), true);
    if (is_array($customItems) && !empty($customItems)) {
        $body = "📖 <b>راهنمای جامع پنل و خدمات هوشمند SSAEE</b>\n\n"
            . "به بخش راهنمای سامانهٔ انجمن علمی مهندسی برق خوش آمدید:\n\n";
        foreach ($customItems as $it) {
            if (!is_array($it)) continue;
            $title = tg_esc(trim((string)($it['title'] ?? '')));
            $desc = tg_esc(trim((string)($it['body'] ?? '')));
            if ($title === '' && $desc === '') continue;
            $body .= "🔹 <b>{$title}</b>\n{$desc}\n\n";
        }
        $body .= "━━━━━━━━━━━━━━━━━━━━\n"
            . "💡 برای استفاده از امکانات کامل پنل، می‌توانید دکمهٔ «🚀 SSAEE Panel» را لمس کنید.";
        return $body;
    }

    return "📖 <b>راهنمای جامع پنل هوشمند و ربات تلگرام SSAEE</b>\n\n"
        . "سامانهٔ جامع انجمن علمی دانشجویی مهندسی برق (SSAEE) بستری یکپارچه جهت ارائهٔ خدمات علمی، آموزشی و اداری به دانشجویان محترم است.\n\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "💳 <b>۱. عضویت در انجمن علمی:</b>\n"
        . "با تکمیل فرم عضویت در پنل، مشخصات شما در سامانه ثبت شده و کارت عضویت دیجیتال همراه با کد اختصاصی برای شما صادر می‌گردد. اعضای انجمن از تخفیف‌های ویژه در کارگاه‌ها، امتیازات فعالیت و اولویت شرکت در رویدادها برخوردار می‌شوند.\n\n"
        . "🤖 <b>۲. کارگاه‌ها و دوره‌های آموزشی:</b>\n"
        . "لیست تمامی کارگاه‌های تخصصی، دوره‌های نرم‌افزاری و وبینارها در بخش کارگاه‌ها قابل مشاهده است. پس از ثبت‌نام، لینک اختصاصی گروه کلاسی در تلگرام به صورت خودکار برای شما ارسال می‌شود.\n\n"
        . "📜 <b>۳. استعلام و دریافت گواهینامه:</b>\n"
        . "تمامی گواهینامه‌های صادره انجمن دارای سریال یکتا و کد اعتبارسنجی هستند. شما می‌توانید در ربات (با زدن دکمهٔ «📜 استعلام گواهینامه») یا در سایت، اصالت گواهی را استعلام نمایید. گواهینامه‌های دوره‌های گذرانده‌شده نیز در بخش «گواهینامه‌های من» در پروفایلتان قابل مشاهده و دانلود است.\n\n"
        . "📚 <b>۴. گروه‌های درسی ترم جاری:</b>\n"
        . "در این بخش لینک گروه‌های درسی تلگرامی رشتهٔ مهندسی برق قرار می‌گیرد تا دانشجویان بتوانند با اساتید و هم‌کلاسی‌های خود در ارتباط باشند (ثبت لینک‌ها منوط به درخواست گروه‌های آموزشی است).\n\n"
        . "🤝 <b>۵. درخواست همکاری و برگزاری کلاس:</b>\n"
        . "دانشجویان و اساتید محترم می‌توانند درخواست‌های برگزاری کارگاه‌های تخصصی، کلاس‌های حل تمرین و همکاری‌های مشترک بین‌دانشگاهی یا شرکتی را از طریق بخش «همکاری با ما» در پنل ثبت نمایند.\n\n"
        . "🔗 <b>۶. اتصال حساب پنل به تلگرام:</b>\n"
        . "با اتصال حساب کاربری پنل به این ربات، اطلاعیه‌های فوری، نوتیفیکیشن صدور گواهی‌نامه و لینک کارگاه‌های ثبت‌نامی بلافاصله در همین چت برای شما ارسال خواهد شد.\n\n"
        . "🆘 <b>۷. پشتیبانی و ارتباط مستقیم:</b>\n"
        . "در هر زمان با انتخاب گزینهٔ «ارتباط با پشتیبانی» می‌توانید سؤالات، مشکلات و پیشنهادات خود را مستقیماً برای تیم مدیریت ارسال و پاسخ آن را دریافت کنید.\n\n"
        . "━━━━━━━━━━━━━━━━━━━━\n"
        . "🚀 برای ورود به سامانه، از دکمهٔ زیر استفاده فرمایید 👇";
}

function bot_panel_help_send(int $chatId): void
{
    $appUrl = rtrim((string)Env::get('APP_URL', ''), '/');
    $inlineKb = [
        [['text' => '🚀 SSAEE Panel', 'web_app' => ['url' => $appUrl . '/']]],
        [['text' => '🆘 ارتباط با پشتیبانی', 'callback_data' => 'bot:support:help']],
    ];
    bot_send($chatId, bot_panel_help_text(), $inlineKb, bot_kb_reply_menu());
}

function bot_help_text(): string
{
    return bot_panel_help_text();
}

/**
 * ارسال راهنمای جست‌وجوی گواهینامه همراه با تصویر نمونه (assets/cert-example.png)
 */
function bot_cert_search_prompt(int $chatId): void
{
    bot_state_set($chatId, 'cert_search', '');

    $caption = "🔍 <b>راهنمای جست‌وجو و استعلام گواهی‌نامه</b>\n\n"
        . "شمارهٔ گواهی‌نامه‌ها از الگوی <b dir=\"ltr\">YYYYMM-xxxxx</b> پیروی می‌کند (سال و ماه صدور + شمارهٔ سریال).\n\n"
        . "برای استعلام، وارد کردن هر یک از ۳ حالت زیر کافی است:\n"
        . "🔴 <b>حالت ۱:</b> کد کامل با خط‌تیره — مانند <code>202509-12345</code>\n"
        . "🟡 <b>حالت ۲:</b> کد کامل بدون خط‌تیره — مانند <code>20250912345</code>\n"
        . "🟢 <b>حالت ۳:</b> فقط شمارهٔ سریال (۴ تا ۶ رقم آخر) — مانند <code>12345</code>\n\n"
        . "━━━━━━━━━━━━━━━━━━━━\n"
        . "✍️ <b>لطفاً کد یا شماره سریال گواهینامه را ارسال فرمایید:</b>\n"
        . "<i>(جهت انصراف، دکمهٔ «❌ انصراف» را لمس کنید یا /cancel را بفرستید)</i>";

    $img = 'assets/cert-example.png';
    $cancelKb = [[['text' => '❌ انصراف']]];

    bot_send_photo($chatId, $img, $caption, null, $cancelKb);
}

function bot_delete_wait_msg(int $chatId, int $waitMsgId): void
{
    if ($waitMsgId <= 0) return;
    sleep(3);
    tg_api('deleteMessage', [
        'chat_id'    => $chatId,
        'message_id' => $waitMsgId,
    ]);
}

/**
 * پردازش کد گواهینامه و استعلام در جدول certificates
 */
function bot_cert_search_process(int $chatId, string $rawInput): void
{
    @set_time_limit(90);
    @ignore_user_abort(true);

    // ۱. بلافاصله ارسال آیکن ساعت شنی
    $waitMsg = bot_send($chatId, "⏳");
    $waitMsgId = (int)($waitMsg['result']['message_id'] ?? 0);

    $input = trim(fa2en($rawInput));
    $clean = str_replace(["\u{200C}", "\u{200F}", ' ', "\t"], '', $input);
    $cbCert = mb_substr($clean, 0, 40);

    $cert = null;
    $rows = [];

    if ($clean !== '' && mb_strlen($clean) <= 50 && preg_match('/^[\p{L}\p{N}\-]{3,50}$/u', $clean)) {
        // شناسایی ستون‌های موجود در جدول certificates
        $cols = ['ID', 'NameFa', 'NameEn', 'Course', 'CertType', 'IssueDate', 'ExpDate'];
        try {
            $hasCourseEn = bot_db("SHOW COLUMNS FROM certificates LIKE 'CourseEn'", [], 'cert_chk_col')->fetch();
            if ($hasCourseEn) $cols[] = 'CourseEn';
        } catch (Throwable $e) {}
        $colList = implode(', ', $cols);

        $norm = str_replace('-', '', $clean);

        // ۱) روش اول و دوم: تطابق دقیق با یا بدون خط‌تیره
        $cert = bot_db(
            "SELECT {$colList} FROM certificates 
             WHERE TRIM(ID) = ? 
                OR REPLACE(TRIM(ID), '-', '') = ? 
             LIMIT 1",
            [$clean, $norm],
            'cert_exact'
        )->fetch();

        if ($cert === null && strlen($norm) >= 10 && strlen($norm) <= 12) {
            $withDash = substr($norm, 0, 6) . '-' . substr($norm, 6);
            $cert = bot_db(
                "SELECT {$colList} FROM certificates WHERE TRIM(ID) = ? LIMIT 1",
                [$withDash],
                'cert_with_dash'
            )->fetch();
        }

        // ۲) روش سوم: تطابق با فقط شماره سریال (پایان کد یا بخش بعد از خط‌تیره)
        if ($cert === null) {
            $rows = bot_db(
                "SELECT {$colList} FROM certificates
                 WHERE (ID LIKE '%-%' AND SUBSTRING_INDEX(TRIM(ID), '-', -1) = ?)
                    OR TRIM(ID) LIKE ?
                    OR REPLACE(TRIM(ID), '-', '') LIKE ?
                 ORDER BY IssueDate DESC, ID DESC LIMIT 5",
                [$clean, '%-' . $clean, '%' . $norm],
                'cert_serial'
            )->fetchAll();

            if (!empty($rows)) {
                if (count($rows) === 1) {
                    $cert = $rows[0];
                } else {
                    // چند گواهی پیدا شد: نمایش گزینه‌ها با دکمه شیشه‌ای
                    bot_state_clear($chatId);
                    $multiText = "📋 <b>چند گواهینامه با سریال <code>{$clean}</code> پیدا شد:</b>\n\n"
                        . "برای مشاهدهٔ جزئیات هر گواهینامه، روی گزینهٔ مربوطه کلیک کنید:";
                    $inlineKb = [];
                    foreach ($rows as $r) {
                        $btnLabel = $r['ID'] . ' — ' . ($r['NameFa'] ?: $r['NameEn']);
                        $inlineKb[] = [['text' => $btnLabel, 'callback_data' => 'bot:cert:view:' . $r['ID']]];
                    }
                    bot_send($chatId, $multiText, $inlineKb, bot_kb_reply_menu());
                    bot_delete_wait_msg($chatId, $waitMsgId);
                    return;
                }
            }
        }
    }

    // گواهینامه با موفقیت یافت شد
    if ($cert !== null) {
        bot_state_clear($chatId);
        bot_send_certificate_result($chatId, $cert);
        bot_delete_wait_msg($chatId, $waitMsgId);
        return;
    }

    // در صورتی که استعلام پاسخی دریافت نکرد (عدم یافت یا عدم اعتبار):
    // ۱) انتظار ۱۰ ثانیه در وضعیت ساعت شنی
    sleep(10);

    // ۲) ارسال پیام صبوری
    bot_send(
        $chatId,
        "⏳ استعلام گواهی و ارسال نتیجه ممکن است تا ۳۰ ثانیه به طول بینجامد، لطفاً صبور باشید."
    );

    // ۳) انتظار ۱۰ ثانیه پس از این پیام (مجموعاً ۲۰ ثانیه)
    sleep(10);

    // ۴) توقف فرآیند جستجو و ارسال پیام نهایی با عبارت معتبر نبوده است دارای آندرلاین
    bot_state_clear($chatId);
    $failText = "❌ <b>استعلام ناموفق بود.</b>\n\n"
        . "لطفاً سریال یا شماره گواهی‌نامه را بررسی کنید و از صحت آن اطمینان حاصل کنید و مجدداً تلاش کنید.\n\n"
        . "در صورتی که از صحت آن مطمئن هستید، گواهی ارسالی <u>معتبر نبوده است</u>.";

    bot_send(
        $chatId,
        $failText,
        [
            [['text' => '🆘 پیام به پشتیبانی', 'callback_data' => 'bot:support:cert:' . ($cbCert ?: 'invalid')]],
            [['text' => '❌ انصراف', 'callback_data' => 'bot:cancel']]
        ],
        bot_kb_reply_menu()
    );

    if ($waitMsgId > 0) {
        tg_api('deleteMessage', [
            'chat_id'    => $chatId,
            'message_id' => $waitMsgId,
        ]);
    }
}

/**
 * نمایش نتیجه استعلام به شکل نام فارسی، نام انگلیسی، نام دوره، نام دوره انگلیسی،
 * نوع گواهینامه، تاریخ صدور و تاریخ انقضا
 */
function bot_send_certificate_result(int $chatId, array $cert): void
{
    $id = tg_esc($cert['ID'] ?? '—');
    $nameFa = tg_esc(trim((string)($cert['NameFa'] ?? '—')) ?: '—');
    $nameEn = tg_esc(trim((string)($cert['NameEn'] ?? '—')) ?: '—');
    $courseFa = tg_esc(trim((string)($cert['Course'] ?? '—')) ?: '—');
    $courseEn = tg_esc(trim((string)($cert['CourseEn'] ?? '—')) ?: '—');
    $certType = tg_esc(trim((string)($cert['CertType'] ?? '')) ?: 'گواهی حضور و مشارکت');

    $issueDate = $cert['IssueDate'] ?? null;
    $expDate = $cert['ExpDate'] ?? null;

    $issueJalali = ($issueDate && strtotime($issueDate)) ? date_jalali((string)$issueDate) : '—';
    $issueGregorian = $issueDate ? date('Y/m/d', strtotime((string)$issueDate)) : '';
    $issueStr = $issueJalali . ($issueGregorian ? " ({$issueGregorian})" : "");

    $isExpired = false;
    if ($expDate !== null && $expDate !== '' && strtotime((string)$expDate)) {
        $expJalali = date_jalali((string)$expDate);
        $expGregorian = date('Y/m/d', strtotime((string)$expDate));
        $expStr = $expJalali . " ({$expGregorian})";
        if (strtotime((string)$expDate) < time()) {
            $isExpired = true;
        }
    } else {
        $expStr = 'دائمی (فاقد انقضا)';
    }

    $validityStatus = $isExpired
        ? "⛔ <b>وضعیت اعتبار:</b> منقضی شده"
        : "✅ <b>وضعیت اعتبار:</b> معتبر و مورد تأیید";

    $appUrl = rtrim((string)Env::get('APP_URL', ''), '/');
    $certUrl = $appUrl . '/#certificates';

    $text = "📜 <b>نتیجهٔ استعلام رسمی گواهینامه</b>\n\n"
        . "🔹 <b>شماره گواهینامه:</b> <code>{$id}</code>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n"
        . "👤 <b>نام فارسی:</b> {$nameFa}\n"
        . "🔤 <b>نام انگلیسی:</b> {$nameEn}\n"
        . "📚 <b>نام دوره:</b> {$courseFa}\n"
        . "🌐 <b>نام دوره انگلیسی:</b> {$courseEn}\n"
        . "🏷 <b>نوع گواهینامه:</b> {$certType}\n"
        . "📅 <b>تاریخ صدور:</b> {$issueStr}\n"
        . "⏳ <b>تاریخ انقضا:</b> {$expStr}\n"
        . "━━━━━━━━━━━━━━━━━━━━\n"
        . "{$validityStatus}\n\n"
        . "🏛 <b>صادرکننده:</b> انجمن علمی مهندسی برق — دانشگاه آزاد اسلامی واحد علوم و تحقیقات";

    $inlineKb = [
        [['text' => '🚀 SSAEE Panel', 'web_app' => ['url' => $certUrl]]],
        [['text' => '🔍 استعلام مجدد', 'callback_data' => 'bot:cert:search']],
    ];

    bot_send($chatId, $text, $inlineKb, bot_kb_reply_menu());
}

/**
 * مشاهده گواهی با ID خاص پس از انتخاب از بین نتایج چندگانه
 */
function bot_cert_view_by_id(int $chatId, string $certId): void
{
    $waitMsg = bot_send($chatId, "⏳");
    $waitMsgId = (int)($waitMsg['result']['message_id'] ?? 0);

    $cols = ['ID', 'NameFa', 'NameEn', 'Course', 'CertType', 'IssueDate', 'ExpDate'];
    try {
        $hasCourseEn = bot_db("SHOW COLUMNS FROM certificates LIKE 'CourseEn'", [], 'cert_chk_col')->fetch();
        if ($hasCourseEn) $cols[] = 'CourseEn';
    } catch (Throwable $e) {}
    $colList = implode(', ', $cols);

    $cert = bot_db("SELECT {$colList} FROM certificates WHERE ID = ? LIMIT 1", [$certId], 'cert_view_id')->fetch();
    if ($cert === null) {
        bot_send($chatId, "❌ گواهینامهٔ مورد نظر یافت نشد.", null, bot_kb_reply_menu());
        bot_delete_wait_msg($chatId, $waitMsgId);
        return;
    }

    bot_send_certificate_result($chatId, $cert);
    bot_delete_wait_msg($chatId, $waitMsgId);
}

/**
 * ارسال راهنمای اشتراک‌گذاری شماره تلفن
 */
function bot_send_phone_request(int $chatId): void
{
    bot_state_set($chatId, 'await_contact', '');
    bot_send(
        $chatId,
        "📱 <b>ارسال و اشتراک‌گذاری شماره تماس</b>\n\n"
        . "جهت احراز هویت، ورود یا اتصال حساب تلگرام به پنل، لطفاً دکمهٔ <b>«📱 ارسال شماره تماس»</b> را در کیبورد پایین صفحه لمس فرمایید:\n\n"
        . "<i>(برای لغو فرآیند می‌توانید /cancel را بفرستید)</i>",
        null,
        [
            [['text' => '📱 ارسال شماره تماس', 'request_contact' => true]],
            [['text' => '❌ انصراف']]
        ]
    );
}

/* ============================================================
   Message router
   ============================================================ */

function bot_process_message(array $m): void
{
    $chatId = (int)($m['chat']['id'] ?? 0);
    if ($chatId === 0) return;

    $text = trim((string)($m['text'] ?? ''));
    $firstName = (string)($m['from']['first_name'] ?? '');
    $lastName = (string)($m['from']['last_name'] ?? '');

    // Contact share (from self — user_id is optional, fallback to from.id)
    if (isset($m['contact'])) {
        $contactUserId = (int)($m['contact']['user_id'] ?? $chatId);
        if ($contactUserId !== 0 && $contactUserId !== $chatId) {
            bot_send($chatId, "⚠️ لطفاً شماره تلفن متعلق به همین حساب تلگرام را با زدن دکمهٔ «📱 اشتراک‌گذاری شماره» ارسال کنید.");
            return;
        }
        bot_handle_contact($chatId, (string)$m['contact']['phone_number'], $firstName, $lastName);
        return;
    }

    if ($text === '') return;

    // /start link_CODE → telegram connect flow
    if (preg_match('/^\/start\s+link_([A-Fa-f0-9]{8})$/i', $text, $mm)) {
        bot_connect_start($chatId, strtoupper($mm[1]), $firstName, $lastName);
        return;
    }

    // /start wl_CODE → web quick-login handshake (site waits with countdown)
    if (preg_match('/^\/start\s+wl_([A-Fa-f0-9]{6,20})$/i', $text, $mm)) {
        bot_weblogin_start_code($chatId, strtoupper($mm[1]), $firstName, $lastName);
        return;
    }

    // /start weblogin → legacy quick-login entry
    if ($text === '/start weblogin') {
        bot_state_clear($chatId);
        bot_weblogin_start($chatId);
        return;
    }

    // Global commands
    if ($text === '/start') {
        bot_state_clear($chatId);
        bot_send($chatId, bot_welcome_text($firstName), bot_kb_main(), bot_kb_reply_menu());
        return;
    }

    if ($text === '/cancel' || $text === '❌ انصراف' || $text === 'انصراف') {
        bot_return_to_main_menu($chatId);
        return;
    }

    // Stateful flows
    $state = bot_state_get($chatId);
    if ($state !== null) {
        $st = (string)$state['state'];

        if ($st === 'cert_search') {
            bot_cert_search_process($chatId, $text);
            return;
        }
        if ($st === 'support_msg') {
            bot_support_collect($chatId, $text, (string)$state['data']);
            return;
        }
        if ($st === 'support_confirm') {
            bot_send($chatId, "👆 برای ارسال یا انصراف، از دکمه‌های پیام قبلی استفاده کنید.", bot_kb_support_confirm());
            return;
        }
        if ($st === 'admin_reply') {
            bot_admin_reply_send($chatId, $text, (int)$state['data']);
            return;
        }
        if ($st === 'await_contact') {
            bot_send($chatId, "🔗 <b>اتصال حساب شما در انتظار تأیید است.</b>\n\nلطفاً شماره موبایل خود را با دکمه «📱 ارسال شماره تماس» ارسال کنید.\nبرای لغو: /cancel", null,
                [[['text' => '📱 ارسال شماره تماس', 'request_contact' => true]], [['text' => '❌ انصراف']]]);
            return;
        }
        if ($st === 'await_weblogin') {
            bot_send($chatId, "⚡ <b>ورود سریع — در انتظار تأیید شماره است.</b>\n\nبا دکمه زیر شماره موبایل خود را ارسال کنید.\nبرای لغو: /cancel", null,
                [[['text' => '📱 ارسال شماره تماس', 'request_contact' => true]], [['text' => '❌ انصراف']]]);
            return;
        }
    }

    // استعلام سریع در صورت ارسال مستقیم کد گواهینامه (مانند 202509-12345، 20250912345 یا سریال ۳ تا ۸ رقمی)
    $cleanText = str_replace('-', '', fa2en($text));
    if (
        !in_array($text, ['/help', '/cert', '/start', '/panel', '/support', '/cancel', '❌ انصراف', 'انصراف'], true)
        && (
            preg_match('/^20\d{4}-?[A-Za-z0-9]{3,8}$/D', fa2en($text))
            || (strlen($cleanText) >= 3 && strlen($cleanText) <= 8 && preg_match('/^[A-Za-z0-9]+$/D', $cleanText) && !str_starts_with($text, '/'))
        )
    ) {
        bot_cert_search_process($chatId, $text);
        return;
    }

    switch ($text) {
        case '/help':
        case 'ℹ️ راهنمای ربات':
        case 'راهنمای ربات':
        case 'راهنما':
            bot_panel_help_send($chatId);
            return;

        case '/cert':
        case '/certificate':
        case '📜 استعلام گواهینامه':
        case 'استعلام گواهینامه':
            bot_cert_search_prompt($chatId);
            return;

        case '/panel':
            bot_send($chatId, "🚀 برای ورود به پنل هوشمند، از دکمه‌های زیر استفاده فرمایید:", bot_kb_main(), bot_kb_reply_menu());
            return;

        case '/support':
        case '🆘 ارتباط با پشتیبانی':
        case 'ارتباط با پشتیبانی':
            bot_support_start($chatId, 'menu');
            return;

        case '📱 ارسال شماره':
        case '📱 ارسال شماره تماس':
        case 'ارسال شماره':
            bot_send_phone_request($chatId);
            return;

        case '/weblogin':
            bot_weblogin_start($chatId);
            return;

        default:
            bot_send($chatId, "🤔 متوجه پیام شما نشدم.\nجهت دسترسی به امکانات سامانه، از منوی زیر یا دکمه‌های شیشه‌ای استفاده فرمایید 👇", bot_kb_main(), bot_kb_reply_menu());
    }
}

function bot_process_callback(array $cb): void
{
    $chatId = (int)($cb['message']['chat']['id'] ?? 0);
    $data = (string)($cb['data'] ?? '');
    $cbId = (string)($cb['id'] ?? '');
    if ($chatId === 0 || $data === '') return;

    tg_api('answerCallbackQuery', ['callback_query_id' => $cbId]);

    // bot:cert:view:<certId>
    if (preg_match('/^bot:cert:view:([A-Za-z0-9\-]+)$/', $data, $mm)) {
        bot_cert_view_by_id($chatId, $mm[1]);
        return;
    }

    // bot:support:cert:<certId>
    if (preg_match('/^bot:support:cert:(.+)$/', $data, $mm)) {
        bot_support_start($chatId, 'cert:' . trim($mm[1]));
        return;
    }

    // bot:support:<stage> — stage is [a-zA-Z0-9_\-:]+, so 'send'/'cancel'/'edit' never match here
    if (preg_match('/^bot:support:([a-zA-Z0-9_\-:]+)$/', $data, $mm)
        && !in_array($mm[1], ['send', 'cancel', 'edit'], true)) {
        bot_support_start($chatId, $mm[1]);
        return;
    }

    // bot:reply:<ticketId>
    if (preg_match('/^bot:reply:(\d+)$/', $data, $mm)) {
        bot_admin_reply_start($chatId, (int)$mm[1], $cbId);
        return;
    }

    // Survey rating: bot:survey:<registration_id>:<rating>
    if (preg_match('/^bot:survey:([A-Za-z0-9\-]+):([1-5])$/', $data, $mm)) {
        bot_survey_answer($chatId, $mm[1], (int)$mm[2]);
        return;
    }

    switch ($data) {
        case 'bot:cancel':
        case 'bot:support:cancel':
            bot_return_to_main_menu($chatId);
            return;

        case 'bot:cert:search':
            bot_cert_search_prompt($chatId);
            return;

        case 'bot:help':
            bot_panel_help_send($chatId);
            return;

        case 'bot:send_phone':
            bot_send_phone_request($chatId);
            return;

        case 'bot:support:send':
            bot_support_send($chatId);
            return;

        case 'bot:support:edit':
            $st = bot_state_get($chatId);
            $stage = 'menu';
            if ($st !== null && $st['state'] === 'support_confirm') {
                $d = json_decode((string)$st['data'], true);
                $stage = is_array($d) ? (string)($d['stage'] ?? 'menu') : 'menu';
            }
            bot_support_start($chatId, $stage);
            return;

        case 'bot:reply:cancel':
            bot_return_to_main_menu($chatId, "✅ پاسخ لغو شد.");
            return;
    }
}

/* ============================================================
   Support flow (user side)
   ============================================================ */

function bot_support_start(int $chatId, string $stage): void
{
    $stage = preg_replace('/[^a-zA-Z0-9_\-:]/', '', $stage) ?: 'menu';
    bot_state_set($chatId, 'support_msg', $stage);

    $extraCert = '';
    if (str_starts_with($stage, 'cert:')) {
        $certNum = tg_esc(substr($stage, 5));
        $extraCert = "📜 <b>شماره گواهینامهٔ استعلامی:</b> <code>{$certNum}</code>\n"
            . "<i>(این شماره به همراه پیام شما برای پشتیبانی ارسال خواهد شد)</i>\n\n";
    }

    bot_send(
        $chatId,
        "🆘 <b>درخواست پشتیبانی</b>\n\n"
        . $extraCert
        . "لطفاً <b>متن پیام خود را بنویسید</b> و ارسال کنید.\n\n"
        . "✏️ مشکل یا سؤال خود را دقیق توضیح دهید تا سریع‌تر پاسخ بگیرید.\n"
        . "ℹ️ برای لغو، /cancel را بفرستید یا دکمه انصراف را بزنید.",
        bot_kb_cancel()
    );
}

function bot_support_collect(int $chatId, string $text, string $stage): void
{
    if (mb_strlen($text) < 5) {
        bot_send($chatId, "⚠️ متن پیام خیلی کوتاه است. لطفاً توضیح کامل‌تری بنویسید.", bot_kb_cancel());
        return;
    }
    if (mb_strlen($text) > 2000) {
        bot_send($chatId, "⚠️ متن پیام بیش از حد طولانی است (حداکثر ۲۰۰۰ کاراکتر).", bot_kb_cancel());
        return;
    }
    if (!rl_check('bot_support', (string)$chatId, 3, 3600)) {
        bot_state_clear($chatId);
        bot_send($chatId, "⚠️ تعداد درخواست‌های پشتیبانی شما زیاد است.\nلطفاً کمی صبر کنید و بعد تلاش کنید.", bot_kb_main());
        return;
    }

    bot_state_set($chatId, 'support_confirm', json_encode(['text' => $text, 'stage' => $stage], JSON_UNESCAPED_UNICODE));

    $profile = bot_get_profile($chatId);
    $name = $profile['name'] ?? '—';
    $phone = $profile['phone'] ?? '';

    $certLine = '';
    if (str_starts_with($stage, 'cert:')) {
        $certNum = tg_esc(substr($stage, 5));
        $certLine = "📜 شماره گواهی استعلامی: <code>{$certNum}</code>\n";
    }

    $info = "ℹ️ <b>علاوه بر متن پیام، این اطلاعات به تیم پشتیبانی ارسال می‌شود:</b>\n\n"
        . "👤 نام حساب: <b>" . tg_esc($name) . "</b>\n"
        . "🆔 آیدی عددی: <code>{$chatId}</code>\n"
        . "🕐 زمان: " . date('H:i') . ' — ' . date_jalali(date('Y-m-d')) . "\n"
        . "📍 محل درخواست: " . tg_esc(bot_stage_label($stage)) . "\n"
        . $certLine
        . ($phone !== '' ? "📱 شماره تماس: <code>" . tg_esc($phone) . "</code> (اشتراک قبلی)\n" : "")
        . "\n❓ آیا تایید می‌کنید پیام ارسال شود؟";

    bot_send(
        $chatId,
        "📨 <b>پیام شما:</b>\n\n<i>" . tg_esc($text) . "</i>\n\n━━━━━━━━━━━━\n\n" . $info,
        bot_kb_support_confirm()
    );
}

function bot_support_send(int $chatId): void
{
    $st = bot_state_get($chatId);
    if ($st === null || $st['state'] !== 'support_confirm') {
        bot_send($chatId, "⚠️ درخواستی برای ارسال پیدا نشد.\nاز دکمه 🆘 دوباره اقدام کنید.", bot_kb_support('menu'));
        return;
    }

    $d = json_decode((string)$st['data'], true);
    $text = is_array($d) ? (string)($d['text'] ?? '') : '';
    $stage = is_array($d) ? (string)($d['stage'] ?? 'menu') : 'menu';
    if ($text === '') {
        bot_state_clear($chatId);
        bot_send($chatId, "⚠️ خطا در بازیابی پیام. دوباره تلاش کنید.", bot_kb_support('menu'));
        return;
    }

    $profile = bot_get_profile($chatId);
    $name = $profile['name'] ?? 'کاربر';
    $phone = $profile['phone'] ?? '';
    $time = date('H:i') . ' — ' . date_jalali(date('Y-m-d'));

    bot_db(
        "INSERT INTO bot_support_sessions (user_telegram_id, user_name) VALUES (?, ?)",
        [$chatId, mb_substr($name, 0, 250)],
        'support_insert_session'
    );
    $sessionId = db_id();

    $certLine = '';
    $msgPrefix = '';
    if (str_starts_with($stage, 'cert:')) {
        $cId = substr($stage, 5);
        $certLine = "📜 شماره گواهینامه استعلامی: <code>" . tg_esc($cId) . "</code>\n";
        $msgPrefix = "[استعلام گواهینامه: {$cId}]\n\n";
    }

    bot_db(
        "INSERT INTO bot_messages (session_id, from_telegram_id, message, direction) VALUES (?, ?, ?, 'user_to_admin')",
        [$sessionId, $chatId, mb_substr($msgPrefix . $text, 0, 3000)],
        'support_insert_message'
    );
    bot_state_clear($chatId);

    $adminText = "🆘 <b>درخواست پشتیبانی جدید</b> <code>#{$sessionId}</code>\n\n"
        . ($certLine !== '' ? "📎 <b>اطلاعات ضمیمه:</b>\n" . $certLine . "\n" : "")
        . "💬 <b>متن پیام کاربر:</b>\n"
        . tg_esc($text) . "\n\n"
        . "━━━━━━━━━━━━\n\n"
        . "👤 نام حساب: <b>" . tg_esc($name) . "</b>\n"
        . "🆔 آیدی عددی: <code>{$chatId}</code>\n"
        . "🕐 زمان: {$time}\n"
        . "📍 محل درخواست: " . tg_esc(bot_stage_label($stage)) . "\n"
        . ($phone !== '' ? "📱 شماره تماس: <code>" . tg_esc($phone) . "</code>\n" : "")
        . "\n👇 برای پاسخ، دکمه زیر را بزنید:";

    $ids = bot_admin_ids();
    if (!$ids) {
        $fallback = (int)Env::get('TELEGRAM_ADMIN_CHAT_ID', '0');
        $ids = $fallback > 0 ? [$fallback] : [];
    }

    $delivered = 0;
    foreach ($ids as $i => $aid) {
        if ($aid <= 0) continue;
        // reply button must go through reply_markup — tg_send's 3rd arg is raw API params
        $res = tg_send($aid, $adminText, ['reply_markup' => ['inline_keyboard' => bot_kb_reply($sessionId)]]);
        if ($res !== null && ($res['ok'] ?? false)) $delivered++;
        if ($i > 0 && $i % 10 === 0) usleep(200000);
    }

    if ($delivered === 0) {
        bot_send($chatId, "⚠️ در حال حاضر تیم پشتیبانی در دسترس نیست.\nلطفاً به @saee_admin در تلگرام پیام دهید.", bot_kb_panel());
        return;
    }

    bot_send(
        $chatId,
        "✅ <b>پیام شما با موفقیت ارسال شد!</b>\n\n"
        . "🎫 کد پیگیری: <code>#{$sessionId}</code>\n\n"
        . "⏳ تیم پشتیبانی در اسرع وقت پاسخ می‌دهد؛ پاسخ در همین چت برای شما ارسال می‌شود.",
        bot_kb_main(),
        bot_kb_reply_menu()
    );
}

function bot_support_cancel(int $chatId): void
{
    bot_return_to_main_menu($chatId);
}

/* ============================================================
   Support flow (admin side)
   ============================================================ */

function bot_admin_reply_start(int $chatId, int $sessionId, string $cbId = ''): void
{
    if (!bot_is_admin($chatId)) {
        if ($cbId !== '') {
            bot_answer_callback($cbId, '⛔ شما دسترسی پشتیبانی ندارید', true);
        }
        bot_send($chatId, "⛔ شما به بخش پشتیبانی دسترسی ندارید.");
        return;
    }

    $session = bot_db(
        "SELECT id, user_telegram_id, status FROM bot_support_sessions WHERE id = ? LIMIT 1",
        [$sessionId],
        'reply_get_session'
    )->fetch();
    if ($session === null) {
        bot_send($chatId, "⚠️ این درخواست یافت نشد.");
        return;
    }
    if ($session['status'] === 'closed') {
        bot_send($chatId, "ℹ️ این درخواست قبلاً پاسخ داده شده و بسته شده است.", bot_kb_support('menu'));
        return;
    }

    bot_state_set($chatId, 'admin_reply', (string)$sessionId);
    bot_send(
        $chatId,
        "💬 <b>پاسخ به درخواست <code>#{$sessionId}</code></b>\n\n"
        . "لطفاً متن پاسخ خود را بنویسید و ارسال کنید.\n\n"
        . "🔒 در پیام پاسخ، فقط نام شما و زمان پاسخ برای کاربر ارسال می‌شود.\n"
        . "ℹ️ برای لغو، /cancel یا دکمه زیر.",
        bot_kb_cancel('bot:reply:cancel')
    );
}

function bot_admin_reply_send(int $chatId, string $text, int $sessionId): void
{
    if (!bot_is_admin($chatId)) {
        bot_state_clear($chatId);
        bot_send($chatId, "⛔ شما به بخش پشتیبانی دسترسی ندارید.");
        return;
    }
    if (mb_strlen($text) < 2) {
        bot_send($chatId, "⚠️ متن پاسخ خیلی کوتاه است.", bot_kb_cancel('bot:reply:cancel'));
        return;
    }
    if (mb_strlen($text) > 3000) {
        bot_send($chatId, "⚠️ متن پاسخ بیش از حد طولانی است (حداکثر ۳۰۰۰ کاراکتر).", bot_kb_cancel('bot:reply:cancel'));
        return;
    }

    $session = bot_db(
        "SELECT id, user_telegram_id FROM bot_support_sessions WHERE id = ? AND status = 'open' LIMIT 1",
        [$sessionId],
        'reply_get_open_session'
    )->fetch();
    if ($session === null) {
        bot_state_clear($chatId);
        bot_send($chatId, "⚠️ این درخواست قبلاً بسته شده است.", bot_kb_main());
        return;
    }

    $admin = bot_db(
        "SELECT full_name FROM users WHERE telegram_id = ? AND is_active = 1 LIMIT 1",
        [$chatId],
        'reply_get_admin'
    )->fetch();
    $supporterName = $admin['full_name'] ?? ('پشتیبان #' . $chatId);
    $time = date('H:i') . ' — ' . date_jalali(date('Y-m-d'));

    $userText = "💬 <b>پاسخ پشتیبانی SSAEE</b>\n\n"
        . tg_esc($text) . "\n\n"
        . "━━━━━━━━━━━━\n"
        . "👤 پشتیبان: <b>" . tg_esc($supporterName) . "</b>\n"
        . "🕐 زمان پاسخ: {$time}";

    $sent = tg_send((int)$session['user_telegram_id'], $userText);
    if ($sent === null || !($sent['ok'] ?? false)) {
        bot_send($chatId, "⚠️ ارسال پاسخ ناموفق بود.\n(احتمالاً کاربر ربات را بلاک کرده است)");
        return;
    }

    bot_db(
        "INSERT INTO bot_messages (session_id, from_telegram_id, to_telegram_id, message, direction)
         VALUES (?, ?, ?, ?, 'admin_to_user')",
        [$sessionId, $chatId, (int)$session['user_telegram_id'], mb_substr($text, 0, 3000)],
        'reply_insert_message'
    );
    bot_db(
        "UPDATE bot_support_sessions SET status = 'closed', closed_at = NOW() WHERE id = ?",
        [$sessionId],
        'reply_close_session'
    );
    bot_state_clear($chatId);

    bot_send($chatId, "✅ پاسخ شما به درخواست <code>#{$sessionId}</code> ارسال شد.", bot_kb_main());
}

/* ============================================================
   Workshop satisfaction survey
   ============================================================ */

function bot_survey_answer(int $chatId, string $regId, int $rating): void
{
    bot_db(
        "UPDATE workshop_surveys SET rating = ?, answered_at = NOW() WHERE registration_id = ? AND chat_id = ? AND rating IS NULL",
        [$rating, $regId, $chatId],
        'survey_answer'
    );
    $emojis = ['', '😠', '😕', '😐', '😊', '🤩'];
    $emoji = $emojis[$rating] ?? '⭐';
    bot_send($chatId, "{$emoji} <b>ممنون از بازخورد شما!</b>\n\nنظر شما ثبت شد ({$rating} از ۵).");
}

/* ============================================================
   Telegram connect flow (link account)
   ============================================================ */

function bot_connect_start(int $chatId, string $code, string $firstName, string $lastName): void
{
    $wu = bot_db(
        "SELECT id FROM web_users WHERE link_code = ? AND link_expires > NOW() LIMIT 1",
        [$code],
        'connect_find_code'
    )->fetch();
    if ($wu === null) {
        bot_send($chatId, "❌ <b>کد اتصال نامعتبر یا منقضی شده است.</b>\n\nاز پنل SSAEE دوباره اقدام کنید:\n<b>پروفایل ← اتصال با تلگرام</b>", bot_kb_main(), bot_kb_reply_menu());
        return;
    }
    bot_state_set($chatId, 'await_contact', $code);
    bot_send($chatId, "🔗 <b>اتصال حساب پنل به تلگرام</b>\n\nبرای تکمیل اتصال، دکمهٔ «📱 ارسال شماره تماس» را در کیبورد پایین بزنید تا شماره موبایل حساب شما ارسال شود.\n\n🔒 شماره شما فقط برای تأیید هویت استفاده می‌شود و محرمانه می‌ماند.", null,
        [[['text' => '📱 ارسال شماره تماس', 'request_contact' => true]], [['text' => '❌ انصراف']]]);
}

function bot_handle_contact(int $chatId, string $rawPhone, string $firstName, string $lastName): void
{
    $phone = phone_normalize($rawPhone);
    if (!phone_valid($phone)) {
        bot_send($chatId, "❌ شماره ارسالی معتبر نیست. لطفاً دوباره تلاش کنید.");
        return;
    }

    $state = bot_state_get($chatId);
    $isConnect  = ($state !== null && $state['state'] === 'await_contact');
    $isWeblogin = ($state !== null && $state['state'] === 'await_weblogin');

    // 1) Telegram connect flow (code-based — verified by the code + phone match)
    if ($isConnect) {
        $code = (string)$state['data'];
        $wu = bot_db(
            "SELECT id, phone, profile_id FROM web_users WHERE link_code = ? AND link_expires > NOW() LIMIT 1",
            [$code],
            'contact_find_link'
        )->fetch();

        if ($wu !== null && phone_matches($wu['phone'], $phone)) {
            bot_state_clear($chatId);
            if (bot_link_account($wu, $chatId, $phone, $firstName, $lastName, 'connect')) {
                bot_send($chatId, "🎉 <b>اتصال با موفقیت انجام شد!</b>\n\n"
                    . "از این پس این اطلاعیه‌ها را دریافت می‌کنید:\n"
                    . "📢 اطلاعیه‌های اعضا\n"
                    . "📜 اطلاعیه صدور گواهینامه برای شما\n"
                    . "🚀 اطلاعیه آغاز ثبت‌نام کارگاه‌های جدید\n"
                    . "🤖 ارسال خودکار لینک کارگاه پس از ثبت‌نام", bot_kb_main(), bot_kb_reply_menu());
                return;
            }
            bot_send($chatId, "⛔ اتصال این حساب تلگرام ممکن نشد (این تلگرام به حساب دیگری متصل است).", bot_kb_main(), bot_kb_reply_menu());
            return;
        }
        bot_state_clear($chatId);
        bot_send($chatId, "❌ <b>شماره ارسالی با حساب مربوط به کد اتصال مطابقت ندارد.</b>\n\nمطمئن شوید شماره حساب پنل شماست و دوباره از پنل اقدام کنید.", bot_kb_main(), bot_kb_reply_menu());
        return;
    }

    // 2) Web quick-login flow (with optional wl_CODE handshake from the site)
    if ($isWeblogin) {
        $code = strtoupper(trim((string)($state['data'] ?? '')));
        bot_state_clear($chatId);

        // ورود و اتصال حساب منحصراً بر اساس شماره تلفن
        $res = bot_login_or_link_by_phone($chatId, $phone, $firstName, $lastName);
        $profile = $res['profile'];
        $session = bot_create_session((int)$profile['TelegramID'], $phone);

        // اگر کد هندشیک سایت معتبر است، توکن را برای ورود خودکار در سایت ثبت کن
        if ($code !== '' && preg_match('/^[A-F0-9]{6,20}$/D', $code)) {
            try {
                $reqRaw = (string)setting('wl_req_' . $code, '');
                $req = $reqRaw !== '' ? json_decode($reqRaw, true) : null;
                if (!empty($req)) {
                    setting_set('wl_done_' . $code, json_encode([
                        'token' => $session['token'],
                        'phone' => $phone,
                        'ts'    => time()
                    ]));
                }
            } catch (Throwable $e) {
                bot_log('weblogin handoff failed: ' . $e->getMessage());
            }
        }

        $appUrl = rtrim((string)Env::get('APP_URL', ''), '/');
        $link = $appUrl . '/#auth=' . $session['token'];

        $existingNotice = $res['is_existing']
            ? "🔗 شماره تماس شما قبلاً در سیستم ثبت شده بود و حساب تلگرام شما با موفقیت به آن متصل شد."
            : "✨ حساب کاربری جدیدی با شماره تماس شما ایجاد و به تلگرام متصل شد.";

        // ارسال مشخصات ورود و دکمه‌های شیشه‌ای زیر پیام همراه با کیبورد منوی اصلی
        bot_send($chatId, "✅ <b>ورود با موفقیت تأیید شد!</b>\n\n"
            . "📱 شماره تماس: <code>{$phone}</code>\n"
            . "🆔 شناسه تلگرام: <code>{$chatId}</code>\n\n"
            . "{$existingNotice}\n\n"
            . "🖥 اگر صفحهٔ سایت در مرورگر شما باز است، تا چند ثانیه دیگر خودکار وارد می‌شوید.\nدر غیر این صورت از دکمه‌های زیر استفاده فرمایید:",
            bot_kb_main($link), bot_kb_reply_menu());
        return;
    }

    // 3) Default: ارسال شماره بدون وضعیت فعال
    $res = bot_login_or_link_by_phone($chatId, $phone, $firstName, $lastName);
    $text = $res['is_existing']
        ? "🎉 <b>حساب شما به تلگرام متصل شد.</b>\n\nشمارهٔ <b>{$phone}</b> به حساب موجود شما در پنل متصل شد. از این پس اطلاعیه‌ها را همین‌جا دریافت می‌کنید."
        : "✅ شمارهٔ شما ثبت شد و حساب کاربری جدیدی برای شما ساخته شد.";
    bot_send($chatId, $text, bot_kb_main(), bot_kb_reply_menu());
}

/**
 * ورود یا اتصال حساب بر اساس شماره تلفن:
 * اگر شماره تلفن قبلاً در دیتابیس (web_users یا user_profiles) ثبت بوده باشد:
 * شناسه عددی تلگرام ($chatId) به همان حساب متصل می‌شود تا حساب تکراری ساخته نشود.
 * در غیر این صورت حساب جدید بر اساس شماره تلفن و تلگرام ایجاد می‌گردد.
 * 
 * @return array ['profile' => array, 'is_existing' => bool]
 */
function bot_login_or_link_by_phone(int $chatId, string $phone, string $firstName, string $lastName): array
{
    $phoneNorm = phone_normalize($phone);
    $intl = phone_to_intl($phoneNorm);
    $variants = array_values(array_unique(array_filter([
        $phoneNorm,
        $intl,
        ltrim($intl, '+'),
        substr($phoneNorm, 1),
        ltrim($phoneNorm, '0'),
    ])));

    $candidates = [];

    // ۱) جستجو بر اساس شناسه تلگرام
    $profByTg = bot_db("SELECT * FROM user_profiles WHERE TelegramID = ? LIMIT 1", [$chatId], 'find_prof_by_tg')->fetch();
    if ($profByTg !== false && $profByTg !== null) {
        $candidates[(int)$profByTg['ID']] = $profByTg;
    }

    // ۲) جستجو در web_users (تمام رکوردهای مطابق با تلگرام یا شماره)
    $inClause = implode(',', array_fill(0, count($variants), '?'));
    $stmtWu = bot_db("SELECT * FROM web_users WHERE telegram_id = ? OR phone IN ({$inClause})", array_merge([$chatId], $variants), 'find_wu');
    $wuList = [];
    while ($w = $stmtWu->fetch()) {
        $wuList[] = $w;
        if (!empty($w['profile_id'])) {
            $profByWu = bot_db("SELECT * FROM user_profiles WHERE ID = ? LIMIT 1", [(int)$w['profile_id']], 'find_prof_by_wu')->fetch();
            if ($profByWu !== false && $profByWu !== null) {
                $candidates[(int)$profByWu['ID']] = $profByWu;
            }
        }
    }

    // ۳) جستجو در user_profiles بر اساس تمام واریانت‌های شماره تلفن
    $searchPhones = $variants;
    foreach ($wuList as $w) {
        if (!empty($w['phone'])) {
            $searchPhones[] = phone_normalize($w['phone']);
            $searchPhones[] = phone_to_intl($w['phone']);
        }
    }
    foreach ($candidates as $cand) {
        if (!empty($cand['Phone'])) {
            $searchPhones[] = phone_normalize($cand['Phone']);
            $searchPhones[] = phone_to_intl($cand['Phone']);
        }
    }
    $searchPhones = array_values(array_unique(array_filter($searchPhones)));

    if (!empty($searchPhones)) {
        $inSearch = implode(',', array_fill(0, count($searchPhones), '?'));
        $stmtProfs = bot_db("SELECT * FROM user_profiles WHERE Phone IN ({$inSearch})", $searchPhones, 'find_profs_by_phone');
        while ($p = $stmtProfs->fetch()) {
            $candidates[(int)$p['ID']] = $p;
        }
    }

    $isExisting = !empty($wuList) || !empty($candidates);

    if (empty($candidates)) {
        $fullName = trim($firstName . ' ' . $lastName) ?: null;
        // آزاد کردن شناسه تلگرام از هرگونه رکورد دیگر برای تضمین عدم خطای UNIQUE
        bot_db("UPDATE user_profiles SET TelegramID = 0 - ID WHERE TelegramID = ?", [$chatId], 'release_prof_tg');
        bot_db(
            "INSERT INTO user_profiles (TelegramID, Phone, FirstName, LastName, FullName, IsProfileComplete, LastLogin)
             VALUES (?, ?, ?, ?, ?, 0, NOW())
             ON DUPLICATE KEY UPDATE Phone = VALUES(Phone), LastLogin = NOW()",
            [$chatId, $phoneNorm, $firstName, $lastName, $fullName],
            'insert_new_profile'
        );
        $targetProfileId = (int)bot_db("SELECT ID FROM user_profiles WHERE TelegramID = ? LIMIT 1", [$chatId], 'get_new_prof_id')->fetchColumn();
        $master = bot_db("SELECT * FROM user_profiles WHERE ID = ? LIMIT 1", [$targetProfileId], 'get_new_prof')->fetch();
    } else {
        $candList = array_values($candidates);
        usort($candList, function($a, $b) {
            $scoreA = (!empty($a['NationalID']) ? 10 : 0)
                    + (!empty($a['StudentID']) ? 8 : 0)
                    + (!empty($a['Phone']) ? 4 : 0)
                    + (!empty($a['FirstName']) ? 3 : 0)
                    + ((int)($a['IsProfileComplete'] ?? 0) ? 2 : 0);
            $scoreB = (!empty($b['NationalID']) ? 10 : 0)
                    + (!empty($b['StudentID']) ? 8 : 0)
                    + (!empty($b['Phone']) ? 4 : 0)
                    + (!empty($b['FirstName']) ? 3 : 0)
                    + ((int)($b['IsProfileComplete'] ?? 0) ? 2 : 0);
            if ($scoreB !== $scoreA) {
                return $scoreB <=> $scoreA;
            }
            return (int)$a['ID'] <=> (int)$b['ID'];
        });

        $master = $candList[0];
        $targetProfileId = (int)$master['ID'];
        $oldTgId = (int)($master['TelegramID'] ?? 0);

        // ۱. ابتدا تلگرام آیدی را از سایر پروفایل‌ها آزاد می‌کنیم تا کلید یکتا نقض نشود
        bot_db("UPDATE user_profiles SET TelegramID = 0 - ID WHERE TelegramID = ? AND ID != ?", [$chatId, $targetProfileId], 'release_prof_tg');

        // ۲. حذف یا انتقال ارجاعات پروفایل‌های تکراری خالی به پروفایل اصلی
        foreach ($candList as $cand) {
            if ((int)$cand['ID'] === $targetProfileId) continue;
            if (empty($cand['NationalID']) && empty($cand['StudentID'])) {
                bot_db("UPDATE web_users SET profile_id = ? WHERE profile_id = ?", [$targetProfileId, (int)$cand['ID']], 'repoint_wu_cand');
                bot_db("DELETE FROM user_profiles WHERE ID = ?", [(int)$cand['ID']], 'clean_dup_prof');
            }
        }

        // ۳. به‌روزرسانی شناسه تلگرام و شماره تلفن روی پروفایل کانونی
        $up = ["TelegramID = ?", "Phone = ?", "LastLogin = NOW()"];
        $prm = [$chatId, $phoneNorm];
        if (empty($master['FirstName']) && $firstName !== '') {
            $up[] = "FirstName = ?";
            $prm[] = $firstName;
        }
        if (empty($master['LastName']) && $lastName !== '') {
            $up[] = "LastName = ?";
            $prm[] = $lastName;
        }
        if (empty($master['FullName']) && ($firstName !== '' || $lastName !== '')) {
            $up[] = "FullName = ?";
            $prm[] = trim($firstName . ' ' . $lastName);
        }
        $prm[] = $targetProfileId;
        bot_db("UPDATE user_profiles SET " . implode(', ', $up) . " WHERE ID = ?", $prm, 'update_master_prof');

        // ۴. انتقال سشن‌ها در صورت تغییر TelegramID
        if ($oldTgId > 0 && $oldTgId !== $chatId) {
            bot_db("UPDATE user_sessions SET TelegramID = ? WHERE TelegramID = ?", [$chatId, $oldTgId], 'update_sessions_tg');
        }
    }

    // همگام‌سازی web_users: ابتدا شناسه تلگرام را از سایر رکوردهای web_users پاک می‌کنیم
    bot_db("UPDATE web_users SET telegram_id = NULL WHERE telegram_id = ?", [$chatId], 'release_all_wu_tg');

    // انتخاب بهترین رکورد web_users برای لینک
    $primaryWu = null;
    foreach ($wuList as $w) {
        if (!empty($w['phone']) && in_array($w['phone'], $variants, true)) {
            $primaryWu = $w;
            break;
        }
    }
    if ($primaryWu === null && !empty($wuList)) {
        $primaryWu = $wuList[0];
    }

    if ($primaryWu !== null) {
        bot_db(
            "UPDATE web_users
             SET telegram_id = ?,
                 profile_id = ?,
                 last_login = NOW()
             WHERE id = ?",
            [$chatId, $targetProfileId, (int)$primaryWu['id']],
            'update_wu_tg'
        );
    } else {
        $existingWu = bot_db("SELECT id FROM web_users WHERE phone = ? LIMIT 1", [$phoneNorm], 'check_wu_phone')->fetch();
        if ($existingWu) {
            bot_db(
                "UPDATE web_users SET telegram_id = ?, profile_id = ?, last_login = NOW() WHERE id = ?",
                [$chatId, $targetProfileId, (int)$existingWu['id']],
                'update_existing_wu'
            );
        } else {
            $dummyPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            bot_db(
                "INSERT INTO web_users (phone, password_hash, profile_id, telegram_id, is_active, last_login)
                 VALUES (?, ?, ?, ?, 1, NOW())",
                [$phoneNorm, $dummyPass, $targetProfileId, $chatId],
                'insert_wu_record'
            );
        }
    }

    $finalProfile = bot_db("SELECT * FROM user_profiles WHERE ID = ? LIMIT 1", [$targetProfileId], 'get_final_prof')->fetch();
    return [
        'profile'     => $finalProfile,
        'is_existing' => $isExisting,
    ];
}

/**
 * یک حساب وب‌مستقر (web_users) را به این تلگرام متصل کن
 */
function bot_link_account(array $wu, int $chatId, string $phone, string $firstName, string $lastName, string $step): bool
{
    $res = bot_login_or_link_by_phone($chatId, $phone, $firstName, $lastName);
    return !empty($res['profile']);
}

/** بر اساس شماره موبایل، حساب وب موجود را پیدا و به تلگرام وصل کن. */
function bot_link_existing_by_phone(int $chatId, string $phone, string $firstName, string $lastName): bool
{
    $res = bot_login_or_link_by_phone($chatId, $phone, $firstName, $lastName);
    return $res['is_existing'];
}

/* ============================================================
   Web quick-login (called from /weblogin command + web button)
   ============================================================ */

function bot_weblogin_start(int $chatId): void
{
    bot_state_set($chatId, 'await_weblogin', '');
    bot_send($chatId, "🔐 <b>ورود سریع به پنل وب</b>\n\nبرای تأیید هویت و ورود بر اساس شماره تماس، لطفاً دکمهٔ زیر را بزنید تا شماره تلفن شما ارسال شود:", null,
        [
            [['text' => '📱 ارسال شماره تلفن و ورود به سایت', 'request_contact' => true]],
            [['text' => '❌ انصراف']]
        ]);
}

/**
 * Quick-login initiated from the site with a one-time handshake code
 * (t.me/bot?start=wl_CODE). The site keeps a countdown open and
 * auto-logs-in as soon as the bot writes the token for this code.
 */
function bot_weblogin_start_code(int $chatId, string $code, string $firstName, string $lastName): void
{
    $reqRaw = (string)setting('wl_req_' . $code, '');
    $req = $reqRaw !== '' ? json_decode($reqRaw, true) : null;
    if (!is_array($req) || time() - (int)($req['ts'] ?? 0) > 300) {
        bot_send($chatId, "⏱ <b>کد ورود منقضی یا نامعتبر است.</b>\n\nلطفاً در سایت دوباره روی «ورود سریع با تلگرام» کلیک کنید.", bot_kb_main(), bot_kb_reply_menu());
        return;
    }

    bot_state_set($chatId, 'await_weblogin', $code);
    bot_send($chatId, "🔐 <b>ورود به حساب کاربری سایت SSAEE</b>\n\n"
        . "برای تکمیل ورود و احراز هویت، لطفاً دکمهٔ زیر را بزنید تا <b>شماره تلفن</b> شما به ربات ارسال شود.\n\n"
        . "📱 <b>ورود نهایی منحصراً بر اساس شماره تلفن انجام می‌شود</b>؛ در صورتی که قبلاً با این شماره در سایت ثبت‌نام کرده باشید، حساب تلگرام شما به‌طور خودکار به شماره تماستان لینک خواهد شد.\n\n"
        . "⚡ پس از ارسال، صفحهٔ سایت به‌صورت خودکار وارد حساب کاربری می‌شود.", null,
        [
            [['text' => '📱 ارسال شماره تلفن و ورود به سایت', 'request_contact' => true]],
            [['text' => '❌ انصراف']]
        ]);
}

function bot_weblogin_issue(int $chatId, array $profile): void
{
    $session = bot_create_session((int)$profile['TelegramID'], $profile['Phone'] ?? null);
    $appUrl = rtrim((string)Env::get('APP_URL', ''), '/');
    $link = $appUrl . '/#auth=' . $session['token'];

    bot_send($chatId, "✅ <b>هویت شما تأیید شد</b>\n\n🚀 با دکمه‌های زیر وارد پنل شوید:\n(لینک ورود اختصاصی و امن است)", bot_kb_main($link), bot_kb_reply_menu());
}

/* ============================================================
   Profile helpers (explicit columns only)
   ============================================================ */

function bot_get_profile(int $telegramId): ?array
{
    $row = bot_db(
        "SELECT TelegramID, Phone, FirstName, LastName, FullName FROM user_profiles WHERE TelegramID = ? LIMIT 1",
        [$telegramId],
        'profile_get'
    )->fetch();
    if ($row === null) return null;

    return [
        'TelegramID' => (int)$row['TelegramID'],
        'phone'      => $row['Phone'] ?? '',
        'name'       => trim(($row['FirstName'] ?? '') . ' ' . ($row['LastName'] ?? '')) ?: ($row['FullName'] ?? 'کاربر'),
    ];
}

function bot_get_profile_by_phone(string $phone): ?array
{
    $row = bot_db(
        "SELECT TelegramID, Phone, FirstName, LastName, FullName FROM user_profiles WHERE Phone IN (?, ?) LIMIT 1",
        [phone_normalize($phone), phone_to_intl($phone)],
        'profile_get_by_phone'
    )->fetch();
    if ($row === null) return null;

    return [
        'TelegramID' => (int)$row['TelegramID'],
        'phone'      => $row['Phone'] ?? '',
        'name'       => trim(($row['FirstName'] ?? '') . ' ' . ($row['LastName'] ?? '')) ?: ($row['FullName'] ?? 'کاربر'),
    ];
}

function bot_upsert_profile(int $telegramId, string $firstName, string $lastName, ?string $phone): void
{
    $exists = bot_db(
        "SELECT TelegramID FROM user_profiles WHERE TelegramID = ? LIMIT 1",
        [$telegramId],
        'profile_exists'
    )->fetch();

    if ($exists !== null) {
        bot_db(
            "UPDATE user_profiles
             SET FirstName = COALESCE(NULLIF(?,''), FirstName),
                 LastName = COALESCE(NULLIF(?,''), LastName),
                 Phone = COALESCE(?, Phone),
                 LastLogin = NOW()
             WHERE TelegramID = ?",
            [$firstName, $lastName, $phone, $telegramId],
            'profile_update'
        );
    } else {
        bot_db(
            "INSERT INTO user_profiles (TelegramID, FirstName, LastName, FullName, Phone, IsProfileComplete, LastLogin)
             VALUES (?, ?, ?, ?, ?, 0, NOW())",
            [$telegramId, $firstName, $lastName, trim($firstName . ' ' . $lastName) ?: null, $phone],
            'profile_insert'
        );
    }
}

/* ============================================================
   Session helper (for web login link)
   ============================================================ */

function bot_create_session(int $telegramId, ?string $phone = null): array
{
    bot_db("DELETE FROM user_sessions WHERE TelegramID = ? AND ExpiresAt < NOW()", [$telegramId], 'session_clear_expired');
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
    bot_db(
        "INSERT INTO user_sessions (TelegramID, Token, IP, UserAgent, ExpiresAt) VALUES (?, ?, ?, ?, ?)",
        [$telegramId, $token, 'telegram-bot', 'Telegram Bot Auth', $expiresAt],
        'session_insert'
    );

    $payload = ['tg' => $telegramId, 'sid' => $token];
    if ($phone) {
        $payload['phone'] = phone_normalize($phone);
    }
    $jwt = jwt_sign($payload, 60 * 60 * 24 * 30);
    return ['token' => $jwt, 'expires_at' => $expiresAt];
}

/* ============================================================
   State helpers
   ============================================================ */

function bot_state_get(int $tgId): ?array
{
    $row = bot_db(
        "SELECT state, data, updated_at FROM bot_user_states WHERE telegram_id = ? AND updated_at > DATE_SUB(NOW(), INTERVAL 45 MINUTE) LIMIT 1",
        [$tgId],
        'state_get'
    )->fetch();
    return $row === false ? null : $row;
}

function bot_state_set(int $tgId, string $state, string $data = ''): void
{
    bot_db(
        "INSERT INTO bot_user_states (telegram_id, state, data, updated_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE state = VALUES(state), data = VALUES(data), updated_at = NOW()",
        [$tgId, $state, $data],
        'state_set'
    );
}

function bot_state_clear(int $tgId): void
{
    bot_db(
        "DELETE FROM bot_user_states WHERE telegram_id = ?",
        [$tgId],
        'state_clear'
    );
}

/* ============================================================
   Bot admins
   ============================================================ */

function bot_is_admin(int $tgId): bool
{
    $row = bot_db(
        "SELECT telegram_id FROM bot_admins WHERE telegram_id = ? AND is_active = 1 LIMIT 1",
        [$tgId],
        'is_admin'
    )->fetch();
    return $row !== false;
}

function bot_admin_ids(): array
{
    $rows = bot_db(
        "SELECT telegram_id FROM bot_admins WHERE is_active = 1",
        [],
        'admin_ids'
    )->fetchAll();
    return array_map('intval', array_column($rows, 'telegram_id'));
}

/* ============================================================
   Workshop Survey ( satisfaction 1-5 + feedback link)
   ============================================================ */

function bot_send_survey(int $chatId, string $workshopTitle, string $regId): bool
{
    $text = "📊 <b>نظرسنجی کارگاه</b>\n\n"
        . "کارگاه <b>" . tg_esc($workshopTitle) . "</b> برگزار شد.\n"
        . "چقدر از این کارگاه راضی بودید؟\n\n"
        . "۵ = عالی · ۴ = خوب · ۳ = متوسط · ۲ = ضعیف · ۱ = خیلی ضعیف\n\n"
        . "لطفاً امتیاز خود را انتخاب کنید 👇";

    // callback format matches the webhook route: bot:survey:<registration_id>:<rating>
    $kb = [
        [['text' => '⭐ ۵ — عالی', 'callback_data' => 'bot:survey:' . $regId . ':5']],
        [['text' => '⭐ ۴ — خوب', 'callback_data' => 'bot:survey:' . $regId . ':4']],
        [['text' => '⭐ ۳ — متوسط', 'callback_data' => 'bot:survey:' . $regId . ':3']],
        [['text' => '⭐ ۲ — ضعیف', 'callback_data' => 'bot:survey:' . $regId . ':2']],
        [['text' => '⭐ ۱ — خیلی ضعیف', 'callback_data' => 'bot:survey:' . $regId . ':1']],
        [['text' => '📝 ارسال انتقادات و پیشنهادات', 'web_app' => ['url' => rtrim((string)Env::get('APP_URL',''), '/') . '/#view-contact']]],
    ];

    $res = bot_send($chatId, $text, $kb);
    return ($res !== null && ($res['ok'] ?? false));
}

/**
 * Create + send workshop surveys.
 * $targets: [['tg' => int, 'reg_id' => string], ...] — one row per registration.
 * Inserts a workshop_surveys row per recipient so answers map back via
 * bot:survey:<registration_id>:<rating> callbacks (handled by bot_survey_answer).
 */
function bot_create_survey(string $workshopTag, string $workshopTitle, array $targets): int
{
    $created = 0;
    foreach ($targets as $t) {
        $tgId = (int)($t['tg'] ?? 0);
        $regId = (string)($t['reg_id'] ?? '');
        if ($tgId <= 0 || $regId === '') continue;
        try {
            bot_db(
                "INSERT INTO workshop_surveys (registration_id, chat_id, workshop_tag) VALUES (?, ?, ?)",
                [$regId, $tgId, $workshopTag],
                'survey_create'
            );
            bot_send_survey($tgId, $workshopTitle, $regId);
            $created++;
            usleep(200000);
        } catch (Throwable $ex) {
            bot_log('SURVEY ERROR: ' . $ex->getMessage());
        }
    }
    return $created;
}