<?php
/* ============================================================
   Database Migration Tool (v1 → v2) — two-phase with preview
   Access: tech_admin only.

   Flow:
     1. Upload SQL dump → analyze ONLY (no writes)
     2. Show column-by-column + row-by-row diff preview → admin confirms
     3. On confirm (token-bound to the uploaded file) → execute in
        transaction → show per-row outcome report.

   The uploaded file is kept in storage/migrate/ (20 min TTL) bound to
   a random token. Nothing touches the DB until the admin approves the
   preview, so the preview is a true "dry-run".
   ============================================================ */

try {
    // migrate.php lives in the DOCUMENT ROOT (next to includes/) — no ../ needed
    require_once __DIR__ . '/includes/bootstrap.php';
    require_once __DIR__ . '/includes/admin.php';
} catch (ParseError | Error $ex) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Load Error: ' . $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

app_boot();
boot_session();

/* ============================================================
   Auth guard — tech_admin only (canonical panel resolver)
   ============================================================ */
$admin = null;
if (!empty($_SESSION['admin']['id'])) {
    $row = db_one("SELECT id, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1", [(int)$_SESSION['admin']['id']]);
    if ($row !== null) $admin = $row;
}
if ($admin === null || !admin_is_tech($admin)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>دسترسی</title></head>';
    echo '<body style="font-family:Tahoma;background:#0b0b0d;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh">';
    echo '<div style="background:#141416;border:1px solid #26262a;padding:30px;border-radius:16px;text-align:center">';
    echo '<h2 style="color:#ff5252">⛔ فقط ادمین فنی</h2>';
    echo '<p style="color:#8b8b90">ابزار مهاجرت دیتابیس فقط با حساب «ادمین فنی» در دسترس است.</p>';
    echo '<p style="color:#8b8b90">از <a href="admin/" style="color:#ffcc00">پنل مدیریت</a> وارد شوید.</p></div></body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');

/* ============================================================
   Migration definitions — column maps (v1 → v2)
   Each map: v1column => [v2column, castType]
   castType: string | int | float | date (keeps as-is) | nullify(''→NULL)
   ============================================================ */

function mig_defs(): array
{
    return [
        'users' => [
            'table'   => 'users',
            'id_col'  => 'id',
            'unique'  => 'username',
            'columns' => [
                'id'       => ['id', 'int'],
                'username' => ['username', 'string'],
                'password' => ['password', 'string'],
                'full_name'=> ['full_name', 'string'],
                'role'     => ['role', 'string'],
                'is_active'=> ['is_active', 'int'],
            ],
        ],
        'members' => [
            'table'   => 'members',
            'id_col'  => 'ID',
            'unique'  => 'NationalID',
            'columns' => [
                'NameFa'     => ['NameFa', 'string'],
                'NationalID' => ['NationalID', 'string'],
                'StudentID'  => ['StudentID', 'string'],
                'Branch'     => ['Branch', 'string'],
                'Active'     => ['Active', 'int'],
                'Points'     => ['Points', 'float'],
                'IssueDate'  => ['IssueDate', 'date'],
                'ExpDate'    => ['ExpDate', 'date'],
                'Phone'      => ['Phone', 'string'],
            ],
        ],
        'certificates' => [
            'table'   => 'certificates',
            'id_col'  => 'ID',
            'unique'  => 'ID',
            'columns' => [
                'ID'         => ['ID', 'string'],
                'NameEn'     => ['NameEn', 'string'],
                'NameFa'     => ['NameFa', 'string'],
                'NationalID' => ['NationalID', 'string'],
                'Course'     => ['Course', 'string'],
                'IssueDate'  => ['IssueDate', 'date'],
                'ExpDate'    => ['ExpDate', 'date'],
            ],
        ],
        'workshop_registrations' => [
            'table'   => 'workshop_registrations',
            'id_col'  => 'ID',
            'unique'  => 'ID',
            'columns' => [
                'ID'          => ['ID', 'string'],
                'Date'        => ['Date', 'date'],
                'FullName'    => ['FullName', 'string'],
                'Phone'       => ['Phone', 'string'],
                'StudentID'   => ['StudentID', 'string'],
                'University'  => ['University', 'string'],
                'Field'       => ['Field', 'string'],
                'WorkshopType'=> ['WorkshopType', 'string'],
                'WorkshopTag' => ['WorkshopTag', 'string'],
                'Status'      => ['Status', 'string'],
            ],
        ],
        'user_profiles' => [
            'table'   => 'user_profiles',
            'id_col'  => 'ID',
            'unique'  => 'TelegramID',
            'columns' => [
                'TelegramID' => ['TelegramID', 'int'],
                'Phone'      => ['Phone', 'string'],
                'FirstName'  => ['FirstName', 'string'],
                'LastName'   => ['LastName', 'string'],
                'NationalID' => ['NationalID', 'string'],
            ],
        ],
        'associations' => [
            'table'   => 'associations',
            'id_col'  => 'id',
            'unique'  => null, // no natural unique col — full-row hash check
            'columns' => [
                'Name'       => ['Name', 'string'],
                'University' => ['University', 'string'],
                'Field'      => ['Field', 'string'],
                'Phone'      => ['Phone', 'string'],
            ],
        ],
        'teachers' => [
            'table'   => 'teachers',
            'id_col'  => 'id',
            'unique'  => null,
            'columns' => [
                'Name'       => ['Name', 'string'],
                'University' => ['University', 'string'],
                'AcRank'     => ['AcRank', 'string'],
                'Course'     => ['Course', 'string'],
                'Phone'      => ['Phone', 'string'],
            ],
        ],
        'companies' => [
            'table'   => 'companies',
            'id_col'  => 'id',
            'unique'  => null,
            'columns' => [
                'Name'    => ['Name', 'string'],
                'Contact' => ['Contact', 'string'],
                'Phone'   => ['Phone', 'string'],
                'Email'   => ['Email', 'string'],
            ],
        ],
        'sponsor' => [
            'table'   => 'sponsor',
            'id_col'  => 'id',
            'unique'  => null,
            'columns' => [
                'Brand'    => ['Brand', 'string'],
                'Interface'=> ['Interface', 'string'],
                'Type'     => ['Type', 'string'],
                'Money'    => ['Money', 'float'],
                'Details'  => ['Details', 'string'],
                'Email'    => ['Email', 'string'],
                'Page'     => ['Phone', 'string'],
                'Telegram' => ['Telegram', 'string'],
                'Website'  => ['Website', 'string'],
                'Note'     => ['Note', 'string'],
            ],
        ],
        'suggestions' => [
            'table'   => 'suggestions',
            'id_col'  => 'id',
            'unique'  => null,
            'columns' => [
                'Phone'   => ['Phone', 'string'],
                'Unit'    => ['Unit', 'string'],
                'Subject' => ['Subject', 'string'],
                'Note'    => ['Note', 'string'],
            ],
        ],
    ];
}

