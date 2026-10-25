<?php
/* ============================================================
   SSAEE Panel — ADMIN
   All admin-panel PHP functions + API dispatcher.
   Entry: admin/index.php (?api=<action>)
   ============================================================ */

/* ============================================================
   Roles / Permissions
   ============================================================ */

const ADMIN_ROLES = [
    'tech_admin'         => 'ادمین فنی',
    'superadmin'         => 'مدیر ارشد',
    'banners_admin'      => 'ادمین بنرها و اطلاعیه‌ها',
    'classes_admin'      => 'ادمین لینک کلاس‌ها',
    'cert_admin'         => 'مدیر گواهینامه‌ها',
    'members_admin'      => 'مدیر اعضا',
    'associations_admin' => 'مدیر روابط عمومی',
    'workshops_admin'    => 'مدیر کارگاه‌ها',
];

/* Board photo upload — superadmin only, strict validation */
function admin_handle_image_upload(string $kind): ?string
{
    if (empty($_FILES['photo_file']) || ($_FILES['photo_file']['error'] ?? 4) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES['photo_file'];
    if ($f['error'] !== UPLOAD_ERR_OK) json_err('خطا در آپلود تصویر (کد ' . $f['error'] . ')');
    if ($f['size'] > 2 * 1024 * 1024) json_err('حجم تصویر بیش از ۲ مگابایت است');

    $info = @getimagesize($f['tmp_name']);
    if ($info === false) json_err('فایل ارسالی تصویر نیست');
    $mime = $info['mime'] ?? '';
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => null,
    };
    if ($ext === null) json_err('فقط JPG / PNG / WebP مجاز است');

    $folder = match ($kind) {
        'board'  => 'board',
        'banner' => 'banners',
        default  => null,
    };
    if ($folder === null) json_err('نوع آپلود نامعتبر است');

    $dir = BASE_PATH . '/assets/' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        json_err('ساخت پوشه assets/' . $folder . ' ناموفق بود — دسترسی پوشه assets را 755 کنید');
    }

    $name = $kind . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        json_err('ذخیره تصویر ناموفق بود — دسترسی نوشتن پوشه assets/' . $folder . ' را بررسی کنید');
    }
    @chmod($dir . '/' . $name, 0644);
    return 'assets/' . $folder . '/' . $name;
}

function admin_role_tables(): array
{
    return [
        'tech_admin' => ['certificates', 'members', 'membersreq', 'workshopreq', 'classreq', 'registrations',
            'banners', 'activities', 'announcements', 'associations', 'companies', 'sponsor',
            'suggestions', 'teachers', 'users', 'audit', 'settings', 'class_links', 'board'],
        'superadmin' => [
            'certificates', 'members', 'membersreq', 'workshopreq', 'classreq', 'registrations',
            'banners', 'activities', 'announcements', 'associations', 'companies', 'sponsor',
            'suggestions', 'teachers', 'users', 'audit', 'settings', 'class_links', 'board',
        ],
        'banners_admin'      => ['banners'],
        'classes_admin'      => ['class_links'],
        'cert_admin'         => ['certificates'],
        'members_admin'      => ['members', 'membersreq'],
        'associations_admin' => ['associations', 'companies', 'sponsor', 'suggestions', 'teachers', 'activities'],
        'workshops_admin'    => ['workshopreq', 'classreq', 'registrations', 'banners', 'activities', 'announcements'],
    ];
}

/* ---------- Multi-role support ---------- */

function admin_roles_of(array $admin): array
{
    static $cache = [];
    $uid = (int)($admin['id'] ?? 0);
    if (!isset($cache[$uid])) {
        $roles = array_column(db_all("SELECT role FROM user_roles WHERE user_id = ?", [$uid]), 'role');
        if (!$roles) {
            $roles = [$admin['role'] ?? 'superadmin'];
        }
        $cache[$uid] = $roles;
    }
    return $cache[$uid];
}

function admin_can(array $admin, string $key): bool
{
    foreach (admin_roles_of($admin) as $role) {
        if ($role === 'superadmin') return true;
        if (in_array($key, admin_role_tables()[$role] ?? [], true)) return true;
    }
    return false;
}

function admin_is_super(array $admin): bool
{
    return in_array('superadmin', admin_roles_of($admin), true);
}

function admin_is_tech(array $admin): bool
{
    return in_array('tech_admin', admin_roles_of($admin), true);
}

/* ============================================================
   API Dispatcher
   ============================================================ */

function admin_api_handle(): void
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
        if ($action === 'login')      { admin_action_login(req_input()); }
        if ($action === '2fa')        { admin_action_2fa(req_input()); }
        if ($action === 'cron')       { admin_action_cron(); return; }

        $admin = admin_require();

        // Auto-logout after 2 hours of inactivity
        if (!isset($_SESSION['admin_last_activity'])) {
            $_SESSION['admin_last_activity'] = time();
        } elseif (time() - (int)$_SESSION['admin_last_activity'] > 7200) {
            admin_do_logout();
            json_err('نشست شما به دلیل عدم فعالیت منقضی شد', 401);
        }
        $_SESSION['admin_last_activity'] = time();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            json_err('درخواست نامعتبر (CSRF). صفحه را رفرش کنید', 403);
        }

        switch ($action) {
            case 'me':
                try {
                    $mustSetup = (int)(db_val("SELECT must_setup FROM users WHERE id = ? LIMIT 1", [(int)$admin['id']]) ?? 0) === 1;
                } catch (Throwable $ex) {
                    $mustSetup = false;
                }
                json_ok(['admin' => admin_public($admin), 'must_setup' => $mustSetup]);
                break;
            case 'logout':        admin_do_logout(); json_ok([], 'خروج انجام شد'); break;
            case 'meta':          admin_meta($admin); break;
            case 'stats':         admin_stats($admin); break;
            case 'list':          admin_action_list($admin); break;
            case 'save':          admin_action_save($admin); break;
            case 'delete':        admin_action_delete($admin); break;
            case 'row_action':    admin_action_row($admin); break;
            case 'broadcast':     admin_action_broadcast($admin); break;
            case 'upload':        admin_action_upload($admin); break;
            case 'certtpl':       admin_action_cert_template($admin); break;
            case 'cardtpl':       admin_action_card_template($admin); break;
            case 'cardforws':     admin_action_card_for_member($admin); break;
                        case 'snapshots':     admin_action_snapshots($admin); break;
                        case 'bulkimport':    admin_action_bulk_import($admin); break;
            case 'certforws':     admin_action_cert_for_workshop($admin); break;
            case 'send_survey':   admin_action_send_survey($admin); break;
            case 'sessions':      admin_action_sessions($admin); break;
            case 'cron':          admin_action_cron(); break;
            case 'trash':         admin_action_snapshots($admin); break;
            case 'tech_status':   admin_tech_status($admin); break;
            case 'autopoints':    admin_action_autopoints($admin); break;
            case 'helpcontent':   admin_action_help_content($admin); break;
            case 'errorcodes':    admin_error_codes($admin); break;
            case 'errorlogs':     admin_error_logs($admin); break;
            case 'settings_get':  admin_settings_get(); break;
            case 'setup_profile': admin_action_setup_profile($admin); break;
            case 'settings_save': admin_settings_save($admin); break;
            default:              json_err('عملیات نامعتبر: ' . $action . ' (فایل admin.php را آپلود کنید)', 404);
        }
    } catch (Throwable $ex) {
        error_log('[admin:' . $action . '] ' . $ex->getMessage() . ' | ' . $ex->getFile() . ':' . $ex->getLine());
        $msg = 'خطای داخلی سرور';
        if ($ex instanceof PDOException) {
            $msg = 'خطای دیتابیس: ' . mb_substr($ex->getMessage(), 0, 200);
        } else {
            $msg = 'خطا: ' . mb_substr($ex->getMessage(), 0, 200) . ' (' . basename($ex->getFile()) . ':' . $ex->getLine() . ')';
        }
        json_err($msg, 500);
    }
}

function admin_public(array $admin): array
{
    $roles = admin_roles_of($admin);
    $labels = array_map(fn($r) => ADMIN_ROLES[$r] ?? $r, $roles);

    return [
        'id'         => (int)$admin['id'],
        'username'   => $admin['username'],
        'full_name'  => $admin['full_name'],
        'roles'      => $roles,
        'role'       => $roles[0] ?? $admin['role'],
        'role_label' => implode(' + ', $labels),
    ];
}

function admin_current(): ?array
{
    boot_session();
    return $_SESSION['admin'] ?? null;
}

function admin_require(): array
{
    $admin = admin_current();
    if ($admin === null) json_err('احراز هویت لازم است', 401);
    return $admin;
}

