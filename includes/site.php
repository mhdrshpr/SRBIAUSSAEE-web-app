<?php
/* ============================================================
   SSAEE Panel — MAIN SITE
   All public-site PHP functions + API dispatcher.
   Entry: public/index.php (?api=<action>)
   ============================================================ */

/* ============================================================
   API Dispatcher
   ============================================================ */

function site_api_handle(): void
{
    $action = $_GET['api'] ?? '';
    // Tolerate legacy double-'?' URL pattern (?api=list?entity=x) from cached admin.js
    if (str_contains($action, '?')) {
        [$action, $legacyQs] = explode('?', $action, 2);
        parse_str($legacyQs, $legacyParams);
        foreach ($legacyParams as $lk => $lv) {
            if (!isset($_GET[$lk])) {
                $_GET[$lk] = $lv;
            }
        }
    }
    try {
        switch ($action) {
            case 'ping':              site_ping(); break;
            case 'bot_link':          site_bot_link(); break;
            case 'maintenance_check': site_maintenance_check(); break;
            case 'help':              site_help(); break;

            case 'login':             site_login(req_input()); break;
            case 'register':          site_register(req_input()); break;
            case 'telegram_login':    site_telegram_login(req_input()); break;
            case 'logout':            site_logout(); break;
            case 'me':                site_me(); break;
            case 'profile_update':    site_update_profile(req_input()); break;

            case 'account_delete_request': site_account_delete_request(); break;
            case 'account_delete_confirm': site_account_delete_confirm(req_input()); break;

            case 'member_request':    site_member_request(req_input()); break;
            case 'points':            site_member_points(req_input()); break;

            case 'banners':           site_banners_active(); break;
            case 'banner_click':      site_banner_click(req_input()); break;
            case 'workshop_register': site_workshop_register(req_input()); break;
            case 'workshop_req':      site_workshop_req(req_input()); break;
            case 'class_req':         site_class_req(req_input()); break;

            case 'cert_check':        site_cert_check(req_input()); break;
            case 'cert_download':     site_cert_download(req_input()); break;
            case 'cert_file':         site_cert_file(req_input()); break;
            case 'my_certificates':   site_my_certificates(); break;
            case 'member_card':       site_member_card(); break;
            case 'my_registrations':  site_my_registrations(); break;

            case 'collab':            site_collab(req_input()); break;
            case 'feedback':          site_feedback(req_input()); break;

            case 'activities':        site_activities(); break;
            case 'stats':             site_stats(); break;
            case 'announcements':     site_announcements(); break;
            case 'classes':           site_classes(); break;
            case 'board_members':     site_board(); break;

            case 'tg_connect_start':  site_tg_connect_start(); break;
            case 'tg_connect_direct': site_tg_connect_direct(req_input()); break;

            case 'weblogin_start':    site_weblogin_start(); break;
            case 'weblogin_poll':     site_weblogin_poll(); break;

            case 'reset_request':     site_reset_request(req_input()); break;
            case 'reset_confirm':     site_reset_confirm(req_input()); break;

            case 'bot':               bot_handle_webhook(); break;
            case 'bot_selftest':      site_bot_selftest(); break;

            default:                  json_err('عملیات نامعتبر', 404);
        }
    } catch (Throwable $ex) {
        error_log('[site:' . $action . '] ' . $ex->getMessage() . ' | ' . $ex->getFile() . ':' . $ex->getLine());
        $code = site_error_code($ex);
        $detail = Env::bool('APP_DEBUG', false)
            ? mb_substr($ex->getMessage() . ' (' . basename($ex->getFile()) . ':' . $ex->getLine() . ')', 0, 200)
            : 'کد ' . $code;
        json_out(['status' => 'error', 'err' => $code, 'message' => 'خطا — ' . $detail], 500);
    }
}

/* Turn raw exceptions into actionable Persian messages */
function site_error_code(Throwable $ex): int
{
    $msg = $ex->getMessage();
    if ($ex instanceof RuntimeException && str_contains($msg, 'JWT_SECRET')) {
        return 1004;
    }
    if ($ex instanceof PDOException) {
        if (str_contains($msg, 'Unknown database') || str_contains($msg, 'Connection refused')) {
            return 1002;
        }
        if (str_contains($msg, "doesn't exist")) {
            return 1003;
        }
        return 1005;
    }
    return 1099;
}

/* Maintenance status poll — public, whitelisted, called by the maintenance page
   so visitors' browsers auto-reload the moment the site is back. */
function site_maintenance_check(): void
{
    json_ok(['data' => [
        'maintenance' => maintenance_is_active() ? 1 : 0,
        'message'     => maintenance_message(),
    ]]);
}

/* Public site help guide content — written by the tech admin in the panel. */
function site_help(): void
{
    $items = json_decode((string)setting('site_help_content', '[]'), true);
    if (!is_array($items)) $items = [];
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $out[] = [
            'icon'  => preg_replace('/[^a-z0-9\-]/', '', (string)($it['icon'] ?? 'info')) ?: 'info',
            'title' => (string)($it['title'] ?? ''),
            'body'  => (string)($it['body'] ?? ''),
        ];
    }
    json_ok(['data' => ['items' => $out]]);
}

/* Health check used by the app splash + manual debugging */
function site_ping(): void
{    $diag = ['time' => now(), 'db' => false, 'missing_tables' => [], 'jwt_ok' => false, 'issues' => [], 'err' => null];

    try {
        db_val('SELECT 1');
        $diag['db'] = true;
    } catch (Throwable $e) {
        $diag['issues'][] = ['code' => 1002, 'text' => 'اتصال دیتابیس'];
        $diag['err'] = 1002;
    }

    if ($diag['db']) {
        $required = ['users', 'user_roles', 'user_profiles', 'user_sessions', 'web_users',
                     'rate_limits', 'banners', 'workshops', 'workshop_registrations',
                     'settings', 'activities', 'announcements', 'members', 'certificates'];
        $missing = [];
        foreach ($required as $t) {
            try {
                db_val("SELECT 1 FROM `{$t}` LIMIT 1");
            } catch (Throwable $e) {
                $missing[] = $t;
            }
        }
        $diag['missing_tables'] = $missing;
        if ($missing) {
            $diag['issues'][] = ['code' => 1003, 'text' => 'جداول ناقص: ' . implode(', ', $missing)];
            $diag['err'] = 1003;
        }
    }

    $jwtLen = strlen((string)Env::get('JWT_SECRET', ''));
    $diag['jwt_ok'] = $jwtLen >= 32;
    if (!$diag['jwt_ok']) {
        $diag['issues'][] = ['code' => 1004, 'text' => 'JWT_SECRET تنظیم نشده (طول فعلی: ' . $jwtLen . ')'];
        if ($diag['err'] === null) $diag['err'] = 1004;
    }

    if ($diag['err'] === null) {
        unset($diag['issues']);
    }
    json_ok(['data' => $diag]);
}

/* Public: bot deep link (for "login via Telegram" button) */
function site_bot_link(): void
{
    $bot = setting('bot_username') ?: (string)Env::get('TELEGRAM_BOT_USERNAME', '');
    json_ok(['data' => [
        'username'   => $bot,
        'url'        => $bot !== '' ? 'https://t.me/' . $bot : null,
        'configured' => $bot !== '',
    ]]);
}

/* ============================================================
   Authentication
   ============================================================ */

function site_create_session(int|string $telegramId): array
{
    db_exec("DELETE FROM user_sessions WHERE TelegramID = ? AND ExpiresAt < NOW()", [$telegramId]);
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
    db_exec(
        "INSERT INTO user_sessions (TelegramID, Token, IP, UserAgent, ExpiresAt) VALUES (?, ?, ?, ?, ?)",
        [$telegramId, $token, client_ip(), client_ua(), $expiresAt]
    );
    return [
        'token'      => jwt_sign(['tg' => (int)$telegramId, 'sid' => $token], 60 * 60 * 24 * 30),
        'expires_at' => $expiresAt,
    ];
}

function site_current_user(): ?array
{
    $tok = bearer_token();
    if ($tok !== null) {
        $payload = jwt_verify($tok);
        if ($payload !== null && !empty($payload['tg']) && !empty($payload['sid'])) {
            $session = db_one(
                "SELECT ID FROM user_sessions WHERE TelegramID = ? AND Token = ? AND ExpiresAt > NOW() LIMIT 1",
                [$payload['tg'], $payload['sid']]
            );
            if ($session !== null) {
                $tgId = (int)$payload['tg'];
                $p = db_one(
                    "SELECT * FROM user_profiles 
                     WHERE TelegramID = ? 
                     ORDER BY (NationalID IS NOT NULL AND NationalID != '') DESC,
                              (StudentID IS NOT NULL AND StudentID != '') DESC,
                              (Phone IS NOT NULL AND Phone != '') DESC,
                              ID DESC LIMIT 1",
                    [$tgId]
                );
                if ($p !== null) return $p;
                $wu = db_one("SELECT profile_id FROM web_users WHERE telegram_id = ? AND profile_id IS NOT NULL LIMIT 1", [$tgId]);
                if ($wu !== null && !empty($wu['profile_id'])) {
                    $pWu = db_one("SELECT * FROM user_profiles WHERE ID = ? LIMIT 1", [(int)$wu['profile_id']]);
                    if ($pWu !== null) return $pWu;
                }
                if (!empty($payload['phone'])) {
                    $normPh = phone_normalize((string)$payload['phone']);
                    $intlPh = phone_to_intl($normPh);
                    $pByPhone = db_one("SELECT * FROM user_profiles WHERE Phone IN (?, ?) ORDER BY (NationalID IS NOT NULL AND NationalID != '') DESC LIMIT 1", [$normPh, $intlPh]);
                    if ($pByPhone !== null) return $pByPhone;
                }
            }
        }
        return null;
    }

    $initData = req_str(req_input(), 'initData') ?: (string)($_GET['initData'] ?? '');
    if ($initData !== '') {
        $tgUser = tg_verify_init_data($initData);
        if ($tgUser !== null) {
            $tgId = (int)$tgUser['id'];
            $p = db_one(
                "SELECT * FROM user_profiles 
                 WHERE TelegramID = ? 
                 ORDER BY (NationalID IS NOT NULL AND NationalID != '') DESC,
                          (StudentID IS NOT NULL AND StudentID != '') DESC,
                          (Phone IS NOT NULL AND Phone != '') DESC,
                          ID DESC LIMIT 1",
                [$tgId]
            );
            if ($p !== null) return $p;
            $wu = db_one("SELECT profile_id FROM web_users WHERE telegram_id = ? AND profile_id IS NOT NULL LIMIT 1", [$tgId]);
            if ($wu !== null && !empty($wu['profile_id'])) {
                $p = db_one("SELECT * FROM user_profiles WHERE ID = ? LIMIT 1", [(int)$wu['profile_id']]);
                if ($p !== null) {
                    db_exec("UPDATE user_profiles SET TelegramID = 0 - ID WHERE TelegramID = ? AND ID != ?", [$tgId, (int)$p['ID']]);
                    db_exec("UPDATE user_profiles SET TelegramID = ? WHERE ID = ?", [$tgId, (int)$p['ID']]);
                    return $p;
                }
            }
        }
    }
    return null;
}

