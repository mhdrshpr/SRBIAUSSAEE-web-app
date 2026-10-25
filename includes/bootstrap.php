<?php
/* ============================================================
   SSAEE Panel — Core Bootstrap (shared by site + admin)
   ============================================================ */

define('BASE_PATH', __DIR__ . '/..');
define('APP_INIT', true);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (!is_dir(BASE_PATH . '/storage/logs')) {
    @mkdir(BASE_PATH . '/storage/logs', 0755, true);
}
ini_set('error_log', BASE_PATH . '/storage/logs/error.log');
mb_internal_encoding('UTF-8');

/* ============================================================
   Environment
   ============================================================ */

final class Env
{
    private static ?array $vars = null;

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            self::$vars = [];
            error_log('[env] .env not found at: ' . $path);
            return;
        }
        $vars = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if (strlen($val) >= 2 && (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === "'" && substr($val, -1) === "'"))) {
                $val = substr($val, 1, -1);
            }
            $vars[$key] = $val;
        }
        self::$vars = $vars;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = self::$vars[$key] ?? getenv($key);
        return ($v === false || $v === null || $v === '') ? $default : (string)$v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int)$v;
    }
}

/* ============================================================
   Database (PDO)
   ============================================================ */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        Env::get('DB_HOST', 'localhost'),
        Env::get('DB_PORT', '3306'),
        Env::get('DB_NAME', '')
    );
    try {
        $pdo = new PDO($dsn, (string)Env::get('DB_USER', ''), (string)Env::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_persian_ci",
        ]);
    } catch (PDOException $ex) {
        error_log('[DB] ' . $ex->getMessage());
        json_err('خطا در اتصال به سرور. لطفاً بعداً تلاش کنید.', 500);
    }
    return $pdo;
}

function db_q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}

function db_all(string $sql, array $p = []): array
{
    return db_q($sql, $p)->fetchAll();
}

function db_one(string $sql, array $p = []): ?array
{
    $r = db_q($sql, $p)->fetch();
    return $r === false ? null : $r;
}

function db_val(string $sql, array $p = [])
{
    $v = db_q($sql, $p)->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(string $sql, array $p = []): int
{
    return db_q($sql, $p)->rowCount();
}

function db_id(): int
{
    return (int)db()->lastInsertId();
}

/* ============================================================
   Settings (key/value)
   ============================================================ */

function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db_all("SELECT setting_key, setting_value FROM settings") as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $ex) {
            error_log('[settings] ' . $ex->getMessage());
        }
    }
    return $cache[$key] ?? $default;
}

function setting_set(string $key, string $value): void
{
    db_exec(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        [$key, $value]
    );
}

/* ============================================================
   JSON / Input
   ============================================================ */

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok(array $data = [], string $msg = 'success'): void
{
    json_out(array_merge(['status' => 'success', 'message' => $msg], $data));
}

function json_err(string $msg, int $code = 400, array $extra = []): void
{
    if (!isset($extra['err'])) {
        $auto = match (true) {
            $code === 401 => 1010,
            $code === 429 => 1020,
            $code >= 500  => 1005,
            default       => null,
        };
        if ($auto !== null) {
            $extra['err'] = $auto;
        }
    }
    json_out(array_merge(['status' => 'error', 'message' => $msg], $extra), $code);
}

/* ------------------------------------------------------------
   Error code catalogue (1000-1999)
   Shown to users as «کد خطا» — full details for superadmin only.
   ------------------------------------------------------------ */
/** Board position badges (fixed — used by admin select, site display and cert/card secretary lookup). */
function board_positions(): array
{
    return [
        'دبیر' => 'Secretary',
        'نائب دبیر' => 'Deputy Secretary',
        'مدیر برنامه' => 'Program Manager',
        'بازرس' => 'Inspector',
        'مسئول امور مالی' => 'Treasurer',
        'علی البدل' => 'Alternate',
    ];
}

/** Persian-letter key used when searching the secretary row. */
function board_secretary_position(): string { return 'دبیر'; }

