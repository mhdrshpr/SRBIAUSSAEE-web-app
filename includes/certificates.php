<?php
/* ============================================================
   SSAEE — Certificate PNG renderer (image master output)
   -----------------------------------------------------------
   Text pipeline per field:
     database UTF-8 (logical order)
        ↓  PersianGd shaper: contextual presentation forms,
           lam-alef ligatures, ZWNJ join-break, bidi-lite visual order
        ↓  ONE imagettftext() call per field (FreeType lays out
           using the font's own advances — no per-glyph hacks)
        ↓  GD image → PNG stream (preview = inline, download = attachment)

   Font: LOCAL TTFs only (assets/fonts, shipped with the panel) —
   YekanBakh-Bold/Regular.ttf preferred, any other valid TTF in the
   folder as fallback. No CDN/network downloads. Verified at runtime:
   TTF magic + cmap coverage of the presentation-form codepoints
   (U+FE70–U+FEFF / U+FB50–U+FBFF).

   Coordinate semantics preserved:
      x_pos = LEFT edge of the text (English docs are left-aligned;
              the per-field `align` column may still say right/center)
      signature field: x,y = center of the square + width px
    ============================================================ */

/** Log a render failure into the site error_log (visible in the admin «خطاها» tab). */
function cert_fail(string $msg): string
{
    error_log('[cert_render] ' . $msg);
    return $msg;
}

/** php.ini shorthand byte-size ("128M", "1G", "-1") to int bytes; -1 = unlimited. */
function cert_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v[0] === '-') return -1;
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
}


/* ------------------------------------------------------------
   Schema (idempotent auto-migration)
   ------------------------------------------------------------ */

/** One DDL/DML statement with its own safety net (missing ALTER grants). */
function cert_ddl(string $sql): bool
{
    try { db_exec($sql); return true; }
    catch (Throwable $ex) { error_log('[cert_ddl] ' . $ex->getMessage()); return false; }
}

function cert_columns(string $table): array
{
    static $memo = [];
    if (array_key_exists($table, $memo)) return $memo[$table];
    $memo[$table] = [];
    try {
        $memo[$table] = array_column(
            db_all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]),
            'COLUMN_NAME'
        );
    } catch (Throwable $ex) { error_log('[cert_cols] ' . $ex->getMessage()); }
    return $memo[$table];
}

function cert_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;

    // Every statement is isolated: a missing privilege on one column never
    // blocks seeds/labels or the rest of the request.
    cert_ddl(
        "CREATE TABLE IF NOT EXISTS `cert_template_fields` (
          `id` INT NOT NULL AUTO_INCREMENT,
          `field_key` VARCHAR(50) NOT NULL,
          `label` VARCHAR(100) NOT NULL,
          `x_pos` INT NOT NULL DEFAULT 0,
          `y_pos` INT NOT NULL DEFAULT 0,
          `font_size` INT NOT NULL DEFAULT 24,
          `width` INT NOT NULL DEFAULT 0,
          `font_color` VARCHAR(7) NOT NULL DEFAULT '#000000',
          `align` VARCHAR(10) NOT NULL DEFAULT 'right',
          `enabled` TINYINT(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_field_key` (`field_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci"
    );
    $tf = cert_columns('cert_template_fields');
    if ($tf) {
        if (!in_array('width', $tf, true)) {
            cert_ddl("ALTER TABLE `cert_template_fields` ADD COLUMN `width` INT NOT NULL DEFAULT 0 AFTER `font_size`");
        }
        if (!in_array('align', $tf, true)) {
            cert_ddl("ALTER TABLE `cert_template_fields` ADD COLUMN `align` VARCHAR(10) NOT NULL DEFAULT 'right' AFTER `font_color`");
        }
        if (!in_array('enabled', $tf, true)) {
            cert_ddl("ALTER TABLE `cert_template_fields` ADD COLUMN `enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `align`");
        }
    }
    $cc = cert_columns('certificates');
    if ($cc) {
        if (!in_array('CertType', $cc, true)) cert_ddl("ALTER TABLE `certificates` ADD COLUMN `CertType` VARCHAR(200) NULL DEFAULT NULL AFTER `Course`");
        if (!in_array('CourseEn', $cc, true)) cert_ddl("ALTER TABLE `certificates` ADD COLUMN `CourseEn` VARCHAR(200) NULL DEFAULT NULL AFTER `Course`");
        if (in_array('FileLink', $cc, true)) cert_ddl("ALTER TABLE `certificates` DROP COLUMN `FileLink`");
    }
    $bm = cert_columns('board_members');
    if ($bm) {
        if (!in_array('name_en', $bm, true)) cert_ddl("ALTER TABLE `board_members` ADD COLUMN `name_en` VARCHAR(200) NULL DEFAULT NULL AFTER `name`");
        if (!in_array('signature', $bm, true)) cert_ddl("ALTER TABLE `board_members` ADD COLUMN `signature` VARCHAR(300) NULL DEFAULT NULL AFTER `photo`");
    }

    cert_ddl("INSERT IGNORE INTO `cert_template_fields`
              (`field_key`,`label`,`x_pos`,`y_pos`,`font_size`,`width`,`font_color`)
              VALUES ('signature','Stamp / Signature (square, center = point)',900,520,0,260,'#000000')");
    cert_ddl("INSERT IGNORE INTO `cert_template_fields`
              (`field_key`,`label`,`x_pos`,`y_pos`,`font_size`,`width`,`font_color`)
              VALUES ('print_date','Print Date',280,950,20,0,'#444444')");
    cert_ddl("INSERT IGNORE INTO `cert_template_fields`
              (`field_key`,`label`,`x_pos`,`y_pos`,`font_size`,`width`,`font_color`)
              VALUES ('president','Secretary Name',900,760,26,0,'#1a1a1a')");
    cert_ddl("UPDATE `cert_template_fields` SET `font_size` = 20 WHERE `field_key` IN ('issue_date','print_date') AND `font_size` > 20");
    cert_ddl("UPDATE `cert_template_fields` SET `width` = 260 WHERE `field_key` = 'signature' AND `width` < 260");
    // English documents: x_pos anchors the LEFT edge of the text (legacy RTL rows realigned once)
    cert_ddl("UPDATE `cert_template_fields` SET `align` = 'left' WHERE `align` = 'right' OR `align` = ''");
    foreach ([
        'name_en' => 'Name (EN)', 'name_fa' => 'Name (FA)', 'national_id' => 'National ID',
        'cert_id' => 'Certificate No.', 'course' => 'Course', 'issue_date' => 'Issue Date',
        'print_date' => 'Print Date', 'president' => 'Secretary Name',
        'custom_text' => 'Description / Type', 'signature' => 'Stamp / Signature (square, center = point)',
    ] as $k => $lbl) {
        try { db_exec("UPDATE `cert_template_fields` SET `label` = ? WHERE `field_key` = ?", [$lbl, $k]); } catch (Throwable $ex) {}
    }
    $ready = true;
    return $ready;
}

/** Hide a template field (kept in DB, excluded from render + editor). */
function cert_hide_field(string $key): bool
{
    $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($key)));
    if ($key === '') return false;
    cert_schema_ready();
    try {
        if (in_array('enabled', cert_columns('cert_template_fields'), true)) {
            db_exec("UPDATE `cert_template_fields` SET `enabled` = 0 WHERE `field_key` = ?", [$key]);
            return true;
        }
        // no enabled column (missing grants): delete the row (defaults re-seed later)
        db_exec("DELETE FROM `cert_template_fields` WHERE `field_key` = ?", [$key]);
        return true;
    } catch (Throwable $ex) {
        error_log('[cert_hide] ' . $ex->getMessage());
        return false;
    }
}

/** Restore one hidden field (by key). */
function cert_show_field(string $key): bool
{
    $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($key)));
    if ($key === '') return false;
    cert_schema_ready();
    if (!in_array('enabled', cert_columns('cert_template_fields'), true)) return false;
    try {
        db_exec("UPDATE `cert_template_fields` SET `enabled` = 1 WHERE `field_key` = ?", [$key]);
        return (int)db_val("SELECT COUNT(*) FROM `cert_template_fields` WHERE `field_key` = ?", [$key]) > 0;
    } catch (Throwable $ex) {
        error_log('[cert_show] ' . $ex->getMessage());
        return false;
    }
}