function site_profile_payload(array $profile): array
{
    $tgId = (int)$profile['TelegramID'];
    $wu = db_one(
        "SELECT id, telegram_id, phone FROM web_users
         WHERE (telegram_id = ? OR phone IN (?, ?)) LIMIT 1",
        [$tgId, phone_normalize($profile['Phone'] ?? ''), phone_to_intl($profile['Phone'] ?? '')]
    );

    $connected = false;
    $initData = req_str(req_input(), 'initData') ?: (string)($_GET['initData'] ?? '');
    if ($initData !== '' && tg_verify_init_data($initData) !== null) {
        $connected = true;
    } elseif ($wu !== null && !empty($wu['telegram_id'])) {
        $connected = true;
    }

    return [
        'telegram_id'         => $tgId,
        'first_name'          => $profile['FirstName'] ?? null,
        'last_name'           => $profile['LastName'] ?? null,
        'full_name'           => $profile['FullName'] ?? null,
        'phone'               => $profile['Phone'] ?? null,
        'national_id'         => $profile['NationalID'] ?? null,
        'student_id'          => $profile['StudentID'] ?? null,
        'is_profile_complete' => (bool)($profile['IsProfileComplete'] ?? false),
        'telegram_connected'  => $connected,
        'login_phone'         => $wu['phone'] ?? null,
    ];
}

function site_member_info(array $profile): ?array
{
    $nationalId = $profile['NationalID'] ?? '';
    if ($nationalId === '') return null;
    $m = db_one("SELECT ID, NameFa, Active, Points, IssueDate, ExpDate, Branch, Year FROM members WHERE NationalID = ? LIMIT 1", [$nationalId]);
    if ($m === null) return null;
    $m['expired'] = ($m['ExpDate'] !== null && strtotime($m['ExpDate']) < time());
    return $m;
}

function site_login(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);

    $phone = phone_normalize(req_str($in, 'phone'));
    $password = req_str($in, 'password');

    if (!phone_valid($phone)) json_err('شماره موبایل معتبر نیست');
    if (strlen($password) < 6) json_err('رمز عبور نامعتبر است');
    if (!rl_check('site_login_ip', client_ip(), 10, 600)) json_err('تلاش‌های زیاد. کمی بعد دوباره امتحان کنید', 429);
    if (!rl_check('site_login_phone', $phone, 7, 900)) json_err('تلاش‌های زیاد برای این شماره. کمی بعد امتحان کنید', 429);

    $wu = db_one("SELECT * FROM web_users WHERE phone IN (?, ?) LIMIT 1", [$phone, phone_to_intl($phone)]);
    if ($wu === null || !password_verify($password, $wu['password_hash'])) {
        if ($wu !== null) {
            $failed = (int)$wu['failed_attempts'] + 1;
            $locked = ($failed >= 10) ? date('Y-m-d H:i:s', time() + 1800) : $wu['locked_until'];
            db_exec("UPDATE web_users SET failed_attempts = ?, locked_until = ? WHERE id = ?", [$failed, $locked, $wu['id']]);
        }
        json_err('شماره موبایل یا رمز عبور اشتباه است', 401);
    }
    if (!(int)$wu['is_active']) json_err('حساب شما غیرفعال است', 403);
    if ($wu['locked_until'] !== null && strtotime($wu['locked_until']) > time()) {
        json_err('حساب شما موقتاً قفل شده است. ۳۰ دقیقه بعد تلاش کنید', 423);
    }

    db_exec("UPDATE web_users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?", [$wu['id']]);
    rl_clear('site_login_ip', client_ip());

    $profile = null;
    if (!empty($wu['profile_id'])) {
        $profile = db_one("SELECT * FROM user_profiles WHERE ID = ? LIMIT 1", [$wu['profile_id']]);
    }
    if ($profile === null && !empty($wu['telegram_id'])) {
        $profile = db_one("SELECT * FROM user_profiles WHERE TelegramID = ? LIMIT 1", [$wu['telegram_id']]);
    }
    if ($profile === null) {
        json_err('پروفایل حساب شما یافت نشد. با پشتیبانی تماس بگیرید', 404);
    }

    $session = site_create_session((int)$profile['TelegramID']);
    json_ok(['data' => [
        'token'      => $session['token'],
        'expires_at' => $session['expires_at'],
        'profile'    => site_profile_payload($profile),
    ]], 'ورود موفق');
}

function site_register(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    if (req_str($in, 'website') !== '') json_err('خطا در پردازش فرم');
    if (!rl_check('site_register', client_ip(), 5, 3600)) json_err('تعداد ثبت‌نام‌ها زیاد است. بعداً تلاش کنید', 429);

    $phone = phone_normalize(req_str($in, 'phone'));
    $password = req_str($in, 'password');
    $firstName = fa_name_clean(req_str($in, 'first_name'));
    $lastName = fa_name_clean(req_str($in, 'last_name'));
    $nationalId = only_digits(req_str($in, 'national_id'));
    $studentId = only_digits(req_str($in, 'student_id'));

    if (!phone_valid($phone)) json_err('شماره موبایل معتبر نیست');
    if (strlen($password) < 8) json_err('رمز عبور باید حداقل ۸ کاراکتر باشد');
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) json_err('رمز عبور باید شامل حرف و عدد باشد');
    if ($firstName === '' || $lastName === '') json_err('نام و نام خانوادگی الزامی است');
    if (!national_id_valid($nationalId)) json_err('کد ملی معتبر نیست');
    if (strlen($studentId) < 8) json_err('شماره دانشجویی معتبر نیست');

    if (db_one("SELECT ID FROM user_profiles WHERE NationalID = ? OR StudentID = ? LIMIT 1", [$nationalId, $studentId]) !== null) {
        json_err('این کد ملی یا شماره دانشجویی قبلاً ثبت شده است', 409);
    }
    if (db_one("SELECT id FROM web_users WHERE phone IN (?, ?) LIMIT 1", [$phone, phone_to_intl($phone)]) !== null) {
        json_err('این شماره موبایل قبلاً ثبت‌نام کرده است', 409);
    }

    $tgId = (int)('98' . ltrim($phone, '0'));
    if (db_one("SELECT ID FROM user_profiles WHERE TelegramID = ?", [$tgId]) !== null) {
        $tgId = (int)('97' . time() . random_int(10, 99));
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO user_profiles (TelegramID, Phone, FirstName, LastName, FullName, NationalID, StudentID, IsProfileComplete, LastLogin)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())"
        )->execute([$tgId, $phone, $firstName, $lastName, $firstName . ' ' . $lastName, $nationalId, $studentId]);

        $pdo->prepare("INSERT INTO web_users (phone, password_hash, profile_id, telegram_id) VALUES (?, ?, ?, NULL)")
            ->execute([$phone, password_hash($password, PASSWORD_DEFAULT), $pdo->lastInsertId()]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    tg_notify_admin("🆕 ثبت‌نام جدید در سایت:\n{$firstName} {$lastName}\nموبایل: {$phone}");

    // Welcome web-registered user if their telegram is already linked
    $wu = db_one("SELECT telegram_id FROM web_users WHERE phone IN (?, ?) AND telegram_id IS NOT NULL AND is_active = 1 LIMIT 1",
        [$phone, phone_to_intl($phone)]);
    if ($wu !== null) {
        tg_send((int)$wu['telegram_id'],
            "🎉 <b>{$firstName} {$lastName} عزیز، خوش آمدید!</b>\n\n" .
            "حساب شما در پنل SSAEE ساخته شد.\n" .
            "از این پس گواهینامه‌ها، امتیازها و کارگاه‌های خود را اینجا دنبال کنید.");
    }

    $profile = db_one("SELECT * FROM user_profiles WHERE TelegramID = ? LIMIT 1", [$tgId]);
    $session = site_create_session($tgId);
    json_ok(['data' => [
        'token'      => $session['token'],
        'expires_at' => $session['expires_at'],
        'profile'    => site_profile_payload($profile),
    ]], 'ثبت‌نام با موفقیت انجام شد');
}