function admin_do_logout(): void
{
    $admin = admin_current();
    if ($admin !== null) audit_log((int)$admin['id'], 'logout', 'admin', (string)$admin['id']);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function admin_establish(array $user): void
{
    $_SESSION['admin'] = [
        'id'         => (int)$user['id'],
        'username'   => $user['username'],
        'full_name'  => $user['full_name'],
        'role'       => $user['role'],
        'login_time' => time(),
    ];
    db_exec("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
    audit_log((int)$user['id'], 'login', 'admin', (string)$user['id'], ['ip' => client_ip()]);
}

/* ============================================================
   Login / 2FA
   ============================================================ */

function admin_action_login(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    if (!csrf_verify(req_str($in, 'csrf_token') ?: ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
        json_err('درخواست نامعتبر (CSRF)', 403);
    }

    $username = req_str($in, 'username');
    $password = req_str($in, 'password');
    $ip = client_ip();
    $maxAttempts = Env::int('LOGIN_MAX_ATTEMPTS', 5);
    $lockout = Env::int('LOGIN_LOCKOUT_MINUTES', 15);

    $failed = (int)db_val(
        "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
        [$ip, $lockout]
    );
    if ($failed >= $maxAttempts) {
        json_err("به دلیل تلاش‌های ناموفق زیاد، ورود موقتاً مسدود است. {$lockout} دقیقه بعد تلاش کنید", 429);
    }

    $user = db_one("SELECT * FROM users WHERE username = ? LIMIT 1", [$username]);
    if ($user === null || !password_verify($password, $user['password'])) {
        db_exec("INSERT INTO login_attempts (ip, username, success) VALUES (?, ?, 0)", [$ip, $username]);
        json_err('نام کاربری یا رمز عبور اشتباه است', 401);
    }
    if (!(int)$user['is_active']) {
        db_exec("INSERT INTO login_attempts (ip, username, success) VALUES (?, ?, 0)", [$ip, $username]);
        json_err('حساب شما فعال نیست. با مدیر ارشد تماس بگیرید', 403);
    }
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        db_exec("UPDATE users SET password = ? WHERE id = ?", [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    $twoFa = Env::bool('ADMIN_2FA_ENABLED', true) && !empty($user['telegram_id']) && (string)Env::get('TELEGRAM_BOT_TOKEN', '') !== '';

    if ($twoFa) {
        $code = (string)random_int(100000, 999999);
        boot_session();
        $_SESSION['admin_2fa'] = [
            'user_id'   => (int)$user['id'],
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires'   => time() + Env::int('ADMIN_2FA_TTL', 300),
            'attempts'  => 0,
        ];
        tg_send(
            (int)$user['telegram_id'],
            "🔐 <b>کد ورود پنل مدیریت SSAEE</b>\n\nکد: <code>{$code}</code>\nIP: <code>" . client_ip() . "</code>\nزمان: " . now() . "\n\nاگر این شما نبودید فوراً رمز خود را تغییر دهید."
        );
        json_ok(['step' => '2fa'], 'کد تأیید به تلگرام شما ارسال شد');
    }

    boot_session();
    session_regenerate_id(true);
    admin_establish($user);
    json_ok(['step' => 'done'], 'ورود موفق');
}

function admin_action_2fa(array $in): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('متد مجاز نیست', 405);
    if (!csrf_verify(req_str($in, 'csrf_token') ?: ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
        json_err('درخواست نامعتبر (CSRF)', 403);
    }

    boot_session();
    if (empty($_SESSION['admin_2fa'])) json_err('جلسه ورود یافت نشد. دوباره وارد شوید');

    $state = $_SESSION['admin_2fa'];
    if (time() > (int)$state['expires']) {
        unset($_SESSION['admin_2fa']);
        json_err('کد منقضی شده است. دوباره وارد شوید');
    }
    if ((int)$state['attempts'] >= 3) {
        unset($_SESSION['admin_2fa']);
        json_err('تعداد تلاش بیش از حد مجاز. دوباره وارد شوید');
    }

    $state['attempts']++;
    $_SESSION['admin_2fa'] = $state;

    if (!password_verify(req_str($in, 'code'), $state['code_hash'])) {
        json_err('کد اشتباه است');
    }

    $user = db_one("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1", [$state['user_id']]);
    if ($user === null) {
        unset($_SESSION['admin_2fa']);
        json_err('کاربر یافت نشد');
    }

    unset($_SESSION['admin_2fa']);
    session_regenerate_id(true);
    admin_establish($user);
    json_ok(['step' => 'done'], 'ورود موفق');
}

/* ============================================================
   Entity Registry (config-driven CRUD)
   Field: [name, label, type, required, options?]
   Types: text, textarea, date, int, float, select, password
   ============================================================ */

function admin_entities(): array
{
    return [
        'certificates' => [
            'title' => 'گواهینامه‌ها', 'icon' => 'award', 'perm' => 'certificates',
            'table' => 'certificates', 'id' => 'ID', 'auto_id' => ['ID', 'CRT'],
            'columns' => ['ID' => 'کد', 'NameFa' => 'نام', 'NameEn' => 'EN', 'Course' => 'دوره', 'CourseEn' => 'EN دوره', 'CertType' => 'نوع گواهی', 'IssueDate' => 'صدور', 'ExpDate' => 'انقضا'],
            'search' => ['ID', 'NameFa', 'NameEn', 'NationalID', 'Course', 'CourseEn', 'CertType'],
            'order' => 'IssueDate DESC, ID DESC',
            'fields' => [
                ['ID', 'کد (خالی = خودکار)', 'text', false],
                ['NameFa', 'نام (فارسی)', 'text', true],
                ['NameEn', 'نام (انگلیسی) — روی گواهی چاپ می‌شود', 'text', true],
                ['NationalID', 'کد ملی', 'text', false],
                ['Course', 'دوره (فارسی)', 'text', true],
                ['CourseEn', 'دوره (انگلیسی) — روی گواهی چاپ می‌شود', 'text', true],
                ['CertType', 'نوع گواهی (متن انگلیسی روی قالب)', 'text', false],
                ['IssueDate', 'تاریخ صدور', 'date', true],
                ['ExpDate', 'تاریخ انقضا (خالی = دائمی)', 'date', false],
            ],
            'on_add' => 'admin_notify_certificate',
            'row_actions' => [
                ['key' => 'pdf', 'label' => 'پیش‌نمایش PDF', 'icon' => 'file-search'],
            ],
        ],

        'members' => [
            'title' => 'اعضا', 'icon' => 'users', 'perm' => 'members',
            'table' => 'members', 'id' => 'ID',
            'columns' => ['ID' => '#', 'NameFa' => 'نام', 'NationalID' => 'کد ملی', 'StudentID' => 'دانشجویی', 'Phone' => 'موبایل', 'Points' => 'امتیاز', 'Active' => 'نوع عضویت', 'ExpDate' => 'انقضا'],
            'search' => ['NameFa', 'NationalID', 'StudentID', 'Phone'],
            'order' => 'ID DESC',
            'fields' => [
                ['NameFa', 'نام و نام خانوادگی', 'text', true],
                ['NationalID', 'کد ملی', 'text', true],
                ['StudentID', 'شماره دانشجویی', 'text', false],
                ['Phone', 'موبایل', 'text', false],
                ['Branch', 'گرایش', 'text', false],
                ['Points', 'امتیاز', 'float', false],
                ['Active', 'نوع عضویت', 'select', false, ['1' => 'فعال (دارای کارت)', '0' => 'عادی']],
                ['IssueDate', 'تاریخ عضویت', 'date', false],
                ['ExpDate', 'تاریخ انقضا', 'date', false],
                ['FileLink', 'لینک کارت عضویت', 'text', false],
            ],
            'row_actions' => [
                ['key' => 'points', 'label' => '+۵ امتیاز', 'icon' => 'plus'],
                ['key' => 'toggle', 'label' => 'فعال/غیرفعال', 'icon' => 'power'],
            ],
        ],

        'membersreq' => [
            'title' => 'درخواست‌های عضویت', 'icon' => 'user-plus', 'perm' => 'membersreq',
            'table' => 'membersreq', 'id' => 'ID',
            'columns' => ['ID' => 'کد', 'Date' => 'تاریخ', 'Name' => 'نام', 'NationalID' => 'کد ملی', 'StudentID' => 'دانشجویی', 'Phone' => 'موبایل', 'Status' => 'وضعیت'],
            'search' => ['Name', 'NationalID', 'StudentID', 'Phone'],
            'order' => 'ID DESC',
            'readonly' => true,
            'row_actions' => [
                ['key' => 'approve_req', 'label' => 'تأیید', 'icon' => 'check', 'when' => ['Status', 'NEW']],
                ['key' => 'reject_req', 'label' => 'رد', 'icon' => 'x', 'when' => ['Status', 'NEW'], 'danger' => true],
            ],
        ],

        'workshopreq' => [
            'title' => 'درخواست کارگاه', 'icon' => 'graduation-cap', 'perm' => 'workshopreq',
            'table' => 'workshopreq', 'id' => 'ID',
            'columns' => ['ID' => '#', 'Date' => 'تاریخ', 'Time' => 'ساعت', 'Course' => 'دوره', 'Teacher' => 'مدرس', 'Phone' => 'موبایل', 'Status' => 'وضعیت'],
            'search' => ['Course', 'Teacher', 'Phone'],
            'order' => 'Date DESC, ID DESC',
            'fields' => [
                ['Date', 'تاریخ', 'date', true],
                ['Time', 'ساعت', 'text', true],
                ['Course', 'عنوان دوره', 'text', true],
                ['Teacher', 'مدرس', 'text', true],
                ['Phone', 'موبایل', 'text', false],
                ['Status', 'وضعیت', 'select', false, ['NEW' => 'جدید', 'در انتظار' => 'در انتظار', 'تکمیل شده' => 'تکمیل شده']],
            ],
        ],

        'classreq' => [
            'title' => 'کلاس حل تمرین', 'icon' => 'book-open', 'perm' => 'classreq',
            'table' => 'classreq', 'id' => 'ID',
            'columns' => ['ID' => '#', 'Date' => 'تاریخ', 'Time' => 'ساعت', 'Course' => 'درس', 'Teacher' => 'استاد', 'Phone' => 'موبایل', 'Status' => 'وضعیت'],
            'search' => ['Course', 'Teacher', 'Phone'],
            'order' => 'Date DESC, ID DESC',
            'fields' => [
                ['Date', 'تاریخ', 'date', true],
                ['Time', 'ساعت', 'text', true],
                ['Course', 'عنوان درس', 'text', true],
                ['Teacher', 'استاد', 'text', true],
                ['Phone', 'موبایل', 'text', false],
                ['Status', 'وضعیت', 'select', false, ['NEW' => 'جدید', 'در انتظار' => 'در انتظار', 'تکمیل شده' => 'تکمیل شده']],
            ],
        ],

        'registrations' => [
            'title' => 'ثبت‌نام کارگاه', 'icon' => 'clipboard-list', 'perm' => 'registrations',
            'table' => 'workshop_registrations', 'id' => 'ID',
            'columns' => ['ID' => 'کد', 'Date' => 'تاریخ', 'FullName' => 'نام', 'Phone' => 'موبایل', 'StudentID' => 'دانشجویی', 'University' => 'دانشگاه', 'WorkshopType' => 'کارگاه'],
            'search' => ['FullName', 'Phone', 'StudentID', 'ID'],
            'order' => 'Date DESC',
            'fields' => [
                ['FullName', 'نام کامل', 'text', true],
                ['Phone', 'موبایل', 'text', true],
                ['StudentID', 'شماره دانشجویی', 'text', false],
                ['University', 'دانشگاه (غیر علوم تحقیقات)', 'text', false],
                ['Field', 'رشته', 'text', false],
                ['WorkshopType', 'نوع/کد کارگاه', 'text', false],
                
                ['GroupLink', 'لینک گروه', 'text', false],
            ],
        ],

        'banners' => [
            'title' => 'لیست کارگاه‌ها', 'icon' => 'image', 'perm' => 'banners',
            'table' => 'banners', 'id' => 'ID', 'auto_id' => ['ID', 'BAN'],
            'columns' => ['ID' => 'کد', 'Title' => 'عنوان کارگاه', 'TargetTag' => 'کد کارگاه', '_reg_status' => 'وضعیت ثبت‌نام', '_ws_start' => 'شروع کارگاه (جلسه اول)', '_sessions' => 'جلسات', 'ClickCount' => 'کلیک'],
            'order' => 'Priority DESC, CreatedAt DESC',
            'fields' => [
                ['Title', 'نام کارگاه', 'text', true],
                ['TargetTag', 'کد کوتاه کارگاه (۱ تا ۵ حرف انگلیسی)', 'text', true],
                ['ImageURL', 'تصویر بنر', 'image_upload', false],
                ['LinkURL', 'لینک مقصد (اختیاری — خالی = فرم ثبت‌نام)', 'text', false],
                ['GroupLink', 'لینک گروه کارگاه (ارسال خودکار به ثبت‌نامی‌ها)', 'text', false],
                ['StartDate', 'تاریخ شروع ثبت‌نام (نمایش بنر)', 'date', false],
                ['EndDate', 'تاریخ پایان ثبت‌نام (مخفی شدن بنر)', 'date', false],
                ['IsActive', 'فعال', 'select', false, ['1' => 'فعال', '0' => 'غیرفعال']],
                ['Priority', 'اولویت نمایش', 'select', false, ['3' => 'متوسط', '5' => 'زیاد', '1' => 'پایین']],
                ['notify', 'اطلاع‌رسانی شروع ثبت‌نام به کاربران', 'select', false, ['0' => 'خیر', '1' => 'بله (فقط هنگام افزودن)']],
            ],
        ],

        'activities' => [
            'title' => 'فعالیت‌ها', 'icon' => 'activity', 'perm' => 'activities',
            'table' => 'activities', 'id' => 'id',
            'columns' => ['id' => '#', 'title' => 'تیتر', 'act_date' => 'تاریخ', 'description' => 'توضیح'],
            'search' => ['title', 'description'],
            'order' => 'act_date DESC, id DESC',
            'fields' => [
                ['title', 'تیتر فعالیت', 'text', true],
                ['act_date', 'تاریخ', 'date', true],
                ['description', 'توضیح', 'textarea', false],
                ['image_url', 'آدرس تصویر (https://)', 'text', false],
            ],
        ],

        'associations' => [
            'title' => 'انجمن‌ها', 'icon' => 'building-2', 'perm' => 'associations',
            'table' => 'associations', 'id' => 'ID', 'auto_id' => ['ID', 'ASC'],
            'columns' => ['ID' => 'کد', 'Date' => 'تاریخ', 'Name' => 'نام', 'University' => 'دانشگاه', 'Phone' => 'تماس', 'Status' => 'وضعیت'],
            'search' => ['Name', 'University', 'Phone'],
            'order' => 'ID DESC',
            'fields' => [
                ['Name', 'نام انجمن', 'text', true],
                ['University', 'دانشگاه', 'text', false],
                ['Field', 'رشته', 'text', false],
                ['Course', 'دوره', 'text', false],
                ['Phone', 'تلفن', 'text', false],
                ['Status', 'وضعیت', 'select', false, ['NEW' => 'جدید', 'فعال' => 'فعال', 'غیرفعال' => 'غیرفعال']],
            ],
        ],

        'teachers' => [
            'title' => 'اساتید', 'icon' => 'person-standing', 'perm' => 'teachers',
            'table' => 'teachers', 'id' => 'ID', 'auto_id' => ['ID', 'TCH'],
            'columns' => ['ID' => 'کد', 'Date' => 'تاریخ', 'Name' => 'نام', 'University' => 'دانشگاه', 'AcRank' => 'مرتبه', 'Phone' => 'تماس', 'Status' => 'وضعیت'],
            'search' => ['Name', 'University', 'Phone'],
            'order' => 'ID DESC',
            'fields' => [
                ['Name', 'نام', 'text', true],
                ['University', 'دانشگاه', 'text', false],
                ['AcRank', 'مرتبه علمی', 'text', false],
                ['Course', 'درس/تخصص', 'text', false],
                ['Phone', 'تلفن', 'text', false],
                ['Status', 'وضعیت', 'select', false, ['NEW' => 'جدید', 'فعال' => 'فعال', 'غیرفعال' => 'غیرفعال']],
            ],
        ],

        'companies' => [
            'title' => 'شرکت‌ها', 'icon' => 'briefcase', 'perm' => 'companies',
            'table' => 'companies', 'id' => 'ID', 'auto_id' => ['ID', 'COM'],
            'columns' => ['ID' => 'کد', 'Date' => 'تاریخ', 'Name' => 'شرکت', 'Phone' => 'تماس', 'Status' => 'وضعیت'],
            'search' => ['Name', 'Phone'],
            'order' => 'ID DESC',
            'fields' => [
                ['Name', 'نام شرکت', 'text', true],
                ['Phone', 'تلفن', 'text', false],
                ['Status', 'وضعیت', 'select', false, ['NEW' => 'جدید', 'فعال' => 'فعال', 'غیرفعال' => 'غیرفعال']],
                ['Note', 'توضیحات', 'textarea', false],
            ],
        ],

        'sponsor' => [
            'title' => 'اسپانسرها', 'icon' => 'gem', 'perm' => 'sponsor',
            'table' => 'sponsor', 'id' => 'ID', 'auto_id' => ['ID', 'SPN'],
            'columns' => ['ID' => 'کد', 'Date' => 'تاریخ', 'Brand' => 'برند', 'Type' => 'نوع', 'Money' => 'مبلغ', 'Phone' => 'تماس'],
            'search' => ['Brand', 'Phone'],
            'order' => 'ID DESC',
            'fields' => [
                ['Brand', 'برند', 'text', true],
                ['Interface', 'رابط', 'text', false],
                ['Type', 'نوع همکاری', 'text', false],
                ['Money', 'مبلغ (تومان)', 'float', false],
                ['Email', 'ایمیل', 'text', false],
                ['Phone', 'تلفن', 'text', false],
                ['Telegram', 'تلگرام', 'text', false],
                ['Website', 'وب‌سایت', 'text', false],
                ['Details', 'جزئیات', 'textarea', false],
                ['Note', 'یادداشت', 'textarea', false],
            ],
        ],

        'suggestions' => [
            'title' => 'پیام‌ها', 'icon' => 'message-square', 'perm' => 'suggestions',
            'table' => 'suggestions', 'id' => 'ID',
            'columns' => ['ID' => '#', 'Phone' => 'موبایل', 'Unit' => 'واحد', 'Subject' => 'موضوع', 'Note' => 'متن'],
            'search' => ['Phone', 'Subject'],
            'order' => 'ID DESC',
            'fields' => [
                ['Phone', 'موبایل', 'text', true],
                ['Unit', 'واحد', 'text', false],
                ['Subject', 'موضوع', 'text', false],
                ['Note', 'متن', 'textarea', false],
            ],
        ],

        'announcements' => [
            'title' => 'اطلاعیه‌های سایت', 'icon' => 'megaphone', 'perm' => 'announcements',
            'table' => 'announcements', 'id' => 'id',
            'columns' => ['id' => '#', 'title' => 'عنوان', 'type' => 'نوع', 'is_active' => 'فعال', 'start_date' => 'شروع', 'end_date' => 'پایان', '_board_status' => 'وضعیت'],
            'search' => ['title', 'message'],
            'order' => 'created_at DESC',
            'fields' => [
                ['title', 'عنوان', 'text', true],
                ['message', 'متن', 'textarea', true],
                ['type', 'نوع', 'select', false, ['info' => 'اطلاع', 'warning' => 'هشدار', 'danger' => 'مهم', 'success' => 'موفق']],
                ['is_active', 'فعال', 'select', false, ['1' => 'فعال', '0' => 'غیرفعال']],
                ['start_date', 'تاریخ شروع', 'date', false],
                ['end_date', 'تاریخ پایان', 'date', false],
            ],
        ],

        'users' => [
            'title' => 'مدیران', 'icon' => 'shield', 'perm' => 'users',
            'table' => 'users', 'id' => 'id',
            'columns' => ['id' => '#', 'username' => 'نام کاربری', 'full_name' => 'نام', 'roles_label' => 'نقش‌ها', 'telegram_id' => 'تلگرام', 'is_active' => 'فعال', 'last_login' => 'آخرین ورود'],
            'search' => ['username', 'full_name'],
            'order' => 'id ASC',
            'fields' => [
                ['username', 'نام کاربری (انگلیسی)', 'text', true],
                ['full_name', 'نام کامل', 'text', true],
                ['password', 'رمز عبور (خالی = بدون تغییر)', 'password', false],
                ['roles', 'نقش‌های دسترسی (چند انتخاب)', 'roles_multi', true],
                ['telegram_id', 'تلگرام ID (برای 2FA) — بازیابی رمز عبور مدیران فقط از طریق پشتیبانی انجام می‌شود', 'text', false],
                ['is_active', 'فعال', 'select', false, ['1' => 'فعال', '0' => 'غیرفعال']],
            ],
        ],

        'audit' => [
            'title' => 'لاگ فعالیت', 'icon' => 'scroll-text', 'perm' => 'audit',
            'table' => 'audit_log a LEFT JOIN users u ON u.id = a.admin_id', 'id' => 'a.id',
            'select' => 'a.*, u.username AS admin_username',
            'columns' => ['id' => '#', 'admin_username' => 'مدیر', 'action' => 'عملیات', 'entity' => 'موجودیت', 'entity_id' => 'شناسه', 'ip' => 'IP', 'created_at' => 'زمان'],
            'search' => ['a.action', 'a.entity', 'a.entity_id', 'u.username'],
            'order' => 'a.id DESC',
            'readonly' => true,
        ],

        'bot_admins' => [
            'title' => 'ادمین‌های ربات', 'icon' => 'bot', 'perm' => 'users',
            'table' => 'bot_admins', 'id' => 'id',
            'columns' => ['id' => '#', 'telegram_id' => 'آیدی تلگرام', 'role' => 'نقش', 'is_active' => 'فعال', 'created_at' => 'تاریخ ایجاد'],
            'search' => ['telegram_id'],
            'order' => 'id ASC',
            'fields' => [
                ['telegram_id', 'آیدی عددی تلگرام', 'text', true],
                ['role', 'نقش در ربات', 'select', false, ['support' => 'پشتیبان (پاسخ به تیکت‌ها)', 'admin' => 'ادمین']],
                ['is_active', 'فعال', 'select', false, ['1' => 'فعال', '0' => 'غیرفعال']],
            ],
            'on_add' => 'admin_notify_bot_admin',
        ],

        'class_links' => [
            'title' => 'لینک کلاس‌ها', 'icon' => 'book-copy', 'perm' => 'class_links',
            'table' => 'class_links', 'id' => 'id',
            'columns' => ['id' => '#', 'course_name' => 'نام درس', 'teacher' => 'استاد', 'class_day' => 'روز', 'class_time' => 'ساعت', 'is_active' => 'فعال'],
            'search' => ['course_name', 'teacher'],
            'order' => 'course_name ASC, id ASC',
            'fields' => [
                ['course_name', 'نام درس', 'text', true],
                ['teacher', 'استاد', 'text', true],
                ['class_day', 'روز کلاس (مثلاً شنبه)', 'text', false],
                ['class_time', 'ساعت کلاس (مثلاً ۱۰:۳۰)', 'text', false],
                ['link', 'لینک گروه (https://t.me/...)', 'text', true],
                ['is_active', 'فعال', 'select', false, ['1' => 'فعال', '0' => 'غیرفعال']],
            ],
        ],

        'board_members' => [
            'title' => 'هیئت مدیره', 'icon' => 'users-round', 'perm' => 'board',
            'table' => 'board_members', 'id' => 'id',
            'columns' => ['id' => '#', 'photo' => 'عکس', 'name' => 'نام', 'name_en' => 'EN', 'position' => 'بج', 'term' => 'دوره', 'start_date' => 'شروع', 'end_date' => 'پایان', '_board_status' => 'وضعیت'],
            'search' => ['name', 'name_en', 'position', 'term'],
            'order' => 'sort ASC, id ASC',
            'fields' => [
                ['name', 'نام و نام خانوادگی', 'text', true],
                ['name_en', 'نام انگلیسی (برای گواهی‌نامه‌ها)', 'text', false],
                ['position', 'بج', 'select', false, board_positions()],
                ['term', 'دوره (مثلاً ۱۴۰۳-۱۴۰۴)', 'text', true],
                ['telegram', 'تلگرام (@username یا لینک)', 'text', false],
                ['linkedin', 'لینکدین (لینک کامل)', 'text', false],
                ['photo', 'تصویر عضو', 'image_upload', false],
                ['signature', 'تصویر امضا / مهر (برای گواهی و کارت عضویت)', 'image_upload', false],
                ['sort', 'ترتیب نمایش', 'int', false],
            ],
        ],
    ];
}

function admin_sections(array $admin): array
{
    $sections = [
        ['key' => 'dashboard',     'title' => 'داشبورد',           'icon' => 'layout-dashboard', 'type' => 'dashboard'],
        ['key' => 'certificates',  'title' => 'گواهینامه‌ها',       'icon' => 'award',            'entities' => ['certificates']],
        ['key' => 'members',       'title' => 'اعضا',              'icon' => 'users',            'entities' => ['members', 'membersreq']],
        ['key' => 'cardtpl',       'title' => 'قالب کارت عضویت',  'icon' => 'id-card',          'type' => 'cardtpl'],        ['key' => 'requests',      'title' => 'کارگاه و کلاس',     'icon' => 'graduation-cap',   'entities' => ['workshopreq', 'classreq']],
        ['key' => 'registrations', 'title' => 'ثبت‌نام کارگاه',    'icon' => 'clipboard-list',   'entities' => ['registrations']],
        ['key' => 'banners',       'title' => 'لیست کارگاه‌ها',     'icon' => 'image',            'entities' => ['banners']],
        ['key' => 'classes',       'title' => 'لینک کلاس‌ها',      'icon' => 'book-copy',        'entities' => ['class_links']],
        ['key' => 'board',         'title' => 'هیئت مدیره',        'icon' => 'users-round',      'entities' => ['board_members']],
        ['key' => 'activities',    'title' => 'فعالیت‌ها',         'icon' => 'activity',         'entities' => ['activities']],
        ['key' => 'collab',        'title' => 'روابط عمومی',       'icon' => 'handshake',        'entities' => ['associations', 'teachers', 'companies', 'sponsor', 'suggestions']],
        ['key' => 'announce',      'title' => 'اطلاع‌رسانی',       'icon' => 'bell',             'entities' => ['announcements'], 'broadcast' => true],
        ['key' => 'bot',           'title' => 'ربات تلگرام',       'icon' => 'bot',              'entities' => ['bot_admins']],
        ['key' => 'settings',      'title' => 'تنظیمات',           'icon' => 'settings',         'type' => 'settings'],
        ['key' => 'certtpl',       'title' => 'قالب گواهینامه',   'icon' => 'image-up',        'type' => 'certtpl'],
        ['key' => 'errcodes',      'title' => 'خطاها',             'icon' => 'alert-triangle',   'type' => 'errorcodes'],
        ['key' => 'users',         'title' => 'مدیران',            'icon' => 'shield',           'entities' => ['users']],
        ['key' => 'audit',         'title' => 'لاگ فعالیت',        'icon' => 'scroll-text',      'entities' => ['audit']],
        ['key' => 'helpcontent',   'title' => 'متن راهنمای سایت',  'icon' => 'circle-help',      'type' => 'helpeditor'],
        ['key' => 'trash',         'title' => 'سبد بازیافت',       'icon' => 'trash-2',          'type' => 'trash'],
    ];

    $out = [];
    foreach ($sections as $sec) {
        if (!empty($sec['entities'])) {
            $visible = array_filter($sec['entities'], fn($e) => admin_can($admin, admin_entities()[$e]['perm']));
            if (!$visible) continue;
            $sec['entities'] = array_values($visible);
        } elseif (($sec['type'] ?? '') === 'cardtpl') {
            if (!admin_is_tech($admin)) continue;
        } elseif (($sec['type'] ?? '') === 'helpeditor') {
            if (!admin_is_tech($admin)) continue;
        } elseif (($sec['type'] ?? '') === 'trash') {
            if (!admin_is_tech($admin)) continue;
        } elseif (($sec['type'] ?? '') === 'errorcodes') {
            if (!admin_is_tech($admin)) continue;
        } elseif (($sec['type'] ?? '') === 'settings') {
            if (!admin_can($admin, 'settings')) continue;
        }
        $out[] = $sec;
    }
    return $out;
}

function admin_meta(array $admin): void
{
    try {
        $all = admin_entities();
    } catch (Throwable $ex) {
        // e.g. bootstrap.php not updated (board_positions() missing) — don't 500 the panel
        error_log('[admin_meta.entities] ' . $ex->getMessage());
        $all = [];
    }
    $out = [];
    foreach ($all as $key => $cfg) {
        try {
            if (!admin_can($admin, $cfg['perm'])) continue;
            $out[$key] = [
                'title'       => $cfg['title'],
                'icon'        => $cfg['icon'],
                'id'          => $cfg['id'],
                'columns'     => $cfg['columns'],
                'fields'      => !empty($cfg['readonly']) ? [] : ($cfg['fields'] ?? []),
                'search'      => $cfg['search'] ?? [],
                'readonly'    => (bool)($cfg['readonly'] ?? false),
                'row_actions' => $cfg['row_actions'] ?? [],
            ];
        } catch (Throwable $ex) {
            error_log('[admin_meta.entity:' . $key . '] ' . $ex->getMessage());
        }
    }

    // Optional extras — must never break /api=meta (missing columns, etc.)
    $certUntranslated = 0;
    $boardPositions = [];
    try {
        $boardPositions = array_keys(board_positions());
    } catch (Throwable $ex) { $boardPositions = []; }
    if (admin_can($admin, 'certificates')) {
        try {
            cert_schema_ready();
            $cols = array_column(db_all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificates'"), 'COLUMN_NAME');
            if ($cols && in_array('CourseEn', $cols, true)) {
                $certUntranslated = (int)db_val("SELECT COUNT(*) FROM certificates WHERE (NameEn IS NULL OR NameEn = '') OR (CourseEn IS NULL OR CourseEn = '')");
            } elseif ($cols) {
                $certUntranslated = (int)db_val("SELECT COUNT(*) FROM certificates WHERE (NameEn IS NULL OR NameEn = '')");
            }
        } catch (Throwable $ex) {
            error_log('[admin_meta.count] ' . $ex->getMessage());
            $certUntranslated = 0;
        }
    }

    $sections = [];
    try {
        $sections = admin_sections($admin);
    } catch (Throwable $ex) {
        error_log('[admin_meta.sections] ' . $ex->getMessage());
        $sections = [['key' => 'dashboard', 'title' => 'داشبورد', 'icon' => 'layout-dashboard', 'type' => 'dashboard']];
    }

    json_ok(['data' => [
        'entities'          => $out,
        'sections'          => $sections,
        'board_positions'   => $boardPositions,
        'cert_untranslated' => $certUntranslated,
    ]]);
}

/* ============================================================
   Generic CRUD
   ============================================================ */

function admin_action_list(array $admin): void
{
    $key = req_str($_GET, 'entity');
    $cfg = admin_entities()[$key] ?? null;
    if ($cfg === null || !admin_can($admin, $cfg['perm'])) json_err('دسترسی غیرمجاز', 403);
    if ($key === 'certificates') cert_schema_ready();

    [$page, $perPage, $offset] = admin_pagination();
    $q = req_str($_GET, 's');
    $filter = req_str($_GET, 'filter');
    $filterCol = req_str($_GET, 'filter_col');

    $where = '';
    $params = [];
    if ($q !== '' && !empty($cfg['search'])) {
        $conds = [];
        foreach ($cfg['search'] as $col) {
            $conds[] = "{$col} LIKE ?";
            $params[] = "%{$q}%";
        }
        $where = '(' . implode(' OR ', $conds) . ')';
    }
    $allowedCols = array_unique(array_merge(
        array_keys($cfg['columns'] ?? []),
        $cfg['search'] ?? [],
        isset($cfg['id']) ? [(string)$cfg['id']] : []
    ));
    if ($filter !== '' && $filterCol !== '' && in_array($filterCol, $allowedCols, true) && preg_match('/^[A-Za-z_]+$/', $filterCol) && preg_match('/^[\w\x{0600}-\x{06FF} ]+$/u', $filter)) {
        $where = trim(($where ? "({$where}) AND " : '') . "`{$filterCol}` = ?");
        $params[] = $filter;
    }
    $whereSql = $where !== '' ? " WHERE {$where}" : '';

    $select = $cfg['select'] ?? '*';
    $total = (int)db_val("SELECT COUNT(*) FROM {$cfg['table']}{$whereSql}", $params);
    $rows = db_all("SELECT {$select} FROM {$cfg['table']}{$whereSql} ORDER BY {$cfg['order']} LIMIT {$perPage} OFFSET {$offset}", $params);

    // Multi-role labels for the users entity
    if ($key === 'users' && $rows) {
        $ids = array_map('intval', array_column($rows, 'id'));
        $inIds = implode(',', $ids);
        $roleMap = [];
        foreach (db_all("SELECT user_id, role FROM user_roles WHERE user_id IN ({$inIds})") as $r) {
            $roleMap[(int)$r['user_id']][] = $r['role'];
        }
        foreach ($rows as &$row) {
            $uid = (int)$row['id'];
            $roles = $roleMap[$uid] ?? [$row['role']];
            $row['roles_label'] = implode(' + ', array_map(fn($r) => ADMIN_ROLES[$r] ?? $r, $roles));
            $row['roles'] = $roles;
        }
        unset($row);
    }

    // Banner countdown badges + live GroupLink + workshop session columns
    if ($key === 'banners' && $rows) {
        foreach ($rows as &$row) {
            $row['_countdown'] = admin_banner_countdown($row['StartDate'] ?? null, $row['EndDate'] ?? null, $row['IsActive'] ?? 0);
        $row['_timestatus'] = admin_banner_time_status($row['StartDate'] ?? null, $row['EndDate'] ?? null);
        }
        unset($row);

        // Merge workshops.GroupLink so the EDIT modal prefills the field
        // (otherwise every edit would show it empty and saving would wipe it)
        $tags = array_values(array_unique(array_filter(array_column($rows, 'TargetTag'))));
        $linkMap = []; $sessMap = [];
        if ($tags) {
            $ph = implode(',', array_fill(0, count($tags), '?'));
            $linkMap = [];
            foreach (db_all("SELECT Tag, GroupLink FROM workshops WHERE Tag IN ({$ph})", $tags) as $w) {
                $linkMap[$w['Tag']] = (string)($w['GroupLink'] ?? '');
            }
            // First session + session count per workshop (tasks 5/6/7)
            foreach (db_all(
                "SELECT workshop_tag, COUNT(*) AS c, MIN(CONCAT(session_date, ' ', session_time)) AS first_session
                 FROM workshop_sessions WHERE workshop_tag IN ({$ph}) GROUP BY workshop_tag",
                $tags
            ) as $wss) {
                $sessMap[$wss['workshop_tag']] = ['count' => (int)$wss['c'], 'first' => $wss['first_session']];
            }
        }

        foreach ($rows as &$row) {
            $row['GroupLink'] = $linkMap[$row['TargetTag']] ?? '';

            $tag = (string)($row['TargetTag'] ?? '');
            $ss = $sessMap[$tag] ?? null;
            $firstSession = $ss['first'] ?? null;
            $sessCount = $ss['count'] ?? 0;

            // Effective registration end = min(EndDate, first session start) — task 6
            // (reg_window_end handles date-only vs DATETIME safely)
            $effEnd = reg_window_end($row['EndDate'] ?? null);
            if ($firstSession !== null) {
                $fsTs = strtotime($firstSession);
                if ($fsTs !== false && ($effEnd === null || $fsTs < $effEnd)) $effEnd = $fsTs;
            }

            // _ws_start: first session date (jalali) or «اعلام می‌شود»
            $row['_ws_start'] = ($firstSession !== null)
                ? date_jalali(substr($firstSession, 0, 10)) . (($t = substr($firstSession, 11, 5)) ? ' — ' . $t : '')
                : 'اعلام می‌شود';

            // _sessions: defined session count
            $row['_sessions'] = $sessCount > 0 ? $sessCount . ' جلسه' : 'تعریف نشده';

            // _reg_status: detailed status with reason + day counts (task 6/7)
            $now = time();
            $jStart = (!empty($row['StartDate']) && strtotime($row['StartDate']) !== false)
                ? date_jalali(substr($row['StartDate'], 0, 10)) : null;
            $jEnd = (!empty($row['EndDate']) && strtotime($row['EndDate']) !== false)
                ? date_jalali(substr($row['EndDate'], 0, 10)) : null;
            $jFirst = ($firstSession !== null) ? date_jalali(substr($firstSession, 0, 10)) : null;

            if (!(int)($row['IsActive'] ?? 0)) {
                $row['_reg_status'] = 'غیرفعال';
            } elseif ($effEnd !== null && $now > $effEnd) {
                // closed — say WHICH rule closed it
                if ($firstSession !== null && $now >= strtotime($firstSession)) {
                    $row['_reg_status'] = 'بسته است (کارگاه آغاز شده — جلسه اول: ' . $jFirst . ')';
                } else {
                    $row['_reg_status'] = 'بسته است (مهلت تا: ' . $jEnd . ')';
                }
            } elseif ($firstSession !== null && $now >= strtotime($firstSession)) {
                $row['_reg_status'] = 'بسته است (کارگاه آغاز شده — جلسه اول: ' . $jFirst . ')';
            } elseif (!empty($row['StartDate']) && $now < strtotime($row['StartDate'])) {
                $days = (int)ceil((strtotime($row['StartDate']) - $now) / 86400);
                $row['_reg_status'] = 'از ' . fa_days($days) . ' روز دیگر آغاز می‌شود (' . $jStart . ')';
            } else {
                // open — show remaining days to the effective deadline
                if ($effEnd !== null) {
                    $days = (int)ceil(($effEnd - $now) / 86400);
                    $row['_reg_status'] = 'باز است — تا ' . fa_days($days) . ' روز دیگر';
                } else {
                    $row['_reg_status'] = 'باز است';
                }
            }
        }
        unset($row);
    }

    foreach ($rows as &$row) {
        foreach ($row as $col => &$val) {
            if (is_string($val) && preg_match('/^\d{4}-\d{2}-\d{2}/', $val)) {
                $val = date_jalali(substr($val, 0, 10));
            }
        }
    }

    json_ok(['data' => [
        'rows'  => $rows,
        'total' => $total,
        'page'  => $page,
        'pages' => max(1, (int)ceil($total / $perPage)),
    ]]);
}

function admin_banner_time_status(?string $startDate, ?string $endDate): string
{
    $now = time();
    $start = $startDate ? strtotime($startDate) : null;
    $end = $endDate ? strtotime($endDate) : null;

    if ($start !== null && $now < $start) {
        return 'هنوز شروع نشده';
    }
    if ($end !== null && $now > $end) {
        $daysPassed = (int)floor(($now - $end) / 86400);
        return $daysPassed > 0 ? ($daysPassed . ' روز از پایان گذشته') : 'امروز پایان یافت';
    }
    if ($start !== null && $end !== null && $now >= $start && $now <= $end) {
        $daysLeft = (int)ceil(($end - $now) / 86400);
        return $daysLeft > 0 ? ($daysLeft . ' روز باقی‌مانده') : 'امروز آخرین روز';
    }
    return 'در جریان';
}
function admin_banner_countdown(?string $startDate, ?string $endDate, $isActive): array
{
    if (!$isActive) {
        return ['state' => 'inactive', 'text' => 'غیرفعال', 'days' => 0];
    }

    $now = time();
    $start = $startDate ? strtotime($startDate) : null;
    $end = $endDate ? strtotime($endDate) : null;

    // Registration hasn't started yet
    if ($start !== null && $now < $start) {
        $days = (int)ceil(($start - $now) / 86400);
        return ['state' => 'upcoming', 'text' => $days . ' روز تا شروع', 'days' => $days];
    }

    // Registration is open, check end date
    if ($end !== null && $now <= $end) {
        $days = (int)ceil(($end - $now) / 86400);
        if ($days === 0) {
            return ['state' => 'open', 'text' => 'آخرین روز', 'days' => 0];
        }
        return ['state' => 'open', 'text' => $days . ' روز تا پایان ثبت‌نام', 'days' => $days];
    }

    // Registration ended
    if ($end !== null && $now > $end) {
        return ['state' => 'ended', 'text' => 'ثبت‌نام بسته شد', 'days' => 0];
    }

    // No dates set — always open
    return ['state' => 'open', 'text' => 'ثبت‌نام باز', 'days' => 0];
}

function admin_pagination(): array
{
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(5, (int)($_GET['per_page'] ?? 20)));
    return [$page, $perPage, ($page - 1) * $perPage];
}

function admin_action_save(array $admin): void
{
    $in = req_input();
    $key = req_str($in, 'entity');
    $cfg = admin_entities()[$key] ?? null;
    if ($cfg === null || !admin_can($admin, $cfg['perm'])) json_err('دسترسی غیرمجاز', 403);
    if (!empty($cfg['readonly'])) json_err('این بخش فقط خواندنی است', 400);

    $id = req_str($in, 'id');
    $isAdd = ($id === '');

    $values = [];
    foreach ($cfg['fields'] as $f) {
        [$name, $label, $type, $required] = $f;

        // Virtual / special fields handled by entity hooks
        if ($key === 'banners' && $name === 'notify') continue;
        if ($type === 'roles_multi') continue;

        $raw = req_str($in, $name);
        $value = admin_cast($raw, $type);

        if ($type === 'password' && $raw === '') continue;
        if ($required && ($value === '' || $value === null)) {
            json_err("فیلد «{$label}» الزامی است");
        }
        $values[$name] = $value;
    }

    switch ($key) {
        case 'users':      admin_save_user($admin, $in, $id); return;
        case 'bot_admins': admin_save_bot_admin($admin, $values, $id); return;
        case 'banners':    admin_save_banner($admin, $values, $id, $isAdd, req_str($in, 'notify')); return;
        case 'certificates': admin_save_certificate($admin, $values, $id, $isAdd); return;
    }

    admin_generic_save($admin, $cfg, $values, $id, $isAdd);

    // Auto-archived activities: clear the review flag once an admin edits the record
    if ($key === 'activities') {
        try { db_exec("UPDATE activities SET needs_review = 0 WHERE id = ?", [(int)$id]); } catch (Throwable $ex) {}
    }
}

function admin_save_bot_admin(array $admin, array $values, string $id): void
{
    $isAdd = ($id === '');
    $tgId = only_digits($values['telegram_id'] ?? '');
    $role = $values['role'] ?? 'support';
    $isActive = (int)($values['is_active'] ?? 1);

    if (!preg_match('/^\d{4,15}$/', $tgId)) {
        json_err('آیدی عددی تلگرام نامعتبر است (فقط رقم، ۴ تا ۱۵ رقم)');
    }
    if (!in_array($role, ['admin', 'support'], true)) {
        $role = 'support';
    }
    $dup = db_one("SELECT id FROM bot_admins WHERE telegram_id = ? AND id != ? LIMIT 1", [$tgId, $id ?: 0]);
    if ($dup !== null) {
        json_err('این آیدی قبلاً به عنوان ادمین ربات ثبت شده است');
    }

    if ($isAdd) {
        db_exec(
            "INSERT INTO bot_admins (telegram_id, role, is_active) VALUES (?, ?, ?)",
            [$tgId, $role, $isActive]
        );
        $newId = db_id();
        audit_log($admin['id'], 'create', 'bot_admins', (string)$newId, ['telegram_id' => $tgId, 'role' => $role]);
        admin_notify_bot_admin($tgId, $role);
        json_ok(['data' => ['id' => $newId]], 'ادمین ربات اضافه شد');
    }

    db_exec("UPDATE bot_admins SET telegram_id = ?, role = ?, is_active = ? WHERE id = ?", [$tgId, $role, $isActive, $id]);
    audit_log($admin['id'], 'update', 'bot_admins', $id, ['telegram_id' => $tgId, 'role' => $role]);
    json_ok(['data' => ['id' => $id]], 'تغییرات ذخیره شد');
}

function admin_notify_bot_admin($tgIdOrValues, ?string $role = null): void
{
    $tgId = is_array($tgIdOrValues) ? ($tgIdOrValues['telegram_id'] ?? '') : $tgIdOrValues;
    if (!is_numeric($tgId) || (int)$tgId <= 0) return;

    $res = tg_send(
        (int)$tgId,
        "🤖 <b>شما به عنوان پشتیبان ربات SSAEE تعیین شدید.</b>\n\n" .
        "از این پس درخواست‌های پشتیبانی کاربران برای شما ارسال می‌شود و می‌توانید با دکمه «پاسخ به کاربر» جواب دهید.\n\n" .
        "⚠️ این پیام به صورت خودکار ارسال شده؛ اگر انتظارش را نداشتید با مدیر ارشد تماس بگیرید."
    );
    if ($res === null || !($res['ok'] ?? false)) {
        // User hasn't started the bot yet — non-fatal, logged for admin attention
        error_log('[bot_admin] notify failed for ' . $tgId . ' (bot not started?)');
    }
}

function admin_cast(string $raw, string $type)
{
    return match ($type) {
        'int'      => ($raw === '' ? 0 : (int)only_digits($raw)),
        'float'    => ($raw === '' ? 0.0 : (float)str_replace(',', '', fa2en($raw))),
        'date'     => ($raw === '' ? null : date_from_jalali($raw)),
        'password' => $raw,
        default    => clean_str($raw),
    };
}

function admin_generic_save(array $admin, array $cfg, array $values, string $id, bool $isAdd, string $entityKey = ''): void
{
    $table = $cfg['table'];
    $idCol = $cfg['id'];
    $entityKey = $entityKey !== '' ? $entityKey : $table;

    if ($isAdd) {
        if (!empty($cfg['auto_id'])) {
            [$col, $prefix] = $cfg['auto_id'];
            if (($values[$col] ?? '') === '' || $values[$col] === null) {
                $values[$col] = $prefix === 'CRT' ? gen_cert_id() : gen_id($prefix);
            }
        }
        $cols = array_keys($values);
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        db_exec("INSERT INTO `{$table}` (`" . implode('`, `', $cols) . "`) VALUES ({$ph})", array_values($values));
        $newId = (string)($values[$idCol] ?? db_id());
        audit_log($admin['id'], 'create', $entityKey, $newId, $values);
        json_ok(['data' => ['id' => $newId]], 'با موفقیت اضافه شد');
    } else {
        if ($id === '') json_err('شناسه نامعتبر');
        $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($values)));
        $params = array_values($values);
        $params[] = $id;
        db_exec("UPDATE `{$table}` SET {$set} WHERE `{$idCol}` = ?", $params);
        audit_log($admin['id'], 'update', $table, $id, $values);
        json_ok(['data' => ['id' => $id]], 'تغییرات ذخیره شد');
    }
}

function admin_save_user(array $admin, array $in, string $id): void
{
    // superadmin and tech_admin manage admin accounts — but tech_admin can never create/modify a superadmin
    if (!admin_is_super($admin) && !admin_is_tech($admin)) {
        json_err('دسترسی مدیریت مدیران فقط برای مدیر ارشد و ادمین فنی مجاز است', 403);
    }
    $isAdd = ($id === '');
    $username = req_str($in, 'username');
    $fullName = req_str($in, 'full_name');
    $password = req_str($in, 'password');
    $telegramId = only_digits(req_str($in, 'telegram_id'));
    $isActive = req_str($in, 'is_active', '1') === '0' ? 0 : 1;

    $roles = $in['roles'] ?? [];
    if (is_string($roles)) $roles = array_filter(array_map('trim', explode(',', $roles)));
    if (!is_array($roles)) $roles = [];
    $roles = array_values(array_unique(array_intersect($roles, array_keys(ADMIN_ROLES))));
    if (!$roles) json_err('انتخاب حداقل یک نقش الزامی است');

    // task 10: tech_admin may create/modify admin accounts at ANY access level
    // (including superadmin) — access to this section itself is already gated.

    if (!preg_match('/^[a-zA-Z0-9_]{4,50}$/', $username)) json_err('نام کاربری باید ۴ تا ۵۰ حرف انگلیسی/عدد باشد');
    if ($fullName === '') json_err('نام کامل الزامی است');
    if ($isAdd && strlen($password) < 8) json_err('رمز عبور باید حداقل ۸ کاراکتر باشد');
    if (!$isAdd && $password !== '' && strlen($password) < 8) json_err('رمز عبور باید حداقل ۸ کاراکتر باشد');

    $dup = db_one("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1", [$username, $id ?: 0]);
    if ($dup !== null) json_err('این نام کاربری قبلاً استفاده شده است');

    $selfId = (int)$admin['id'];
    $targetId = (int)$id;

    if (!$isAdd && $targetId === $selfId) {
        if ($isActive === 0) json_err('نمی‌توانید حساب خودتان را غیرفعال کنید');
        if (in_array('superadmin', admin_roles_of($admin), true) && !in_array('superadmin', $roles, true)) {
            json_err('نمی‌توانید نقش مدیر ارشد خودتان را حذف کنید');
        }
    }

    $primary = in_array('superadmin', $roles, true) ? 'superadmin' : $roles[0];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($isAdd) {
            $pdo->prepare(
                "INSERT INTO users (username, password, full_name, role, is_active, telegram_id, created_by, must_setup)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
            )->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $primary, $isActive, $telegramId ?: null, $selfId]);
            $targetId = db_id();
        } else {
            if ($password !== '') {
                $pdo->prepare(
                    "UPDATE users SET username = ?, full_name = ?, role = ?, is_active = ?, telegram_id = ?, password = ? WHERE id = ?"
                )->execute([$username, $fullName, $primary, $isActive, $telegramId ?: null, password_hash($password, PASSWORD_DEFAULT), $targetId]);
            } else {
                $pdo->prepare(
                    "UPDATE users SET username = ?, full_name = ?, role = ?, is_active = ?, telegram_id = ? WHERE id = ?"
                )->execute([$username, $fullName, $primary, $isActive, $telegramId ?: null, $targetId]);
            }
        }
        $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$targetId]);
        $ins = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, ?)");
        foreach ($roles as $r) {
            $ins->execute([$targetId, $r]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    audit_log($selfId, $isAdd ? 'create' : 'update', 'users', (string)$targetId, ['username' => $username, 'roles' => $roles]);
    json_ok(['data' => ['id' => $targetId]], $isAdd ? 'مدیر جدید اضافه شد' : 'تغییرات ذخیره شد');
}function admin_save_banner(array $admin, array $values, string $id, bool $isAdd, string $notify): void
{
    $tag = strtoupper($values['TargetTag'] ?? '');
    if (!preg_match('/^[A-Z]{1,5}$/', $tag)) {
        json_err('کد کوتاه کارگاه باید ۱ تا ۵ حرف انگلیسی باشد');
    }
    $values['TargetTag'] = $tag;

    $dup = db_one(
        "SELECT ID FROM banners WHERE TargetTag = ? AND IsActive = 1 AND ID != ? LIMIT 1",
        [$tag, $id ?: '']
    );
    if ($dup !== null) json_err('این کد کوتاه قبلاً برای بنر فعال دیگری استفاده شده است');

    // GroupLink lives in workshops, not in banners
    $groupLink = $values['GroupLink'] ?? null;
    unset($values['GroupLink']);

    // Guard: an empty GroupLink submitted from the edit form must NOT wipe an
    // existing workshop link (e.g. stale tab saved before the modal fix).
    // To intentionally clear a link, admin can type "-" (or "- ").
    if (($groupLink === '' || $groupLink === null) && !$isAdd) {
        $existing = db_val("SELECT GroupLink FROM workshops WHERE Tag = ? LIMIT 1", [$tag]);
        if ($existing !== null && $existing !== '') {
            $groupLink = $existing; // keep current link, ignore empty submit
        }
    }
    if ($groupLink === '-') $groupLink = null;

    $bannerId = admin_generic_save_raw($admin, 'banners', 'ID', $values, $id, $isAdd, ['ID', 'BAN'], 'banners');

    // Sync workshops table (used for group links)
    $workshop = db_one("SELECT ID FROM workshops WHERE Tag = ? LIMIT 1", [$tag]);
    $wVals = [
        'Title'     => $values['Title'],
        'Tag'       => $tag,
        'GroupLink' => $groupLink,
        'BannerURL' => $values['ImageURL'] ?? null,
        'IsActive'  => (int)($values['IsActive'] ?? 1),
        'StartDate' => $values['StartDate'] ?? null,
        'EndDate'   => $values['EndDate'] ?? null,
    ];
    if ($workshop !== null) {
        db_exec(
            "UPDATE workshops SET Title=?, Tag=?, GroupLink=?, BannerURL=?, IsActive=?, StartDate=?, EndDate=? WHERE ID=?",
            [$wVals['Title'], $wVals['Tag'], $wVals['GroupLink'], $wVals['BannerURL'], $wVals['IsActive'], $wVals['StartDate'], $wVals['EndDate'], $workshop['ID']]
        );
    } else {
        $dupTag = db_one("SELECT ID FROM workshops WHERE Tag = ? LIMIT 1", [$tag]);
        if ($dupTag === null) {
            db_exec(
                "INSERT INTO workshops (ID, Title, Tag, Type, GroupLink, BannerURL, IsActive, StartDate, EndDate)
                 VALUES (?, ?, ?, 'general', ?, ?, ?, ?, ?)",
                [gen_id('WS'), $wVals['Title'], $wVals['Tag'], $wVals['GroupLink'], $wVals['BannerURL'], $wVals['IsActive'], $wVals['StartDate'], $wVals['EndDate']]
            );
        }
    }

    if ($isAdd && $notify === '1') {
        admin_notify_workshop_start($values['Title'], $tag);
    }

    json_ok(['data' => ['id' => $bannerId]], $isAdd ? 'بنر اضافه شد' : 'تغییرات ذخیره شد');
}

function admin_save_certificate(array $admin, array $values, string $id, bool $isAdd): void
{
    cert_schema_ready();
    if ($isAdd && ($values['ID'] ?? '') === '') {
        $values['ID'] = gen_cert_id();
    }
    if (($values['ExpDate'] ?? null) === '') $values['ExpDate'] = null;
    if (($values['CertType'] ?? '') === '') $values['CertType'] = null;
    // FileLink removed — certificates are rendered on the fly from the template
    unset($values['FileLink']);

    $bannerId = admin_generic_save_raw($admin, 'certificates', 'ID', $values, $id, $isAdd, ['ID', 'CRT'], 'certificates');

    if ($isAdd) {
        admin_notify_certificate($values);
    }
    json_ok(['data' => ['id' => $bannerId]], $isAdd ? 'گواهینامه اضافه شد' : 'تغییرات ذخیره شد');
}

function admin_generic_save_raw(array $admin, string $table, string $idCol, array $values, string $id, bool $isAdd, array $autoId, string $entityKey): string
{
    if ($isAdd) {
        if (($values[$idCol] ?? '') === '' || $values[$idCol] === null) {
            $values[$idCol] = $autoId[1] === 'CRT' ? gen_cert_id() : gen_id($autoId[1]);
        }
        $cols = array_keys($values);
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        db_exec("INSERT INTO `{$table}` (`" . implode('`, `', $cols) . "`) VALUES ({$ph})", array_values($values));
        $newId = (string)$values[$idCol];
        audit_log($admin['id'], 'create', $entityKey, $newId, $values);
        return $newId;
    }

    $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($values)));
    $params = array_values($values);
    $params[] = $id;
    db_exec("UPDATE `{$table}` SET {$set} WHERE `{$idCol}` = ?", $params);
    audit_log($admin['id'], 'update', $entityKey, $id, $values);
    return $id;
}