/* ============================================================
   SQL dump parsing — shared by analyze & execute
   ============================================================ */

/** Split a value tuple "( ... )" respecting quotes and escapes. */
function mig_split_values(string $tuple): array
{
    $vals = [];
    $cur = '';
    $len = strlen($tuple);
    $inQuote = false;
    $qChar = '';
    for ($i = 0; $i < $len; $i++) {
        $ch = $tuple[$i];
        if ($inQuote) {
            if ($ch === '\\') {            // escaped char inside string
                $cur .= $ch;
                if ($i + 1 < $len) { $cur .= $tuple[$i + 1]; $i++; }
                continue;
            }
            if ($ch === $qChar) {
                if ($i + 1 < $len && $tuple[$i + 1] === $qChar) { // doubled quote
                    $cur .= $ch . $ch;
                    $i++;
                    continue;
                }
                $inQuote = false;
                $qChar = '';
                continue;
            }
            $cur .= $ch;
            continue;
        }
        if ($ch === "'" || $ch === '"') { $inQuote = true; $qChar = $ch; continue; }
        if ($ch === ',') { $vals[] = trim($cur); $cur = ''; continue; }
        if ($ch === '(') { /* nested — keep */ }
        $cur .= $ch;
    }
    $vals[] = trim($cur);
    return $vals;
}

/** Parse an INSERT statement into [table, columnList|null, rows[]]. */
function mig_parse_insert(string $stmt): ?array
{
    if (!preg_match("/INSERT\s+(?:IGNORE\s+)?INTO\s+[`\"]?(\w+)[`\"]?\s*(\([^)]*\))?\s*VALUES\s*/is", $stmt, $m)) {
        return null;
    }
    $table = strtolower($m[1]);
    $colsRaw = $m[2] ?? null;
    $cols = null;
    if ($colsRaw !== null) {
        $cols = array_map(fn($c) => trim($c, " `\""), explode(',', trim($colsRaw, '()')));
    }
    $body = mb_substr($stmt, strlen($m[0]));
    $body = rtrim($body, "; \r\n");
    $rows = [];
    $depth = 0; $cur = ''; $len = mb_strlen($body);
    $inQuote = false; $qChar = '';
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($body, $i, 1);
        if ($inQuote) {
            if ($ch === '\\') { $cur .= $ch; if ($i + 1 < $len) { $cur .= mb_substr($body, $i + 1, 1); $i++; } continue; }
            if ($ch === $qChar) {
                if ($i + 1 < $len && mb_substr($body, $i + 1, 1) === $qChar) { $cur .= $ch . $ch; $i++; continue; }
                $inQuote = false; $qChar = ''; continue;
            }
            $cur .= $ch; continue;
        }
        if ($ch === "'" || $ch === '"') { $inQuote = true; $qChar = $ch; continue; }
        if ($ch === '(') { $depth++; if ($depth === 1) { $cur = ''; continue; } }
        if ($ch === ')') { $depth--; if ($depth === 0) { $rows[] = mig_split_values($cur); $cur = ''; continue; } }
        $cur .= $ch;
    }
    return ['table' => $table, 'cols' => $cols, 'rows' => $rows];
}