function site_telegram_login(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);

    $initData = req_str($in, 'initData') ?: (req_str($in, 'init_data') ?: (req_str($_POST, 'initData') ?: (req_str($_POST, 'init_data') ?: req_str($_GET, 'initData'))));
    if ($initData === '') json_err('داده تلگرام یافت نشد');

    $tgUser = tg_verify_init_data($initData);
    if ($tgUser === null) json_err('اعتبارسنجی تلگرام ناموفق بود', 401);

    $tgId = (int)$tgUser['id'];
    $firstName = clean_str((string)($tgUser['first_name'] ?? ''));
    $lastName = clean_str((string)($tgUser['last_name'] ?? ''));
    $username = clean_str((string)($tgUser['username'] ?? ''));

    // ۱) جستجوی پروفایل متصل در user_profiles (با اولویت پروفایل دارای اطلاعات هویتی کامل)
    $profile = db_one(
        "SELECT * FROM user_profiles 
         WHERE TelegramID = ? 
         ORDER BY (NationalID IS NOT NULL AND NationalID != '') DESC,
                  (StudentID IS NOT NULL AND StudentID != '') DESC,
                  (Phone IS NOT NULL AND Phone != '') DESC,
                  ID DESC LIMIT 1",
        [$tgId]
    );

    // ۲) بررسی حساب متصل در web_users بر اساس telegram_id
    $wu = db_one("SELECT * FROM web_users WHERE telegram_id = ? LIMIT 1", [$tgId]);
    if ($wu !== null && !empty($wu['profile_id'])) {
        $profByWu = db_one("SELECT * FROM user_profiles WHERE ID = ? LIMIT 1", [(int)$wu['profile_id']]);
        if ($profByWu !== null) {
            // اگر پروفایلی پیدا نشده بود یا پروفایل وب اطلاعات کامل‌تری دارد
            if ($profile === null || (empty($profile['NationalID']) && !empty($profByWu['NationalID']))) {
                // اگر پروفایل خالی مجزایی با این TelegramID وجود داشته، حذف یا بازآرایی شود تا تکراری نماند
                if ($profile !== null && (int)$profile['ID'] !== (int)$profByWu['ID'] && empty($profile['NationalID']) && empty($profile['StudentID'])) {
                    db_exec("UPDATE web_users SET profile_id = ? WHERE profile_id = ?", [(int)$profByWu['ID'], (int)$profile['ID']]);
                    db_exec("DELETE FROM user_profiles WHERE ID = ?", [(int)$profile['ID']]);
                }
                // ابتدا TelegramID را از سایر رکوردها آزاد کن
                db_exec("UPDATE user_profiles SET TelegramID = 0 - ID WHERE TelegramID = ? AND ID != ?", [$tgId, (int)$profByWu['ID']]);
                $profile = $profByWu;
                db_exec("UPDATE user_profiles SET TelegramID = ? WHERE ID = ?", [$tgId, (int)$profile['ID']]);
            }
        }
    }

    // ۳) اگر کاربر شماره تماس دارد ولی هنوز به web_users وصل نشده، اتصال را با ایمنی کلید یکتا برقرار کنیم
    if ($profile !== null && !empty($profile['Phone'])) {
        $normPhone = phone_normalize($profile['Phone']);
        $intlPhone = phone_to_intl($profile['Phone']);
        $wuByPhone = db_one("SELECT id, telegram_id, profile_id FROM web_users WHERE phone IN (?, ?) LIMIT 1", [$normPhone, $intlPhone]);
        if ($wuByPhone !== null) {
            // اول تلگرام آیدی را از هر رکورد وب دیگری آزاد کن
            db_exec("UPDATE web_users SET telegram_id = NULL WHERE telegram_id = ? AND id != ?", [$tgId, (int)$wuByPhone['id']]);
            db_exec("UPDATE web_users SET telegram_id = ?, profile_id = ? WHERE id = ?", [$tgId, (int)$profile['ID'], (int)$wuByPhone['id']]);
        }
    }

    if ($profile !== null) {
        // به‌روزرسانی زمان آخرین ورود و نام‌ها در صورت خالی بودن
        $updates = ["LastLogin = NOW()", "TelegramID = ?"];
        $params = [$tgId];
        if (empty($profile['FirstName']) && $firstName !== '') {
            $updates[] = "FirstName = ?";
            $params[] = $firstName;
        }
        if (empty($profile['LastName']) && $lastName !== '') {
            $updates[] = "LastName = ?";
            $params[] = $lastName;
        }
        if (empty($profile['FullName']) && ($firstName !== '' || $lastName !== '')) {
            $updates[] = "FullName = ?";
            $params[] = trim($firstName . ' ' . $lastName);
        }
        $params[] = (int)$profile['ID'];
        // آزاد کردن تکراری‌ها قبل از آپدیت
        db_exec("UPDATE user_profiles SET TelegramID = 0 - ID WHERE TelegramID = ? AND ID != ?", [$tgId, (int)$profile['ID']]);
        db_exec("UPDATE user_profiles SET " . implode(', ', $updates) . " WHERE ID = ?", $params);
        $profile = db_one("SELECT * FROM user_profiles WHERE ID = ? LIMIT 1", [(int)$profile['ID']]);
    } else {
        // ۴) اگر کاربر واقعاً جدید است و هیچ رکوردی از قبل نداشته:
        $fullName = trim($firstName . ' ' . $lastName) ?: null;
        db_exec("UPDATE user_profiles SET TelegramID = 0 - ID WHERE TelegramID = ?", [$tgId]);
        db_exec(
            "INSERT INTO user_profiles (TelegramID, FirstName, LastName, FullName, IsProfileComplete, LastLogin)
             VALUES (?, ?, ?, ?, 0, NOW())
             ON DUPLICATE KEY UPDATE LastLogin = NOW()",
            [$tgId, $firstName, $lastName, $fullName]
        );
        $profile = db_one("SELECT * FROM user_profiles WHERE TelegramID = ? LIMIT 1", [$tgId]);
        tg_notify_admin("👤 کاربر جدید Mini App:\n{$firstName} {$lastName}\nID: {$tgId}" . ($username ? "\n@{$username}" : ""));
    }

    $session = site_create_session($tgId);
    json_ok(['data' => [
        'token'      => $session['token'],
        'expires_at' => $session['expires_at'],
        'profile'    => site_profile_payload($profile),
    ]], 'ورود موفق');
}

function site_logout(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    $tok = bearer_token();
    if ($tok !== null) {
        $payload = jwt_verify($tok);
        if ($payload !== null && !empty($payload['tg'])) {
            db_exec("DELETE FROM user_sessions WHERE TelegramID = ? AND Token = ?", [$payload['tg'], $payload['sid'] ?? '']);
        }
    }
    json_ok([], 'خروج انجام شد');
}

function site_me(): void
{
    $profile = site_current_user();
    if ($profile === null) json_err('احراز هویت لازم است', 401);

    $member = site_member_info($profile);
    $certCount = 0;
    $regCount = 0;
    if (($profile['NationalID'] ?? '') !== '') {
        $certCount = (int)db_val("SELECT COUNT(*) FROM certificates WHERE NationalID = ?", [$profile['NationalID']]);
    }
    $sid = $profile['StudentID'] ?? '';
    $ph = $profile['Phone'] ?? '';
    if ($sid !== '' || $ph !== '') {
        $regCount = (int)db_val(
            "SELECT COUNT(*) FROM workshop_registrations WHERE (StudentID = ? AND StudentID != 'EXT') OR (Phone = ? AND Phone != '')",
            [$sid, $ph]
        );
    }

    json_ok(['data' => [
        'profile'          => site_profile_payload($profile),
        'member'           => $member,
        'certificates_count' => $certCount,
        'registrations_count' => $regCount,
    ]]);
}

function site_update_profile(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    $profile = site_current_user();
    if ($profile === null) json_err('احراز هویت لازم است', 401);

    $tgId = (int)$profile['TelegramID'];
    $nationalId = only_digits(req_str($in, 'national_id'));
    $studentId = only_digits(req_str($in, 'student_id'));
    $firstName = fa_name_clean(req_str($in, 'first_name'));
    $lastName = fa_name_clean(req_str($in, 'last_name'));
    $phone = phone_normalize(req_str($in, 'phone'));

    if ($nationalId !== '' && !national_id_valid($nationalId)) json_err('کد ملی معتبر نیست');
    if ($studentId !== '' && strlen($studentId) < 8) json_err('شماره دانشجویی معتبر نیست');
    if ($phone !== '' && !phone_valid($phone)) json_err('شماره موبایل معتبر نیست');

    if ($nationalId !== '' || $studentId !== '' || $phone !== '') {
        $dup = db_one(
            "SELECT TelegramID FROM user_profiles WHERE ((? != '' AND NationalID = ?) OR (? != '' AND StudentID = ?) OR (? != '' AND Phone = ?)) AND TelegramID != ? LIMIT 1",
            [$nationalId, $nationalId, $studentId, $studentId, $phone, $phone, $tgId]
        );
        if ($dup !== null) json_err('این کد ملی، شماره دانشجویی یا شماره تماس قبلاً برای کاربر دیگری ثبت شده است', 409);
    }

    $updates = ['IsProfileComplete = 1', 'UpdatedAt = NOW()'];
    $params = [];
    foreach (['NationalID' => $nationalId, 'StudentID' => $studentId, 'FirstName' => $firstName, 'LastName' => $lastName, 'Phone' => $phone] as $col => $val) {
        if ($val !== '') {
            $updates[] = "$col = ?";
            $params[] = $val;
        }
    }
    if ($firstName !== '' && $lastName !== '') {
        $updates[] = 'FullName = ?';
        $params[] = $firstName . ' ' . $lastName;
    }
    $params[] = $tgId;
    db_exec("UPDATE user_profiles SET " . implode(', ', $updates) . " WHERE TelegramID = ?", $params);

    // Sync phone with web_users if linked
    if ($phone !== '') {
        $webUser = db_one("SELECT id FROM web_users WHERE telegram_id = ? LIMIT 1", [$tgId]);
        if ($webUser !== null) {
            $phoneTaken = db_one("SELECT id FROM web_users WHERE phone = ? AND id != ? LIMIT 1", [$phone, $webUser['id']]);
            if ($phoneTaken !== null) {
                json_err('این شماره موبایل قبلاً برای حساب وب دیگری ثبت شده است', 409);
            }
            db_exec("UPDATE web_users SET phone = ?, updated_at = NOW() WHERE id = ?", [$phone, $webUser['id']]);
        }
    }

    json_ok([], 'پروفایل با موفقیت به‌روزرسانی شد');
}

/* ============================================================
   Account deletion (task: درخواست حذف اطلاعات شخصی)
   Deletes ONLY the website account (web_users row + sessions + link).
   Memberships, certificates, workshop history are NOT touched —
   the user is told to contact support for those.
   Two-phase with a bot-delivered confirmation code (like admin 2FA).
   ============================================================ */

/** Phase 1: issue a deletion code and send it to the user's bot chat. */
function site_account_delete_request(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    $profile = site_current_user();
    if ($profile === null) json_err('احراز هویت لازم است', 401);

    $tgId = site_connected_telegram_id($profile);
    if ($tgId === null) json_err('ابتدا حساب خود را به تلگرام متصل کنید', 400);
    if ((string)Env::get('TELEGRAM_BOT_TOKEN', '') === '') json_err('ربات تلگرام در دسترس نیست. با پشتیبانی تماس بگیرید', 503);

    if (!rl_check('acct_delete', (string)$tgId, 3, 3600)) json_err('تعداد درخواست‌ها زیاد است. بعداً تلاش کنید', 429);

    $code = (string)random_int(100000, 999999);
    boot_session();
    $_SESSION['acct_delete'] = [
        'tg'        => $tgId,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires'   => time() + 300, // 5 minutes
        'attempts'  => 0,
    ];
    try {
        db_exec(
            "INSERT INTO bot_user_states (telegram_id, state, data) VALUES (?, 'acct_delete', ?) "
            . "ON DUPLICATE KEY UPDATE state = 'acct_delete', data = VALUES(data)",
            [$tgId, json_encode($_SESSION['acct_delete'], JSON_UNESCAPED_UNICODE)]
        );
    } catch (Throwable $e) {}

    $sent = tg_send($tgId,
        "🗑 <b>درخواست حذف حساب کاربری سایت</b>\n\n"
        . "کد تأیید: <code>{$code}</code>\n"
        . "⏳ اعتبار: ۵ دقیقه\n\n"
        . "⚠️ این درخواست فقط <b>حساب کاربری شما از وب‌سایت</b> را حذف می‌کند.\n"
        . "گواهینامه‌ها، سوابق ثبت‌نام و اطلاعات عضویت شما باقی می‌مانند.\n\n"
        . "اگر این درخواست از طرف شما نبوده، این کد را نادیده بگیرید."
    );
    if ($sent === null || !($sent['ok'] ?? false)) {
        unset($_SESSION['acct_delete']);
        json_err('ارسال کد به تلگرام ناموفق بود. با پشتیبانی تماس بگیرید', 502);
    }

    json_ok([], 'کد تأیید به تلگرام شما ارسال شد');
}