/** Restore every hidden field. */
function cert_show_all_fields(): int
{
    cert_schema_ready();
    if (!in_array('enabled', cert_columns('cert_template_fields'), true)) return 0;
    try { return db_exec("UPDATE `cert_template_fields` SET `enabled` = 1 WHERE `enabled` = 0"); } catch (Throwable $ex) { return 0; }
}

/** Keys/labels of currently hidden fields (for the restore UI). */
function cert_hidden_fields(): array
{
    cert_schema_ready();
    if (!in_array('enabled', cert_columns('cert_template_fields'), true)) return [];
    try {
        return db_all("SELECT field_key, label FROM cert_template_fields WHERE enabled = 0 ORDER BY id ASC");
    } catch (Throwable $ex) { return []; }
}

/* ------------------------------------------------------------
   Font manager: LOCAL TTFs only (assets/fonts) — no CDN/network
   ------------------------------------------------------------ */

function cert_fonts_dir(): string
{
    $d = BASE_PATH . '/assets/fonts';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

/** TTF file magic check (sfnt version 1.0 / 'true' / 'OTTO' / 'ttcf'). */
function cert_is_font_file(string $path): bool
{
    if (!is_file($path)) return false;
    $h = (string)@file_get_contents($path, false, null, 0, 4);
    return in_array($h, ["\x00\x01\x00\x00", "true", "OTTO", "ttcf"], true);
}

/** Legacy diagnostic hook (downloads removed; kept readable for old settings rows). */
function cert_font_last_error(): string
{
    return (string)setting('cert_font_last_error', '');
}

/**
 * Pick the best capable font per weight from LOCAL files only:
 * YekanBakh (bundled TTFs) > Vazirmatn (if placed manually) > any
 * *.ttf in the fonts dir. "Capable" = cmap contains a spread of
 * presentation forms (checked, not assumed).
 */
function cert_fonts_capable(): array
{
    static $res = null;
    if ($res !== null) return $res;
    $cps = [0xFE8D, 0xFEE6, 0xFEE7, 0xFEE8, 0xFECA, 0xFECB, 0xFB93, 0xFB94, 0xFEFB, 0xFBFC, 0xFB7A, 0xFB56, 0x0628, 0x06AF, 0x06CC];
    $okCp = function (string $p) use ($cps): bool { return cert_cmap_covers($p, $cps); };
    $rank = static fn(string $p): int => (cert_is_font_file($p) && $okCp($p)) ? 2 : (cert_is_font_file($p) ? 1 : 0);
    $pick = function (array $cands) use ($rank): array {
        $best = null; $score = -1;
        foreach ($cands as $c) {
            if ($c === '' || !is_file($c)) continue;
            $s = $rank($c);
            if ($s > $score) { $score = $s; $best = $c; }
        }
        if ($score <= 0) { // any TTF in the dir as last resort
            foreach (glob(cert_fonts_dir() . '/*.ttf') ?: [] as $c) {
                $s = $rank($c);
                if ($s > $score) { $score = $s; $best = $c; }
            }
        }
        return ['path' => $score > 0 ? $best : null, 'score' => max(0, $score)];
    };
    $f = cert_fonts_dir();
    $bold = $pick(["$f/YekanBakh-Bold.ttf", "$f/Vazirmatn-Bold.ttf", "$f/Vazirmatn-SemiBold.ttf", "$f/Vazirmatn-Medium.ttf"]);
    $reg  = $pick(["$f/YekanBakh-Regular.ttf", "$f/Vazirmatn-Regular.ttf", $bold['path'] ?? '']);
    return $res = ['bold' => $bold, 'reg' => $reg];
}

/** Which font is actually used (for admin diagnostics). */
function cert_active_font(): array
{
    $c = cert_fonts_capable();
    return [
        'bold'     => $c['bold']['path'] ? basename($c['bold']['path']) : null,
        'reg'      => $c['reg']['path'] ? basename($c['reg']['path']) : null,
        'shaping_ok' => ($c['bold']['score'] ?? 0) >= 2 && ($c['reg']['score'] ?? 0) >= 2,
    ];
}

/* ------------------------------------------------------------
   cmap reader (format 4 + format 12) — used for coverage checks
   ------------------------------------------------------------ */

function cert_cmap_covers(string $fontFile, array $codepoints): bool
{
    static $cache = [];
    $key = $fontFile . '|' . implode(',', $codepoints);
    if (array_key_exists($key, $cache)) return $cache[$key];
    $cache[$key] = false;
    $data = @file_get_contents($fontFile);
    if ($data === false || strlen($data) < 200) return false;

    $u16 = fn(string $s, int $o): int => (ord($s[$o]) << 8) | ord($s[$o + 1]);
    $u32 = fn(string $s, int $o): int => (ord($s[$o]) << 24) | (ord($s[$o + 1]) << 16) | (ord($s[$o + 2]) << 8) | ord($s[$o + 3]);

    $numTables = $u16($data, 4);
    $cmapOff = null;
    for ($i = 0; $i < $numTables; $i++) {
        $rec = 12 + 16 * $i;
        if ($rec + 16 > strlen($data)) return false;
        if (substr($data, $rec, 4) === 'cmap') { $cmapOff = $u32($data, $rec + 8); break; }
    }
    if ($cmapOff === null) return false;
    $subCount = $u16($data, $cmapOff + 2);
    $missing = array_fill_keys($codepoints, true);

    for ($s = 0; $s < $subCount && $missing; $s++) {
        $rec = $cmapOff + 4 + 8 * $s;
        $st = $cmapOff + $u32($data, $rec + 4);
        if ($st + 14 > strlen($data)) continue;
        $fmt = $u16($data, $st);

        if ($fmt === 12) {
            $nGroups = $u32($data, $st + 12);
            for ($g = 0; $g < $nGroups; $g++) {
                $go = $st + 16 + 12 * $g;
                if ($go + 12 > strlen($data)) break;
                $sc = $u32($data, $go); $ec = $u32($data, $go + 4);
                foreach (array_keys($missing) as $cp) {
                    if ($cp >= $sc && $cp <= $ec) unset($missing[$cp]);
                }
            }
            continue;
        }
        if ($fmt !== 4) continue;

        $seg = intdiv($u16($data, $st + 6), 2);
        $endBase = $st + 14;
        $startBase = $endBase + 2 * $seg + 2;
        $deltaBase = $startBase + 2 * $seg;
        $rangeBase = $deltaBase + 2 * $seg;
        if ($rangeBase + 2 * $seg > strlen($data)) continue;
        foreach (array_keys($missing) as $probe) {
            for ($g = 0; $g < $seg; $g++) {
                $endC = $u16($data, $endBase + 2 * $g);
                if ($probe > $endC) continue;
                $startC = $u16($data, $startBase + 2 * $g);
                if ($probe < $startC) break;
                $rangeOff = $u16($data, $rangeBase + 2 * $g);
                if ($rangeOff === 0) {
                    $dRaw = $u16($data, $deltaBase + 2 * $g);
                    $d = $dRaw >= 0x8000 ? $dRaw - 0x10000 : $dRaw;
                    if ((($probe + $d) & 0xFFFF) !== 0) unset($missing[$probe]);
                } else {
                    $addr = $rangeBase + 2 * $g + $rangeOff + 2 * ($probe - $startC);
                    if ($addr + 2 <= strlen($data) && $u16($data, $addr) !== 0) unset($missing[$probe]);
                }
                break;
            }
        }
    }
    return $cache[$key] = !$missing;
}

/* ------------------------------------------------------------
   PersianGd — the shaper (fixed joining rules; used ONLY with a
   font that carries real presentation-form glyphs, e.g. local
   YekanBakh / Vazirmatn TTFs)
   ------------------------------------------------------------ */

class PersianGd
{
    private const FORMS = [
        "\u{0621}" => ["\u{FE80}", null, null, null],
        "\u{0622}" => ["\u{FE81}", null, null, "\u{FE82}"],
        "\u{0623}" => ["\u{FE83}", null, null, "\u{FE84}"],
        "\u{0624}" => ["\u{FE85}", null, null, "\u{FE86}"],
        "\u{0625}" => ["\u{FE87}", null, null, "\u{FE88}"],
        "\u{0626}" => ["\u{FE89}", "\u{FE8A}", "\u{FE8B}", "\u{FE8C}"],
        "\u{0627}" => ["\u{FE8D}", null, null, "\u{FE8E}"],
        "\u{0628}" => ["\u{FE8F}", "\u{FE90}", "\u{FE91}", "\u{FE92}"],
        "\u{0629}" => ["\u{FE93}", null, null, "\u{FE94}"],
        "\u{062A}" => ["\u{FE95}", "\u{FE96}", "\u{FE97}", "\u{FE98}"],
        "\u{062B}" => ["\u{FE99}", "\u{FE9A}", "\u{FE9B}", "\u{FE9C}"],
        "\u{062C}" => ["\u{FE9D}", "\u{FE9E}", "\u{FE9F}", "\u{FEA0}"],
        "\u{0686}" => ["\u{FB7A}", "\u{FB7B}", "\u{FB7C}", "\u{FB7D}"],
        "\u{062D}" => ["\u{FEA1}", "\u{FEA2}", "\u{FEA3}", "\u{FEA4}"],
        "\u{062E}" => ["\u{FEA5}", "\u{FEA6}", "\u{FEA7}", "\u{FEA8}"],
        "\u{062F}" => ["\u{FEA9}", null, null, "\u{FEAA}"],
        "\u{0630}" => ["\u{FEAB}", null, null, "\u{FEAC}"],
        "\u{0631}" => ["\u{FEAD}", null, null, "\u{FEAE}"],
        "\u{0632}" => ["\u{FEAF}", null, null, "\u{FEB0}"],
        "\u{0698}" => ["\u{FB8A}", null, null, "\u{FB8B}"],
        "\u{0633}" => ["\u{FEB1}", "\u{FEB2}", "\u{FEB3}", "\u{FEB4}"],
        "\u{0634}" => ["\u{FEB5}", "\u{FEB6}", "\u{FEB7}", "\u{FEB8}"],
        "\u{0635}" => ["\u{FEB9}", "\u{FEBA}", "\u{FEBB}", "\u{FEBC}"],
        "\u{0636}" => ["\u{FEBD}", "\u{FEBE}", "\u{FEBF}", "\u{FEC0}"],
        "\u{0637}" => ["\u{FEC1}", "\u{FEC2}", "\u{FEC3}", "\u{FEC4}"],
        "\u{0638}" => ["\u{FEC5}", "\u{FEC6}", "\u{FEC7}", "\u{FEC8}"],
        "\u{0639}" => ["\u{FEC9}", "\u{FECA}", "\u{FECB}", "\u{FECC}"],
        "\u{063A}" => ["\u{FECD}", "\u{FECE}", "\u{FECF}", "\u{FED0}"],
        "\u{0640}" => ["\u{0640}", "\u{0640}", "\u{0640}", "\u{0640}"],
        "\u{0641}" => ["\u{FED1}", "\u{FED2}", "\u{FED3}", "\u{FED4}"],
        "\u{0642}" => ["\u{FED5}", "\u{FED6}", "\u{FED7}", "\u{FED8}"],
        "\u{06A9}" => ["\u{FB8E}", "\u{FB8F}", "\u{FB90}", "\u{FB91}"],
        "\u{0643}" => ["\u{FED9}", "\u{FEDA}", "\u{FEDB}", "\u{FEDC}"],
        "\u{06AF}" => ["\u{FB92}", "\u{FB93}", "\u{FB94}", "\u{FB95}"],
        "\u{0644}" => ["\u{FEDD}", "\u{FEDE}", "\u{FEDF}", "\u{FEE0}"],
        "\u{0645}" => ["\u{FEE1}", "\u{FEE2}", "\u{FEE3}", "\u{FEE4}"],
        "\u{0646}" => ["\u{FEE5}", "\u{FEE6}", "\u{FEE7}", "\u{FEE8}"],
        "\u{0647}" => ["\u{FEE9}", "\u{FEEA}", "\u{FEEB}", "\u{FEEC}"],
        "\u{0648}" => ["\u{FEED}", null, null, "\u{FEEE}"],
        "\u{0649}" => ["\u{FEEF}", null, null, "\u{FEF0}"],
        "\u{06CC}" => ["\u{FBFC}", "\u{FBFD}", "\u{FBFE}", "\u{FBFF}"],
        "\u{064A}" => ["\u{FEF1}", "\u{FEF2}", "\u{FEF3}", "\u{FEF4}"],
    ];
    private const LIGS = [
        "\u{0622}" => ["\u{FEF9}", "\u{FEFA}"],
        "\u{0623}" => ["\u{FEF5}", "\u{FEF6}"],
        "\u{0625}" => ["\u{FEF7}", "\u{FEF8}"],
        "\u{0627}" => ["\u{FEFB}", "\u{FEFC}"],
    ];
    private const ZWNJ = "\u{200C}";
    private const LAM = "\u{0644}";

    /** Joining rules: receives = prev dual; connects forward = dual AND next in FORMS. */
    public static function shapeSegment(string $seg): string
    {
        $chars = mb_str_split($seg, 1, 'UTF-8');
        if ($chars === false || $chars === []) return $seg;
        $out = []; $prev = false; $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            $ch = $chars[$i];
            if ($ch === self::ZWNJ) { $prev = false; continue; }
            if ($ch === self::LAM && isset($chars[$i + 1], self::LIGS[$chars[$i + 1]])) {
                $out[] = $prev ? self::LIGS[$chars[$i + 1]][1] : self::LIGS[$chars[$i + 1]][0];
                $prev = false; $i++; continue;
            }
            if (!isset(self::FORMS[$ch])) { $out[] = $ch; $prev = false; continue; }
            [$iso, $ini, $med, $fin] = self::FORMS[$ch];
            $next = $chars[$i + 1] ?? '';
            $nextJoin = isset(self::FORMS[$next]);
            $dual = $ini !== null;
            if ($prev && $dual && $nextJoin) $out[] = $med;
            elseif ($prev) $out[] = $fin;
            elseif ($dual && $nextJoin) $out[] = $ini;
            else $out[] = $iso;
            $prev = $dual && $nextJoin;
        }
        return implode('', array_reverse($out));
    }

    public static function shape(string $text): string
    {
        $text = str_replace(self::ZWNJ, '', $text);
        $cls = '0-9A-Za-z\x{06F0}-\x{06F9}\x{0660}-\x{0669}@.\-_\/: ';
        $parts = @preg_split('/([' . $cls . ']+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return $text;
        $vis = [];
        foreach ($parts as $p) {
            if ($p === '' || $p === null) continue;
            $vis[] = preg_match('/^[' . $cls . ']+$/u', $p) ? $p : self::shapeSegment($p);
        }
        return implode('', array_reverse($vis));
    }

    /** width of shaped string at px, per FreeType advances */
    public static function width(string $font, int $px, string $shaped): int
    {
        $b = @imagettfbbox($px, 0, $font, $shaped);
        if ($b === false) return 0;
        return (int)round(max($b[0], $b[2], $b[4], $b[6]) - min($b[0], $b[2], $b[4], $b[6]));
    }

    /** draw one logical string, right-anchored to $x, baseline $y */
    /**
     * Draw logical UTF-8 text, right-anchored to $x with baseline $y.
     * GLYPH-BY-GLYPH: every imagettftext call gets ONE codepoint, so the
     * active GD's bidi/shaping (raqm present OR absent) can never
     * re-order / re-join / mirror the pre-shaped string (that was the
     * «ـم instead of مـ» bug). Advances are FreeType's own (pair
     * difference for inked glyphs, origin-shift for spaces), cached.
     */
    public static function draw($img, string $text, string $font, int $px, int $col, int $x, int $y, string $align = 'right'): bool
    {
        $shaped = self::shape($text);
        if ($shaped === '') return true;
        $chars = mb_str_split($shaped, 1, 'UTF-8');
        if ($chars === false || $chars === []) return true;

        $adv = static function (string $ch) use ($font, $px): float {
            static $memo = [];
            $k = $font . '|' . $px . '|' . $ch;
            if (isset($memo[$k])) return $memo[$k];
            $b1 = @imagettfbbox($px, 0, $font, $ch);
            if ($b1 === false) return $memo[$k] = 0.0;
            $minX = (float)min($b1[0], $b1[2], $b1[4], $b1[6]);
            $maxX = (float)max($b1[0], $b1[2], $b1[4], $b1[6]);
            $a = 0.0;
            if ($maxX - $minX > 0) {
                // inked: width("cc") = advance + inkWidth  →  advance = diff
                $b2 = @imagettfbbox($px, 0, $font, $ch . $ch);
                if ($b2 !== false) {
                    $w2 = (float)(max($b2[0], $b2[2], $b2[4], $b2[6]) - min($b2[0], $b2[2], $b2[4], $b2[6]));
                    $a = max(0.0, $w2 - ($maxX - $minX));
                }
            } else {
                // zero ink (space…): shift of a reference glyph = advance
                $ref = "\u{0628}";
                $br = @imagettfbbox($px, 0, $font, $ref);
                $bc = @imagettfbbox($px, 0, $font, $ch . $ref);
                if ($br !== false && $bc !== false) {
                    $a = max(0.0, min($bc[0], $bc[2], $bc[4], $bc[6]) - min($br[0], $br[2], $br[4], $br[6]));
                }
            }
            return $memo[$k] = $a;
        };

        // ink box of the whole shaped line, from the engine's own metrics
        $full = @imagettfbbox($px, 0, $font, $shaped);
        if ($full === false) return false;
        $inkW = (float)(max($full[0], $full[2], $full[4], $full[6]) - min($full[0], $full[2], $full[4], $full[6]));

        $inkMinX = (float)min($full[0], $full[2], $full[4], $full[6]);
        $x = match ($align) {
            'left'   => (float)$x - $inkMinX,
            'center' => (float)$x - $inkW / 2 - $inkMinX,
            default  => (float)$x - $inkW - $inkMinX,
        };
        // start at leftmost ink edge; pen advances per glyph
        foreach ($chars as $ch) {
            if (@imagettftext($img, $px, 0, (int)round($x), $y, $col, $font, $ch) === false) return false;
            $x += $adv($ch);
        }
        return true;
    }
}

/* ------------------------------------------------------------
   Secretary (دبیر) resolution from the CURRENT board term.
   President name + signature for certificates AND membership
   cards are taken automatically from the board member holding
   the 'دبیر' badge of the active term (fallback: any current
   position starting with دبیر that is not نائب).
   ------------------------------------------------------------ */

function cert_secretary(): ?array
{
    static $sec = null;
    if ($sec !== null) return $sec;
    $sec = false;
    try {
        $row = db_one("SELECT id, name, name_en, signature, position FROM board_members
                       WHERE position = ? AND is_current = 1
                       ORDER BY start_date DESC, id DESC LIMIT 1", [board_secretary_position()]);
        if ($row === null) {
            $row = db_one("SELECT id, name, name_en, signature, position FROM board_members
                           WHERE is_current = 1 AND position LIKE ? AND position NOT LIKE ?
                           ORDER BY start_date DESC, id DESC LIMIT 1", ['دبیر%', '%نائب%']);
        }
        if ($row !== null) $sec = $row;
    } catch (Throwable $ex) {
        error_log('[cert_secretary] ' . $ex->getMessage());
    }
    return $sec === false ? null : $sec;
}

/** President name for documents (English-first). */
function cert_president_name(): string
{
    $s = cert_secretary();
    if ($s !== null) {
        $en = trim((string)($s['name_en'] ?? ''));
        if ($en !== '') return $en;
        $fa = trim((string)($s['name'] ?? ''));
        if ($fa !== '') return $fa;
    }
    return trim((string)setting('cert_president', 'Secretary'));
}

/** Signature/stamp file path of the secretary (auto) or legacy global upload. */
function cert_signature_path(): string
{
    $s = cert_secretary();
    if ($s !== null) {
        $rel = trim((string)($s['signature'] ?? ''));
        if ($rel !== '' && is_file(BASE_PATH . '/' . ltrim($rel, '/'))) {
            return BASE_PATH . '/' . ltrim($rel, '/');
        }
    }
    $rel = (string)setting('cert_signature_file', '');
    if ($rel === '') return '';
    $abs = BASE_PATH . '/' . ltrim($rel, '/');
    return is_file($abs) ? $abs : '';
}

/** English long date for documents (template language is English). */
function cert_en_date(?string $isoDate): string
{
    if ($isoDate === null || $isoDate === '') return '';
    $t = strtotime($isoDate);
    return $t === false ? '' : date('d F Y', $t);
}

/**
 * Paste the signature inside a SQUARE box whose CENTER is (x_pos,y_pos)
 * and whose side is `width` px (aspect of the image preserved, letter-
 * boxed inside the square).
 */
function cert_paste_centered($img, string $srcPath, int $cx, int $cy, int $side): bool
{
    $si = @getimagesize($srcPath);
    if (!$si || $side <= 0) return false;
    $sig = $si['mime'] === 'image/jpeg' ? @imagecreatefromjpeg($srcPath) : @imagecreatefrompng($srcPath);
    if (!$sig) return false;
    $sw = imagesx($sig); $sh = imagesy($sig);
    if ($sw <= 0 || $sh <= 0) { imagedestroy($sig); return false; }
    $scale = min($side / $sw, $side / $sh);
    $tw = max(1, (int)round($sw * $scale));
    $th = max(1, (int)round($sh * $scale));
    imagecopyresampled($img, $sig, (int)round($cx - $tw / 2), (int)round($cy - $th / 2), 0, 0, $tw, $th, $sw, $sh);
    imagedestroy($sig);
    return true;
}

/**
 * Optional QR provider: drop a phpqrcode-style file at
 * assets/php/qrcode.php exposing   string qr_png_bytes(string $text,int $px)
 * If absent, a placeholder square with the encoded value is drawn.
 */
function cert_draw_qr($img, string $data, int $cx, int $cy, int $size, string $font, int $px): void
{
    if ($data === '' || $size <= 0) return;
    $qrFile = BASE_PATH . '/assets/php/qrcode.php';
    if (is_file($qrFile)) {
        require_once $qrFile;
        if (function_exists('qr_png_bytes')) {
            $bytes = (string)@call_user_func('qr_png_bytes', $data, $size);
            $layer = $bytes !== '' ? @imagecreatefromstring($bytes) : false;
            if ($layer !== false) {
                imagecopyresampled($img, $layer, (int)round($cx - $size / 2), (int)round($cy - $size / 2), 0, 0, $size, $size, imagesx($layer), imagesy($layer));
                imagedestroy($layer);
                return;
            }
        }
    }
    // placeholder: bordered square with the payload text
    $col = imagecolorallocate($img, 40, 40, 40);
    $white = imagecolorallocate($img, 255, 255, 255);
    $x0 = (int)round($cx - $size / 2); $y0 = (int)round($cy - $size / 2);
    imagefilledrectangle($img, $x0, $y0, $x0 + $size, $y0 + $size, $white);
    imagerectangle($img, $x0, $y0, $x0 + $size, $y0 + $size, $col);
    imagettftext($img, max(8, $px), 0, $x0 + 6, $cy + (int)($px / 2), $col, $font, mb_substr($data, 0, 20));
}

/* ------------------------------------------------------------
   Membership card (same architecture as certificates)
   ------------------------------------------------------------ */

function card_template_path(): string
{
    $rel = (string)setting('card_template_file', '');
    if ($rel === '') return '';
    $abs = BASE_PATH . '/' . ltrim($rel, '/');
    return is_file($abs) ? $abs : '';
}

/** Card template fields (json settings) with English defaults. */
function card_template_fields(): array
{
    $fields = json_decode((string)setting('card_template_fields', '[]'), true);
    if (is_array($fields) && $fields !== []) return $fields;
    return [
        ['field_key' => 'name_en',    'label' => 'Member Name',        'x_pos' => 950, 'y_pos' => 330, 'font_size' => 30, 'font_color' => '#1a1a1a', 'width' => 0,  'align' => 'right'],
        ['field_key' => 'member_id',   'label' => 'Member No.',         'x_pos' => 950, 'y_pos' => 400, 'font_size' => 22, 'font_color' => '#333333', 'width' => 0,  'align' => 'right'],
        ['field_key' => 'student_id', 'label' => 'Student No.',        'x_pos' => 950, 'y_pos' => 445, 'font_size' => 20, 'font_color' => '#444444', 'width' => 0,  'align' => 'right'],
        ['field_key' => 'issue_date', 'label' => 'Issue Date',        'x_pos' => 950, 'y_pos' => 490, 'font_size' => 18, 'font_color' => '#666666', 'width' => 0,  'align' => 'right'],
        ['field_key' => 'qr',         'label' => 'QR (member no.)',    'x_pos' => 150,  'y_pos' => 430, 'font_size' => 10, 'font_color' => '#000000', 'width' => 150, 'align' => 'center'],
        ['field_key' => 'president',  'label' => 'Secretary Name',     'x_pos' => 500, 'y_pos' => 560, 'font_size' => 18, 'font_color' => '#333333', 'width' => 0,  'align' => 'right'],
        ['field_key' => 'signature',  'label' => 'Secretary Stamp',    'x_pos' => 500, 'y_pos' => 480, 'font_size' => 0,  'font_color' => '#000000', 'width' => 140, 'align' => 'center'],
    ];
}

function card_values(array $member): array
{
    return [
        'name_fa'    => trim((string)($member['NameFa'] ?? '')),
        'name_en'    => trim((string)($member['NameEn'] ?? '')) ?: trim((string)($member['NameFa'] ?? '')),
        'member_id'  => (string)($member['ID'] ?? ''),
        'student_id' => trim((string)($member['StudentID'] ?? '')),
        'national_id' => trim((string)($member['NationalID'] ?? '')),
        'issue_date' => cert_en_date($member['IssueDate'] ?? null) ?: cert_en_date(date('Y-m-d')),
        'exp_date'   => cert_en_date($member['ExpDate'] ?? null),
        'points'     => (string)($member['Points'] ?? '0'),
        'president'  => cert_president_name(),
        'custom_text' => '',
    ];
}

/** Render a membership card to PNG bytes (English template, secretary auto). */
function card_render_png(array $member, ?string &$error = null): ?string
{
    $error = null;
    if (!function_exists('imagettftext')) { $error = 'FreeType روی سرور فعال نیست'; return null; }
    $tpl = card_template_path();
    if ($tpl === '') { $error = 'قالب کارت عضویت هنوز آپلود نشده است'; return null; }
    $info = @getimagesize($tpl);
    if (!$info) { $error = 'قالب کارت قابل خواندن نیست'; return null; }
    $img = $info['mime'] === 'image/jpeg' ? @imagecreatefromjpeg($tpl) : @imagecreatefrompng($tpl);
    if (!$img) { $error = 'پردازش قالب کارت ناموفق بود'; return null; }
    imagealphablending($img, true);
    imagesavealpha($img, true);

    $fonts = cert_fonts_usable();
    if (empty($fonts['bold']) || empty($fonts['reg'])) {
        imagedestroy($img);
        $error = 'هیچ فونت TTF در assets/fonts نیست — YekanBakh-Bold/Regular.ttf را در این پوشه قرار دهید';
        return null;
    }

    $vals = card_values($member);
    $sigPath = cert_signature_path();

    foreach (card_template_fields() as $f) {
        $key = (string)($f['field_key'] ?? '');
        if ($key === '') continue;
        if ($key === 'signature') {
            if ($sigPath !== '') {
                cert_paste_centered($img, $sigPath, (int)($f['x_pos'] ?? 0), (int)($f['y_pos'] ?? 0), max(24, (int)($f['width'] ?? 140)));
            }
            continue;
        }
        if ($key === 'qr') {
            cert_draw_qr($img, (string)($vals['member_id'] ?? ''), (int)($f['x_pos'] ?? 0), (int)($f['y_pos'] ?? 0),
                max(40, (int)($f['width'] ?? 150)), (string)$fonts['reg']['path'], 10);
            continue;
        }
        $val = (string)($vals[$key] ?? '');
        if ($val === '') continue;
        $font = in_array($key, ['name_en', 'name_fa'], true) ? $fonts['bold']['path'] : $fonts['reg']['path'];
        $px = max(10, (int)($f['font_size'] ?: 24));
        $rgb = cert_hex_rgb((string)($f['font_color'] ?: '#000000'));
        $col = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        PersianGd::draw($img, $val, (string)$font, $px, (int)$col, (int)($f['x_pos'] ?? 0), (int)($f['y_pos'] ?? 0), (string)($f['align'] ?: 'right'));
    }

    ob_start();
    $ok = imagepng($img, null, 6);
    $bytes = (string)ob_get_clean();
    imagedestroy($img);
    if (!$ok || $bytes === '') { $error = 'تولید کارت ناموفق بود'; return null; }
    return $bytes;
}

/**
 * Fonts for PRODUCTION rendering.
 * Documents are English now, so presentation-form coverage is a
 * preference, not a requirement: any readable TTF is usable.
 * Preference order: font with full joining coverage > any TTF present.
 * Returns ['bold'=>?path,'reg'=>?path,'pref'=>bool].
 */
function cert_fonts_usable(): array
{
    static $res = null;
    if ($res !== null) return $res;
    $cap = cert_fonts_capable();
    $f = cert_fonts_dir();
    $anyBold = null; $anyReg = null;
    foreach (["$f/YekanBakh-Bold.ttf", "$f/Vazirmatn-Bold.ttf", "$f/Vazirmatn-SemiBold.ttf", "$f/Vazirmatn-Medium.ttf"] as $p) {
        if ($anyBold === null && cert_is_font_file($p)) $anyBold = $p;
    }
    foreach (["$f/YekanBakh-Regular.ttf", "$f/Vazirmatn-Regular.ttf", "$f/Vazirmatn-Light.ttf"] as $p) {
        if ($anyReg === null && cert_is_font_file($p)) $anyReg = $p;
    }
    $bold = ($cap['bold']['path'] ?? null) ?: $anyBold;
    $reg  = ($cap['reg']['path'] ?? null) ?: $anyReg ?: $bold;
    $pref = (($cap['bold']['score'] ?? 0) >= 2 && ($cap['reg']['score'] ?? 0) >= 2);
    return $res = ['bold' => $bold, 'reg' => $reg, 'pref' => $pref];
}

/* ------------------------------------------------------------
   Renderer (certificates)
   ------------------------------------------------------------ */

function cert_template_path(): string
{
    $rel = (string)setting('cert_template_file', '');
    return $rel !== '' && is_file(BASE_PATH . '/' . ltrim($rel, '/')) ? BASE_PATH . '/' . ltrim($rel, '/') : '';
}
/**
 * Template field rows. $onlyEnabled=true → active fields (renderer);
 * false → every field incl. hidden ones (editor shows them faded).
 */
function cert_template_fields(bool $onlyEnabled = true): array
{
    cert_schema_ready();
    // Tolerate missing optional columns (align/width/enabled) on servers without ALTER grants.
    $cols = cert_columns('cert_template_fields');
    $sel = ['field_key', 'label', 'x_pos', 'y_pos', 'font_size', 'font_color'];
    foreach (['width', 'align', 'enabled'] as $opt) {
        if (in_array($opt, $cols, true)) $sel[] = $opt;
    }
    $hasEnabled = in_array('enabled', $cols, true);
    $where = ($onlyEnabled && $hasEnabled) ? ' WHERE `enabled` = 1' : '';
    try {
        $rows = db_all('SELECT ' . implode(', ', $sel) . ' FROM cert_template_fields' . $where . ' ORDER BY id ASC');
    } catch (Throwable $ex) {
        error_log('[cert_fields] ' . $ex->getMessage());
        return [];
    }
    foreach ($rows as &$r) {
        $r['width'] = isset($r['width']) ? (int)$r['width'] : 0;
        // English template: default alignment is LEFT
        $r['align'] = isset($r['align']) && $r['align'] !== null && $r['align'] !== '' ? (string)$r['align'] : 'left';
        $r['enabled'] = $hasEnabled ? (int)($r['enabled'] ?? 1) : 1;
    }
    unset($r);
    return $rows;
}
function cert_hex_rgb(string $hex): array
{
    $c = sscanf($hex, '#%02x%02x%02x');
    return [(int)($c[0] ?? 0), (int)($c[1] ?? 0), (int)($c[2] ?? 0)];
}

function cert_render_png(array $cert, ?string &$error = null): ?string
{
    $error = null;
    if (!function_exists('imagettftext')) { $error = cert_fail('FreeType روی سرور فعال نیست (GD بدون پشتیبانی TTF)'); return null; }
    $tpl = cert_template_path();
    if ($tpl === '') { $error = cert_fail('قالب گواهینامه هنوز آپلود نشده است'); return null; }
    $info = @getimagesize($tpl);
    if (!$info) { $error = cert_fail('قالب قابل خواندن نیست: ' . basename($tpl)); return null; }

    /* GD needs roughly 5–6 bytes per pixel: raise the memory ceiling when the
       host allows it, otherwise fail with a clear message instead of a fatal
       (that used to surface as an unexplained HTTP 503 on big templates). */
    @set_time_limit(300);
    $need = (int)($info[0] * $info[1] * 6) + 8 * 1024 * 1024;
    $mem = cert_ini_bytes((string)ini_get('memory_limit'));
    if ($mem > 0 && memory_get_usage(true) + $need > $mem) {
        @ini_set('memory_limit', (string)(intdiv($need + memory_get_usage(true), 1048576) + 64) . 'M');
        $mem = cert_ini_bytes((string)ini_get('memory_limit'));
    }
    if ($mem > 0 && memory_get_usage(true) + $need > $mem) {
        $error = cert_fail(sprintf(
            'رزولوشن قالب (%d×%d پیکسل) با سقف حافظه سرور (%dMB) قابل پردازش نیست — قالب سبک‌تر آپلود کنید',
            $info[0], $info[1], intdiv($mem, 1048576)
        ));
        return null;
    }

    $img = $info['mime'] === 'image/jpeg' ? @imagecreatefromjpeg($tpl) : @imagecreatefrompng($tpl);
    if (!$img) { $error = cert_fail('پردازش قالب ناموفق بود (حافظه/فرمت تصویر)'); return null; }
    imagealphablending($img, true);
    imagesavealpha($img, true);

    $fonts = cert_fonts_usable();
    if (empty($fonts['bold']) || empty($fonts['reg'])) {
        imagedestroy($img);
        $error = cert_fail('هیچ فونت TTF در assets/fonts یافت نشد — فایل‌های YekanBakh-Bold.ttf و YekanBakh-Regular.ttf را در این پوشه قرار دهید');
        return null;
    }
    if (!$fonts['pref']) {
        error_log('[cert] note: rendering with a non-shaping font (fine for English text): ' . basename((string)$fonts['bold']));
    }

    // English document texts (template language = English).
    $courseEn = trim((string)($cert['CourseEn'] ?? ''));
    $data = [
        'name_fa'     => trim((string)($cert['NameFa'] ?? '')),
        'name_en'     => trim((string)($cert['NameEn'] ?? '')) ?: trim((string)($cert['NameFa'] ?? '')),
        'national_id' => trim((string)($cert['NationalID'] ?? '')),
        'cert_id'     => trim((string)($cert['ID'] ?? '')),
        'course'      => $courseEn !== '' ? $courseEn : trim((string)($cert['Course'] ?? '')),
        'issue_date'  => cert_en_date($cert['IssueDate'] ?? null) ?: cert_en_date(date('Y-m-d')),
        'print_date'  => cert_en_date(date('Y-m-d')),
        'exp_date'    => cert_en_date($cert['ExpDate'] ?? null) ?: 'Permanent',
        'president'   => cert_president_name(),
        'custom_text' => trim((string)($cert['CertType'] ?? '')),
    ];
    $sigPath = cert_signature_path();

    $okCount = 0; $fail = 0;
    foreach (cert_template_fields() as $f) {
        $key = (string)$f['field_key'];
        if ($key === 'signature') {
            // x_pos/y_pos = CENTER of the square; width = side length
            if ($sigPath === '') continue;
            $side = max(24, (int)($f['width'] ?? 260));
            if (cert_paste_centered($img, $sigPath, (int)$f['x_pos'], (int)$f['y_pos'], $side)) $okCount++;
            continue;
        }
        $val = $data[$key] ?? '';
        if ($val === '') continue;
        // English documents: left-aligned by default (x_pos = LEFT edge)
        $font = in_array($key, ['name_fa', 'name_en'], true) ? $fonts['bold'] : $fonts['reg'];
        $px = max(16, (int)($f['font_size'] ?: 32));
        $rgb = cert_hex_rgb((string)($f['font_color'] ?: '#000000'));
        $col = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        $align = strtolower((string)($f['align'] ?? ''));
        if ($align !== 'center' && $align !== 'right') $align = 'left';
        $drawn = PersianGd::draw($img, $val, (string)$font, $px, (int)$col, (int)$f['x_pos'], (int)$f['y_pos'], $align);
        $drawn ? $okCount++ : $fail++;
        if (!$drawn) error_log('[cert] draw failed for ' . $key);
    }
    if ($okCount === 0 && $fail > 0) { imagedestroy($img); $error = cert_fail('رندر متن ناموفق بود (فونت/FreeType)'); return null; }

    ob_start();
    $ok = imagepng($img, null, 6);
    $bytes = (string)ob_get_clean();
    imagedestroy($img);
    if (!$ok || $bytes === '') { $error = cert_fail('تولید PNG ناموفق بود'); return null; }
    return $bytes;
}

/** Load a certificate row, tolerating missing optional columns. */
function cert_certificate_row(string $certId): ?array
{
    static $cols = null;
    if ($cols === null) {
        try {
            $cols = array_column(db_all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificates'"), 'COLUMN_NAME');
        } catch (Throwable $ex) { $cols = []; }
    }
    $sel = ['ID', 'NameEn', 'NameFa', 'NationalID', 'Course', 'CertType', 'IssueDate', 'ExpDate'];
    if (is_array($cols) && in_array('CourseEn', $cols, true)) $sel[] = 'CourseEn';
    try {
        $row = db_one('SELECT ' . implode(', ', $sel) . ' FROM certificates WHERE ID = ? LIMIT 1', [$certId]);
    } catch (Throwable $ex) {
        error_log('[cert_row] ' . $ex->getMessage());
        $row = db_one("SELECT ID, NameEn, NameFa, NationalID, Course, IssueDate, ExpDate FROM certificates WHERE ID = ? LIMIT 1", [$certId]);
    }
    if ($row !== null && !isset($row['CertType'])) $row['CertType'] = null;
    if ($row !== null && !isset($row['CourseEn'])) $row['CourseEn'] = null;
    return $row;
}

function cert_stream_png(string $certId, bool $attachment): void
{
    cert_schema_ready();
    $row = cert_certificate_row($certId);
    if ($row === null) json_err('گواهینامه‌ای با این کد یافت نشد', 404);
    $err = null;
    try {
        $png = cert_render_png($row, $err);
    } catch (Throwable $ex) {
        error_log('[cert_stream] ' . $ex->getMessage());
        json_err('تولید تصویر ناموفق بود — ' . mb_substr($ex->getMessage(), 0, 120), 503);
    }
    if ($png === null) json_err($err ?: 'تولید تصویر ناموفق بود', 503);
    $safe = preg_replace('/[^A-Za-z0-9\-]/', '', $certId);
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($png));
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($attachment ? 'attachment' : 'inline') . '; filename="certificate-' . $safe . '.png"');
    echo $png;
    exit;
}

/* ------------------------------------------------------------
   Admin status + sample + selftest/glyph sheet (diagnostics)
   ------------------------------------------------------------ */

function cert_render_status(): array
{
    $cap = cert_fonts_capable();
    $missing = [];
    if (($cap['bold']['path'] ?? null) !== null) {
        foreach ([0xFE8D, 0xFEE6, 0xFEE7, 0xFEE8, 0xFECA, 0xFECB, 0xFB93, 0xFB94, 0xFEFB] as $cp) {
            if (!cert_cmap_covers((string)$cap['bold']['path'], [$cp])) $missing[] = 'U+' . strtoupper(dechex($cp));
        }
    }
    $shapingOk = ($cap['bold']['score'] ?? 0) >= 2 && ($cap['reg']['score'] ?? 0) >= 2 && function_exists('imagettftext');
    $ok = ($cap['bold']['path'] ?? null) !== null && ($cap['reg']['path'] ?? null) !== null && function_exists('imagettftext');

    return [
        'ok'         => $ok,
        'renderer'   => 'gd-freetype + PersianGd (per-glyph)',
        'font'       => ($cap['bold']['path'] ?? null) !== null ? basename($cap['bold']['path']) : null,
        'font_bold'  => ($cap['bold']['path'] ?? null) !== null ? basename($cap['bold']['path']) : null,
        'font_reg'   => ($cap['reg']['path'] ?? null) !== null ? basename($cap['reg']['path']) : null,
        'shaping_ok' => $shapingOk,
        'missing'    => $missing,
        'font_error' => cert_font_last_error(),
        'template'   => cert_template_path() !== '',
        'freetype'   => function_exists('imagettftext'),
        'note'       => $shapingOk
            ? 'فونت لوکال با گلیف‌های اتصال فعال است (assets/fonts)'
            : 'فونت کامل یافت نشد — فایل‌های YekanBakh-Bold.ttf و YekanBakh-Regular.ttf را در پوشه assets/fonts قرار دهید (دانلود CDN حذف شده است).',
    ];
}

function cert_sample_png(?string &$error = null): ?string
{
    $error = null;
    return cert_render_png([
        'ID' => 'CRT-SAMPLE', 'NameFa' => 'علی محمدی', 'NameEn' => 'Ali Mohammadi',
        'NationalID' => '0012345678', 'Course' => 'مهندسی برق', 'CourseEn' => 'Electrical Engineering',
        'CertType' => 'Attendance Certificate',
        'IssueDate' => date('Y-m-d'), 'ExpDate' => null,
    ], $error);
}

/** sheet of the mandatory test corpus through the real pipeline */
function cert_selftest_png(): ?string
{
    $cap = cert_fonts_capable();
    $font = $cap['bold']['path'] ?? null;
    if ($font === null || !function_exists('imagettftext')) return null;
    $rows = ['سلام', 'محمدرضا احمدی', 'مهندسی برق', 'نگار', 'علی', 'مهندس', 'گواهینامه', 'برق در خدمت زندگی', 'می‌روم', 'لا', 'IoT و هوشمندسازی', 'Electrical Engineering', 'کد 1405-001', '۱۴۰۵/۰۶/۲۰'];
    $w = 1100; $rowH = 64; $top = 56;
    $img = imagecreatetruecolor($w, $top + $rowH * count($rows) + 20);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    $black = imagecolorallocate($img, 20, 20, 20);
    $blue = imagecolorallocate($img, 30, 80, 160);
    imagettftext($img, 13, 0, 12, 34, $blue, $font, 'selftest — font: ' . basename($font) . ' (shaping: ' . (($cap['bold']['score'] ?? 0) >= 2 ? 'full' : 'partial') . ')');
    $y = $top + 34;
    foreach ($rows as $r) {
        PersianGd::draw($img, $r, $font, 28, $black, $w - 14, $y, 'right');
        $y += $rowH;
    }
    ob_start();
    imagepng($img, null, 6);
    imagedestroy($img);
    $b = (string)ob_get_clean();
    return $b !== '' ? $b : null;
}

/** glyph table: each presentation form drawn alone with its codepoint */
function cert_glyph_sheet_png(): ?string
{
    $cap = cert_fonts_capable();
    $font = $cap['bold']['path'] ?? null;
    if ($font === null) return null;
    $forms = (function (): array { $r = new ReflectionClass(PersianGd::class); return $r->getConstant('FORMS'); })();
    $w = 880; $rowH = 86; $top = 90; $cell = 130; $nameCol = 150;
    $img = imagecreatetruecolor($w, $top + $rowH * max(1, count($forms)) + 20);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    $black = imagecolorallocate($img, 20, 20, 20);
    $gray = imagecolorallocate($img, 130, 130, 130);
    $blue = imagecolorallocate($img, 30, 80, 160);
    imagettftext($img, 12, 0, 10, 32, $blue, $font, 'font: ' . basename($font) . '  |  base / isolated / initial / medial / final  (codepoint under each)');
    foreach (['BASE', 'ISO', 'INIT', 'MED', 'FINA'] as $i => $h) {
        imagettftext($img, 11, 0, $nameCol + $i * $cell + 34, 66, $blue, $font, $h);
    }
    $y = $top + 40;
    foreach ($forms as $base => $set) {
        $cp = mb_ord($base, 'UTF-8');
        imagettftext($img, 20, 0, 12, $y, $black, $font, $base);
        imagettftext($img, 9, 0, 64, $y, $gray, $font, 'U+' . strtoupper(dechex($cp)));
        foreach ([ $base, $set[0], $set[1], $set[2], $set[3] ] as $i => $cellv) {
            $cx = $nameCol + $i * $cell;
            if ($cellv === null || $cellv === '') { imagettftext($img, 12, 0, $cx + 58, $y, $gray, $font, '-'); continue; }
            imagettftext($img, 30, 0, $cx + 40, $y, $black, $font, $cellv);
            imagettftext($img, 8, 0, $cx + 28, $y + 18, $gray, $font, 'U+' . strtoupper(dechex(mb_ord($cellv, 'UTF-8'))));
        }
        $y += $rowH;
    }
    ob_start();
    imagepng($img, null, 6);
    imagedestroy($img);
    $b = (string)ob_get_clean();
    return $b !== '' ? $b : null;
}