/** Decode SQL literal → PHP value. */
function mig_literal(string $v)
{
    if (strcasecmp($v, 'NULL') === 0) return null;
    $len = strlen($v);
    if ($len >= 2) {
        $q = $v[0];
        if (($q === "'" || $q === '"') && $v[$len - 1] === $q) {
            $inner = substr($v, 1, -1);
            $inner = str_replace(['\\\\', "\\'", '\\"', '\\n', '\\r', '\\t', '\\0'], ['\\', "'", '"', "\n", "\r", "\t", "\0"], $inner);
            return $inner;
        }
    }
    return $v;
}

/** Cast + normalize a value per column type. Returns [ok, value]. */
function mig_cast($v, string $type): array
{
    if ($v === null) return [true, null];
    switch ($type) {
        case 'int':    return [true, is_numeric($v) ? (int)$v : null];
        case 'float':  return [true, is_numeric($v) ? (float)$v : null];
        case 'date':
            $t = strtotime((string)$v);
            return [true, $t !== false ? date('Y-m-d', $t) : null];
        case 'nullify':
            $s = trim((string)$v);
            return [true, $s === '' ? null : $s];
        default:       return [true, (string)$v];
    }
}

/* ============================================================
   Storage of the uploaded file between phases
   ============================================================ */

function mig_dir(): string
{
    $dir = BASE_PATH . '/storage/migrate';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function mig_store_upload(string $content): string
{
    $token = bin2hex(random_bytes(16));
    $meta = ['created' => time(), 'owner' => (int)$_SESSION['admin']['id']];
    file_put_contents(mig_dir() . "/{$token}.json", json_encode($meta));
    file_put_contents(mig_dir() . "/{$token}.sql", $content);
    return $token;
}

function mig_load_upload(string $token): ?string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $file = mig_dir() . "/{$token}.sql";
    if (!is_file($file)) return null;
    $metaFile = mig_dir() . "/{$token}.json";
    if (is_file($metaFile)) {
        $meta = json_decode((string)file_get_contents($metaFile), true);
        if (!is_array($meta) || (time() - (int)($meta['created'] ?? 0)) > 1200) { // 20 min TTL
            @unlink($file); @unlink($metaFile);
            return null;
        }
    }
    return (string)file_get_contents($file);
}

function mig_delete_upload(string $token): void
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return;
    @unlink(mig_dir() . "/{$token}.sql");
    @unlink(mig_dir() . "/{$token}.json");
}

/* Purge stale uploads (>20 min) */
foreach (glob(mig_dir() . '/*.sql') ?: [] as $stale) {
    $tok = basename($stale, '.sql');
    if (preg_match('/^[a-f0-9]{32}$/', $tok)) {
        $metaFile = mig_dir() . "/{$tok}.json";
        $meta = is_file($metaFile) ? json_decode((string)file_get_contents($metaFile), true) : null;
        if (!is_array($meta) || (time() - (int)($meta['created'] ?? 0)) > 1200) mig_delete_upload($tok);
    }
}

/* ============================================================
   Core: analyze the dump against the live v2 schema (dry-run)
   ============================================================ */

/**
 * @return array{defs:array,plan:array,rows:array,warnings:array,stats:array}
 */