/** Phase 2: verify code → delete ONLY the web account, then log the user out. */
function site_account_delete_confirm(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    // Site API uses Bearer-auth (JWT) + rate limits, consistent with the rest of site.php

    $profile = site_current_user();
    if ($profile === null) json_err('احراز هویت لازم است', 401);

    boot_session();
    $tgId = site_connected_telegram_id($profile);
    if ($tgId === null) json_err('ابتدا حساب خود را به تلگرام متصل کنید', 400);
    $st = $_SESSION['acct_delete'] ?? null;
    if (empty($st)) {
        try {
            $row = db_one("SELECT data FROM bot_user_states WHERE telegram_id = ? AND state = 'acct_delete' LIMIT 1", [$tgId]);
            if ($row !== null && !empty($row['data'])) {
                $st = json_decode((string)$row['data'], true);
            }
        } catch (Throwable $e) {}
    }
    if (empty($st)) json_err('درخواستی یافت نشد. ابتدا کد بگیرید');
    if ($tgId === null || (int)$st['tg'] !== $tgId) {
        unset($_SESSION['acct_delete']);
        json_err('نشست حذف حساب نامعتبر است. دوباره اقدام کنید');
    }
    if (time() > (int)$st['expires']) {
        unset($_SESSION['acct_delete']);
        json_err('کد منقضی شده است. دوباره درخواست بدهید');
    }
    if ((int)$st['attempts'] >= 3) {
        unset($_SESSION['acct_delete']);
        json_err('تعداد تلاش بیش از حد مجاز. دوباره درخواست بدهید');
    }

    $st['attempts']++;
    $_SESSION['acct_delete'] = $st;

    if (!password_verify(req_str($in, 'code'), $st['code_hash'])) {
        json_err('کد وارد شده اشتباه است');
    }
    unset($_SESSION['acct_delete']);
    try { db_exec("DELETE FROM bot_user_states WHERE telegram_id = ? AND state = 'acct_delete'", [$tgId]); } catch (Throwable $e) {}

    $tg = $tgId;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 1) Only the website account: web_users row(s) for this telegram id
        $wu = db_all("SELECT id, phone FROM web_users WHERE telegram_id = ?", [$tg]);
        foreach ($wu as $w) {
            db_exec("DELETE FROM password_resets WHERE phone = ?", [$w['phone']]);
            db_exec("DELETE FROM login_attempts WHERE username = ?", [$w['phone']]);
        }
        db_exec("DELETE FROM web_users WHERE telegram_id = ?", [$tg]);
        // 2) Website sessions for this user
        db_exec("DELETE FROM user_sessions WHERE TelegramID = ?", [$tg]);
        // 3) Unlink telegram from the profile but KEEP the profile data
        //    (memberships / certificates / registrations are intentionally untouched)
        db_exec(
            "UPDATE user_profiles SET
                Phone = NULL, NationalID = NULL, StudentID = NULL,
                IsProfileComplete = 0, UpdatedAt = NOW()
             WHERE TelegramID = ?",
            [$tg]
        );
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    // Notify via bot (chat still works — bot is independent of the web account)
    tg_send($tg,
        "✅ <b>حساب کاربری شما از وب‌سایت حذف شد.</b>\n\n"
        . "✔ حذف‌شده: حساب کاربری سایت و نشست‌های ورود\n"
        . "🛡 محفوظ: گواهینامه‌ها، سوابق ثبت‌نام کارگاه و اطلاعات عضویت\n\n"
        . "اگر مایل به حذف کامل اطلاعات بالا هم هستید، به پشتیبانی پیام دهید: @saee_admin"
    );
    audit_log(null, 'account_delete', 'web_users', (string)$tg);

    json_ok(['data' => ['deleted' => true]], 'حساب کاربری شما از وب‌سایت حذف شد');
}

/* ============================================================
   Members
   ============================================================ */

function site_member_request(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    if (req_str($in, 'website') !== '') json_err('خطا در پردازش فرم');
    if (!rl_check('member_req', client_ip(), 5, 3600)) json_err('تعداد درخواست‌ها زیاد است. بعداً تلاش کنید', 429);

    $name = fa_name_clean(req_str($in, 'Name'));
    $studentId = only_digits(req_str($in, 'StudentID'));
    $phone = phone_normalize(req_str($in, 'Phone'));
    $nationalId = only_digits(req_str($in, 'NationalID'));
    $branch = clean_str(req_str($in, 'Branch'));
    $introducer = clean_str(req_str($in, 'Introducer'));

    if (mb_strlen($name) < 3) json_err('نام و نام خانوادگی الزامی است');
    if (!preg_match('/^\d{8,20}$/', $studentId)) json_err('شماره دانشجویی معتبر نیست');
    if (!phone_valid($phone)) json_err('شماره موبایل معتبر نیست');
    if (!national_id_valid($nationalId)) json_err('کد ملی معتبر نیست');

    if (db_one("SELECT ID FROM membersreq WHERE StudentID = ? OR NationalID = ? OR Phone = ? LIMIT 1", [$studentId, $nationalId, $phone]) !== null) {
        json_err('شما قبلاً درخواست عضویت داده‌اید. منتظر تأیید بمانید', 409);
    }
    if (db_one("SELECT ID FROM members WHERE StudentID = ? OR NationalID = ? LIMIT 1", [$studentId, $nationalId]) !== null) {
        json_err('شما از قبل عضو انجمن هستید', 409);
    }

    $id = gen_id('MRQ');
    db_exec(
        "INSERT INTO membersreq (ID, Date, Name, NationalID, StudentID, Phone, TelegramID, YearOfEnter, Branch, Status, Introducer)
         VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, 'NEW', ?)",
        [$id, $name, $nationalId, $studentId, $phone, null, substr($studentId, 0, 3), $branch ?: null, $introducer ?: null]
    );

    tg_notify_admin("📝 درخواست عضویت جدید\nکد: {$id}\nنام: {$name}\nموبایل: {$phone}");
    json_ok(['data' => ['request_id' => $id]], 'درخواست عضویت شما ثبت شد. پس از تأیید مدیران اطلاع‌رسانی می‌شود');
}

function site_member_points(array $in): void
{
    if (!rl_check('points_lookup', client_ip(), 20, 600)) json_err('درخواست‌های زیاد. کمی بعد تلاش کنید', 429);

    $studentId = only_digits(req_str($in, 'sid') ?: (string)($_GET['sid'] ?? ''));
    if (strlen($studentId) < 8) json_err('شماره دانشجویی معتبر نیست');

    $row = db_one("SELECT NameFa, Points, Active FROM members WHERE StudentID = ? LIMIT 1", [$studentId]);
    if ($row === null) json_err('عضوی با این شماره دانشجویی یافت نشد', 404);

    json_ok(['data' => [
        'name'    => $row['NameFa'],
        'points'  => (float)$row['Points'],
        'active'  => (int)$row['Active'],
    ]]);
}

/* ============================================================
   Banners / Workshops
   ============================================================ */

function site_banners_active(): void
{
    $rows = db_all(
        "SELECT b.ID, b.Title, b.ImageURL, b.LinkURL, b.TargetTag, b.StartDate, b.EndDate,
                ws.first_session
         FROM banners b
         LEFT JOIN workshops w ON w.Tag = b.TargetTag
         LEFT JOIN (
             SELECT workshop_tag, MIN(CONCAT(session_date, ' ', session_time)) AS first_session
             FROM workshop_sessions GROUP BY workshop_tag
         ) ws ON ws.workshop_tag = b.TargetTag
         WHERE b.IsActive = 1
           AND (b.StartDate IS NULL OR b.StartDate <= NOW())
           AND (b.EndDate IS NULL OR b.EndDate >= NOW())
         ORDER BY b.Priority DESC, b.CreatedAt DESC
         LIMIT 10"
    );
    $out = array_map(function ($b) {
        // Show the first SESSION date as the workshop start (or «اعلام می‌شود»)
        $wsStart = !empty($b['first_session']) ? date_jalali(substr($b['first_session'], 0, 10)) : null;

        // Registration open? (same rules as the admin panel - task 6)
        // Effective end = reg_window_end(EndDate) clamped by first session start.
        $now = time();
        $effEnd = reg_window_end($b['EndDate'] ?? null);
        if (!empty($b['first_session'])) {
            $fs = strtotime($b['first_session']);
            if ($fs !== false && ($effEnd === null || $fs < $effEnd)) $effEnd = $fs;
        }
        $startOk = true;
        if (!empty($b['StartDate'])) {
            $st = strtotime($b['StartDate']);
            $startOk = ($st === false || $now >= $st);
        }
        $regOpen = ($effEnd === null || $now <= $effEnd) && $startOk;

        return [
            'id'         => $b['ID'],
            'title'      => $b['Title'],
            'image'      => $b['ImageURL'] ?: null,
            'link'       => $b['LinkURL'] ?: null,
            'tag'        => $b['TargetTag'] ?: null,
            'start_jalali' => date_jalali($b['StartDate']),
            'end_jalali'   => date_jalali($b['EndDate']),
            'ws_start_jalali' => $wsStart,               // null → «اعلام می‌شود» در فرانت
            'reg_open'   => $regOpen,
        ];
    }, $rows);
    json_ok(['data' => ['banners' => $out]]);
}

function site_banner_click(array $in): void
{
    $id = req_str($in, 'id') ?: (string)($_GET['id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9\-]{3,50}$/', $id)) json_err('شناسه نامعتبر');
    if (rl_check('banner_click', client_ip() . '_' . $id, 3, 60)) {
        db_exec("UPDATE banners SET ClickCount = ClickCount + 1 WHERE ID = ?", [$id]);
    }
    json_ok();
}