function error_codes(): array
{
    return [
        1000 => ['اتصال به سرور برقرار نشد', 'درخواست شما به سرور نرسید — معمولاً قطعی اینترنت یا اختلال شبکه است.', 'اینترنت را بررسی کنید و صفحه را دوباره باز کنید.'],
        1001 => ['پاسخ نامعتبر از سرور', 'سرور پاسخی خارج از انتظار برگرداند.', 'صفحه را دوباره بارگذاری کنید؛ اگر تکرار شد به پشتیبانی اطلاع دهید.'],
        1002 => ['اتصال به دیتابیس برقرار نیست', 'اطلاعات دیتابیس در فایل .env نادرست است یا MySQL در دسترس نیست.', 'مقادیر DB_* در فایل .env را بررسی و سرویس دیتابیس را چک کنید.'],
        1003 => ['جداول دیتابیس ناقص است', 'برخی جدول‌های موردنیاز در دیتابیس وجود ندارند.', 'فایل install/schema.sql را روی دیتابیس اجرا کنید.'],
        1004 => ['کلید امنیتی (JWT_SECRET) تنظیم نشده', 'JWT_SECRET در .env خالی یا کوتاه‌تر از حد مجاز است.', 'در فایل .env مقدار JWT_SECRET را با ۶۴ کاراکتر تصادفی پر کنید (bin2hex(random_bytes(32))).'],
        1005 => ['خطای داخلی سرور', 'یک خطای غیرمنتظره در سرور رخ داد.', 'لاگ سرور در storage/logs/error.log را بررسی کنید.'],
        1006 => ['فایل .env یافت نشد', 'فایل تنظیمات در روت سایت وجود ندارد.', 'فایل .env.example را به .env تغییر نام دهید و مقادیر را تکمیل کنید.'],
        1010 => ['احراز هویت لازم است', 'برای این بخش باید وارد حساب کاربری شوید.', 'وارد حساب شوید و دوباره تلاش کنید.'],
        1011 => ['نشست شما منقضی شده', 'اعتبار توکن ورود به پایان رسیده است.', 'دوباره وارد حساب شوید.'],
        1020 => ['محدودیت تعداد درخواست', 'در بازه کوتاه درخواست‌های زیادی ارسال شده است.', 'چند دقیقه صبر کنید و دوباره تلاش کنید.'],
                1007 => ['دسترسی به فایل ممکن نیست', 'یک فایل یا پوشه موردنیاز قابل دسترسی نیست.', 'دسترسی نوشتن پوشه assets را بررسی کنید.'],
        1008 => ['آپلود ناموفق', 'فایل ارسالی معتبر نیست یا حجم بیش از حد مجاز دارد.', 'فرمت و حجم فایل را بررسی کنید.'],
        1009 => ['فایل اکسل نامعتبر', 'ساختار فایل xlsx قابل خواندن نیست یا ستون‌های الزامی وجود ندارند.', 'فایل نمونه را بررسی و سرستون‌ها را مطابقت دهید.'],
        1012 => ['کد ملی تکراری', 'این کد ملی قبلاً در سیستم ثبت شده است.', 'با پشتیبانی تماس بگیرید یا با حساب قبلی وارد شوید.'],
        1013 => ['شماره دانشجویی تکراری', 'این شماره دانشجویی قبلاً ثبت شده است.', 'اگر فکر می‌کنید اشتباه است با پشتیبانی تماس بگیرید.'],
        1014 => ['ثبت‌نام کارگاه بسته است', 'مهلت ثبت‌نام این کارگاه به پایان رسیده یا هنوز شروع نشده.', 'منتظر کارگاه‌های بعدی باشید.'],
        1015 => ['ظرفیت کارگاه تکمیل است', 'ظرفیت ثبت‌نام این کارگاه پر شده است.', 'اولویت‌بندی در کارگاه‌های بعدی انجام می‌شود.'],
        1016 => ['قبلاً در این کارگاه ثبت‌نام کرده‌اید', 'برای هر کارگاه فقط یک بار می‌توانید ثبت‌نام کنید.', 'وضعیت ثبت‌نام خود را از پروفایل ببینید.'],
        1017 => ['حساب تلگرام قبلاً متصل است', 'این حساب تلگرام به حساب کاربری دیگری وصل است.', 'با پشتیبانی تماس بگیرید تا اتصال قبلی حذف شود.'],
        1018 => ['کد اتصال منقضی شده', 'کد اتصال تلگرام اعتبارش به پایان رسیده.', 'از پنل، دوباره کد اتصال جدید بگیرید.'],
        1019 => ['کد پیگیری نامعتبر', 'این کد گواهینامه یا درخواست یافت نشد.', 'کد را دوباره بررسی کنید یا با پشتیبانی تماس بگیرید.'],
        1021 => ['عملیات غیرمجاز', 'شما اجازه انجام این عملیات را ندارید.', 'با مدیر ارشد تماس بگیرید.'],
        1022 => ['درخواست تکراری', 'این درخواست قبلاً ثبت شده است.', 'صبور باشید؛ پاسخ به‌زودی ارسال می‌شود.'],
        1030 => ['اینترنت شما قطع است', 'دستگاه به شبکه متصل نیست یا اتصال قطع شده است.', 'اتصال Wi-Fi یا دیتا را بررسی و دوباره تلاش کنید.'],
        1031 => ['مهلت اجرای درخواست تمام شد', 'سرور در زمان مجاز پاسخ نداد؛ معمولاً شلوغی شبکه یا کندی هاست است.', 'چند لحظه صبر کنید و دوباره امتحان کنید.'],
        1032 => ['خطا در سرویس پرداخت', 'درگاه پرداخت پاسخ نامعتبر داد؛ مبلغی از حساب شما کسر نشده است.', 'دوباره تلاش کنید یا از کارت دیگری استفاده نمایید.'],
        1033 => ['کد ملی تکراری است', 'این کد ملی قبلاً در سامانه ثبت شده است.', 'با همان حساب وارد شوید یا با پشتیبانی تماس بگیرید.'],
        1034 => ['شما عضو انجمن نیستید', 'برای استفاده از این امکان، ابتدا باید عضو تأییدشده باشید.', 'در بخش عضویت، درخواست خود را ثبت کنید.'],
        1035 => ['ظرفیت کارگاه تکمیل شده', 'تعداد ثبت‌نام‌های این کارگاه به سقف مجاز رسیده است.', 'کارگاه بعدی یا لیست انتظار را ببینید.'],
        1036 => ['مهلت ثبت‌نام به پایان رسیده', 'زمان ثبت‌نام این رویداد گذشته است.', 'کارگاه‌های فعال دیگر را بررسی کنید.'],
        1037 => ['فایل نامعتبر یا بیش از حد بزرگ', 'فایل ارسالی قابل پردازش نیست یا از سقف حجم عبور کرده.', 'فرمت و حجم فایل را کنترل کنید.'],
        1038 => ['اعتبار صفحه منقضی شده', 'برای امنیت، این درخواست باید دوباره ارسال شود.', 'صفحه را تازه (Refresh) کنید و مجدد اقدام کنید.'],
        1039 => ['این بخش مدیریتی است', 'دسترسی به این قسمت فقط برای مدیران پنل مجاز است.', 'با مدیر سیستم هماهنگ کنید.'],
        1040 => ['ورود از نشست دیگر', 'حساب شما در دستگاه/نشست دیگری فعال شده است.', 'اگر خودتان نبودید، رمز عبور را تغییر دهید.'],
        1041 => ['سایت موقتاً در تعمیرات است', 'در حال به‌روزرسانی سیستم هستیم؛ ربات تلگرام همچنان فعال است.', 'کمی دیگر دوباره سر بزنید.'],
        1099 => ['خطای نامشخص', 'خطایی بدون توضیح رخ داده است.', 'این کد را برای پشتیبانی (t.me/saee_admin) ارسال کنید.'],
        1100 => ['داده نامعتبر', 'اطلاعات ارسالی ناقص یا نادرست است.', 'فیلدها را بررسی و دوباره تلاش کنید.'],
        1101 => ['رکورد تکراری', 'این رکورد قبلاً در سیستم ثبت شده است.', 'شماره دانشجویی یا کد ملی را بررسی کنید.'],
        1102 => ['حساب متصل است', 'این حساب تلگرام قبلاً به حساب دیگری متصل شده.', 'با پشتیبانی تماس بگیرید.'],
        1103 => ['کد اتصال منقضی', 'کد اتصال تلگرام منقضی یا نامعتبر است.', 'از پنل دوباره درخواست اتصال دهید.'],
        1110 => ['درخواست یافت نشد', 'رکورد مورد نظر در سیستم موجود نیست.', 'کد را بررسی کنید.'],
        1120 => ['فایل بزرگ است', 'حجم فایل آپلودی بیش از حد مجاز است.', 'حجم فایل را کم کنید.'],
        1121 => ['فرمت فایل نامعتبر', 'فرمت فایل آپلودی مجاز نیست.', 'فقط JPG / PNG / WebP مجاز است.'],
        1130 => ['فایل گواهینامه یافت نشد', 'فایل PDF یا تصویر گواهینامه هنوز بارگذاری نشده است.', 'با پشتیبانی تماس بگیرید.'],
        1140 => ['عملیات مجاز نیست', 'شما اجازه انجام این عملیات را ندارید.', 'با مدیر ارشد تماس بگیرید.'],
        1150 => ['نظرسنجی تکراری', 'شما قبلاً در این نظرسنجی شرکت کرده‌اید.', 'نیازی به ارسال مجدد نیست.'],
    ];
}

