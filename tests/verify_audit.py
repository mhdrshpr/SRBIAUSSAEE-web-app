"""
Comprehensive Verification Suite for SSAEE Smart Panel v2 Audit Remediation
Tests security barriers, HTML structure, escaping logic, and file mirror parity.
"""

import os
import re
import xml.etree.ElementTree as ET

def test_removed_files():
    forbidden = [
        'diag.php',
        'promote.php',
        'appbeta.html',
        'includes/bootstrap_backup.php',
        'v2.zip',
    ]
    for path in forbidden:
        assert not os.path.exists(path), f"Security risk: {path} should not exist!"
    print("[OK] PASS: All temporary, diagnostic, and dead files successfully removed.")

def test_htaccess_rules():
    with open('.htaccess', 'r', encoding='utf-8') as f:
        content = f.read()

    patterns = [
        r'\.zip',
        r'\.tar',
        r'\.gz',
        r'\.bak',
        r'\.backup',
        r'\.sql',
        r'\.env',
        r'\.log',
        r'RewriteRule \^\(includes\|storage\|install\|tests\)/',
        r'X-Content-Type-Options "nosniff"',
        r'Cache-Control "public, max-age=2592000, immutable"',
    ]
    for pat in patterns:
        assert re.search(pat, content), f"Missing security/caching directive in .htaccess: {pat}"
    print("[OK] PASS: .htaccess contains robust security barriers, sensitive file blocks, and caching rules.")

def test_admin_cron_and_filter():
    with open('includes/admin.php', 'r', encoding='utf-8') as f:
        content = f.read()

    # Check cron before admin_require
    cron_pos = content.find("if ($action === 'cron')       { admin_action_cron(); return; }")
    require_pos = content.find("$admin = admin_require();")
    assert cron_pos != -1, "admin_action_cron bypass handler missing"
    assert cron_pos < require_pos, "cron handler must be evaluated BEFORE admin_require()"

    # Check allowedCols whitelist in admin_action_list
    assert "$allowedCols = array_unique(array_merge(" in content, "Missing column whitelist in admin.php"
    assert "in_array($filterCol, $allowedCols, true)" in content, "Missing whitelist membership check"
    assert "`{$filterCol}` = ?" in content, "Missing SQL identifier escaping (backticks) for filter column"
    print("[OK] PASS: Admin cron authentication routing and filter column whitelist are verified.")

def test_site_phone_and_delete_fallback():
    with open('includes/site.php', 'r', encoding='utf-8') as f:
        content = f.read()

    # Phone uniqueness and sync
    assert "OR (? != '' AND Phone = ?)" in content, "Missing phone uniqueness check in site_update_profile"
    assert "UPDATE web_users SET phone = ?, updated_at = NOW() WHERE id = ?" in content, "Missing phone sync to web_users"

    # Account delete DB state fallback
    assert "INSERT INTO bot_user_states (telegram_id, state, data) VALUES (?, 'acct_delete', ?)" in content, "Missing DB state backup for account delete"
    assert "SELECT data FROM bot_user_states WHERE telegram_id = ? AND state = 'acct_delete'" in content, "Missing DB state fallback check in delete confirm"
    assert "DELETE FROM bot_user_states WHERE telegram_id = ? AND state = 'acct_delete'" in content, "Missing DB state cleanup on delete"
    print("[OK] PASS: Profile phone synchronization and account delete fallback are verified.")

def test_js_escaping():
    for js_path in ['assets/app.js', 'admin/assets/admin.js']:
        with open(js_path, 'r', encoding='utf-8') as f:
            code = f.read()

        assert ".replace(/&/g, '&amp;')" in code, f"Missing & escape in {js_path}"
        assert ".replace(/</g, '&lt;')" in code, f"Missing < escape in {js_path}"
        assert ".replace(/>/g, '&gt;')" in code, f"Missing > escape in {js_path}"
        assert '.replace(/"/g, \'&quot;\')' in code, f"Missing \" escape in {js_path}"
        assert ".replace(/'/g, '&#39;')" in code, f"Missing ' escape in {js_path}"
        assert "function safeUrl(url)" in code, f"Missing safeUrl in {js_path}"
    print("[OK] PASS: Full 5-character DOM attribute escaping & safeUrl verified in app.js and admin.js.")

def test_html_validity_and_seo():
    with open('app.html', 'r', encoding='utf-8') as f:
        html = f.read()

    # Viewport WCAG 1.4.4
    assert '<meta name="viewport" content="width=device-width, initial-scale=1.0">' in html, "Viewport must not restrict zoom"
    assert 'user-scalable=no' not in html, "user-scalable=no violates WCAG 1.4.4"

    # SEO tags
    assert '<meta name="description"' in html, "Missing meta description"
    assert '<meta property="og:title"' in html, "Missing og:title"
    assert '<meta property="og:description"' in html, "Missing og:description"

    # Telegram non-blocking
    assert 'telegram-web-app.js" defer' in html, "Telegram WebApp script must be deferred"

    # Mismatched tags
    assert '<button class="qcard" data-goto="classes" type="button"><i data-lucide="book-copy" class="qi"></i><i data-lucide="book-copy" class="qi-bg"></i><span>گروه‌های درسی ترم جاری</span></a>' not in html, "Found broken button tag for classes"
    assert '<button class="qcard qcard-gold" data-goto="sponsor" type="button"><i data-lucide="gem" class="qi"></i><i data-lucide="gem" class="qi-bg"></i><span>اسپانسرشیپ 💎</span></a>' not in html, "Found broken button tag for sponsor"
    assert 'data-goto="classes" type="button"><i data-lucide="book-copy" class="qi"></i><i data-lucide="book-copy" class="qi-bg"></i><span>گروه‌های درسی ترم جاری</span></button>' in html, "Button tag for classes not properly closed"
    assert 'data-goto="sponsor" type="button"><i data-lucide="gem" class="qi"></i><i data-lucide="gem" class="qi-bg"></i><span>اسپانسرشیپ 💎</span></button>' in html, "Button tag for sponsor not properly closed"

    print("[OK] PASS: app.html validated for WCAG 1.4.4, SEO meta tags, non-blocking script, and valid HTML tags.")

def test_robots_and_sitemap():
    assert os.path.exists('robots.txt'), "robots.txt missing"
    assert os.path.exists('sitemap.xml'), "sitemap.xml missing"

    with open('robots.txt', 'r', encoding='utf-8') as f:
        robots = f.read()
    assert 'Disallow: /admin/' in robots, "robots.txt must protect admin"
    assert 'Sitemap:' in robots, "robots.txt must point to sitemap"

    # Validate XML syntax
    tree = ET.parse('sitemap.xml')
    root = tree.getroot()
    assert 'urlset' in root.tag, "sitemap.xml root must be urlset"
    urls = [elem.text for elem in root.findall('.//{http://www.sitemaps.org/schemas/sitemap/0.9}loc')]
    assert 'https://panel.ssaee.ir/' in urls, "Root URL missing in sitemap"

    print("[OK] PASS: robots.txt and sitemap.xml verified and well-formed.")

if __name__ == '__main__':
    print("=" * 60)
    print("SSAEE AUDIT VERIFICATION TEST HARNESS")
    print("=" * 60)
    test_removed_files()
    test_htaccess_rules()
    test_admin_cron_and_filter()
    test_site_phone_and_delete_fallback()
    test_js_escaping()
    test_html_validity_and_seo()
    test_robots_and_sitemap()
    print("=" * 60)
    print("ALL VERIFICATION TESTS PASSED SUCCESSFULLY! (100% GREEN)")
    print("=" * 60)