function admin_action_delete(array $admin): void
{
    $in = req_input();
    $key = req_str($in, 'entity');
    $id = req_str($in, 'id');
    $cfg = admin_entities()[$key] ?? null;
    if ($cfg === null || !admin_can($admin, $cfg['perm'])) json_err('دسترسی غیرمجاز', 403);
    if (!empty($cfg['readonly'])) json_err('این بخش فقط خواندنی است', 400);
    if ($id === '') json_err('شناسه نامعتبر');
    if ($key === 'users' && !admin_is_super($admin)) {
        json_err('حذف مدیران فقط توسط مدیر ارشد امکان‌پذیر است', 403);
    }
    if ($key === 'users' && (int)$id === (int)$admin['id']) json_err('نمی‌توانید حساب خودتان را حذف کنید');

    // Trashbin capture before delete (30-day rollback for records + local files)
    try {
        $table = $cfg['table'];
        $idCol = $cfg['id'];
        $record = db_one("SELECT * FROM {$table} WHERE {$idCol} = ? LIMIT 1", [$id]);
        if ($record !== null) {
            admin_trashbin_capture($admin, $key, $cfg, $id, $record);
        }
    } catch (Throwable $ex) {
        error_log('[trashbin] ' . $ex->getMessage());
    }

    db_exec("DELETE FROM {$cfg['table']} WHERE {$cfg['id']} = ?", [$id]);
    audit_log($admin['id'], 'delete', $key, $id);
    json_ok([], 'به سبد بازیافت منتقل شد (۳۰ روز فرصت بازگردانی)');
}