function site_workshop_register(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    if (req_str($in, 'website') !== '') json_err('خطا در پردازش فرم');
    if (!rl_check('ws_register', client_ip(), 6, 3600)) json_err('تعداد ثبت‌نام‌ها زیاد است. بعداً تلاش کنید', 429);

    $fullName = fa_name_clean(req_str($in, 'FullName'));
    $phone = phone_normalize(req_str($in, 'Phone'));
    $field = clean_str(req_str($in, 'Field'));
    $tag = strtoupper(clean_str(req_str($in, 'WorkshopTag')));
    $isExternal = req_str($in, 'is_external') === '1';
    $studentId = only_digits(req_str($in, 'StudentID'));
    $university = clean_str(req_str($in, 'University'));

    if (mb_strlen($fullName) < 3) json_err('نام و نام خانوادگی الزامی است');
    if (!phone_valid($phone)) json_err('شماره موبایل معتبر نیست');
    if ($field === '') json_err('رشته تحصیلی الزامی است');
    if ($isExternal) {
        if (mb_strlen($university) < 3) json_err('نام دانشگاه الزامی است');
    } else {
        if (!preg_match('/^\d{8,20}$/', $studentId)) json_err('شماره دانشجویی معتبر نیست');
    }

    $workshop = null;
    if ($tag !== '') {
        $workshop = db_one("SELECT Title, GroupLink FROM workshops WHERE Tag = ? AND IsActive = 1 LIMIT 1", [$tag]);
        if ($workshop === null) json_err('این کارگاه در دسترس نیست', 404);

        // Registration window rules (task 6): closed at first-session time OR EndDate
        // NOTE: EndDate is a DATETIME column — appending " 23:59:59" made strtotime()
        // return false and "$now > false" always true → bogus «مهلت به پایان رسیده» 410.
        $banner = db_one(
            "SELECT StartDate, EndDate FROM banners WHERE TargetTag = ? AND IsActive = 1 LIMIT 1",
            [$tag]
        );
        $now = time();
        $effEnd = reg_window_end($banner['EndDate'] ?? null);
        $firstSession = db_val(
            "SELECT MIN(CONCAT(session_date, ' ', session_time)) FROM workshop_sessions WHERE workshop_tag = ?",
            [$tag]
        );
        if (!empty($firstSession)) {
            $fs = strtotime($firstSession);
            if ($fs !== false && ($effEnd === null || $fs < $effEnd)) $effEnd = $fs;
        }
        if ($effEnd !== null && $now > $effEnd) {
            json_err('مهلت ثبت‌نام این کارگاه به پایان رسیده است', 410);
        }
        if ($banner !== null && !empty($banner['StartDate'])) {
            $st = strtotime($banner['StartDate']);
            if ($st !== false && $now < $st) {
                json_err('ثبت‌نام این کارگاه هنوز آغاز نشده است', 410);
            }
        }
    }

    if ($isExternal) {
        $dup = db_one(
            "SELECT ID FROM workshop_registrations WHERE Phone = ? AND WorkshopType = ? LIMIT 1",
            [$phone, $tag !== '' ? $tag : 'general']
        );
    } else {
        $dup = db_one(
            "SELECT ID FROM workshop_registrations WHERE StudentID = ? AND WorkshopType = ? LIMIT 1",
            [$studentId, $tag !== '' ? $tag : 'general']
        );
    }
    if ($dup !== null) json_err('شما قبلاً در این کارگاه ثبت‌نام کرده‌اید', 409);

    $regId = gen_id('REG');
    // Registration is auto-confirmed (no PENDING stage)
    db_exec(
        "INSERT INTO workshop_registrations (ID, Date, FullName, Phone, StudentID, University, Field, WorkshopType, WorkshopTag, Status, GroupLink)
         VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, 'CONFIRMED', ?)",
        [
            $regId, $fullName, $phone,
            $isExternal ? 'EXT' : $studentId,
            $isExternal ? $university : null,
            $field,
            $tag !== '' ? $tag : 'general',
            $tag ?: null,
            $workshop['GroupLink'] ?? null,
        ]
    );

    tg_notify_admin(
        "🤖 ثبت‌نام کارگاه\nکد: {$regId}\nنام: {$fullName}\nموبایل: {$phone}\n" .
        ($isExternal ? "دانشگاه: {$university}" : "دانشجویی: {$studentId}") .
        ($tag !== '' ? "\nکارگاه: {$tag}" : '')
    );

    $groupLink = $workshop['GroupLink'] ?? null;

    // Confirmation via Telegram — best-effort: a Telegram hiccup must NEVER
    // fail the registration itself (registration is already committed above).
    $user = site_current_user();
    $tgId = null;
    if ($user !== null) {
        try {
            $tgId = site_connected_telegram_id($user);
            if ($tgId !== null) {
                $msg = "🤖 <b>ثبت‌نام شما در کارگاه انجام شد!</b>\n\n";
                if ($workshop !== null && !empty($workshop['Title'])) {
                    $msg .= "📚 کارگاه: {$workshop['Title']}\n";
                }
                $msg .= "🆔 کد ثبت‌نام: <code>{$regId}</code>\n\n";
                $extra = [];
                if ($groupLink) {
                    // group link as an inline BUTTON (standard Telegram UX)
                    $extra['reply_markup'] = ['inline_keyboard' => [
                        [['text' => '🚀 پیوستن به گروه کارگاه', 'url' => $groupLink]],
                    ]];
                    $msg .= "🔗 برای پیوستن به گروه، دکمه زیر را بزنید 👇";
                } else {
                    $msg .= "⏳ لینک گروه بعداً برای شما ارسال می‌شود.";
                }
                tg_send($tgId, $msg, $extra);
                db_exec(
                    "INSERT INTO notifications (telegram_id, title, message, type) VALUES (?, 'ثبت‌نام کارگاه', ?, 'success')",
                    [$tgId, "ثبت‌نام شما در کارگاه انجام شد (کد: {$regId})" . ($groupLink ? " — لینک گروه در پروفایل شما (بخش کارگاه‌های من) موجود است." : '')]
                );
            }
        } catch (Throwable $ex) {
            error_log('[ws-register-notify] ' . $ex->getMessage());
        }
    }

    // group_link goes back to the registrant's own session for the auto-join modal;
    // it is never embedded in broadcast chat text.
    json_ok([
        'data' => ['registration_id' => $regId, 'group_link' => $groupLink],
    ], 'ثبت‌نام شما با موفقیت انجام شد');
}

function site_workshop_req(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    if (req_str($in, 'website') !== '') json_err('خطا در پردازش فرم');
    if (!rl_check('ws_req', client_ip(), 5, 3600)) json_err('تعداد درخواست‌ها زیاد است. بعداً تلاش کنید', 429);

    $course = clean_str(req_str($in, 'Course'));
    $teacher = clean_str(req_str($in, 'Teacher'));
    $phone = phone_normalize(req_str($in, 'Phone'));
    $date = date_from_jalali(req_str($in, 'Date'));
    $time = only_digits(req_str($in, 'Time'));

    if ($course === '') json_err('عنوان دوره الزامی است');
    if ($teacher === '') json_err('نام مدرس الزامی است');
    if (!phone_valid($phone)) json_err('شماره موبایل معتبر نیست');
    if ($date === null) json_err('تاریخ معتبر نیست');
    if (!preg_match('/^\d{3,4}$/', $time)) json_err('ساعت معتبر نیست');
    $time = substr($time, 0, 2) . ':' . substr($time, 2, 2) . ':00';

    db_exec(
        "INSERT INTO workshopreq (Date, Time, Course, Teacher, Phone, Status) VALUES (?, ?, ?, ?, ?, 'NEW')",
        [$date, $time, $course, $teacher, $phone]
    );
    tg_notify_admin("🎓 درخواست کارگاه جدید\nدوره: {$course}\nمدرس: {$teacher}\nموبایل: {$phone}");
    json_ok([], 'درخواست کارگاه شما ثبت شد');
}

function site_class_req(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    if (req_str($in, 'website') !== '') json_err('خطا در پردازش فرم');
    if (!rl_check('cls_req', client_ip(), 5, 3600)) json_err('تعداد درخواست‌ها زیاد است. بعداً تلاش کنید', 429);

    $course = clean_str(req_str($in, 'Course'));
    $teacher = clean_str(req_str($in, 'Teacher'));
    $phone = phone_normalize(req_str($in, 'Phone'));
    $date = date_from_jalali(req_str($in, 'Date'));
    $time = only_digits(req_str($in, 'Time'));

    if ($course === '') json_err('عنوان درس الزامی است');
    if ($teacher === '') json_err('نام استاد الزامی است');
    if (!phone_valid($phone)) json_err('شماره موبایل معتبر نیست');
    if ($date === null) json_err('تاریخ معتبر نیست');
    if (!preg_match('/^\d{3,4}$/', $time)) json_err('ساعت معتبر نیست');
    $time = substr($time, 0, 2) . ':' . substr($time, 2, 2) . ':00';

    db_exec(
        "INSERT INTO classreq (Date, Time, Course, Teacher, Phone, Status) VALUES (?, ?, ?, ?, ?, 'NEW')",
        [$date, $time, $course, $teacher, $phone]
    );
    tg_notify_admin("📚 درخواست کلاس حل تمرین\nدرس: {$course}\nاستاد: {$teacher}\nموبایل: {$phone}");
    json_ok([], 'درخواست کلاس حل تمرین شما ثبت شد');
}

/* ============================================================
   Certificates
   ============================================================ */

function site_cert_check(array $in): void
{
    if (!rl_check('cert_check', client_ip(), 30, 600)) json_err('تعداد استعلام‌ها زیاد است. کمی بعد تلاش کنید', 429);

    $raw = req_str($in, 'id') ?: (string)($_GET['id'] ?? '');
    // ارقام فارسی/عربی → لاتین؛ حذف فاصله، ZWNJ و علائم جهت
    $id = str_replace(["\u{200C}", "\u{200F}", ' ', "\t"], '', fa2en(trim($raw)));
    if ($id === '' || mb_strlen($id) > 50 || !preg_match('/^[\p{L}\p{N}\-]{3,50}$/u', $id)) {
        json_err('کد گواهینامه معتبر نیست');
    }

    $cols = 'ID, NameFa, NameEn, Course, IssueDate, ExpDate';
    $shape = static function (array $r): array {
        return [
            'id'           => $r['ID'],
            'name_fa'      => $r['NameFa'],
            'name_en'      => $r['NameEn'],
            'course'       => $r['Course'],
            'issue_jalali' => date_jalali((string)$r['IssueDate']),
            'exp_jalali'   => ($r['ExpDate'] !== null && $r['ExpDate'] !== '') ? date_jalali((string)$r['ExpDate']) : 'دائمی',
        ];
    };
    $alive = static function (array $r): bool {
        return ($r['ExpDate'] === null || $r['ExpDate'] === '' || strtotime((string)$r['ExpDate']) >= time());
    };

    // ۱) تطابق دقیق همان‌طور که کاربر تایپ کرده
    $cert = db_one("SELECT $cols FROM certificates WHERE ID = ? LIMIT 1", [$id]);

    /* الگوی کد: YYYYMM-xxxxx یا YYYYMMxxxxx — سریال ۴ تا ۶ کاراکتر (حرف یا رقم).
       بدون پیش‌شرط روی سال/ماه: طول ۱۰..۱۲ ← کد کامل، طول ۴..۶ ← فقط سریال. */
    $norm = str_replace('-', '', $id);
    $len = strlen($norm);

    // ۲) کد کامل (حالت ۲): جست‌وجو در هر دو قالب با/بدون خط‌تیره + درج خط‌تیره پس از ۶ رقم سال‌ماه
    if ($cert === null && $len >= 10 && $len <= 12 && preg_match('/^[A-Za-z0-9]+$/D', $norm)) {
        $withDash = substr($norm, 0, 6) . '-' . substr($norm, 6);
        $cert = db_one(
            "SELECT $cols FROM certificates
             WHERE ID = ? OR REPLACE(ID, '-', '') = ? OR ID = ? LIMIT 1",
            [$id, $norm, $withDash]
        );
    }

    // ۳) فقط سریال ۴..۶ کاراکتری (حالت ۳): تطابق انتهای کد / بخشی پس از خط‌تیره
    if ($cert === null && $len >= 4 && $len <= 6 && preg_match('/^[A-Za-z0-9]+$/D', $norm)) {
        $rows = db_all(
            "SELECT $cols FROM certificates
             WHERE RIGHT(REPLACE(ID, '-', ''), ?) = ?
                OR (ID LIKE '%-%' AND SUBSTRING_INDEX(ID, '-', -1) = ?)
             ORDER BY IssueDate DESC, ID DESC LIMIT 5",
            [$len, $norm, $norm]
        );
        if (!$rows) json_err('گواهینامه‌ای با این کد یافت نشد', 404);
        if (count($rows) > 1) {
            json_ok(['data' => ['multiple' => true, 'options' => array_map($shape, $rows)]],
                count($rows) . ' گواهی با این سریال پیدا شد');
        }
        $cert = $rows[0];
    }

    if ($cert === null) json_err('گواهینامه‌ای با این کد یافت نشد', 404);

    $valid = $alive($cert);
    json_ok(['data' => [
        'certificate' => $shape($cert),
        'is_valid'    => $valid,
        'status_text' => $valid ? 'معتبر' : 'منقضی شده',
    ]]);
}