/** Default lucide icon per error code (admin can override per code). */
function error_code_icons(): array
{
    return [
        1000 => 'wifi-off',       1001 => 'file-warning',   1002 => 'database-off',
        1003 => 'table',          1004 => 'key-round',      1005 => 'bug',
        1006 => 'file-question',  1007 => 'folder-lock',    1008 => 'upload',
        1009 => 'file-x',         1010 => 'lock',           1011 => 'log-out',
        1012 => 'id-card',        1013 => 'shield-alert',   1014 => 'user-x',
        1015 => 'send',           1016 => 'hash',           1017 => 'timer-off',
        1018 => 'timer-off',      1019 => 'search-x',       1021 => 'shield-off',
        1022 => 'copy',           1030 => 'wifi-off',       1031 => 'timer',
        1032 => 'receipt',        1033 => 'user-check',     1034 => 'graduation-cap',
        1035 => 'users',          1036 => 'clock',          1037 => 'hard-drive',
        1038 => 'refresh-rotate', 1039 => 'shield',         1040 => 'monitor-x',
        1041 => 'construction',   1099 => 'circle-help',    1100 => 'file-x',
        1101 => 'copy',           1102 => 'link',           1103 => 'key-round',
        1110 => 'search-x',       1120 => 'ban',            1121 => 'image-off',
        1130 => 'file-text',      1140 => 'gauge',          1150 => 'trending-down',
    ];
}