function admin_action_row(array $admin): void
{
    $in = req_input();
    $do = req_str($in, 'do');
    $id = (int)req_str($in, 'id');

    switch ($do) {
        case 'approve_req': {
            if (!admin_can($admin, 'membersreq')) json_err('دسترسی غیرمجاز', 403);
            $req = db_one("SELECT * FROM membersreq WHERE ID = ?", [$id]);
            if ($req === null) json_err('درخواست یافت نشد', 404);

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $dup = db_val("SELECT ID FROM members WHERE NationalID = ? OR StudentID = ?", [$req['NationalID'], $req['StudentID']]);
                if ($dup === null) {
                    $pdo->prepare(
                        "INSERT INTO members (NameFa, NationalID, StudentID, Branch, Active, Points, IssueDate, ExpDate, Phone, TelegramID, Year)
                         VALUES (?, ?, ?, ?, 0, 0, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), ?, ?, ?)"
                    )->execute([$req['Name'], $req['NationalID'], $req['StudentID'], $req['Branch'], $req['Phone'], $req['TelegramID'], $req['YearOfEnter']]);

                    notify_member($req['NationalID'], $req['TelegramID'],
                        'تأیید عضویت',
                        "🎉 <b>درخواست عضویت شما تأیید شد!</b>\n\n" .
                        "به انجمن علمی مهندسی برق علوم و تحقیقات (SSAEE) خوش آمدید.\n\n" .
                        "🔹 کارت عضویت شما فعال شد\n" .
                        "🔹 از پنل می‌توانید امتیازها و گواهینامه‌های خود را دنبال کنید\n" .
                        "🔹 مدت عضویت: یک سال از امروز",
                        'success'
                    );
                }
                $pdo->prepare("UPDATE membersreq SET Status = 'تایید شده' WHERE ID = ?")->execute([$id]);
                $pdo->commit();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                throw $ex;
            }
            audit_log($admin['id'], 'approve', 'membersreq', (string)$id);
            json_ok([], 'درخواست تأیید شد');
        }

        case 'reject_req': {
            if (!admin_can($admin, 'membersreq')) json_err('دسترسی غیرمجاز', 403);
            $req = db_one("SELECT NationalID, TelegramID FROM membersreq WHERE ID = ?", [$id]);
            db_exec("UPDATE membersreq SET Status = 'رد شده' WHERE ID = ?", [$id]);
            if ($req !== null) {
                notify_member($req['NationalID'], $req['TelegramID'],
                    'نتیجه درخواست عضویت',
                    "📋 <b>درخواست عضویت شما بررسی شد.</b>\n\nمتأسفانه درخواست شما در این مرحله تأیید نشد.\nبرای اطلاعات بیشتر با پشتیبانی (t.me/saee_admin) تماس بگیرید.",
                    'warning'
                );
            }
            audit_log($admin['id'], 'reject', 'membersreq', (string)$id);
            json_ok([], 'درخواست رد شد');
        }

        case 'points': {
            if (!admin_can($admin, 'members')) json_err('دسترسی غیرمجاز', 403);
            // default amount from auto-points settings (task 2); 0 falls back to 5
            $defaultAmt = (int)setting('auto_points_activity', '5');
            if ($defaultAmt <= 0) $defaultAmt = 5;
            $amount = min(100, max(1, (int)req_str($in, 'amount', (string)$defaultAmt)));
            db_exec("UPDATE members SET Points = Points + ? WHERE ID = ?", [$amount, $id]);
            $member = db_one("SELECT NameFa, NationalID, TelegramID FROM members WHERE ID = ?", [$id]);
            if ($member !== null) {
                notify_member($member['NationalID'], $member['TelegramID'],
                    'افزایش امتیاز',
                    "⭐ <b>{$amount} امتیاز به شما اضافه شد!</b>\n\n" .
                    "امتیاز فعلی: " . (string)db_val("SELECT Points FROM members WHERE ID = ?", [$id]) . "\n" .
                    "از پنل SSAEE می‌توانید امتیاز خود را دنبال کنید.",
                    'success'
                );
            }
            audit_log($admin['id'], 'add_points', 'members', (string)$id, ['amount' => $amount]);
            json_ok([], "امتیاز {$amount} اضافه شد");
        }

        case 'toggle': {
            if (!admin_can($admin, 'members')) json_err('دسترسی غیرمجاز', 403);
            db_exec("UPDATE members SET Active = 1 - Active WHERE ID = ?", [$id]);
            $member = db_one("SELECT NameFa, NationalID, TelegramID, Active FROM members WHERE ID = ?", [$id]);
            if ($member !== null) {
                $isNowActive = (int)$member['Active'] === 1;
                notify_member($member['NationalID'], $member['TelegramID'],
                    'تغییر وضعیت عضویت',
                    $isNowActive
                        ? "✅ <b>کارت عضویت شما فعال شد.</b>\nاز پنل SSAEE می‌توانید امتیازها و گواهینامه‌های خود را دنبال کنید."
                        : "⚠️ <b>کارت عضویت شما غیرفعال شد.</b>\n\nدر صورت نیاز به توضیح، با پشتیبانی (t.me/saee_admin) تماس بگیرید.",
                    $isNowActive ? 'success' : 'warning'
                );
            }
            audit_log($admin['id'], 'toggle_active', 'members', (string)$id);
            json_ok([], 'وضعیت تغییر کرد');
        }

        default:
            json_err('عملیات نامعتبر', 400);
    }
}

/* ============================================================
   Stats
   ============================================================ */

function admin_stats(array $admin): void
{
    $cards = [];
    if (admin_can($admin, 'certificates')) {
        $cards[] = ['icon' => 'award', 'label' => 'گواهینامه‌ها', 'value' => (int)db_val("SELECT COUNT(*) FROM certificates")];
        $un = 0;
        try {
            $un = (int)db_val("SELECT COUNT(*) FROM certificates WHERE (NameEn IS NULL OR NameEn = '') OR (CourseEn IS NULL OR CourseEn = '')");
        } catch (Throwable $ex) {}
        if ($un > 0) {
            $cards[] = ['icon' => 'alert-triangle', 'label' => 'گواهی‌های بدون ترجمه انگلیسی — برای ترجمه اقدام کنید', 'value' => $un, 'danger' => true, 'entity' => 'certificates'];
        }
    }
    if (admin_can($admin, 'members')) {
        $cards[] = ['icon' => 'users', 'label' => 'اعضای تأیید شده', 'value' => (int)db_val("SELECT COUNT(*) FROM members")];
        $cards[] = ['icon' => 'user-plus', 'label' => 'درخواست عضویت جدید', 'value' => (int)db_val("SELECT COUNT(*) FROM membersreq WHERE Status = 'NEW'")];
    }
    if (admin_can($admin, 'workshopreq')) {
        $cards[] = ['icon' => 'graduation-cap', 'label' => 'درخواست کارگاه', 'value' => (int)db_val("SELECT COUNT(*) FROM workshopreq WHERE Status = 'NEW'")];
        $cards[] = ['icon' => 'book-open', 'label' => 'کلاس حل تمرې', 'value' => (int)db_val("SELECT COUNT(*) FROM classreq WHERE Status = 'NEW'")];
    }
    if (admin_can($admin, 'registrations')) {
        $cards[] = ['icon' => 'clipboard-list', 'label' => 'ثبت‌نام کارگاه', 'value' => (int)db_val("SELECT COUNT(*) FROM workshop_registrations")];
    }
    if (admin_can($admin, 'banners')) {
        $cards[] = ['icon' => 'image', 'label' => 'بنرهای فعال', 'value' => (int)db_val("SELECT COUNT(*) FROM banners WHERE IsActive = 1")];
    }
    if (admin_can($admin, 'activities')) {
        $cards[] = ['icon' => 'activity', 'label' => 'فعالیت‌های ثبت شده', 'value' => (int)db_val("SELECT COUNT(*) FROM activities")];
    }
    if (admin_can($admin, 'users')) {
        $cards[] = ['icon' => 'shield', 'label' => 'مدیران فعال', 'value' => (int)db_val("SELECT COUNT(*) FROM users WHERE is_active = 1")];
    }

    // Members analytics (for the members-stats modal)
    $memberCount = (int)db_val("SELECT COUNT(*) FROM members");
    $participating = (int)db_val(
        "SELECT COUNT(DISTINCT m.ID) FROM members m
         WHERE m.StudentID IS NOT NULL AND m.StudentID != ''
           AND EXISTS (SELECT 1 FROM workshop_registrations r WHERE r.StudentID = m.StudentID)"
    );

    // Satisfaction = live average of survey ratings (1..5 → %), fallback to manual setting
    $sAvg = db_val(
        "SELECT AVG(rating) FROM workshop_surveys
         WHERE rating IS NOT NULL AND answered_at IS NOT NULL"
    );
    $satisfaction = $sAvg !== null && (float)$sAvg > 0
        ? round(((float)$sAvg / 5) * 100, 1)
        : (float)(setting('satisfaction_rate', '0') ?? 0);

    $membersStats = [
        'count'         => $memberCount,
        'participation' => $memberCount > 0 ? round($participating / $memberCount * 100) : 0,
        'total_points'  => (float)(db_val("SELECT COALESCE(SUM(Points),0) FROM members") ?? 0),
        'satisfaction'  => $satisfaction,
        'club_score'    => (float)(setting('researchers_club_score', '0') ?? 0),
        'total_registrations' => (int)db_val("SELECT COUNT(*) FROM workshop_registrations"),
    ];

    // Chart data (dashboard)
    $charts = [];
    if (admin_can($admin, 'registrations')) {
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $days[date('Y-m-d', strtotime("-{$i} day"))] = 0;
        }
        foreach (db_all(
            "SELECT DATE(Date) d, COUNT(*) c FROM workshop_registrations
             WHERE Date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
             GROUP BY DATE(Date)"
        ) as $r) {
            if (isset($days[$r['d']])) $days[$r['d']] = (int)$r['c'];
        }
        $charts['registrations_daily'] = [
            'labels' => array_map(fn($d) => date_jalali($d), array_keys($days)),
            'values' => array_values($days),
        ];
        $charts['registration_status'] = [
            'labels' => ['در انتظار', 'تأیید شده', 'رد شده', 'لغو شده'],
            'values' => [
                (int)db_val("SELECT COUNT(*) FROM workshop_registrations WHERE Status = 'PENDING'"),
                (int)db_val("SELECT COUNT(*) FROM workshop_registrations WHERE Status = 'CONFIRMED'"),
                (int)db_val("SELECT COUNT(*) FROM workshop_registrations WHERE Status = 'REJECTED'"),
                (int)db_val("SELECT COUNT(*) FROM workshop_registrations WHERE Status = 'CANCELLED'"),
            ],
        ];
        $charts['collab_counts'] = [
        'labels' => ['انجمن‌ها', 'اساتید', 'شرکت‌ها', 'اسپانسرها'],
        'values' => [
            admin_can($admin, 'associations') ? (int)db_val("SELECT COUNT(*) FROM associations") : 0,
            admin_can($admin, 'teachers') ? (int)db_val("SELECT COUNT(*) FROM teachers") : 0,
            admin_can($admin, 'companies') ? (int)db_val("SELECT COUNT(*) FROM companies") : 0,
            admin_can($admin, 'sponsor') ? (int)db_val("SELECT COUNT(*) FROM sponsor") : 0,
        ],
    ];

    $charts['entity_counts'] = [
            'labels' => ['گواهینامه‌ها', 'اعضا', 'درخواست عضویت', 'درخواست کارگاه', 'کلاس حل تمرې', 'پیام‌ها'],
            'values' => [
                admin_can($admin, 'certificates') ? (int)db_val("SELECT COUNT(*) FROM certificates") : 0,
                admin_can($admin, 'members') ? (int)db_val("SELECT COUNT(*) FROM members") : 0,
                admin_can($admin, 'membersreq') ? (int)db_val("SELECT COUNT(*) FROM membersreq") : 0,
                admin_can($admin, 'workshopreq') ? (int)db_val("SELECT COUNT(*) FROM workshopreq") : 0,
                admin_can($admin, 'classreq') ? (int)db_val("SELECT COUNT(*) FROM classreq") : 0,
                admin_can($admin, 'suggestions') ? (int)db_val("SELECT COUNT(*) FROM suggestions") : 0,
            ],
        ];
    }

    // Sidebar alerts (blinking indicators)
    $alerts = ['activities' => admin_pending_activity_count()];

    json_ok(['data' => [
        'cards'         => $cards,
        'members_stats' => $membersStats,
        'charts'        => $charts,
        'alerts'        => $alerts,
        'sections'      => [
            'members_stats' => admin_can($admin, 'members'),
            'charts'        => admin_can($admin, 'registrations'),
        ],
    ]]);
}