function mig_analyze(string $content): array
{
    $defs = mig_defs();
    $v2cols = []; // live v2 schema cache

    $getV2Cols = function (string $table) use (&$v2cols): array {
        if (!isset($v2cols[$table])) {
            $v2cols[$table] = [];
            foreach (db_all(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
                [$table]
            ) as $c) {
                $v2cols[$table][] = $c['COLUMN_NAME'];
            }
        }
        return $v2cols[$table];
    };

    // Collect INSERT statements per table
    $stmts = [];
    preg_match_all("/INSERT\s+(?:IGNORE\s+)?INTO\s+[`\"]?(\w+)[`\"]?[^;]*?;/is", $content, $mm, PREG_SET_ORDER);
    foreach ($mm as $m) {
        $t = strtolower($m[1]);
        $stmts[$t][] = $m[0];
    }

    $plan = [];      // per-table plan
    $rowList = [];   // per-row preview (limited)
    $warnings = [];
    $stats = ['tables_in_dump' => count($stmts), 'tables_matched' => 0, 'rows_total' => 0, 'rows_dup' => 0, 'rows_bad' => 0, 'rows_migratable' => 0];

    foreach ($defs as $srcTable => $def) {
        if (empty($stmts[$srcTable])) continue;
        $stats['tables_matched']++;

        $targetTable = $def['table'];
        $liveCols = $getV2Cols($targetTable);
        $uniqueCol = $def['unique'];

        // Column mapping preview: v1 column → v2 column (+ exists flag)
        $colMap = [];
        foreach ($def['columns'] as $src => [$dst, $type]) {
            $colMap[] = [
                'src' => $src,
                'dst' => $dst,
                'type' => $type,
                'exists_in_v2' => in_array($dst, $liveCols, true),
            ];
        }

        // Parse rows
        $rows = [];
        foreach ($stmts[$srcTable] as $s) {
            $parsed = mig_parse_insert($s);
            if ($parsed === null) continue;
            $pc = $parsed['cols'];
            foreach ($parsed['rows'] as $tuple) {
                $assoc = [];
                if ($pc !== null) {
                    foreach ($pc as $i => $cname) {
                        $assoc[$cname] = $tuple[$i] ?? 'NULL';
                    }
                } else {
                    // positional — rely on map order (documented limitation)
                    $srcCols = array_keys($def['columns']);
                    foreach ($srcCols as $i => $cname) {
                        if (isset($tuple[$i])) $assoc[$cname] = $tuple[$i];
                    }
            }
                $rows[] = $assoc;
            }
        }

        // Duplicate / validity check against live DB (exact per-table tallies)
        $addCount = 0; $dupCount = 0; $badCount = 0;
        foreach ($rows as $ri => $assoc) {
            $migRow = [];   // dst column => value (cast)
            $bad = false;
            foreach ($def['columns'] as $src => [$dst, $type]) {
                $lit = mig_literal($assoc[$src] ?? 'NULL');
                [$ok, $val] = mig_cast($lit, $type);
                $migRow[$dst] = $val;
                if (!$ok || ($type === 'int' && $val === null && ($assoc[$src] ?? 'NULL') !== 'NULL')) $bad = true;
            }
            if ($bad) {
                $badCount++; $stats['rows_bad']++; $status = 'bad';
            } elseif ($uniqueCol !== null && isset($migRow[$uniqueCol]) && $migRow[$uniqueCol] !== null
                      && db_val("SELECT 1 FROM `{$targetTable}` WHERE `{$uniqueCol}` = ? LIMIT 1", [$migRow[$uniqueCol]])) {
                $dupCount++; $stats['rows_dup']++; $status = 'dup';
            } else {
                $addCount++; $stats['rows_migratable']++; $status = 'add';
            }
            if (count($rowList) < 200) {
                $rowList[] = ['table' => $srcTable, 'assoc' => $assoc, 'mig' => $migRow, 'status' => $status];
            }
        }
        $stats['rows_total'] += count($rows);

        $plan[$srcTable] = [
            'target' => $targetTable,
            'colmap' => $colMap,
            'rows_found' => count($rows),
            'rows_add' => $addCount,
            'rows_dup' => $dupCount,
            'rows_bad' => $badCount,
        ];
    }

    // Warnings
    if ($stats['rows_bad'] > 0) $warnings[] = "{$stats['rows_bad']} ردیف مقدار نامعتبر دارد و رد می‌شود";
    foreach ($plan as $srcTable => $p) {
        foreach ($p['colmap'] as $cm) {
            if (!$cm['exists_in_v2']) $warnings[] = "ستون مقصد «{$cm['dst']}» در جدول v2 «{$p['target']}» وجود ندارد — داده این ستون منتقل نخواهد شد";
        }
    }
    foreach ($stmts as $t => $list) {
        if (!isset($defs[$t])) $warnings[] = "جدول «{$t}» در فایل مهاجرت تعریف نشده — " . count($list) . " دستور INSERT نادیده گرفته می‌شود";
    }

    return ['defs' => $defs, 'plan' => $plan, 'rows' => $rowList, 'warnings' => $warnings, 'stats' => $stats, 'stmts' => $stmts];
}