function site_cert_file(array $in): void
{
    if (!rl_check('cert_file', client_ip(), 60, 300)) json_err('تعداد درخواست‌ها زیاد است', 429);

    $id = req_str($in, 'id') ?: (string)($_GET['id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9\-]{3,50}$/', $id)) json_err('کد نامعتبر');

    // Ownership gate: only the holder may render/download the image
    site_cert_owner_check($id);
    cert_stream_png($id, false);
}
function site_cert_download(array $in): void
{
    if (!rl_check('cert_download', client_ip(), 30, 600)) json_err('تعداد درخواست‌ها زیاد است. کمی بعد تلاش کنید', 429);

    $id = req_str($in, 'id') ?: (string)($_GET['id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9\-]{3,50}$/', $id)) json_err('کد گواهینامه معتبر نیست');

    site_cert_owner_check($id);
    cert_stream_png($id, true);
}

/**
 * Certificate images may be opened only by their owner:
 * logged-in profile whose NationalID equals the certificate's,
 * or a logged-in admin of the panel (for support) — guests get 401.
 */
function site_cert_owner_check(string $certId): void
{
    // panel admin (support) may open any certificate
    boot_session();
    if (!empty($_SESSION['admin']['id'])) {
        $row = db_one("SELECT id, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1", [(int)$_SESSION['admin']['id']]);
        if ($row !== null) return;
    }

    $profile = site_current_user();
    $ownerNid = (string)db_val("SELECT NationalID FROM certificates WHERE ID = ? LIMIT 1", [$certId]);
    if ($ownerNid === '') {
        if ($profile === null) json_err('برای دریافت فایل گواهی وارد حساب شوید', 401);
        return;
    }
    if ($profile === null) json_err('دریافت فایل گواهی فقط برای دارندهٔ آن ممکن است — وارد حساب شوید', 401);
    $mine = (string)($profile['NationalID'] ?? '');
    $ok = $mine !== '' && $mine === $ownerNid;
    if (!$ok) json_err('این گواهی متعلق به حساب شما نیست', 403);
}

function site_my_certificates(): void
{
    $profile = site_current_user();
    if ($profile === null) json_err('احراز هویت لازم است', 401);
    cert_schema_ready();

    $nationalId = $profile['NationalID'] ?? '';
    if ($nationalId === '') json_ok(['data' => ['certificates' => []]]);

    $rows = db_all(
        "SELECT ID, NameFa, Course, CertType, IssueDate, ExpDate FROM certificates
         WHERE NationalID = ? ORDER BY IssueDate DESC",
        [$nationalId]
    );
    $out = array_map(fn($c) => [
        'id'           => $c['ID'],
        'name_fa'      => $c['NameFa'],
        'course'       => $c['Course'],
        'cert_type'    => $c['CertType'] ?? null,
        'issue_jalali' => date_jalali($c['IssueDate']),
        'exp_jalali'   => $c['ExpDate'] ? date_jalali($c['ExpDate']) : 'دائمی',
        'is_valid'     => $c['ExpDate'] === null || strtotime($c['ExpDate']) >= time(),
    ], $rows);
    json_ok(['data' => ['certificates' => $out]]);
}

/* ============================================================
   Certificate Service — membership card download
   (active members only; same render architecture as certificates)
   ============================================================ */

function site_member_card(): void
{
    if (!rl_check('member_card', client_ip(), 20, 3600)) json_err('تعداد درخواست‌ها زیاد است. کمی بعد تلاش کنید', 429);

    $profile = site_current_user();
    if ($profile === null) json_err('احراز هویت لازم است', 401);
    $nationalId = (string)($profile['NationalID'] ?? '');
    if ($nationalId === '') json_err('ابتدا کد ملی خود را در پروفایل تکمیل کنید', 400);

    $member = db_one("SELECT ID, NameFa, NationalID, StudentID, IssueDate, ExpDate, Points, Active, FileLink
                      FROM members WHERE NationalID = ? LIMIT 1", [$nationalId]);
    if ($member === null) json_err('شما عضو انجمن نیستید', 403);
    if (!(int)($member['Active'] ?? 0)) json_err('کارت عضویت فقط برای اعضای فعال صادر می‌شود', 403);

    $rel = (string)($member['FileLink'] ?? '');
    $path = $rel !== '' ? BASE_PATH . '/' . ltrim($rel, '/') : '';
    $fresh = $path !== '' && is_file($path) && (time() - (int)@filemtime($path)) < 86400;
    if (!$fresh) {
        $err = null;
        $bytes = card_render_png($member, $err);
        if ($bytes === null) json_err('تولید کارت ناموفق بود — ' . ($err ?: 'قالب/فونت را بررسی کنید'), 503);
        $outDir = BASE_PATH . '/assets/cards';
        if (!is_dir($outDir)) @mkdir($outDir, 0755, true);
        $file = 'card_' . preg_replace('/[^0-9A-Za-z]/', '', $nationalId) . '.png';
        $tmp = $outDir . '/' . $file . '.tmp';
        if (@file_put_contents($tmp, $bytes) === false || !@rename($tmp, $outDir . '/' . $file)) {
            @unlink($tmp);
            json_err('ذخیره کارت ناموفق بود', 500);
        }
        db_exec("UPDATE members SET FileLink = ? WHERE NationalID = ?", ['assets/cards/' . $file, $nationalId]);
        $path = $outDir . '/' . $file;
    }

    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="SSAEE-Member-Card-' . preg_replace('/[^0-9A-Za-z]/', '', $nationalId) . '.png"');
    readfile($path);
    exit;
}

function site_my_registrations(): void{
    $profile = site_current_user();
    if ($profile === null) json_err('احراز هویت لازم است', 401);

    $sid = $profile['StudentID'] ?? '';
    $ph = $profile['Phone'] ?? '';
    $rows = [];
    if ($sid !== '' || $ph !== '') {
        // GroupLink: registration-time link first, fall back to the live workshop link
        // (the admin may add/update the link AFTER someone registered)
        $rows = db_all(
            "SELECT r.ID, r.Date, r.FullName, r.WorkshopType, r.Status, r.University,
                    COALESCE(NULLIF(r.GroupLink, ''), NULLIF(w.GroupLink, '')) AS GroupLink,
                    COALESCE(NULLIF(w.Title, ''), r.WorkshopType) AS WorkshopTitle
             FROM workshop_registrations r
             LEFT JOIN workshops w ON w.Tag = r.WorkshopTag
             WHERE (r.StudentID = ? AND r.StudentID != 'EXT') OR (r.Phone = ? AND r.Phone != '')
             ORDER BY r.Date DESC LIMIT 50",
            [$sid, $ph]
        );
    }
    $out = array_map(fn($r) => [
        'id'       => $r['ID'],
        'date_jalali' => date_jalali($r['Date']),
        'title'    => $r['WorkshopTitle'] ?: $r['WorkshopType'],
        'status'   => $r['Status'],
        'university' => $r['University'],
        'group_link' => $r['GroupLink'],
    ], $rows);
    json_ok(['data' => ['registrations' => $out]]);
}

/* ============================================================
   Collaboration & Feedback
   ============================================================ */

function site_collab(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    if (req_str($in, 'website') !== '') json_err('خطا در پردازش فرم');
    if (!rl_check('collab', client_ip(), 8, 3600)) json_err('تعداد درخواست‌ها زیاد است. بعداً تلاش کنید', 429);

    $type = req_str($in, 'type');
    $phone = phone_normalize(req_str($in, 'Phone'));

    switch ($type) {
        case 'association': {
            $name = clean_str(req_str($in, 'Name'));
            $university = clean_str(req_str($in, 'University'));
            if (mb_strlen($name) < 3) json_err('نام انجمن الزامی است');
            if ($university === '') json_err('نام دانشگاه الزامی است');
            if (!phone_valid($phone)) json_err('شماره تماس معتبر نیست');

            $id = gen_id('ASC');
            db_exec(
                "INSERT INTO associations (ID, Date, Name, University, Field, Course, Phone, Status)
                 VALUES (?, CURDATE(), ?, ?, ?, ?, ?, 'NEW')",
                [$id, $name, $university, clean_str(req_str($in, 'Field')) ?: null, clean_str(req_str($in, 'Course')) ?: null, $phone]
            );
            tg_notify_admin("🤝 همکاری انجمن جدید\nکد: {$id}\n{$name} - {$university}\nموبایل: {$phone}");
            json_ok(['data' => ['request_id' => $id]], 'درخواست همکاری ثبت شد');
        }

        case 'teacher': {
            $name = fa_name_clean(req_str($in, 'Name'));
            if (mb_strlen($name) < 3) json_err('نام و نام خانوادگی الزامی است');
            if (!phone_valid($phone)) json_err('شماره تماس معتبر نیست');

            $id = gen_id('TCH');
            db_exec(
                "INSERT INTO teachers (ID, Date, Name, University, AcRank, Course, Phone, Status)
                 VALUES (?, CURDATE(), ?, ?, ?, ?, ?, 'NEW')",
                [$id, $name, clean_str(req_str($in, 'University')) ?: null, clean_str(req_str($in, 'AcRank')) ?: null, clean_str(req_str($in, 'Course')) ?: null, $phone]
            );
            tg_notify_admin("👨‍🏫 همکاری استاد جدید\nکد: {$id}\n{$name}\nموبایل: {$phone}");
            json_ok(['data' => ['request_id' => $id]], 'درخواست همکاری ثبت شد');
        }

        case 'company': {
            $name = clean_str(req_str($in, 'Name'));
            if (mb_strlen($name) < 2) json_err('نام شرکت الزامی است');
            if (!phone_valid($phone)) json_err('شماره تماس معتبر نیست');

            $id = gen_id('COM');
            db_exec(
                "INSERT INTO companies (ID, Date, Name, Note, Phone, Status)
                 VALUES (?, CURDATE(), ?, ?, ?, 'NEW')",
                [$id, $name, clean_str(req_str($in, 'Note')) ?: null, $phone]
            );
            tg_notify_admin("🏢 همکاری شرکت جدید\nکد: {$id}\n{$name}\nموبایل: {$phone}");
            json_ok(['data' => ['request_id' => $id]], 'درخواست همکاری ثبت شد');
        }

        case 'sponsor': {
            $brand = clean_str(req_str($in, 'Brand'));
            $type2 = clean_str(req_str($in, 'Type'));
            if (mb_strlen($brand) < 2) json_err('نام برند الزامی است');
            if ($type2 === '') json_err('نوع همکاری الزامی است');
            if (!phone_valid($phone)) json_err('شماره تماس معتبر نیست');

            $email = filter_var(req_str($in, 'Email'), FILTER_VALIDATE_EMAIL) ?: null;
            $website = req_str($in, 'Website');
            if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) json_err('آدرس وب‌سایت معتبر نیست');

            $id = gen_id('SPN');
            // merge conditional fields into existing schema columns (no DB change)
            $goods = trim(req_str($in, 'Goods'));
            $svc = trim(req_str($in, 'ServiceNote'));
            $details = $goods !== '' ? 'محصول/خدمات: ' . $goods : ($svc !== '' ? 'خدمات: ' . $svc : null);
            $note = trim(req_str($in, 'Note'));
            $noteOut = $note !== '' ? $note : ($details ? null : null);
            if ($details !== null && $noteOut === null) $noteOut = $details;
            db_exec(
                "INSERT INTO sponsor (ID, Date, Brand, Interface, Type, Money, Details, Email, Phone, Telegram, Website, Note)
                 VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $id, $brand,
                    clean_str(req_str($in, 'Interface')) ?: null,
                    $type2,
                    (float)str_replace(',', '', fa2en(req_str($in, 'Money', '0'))),
                    $details !== null ? clean_str($details) : (clean_str(req_str($in, 'Details')) ?: null),
                    $email, $phone,
                    clean_str(req_str($in, 'Telegram')) ?: null,
                    $website ?: null,
                    $noteOut !== null ? clean_str($noteOut) : null,
                ]
            );
            tg_notify_admin("💎 پیشنهاد اسپانسرشیپ جدید\nکد: {$id}\nبرند: {$brand}\nنوع: {$type2}\nموبایل: {$phone}");
            json_ok(['data' => ['request_id' => $id]], 'پیشنهاد همکاری شما ثبت شد');
        }

        default:
            json_err('نوع همکاری نامعتبر', 400);
    }
}