/* ============================================================
   Finished-workshop auto-archive → activities (task: فعالیت‌ها)
   ============================================================ */

function admin_ensure_activities_review_col(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $col = db_one(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activities' AND COLUMN_NAME = 'needs_review'"
        );
        if ($col === null) {
            db_exec("ALTER TABLE activities ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Throwable $ex) {
        error_log('[needs_review column] ' . $ex->getMessage());
    }
}

function admin_pending_activity_count(): int
{
    try {
        return (int)db_val("SELECT COUNT(*) FROM activities WHERE needs_review = 1");
    } catch (Throwable $ex) {
        return 0;
    }
}

/** Telegram ids of admins who can access the activities section (via role tables). */
function admin_activity_reviewer_tg_ids(): array
{
    $roles = [];
    foreach (admin_role_tables() as $role => $tables) {
        if (in_array('activities', $tables, true)) $roles[] = $role;
    }
    if (!$roles) return [];

    $ids = [];
    $ph = implode(',', array_fill(0, count($roles), '?'));
    foreach (db_all(
        "SELECT DISTINCT u.telegram_id
         FROM users u
         LEFT JOIN user_roles ur ON ur.user_id = u.id
         WHERE u.is_active = 1 AND u.telegram_id IS NOT NULL AND u.telegram_id > 0
           AND (u.role IN ({$ph}) OR ur.role IN ({$ph}))",
        array_merge($roles, $roles)
    ) as $r) {
        $ids[] = (int)$r['telegram_id'];
    }
    return array_values(array_unique(array_filter($ids, fn($i) => $i > 0)));
}

/** Cron step: move finished workshops into activities (draft, needs_review=1) + notify. */
/**
 * Persian day-count for status strings: «۱ روز» / «۳ روز»
 */
function fa_days(int $n): string
{
    $n = max(0, $n);
    $d = str_replace('/', '‌', date_jalali(date('Y-m-d'))); // ensure jalali context loaded
    return $n . ($n === 1 ? ' روز' : ' روز');
}

/**
 * Auto points (task 2): award points to a workshop's confirmed participants.
 * Amount comes from settings (auto_points_workshop, 0 = disabled).
 * Idempotent via auto_points_log (a (kind, ref, member) triple is awarded once).
 */
function admin_award_workshop_points(string $tag, string $title): int
{
    $amount = (int)setting('auto_points_workshop', '0');
    if ($amount <= 0) return 0;

    try {
        db_exec(
            "CREATE TABLE IF NOT EXISTS auto_points_log (
                `id` INT NOT NULL AUTO_INCREMENT,
                `kind` VARCHAR(30) NOT NULL,
                `ref` VARCHAR(60) NOT NULL,
                `member_id` INT NOT NULL,
                `amount` DECIMAL(5,2) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ap` (`kind`, `ref`, `member_id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci"
        );
    } catch (Throwable $ex) {
        error_log('[auto_points table] ' . $ex->getMessage());
        return 0;
    }

    $awarded = 0;
    foreach (db_all(
        "SELECT r.StudentID, r.FullName FROM workshop_registrations r
         WHERE r.WorkshopTag = ? AND r.Status = 'CONFIRMED' AND r.StudentID != 'EXT' AND r.StudentID != ''",
        [$tag]
    ) as $r) {
        $m = db_one("SELECT ID, NationalID, TelegramID FROM members WHERE StudentID = ? LIMIT 1", [$r['StudentID']]);
        if ($m === null) continue;

        $already = db_val(
            "SELECT id FROM auto_points_log WHERE kind = 'workshop' AND ref = ? AND member_id = ? LIMIT 1",
            [$tag, (int)$m['ID']]
        );
        if ($already) continue;

        db_exec("UPDATE members SET Points = Points + ? WHERE ID = ?", [$amount, (int)$m['ID']]);
        db_exec(
            "INSERT INTO auto_points_log (kind, ref, member_id, amount) VALUES ('workshop', ?, ?, ?)",
            [$tag, (int)$m['ID'], $amount]
        );
        notify_member($m['NationalID'], $m['TelegramID'],
            'امتیاز کارگاه',
            "⭐ <b>{$amount} امتیاز بابت شرکت در کارگاه «{$title}» به شما اهدا شد!</b>\n\nمجموع امتیاز: " .
            (string)db_val("SELECT Points FROM members WHERE ID = ?", [(int)$m['ID']]),
            'success'
        );
        $awarded++;
    }
    return $awarded;
}

function admin_archive_finished_workshops(): array
{    admin_ensure_activities_review_col();

    $out = ['archived' => 0, 'notified' => 0, 'points_awarded' => 0];
    $archivedTags = json_decode((string)setting('archived_workshops', '[]'), true) ?: [];
    $archivedTags = is_array($archivedTags) ? $archivedTags : [];

    // Workshops whose last session ended more than 24h ago
    $finished = db_all(
        "SELECT w.Tag, w.Title,
                MAX(CONCAT(ws.session_date, ' ', ws.session_time)) AS last_session
         FROM workshops w
         JOIN workshop_sessions ws ON ws.workshop_tag = w.Tag
         GROUP BY w.Tag, w.Title
         HAVING last_session IS NOT NULL AND last_session < NOW() - INTERVAL 24 HOUR"
    );

    $pendingTags = [];
    foreach ($finished as $w) {
        $tag = (string)$w['Tag'];
        if ($tag === '' || in_array($tag, $archivedTags, true)) continue;

        $title = $w['Title'] ?: $tag;
        $actDate = substr((string)$w['last_session'], 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $actDate)) $actDate = date('Y-m-d');

        // Draft activity — admin completes the info later
        db_exec(
            "INSERT INTO activities (title, act_date, description, needs_review)
             VALUES (?, ?, ?, 1)",
            [
                'برگزاری کارگاه: ' . $title,
                $actDate,
                "کارگاه «{$title}» برگزار شد. [tag:{$tag}] — این رکورد به‌صورت خودکار ساخته شده است؛ اطلاعات فعالیت (شرح، تصویر و تاریخ دقیق) را تکمیل کنید.",
            ]
        );

        $archivedTags[] = $tag;
        $pendingTags[] = $title;
        $out['archived']++;

        // Auto-award points to this workshop's confirmed participants (task 2)
        $out['points_awarded'] += admin_award_workshop_points($tag, $title);
    }

    if ($out['archived'] > 0) {
        setting_set('archived_workshops', json_encode($archivedTags));

        // Telegram alarm to admins with activities access
        $ids = admin_activity_reviewer_tg_ids();
        if ($ids) {
            $names = implode('، ', array_slice($pendingTags, 0, 5));
            $more = count($pendingTags) > 5 ? ' و ' . (count($pendingTags) - 5) . ' مورد دیگر' : '';
            $text = "🏁 <b>کارگاه‌های به‌اتمام‌رسیده به بخش فعالیت‌ها منتقل شدند</b>\n\n"
                . "📚 {$names}{$more}\n\n"
                . "✏️ لطفاً اطلاعات فعالیت‌ها (شرح، تصویر و تاریخ) را در پنل تکمیل کنید.";
            $stats = tg_broadcast($ids, $text);
            $out['notified'] = $stats['sent'] ?? 0;
        }
    }

    return $out;
}

/* ============================================================
   Notifications (Telegram)
   ============================================================ */

function admin_audience_ids(string $audience): array
{
    $ids = [];

    // Bot users — a user_profiles row is created for EVERY user who started the bot,
    // so this is the primary audience source (members who never registered on the
    // website are still reachable here).
    if ($audience === 'connected' || $audience === 'all') {
        foreach (db_all("SELECT TelegramID FROM user_profiles WHERE TelegramID > 0") as $r) {
            $ids[] = (int)$r['TelegramID'];
        }
        // Site users who linked telegram via the website
        foreach (db_all("SELECT telegram_id FROM web_users WHERE telegram_id IS NOT NULL AND telegram_id > 0 AND is_active = 1") as $r) {
            $ids[] = (int)$r['telegram_id'];
        }
    }
    // Members (with telegram) — legacy column + canonical profile resolution
    if ($audience === 'members' || $audience === 'all') {
        foreach (db_all("SELECT TelegramID FROM members WHERE TelegramID IS NOT NULL AND TelegramID > 0") as $r) {
            $ids[] = (int)$r['TelegramID'];
        }
        foreach (db_all(
            "SELECT DISTINCT up.TelegramID
             FROM members m
             JOIN user_profiles up ON up.NationalID = m.NationalID
             WHERE m.Active = 1 AND up.TelegramID > 0"
        ) as $r) {
            $ids[] = (int)$r['TelegramID'];
        }
    }
    // Site admins
    if ($audience === 'admins') {
        foreach (db_all("SELECT telegram_id FROM users WHERE telegram_id IS NOT NULL AND telegram_id > 0 AND is_active = 1") as $r) {
            $ids[] = (int)$r['telegram_id'];
        }
    }
    // Bot admins (support)
    if ($audience === 'bot_admins') {
        foreach (db_all("SELECT telegram_id FROM bot_admins WHERE telegram_id IS NOT NULL AND telegram_id > 0 AND is_active = 1") as $r) {
            $ids[] = (int)$r['telegram_id'];
        }
    }
    return array_values(array_unique(array_filter($ids, fn($i) => $i > 0)));
}

/**
 * Telegram ids of a single workshop's confirmed registrants.
 * Uses the same resolution chain as surveys/notifications:
 * direct TelegramID on the registration's member → phone → profiles → web_users.
 */
function admin_workshop_registrant_ids(string $tag): array
{
    $ids = [];
    foreach (db_all(
        "SELECT r.ID AS reg_id, r.Phone, r.StudentID
         FROM workshop_registrations r
         WHERE r.WorkshopTag = ? AND r.Status = 'CONFIRMED'",
        [$tag]
    ) as $r) {
        $tgId = member_tg_id('', null, (string)($r['Phone'] ?? ''));
        if ($tgId === null && !empty($r['StudentID']) && $r['StudentID'] !== 'EXT') {
            $m = db_one("SELECT NationalID, TelegramID FROM members WHERE StudentID = ? LIMIT 1", [$r['StudentID']]);
            if ($m !== null) $tgId = member_tg_id($m['NationalID'], $m['TelegramID'], (string)($r['Phone'] ?? ''));
        }
        if ($tgId !== null && $tgId > 0) $ids[] = (int)$tgId;
    }
    return array_values(array_unique($ids));
}

function admin_action_broadcast(array $admin): void
{
    // Targeted workshop broadcasts come from the banners section (banners perm),
    // global ones from اطلاع‌رسانی (announcements perm) — either is sufficient.
    if (!admin_can($admin, 'announcements') && !admin_can($admin, 'banners')) json_err('دسترسی غیرمجاز', 403);

    // Audience stats — GET ?api=broadcast&sub=stats
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && req_str($_GET, 'sub') === 'stats') {
        $out = [];
        foreach (['connected', 'members', 'all', 'admins', 'bot_admins'] as $a) {
            $out[$a] = count(admin_audience_ids($a));
        }
        json_ok(['data' => ['audiences' => $out]]);
    }

    $in = req_input();

    /* Chunk-drain requests ({sub:'next'}) carry no title/message — handle them
       FIRST, before title/message validation kicks in. */
    if (req_str($in, 'sub') === 'next') {
        boot_session();
        $job = $_SESSION['bc_job'] ?? null;
        if (!is_array($job) || empty($job['ids']) || (time() - (int)($job['created'] ?? 0)) > 1800) {
            unset($_SESSION['bc_job']);
            json_err('کار ارسالی یافت نشد یا منقضی شده — دوباره شروع کنید', 404);
        }

        $ids = array_values($job['ids']);
        $total = (int)$job['total'];
        $pos = (int)$job['pos'];
        $CHUNK = 10; // ≈5s typical, worst-case still far below PHP time limits

        $chunkIds = array_slice($ids, $pos, $CHUNK);
        foreach ($chunkIds as $chatId) {
            $res = tg_send((int)$chatId, (string)$job['text']);
            if ($res !== null && ($res['ok'] ?? false)) {
                $job['sent']++;
            } else {
                $desc = (string)($res['description'] ?? '');
                if (strpos($desc, 'bot was blocked') !== false
                    || strpos($desc, 'chat not found') !== false
                    || strpos($desc, 'user is deactivated') !== false) {
                    $job['blocked']++;
                } else {
                    $job['failed']++;
                }
            }
            usleep(60000); // ~60ms gap → ≈16 msg/sec, well under Telegram limits
        }
        $job['pos'] = $pos + count($chunkIds);
        $_SESSION['bc_job'] = $job;

        $done = $job['pos'] >= $total;

        if ($done) {
            unset($_SESSION['bc_job']);
            audit_log((int)$job['admin_id'], 'broadcast', 'telegram', null, [
                'title' => $job['title'], 'audience' => $job['audience'],
                'recipients' => $total, 'sent' => $job['sent'],
                'failed' => $job['failed'], 'blocked' => $job['blocked'],
            ]);
            $msg = "پیام برای {$job['sent']} نفر از {$total} نفر ارسال شد";
            if ($job['blocked'] > 0) $msg .= " — {$job['blocked']} نفر ربات را بلاک/استارت نکرده‌اند";
            if ($job['failed'] > 0) $msg .= " — {$job['failed']} ناموفق";
            json_ok(['data' => [
                'total' => $total, 'sent' => $job['sent'], 'failed' => $job['failed'],
                'blocked' => $job['blocked'], 'done' => true, 'progress' => 100,
            ]], $msg);
        }

        json_ok(['data' => [
            'total' => $total, 'sent' => $job['sent'], 'failed' => $job['failed'],
            'blocked' => $job['blocked'], 'done' => false,
            'progress' => (int)floor($job['pos'] / max(1, $total) * 100),
        ]], 'در حال ارسال…');
    }

    /* ---- Below: new broadcast job (title/message required) ---- */

    $title = clean_str(req_str($in, 'title'));
    $message = clean_str(req_str($in, 'message'));
    $audience = req_str($in, 'audience', 'connected');
    // Targeted mode: {tag} → only this workshop's confirmed registrants
    $tag = strtoupper(clean_str(req_str($in, 'tag')));

    if ($title === '' || $message === '') json_err('عنوان و متن پیام الزامی است');
    if ($tag === '') {
        if (!in_array($audience, ['connected', 'members', 'all', 'admins', 'bot_admins'], true)) json_err('مخاطب نامعتبر است');
    }

    /* Chunked sending — a broadcast to hundreds of users cannot run in one PHP
       request (max_execution_time fatal → HTTP 500). The recipient list is
       resolved once, persisted in the session, and the frontend drains it. */

    $text = "<b>📢 {$title}</b>\n\n{$message}\n\n— انجمن علمی مهندسی برق علوم و تحقیقات";

    boot_session();

    // Start a new broadcast job
    {
        if ($tag !== '') {
            $ids = admin_workshop_registrant_ids($tag);
            if (!$ids) json_err('هیچ ثبت‌نامی تأییدشده با تلگرامِ متصل در این کارگاه یافت نشد', 404);
            $audience = 'workshop:' . $tag;
        } else {
            $ids = admin_audience_ids($audience);
            if (!$ids) json_err('هیچ کاربری در این مخاطب یافت نشد — ابتدا کاربران باید ربات را استارت کنند', 404);
        }

        // Validate message BEFORE locking it into the session job
        if (mb_strlen($text) > 3800) json_err('متن پیام بیش از حد طولانی است (حداکثر ~۳۸۰۰ کاراکتر)');

        $_SESSION['bc_job'] = [
            'ids'      => $ids,
            'text'     => $text,
            'pos'      => 0,
            'total'    => count($ids),
            'sent'     => 0,
            'failed'   => 0,
            'blocked'  => 0,
            'title'    => $title,
            'audience' => $audience,
            'admin_id' => (int)$admin['id'],
            'created'  => time(),
        ];

        $recipients = count($ids);
        json_ok(['data' => [
            'total'    => $recipients,
            'sent'     => 0,
            'failed'   => 0,
            'blocked'  => 0,
            'done'     => false,
            'progress' => 0,
        ]], "صف ارسال ساخته شد — {$recipients} گیرنده");
    }
}

function admin_action_upload(array $admin): void
{
    $kind = req_str($_GET, 'kind') ?: req_str($_POST, 'kind') ?: 'board';
    if ($kind === 'banner') {
        if (!admin_can($admin, 'banners')) json_err('دسترسی غیرمجاز', 403);
    } else {
        if (!admin_is_super($admin)) json_err('آپلود تصویر اعضا فقط توسط مدیر ارشد مجاز است', 403);
        $kind = 'board';
    }

    $path = admin_handle_image_upload($kind);
    if ($path === null) {
        json_err('فایلی برای آپلود انتخاب نشده است');
    }
    audit_log($admin['id'], 'upload', 'assets', $path, ['kind' => $kind]);
    json_ok(['data' => ['path' => $path]], 'تصویر با موفقیت آپلود شد');
}

function admin_action_sessions(array $admin): void
{
    if (!admin_can($admin, 'workshopreq')) json_err('دسترسی غیرمجاز', 403);

    $in = req_input();
    $action = req_str($in, 'sub');

    if ($action === 'list') {
        $tag = req_str($in, 'tag');
        $rows = db_all(
            "SELECT id, workshop_tag, session_number, session_date, session_time
             FROM workshop_sessions WHERE workshop_tag = ? ORDER BY session_number ASC",
            [$tag]
        );
        foreach ($rows as &$r) {
            $r['session_date_jalali'] = date_jalali($r['session_date']);
        }
        json_ok(['data' => ['sessions' => $rows]]);
    }

    if ($action === 'save') {
        $tag = req_str($in, 'tag');
        $count = min(20, max(1, (int)req_str($in, 'count', '1')));
        if ($tag === '') json_err('کد کارگاه الزامی است');

        // Clear existing sessions for this workshop
        db_exec("DELETE FROM workshop_sessions WHERE workshop_tag = ?", [$tag]);

        $created = 0;
        for ($i = 1; $i <= $count; $i++) {
            $date = req_str($in, 'date_' . $i);
            $time = req_str($in, 'time_' . $i);
            if ($date === '' || $time === '') continue;
            $greg = date_from_jalali($date);
            if ($greg === null) continue;
            $time = only_digits($time);
            if (!preg_match('/^\d{3,4}$/', $time)) continue;
            $time = substr($time, 0, 2) . ':' . substr($time, 2, 2) . ':00';
            db_exec(
                "INSERT INTO workshop_sessions (workshop_tag, session_number, session_date, session_time) VALUES (?, ?, ?, ?)",
                [$tag, $i, $greg, $time]
            );
            $created++;
        }
        audit_log($admin['id'], 'save_sessions', 'workshop_sessions', $tag, ['count' => $created]);
        json_ok(['data' => ['created' => $created]], "{$created} جلسه ثبت شد");
    }

    json_err('عملیات نامعتبر', 400);
}