/** Execute the migration for the analyzed dump inside a transaction. */
function mig_execute(array $analysis): array
{
    $defs = $analysis['defs'];
    $liveColsCache = [];
    $getLiveCols = function (string $table) use (&$liveColsCache): array {
        if (!isset($liveColsCache[$table])) {
            $liveColsCache[$table] = array_column(
                db_all(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                    [$table]
                ),
                'COLUMN_NAME'
            );
        }
        return $liveColsCache[$table];
    };

    $results = [];
    db()->beginTransaction();
    try {
        foreach ($defs as $srcTable => $def) {
            if (empty($analysis['stmts'][$srcTable])) continue;
            $targetTable = $def['table'];
            $uniqueCol = $def['unique'];
            $count = 0; $skip = 0; $err = 0;

            foreach ($analysis['stmts'][$srcTable] as $s) {
                $parsed = mig_parse_insert($s);
                if ($parsed === null) continue;
                $pc = $parsed['cols'];
                foreach ($parsed['rows'] as $tuple) {
                    $assoc = [];
                    if ($pc !== null) {
                        foreach ($pc as $i => $cname) $assoc[$cname] = $tuple[$i] ?? 'NULL';
                    } else {
                        $srcCols = array_keys($def['columns']);
                        foreach ($srcCols as $i => $cname) if (isset($tuple[$i])) $assoc[$cname] = $tuple[$i];
                    }

                    $migRow = [];
                    $bad = false;
                    foreach ($def['columns'] as $src => [$dst, $type]) {
                        $lit = mig_literal($assoc[$src] ?? 'NULL');
                        [$ok, $val] = mig_cast($lit, $type);
                        $migRow[$dst] = $val;
                        if ($val === null && ($assoc[$src] ?? 'NULL') !== 'NULL' && in_array($type, ['int', 'float'], true)) $bad = true;
                    }
                    if ($bad) { $err++; continue; }

                    if ($uniqueCol !== null && isset($migRow[$uniqueCol]) && $migRow[$uniqueCol] !== null) {
                        $exists = db_val("SELECT 1 FROM `{$targetTable}` WHERE `{$uniqueCol}` = ? LIMIT 1", [$migRow[$uniqueCol]]);
                        if ($exists) { $skip++; continue; }
                    }

                    // Only include columns that exist in the live v2 table
                    $live = $getLiveCols($targetTable);
                    $cols = [];
                    $params = [];
                    foreach ($migRow as $dst => $val) {
                        if (!in_array($dst, $live, true)) continue; // skip unknown columns
                        $cols[] = "`{$dst}`";
                        $params[] = $val;
                    }
                    if (!$cols) { $err++; continue; }
                    $ph = implode(',', array_fill(0, count($cols), '?'));
                    db_exec(
                        "INSERT INTO `{$targetTable}` (" . implode(',', $cols) . ") VALUES ({$ph})",
                        $params
                    );
                    $count++;
                }
            }
            $results[] = ['table' => $srcTable, 'inserted' => $count, 'skipped' => $skip, 'errors' => $err];
        }
        db()->commit();
        return ['ok' => true, 'results' => $results];
    } catch (Throwable $ex) {
        db()->rollBack();
        return ['ok' => false, 'error' => mb_substr($ex->getMessage(), 0, 300), 'results' => $results];
    }
}

/* ============================================================
   Shared page chrome
   ============================================================ */