function site_feedback(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    if (req_str($in, 'website') !== '') json_err('خطا در پردازش فرم');
    if (!rl_check('feedback', client_ip(), 5, 3600)) json_err('تعداد پیام‌ها زیاد است. بعداً تلاش کنید', 429);

    $phone = phone_normalize(req_str($in, 'Phone'));
    $subject = clean_str(req_str($in, 'Subject'));
    $note = clean_str(req_str($in, 'Note'));

    if (!phone_valid($phone)) json_err('شماره موبایل معتبر نیست');
    if ($subject === '') json_err('موضوع الزامی است');
    if (mb_strlen($note) < 5) json_err('متن پیام کوتاه است');
    if (mb_strlen($note) > 2000) json_err('متن پیام بیش از حد طولانی است');

    db_exec("INSERT INTO suggestions (Phone, Unit, Subject, Note) VALUES (?, ?, ?, ?)", [$phone, clean_str(req_str($in, 'Unit')) ?: null, $subject, $note]);
    tg_notify_admin("💬 پیام جدید (انتقادات و پیشنهادات)\nموضوع: {$subject}\nموبایل: {$phone}");
    json_ok([], 'پیام شما با موفقیت ثبت شد. سپاس از همراهی شما');
}

/* ============================================================
   Class group links (public)
   ============================================================ */

function site_classes(): void
{
    $q = req_str($_GET, 'q');
    $params = [];
    $where = 'is_active = 1';

    if ($q !== '') {
        $q = clean_str($q);
        $where .= ' AND (course_name LIKE ? OR teacher LIKE ?)';
        $params = ["%{$q}%", "%{$q}%"];
    }

    $rows = db_all(
        "SELECT id, course_name, teacher, class_day, class_time, link
         FROM class_links
         WHERE {$where}
         ORDER BY course_name ASC, id ASC
         LIMIT 100",
        $params
    );

    $out = array_map(fn($r) => [
        'id'      => (int)$r['id'],
        'course'  => $r['course_name'],
        'teacher' => $r['teacher'],
        'day'     => $r['class_day'],
        'time'    => $r['class_time'],
        'link'    => preg_match('#^https://#i', (string)$r['link']) ? $r['link'] : null,
    ], $rows);

    json_ok(['data' => ['classes' => $out]]);
}

/* ============================================================
   Board members (public — About modal slideshow)
   ============================================================ */

function board_normalize_telegram(string $raw): ?string
{
    $t = trim($raw);
    if ($t === '') return null;
    if (preg_match('/^https:\/\/t\.me\/[\w\-\/]+$/i', $t)) return $t;
    if (preg_match('/^t\.me\/([\w\-]+)$/i', $t, $m)) return 'https://t.me/' . $m[1];
    if (preg_match('/^@?([A-Za-z0-9_]{4,32})$/', $t, $m)) return 'https://t.me/' . $m[1];
    return null;
}

function board_sanitize_photo(string $raw): ?string
{
    $p = trim($raw);
    if ($p === '') return null;
    if (preg_match('#^assets/board/[\w\-]+\.(?:jpg|jpeg|png|webp)$#i', $p)) return $p;
    if (preg_match('#^https://[\w\-./?%&=]+$#i', $p)) return $p;
    return null;
}

function site_board(): void
{
    $term = req_str($_GET, 'term');

    if ($term === '') {
        $current = db_val("SELECT term FROM board_members WHERE is_current = 1 ORDER BY id DESC LIMIT 1");
        if ($current === null) {
            $current = db_val("SELECT term FROM board_members ORDER BY id DESC LIMIT 1");
        }
        $term = (string)($current ?? '');
    }

    $terms = array_column(
        db_all("SELECT term, MAX(id) AS last_id FROM board_members GROUP BY term ORDER BY last_id DESC"),
        'term'
    );

    $members = [];
    if ($term !== '') {
        $rows = db_all(
            "SELECT name, position, telegram, linkedin, photo FROM board_members
             WHERE term = ? AND (end_date IS NULL OR end_date >= CURDATE()) ORDER BY sort ASC, id ASC LIMIT 60",
            [$term]
        );
        $members = array_map(fn($r) => [
            'name'     => $r['name'],
            'position' => $r['position'],
            'telegram' => board_normalize_telegram((string)$r['telegram']),
            'linkedin' => (preg_match('#^https://(www\.)?linkedin\.com/#i', (string)$r['linkedin'])) ? $r['linkedin'] : null,
            'photo'    => board_sanitize_photo((string)$r['photo']),
        ], $rows);
    }

    json_ok(['data' => [
        'term'      => $term,
        'terms'     => $terms,
        'members'   => $members,
        'positions' => board_positions(),
    ]]);
}

/* ============================================================
   Activities & Stats (public modals)
   ============================================================ */

function site_announcements(): void
{
    $rows = db_all(
        "SELECT title, message, type FROM announcements
         WHERE is_active = 1
           AND (start_date IS NULL OR start_date <= NOW())
           AND (end_date IS NULL OR end_date >= NOW())
         ORDER BY created_at DESC LIMIT 5"
    );
    json_ok(['data' => ['announcements' => $rows]]);
}

function site_activities(): void
{
    $rows = db_all(
        "SELECT id, title, act_date, description, image_url FROM activities
         ORDER BY act_date DESC, id DESC LIMIT 50"
    );
    $out = array_map(fn($a) => [
        'id'          => (int)$a['id'],
        'title'       => $a['title'],
        'date_jalali' => date_jalali($a['act_date']),
        'description' => $a['description'],
        'image'       => preg_match('#^https://#i', (string)$a['image_url']) ? $a['image_url'] : null,
    ], $rows);
    json_ok(['data' => ['activities' => $out]]);
}

function site_stats(): void
{
    $satisfaction = (float)(setting('satisfaction_rate', '0') ?? 0);
    $clubScore = (float)(setting('researchers_club_score', '0') ?? 0);
    $totalRegs = (int)db_val("SELECT COUNT(*) FROM workshop_registrations");

    $memberCount = (int)db_val("SELECT COUNT(*) FROM members");
    $participating = (int)db_val(
        "SELECT COUNT(DISTINCT m.ID) FROM members m
         WHERE m.StudentID IS NOT NULL AND m.StudentID != ''
           AND EXISTS (SELECT 1 FROM workshop_registrations r WHERE r.StudentID = m.StudentID)"
    );
    $pct = $memberCount > 0 ? round($participating / $memberCount * 100) : 0;
    $totalPoints = (float)(db_val("SELECT COALESCE(SUM(Points),0) FROM members") ?? 0);

    json_ok(['data' => [
        'satisfaction'        => $satisfaction,
        'total_registrations' => $totalRegs,
        'club_score'          => $clubScore,
        'members'             => [
            'count'       => $memberCount,
            'participation' => $pct,
            'total_points'  => $totalPoints,
        ],
    ]]);
}

/* ============================================================
   Telegram Connect
   ============================================================ */

function site_connected_telegram_id(array $profile): ?int
{
    $initData = req_str(req_input(), 'initData') ?: (string)($_GET['initData'] ?? '');
    if ($initData !== '') {
        $u = tg_verify_init_data($initData);
        if ($u !== null) return (int)$u['id'];
    }
    $row = db_one(
        "SELECT telegram_id FROM web_users
         WHERE telegram_id IS NOT NULL AND (phone IN (?, ?) OR telegram_id = ?) LIMIT 1",
        [phone_normalize($profile['Phone'] ?? ''), phone_to_intl($profile['Phone'] ?? ''), (int)$profile['TelegramID']]
    );
    return $row !== null ? (int)$row['telegram_id'] : null;
}

/* ============================================================
   Web quick-login handshake (site ⇄ bot) — replaces the old
   "open bot, click link" flow: the site opens a 20s countdown,
   the bot writes a one-time token, the site polls and auto-logs-in.
   ============================================================ */

function site_weblogin_purge(): void
{
    try {
        foreach (db_all("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'wl\\_%'") as $r) {
            $v = json_decode((string)$r['setting_value'], true);
            $ts = is_array($v) ? (int)($v['ts'] ?? 0) : 0;
            if ($ts === 0 || time() - $ts > 600) {
                db_exec("DELETE FROM settings WHERE setting_key = ?", [$r['setting_key']]);
            }
        }
    } catch (Throwable $ex) { error_log('[weblogin.purge] ' . $ex->getMessage()); }
}

function site_weblogin_start(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    if (!rl_check('weblogin_start', client_ip(), 8, 300)) json_err('تعداد تلاش زیاد است. کمی بعد دوباره امتحان کنید', 429);

    $bot = setting('bot_username') ?: (string)Env::get('TELEGRAM_BOT_USERNAME', '');
    if ($bot === '') json_err('ربات تلگرام هنوز پیکربندی نشده است. از پشتیبانی کمک بگیرید', 500);

    site_weblogin_purge();
    $code = strtoupper(bin2hex(random_bytes(5)));
    setting_set('wl_req_' . $code, json_encode(['ts' => time(), 'ip' => client_ip()]));

    json_ok(['data' => [
        'code'    => $code,
        'url'     => 'https://t.me/' . $bot . '?start=wl_' . $code,
        'expires' => 20,
    ]]);
}

function site_weblogin_poll(): void
{
    if (!rl_check('weblogin_poll', client_ip(), 150, 120)) json_err('تعداد تلاش زیاد است', 429);
    $code = strtoupper(req_str($_GET, 'code'));
    if (!preg_match('/^[A-F0-9]{6,20}$/D', $code)) json_err('کد نامعتبر');

    $done = json_decode((string)setting('wl_done_' . $code, ''), true);
    if (is_array($done) && (string)($done['token'] ?? '') !== '') {
        db_exec("DELETE FROM settings WHERE setting_key IN (?, ?)", ['wl_done_' . $code, 'wl_req_' . $code]);
        json_ok(['data' => ['token' => $done['token']]], 'ورود با تلگرام انجام شد');
    }

    $req = json_decode((string)setting('wl_req_' . $code, ''), true);
    if (!is_array($req) || time() - (int)($req['ts'] ?? 0) > 300) {
        json_ok(['data' => ['expired' => true]]);
    }
    json_ok(['data' => ['pending' => true]]);
}