function admin_action_cron(): void
{
    // Called by cron or manually — processes reminders, registration close, surveys
    // No auth needed for cron but validate a secret
    $cronSecret = (string)Env::get('CRON_SECRET', '');
    $got = req_str(req_input(), 'secret') ?: (string)($_GET['secret'] ?? '');
    if ($cronSecret === '' || !hash_equals($cronSecret, $got)) {
        json_err('Forbidden', 403);
    }

    $results = ['reminders_2h' => 0, 'reminders_75m' => 0, 'reg_closed' => 0, 'surveys_sent' => 0];
    $now = time();

    // 1) 2-hour reminders
    $sessions2h = db_all(
        "SELECT ws.id, ws.workshop_tag, ws.session_number, ws.session_date, ws.session_time, w.Title, w.GroupLink
         FROM workshop_sessions ws
         LEFT JOIN workshops w ON w.Tag = ws.workshop_tag
         WHERE ws.reminder_2h_sent = 0
         AND ws.session_date = CURDATE()"
    );
    foreach ($sessions2h as $s) {
        $ts = strtotime($s['session_date'] . ' ' . $s['session_time']);
        if ($ts === false) continue;
        $diff = $ts - $now;
        if ($diff > 0 && $diff <= 7200) { // within 2 hours
            admin_send_session_reminder($s, 2);
            db_exec("UPDATE workshop_sessions SET reminder_2h_sent = 1 WHERE id = ?", [$s['id']]);
            $results['reminders_2h']++;
        }
    }

    // 2) 75-minute reminders + registration close warning
    $sessions75m = db_all(
        "SELECT ws.id, ws.workshop_tag, ws.session_number, ws.session_date, ws.session_time, w.Title, w.GroupLink
         FROM workshop_sessions ws
         LEFT JOIN workshops w ON w.Tag = ws.workshop_tag
         WHERE ws.reminder_75m_sent = 0
         AND ws.session_date = CURDATE()"
    );
    foreach ($sessions75m as $s) {
        $ts = strtotime($s['session_date'] . ' ' . $s['session_time']);
        if ($ts === false) continue;
        $diff = $ts - $now;
        if ($diff > 0 && $diff <= 4500) { // within 75 minutes
            admin_send_session_reminder($s, 1);
            db_exec("UPDATE workshop_sessions SET reminder_75m_sent = 1 WHERE id = ?", [$s['id']]);
            $results['reminders_75m']++;
        }
        if ($s['session_number'] === 1 && $diff <= 0 && $diff > -300) { // first session just started
            // Close registration
            db_exec("UPDATE banners SET IsActive = 0 WHERE TargetTag = ? AND IsActive = 1", [$s['workshop_tag']]);
            $results['reg_closed']++;
        }
    }

    // 2b) Close registration when the registration window (EndDate) has passed — task 6, rule 2
    $closedByDate = db_exec(
        "UPDATE banners SET IsActive = 0
         WHERE IsActive = 1 AND EndDate IS NOT NULL AND EndDate < NOW()"
    );
    if ($closedByDate > 0) $results['reg_closed_enddate'] = $closedByDate;

    // 3) Auto-survey 4 hours after each session
    $sessionsSurvey = db_all(
        "SELECT ws.id, ws.workshop_tag, ws.session_number, ws.session_date, ws.session_time, w.Title
         FROM workshop_sessions ws
         LEFT JOIN workshops w ON w.Tag = ws.workshop_tag
         WHERE ws.survey_sent = 0
         AND ws.session_date <= CURDATE()"
    );
    foreach ($sessionsSurvey as $s) {
        $ts = strtotime($s['session_date'] . ' ' . $s['session_time']);
        if ($ts === false) continue;
        $diff = $now - $ts;
        if ($diff >= 14400) { // 4 hours after
            $results['surveys_sent'] += admin_send_auto_survey($s);
            db_exec("UPDATE workshop_sessions SET survey_sent = 1 WHERE id = ?", [$s['id']]);
        }
    }

    // 4) Auto-change registration status for completed workshops
    // PENDING → CONFIRMED when the last session's 4-hour survey window has passed
    $completedTags = db_all(
        "SELECT DISTINCT ws.workshop_tag
         FROM workshop_sessions ws
         WHERE ws.survey_sent = 1
         AND ws.workshop_tag NOT IN (
             SELECT DISTINCT ws2.workshop_tag FROM workshop_sessions ws2
             WHERE ws2.session_date >= CURDATE()
         )"
    );
    foreach ($completedTags as $ct) {
        $tag = $ct['workshop_tag'];
        $updated = db_exec(
            "UPDATE workshop_registrations SET Status = 'CONFIRMED' WHERE WorkshopTag = ? AND Status = 'PENDING'",
            [$tag]
        );
        if ($updated > 0) {
            $results['status_updated'] = ($results['status_updated'] ?? 0) + $updated;
            bot_log('STATUS: ' . $updated . ' registrations auto-confirmed for ' . $tag);
        }
    }

    // 5) Archive finished workshops → activities (draft, needs_review) + admin alarm
    $arch = admin_archive_finished_workshops();
    if ($arch['archived'] > 0) {
        $results['workshops_archived'] = $arch['archived'];
        $results['arch_notified'] = $arch['notified'];
        bot_log('ARCHIVE: ' . $arch['archived'] . ' finished workshops moved to activities');
    }

    json_ok(['data' => $results]);
}

function admin_send_session_reminder(array $session, int $hours): void
{
    $tag = $session['workshop_tag'];
    $title = $session['Title'] ?: $tag;
    $sessionNum = (int)$session['session_number'];
    $groupLink = $session['GroupLink'] ?: '';

    // Get confirmed registrants with telegram
    $chatIds = db_all(
        "SELECT DISTINCT wu.telegram_id
         FROM workshop_registrations r
         JOIN web_users wu ON (wu.phone = r.Phone OR wu.telegram_id IS NOT NULL)
         JOIN user_profiles up ON up.TelegramID = wu.telegram_id
         WHERE r.WorkshopTag = ? AND r.Status = 'CONFIRMED' AND wu.telegram_id IS NOT NULL AND wu.is_active = 1",
        [$tag]
    );

    $timeStr = date('H:i', strtotime($session['session_date'] . ' ' . $session['session_time']));
    $sessionLabel = $sessionNum > 1 ? "جلسه {$sessionNum}" : "اولین جلسه";

    if ($hours === 2) {
        $text = "⏰ <b>یادآوری کارگاه</b>\n\n";
        $text .= "📚 «{$title}» — {$sessionLabel}\n";
        $text .= "🕐 زمان: امروز ساعت {$timeStr}\n";
        $text .= "⏳ کارگاه تا ۲ ساعت دیگر شروع می‌شود!\n\n";
        if ($groupLink) $text .= "🔗 لینک کلاس:\n{$groupLink}\n\n";
        $text .= "⏰ به‌موقع در کلاس حضور پیدا کنید.";
    } else {
        $text = "🔔 <b>کارگاه به‌زودی شروع می‌شود!</b>\n\n";
        $text .= "📚 «{$title}» — {$sessionLabel}\n";
        $text .= "🕐 ساعت {$timeStr}\n\n";
        if ($groupLink) $text .= "🔗 لینک کلاس:\n{$groupLink}\n\n";
        $text .= "⚠️ این آخرین یادآوری است.";
    }

    $text .= "\n\n— انجمن علمی مهندسی برق علوم و تحقیقات";

    foreach ($chatIds as $c) {
        $chatId = (int)($c['telegram_id'] ?? 0);
        if ($chatId > 0) tg_send($chatId, $text);
        usleep(200000);
    }
}

function admin_send_auto_survey(array $session): int
{
    $tag = (string)($session['workshop_tag'] ?? '');
    if ($tag === '') return 0;
    $title = $session['Title'] ?: $tag;

    // Confirmed registrations not yet surveyed — same resolution as the manual flow
    $registrations = db_all(
        "SELECT r.ID, r.Phone, r.StudentID
         FROM workshop_registrations r
         LEFT JOIN workshop_surveys ws2 ON ws2.registration_id = r.ID
         WHERE r.WorkshopTag = ? AND r.Status = 'CONFIRMED' AND ws2.id IS NULL",
        [$tag]
    );

    $targets = [];
    foreach ($registrations as $r) {
        $tgId = member_tg_id('', null, $r['Phone'] ?? '');
        if ($tgId === null && !empty($r['StudentID'])) {
            $m = db_one("SELECT NationalID, TelegramID FROM members WHERE StudentID = ? LIMIT 1", [$r['StudentID']]);
            if ($m !== null) $tgId = member_tg_id($m['NationalID'], $m['TelegramID'], $r['Phone'] ?? '');
        }
        if ($tgId !== null) $targets[] = ['tg' => $tgId, 'reg_id' => (string)$r['ID']];
    }
    if (!$targets) return 0;

    // bot_create_survey inserts the survey row and sends proper
    // bot:survey:<registration_id>:<rating> buttons (answered via bot_survey_answer)
    return bot_create_survey($tag, $title, $targets);
}

function admin_action_cert_template(array $admin): void
{
    if (!admin_is_tech($admin)) json_err('فقط ادمین فنی', 403);
    cert_schema_ready();

    $action = req_str($_GET, 'sub') ?: req_str(req_input(), 'sub') ?: 'get';

    // Upload template image
    if ($action === 'upload') {
        if (empty($_FILES['template'])) json_err('فایل انتخاب نشده');
        $f = $_FILES['template'];
        if ($f['error'] !== UPLOAD_ERR_OK) json_err('خطا در آپلود');
        if ($f['size'] > 10 * 1024 * 1024) json_err('حجم بیش از ۱۰MB');
        $info = @getimagesize($f['tmp_name']);
        if ($info === false || !in_array($info['mime'], ['image/png', 'image/jpeg'])) json_err('فقط PNG یا JPG');
        $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
        $dir = BASE_PATH . '/assets/cert-template';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        move_uploaded_file($f['tmp_name'], $dir . '/template.' . $ext);
        setting_set('cert_template_file', 'assets/cert-template/template.' . $ext);
        audit_log($admin['id'], 'upload', 'cert_template', null);
        json_ok(['data' => ['path' => 'assets/cert-template/template.' . $ext, 'width' => $info[0], 'height' => $info[1]]], 'قالب آپلود شد');
    }

    // Upload secretary stamp/signature (transparent PNG recommended)
    if ($action === 'upload_signature') {
        if (empty($_FILES['signature'])) json_err('فایل انتخاب نشده');
        $f = $_FILES['signature'];
        if ($f['error'] !== UPLOAD_ERR_OK) json_err('خطا در آپلود');
        if ($f['size'] > 5 * 1024 * 1024) json_err('حجم بیش از ۵MB');
        $info = @getimagesize($f['tmp_name']);
        if ($info === false || !in_array($info['mime'], ['image/png', 'image/jpeg'])) json_err('فقط PNG یا JPG');
        $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
        $dir = BASE_PATH . '/assets/cert-template';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        move_uploaded_file($f['tmp_name'], $dir . '/signature.' . $ext);
        setting_set('cert_signature_file', 'assets/cert-template/signature.' . $ext);
        audit_log($admin['id'], 'upload', 'cert_signature', null);
        json_ok(['data' => ['path' => 'assets/cert-template/signature.' . $ext, 'width' => $info[0], 'height' => $info[1]]], 'مهر / امضا آپلود شد');
    }

    // Save field positions (x = LEFT edge of the English/LTR text · y = baseline · signature = square center + width)

    if ($action === 'save_fields') {
        $fields = req_input()['fields'] ?? [];
        if (!is_array($fields)) json_err('داده نامعتبر');
        $submitted = [];
        $hasEnabled = in_array('enabled', cert_columns('cert_template_fields'), true);
        foreach ($fields as $f) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($f['key'] ?? $f['field_key'] ?? '')));
            $label = clean_str((string)($f['label'] ?? $key));
            if ($key === '') continue;
            $submitted[] = $key;
            $color = (string)($f['color'] ?? $f['font_color'] ?? '#000000');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#000000';
            $pos = [(int)($f['x'] ?? $f['x_pos'] ?? 0), (int)($f['y'] ?? $f['y_pos'] ?? 0),
                    (int)($f['size'] ?? $f['font_size'] ?? 24), max(0, (int)($f['width'] ?? 0)), $color];
            $sql = $hasEnabled
                ? "INSERT INTO cert_template_fields (field_key, label, x_pos, y_pos, font_size, width, font_color, enabled)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                   ON DUPLICATE KEY UPDATE x_pos = VALUES(x_pos), y_pos = VALUES(y_pos), font_size = VALUES(font_size), width = VALUES(width), font_color = VALUES(font_color), label = VALUES(label), enabled = 1"
                : "INSERT INTO cert_template_fields (field_key, label, x_pos, y_pos, font_size, width, font_color)
                   VALUES (?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE x_pos = VALUES(x_pos), y_pos = VALUES(y_pos), font_size = VALUES(font_size), width = VALUES(width), font_color = VALUES(font_color), label = VALUES(label)";
            try {
                $db = db();
                $st = $db->prepare($sql);
                $st->execute(array_merge([$key, $label], $pos));
            } catch (Throwable $ex) { error_log('[certtpl.save_field] ' . $ex->getMessage()); }
        }
        // fields not in the submitted list become hidden (not deleted)
        if ($hasEnabled && $submitted !== []) {
            try {
                $ph = implode(',', array_fill(0, count($submitted), '?'));
                db_exec("UPDATE cert_template_fields SET enabled = 0 WHERE field_key NOT IN ($ph)", $submitted);
            } catch (Throwable $ex) {}
        } elseif ($hasEnabled && $submitted === []) {
            try { db_exec("UPDATE cert_template_fields SET enabled = 0"); } catch (Throwable $ex) {}
        }
        audit_log($admin['id'], 'update', 'cert_template_fields', null, ['count' => count($submitted)]);
        json_ok([], 'موقعیت فیلدها ذخیره شد');
    }

    // Hide one field from rendering (stays in DB, restorable)
    if ($action === 'hide_field') {
        $key = (string)req_str(req_input(), 'key');
        if (!cert_hide_field($key)) json_err('حذف فیلد ناموفق بود', 500);
        audit_log($admin['id'], 'hide_field', 'cert_template_fields', $key);
        json_ok(['data' => ['hidden' => cert_hidden_fields()]], 'فیلد از گواهی حذف شد');
    }

    // Restore a single hidden field (faded chip above the template)
    if ($action === 'restore_field') {
        $key = (string)req_str(req_input(), 'key');
        if (!cert_show_field($key)) json_err('بازیابی فیلد ناموفق بود', 500);
        audit_log($admin['id'], 'restore_field', 'cert_template_fields', $key);
        json_ok(['data' => ['hidden' => cert_hidden_fields()]], 'فیلد به قالب بازگردانده شد');
    }

    // Restore all hidden fields
    if ($action === 'restore_fields') {
        $n = cert_show_all_fields();
        audit_log($admin['id'], 'restore_fields', 'cert_template_fields', null, ['restored' => $n]);
        json_ok(['data' => ['restored' => $n]], 'فیلدهای مخفی بازگردانده شدند');
    }

    // Editor state + renderer status (font engine, coverage, template)
    if ($action === 'get') {
        $templatePath = setting('cert_template_file', '');
        $fields = cert_template_fields();
        $info = null;
        if ($templatePath !== '' && is_file(BASE_PATH . '/' . ltrim($templatePath, '/'))) {
            $gi = @getimagesize(BASE_PATH . '/' . ltrim($templatePath, '/'));
            if ($gi) $info = ['path' => $templatePath, 'width' => $gi[0], 'height' => $gi[1]];
        }
        $sigInfo = null;
        $sigRel = (string)setting('cert_signature_file', '');
        if ($sigRel !== '' && is_file(BASE_PATH . '/' . ltrim($sigRel, '/'))) {
            $gi = @getimagesize(BASE_PATH . '/' . ltrim($sigRel, '/'));
            if ($gi) $sigInfo = ['path' => $sigRel, 'width' => $gi[0], 'height' => $gi[1]];
        }
        json_ok(['data' => [
            'template' => $info,
            'signature' => $sigInfo,
            'fields'   => $fields,
            'hidden'   => cert_hidden_fields(),
            'render'   => cert_render_status(),
            'secretary' => (function () {
                $s = cert_secretary();
                return $s === null ? null : [
                    'id' => (int)$s['id'], 'name' => (string)$s['name'],
                    'name_en' => (string)($s['name_en'] ?? ''), 'position' => (string)$s['position'],
                    'has_signature' => trim((string)($s['signature'] ?? '')) !== '',
                ];
            })(),
        ]]);
    }

    // Sample certificate (dummy data, real pipeline)
    if ($action === 'sample') {
        try {
            $err = null;
            $png = cert_sample_png($err);
            if ($png === null) {
                error_log('[certtpl.sample] failed: ' . ($err ?: 'unknown'));
                json_err($err ?: 'تولید نمونه ناموفق بود', 503);
            }
            json_ok(['data' => ['png_base64' => base64_encode($png)]], 'نمونه تولید شد');
        } catch (Throwable $ex) {

            error_log('[certtpl.sample] ' . $ex->getMessage());
            json_err('تولید نمونه ناموفق بود: ' . mb_substr($ex->getMessage(), 0, 140), 503);
        }
    }

    // Self-test sheet: all mandatory Persian/RTL strings through the pipeline
    if ($action === 'selftest') {
        try {
            $png = cert_selftest_png();
            if ($png === null) json_err('تست خودکار ناموفق بود (فونت/FreeType)', 503);
            json_ok(['data' => ['png_base64' => base64_encode($png), 'render' => cert_render_status()]], 'تست متن انجام شد');
        } catch (Throwable $ex) {
            error_log('[certtpl.selftest] ' . $ex->getMessage());
            json_err('تست ناموفق بود: ' . mb_substr($ex->getMessage(), 0, 140), 503);
        }
    }

    // Glyph sheet: base + 4 presentation forms per letter, labeled by codepoint
    if ($action === 'glyphsheet') {
        try {
            $png = cert_glyph_sheet_png();
            if ($png === null) json_err('برگه گلیف ناموفق بود', 503);
            json_ok(['data' => ['png_base64' => base64_encode($png), 'render' => cert_render_status()]], 'برگه گلیف ساخته شد');
        } catch (Throwable $ex) {
            error_log('[certtpl.glyphsheet] ' . $ex->getMessage());
            json_err('برگه گلیف ناموفق بود: ' . mb_substr($ex->getMessage(), 0, 140), 503);
        }
    }

    // Preview any certificate for the admin (same renderer as public)
    if ($action === 'preview_cert') {
        $id = req_str($_GET, 'id');
        if (!preg_match('/^[A-Za-z0-9\-]{3,50}$/', $id)) json_err('کد نامعتبر');
        if (!admin_can($admin, 'certificates')) json_err('دسترسی غیرمجاز', 403);
        cert_stream_png($id, false);
    }

    json_err('عملیات نامعتبر', 400);
}
function admin_action_snapshots(array $admin): void
{
    if (!admin_is_tech($admin) && !admin_is_super($admin)) json_err('دسترسی غیرمجاز', 403);

    $in = req_input();
    $action = req_str($in, 'sub') ?: req_str($_GET, 'sub') ?: 'list';

    /* ============================================================
       Trashbin (task 6) — soft-delete vault for restores
       ============================================================ */

    // Ensure the trashbin table + server folder exist (auto-migrate, once)
    if (!admin_trashbin_ready()) json_err('ساخت سبد بازیافت ناموفق بود', 500);

    // List deleted items (files + records)
    if ($action === 'trash_list') {
        db_exec("DELETE FROM trashbin WHERE deleted_at < NOW() - INTERVAL 30 DAY");
        $rows = db_all(
            "SELECT t.*, u.username AS admin_username
             FROM trashbin t
             LEFT JOIN users u ON u.id = t.admin_id
             ORDER BY t.id DESC LIMIT 300"
        );
        foreach ($rows as &$r) {
            $r['is_file'] = $r['file_path'] !== null && $r['file_path'] !== '';
            $r['payload'] = json_decode((string)$r['payload'], true);
        }
        json_ok(['data' => ['items' => $rows]]);
    }

    // Restore a record (or file record) from the trashbin
    if ($action === 'trash_restore') {
        $tid = (int)req_str($in, 'id');
        $item = db_one("SELECT * FROM trashbin WHERE id = ? LIMIT 1", [$tid]);
        if ($item === null) json_err('مورد یافت نشد', 404);

        $table = $item['table_name'];
        $cfg = admin_entities()[$table] ?? null;
        if ($cfg === null) json_err('جدول شناخته‌شده نیست');

        $idCol = $cfg['id'];
        $exists = db_val("SELECT COUNT(*) FROM {$table} WHERE {$idCol} = ?", [$item['record_id']]);
        if ($exists > 0) json_err('رکوردی با همین شناسه وجود دارد — بازگردانی ممکن نیست');

        // 1) Restore physical file if present
        if (!empty($item['file_path'])) {
            $trashFile = BASE_PATH . '/storage/trashbin/' . basename($item['file_path']);
            $dest = BASE_PATH . '/' . $item['file_path'];
            if (is_file($trashFile)) {
                $destDir = dirname($dest);
                if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
                if (!@copy($trashFile, $dest)) json_err('بازگردانی فایل ناموفق بود');
            }
        }

        // 2) Restore DB record
        $data = json_decode((string)$item['payload'], true);
        if (is_array($data)) {
            unset($data[$idCol]);
            $cols = array_keys($data);
            $ph = implode(', ', array_fill(0, count($cols), '?'));
            db_exec(
                "INSERT INTO `{$table}` (`{$idCol}`, `" . implode('`, `', $cols) . "`) VALUES (?, {$ph})",
                array_merge([$item['record_id']], array_values($data))
            );
        }

        db_exec("DELETE FROM trashbin WHERE id = ?", [$tid]);
        audit_log($admin['id'], 'trash_restore', $table, (string)$item['record_id']);
        json_ok([], 'مورد با موفقیت بازگردانی شد');
    }

    // Permanently purge one item
    if ($action === 'trash_purge') {
        $tid = (int)req_str($in, 'id');
        $item = db_one("SELECT * FROM trashbin WHERE id = ? LIMIT 1", [$tid]);
        if ($item === null) json_err('مورد یافت نشد', 404);
        if (!empty($item['file_path'])) {
            $trashFile = BASE_PATH . '/storage/trashbin/' . basename($item['file_path']);
            if (is_file($trashFile)) @unlink($trashFile);
        }
        db_exec("DELETE FROM trashbin WHERE id = ?", [$tid]);
        audit_log($admin['id'], 'trash_purge', $item['table_name'], (string)$item['record_id']);
        json_ok([], 'برای همیشه حذف شد');
    }

    if ($action === 'list') {
        // Cleanup expired (30 days)
        db_exec("DELETE FROM activity_snapshots WHERE expires_at < NOW()");

        $rows = db_all(
            "SELECT s.*, u.username AS admin_username
             FROM activity_snapshots s
             LEFT JOIN users u ON u.id = s.admin_id
             ORDER BY s.id DESC LIMIT 100"
        );
        json_ok(['data' => ['snapshots' => $rows]]);
    }

    if ($action === 'restore') {
        $snapId = (int)req_str($in, 'id');
        $snap = db_one("SELECT * FROM activity_snapshots WHERE id = ? LIMIT 1", [$snapId]);
        if ($snap === null) json_err('اسنپ‌شات یافت نشد', 404);

        $data = json_decode($snap['snapshot_data'], true);
        if (!is_array($data)) json_err('داده اسنپ‌شات نامعتبر است');

        $table = $snap['table_name'];
        $recordId = $snap['record_id'];
        $cfg = admin_entities()[$table] ?? null;
        if ($cfg === null) json_err('جدول شناخته‌شده نیست');

        $idCol = $cfg['id'];
        // Check if record already exists
        $exists = db_val("SELECT COUNT(*) FROM {$table} WHERE {$idCol} = ?", [$recordId]);
        if ($exists > 0) json_err('این رکورد قبلاً بازیابی شده است');

        // Remove id from data (let DB auto-increment or use saved id)
        unset($data[$idCol]);
        $cols = array_keys($data);
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $idColQuoted = '`' . $idCol . '`';
        db_exec(
            "INSERT INTO `{$table}` ({$idColQuoted}, `" . implode('`, `', $cols) . "`) VALUES (?, {$ph})",
            array_merge([$recordId], array_values($data))
        );

        db_exec("DELETE FROM activity_snapshots WHERE id = ?", [$snapId]);
        audit_log($admin['id'], 'restore', $table, (string)$recordId);
        json_ok([], 'رکورد با موفقیت بازیابی شد');
    }

    json_err('عملیات نامعتبر', 400);
}