function req_input(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) return $json;
    return is_array($_POST) ? $_POST : [];
}

function req_str(array $in, string $key, string $default = ''): string
{
    if (!isset($in[$key]) || !is_scalar($in[$key])) return $default;
    return trim((string)$in[$key]);
}

/* ============================================================
   Session / CSRF
   ============================================================ */

function boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(Env::get('SESSION_NAME', 'SSAEE_SESSION'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => Env::bool('COOKIE_SECURE', true),
        'httponly' => Env::bool('COOKIE_HTTPONLY', true),
        'samesite' => 'Lax',
    ]);
    session_start();
    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    } elseif (time() - (int)$_SESSION['created_at'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
    }
}

function csrf_token(): string
{
    boot_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token = null): bool
{
    boot_session();
    $token = $token ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/* ============================================================
   Sanitization / Validation
   ============================================================ */

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function clean_str(?string $value): string
{
    return htmlspecialchars(trim((string)$value), ENT_QUOTES, 'UTF-8');
}

/* Persian/Arabic person-name sanitizer (server mirror of the UI rule):
   keeps Persian/Arabic letters, their diacritics, Persian digits, space
   and ZWNJ — strips Latin letters and any other scripts. */
function fa_name_clean(?string $value): string
{
    $v = trim((string)$value);
    $v = preg_replace('/[^\p{Arabic}\x{200C} \x{200D}]+/u', '', $v) ?? '';
    return trim(preg_replace('/\s{2,}/u', ' ', $v));
}

function fa2en(string $str): string
{
    return strtr($str, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

function only_digits(string $str): string
{
    return preg_replace('/\D/', '', fa2en(trim($str)));
}

function phone_normalize(string $phone): string
{
    $d = only_digits($phone);
    if (str_starts_with($d, '0098')) {
        $d = '0' . substr($d, 4);
    } elseif (str_starts_with($d, '98') && strlen($d) === 12) {
        $d = '0' . substr($d, 2);
    } elseif (str_starts_with($d, '9') && strlen($d) === 10) {
        $d = '0' . $d;
    }
    return $d;
}

/* Convert local 09… to international +989… (Telegram format) */
function phone_to_intl(string $phone): string
{
    $d = only_digits($phone);
    if (str_starts_with($d, '0098')) {
        $d = '0' . substr($d, 4);
    }
    if (str_starts_with($d, '98') && strlen($d) === 12) {
        $d = '0' . substr($d, 2);
    }
    if (str_starts_with($d, '9') && strlen($d) === 10) {
        return '+98' . $d;
    }
    if (str_starts_with($d, '0')) {
        return '+98' . substr($d, 1);
    }
    return '+' . $d;
}

/* Compare two phone numbers in any format (09…, 989…, +989…, 00989…) */
function phone_matches(?string $a, ?string $b): bool
{
    if ($a === null || $b === null || $a === '' || $b === '') return false;
    $na = phone_normalize($a);
    $nb = phone_normalize($b);
    if ($na === $nb && $na !== '') return true;
    if (strlen($na) < 10 || strlen($nb) < 10) return false;
    return phone_to_intl($na) === phone_to_intl($nb);
}

function phone_valid(string $phone): bool
{
    return (bool)preg_match('/^09\d{9}$/', $phone);
}

function national_id_valid(string $nid): bool
{
    if (!preg_match('/^\d{10}$/', $nid)) return false;
    if (preg_match('/^(\d)\1{9}$/', $nid)) return false;
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$nid[$i] * (10 - $i);
    }
    $rem = $sum % 11;
    $chk = (int)$nid[9];
    return ($rem < 2) ? ($chk === $rem) : ($chk === 11 - $rem);
}

function gen_id(string $prefix = ''): string
{
    $rand = strtoupper(bin2hex(random_bytes(4)));
    $time = base_convert((string)time(), 10, 36);
    return $prefix !== '' ? "{$prefix}-{$time}{$rand}" : ($time . $rand);
}

/** شماره گواهی — الگوی کلاسیک YYYYMM-xxxxx (پنج رقم تصادفی، یکتا). */
function gen_cert_id(): string
{
    for ($i = 0; $i < 40; $i++) {
        $id = date('Ym') . '-' . random_int(10000, 99999);
        try {
            if ((int)db_val("SELECT COUNT(*) FROM certificates WHERE ID = ?", [$id]) === 0) return $id;
        } catch (Throwable $ex) {
            return $id;
        }
    }
    return date('Ym') . '-' . random_int(100000, 999999);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function client_ua(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
}

function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) return $m[1];
    return null;
}

/* ============================================================
   JWT (HS256)
   ============================================================ */

function b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string
{
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_sign(array $payload, ?int $ttl = null): string
{
    $secret = (string)Env::get('JWT_SECRET', '');
    if (strlen($secret) < 48) {
        throw new RuntimeException('JWT_SECRET is not configured');
    }
    if ($ttl !== null) $payload['exp'] = time() + $ttl;
    $payload['iat'] = time();
    $payload['jti'] = bin2hex(random_bytes(8));
    $h = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $b = b64url(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $s = b64url(hash_hmac('sha256', "$h.$b", $secret, true));
    return "$h.$b.$s";
}

function jwt_verify(string $token): ?array
{
    $secret = (string)Env::get('JWT_SECRET', '');
    if (strlen($secret) < 48) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $b, $s] = $parts;
    if (!hash_equals(b64url(hash_hmac('sha256', "$h.$b", $secret, true)), $s)) return null;
    $payload = json_decode(b64url_decode($b), true);
    if (!is_array($payload)) return null;
    if (isset($payload['exp']) && time() >= (int)$payload['exp']) return null;
    return $payload;
}

/* ============================================================
   Telegram initData verification (Mini App)
   ============================================================ */

function tg_verify_init_data(string $initData): ?array
{
    $botToken = (string)(
        Env::get('TELEGRAM_BOT_TOKEN')
        ?: (Env::get('BOT_TOKEN')
        ?: (Env::get('TELEGRAM_TOKEN')
        ?: (setting('bot_token')
        ?: (setting('telegram_bot_token') ?: ''))))
    );
    if ($botToken === '') {
        error_log('[tg_verify] TELEGRAM_BOT_TOKEN is missing or empty');
        return null;
    }

    $initData = ltrim(trim($initData), '?#');
    if ($initData === '') return null;

    // اگر کلید tgWebAppData به صورت رشته کوئری قرار گرفته باشد، رشته داخلی را استخراج کن
    if (strpos($initData, 'tgWebAppData=') !== false) {
        parse_str($initData, $wrapper);
        if (!empty($wrapper['tgWebAppData'])) {
            $initData = ltrim(trim((string)$wrapper['tgWebAppData']), '?#');
        }
    }

    parse_str($initData, $data);
    if (!isset($data['hash'])) {
        error_log('[tg_verify] hash parameter missing from initData');
        return null;
    }
    $hash = (string)$data['hash'];
    unset($data['hash']);
    unset($data['signature']); // ضروری برای کلاینت‌های جدید تلگرام که پارامتر signature را نیز ارسال می‌کنند

    $pairs = [];
    foreach ($data as $k => $v) $pairs[] = $k . '=' . $v;
    sort($pairs);
    $checkString = implode("\n", $pairs);

    $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $computed = hash_hmac('sha256', $checkString, $secretKey);
    $isValid = hash_equals($computed, $hash);

    // اعتبارسنجی جایگزین ۱: با استفاده از جفت‌های rawurldecode
    if (!$isValid) {
        $rawPairs = [];
        foreach (explode('&', $initData) as $item) {
            $item = trim($item);
            if ($item === '') continue;
            $eq = strpos($item, '=');
            if ($eq === false) continue;
            $rk = substr($item, 0, $eq);
            $rv = substr($item, $eq + 1);
            if ($rk === 'hash' || $rk === 'signature') continue;
            $rawPairs[] = rawurldecode($rk) . '=' . rawurldecode($rv);
        }
        sort($rawPairs);
        $checkStringRaw = implode("\n", $rawPairs);
        $computedRaw = hash_hmac('sha256', $checkStringRaw, $secretKey);
        if (hash_equals($computedRaw, $hash)) {
            $isValid = true;
        }
    }

    // اعتبارسنجی جایگزین ۲: بدون دیکود مقادیر (در صورتی که مقادیر دست‌نخورده هش شده باشند)
    if (!$isValid) {
        $rawPairs2 = [];
        foreach (explode('&', $initData) as $item) {
            $item = trim($item);
            if ($item === '') continue;
            $eq = strpos($item, '=');
            if ($eq === false) continue;
            $rk = substr($item, 0, $eq);
            $rv = substr($item, $eq + 1);
            if ($rk === 'hash' || $rk === 'signature') continue;
            $rawPairs2[] = $rk . '=' . $rv;
        }
        sort($rawPairs2);
        $checkStringRaw2 = implode("\n", $rawPairs2);
        $computedRaw2 = hash_hmac('sha256', $checkStringRaw2, $secretKey);
        if (hash_equals($computedRaw2, $hash)) {
            $isValid = true;
        }
    }

    if (!$isValid) {
        error_log("[tg_verify] Hash mismatch: expected {$hash}, got {$computed}");
        return null;
    }

    $authDate = (int)($data['auth_date'] ?? 0);
    // بازه معتبر ۹۰ روز جهت حفظ سشن در تب‌های باز مینی‌اپ
    if ($authDate > 0 && (time() - $authDate) > (86400 * 90)) {
        error_log('[tg_verify] auth_date expired: ' . $authDate);
        return null;
    }

    $rawUser = $data['user'] ?? '{}';
    $user = is_string($rawUser) ? json_decode($rawUser, true) : (is_array($rawUser) ? $rawUser : null);
    if (!is_array($user) && is_string($rawUser)) {
        $user = json_decode(rawurldecode($rawUser), true);
    }
    if (!is_array($user) || empty($user['id'])) {
        error_log('[tg_verify] user payload empty or invalid JSON');
        return null;
    }

    return $user;
}

/* ============================================================
   Jalali <-> Gregorian
   ============================================================ */

function jalali_to_gregorian(int $jy, int $jm, int $jd): string
{
    /* Canonical jdf algorithm (jdf.scr.ir) — verified against known anchors:
       1400/01/01 → 2021-03-21 · 1403/01/01 → 2024-03-20
       1403/12/30 → 2025-03-20 · 1405/06/17 → 2026-09-08
       (the previous hybrid version was off by exactly one day) */
    if ($jy > 979) { $gy = 1600; $jy -= 979; } else { $gy = 621; }

    $days = (365 * $jy)
        + (((int)($jy / 33)) * 8)
        + (int)((($jy % 33) + 3) / 4)
        + 78
        + $jd
        + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

    $gy += 400 * ((int)($days / 146097));
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * ((int)(--$days / 36524));
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $gy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;

    $leap = (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0));
    $mdays = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 0;
    while ($gm < 13 && $gd > $mdays[$gm]) {
        $gd -= $mdays[$gm];
        $gm++;
    }
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

function gregorian_to_jalali(string $greg): string
{
    /* Canonical jdf algorithm (jdf.scr.ir) — verified against:
       2021-03-21 → 1400/01/01 · 2024-03-20 → 1403/01/01
       2025-03-20 → 1403/12/30 · 2026-09-08 → 1405/06/17

       Y-m-d strings are parsed with a regex (timezone-proof); anything else
       falls back to strtotime + date() inside the same PHP timezone. */
    $greg = trim($greg);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $greg, $m)) {
        $gy = (int)$m[1];
        $gm = (int)$m[2];
        $gd = (int)$m[3];
    } else {
        $ts = strtotime($greg);
        if ($ts === false) return $greg;
        $gy = (int)date('Y', $ts);
        $gm = (int)date('n', $ts);
        $gd = (int)date('j', $ts);
    }

    $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100))
        + ((int)(($gy2 + 399) / 400)) - 80 + $gd + $gdm[$gm - 1];

    $jy += 33 * ((int)($days / 12053));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $jm = ($days < 186) ? 1 + (int)($days / 31) : 7 + (int)(($days - 186) / 30);
    $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