function site_tg_connect_start(): void
{
    $profile = site_current_user();
    if ($profile === null) json_err('احراز هویت لازم است', 401);

    $wu = db_one(
        "SELECT * FROM web_users WHERE profile_id = ? OR phone IN (?, ?) LIMIT 1",
        [$profile['ID'] ?? 0, phone_normalize($profile['Phone'] ?? ''), phone_to_intl($profile['Phone'] ?? '')]
    );
    if ($wu === null) {
        json_err('برای اتصال تلگرام باید با شماره موبایل وارد شده باشید (ثبت‌نام با شماره)', 400);
    }
    if (!empty($wu['telegram_id'])) json_ok(['data' => ['already_connected' => true]], 'حساب شما قبلاً به تلگرام متصل شده است');

    $botUsername = setting('bot_username') ?: (string)Env::get('TELEGRAM_BOT_USERNAME', '');
    if ($botUsername === '') json_err('نام کاربری ربات در تنظیمات ثبت نشده است', 500);

    $code = strtoupper(bin2hex(random_bytes(4)));
    db_exec(
        "UPDATE web_users SET link_code = ?, link_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?",
        [$code, $wu['id']]
    );

    json_ok(['data' => [
        'code'    => $code,
        'url'     => "https://t.me/{$botUsername}?start=link_{$code}",
        'expires' => 900,
    ]], 'کد اتصال ساخته شد');
}

function site_tg_connect_direct(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);

    $profile = site_current_user();
    if ($profile === null) json_err('احراز هویت لازم است', 401);

    $initData = req_str($in, 'initData') ?: (req_str($in, 'init_data') ?: (req_str($_POST, 'initData') ?: (req_str($_POST, 'init_data') ?: req_str($_GET, 'initData'))));
    if ($initData === '') json_err('اتصال مستقیم فقط داخل تلگرام امکان‌پذیر است');

    $tgUser = tg_verify_init_data($initData);
    if ($tgUser === null) json_err('اعتبارسنجی تلگرام ناموفق بود', 401);

    $realTgId = (int)$tgUser['id'];
    $wu = db_one(
        "SELECT * FROM web_users WHERE profile_id = ? OR phone IN (?, ?) LIMIT 1",
        [$profile['ID'] ?? 0, phone_normalize($profile['Phone'] ?? ''), phone_to_intl($profile['Phone'] ?? '')]
    );
    if ($wu === null) json_err('برای اتصال تلگرام باید با شماره موبایل وارد شده باشید', 400);

    $taken = db_one("SELECT id FROM web_users WHERE telegram_id = ? AND id != ? LIMIT 1", [$realTgId, $wu['id']]);
    if ($taken !== null) json_err('این حساب تلگرام به حساب دیگری متصل است', 409);

    $firstName = clean_str((string)($tgUser['first_name'] ?? ''));
    $lastName = clean_str((string)($tgUser['last_name'] ?? ''));

    // پروفایل کانونیِ حساب (دارای کد ملی/شماره دانشجویی) را به شناسهٔ تلگرام واقعی منتقل کن — نه ساخت حساب تکراری
    $prof = ($wu['profile_id'] ?? null) !== null
        ? db_one("SELECT * FROM user_profiles WHERE ID = ?", [(int)$wu['profile_id']])
        : null;
    $keepId = null;
    if ($prof !== null) {
        $keepId = (int)$prof['ID'];
        $dupe = db_one("SELECT ID, NationalID, StudentID FROM user_profiles WHERE TelegramID = ? AND ID != ? LIMIT 1", [$realTgId, $keepId]);
        if ($dupe !== null && trim((string)($dupe['NationalID'] ?? '')) === '' && trim((string)($dupe['StudentID'] ?? '')) === '') {
            db_exec("DELETE FROM user_profiles WHERE ID = ?", [(int)$dupe['ID']]);
        }
        db_exec(
            "UPDATE user_profiles SET TelegramID = ?, Phone = COALESCE(NULLIF(?,''), Phone),
                 FirstName = COALESCE(NULLIF(?,''), FirstName), LastName = COALESCE(NULLIF(?,''), LastName)
             WHERE ID = ?",
            [$realTgId, $profile['Phone'] ?? null, $firstName, $lastName, $keepId]
        );
    } else {
        $existing = db_one("SELECT ID FROM user_profiles WHERE TelegramID = ?", [$realTgId]);
        if ($existing === null) {
            db_exec(
                "INSERT INTO user_profiles (TelegramID, Phone, FirstName, LastName, FullName, IsProfileComplete, LastLogin)
                 VALUES (?, ?, ?, ?, ?, 0, NOW())",
                [$realTgId, $profile['Phone'] ?? null, $firstName, $lastName, trim($firstName . ' ' . $lastName) ?: null]
            );
            $keepId = (int)db_id();
        } else {
            $keepId = (int)$existing['ID'];
        }
    }

    db_exec("UPDATE web_users SET telegram_id = ?, profile_id = ?, link_code = NULL, link_expires = NULL WHERE id = ?", [$realTgId, $keepId, $wu['id']]);

    tg_send($realTgId, "✅ حساب شما با موفقیت به پنل SSAEE متصل شد.\nاز این پس اطلاعیه‌ها را دریافت می‌کنید.");
    json_ok([], 'حساب شما به تلگرام متصل شد');
}

/* ============================================================
   Password reset (delivered via Telegram bot)
   ============================================================ */

function site_reset_request(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    if (!rl_check('pwd_reset', client_ip(), 3, 3600)) json_err('تعداد درخواست‌ها زیاد است. بعداً تلاش کنید', 429);

    $phone = phone_normalize(req_str($in, 'phone'));
    $wu = db_one("SELECT * FROM web_users WHERE phone IN (?, ?) AND is_active = 1 LIMIT 1", [$phone, phone_to_intl($phone)]);

    if ($wu !== null && !empty($wu['telegram_id'])) {
        $token = bin2hex(random_bytes(32));
        db_exec(
            "INSERT INTO password_resets (phone, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))",
            [$phone, hash('sha256', $token)]
        );
        $url = rtrim((string)Env::get('APP_URL', ''), '/') . '/#reset=' . $token;
        tg_send((int)$wu['telegram_id'], "🔑 بازیابی رمز عبور پنل SSAEE\n\nلینک (۳۰ دقیقه اعتبار):\n{$url}\n\nاگر این درخواست از طرف شما نبوده، نادیده بگیرید.");
    }

    json_ok([], 'اگر شماره شما متصل به تلگرام باشد، لینک بازیابی ارسال می‌شود');
}

function site_reset_confirm(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    if (!rl_check('pwd_confirm', client_ip(), 10, 600)) json_err('تعداد درخواست‌ها زیاد است', 429);

    $token = req_str($in, 'token');
    $password = req_str($in, 'password');
    if (strlen($token) !== 64) json_err('لینک نامعتبر است');
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        json_err('رمز عبور باید حداقل ۸ کاراکتر و شامل حرف و عدد باشد');
    }

    $pr = db_one(
        "SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1",
        [hash('sha256', $token)]
    );
    if ($pr === null) json_err('لینک بازیابی منقضی یا نامعتبر است');

    db_exec(
        "UPDATE web_users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE phone = ?",
        [password_hash($password, PASSWORD_DEFAULT), $pr['phone']]
    );
    db_exec("UPDATE password_resets SET used = 1 WHERE id = ?", [$pr['id']]);
    json_ok([], 'رمز عبور شما با موفقیت تغییر یافت');
}

function site_bot_selftest(): void
{
    boot_session();
    $admin = $_SESSION['admin'] ?? null;
    if ($admin === null) {
        json_err('فقط مدیر ارشد', 403);
    }
    $roles = array_column(db_all("SELECT role FROM user_roles WHERE user_id = ?", [(int)$admin['id']]), 'role');
    if (!in_array('superadmin', $roles, true)) {
        json_err('فقط مدیر ارشد', 403);
    }
    if (!rl_check('bot_selftest', client_ip(), 3, 300)) {
        json_err('تعداد تست‌ها زیاد است', 429);
    }

    $diag = ['token' => false, 'me' => null, 'webhook_url' => null, 'secret_len' => 0, 'send_test' => null];

    $token = (string)Env::get('TELEGRAM_BOT_TOKEN', '');
    $diag['token'] = $token !== '';
    if ($token === '') {
        json_err('TELEGRAM_BOT_TOKEN تنظیم نشده', 200);
    }

    $me = tg_api('getMe');
    $diag['me'] = ($me['ok'] ?? false) ? ('@' . $me['result']['username']) : null;
    if ($diag['me'] === null) {
        json_err('توکن ربات نامعتبر است', 200);
    }

    $secret = (string)Env::get('TELEGRAM_WEBHOOK_SECRET', '');
    $diag['secret_len'] = strlen($secret);

    $info = tg_api('getWebhookInfo');
    $diag['webhook_url'] = $info['result']['url'] ?? '';
    $diag['last_error'] = $info['result']['last_error_message'] ?? null;
    $diag['pending'] = (int)($info['result']['pending_update_count'] ?? 0);

    $adminChat = (int)Env::get('TELEGRAM_ADMIN_CHAT_ID', '0');
    if ($adminChat > 0) {
        $res = tg_send($adminChat, "🔬 <b>تست داخلی وب‌هوک SSAEE</b>\nزمان: " . now());
        $diag['send_test'] = ($res['ok'] ?? false) ? 'ارسال شد ✓' : ('خطا: ' . ($res['description'] ?? ''));
    } else {
        $diag['send_test'] = 'TELEGRAM_ADMIN_CHAT_ID تنظیم نشده';
    }

    // ===== Round-trip: POST to our own webhook with secret + fake /start =====
    $appUrl = rtrim((string)Env::get('APP_URL', ''), '/');
    $webhookUrl = $appUrl . '/bot/webhook.php';
    $fakeUpdate = json_encode([
        'update_id' => random_int(100000, 999999),
        'message' => [
            'message_id' => 1,
            'from' => ['id' => $adminChat, 'is_bot' => false, 'first_name' => 'SelfTest'],
            'chat' => ['id' => $adminChat, 'type' => 'private'],
            'date' => time(),
            'text' => '/start',
        ],
    ]);

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fakeUpdate,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Telegram-Bot-Api-Secret-Token: ' . $secret,
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $whResponse = curl_exec($ch);
    $whError = curl_error($ch);
    $whCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $diag['roundtrip'] = [
        'http_code' => $whCode,
        'response'  => mb_substr((string)$whResponse, 0, 100),
        'error'     => $whError ?: null,
        'verdict'   => ($whCode === 200 && trim((string)$whResponse) === 'OK') ? 'مسیر ورودی سالم است ✓' : 'مسیر ورودی BLOCKED است!',
    ];

    json_ok(['data' => $diag]);
}