function mig_page_head(): void
{
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1.0">';
    echo '<title>مهاجرت دیتابیس | SSAEE</title>';
    echo '<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">';
    echo '<link rel="shortcut icon" href="assets/favicon.ico">';
    echo '<style>
@font-face { font-family:"YekanBakh"; src:url("assets/fonts/YekanBakh-Regular.woff2") format("woff2"); font-weight:400; font-display:swap; }
@font-face { font-family:"YekanBakh"; src:url("assets/fonts/YekanBakh-Bold.woff2") format("woff2"); font-weight:700; font-display:swap; }
:root { --bg:#0b0b0d; --card:#141416; --border:#26262a; --text:#f2f2f3; --muted:#8b8b90; --accent:#ffcc00; --danger:#ff5252; --success:#4caf50; --warning:#ffa726; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:"YekanBakh",Tahoma,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding:24px 14px; }
.wrap { max-width:960px; margin:0 auto; }
h1 { font-size:1.15rem; color:var(--accent); margin-bottom:4px; }
.sub { color:var(--muted); font-size:.82rem; margin-bottom:22px; line-height:1.9; }
.card { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:20px; margin-bottom:16px; }
.card h2 { font-size:.95rem; margin-bottom:12px; color:var(--accent); display:flex; align-items:center; gap:8px; }
table { width:100%; border-collapse:collapse; font-size:.78rem; }
th, td { padding:8px 10px; text-align:right; border-bottom:1px solid var(--border); }
th { color:var(--accent); font-weight:600; white-space:nowrap; }
td code { background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:1px 6px; direction:ltr; display:inline-block; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle; }
.ok { color:var(--success); } .skip { color:var(--muted); } .err { color:var(--danger); } .warn { color:var(--warning); }
.badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:.7rem; font-weight:600; }
.badge-ok { background:rgba(76,175,80,.15); color:var(--success); }
.badge-dup { background:rgba(255,167,38,.15); color:var(--warning); }
.badge-bad { background:rgba(255,82,82,.15); color:var(--danger); }
.log-box { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:14px; font-size:.78rem; max-height:420px; overflow:auto; line-height:2.2; }
.drop-zone { border:2px dashed var(--border); border-radius:12px; padding:30px 16px; text-align:center; cursor:pointer; transition:border-color .2s; margin-bottom:14px; }
.drop-zone:hover, .drop-zone.drag { border-color:var(--accent); }
.drop-zone b { display:block; font-size:.88rem; margin-bottom:4px; }
.drop-zone span { font-size:.74rem; color:var(--muted); }
.btn { display:inline-block; text-align:center; border:none; border-radius:12px; padding:12px 22px;
    font-family:inherit; font-size:.88rem; font-weight:700; cursor:pointer;
    background:var(--accent); color:#000; margin-top:8px; text-decoration:none; }
.btn-danger { background:var(--danger); color:#fff; }
.btn:disabled { opacity:.4; cursor:not-allowed; }
.summary { background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:12px; margin-top:10px; }
.summary-item { display:flex; justify-content:space-between; padding:5px 0; font-size:.82rem; }
.summary-item .n { color:var(--accent); font-weight:700; }
.preview-note { font-size:.76rem; color:var(--muted); line-height:1.9; margin:10px 0; }
.table-scroll { max-height:460px; overflow:auto; border:1px solid var(--border); border-radius:10px; }
.table-scroll table { min-width:100%; }
.confirm-box { background:rgba(255,167,38,.07); border:1px solid var(--warning); border-radius:12px; padding:16px; margin-top:14px; }
.confirm-box p { font-size:.84rem; line-height:1.9; margin-bottom:10px; }
.stat-chips { display:flex; gap:10px; flex-wrap:wrap; margin:12px 0; }
.chip { background:var(--bg); border:1px solid var(--border); border-radius:999px; padding:6px 14px; font-size:.78rem; }
.chip b { color:var(--accent); }
</style></head><body><div class="wrap">';
}

function mig_page_foot(): void
{
    echo '</div></body></html>';
}

function mig_h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ============================================================
   Router: phase 1 (upload→preview) · phase 2 (confirm→execute)
   ============================================================ */

$step = $_POST['step'] ?? $_GET['step'] ?? 'upload';

/* ---------------- Phase 1: upload → analyze → preview ---------------- */
if ($step === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sqlfile'])) {
    if (!csrf_verify()) {
        mig_page_head();
        echo '<div class="card"><h2>⛔ خطا</h2><p class="err">درخواست نامعتبر (CSRF). صفحه را رفرش کنید.</p></div>';
        echo '<a class="btn" href="migrate.php">بازگشت</a>';
        mig_page_foot();
        exit;
    }

    $f = $_FILES['sqlfile'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        mig_page_head();
        echo '<div class="card"><h2>⛔ خطا</h2><p class="err">خطا در آپلود فایل</p></div>';
        echo '<a class="btn" href="migrate.php">بازگشت</a>';
        mig_page_foot();
        exit;
    }
    if ($f['size'] > 20 * 1024 * 1024) {
        mig_page_head();
        echo '<div class="card"><h2>⛔ خطا</h2><p class="err">حجم فایل بیش از ۲۰MB است</p></div>';
        echo '<a class="btn" href="migrate.php">بازگشت</a>';
        mig_page_foot();
        exit;
    }

    $content = (string)file_get_contents($f['tmp_name']);
    $analysis = mig_analyze($content);
    $token = mig_store_upload($content);

    /* ---------- Render preview ---------- */
    mig_page_head();
    echo '<h1>🔍 پیش‌نمایش مهاجرت (بدون هیچ تغییری اعمال شده)</h1>';
    echo '<p class="sub">فایل تحلیل شد. هیچ داده‌ای نوشته نشده است. لطفاً مقایسه ستون‌ها و ردیف‌ها را بررسی و در صورت صحت، تأیید کنید.</p>';

    $st = $analysis['stats'];
    echo '<div class="stat-chips">
        <span class="chip">جدول در فایل: <b>' . mig_h($st['tables_in_dump']) . '</b></span>
        <span class="chip">جدول قابل انتقال: <b>' . mig_h($st['tables_matched']) . '</b></span>
        <span class="chip">ردیف‌ها: <b>' . mig_h($st['rows_total']) . '</b></span>
        <span class="chip badge-ok">افزوده می‌شوند: <b>' . mig_h($st['rows_migratable']) . '</b></span>
        <span class="chip badge-dup">تکراری: <b>' . mig_h($st['rows_dup']) . '</b></span>
        <span class="chip badge-bad">نامعتبر: <b>' . mig_h($st['rows_bad']) . '</b></span>
    </div>';

    if ($analysis['warnings']) {
        echo '<div class="card"><h2>⚠️ هشدارها</h2><div class="log-box">';
        foreach ($analysis['warnings'] as $w) echo '<div class="warn">• ' . mig_h($w) . '</div>';
        echo '</div></div>';
    }

    echo '<div class="card"><h2>🔗 مقایسه ستون‌به‌ستون (v1 → v2)</h2>';
    foreach ($analysis['plan'] as $srcTable => $p) {
        echo '<h3 style="font-size:.85rem;margin:14px 0 8px">' . mig_h($srcTable) . ' → ' . mig_h($p['target']) . ' '
           . '<span class="badge badge-ok">' . mig_h($p['rows_add']) . ' افزوده</span> '
           . '<span class="badge badge-dup">' . mig_h($p['rows_dup']) . ' تکراری</span> '
           . '<span class="badge badge-bad">' . mig_h($p['rows_bad']) . ' نامعتبر</span></h3>';
        echo '<table><tr><th>ستون v1</th><th>ستون v2</th><th>نوع تبدیل</th><th>موجود در v2؟</th></tr>';
        foreach ($p['colmap'] as $cm) {
            $existsCls = $cm['exists_in_v2'] ? 'ok' : 'err';
            $existsTxt = $cm['exists_in_v2'] ? '✓ بله' : '✗ خیر';
            echo '<tr><td><code>' . mig_h($cm['src']) . '</code></td>'
               . '<td><code>' . mig_h($cm['dst']) . '</code></td>'
               . '<td>' . mig_h($cm['type']) . '</td>'
               . '<td class="' . $existsCls . '">' . $existsTxt . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    echo '<div class="card"><h2>📋 پیش‌نمایش ردیف‌به‌ردیف (حداکثر ۲۰۰ ردیف)</h2>';
    echo '<p class="preview-note">مقدار «v1 خام» همان‌طور که در فایل است؛ «v2 نهایی» بعد از تبدیل نوع. وضعیت: <span class="badge badge-ok">افزوده</span> <span class="badge badge-dup">تکراری (رد)</span> <span class="badge badge-bad">نامعتبر (رد)</span></p>';
    if ($analysis['rows']) {
        echo '<div class="table-scroll"><table><tr><th>جدول</th><th>وضعیت</th><th>مقادیر v1 (خام)</th><th>مقادیر v2 (نهایی)</th></tr>';
        foreach (array_slice($analysis['rows'], 0, 100) as $r) {
            $badge = $r['status'] === 'add' ? 'badge-ok' : ($r['status'] === 'dup' ? 'badge-dup' : 'badge-bad');
            $assocParts = [];
            foreach ($r['assoc'] as $k => $v) $assocParts[] = mig_h($k) . '=' . mig_h($v === null ? 'NULL' : $v);
            $migParts = [];
            foreach ($r['mig'] as $k => $v) $migParts[] = mig_h($k) . '=' . mig_h($v === null ? 'NULL' : $v);
            echo '<tr><td>' . mig_h($r['table']) . '</td>'
               . '<td><span class="badge ' . $badge . '">' . mig_h($r['status']) . '</span></td>'
               . '<td style="font-size:.7rem">' . mig_h(implode(' · ', array_slice($assocParts, 0, 8))) . '</td>'
               . '<td style="font-size:.7rem">' . mig_h(implode(' · ', array_slice($migParts, 0, 8))) . '</td></tr>';
        }
        echo '</table></div>';
    } else {
        echo '<p class="err">هیچ ردیف قابل انتقالی در فایل یافت نشد.</p>';
    }
    echo '</div>';

    /* ---------- Confirm form (phase 2) ---------- */
    $anyAdd = $st['rows_migratable'] > 0;
    echo '<div class="card confirm-box">';
    if ($anyAdd) {
        echo '<p>⚠️ با تأیید، <b>' . mig_h($st['rows_migratable']) . '</b> ردیف به دیتابیس v2 اضافه می‌شود (تکراری‌ها رد، نامعتبرها رد). کل عملیات در یک <b>تراکنش</b> است — با هر خطا، همه‌چیز برمی‌گردد.</p>';
        echo '<form method="post">';
        echo '<input type="hidden" name="csrf_token" value="' . mig_h(csrf_token()) . '">';
        echo '<input type="hidden" name="step" value="confirm">';
        echo '<input type="hidden" name="token" value="' . mig_h($token) . '">';
        echo '<button class="btn btn-danger" type="submit">✅ تأیید و اجرای مهاجرت (' . mig_h($st['rows_migratable']) . ' ردیف)</button>';
        echo ' <a class="btn" style="background:var(--border);color:var(--text)" href="migrate.php">انصراف و حذف فایل</a>';
        echo '</form>';
    } else {
        echo '<p class="warn">هیچ ردیف قابل انتقالی وجود ندارد — نیازی به اجرا نیست.</p>';
        echo '<a class="btn" href="migrate.php">بازگشت</a>';
    }
    echo '</div>';

    /* ---------- Current DB stats ---------- */
    echo mig_db_stats_card();
    mig_page_foot();
    exit;
}

/* ---------------- Phase 2: confirm → execute ---------------- */
if ($step === 'confirm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    mig_page_head();

    if (!csrf_verify()) {
        echo '<div class="card"><h2>⛔ خطا</h2><p class="err">درخواست نامعتبر (CSRF)</p></div><a class="btn" href="migrate.php">بازگشت</a>';
        mig_page_foot(); exit;
    }

    $token = $_POST['token'] ?? '';
    $content = mig_load_upload($token);
    if ($content === null) {
        echo '<div class="card"><h2>⏳ منقضی</h2><p class="err">پیش‌نمایش منقضی شده (بیش از ۲۰ دقیقه). فایل را دوباره آپلود کنید.</p></div><a class="btn" href="migrate.php">بازگشت</a>';
        mig_page_foot(); exit;
    }

    $analysis = mig_analyze($content);
    $res = mig_execute($analysis);
    mig_delete_upload($token);

    echo '<h1>📊 نتیجه مهاجرت</h1>';
    if ($res['ok']) {
        echo '<p class="sub ok">✔ تراکنش با موفقیت انجام و commit شد.</p>';
        audit_log((int)$admin['id'], 'db_migration', 'database', null, ['results' => $res['results']]);
    } else {
        echo '<p class="sub err">✖ تراکنش rollback شد: ' . mig_h($res['error']) . '</p>';
    }
    echo '<div class="card"><div class="log-box">';
    foreach ($res['results'] as $r) {
        echo '<div><b>' . mig_h($r['table']) . '</b> — '
           . '<span class="ok">' . mig_h($r['inserted']) . ' افزوده</span> · '
           . '<span class="skip">' . mig_h($r['skipped']) . ' تکراری</span> · '
           . '<span class="err">' . mig_h($r['errors']) . ' نامعتبر</span></div>';
    }
    echo '</div></div>';

    echo mig_db_stats_card();
    echo '<div class="card" style="text-align:center"><a href="migrate.php" style="color:var(--accent)">مهاجرت فایل دیگر</a> · <a href="admin/" style="color:var(--accent);text-decoration:none">← بازگشت به پنل</a></div>';
    mig_page_foot();
    exit;
}

/* ---------------- Default: upload page ---------------- */
mig_page_head();
echo '<h1>🔄 مهاجرت دیتابیس (نسخه ۱ → نسخه ۲)</h1>';
echo '<p class="sub">فایل SQL دیتابیس قدیمی را آپلود کنید. سیستم ابتدا <b>پیش‌نمایش کامل</b> (مقایسه ستون‌ها و ردیف‌ها) نشان می‌دهد و فقط با تأیید شما داده‌ها منتقل می‌شوند.<br>هیچ داده‌ای قبل از تأیید نوشته نمی‌شود.</p>';

echo mig_db_stats_card();

echo '<div class="card">';
echo '<h2>📤 آپلود فایل SQL</h2>';
echo '<p style="font-size:.78rem;color:var(--muted);margin-bottom:14px;line-height:1.9">فایل .sql خروجی phpMyAdmin از دیتابیس قدیمی. حداکثر ۲۰MB. پس از آپلود، پیش‌نمایش ۲۰ دقیقه اعتبار دارد.</p>';
echo '<form method="post" enctype="multipart/form-data">';
echo '<input type="hidden" name="csrf_token" value="' . mig_h(csrf_token()) . '">';
echo '<input type="hidden" name="step" value="upload">';
echo '<div class="drop-zone" onclick="document.getElementById(\'sqlFile\').click()">';
echo '<b>فایل SQL را اینجا بگذارید</b><span>یا کلیک کنید — تحلیل بدون نوشتن در دیتابیس</span>';
echo '</div>';
echo '<input type="file" name="sqlfile" id="sqlFile" accept=".sql" style="display:none" onchange="this.closest(\'form\').submit()">';
echo '</form>';
echo '</div>';

echo '<div class="card" style="text-align:center"><a href="admin/" style="color:var(--accent);text-decoration:none;font-size:.85rem">← بازگشت به پنل مدیریت</a></div>';
mig_page_foot();

/* ============================================================
   Shared: current DB stats card
   ============================================================ */
function mig_db_stats_card(): string
{
    ob_start();
    echo '<div class="card"><h2>📊 وضعیت فعلی دیتابیس v2</h2><div class="table-scroll"><table><tr><th>جدول</th><th>تعداد رکورد</th></tr>';
    $checkTables = ['users', 'members', 'membersreq', 'certificates', 'workshop_registrations', 'user_profiles', 'web_users', 'banners', 'activities', 'announcements', 'associations', 'teachers', 'companies', 'sponsor', 'suggestions', 'notifications', 'bot_admins'];
    foreach ($checkTables as $t) {
        try {
            $count = (int)db_val("SELECT COUNT(*) FROM `{$t}`");
            echo '<tr><td>' . $t . '</td><td>' . number_format($count) . '</td></tr>';
        } catch (Throwable $e) {
            echo '<tr><td>' . $t . '</td><td class="err">جدول موجود نیست</td></tr>';
        }
    }
    echo '</table></div></div>';
    return ob_get_clean();
}