function date_jalali(?string $greg): string
{
    if ($greg === null || $greg === '' || strtotime($greg) === false) return '—';
    return gregorian_to_jalali($greg);
}

function date_from_jalali(string $input): ?string
{
    $input = str_replace('-', '/', trim(fa2en($input)));
    if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $input, $m)) {
        $y = (int)$m[1];
        if ($y >= 1900) {
            return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[3]);
        }
        return jalali_to_gregorian($y, (int)$m[2], (int)$m[3]);
    }
    return null;
}

/* Effective registration-close timestamp from a banner EndDate.
   EndDate is a DATETIME column: appending " 23:59:59" (or any suffix) to a
   datetime string makes strtotime() return false, and "$now > false" is
   always true (bool→int juggling) — which closed every registration forever.
   Rules: date-only (or 00:00:00 datetime) counts until the END of that day;
   a real time-of-day is used as-is; null/invalid → open-ended (null). */
function reg_window_end(?string $endDate): ?int
{
    if ($endDate === null || trim($endDate) === '') return null;
    $ts = strtotime($endDate);
    if ($ts === false) return null;
    if (strlen($endDate) === 10 || substr($endDate, 11) === '00:00:00') {
        $endOfDay = strtotime(substr($endDate, 0, 10) . ' 23:59:59');
        return $endOfDay !== false ? $endOfDay : $ts;
    }
    return $ts;
}