/* ============================================================
   Trashbin helpers (task 6)
   ============================================================ */

/** Create trashbin table + storage folder on demand. Returns true when ready. */
function admin_trashbin_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        db_exec(
            "CREATE TABLE IF NOT EXISTS `trashbin` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `admin_id` INT NULL DEFAULT NULL,
              `table_name` VARCHAR(50) NOT NULL,
              `record_id` VARCHAR(50) NOT NULL,
              `payload` MEDIUMTEXT NULL,
              `file_path` VARCHAR(500) NULL DEFAULT NULL,
              `deleted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_tb_table` (`table_name`, `record_id`),
              KEY `idx_tb_deleted` (`deleted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci"
        );
        $dir = BASE_PATH . '/storage/trashbin';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ready = is_dir($dir);
    } catch (Throwable $ex) {
        error_log('[trashbin_ready] ' . $ex->getMessage());
        $ready = false;
    }
    return $ready;
}

/**
 * Move a record (and, if it has a local file, the file itself) into the trashbin.
 * Called from admin_action_delete before the hard DELETE.
 */
function admin_trashbin_capture(array $admin, string $entityKey, array $cfg, string $id, array $record): void
{
    if (!admin_trashbin_ready()) return;

    $filePath = null;
    // Image-type entities keep a local file — move it to the vault instead of orphaning it
    foreach (['FileLink', 'image_url', 'Image', 'image', 'photo', 'Logo'] as $col) {
        if (!empty($record[$col]) && is_string($record[$col])) {
            $rel = $record[$col];
            if (strpos($rel, 'http') === 0) break; // remote URL — nothing to move
            $full = BASE_PATH . '/' . ltrim($rel, '/');
            if (is_file($full)) {
                $name = $entityKey . '_' . preg_replace('/[^\w\.\-]/', '_', $id) . '_' . basename($rel);
                $dest = BASE_PATH . '/storage/trashbin/' . $name;
                if (@rename($full, $dest)) {
                    $filePath = 'storage/trashbin/' . $name;
                }
            }
            break;
        }
    }

    try {
        db_exec(
            "INSERT INTO trashbin (admin_id, table_name, record_id, payload, file_path) VALUES (?, ?, ?, ?, ?)",
            [$admin['id'], $entityKey, $id, json_encode($record, JSON_UNESCAPED_UNICODE), $filePath]
        );
    } catch (Throwable $ex) {
        error_log('[trashbin_capture] ' . $ex->getMessage());
    }
}
function admin_action_card_template(array $admin): void
{
    if (!admin_is_tech($admin)) json_err('فقط ادمین فنی', 403);

    $action = req_str($_GET, 'sub') ?: req_str(req_input(), 'sub') ?: 'get';

    if ($action === 'upload') {
        if (empty($_FILES['template'])) json_err('فایل انتخاب نشده');
        $f = $_FILES['template'];
        if ($f['error'] !== UPLOAD_ERR_OK) json_err('خطا در آپلود');
        if ($f['size'] > 10 * 1024 * 1024) json_err('حجم بیش از ۱۰MB');
        $info = @getimagesize($f['tmp_name']);
        if ($info === false || !in_array($info['mime'], ['image/png', 'image/jpeg'])) json_err('فقط PNG یا JPG');
        $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
        $dir = BASE_PATH . '/assets/card-template';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        move_uploaded_file($f['tmp_name'], $dir . '/template.' . $ext);
        setting_set('card_template_file', 'assets/card-template/template.' . $ext);
        audit_log($admin['id'], 'upload', 'card_template', null);
        json_ok(['data' => ['path' => 'assets/card-template/template.' . $ext, 'width' => $info[0], 'height' => $info[1]]], 'قالب کارت آپلود شد');
    }

    if ($action === 'save_fields') {
        $in = req_input();
        $fields = $in['fields'] ?? [];
        if (!is_array($fields)) json_err('داده نامعتبر');
        setting_set('card_template_fields', json_encode($fields, JSON_UNESCAPED_UNICODE));
        audit_log($admin['id'], 'update', 'card_template_fields', null);
        json_ok([], 'موقعیت فیلدهای کارت ذخیره شد');
    }

    if ($action === 'get') {
        $templatePath = setting('card_template_file', '');
        $fields = json_decode((string)setting('card_template_fields', '[]'), true) ?: [];
        $info = null;
        if ($templatePath !== '') {
            $full = BASE_PATH . '/' . $templatePath;
            if (file_exists($full)) {
                $gi = @getimagesize($full);
                if ($gi) $info = ['path' => $templatePath, 'width' => $gi[0], 'height' => $gi[1]];
            }
        }
        json_ok(['data' => ['template' => $info, 'fields' => $fields]]);
    }

    json_err('عملیات نامعتبر', 400);
}

function card_generate_png(array $member): ?string
{
    $err = null;
    $bytes = card_render_png($member, $err);
    if ($bytes === null) { error_log('[card] ' . ($err ?: 'render failed')); return null; }
    $outDir = BASE_PATH . '/assets/cards';
    if (!is_dir($outDir)) @mkdir($outDir, 0755, true);
    $outFile = $outDir . '/card_' . preg_replace('/[^0-9A-Za-z]/', '', (string)($member['NationalID'] ?? gen_id('MEM'))) . '.png';
    if (@file_put_contents($outFile, $bytes) === false) return null;
    db_exec("UPDATE members SET FileLink = ? WHERE NationalID = ?",
        ['assets/cards/' . basename($outFile), $member['NationalID'] ?? '']);
    return 'assets/cards/' . basename($outFile);
}

function admin_action_card_for_member(array $admin): void
{
    if (!admin_can($admin, 'members')) json_err('دسترسی غیرمجاز', 403);

    $in = req_input();
    $memberId = (int)req_str($in, 'id');
    if (!$memberId) json_err('شناسه عضو الزامی است');

    $member = db_one("SELECT ID, NameFa, NameEn, NationalID, StudentID, IssueDate, ExpDate, Points FROM members WHERE ID = ? LIMIT 1", [$memberId]);
    if ($member === null) json_err('عضو یافت نشد', 404);

    $path = card_generate_png($member);
    if ($path === null) json_err('تولید کارت ناموفق بود — قالب کارت و فونت را بررسی کنید');

    audit_log($admin['id'], 'generate_card', 'members', (string)$memberId);
    json_ok(['data' => ['path' => $path]], 'کارت عضویت تولید شد');
}
function admin_error_codes(array $admin): void
{
    if (!admin_is_tech($admin)) {
        json_err('فقط ادمین فنی به کدهای خطا دسترسی دارد', 403);
    }

    // Save edited overrides (title/desc/fix/icon) — single code OR bulk «items» (ذخیره همه)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = req_input();

        if (isset($in['items']) && is_array($in['items'])) {
            $overrides = json_decode((string)setting('error_code_overrides', '[]'), true) ?: [];
            $n = 0; $bad = 0;
            foreach ($in['items'] as $it) {
                if (!is_array($it)) { $bad++; continue; }
                $bcode = (int)($it['code'] ?? 0);
                $btitle = clean_str((string)($it['title'] ?? ''));
                if (!isset(error_codes()[$bcode]) || $btitle === '') { $bad++; continue; }
                $ov = [
                    'title' => $btitle,
                    'desc'  => clean_str((string)($it['desc'] ?? '')),
                    'fix'   => clean_str((string)($it['fix'] ?? '')),
                ];
                $bicon = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($it['icon'] ?? '')));
                if ($bicon !== '') $ov['icon'] = $bicon;
                $overrides[$bcode] = $ov;
                $n++;
            }
            setting_set('error_code_overrides', json_encode($overrides, JSON_UNESCAPED_UNICODE));
            audit_log($admin['id'], 'bulk_update', 'error_codes', null, ['count' => $n]);
            json_ok(['data' => ['count' => $n]],
                $n . ' کد خطا ذخیره شد' . ($bad > 0 ? ' (' . $bad . ' مورد نامعتبر رد شد)' : ''));
        }

        $code = (int)req_str($in, 'code');
        $title = clean_str(req_str($in, 'title'));
        $desc = clean_str(req_str($in, 'desc'));
        $fix = clean_str(req_str($in, 'fix'));
        $icon = preg_replace('/[^a-z0-9\-]/', '', strtolower(req_str($in, 'icon'))) ?: null;

        if (!isset(error_codes()[$code])) json_err('کد نامعتبر است');
        if ($title === '') json_err('عنوان الزامی است');

        $overrides = json_decode((string)setting('error_code_overrides', '[]'), true) ?: [];
        $overrides[$code] = ['title' => $title, 'desc' => $desc, 'fix' => $fix];
        if ($icon !== null) $overrides[$code]['icon'] = $icon;
        setting_set('error_code_overrides', json_encode($overrides, JSON_UNESCAPED_UNICODE));
        audit_log($admin['id'], 'update', 'error_codes', (string)$code);
        json_ok([], 'متن کد ' . $code . ' ذخیره شد');
    }

    // GET: defaults + overrides + resolved icon per code
    $codes = error_codes();
    $icons = error_code_icons();
    $overrides = json_decode((string)setting('error_code_overrides', '[]'), true) ?: [];
    foreach ($overrides as $code => $ov) {
        if (isset($codes[$code])) {
            $codes[$code] = [$ov['title'] ?? $codes[$code][0], $ov['desc'] ?? $codes[$code][1], $ov['fix'] ?? $codes[$code][2]];
            if (!empty($ov['icon'])) $icons[$code] = (string)$ov['icon'];
        }
    }
    ksort($codes);
    $out = [];
    foreach ($codes as $code => $t) {
        $out[$code] = [$t[0], $t[1], $t[2], $icons[$code] ?? 'circle-help'];
    }
    json_ok(['data' => ['codes' => $out]]);
}

/* ----------------------------------------------------------------
   Site error log viewer (tech admin only).
   Reads the tail of configured PHP error_log files, parses
   `[timestamp] message` entries, returns newest-first with Jalali
   + local time. Read-only; never echoes raw secrets.
   ---------------------------------------------------------------- */

function admin_error_logs(array $admin): void
{
    if (!admin_is_tech($admin)) {
        json_err('فقط ادمین فنی به لاگ خطاها دسترسی دارد', 403);
    }

    $files = [];
    $iniLog = (string)ini_get('error_log');
    $known = array_values(array_unique(array_filter([
        BASE_PATH . '/storage/logs/error.log',
        $iniLog !== '' && is_file($iniLog) ? $iniLog : '',
        BASE_PATH . '/error_log',
    ])));

    $q = mb_strtolower(trim(req_str($_GET, 'q')));
    $limit = min(500, max(25, (int)($_GET['limit'] ?? 150)));

    $items = [];
    foreach ($known as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        $size = (int)filesize($path);
        if ($size === 0) { $files[] = ['name' => basename($path), 'size' => 0]; continue; }
        $files[] = ['name' => basename($path), 'size' => $size];

        // read at most last 2 MB
        $fp = @fopen($path, 'rb');
        if ($fp === false) continue;
        if ($size > 2 * 1024 * 1024) fseek($fp, $size - 2 * 1024 * 1024);
        $chunk = (string)stream_get_contents($fp);
        fclose($fp);

        // PHP error_log lines start with [date time zone]
        $lines = preg_split('/\r?\n(?=\[)/', $chunk) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '[') continue;
            if (preg_match('/^\[([^\]]+)\]\s*(.*)$/su', $line, $m)) {
                $ts = $m[1];
                $msg = $m[2];
            } else {
                $ts = '';
                $msg = $line;
            }
            if ($q !== '' && !str_contains(mb_strtolower($msg), $q) && !str_contains(mb_strtolower($ts), $q)) continue;
            $epoch = strtotime($ts) ?: null;
            $items[] = [
                'file'  => basename($path),
                'time'  => $epoch ? date('Y-m-d H:i:s', $epoch) : $ts,
                'jtime' => $epoch ? date_jalali(date('Y-m-d', $epoch)) . ' ' . ($epoch ? date('H:i:s', $epoch) : '') : '',
                'level' => preg_match('/\b(fatal|error|uncaught|exception)\b/i', $msg) ? 'error'
                          : (preg_match('/\b(warning|deprecated|notice)\b/i', $msg) ? 'warn' : 'info'),
                'msg'   => mb_substr($msg, 0, 600),
            ];
            if (count($items) > $limit * 2) break 2;
        }
    }

    // newest first, cap
    usort($items, static function (array $a, array $b): int {
        return strcmp($b['time'], $a['time']);
    });
    $items = array_slice($items, 0, $limit);

    audit_log($admin['id'], 'view', 'error_logs', null, ['count' => count($items), 'q' => $q !== '']);
    json_ok(['data' => [
        'items'  => $items,
        'files'  => $files,
        'total'  => count($items),
        'query'  => $q,
        'hint'   => 'برای جستجو در متن خطا، عبارت را بنویسید (مثلاً [site:login] یا Fatal).',
    ]]);
}

function admin_action_setup_profile(array $admin): void
{
    $in = req_input();
    $userId = (int)$admin['id'];

    try {
        $user = db_one("SELECT password, must_setup FROM users WHERE id = ? AND is_active = 1 LIMIT 1", [$userId]);
    } catch (Throwable $ex) {
        json_err('جدول users نیاز به بروزرسانی دارد', 500);
    }
    if ($user === null) json_err('کاربر یافت نشد', 404);
    if (!(int)$user['must_setup']) json_err('راه‌اندازی اولیه قبلاً انجام شده است');

    $currentPass = req_str($in, 'current_password');
    $newPass = req_str($in, 'new_password');
    $telegramId = preg_replace('/\D/', '', req_str($in, 'telegram_id'));

    if (!password_verify($currentPass, $user['password'])) json_err('رمز عبور فعلی اشتباه است');
    if (strlen($newPass) < 8) json_err('رمز جدید باید حداقل ۸ کاراکتر باشد');
    if ($telegramId !== '' && strlen($telegramId) < 5) json_err('آیدی تلگرام نامعتبر است');

    db_exec(
        "UPDATE users SET password = ?, telegram_id = ?, must_setup = 0 WHERE id = ?",
        [password_hash($newPass, PASSWORD_DEFAULT), $telegramId ?: null, $userId]
    );

    audit_log($userId, 'setup_profile', 'users', (string)$userId);
    json_ok([], 'پروفایل شما با موفقیت تکمیل شد');
}
function admin_action_bulk_import(array $admin): void
{
    $kind = req_str($_GET, 'kind') ?: req_str($_POST, 'kind') ?: 'certificates';
    if (!in_array($kind, ['certificates', 'class_links'], true)) json_err('نوع واردسازی نامعتبر');
    if (!admin_can($admin, $kind === 'certificates' ? 'certificates' : 'class_links')) json_err('دسترسی غیرمجاز', 403);

    if (empty($_FILES['xlsx']) || ($_FILES['xlsx']['error'] ?? 4) !== UPLOAD_ERR_OK) {
        json_err('فایل اکسل انتخاب نشده یا معتبر نیست');
    }
    $f = $_FILES['xlsx'];
    if ($f['size'] > 5 * 1024 * 1024) json_err('حجم فایل بیش از ۵ مگابایت است');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'], true)) json_err('فقط فایل xlsx یا xls مجاز است');

    if (!class_exists('ZipArchive')) json_err('افزونه ZipArchive روی سرور فعال نیست — با پشتیبانی هاست تماس بگیرید');

    $zip = new ZipArchive();
    if ($zip->open($f['tmp_name']) !== true) json_err('خواندن فایل ناموفق بود — فایل xlsx معتبر نیست');

    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                $txt = '';
                foreach ($si->t as $t) {
                    $txt .= (string)$t;
                }
                if (isset($si->r)) {
                    foreach ($si->r as $r) {
                        $txt .= (string)$r->t;
                    }
                }
                $shared[] = $txt;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) json_err('کاربرگی در فایل یافت نشد');

    $sheet = @simplexml_load_string($sheetXml);
    if ($sheet === false) json_err('ساختار فایل نامعتبر است');

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            preg_match('/^([A-Z]+)/', $ref, $mm);
            $col = $mm[1] ?? '';
            $type = (string)$c['t'];
            $val = '';
            if ($type === 's') {
                $v = (int)($c->v ?? 0);
                $val = $shared[$v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string)$c->is->t;
            } else {
                $val = (string)($c->v ?? '');
            }
            $cells[$col] = trim($val);
        }
        $ordered = [];
        foreach (['A','B','C','D','E','F','G','H'] as $colLetter) {
            $ordered[] = $cells[$colLetter] ?? '';
        }
        if (implode('', $ordered) !== '') {
            $rows[] = $ordered;
        }
    }

    if (count($rows) < 2) json_err('فایل خالی است یا فقط سرستون دارد');

    $header = array_map(function ($h) {
        return trim(mb_strtolower($h));
    }, array_shift($rows));

    $imported = 0;
    $skipped = 0;
    $errors = [];

    if ($kind === 'certificates') {
        cert_schema_ready();
        $map = [
            'namefa'   => ['نام فارسی', 'نام'],
            'nameen'   => ['نام انگلیسی'],
            'national' => ['کد ملی'],
            'certid'   => ['شماره گواهینامه', 'کد گواهینامه'],
            'course'   => ['عنوان دوره', 'دوره'],
            'certtype' => ['نوع گواهی', 'نوع گواهینامه', 'توضیح', 'توضیحات'],
            'exp'      => ['تاریخ انقضا', 'انقضا'],
        ];
        $idx = [];
        foreach ($map as $key => $aliases) {
            foreach ($aliases as $alias) {
                $pos = array_search($alias, $header);
                if ($pos !== false) {
                    $idx[$key] = $pos;
                    break;
                }
            }
        }
        if (!isset($idx['namefa'], $idx['national'], $idx['course'])) {
            json_err('ستون‌های الزامی: نام فارسی، کد ملی، عنوان دوره — سرستون‌ها را بررسی کنید');
        }

        foreach ($rows as $i => $r) {
            $line = $i + 2;
            $nameFa = clean_str($r[$idx['namefa']]);
            $national = only_digits($r[$idx['national']]);
            $course = clean_str($r[$idx['course']]);
            $nameEn = isset($idx['nameen']) ? clean_str($r[$idx['nameen']]) : '';
            $certId = isset($idx['certid']) ? clean_str($r[$idx['certid']]) : '';
            $certType = isset($idx['certtype']) ? clean_str($r[$idx['certtype']]) : '';
            $expRaw = isset($idx['exp']) ? $r[$idx['exp']] : '';
            $exp = $expRaw !== '' ? date_from_jalali($expRaw) : null;

            if ($nameFa === '' || $national === '' || $course === '') {
                $errors[] = 'سطر ' . $line . ': فیلد الزامی خالی';
                $skipped++;
                continue;
            }
            if ($certId === '') {
                $certId = gen_cert_id();
            }

            $dup = db_one("SELECT ID FROM certificates WHERE ID = ? LIMIT 1", [$certId]);
            if ($dup !== null) {
                $errors[] = 'سطر ' . $line . ': کد ' . $certId . ' تکراری';
                $skipped++;
                continue;
            }

            db_exec(
                "INSERT INTO certificates (ID, NameEn, NameFa, NationalID, Course, CertType, IssueDate, ExpDate)
                 VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?)",
                [$certId, $nameEn ?: $nameFa, $nameFa, $national, $course, $certType !== '' ? $certType : null, $exp]
            );
            admin_notify_certificate(['ID' => $certId, 'NationalID' => $national, 'Course' => $course, 'ExpDate' => $exp]);
            $imported++;
        }
    } else {
        $map = [
            'course'  => ['نام درس', 'درس'],
            'teacher' => ['استاد', 'نام استاد'],
            'day'     => ['روز', 'روز کلاس'],
            'time'    => ['ساعت', 'ساعت کلاس'],
            'link'    => ['لینک', 'لینک گروه'],
        ];
        $idx = [];
        foreach ($map as $key => $aliases) {
            foreach ($aliases as $alias) {
                $pos = array_search($alias, $header);
                if ($pos !== false) {
                    $idx[$key] = $pos;
                    break;
                }
            }
        }
        if (!isset($idx['course'], $idx['teacher'], $idx['link'])) {
            json_err('ستون‌های الزامی: نام درس، استاد، لینک — سرستون‌ها را بررسی کنید');
        }

        foreach ($rows as $i => $r) {
            $line = $i + 2;
            $course = clean_str($r[$idx['course']]);
            $teacher = clean_str($r[$idx['teacher']]);
            $link = trim($r[$idx['link']]);
            $day = isset($idx['day']) ? clean_str($r[$idx['day']]) : '';
            $time = isset($idx['time']) ? clean_str($r[$idx['time']]) : '';

            if ($course === '' || $teacher === '' || $link === '') {
                $errors[] = 'سطر ' . $line . ': فیلد الزامی خالی';
                $skipped++;
                continue;
            }
            if (!preg_match('#^https://#i', $link)) {
                $errors[] = 'سطر ' . $line . ': لینک باید https باشد';
                $skipped++;
                continue;
            }

            $dup = db_one("SELECT id FROM class_links WHERE course_name = ? AND teacher = ? LIMIT 1", [$course, $teacher]);
            if ($dup !== null) {
                $errors[] = 'سطر ' . $line . ': ترکیب درس/استاد تکراری';
                $skipped++;
                continue;
            }

            db_exec(
                "INSERT INTO class_links (course_name, teacher, class_day, class_time, link) VALUES (?, ?, ?, ?, ?)",
                [$course, $teacher, $day ?: null, $time ?: null, $link]
            );
            $imported++;
        }
    }

    audit_log($admin['id'], 'bulk_import', $kind, null, ['imported' => $imported, 'skipped' => $skipped]);
    json_ok(
        ['data' => ['imported' => $imported, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 10)]],
        $imported . ' رکورد وارد شد' . ($skipped > 0 ? ' — ' . $skipped . ' رد شد' : '')
    );
}

function admin_action_cert_for_workshop(array $admin): void
{
    if (!admin_can($admin, 'certificates')) json_err('دسترسی غیرمجاز', 403);
    cert_schema_ready();

    $in = req_input();
    $tag = clean_str(req_str($in, 'tag'));
    if ($tag === '') json_err('کد کارگاه الزامی است');
    $certType = clean_str(req_str($in, 'cert_type'));

    $registrations = db_all(
        "SELECT r.FullName, r.Phone, r.StudentID, r.WorkshopTag, w.Title
         FROM workshop_registrations r
         LEFT JOIN workshops w ON w.Tag = r.WorkshopTag
         WHERE r.WorkshopTag = ? AND r.Status = 'CONFIRMED'",
        [$tag]
    );

    if (!$registrations) json_err('ثبت‌نامی تأییدشده‌ای برای این کارگاه یافت نشد');

    $imported = 0;
    $skipped = 0;

    foreach ($registrations as $r) {
        $certId = gen_cert_id();
        $dup = db_one("SELECT ID FROM certificates WHERE ID = ? LIMIT 1", [$certId]);
        if ($dup !== null) { $skipped++; continue; }

        db_exec(
            "INSERT INTO certificates (ID, NameEn, NameFa, NationalID, Course, CertType, IssueDate)
             VALUES (?, ?, ?, ?, ?, ?, CURDATE())",
            [$certId, $r['FullName'], $r['FullName'], '', $r['Title'] ?: ('کارگاه ' . $tag), $certType !== '' ? $certType : null]
        );
        admin_notify_certificate(['ID' => $certId, 'NationalID' => '', 'Course' => $r['Title'] ?: 'کارگاه', 'ExpDate' => null]);
        $imported++;
    }

    audit_log($admin['id'], 'cert_for_workshop', 'certificates', $tag, ['imported' => $imported]);
    json_ok(['data' => ['imported' => $imported, 'skipped' => $skipped]],
        $imported . ' گواهینامه صادر شد' . ($skipped > 0 ? ' — ' . $skipped . ' رد شد' : ''));
}

function admin_action_send_survey(array $admin): void
{
    if (!admin_can($admin, 'registrations')) json_err('دسترسی غیرمجاز', 403);

    $in = req_input();
    $tag = clean_str(req_str($in, 'tag'));
    if ($tag === '') json_err('کد کارگاه الزامی است');

    $workshop = db_one("SELECT Title FROM workshops WHERE Tag = ? LIMIT 1", [$tag]);
    $title = $workshop['Title'] ?? ('کارگاه ' . $tag);

    // Confirmed registrations — resolve telegram via phone/profiles, with member fallback by StudentID
    $registrations = db_all(
        "SELECT r.ID, r.Phone, r.StudentID
         FROM workshop_registrations r
         WHERE r.WorkshopTag = ? AND r.Status = 'CONFIRMED'",
        [$tag]
    );
    if (!$registrations) json_err('ثبت‌نامی تأییدشده‌ای یافت نشد');

    // Skip registrations already surveyed for this workshop
    $done = [];
    foreach (db_all("SELECT DISTINCT registration_id FROM workshop_surveys WHERE workshop_tag = ?", [$tag]) as $d) {
        $done[(string)$d['registration_id']] = true;
    }

    $targets = [];
    $noTg = 0;
    $already = 0;
    foreach ($registrations as $r) {
        $regId = (string)$r['ID'];
        if (isset($done[$regId])) { $already++; continue; }

        $tgId = member_tg_id('', null, $r['Phone'] ?? '');
        if ($tgId === null && !empty($r['StudentID'])) {
            $m = db_one("SELECT NationalID, TelegramID FROM members WHERE StudentID = ? LIMIT 1", [$r['StudentID']]);
            if ($m !== null) $tgId = member_tg_id($m['NationalID'], $m['TelegramID'], $r['Phone'] ?? '');
        }
        if ($tgId === null) { $noTg++; continue; }
        $targets[] = ['tg' => $tgId, 'reg_id' => $regId];
    }
    if (!$targets) json_err('هیچ ثبت‌نامی به تلگرام متصل نیست');

    $sent = bot_create_survey($tag, $title, $targets);

    audit_log($admin['id'], 'send_survey', 'telegram', $tag, [
        'sent' => $sent, 'total' => count($targets),
        'no_telegram' => $noTg, 'already_surveyed' => $already,
    ]);
    $msg = "نظرسنجی برای {$sent} نفر ارسال شد";
    if ($already > 0) $msg .= " — {$already} نفر قبلاً نظرسنجی گرفته بودند";
    if ($noTg > 0) $msg .= " ({$noTg} نفر بدون تلگرام متصل رد شدند)";
    json_ok(['data' => ['sent' => $sent, 'total' => count($targets), 'no_telegram' => $noTg, 'already_surveyed' => $already]], $msg);
}
function admin_notify_certificate(array $cert): void
{
    $nationalId = $cert['NationalID'] ?? '';
    if ($nationalId === '') return;

    $course = $cert['Course'] ?? '';
    $certId = $cert['ID'] ?? '';
    $expJalali = !empty($cert['ExpDate']) ? date_jalali($cert['ExpDate']) : 'دائمی';

    // Try to find phone for broader notification coverage
    $phone = db_val("SELECT Phone FROM members WHERE NationalID = ? LIMIT 1", [$nationalId])
        ?: db_val("SELECT Phone FROM user_profiles WHERE NationalID = ? LIMIT 1", [$nationalId]);

    notify_member($nationalId, null,
        'صدور گواهینامه',
        "📜 <b>گواهینامه جدید برای شما صادر شد!</b>\n\n" .
        "🎓 دوره: {$course}\n" .
        "🆔 کد گواهینامه: <code>{$certId}</code>\n" .
        "📅 اعتبار تا: {$expJalali}\n\n" .
        "از پنل SSAEE می‌توانید آن را مشاهده و دانلود کنید.",
        'success',
        $phone
    );
}

function admin_notify_workshop_start(string $title, string $tag): void
{
    $ids = admin_audience_ids('all');
    if (!$ids) return;
    $appUrl = rtrim((string)Env::get('APP_URL', ''), '/');
    $text = "🎉 <b>ثبت‌نام کارگاه «{$title}» آغاز شد!</b>\n\n" .
        "📝 برای ثبت‌نام از پنل SSAEE اقدام کنید:\n{$appUrl}\n\n" .
        "🔖 کد کارگاه: {$tag}";
    tg_broadcast($ids, $text);
}

/* ============================================================
   Settings
   ============================================================ */

function admin_settings_get(): void
{
    $d = [
        'satisfaction_rate'      => setting('satisfaction_rate', '0'),
        'researchers_club_score' => setting('researchers_club_score', '0'),
        'bot_username'           => setting('bot_username', ''),
        'maintenance_mode'       => setting('maintenance_mode', '0'),
        'maintenance_message'    => setting('maintenance_message', 'سایت در حال بروزرسانی است.'),
    ];

    json_ok(['data' => $d]);
}

/**
 * Auto-points settings (task 2).
 * GET  ?api=autopoints            → current values
 * POST {auto_points_workshop, auto_points_activity} → save
 * Allowed: superadmin · members_admin · tech_admin
 */
function admin_action_autopoints(array $admin): void
{
    $allowed = admin_is_super($admin) || admin_is_tech($admin) || admin_can($admin, 'members');
    if (!$allowed) json_err('دسترسی غیرمجاز', 403);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_ok(['data' => [
            'auto_points_workshop'  => (int)setting('auto_points_workshop', '0'),
            'auto_points_activity'  => (int)setting('auto_points_activity', '5'),
        ]]);
    }

    $in = req_input();
    $ws = max(0, min(100, (int)req_str($in, 'auto_points_workshop', '0')));
    $act = max(0, min(100, (int)req_str($in, 'auto_points_activity', '5')));

    setting_set('auto_points_workshop', (string)$ws);
    setting_set('auto_points_activity', (string)$act);
    audit_log((int)$admin['id'], 'update', 'auto_points', null, ['workshop' => $ws, 'activity' => $act]);

    json_ok(['data' => ['auto_points_workshop' => $ws, 'auto_points_activity' => $act]],
        "امتیاز خودکار ذخیره شد — کارگاه: {$ws} · فعالیت: {$act}" . ($ws === 0 ? ' (امتیاز کارگاه غیرفعال)' : ''));
}

/**
 * Site help-content editor (task 1) — tech_admin only.
 * GET  ?api=helpcontent → items
 * POST {items: [{icon, title, body}, ...]} → save
 */
function admin_action_help_content(array $admin): void
{
    if (!admin_is_tech($admin)) json_err('ویرایش راهنمای سایت فقط توسط ادمین فنی مجاز است', 403);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $items = json_decode((string)setting('site_help_content', '[]'), true);
        if (!is_array($items)) $items = [];
        json_ok(['data' => ['items' => $items]]);
    }

    $in = req_input();
    $items = $in['items'] ?? [];
    if (!is_array($items)) json_err('داده نامعتبر');

    $clean = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $title = clean_str((string)($it['title'] ?? ''));
        $body = trim((string)($it['body'] ?? ''));
        $icon = preg_replace('/[^a-z0-9\-]/', '', (string)($it['icon'] ?? 'info')) ?: 'info';
        if ($title === '' && $body === '') continue;
        $clean[] = ['icon' => $icon, 'title' => $title ?: 'بخش', 'body' => mb_substr($body, 0, 4000)];
    }

    setting_set('site_help_content', json_encode($clean, JSON_UNESCAPED_UNICODE));
    audit_log((int)$admin['id'], 'update', 'help_content', null, ['sections' => count($clean)]);
    json_ok(['data' => ['items' => $clean]], 'راهنمای سایت ذخیره شد');
}

/** Tech-admin only: webhook status + database info for the settings page. */
function admin_tech_status(array $admin): void
{    if (!admin_is_tech($admin)) json_err('فقط ادمین فنی', 403);

    $token = (string)Env::get('TELEGRAM_BOT_TOKEN', '');
    $hook = $token !== '' ? tg_api('getWebhookInfo') : null;
    $hookInfo = null;
    if ($hook !== null && ($hook['ok'] ?? false)) {
        $r = $hook['result'] ?? [];
        $hookInfo = [
            'url'            => $r['url'] ?? '',
            'pending_updates'=> (int)($r['pending_update_count'] ?? 0),
            'last_error'     => $r['last_error_message'] ?? null,
            'last_error_date'=> isset($r['last_error_date']) ? date('Y-m-d H:i', (int)$r['last_error_date']) : null,
        ];
    }

    $db = [
        'server' => db_val("SELECT VERSION()"),
        'size'   => null,
        'tables' => null,
    ];
    try {
        $s = db_one(
            "SELECT COUNT(*) t, COALESCE(SUM(data_length + index_length),0) s
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
        );
        if ($s) {
            $db['tables'] = (int)$s['t'];
            $mb = (float)$s['s'] / 1048576;
            $db['size'] = $mb >= 1024 ? round($mb / 1024, 2) . ' GB' : round($mb, 1) . ' MB';
        }
    } catch (Throwable $ex) { /* info_schema may be restricted */ }

    json_ok(['data' => [
        'webhook' => $hookInfo,
        'bot_set' => $token !== '',
        'db'      => $db,
        'migrate_url' => '../migrate.php',
        'webhook_tools' => '../bot/status.php',
    ]]);
}

function admin_settings_save(array $admin): void
{
    if (!admin_can($admin, 'settings')) json_err('دسترسی غیرمجاز', 403);

    $in = req_input();
    $clubScore = (float)req_str($in, 'researchers_club_score', '0');
    $botUsername = preg_replace('/[^A-Za-z0-9_]/', '', req_str($in, 'bot_username'));

    if ($clubScore < 0) json_err('امتیاز باشگاه پژوهشگران نامعتبر است');

    // satisfaction_rate is no longer manual — it is derived from survey ratings (see admin_stats)
    setting_set('researchers_club_score', (string)$clubScore);
    if ($botUsername !== '') setting_set('bot_username', $botUsername);

    // Maintenance / upgrade mode
    if (isset($in['maintenance_mode'])) {
        $maint = (req_str($in, 'maintenance_mode') === '1') ? '1' : '0';
        setting_set('maintenance_mode', $maint);
        if (isset($in['maintenance_message'])) {
            $msg = trim((string)req_str($in, 'maintenance_message'));
            setting_set('maintenance_message', $msg !== '' ? clean_str($msg) : 'سایت در حال بروزرسانی است.');
        }
    }

    audit_log($admin['id'], 'update', 'settings', null, ['club_score' => $clubScore, 'maintenance' => setting('maintenance_mode', '0')]);
    json_ok([], 'تنظیمات ذخیره شد');
}