/* ============================================================
   Rate limiting
   ============================================================ */

function rl_check(string $bucket, string $identifier, int $maxAttempts, int $windowSeconds): bool
{
    if (!Env::bool('RATE_LIMIT_ENABLED', true)) return true;
    if (random_int(1, 100) <= 5) {
        db_exec("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    }
    db_exec("INSERT INTO rate_limits (bucket, identifier, created_at) VALUES (?, ?, NOW())", [$bucket, $identifier]);
    $count = (int)db_val(
        "SELECT COUNT(*) FROM rate_limits
         WHERE bucket = ? AND identifier = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
        [$bucket, $identifier, $windowSeconds]
    );
    return $count <= $maxAttempts;
}

function rl_clear(string $bucket, string $identifier): void
{
    db_exec("DELETE FROM rate_limits WHERE bucket = ? AND identifier = ?", [$bucket, $identifier]);
}

/* ============================================================
   Telegram
   ============================================================ */

function tg_api(string $method, array $params = []): ?array
{
    $token = (string)Env::get('TELEGRAM_BOT_TOKEN', '');
    if ($token === '') {
        error_log('[Telegram] TELEGRAM_BOT_TOKEN is empty');
        return null;
    }

    // Telegram Bot API: nested structures (reply_markup etc) MUST be JSON-encoded.
    // Auto-encode any array values so callers can pass raw PHP arrays safely.
    foreach ($params as $k => $v) {
        if (is_array($v)) {
            $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    $payload = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) {
        error_log('[Telegram] curl error: ' . $err);
        return null;
    }

    $data = json_decode($res, true);
    if (!is_array($data)) {
        error_log('[Telegram] invalid response (HTTP ' . $code . '): ' . mb_substr((string)$res, 0, 200));
        return null;
    }
    if (!($data['ok'] ?? false)) {
        error_log('[Telegram] API error in ' . $method . ': ' . ($data['description'] ?? 'unknown'));
    }
    return $data;
}

function tg_send(int|string $chatId, string $text, array $extra = []): ?array
{
    return tg_api('sendMessage', array_merge([
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ], $extra));
}

function tg_esc(?string $text): string
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function tg_notify_admin(string $text): void
{
    $adminId = (string)Env::get('TELEGRAM_ADMIN_CHAT_ID', '');
    if ($adminId !== '') tg_send($adminId, $text);
}

function tg_broadcast(array $chatIds, string $text): array
{
    $chatIds = array_values(array_unique(array_filter(array_map('intval', $chatIds), fn($i) => $i > 0)));
    $total = count($chatIds);
    $sent = 0;
    $failed = 0;
    $blocked = 0;

    foreach ($chatIds as $i => $chatId) {
        $res = tg_send($chatId, $text);
        if ($res !== null && ($res['ok'] ?? false)) {
            $sent++;
        } else {
            $desc = (string)($res['description'] ?? '');
            // Dead chats — user blocked the bot / deactivated / never started it
            if (strpos($desc, 'bot was blocked') !== false
                || strpos($desc, 'chat not found') !== false
                || strpos($desc, 'user is deactivated') !== false
                || strpos($desc, 'chat_id is empty') !== false) {
                $blocked++;
            } elseif (isset($res['parameters']['retry_after'])) {
                // Rate limited — respect Telegram's retry_after (max 10s), then retry once
                $wait = min(10, max(1, (int)$res['parameters']['retry_after']));
                usleep(($wait + 1) * 1000000);
                $retry = tg_send($chatId, $text);
                if ($retry !== null && ($retry['ok'] ?? false)) $sent++; else $failed++;
            } else {
                $failed++;
            }
        }
        // ~30 msg/sec Telegram limit — pause briefly every 25 messages
        if ($i > 0 && $i % 25 === 0) usleep(400000);
    }

    if ($blocked > 0 || $failed > 0) {
        error_log("[broadcast] total={$total} sent={$sent} failed={$failed} blocked={$blocked}");
    }

    return ['total' => $total, 'sent' => $sent, 'failed' => $failed, 'blocked' => $blocked];
}

/* ============================================================
   Member notifications (central helpers)
   Resolve a member's current telegram via multiple paths:
   members.TelegramID → user_profiles.NationalID→web_users.telegram_id
   ------------------------------------------------------------ */

function member_tg_id(?string $nationalId = null, $fallbackTgId = null, ?string $phone = null): ?int
{
    // 1) Direct fallback (admin set TelegramID on member record)
    if (!empty($fallbackTgId) && (int)$fallbackTgId > 0) {
        return (int)$fallbackTgId;
    }

    // 2) Via user_profiles — the bot creates this for EVERY user who sends /start
    //    The TelegramID in user_profiles IS the chat_id
    if (!empty($nationalId)) {
        $row = db_one(
            "SELECT TelegramID FROM user_profiles WHERE NationalID = ? LIMIT 1",
            [$nationalId]
        );
        if ($row !== null && (int)$row['TelegramID'] > 0) {
            return (int)$row['TelegramID'];
        }
    }

    // 3) Via phone → user_profiles
    if (!empty($phone)) {
        $row = db_one(
            "SELECT TelegramID FROM user_profiles WHERE Phone = ? LIMIT 1",
            [$phone]
        );
        if ($row !== null && (int)$row['TelegramID'] > 0) {
            return (int)$row['TelegramID'];
        }
    }

    // 4) Via web_users (site-registered users who connected telegram)
    if (!empty($nationalId)) {
        $row = db_one(
            "SELECT wu.telegram_id
             FROM web_users wu
             JOIN user_profiles up ON up.TelegramID = wu.telegram_id
             WHERE up.NationalID = ? AND wu.telegram_id IS NOT NULL AND wu.is_active = 1
             LIMIT 1",
            [$nationalId]
        );
        if ($row !== null) return (int)$row['telegram_id'];
    }

    return null;
}

/**
 * Send a notification to a member (by national_id and/or legacy telegram id).
 * Records into notifications table too. Returns true on telegram success.
 */
function notify_member(?string $nationalId, $fallbackTgId, string $title, string $message, string $type = 'info', ?string $phone = null): bool
{
    $tgId = member_tg_id($nationalId, $fallbackTgId, $phone);
    if ($tgId === null) return false;

    $sent = false;
    $res = tg_send($tgId, $message);
    if ($res !== null && ($res['ok'] ?? false)) {
        $sent = true;
    }

    // Always log notification regardless of delivery status
    try {
        db_exec(
            "INSERT INTO notifications (telegram_id, title, message, type) VALUES (?, ?, ?, ?)",
            [$tgId, $title, $message, $type]
        );
    } catch (Throwable $ex) {
        error_log('[notify_member] DB insert failed: ' . $ex->getMessage());
    }
    return $sent;
}
/* ============================================================
   Audit log
   ============================================================ */

function audit_log(?int $adminId, string $action, string $entity, ?string $entityId = null, array $details = []): void
{
    try {
        db_exec(
            "INSERT INTO audit_log (admin_id, action, entity, entity_id, details, ip, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $adminId,
                $action,
                $entity,
                $entityId,
                json_encode($details, JSON_UNESCAPED_UNICODE),
                client_ip(),
                client_ua(),
            ]
        );
    } catch (Throwable $ex) {
        error_log('[audit] ' . $ex->getMessage());
    }
}

/* ============================================================
   Boot
   ============================================================ */

function app_boot(): void
{
    Env::load(BASE_PATH . '/.env');
    date_default_timezone_set(Env::get('APP_TIMEZONE', 'Asia/Tehran'));
}

/* Certificate PNG renderer (GD + PersianGd + Vazirmatn-from-CDN) */
require_once __DIR__ . '/certificates.php';
