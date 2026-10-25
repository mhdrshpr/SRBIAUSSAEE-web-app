/* ============================================================
   SSAEE Admin Panel v2 — SPA logic
   Theme (dark/light) · Charts · PDF export · Live search
   Multi-role · Responsive
   ============================================================ */
'use strict';

(() => {

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const state = {
    admin: null,
    meta: null,
    section: null,
    entity: null,
    page: 1,
    search: '',
    alerts: {},
  };

  let charts = [];

  const API_BASE = location.pathname + '?api=';
  function endpoint(name, qs = '') {
    return API_BASE + name + (qs ? (qs.startsWith('?') ? '' : '&') + qs : '');
  }

  /* ---------------- helpers ---------------- */

  /* Robust icon rendering: lucide is vendored locally; if it ever fails
     to load, retry briefly and fall back to a pinned CDN copy. */
  let iconPoll = null, iconTries = 0, iconCdn = false;
  function renderIconsNow() {
    try { lucide.createIcons(); } catch (err) { /* noop */ }
  }
  function refreshIcons() {
    if (window.lucide?.createIcons) {
      renderIconsNow();
      return;
    }
    if (iconPoll) return; // a poller is already running
    iconPoll = setInterval(() => {
      if (window.lucide?.createIcons) {
        clearInterval(iconPoll);
        iconPoll = null;
        renderIconsNow();
        return;
      }
      if (++iconTries === 25 && !iconCdn) {
        iconCdn = true; // local file missing → CDN fallback (pinned version)
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/lucide@0.462.0/dist/umd/lucide.min.js';
        document.head.appendChild(s);
      }
      if (iconTries >= 100) { clearInterval(iconPoll); iconPoll = null; }
    }, 120);
  }

  function esc(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function safeUrl(url) {
    const s = String(url ?? '').trim();
    if (!s) return '#';
    if (/^(https?:|mailto:|tel:|\/|#)/i.test(s)) return s;
    return '#';
  }

  function faNum(v) {
    return String(v ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
  }

  function faToday() {
    try { return new Date().toLocaleDateString('fa-IR'); }
    catch (err) { return new Date().toISOString().slice(0, 10); }
  }

  function debounce(fn, ms) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
  }

  function cssVar(name) {
    return getComputedStyle(document.body).getPropertyValue(name).trim();
  }

  let toastTimer;
  function toast(msg, ok = true) {
    let el = $('#adminToast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'adminToast';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.style.borderColor = ok ? 'var(--accent)' : 'var(--danger)';
    clearTimeout(toastTimer);
    el.style.display = 'block';
    toastTimer = setTimeout(() => { el.style.display = 'none'; }, 4000);
  }

  async function api(name, opts = {}) {
    const headers = { Accept: 'application/json', 'X-CSRF-Token': window.ADMIN.csrf };
    let body = opts.body;

    if (body && typeof body === 'object') {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }

    let res;
    try {
      res = await fetch(endpoint(name), {
        method: opts.method || 'GET',
        headers,
        body: opts.method === 'GET' ? undefined : body,
      });
    } catch (err) {
      throw new Error('خطا در ارتباط با سرور');
    }

    let data;
    try {
      data = await res.json();
    } catch (err) {
      throw new Error('پاسخ نامعتبر از سرور');
    }

    if (res.status === 401) {
      renderLogin();
      throw new Error(data.message || 'احراز هویت لازم است');
    }
    if (!res.ok || data.status === 'error') {
      throw new Error(data.message || 'خطای سرور');
    }
    return data;
  }

  async function apiTry(name, opts, silent = false) {
    try {
      return await api(name, opts);
    } catch (err) {
      if (!silent) toast(err.message, false);
      return null;
    }
  }

  /* busy state for buttons (spinner + disable) */
  function busy(btn, on, label) {
    if (!btn) return;
    if (on) {
      btn.dataset.label = btn.innerHTML;
      btn.classList.add('loading');
      btn.disabled = true;
      if (label) btn.innerHTML = `<span class="btn-label">${esc(label)}</span>`;
    } else {
      btn.classList.remove('loading');
      btn.disabled = false;
      if (btn.dataset.label) btn.innerHTML = btn.dataset.label;
      refreshIcons();
    }
  }

  /* copy text to clipboard with graceful fallback (http / old browsers) */
  async function copyToClipboard(text) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (err) {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.cssText = 'position:fixed;top:-50px;opacity:0';
      document.body.appendChild(ta);
      ta.select();
      let ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      ta.remove();
      return ok;
    }
  }

  /* ---------------- local confirm (replaces native) ---------------- */


  function uiConfirm(message, { danger = true, okLabel = 'تأیید', cancelLabel = 'انصراف' } = {}) {
    return new Promise(resolve => {
      let modal = document.getElementById('uiConfirmModal');
      if (!modal) {
        modal = document.createElement('div');
        modal.className = 'modal-bg';
        modal.id = 'uiConfirmModal';
        const inner = document.createElement('div');
        inner.className = 'modal confirm-modal';
        const icon = document.createElement('div');
        icon.className = 'confirm-icon';
        icon.innerHTML = '<i data-lucide="alert-triangle"></i>';
        const msg = document.createElement('p');
        msg.className = 'confirm-msg';
        const actions = document.createElement('div');
        actions.className = 'modal-actions';
        actions.style.justifyContent = 'center';
        const ok = document.createElement('button');
        ok.type = 'button';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-outline confirm-cancel';
        actions.appendChild(ok);
        actions.appendChild(cancel);
        inner.appendChild(icon);
        inner.appendChild(msg);
        inner.appendChild(actions);
        modal.appendChild(inner);
        document.body.appendChild(modal);
        // Store refs
        modal._ok = ok;
        modal._cancel = cancel;
        modal._msg = msg;
        /* Listeners are bound ONCE but resolve through modal._resolve, which is
           re-assigned on every uiConfirm() call — otherwise only the FIRST
           confirmation in a session would ever resolve (stale closure bug). */
        ok.addEventListener('click', () => { modal.classList.remove('open'); modal._resolve?.(true); modal._resolve = null; });
        cancel.addEventListener('click', () => { modal.classList.remove('open'); modal._resolve?.(false); modal._resolve = null; });
        modal.addEventListener('click', e => {
          if (e.target === modal) { modal.classList.remove('open'); modal._resolve?.(false); modal._resolve = null; }
        });
        document.addEventListener('keydown', e => {
          if (e.key === 'Escape' && modal.classList.contains('open')) {
            modal.classList.remove('open');
            modal._resolve?.(false);
            modal._resolve = null;
          }
        });
      }

      modal._msg.textContent = message;
      modal._ok.textContent = okLabel;
      modal._ok.className = 'btn ' + (danger ? 'confirm-ok-danger' : 'btn-primary');
      modal._cancel.textContent = cancelLabel;
      modal._resolve = resolve; // ← rebind to THIS call's promise

      modal.classList.add('open');
      refreshIcons();
    });
  }

  /* Single-input prompt modal (same lifecycle as uiConfirm). Resolves
     with the string value, or null when cancelled. */
  function uiPrompt(message, { value = '', placeholder = '', okLabel = 'تأیید', cancelLabel = 'انصراف' } = {}) {
    return new Promise(resolve => {
      let modal = document.getElementById('uiPromptModal');
      if (!modal) {
        modal = document.createElement('div');
        modal.className = 'modal-bg';
        modal.id = 'uiPromptModal';
        const inner = document.createElement('div');
        inner.className = 'modal confirm-modal';
        const msg = document.createElement('p');
        msg.className = 'confirm-msg';
        const input = document.createElement('input');
        input.type = 'text';
        input.style.cssText = 'width:100%;background:var(--bg-3);border:1px solid var(--border);border-radius:10px;padding:10px 14px;color:var(--text);font-family:inherit';
        const actions = document.createElement('div');
        actions.className = 'modal-actions';
        actions.style.justifyContent = 'center';
        const ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'btn btn-primary';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-outline confirm-cancel';
        actions.appendChild(ok);
        actions.appendChild(cancel);
        inner.appendChild(msg);
        inner.appendChild(input);
        inner.appendChild(actions);
        modal.appendChild(inner);
        document.body.appendChild(modal);
        modal._msg = msg;
        modal._input = input;
        modal._ok = ok;
        modal._cancel = cancel;
        const done = val => { modal.classList.remove('open'); modal._resolve?.(val); modal._resolve = null; };
        ok.addEventListener('click', () => done(modal._input.value.trim()));
        input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); done(modal._input.value.trim()); } });
        cancel.addEventListener('click', () => done(null));
        modal.addEventListener('click', e => { if (e.target === modal) done(null); });
        document.addEventListener('keydown', e => {
          if (e.key === 'Escape' && modal.classList.contains('open')) done(null);
        });
      }

      modal._msg.textContent = message;
      modal._input.value = value;
      modal._input.placeholder = placeholder;
      modal._ok.textContent = okLabel;
      modal._cancel.textContent = cancelLabel;
      modal._resolve = resolve;

      modal.classList.add('open');
      setTimeout(() => modal._input.focus(), 50);
      refreshIcons();
    });
  }
  /* ---------------- theme ---------------- */

  function syncLogos() {
    const light = document.body.classList.contains('light');
    $$('img[data-logo-dark]').forEach(img => {
      img.src = light ? (img.dataset.logoLight || img.dataset.logoDark) : img.dataset.logoDark;
    });
  }

  function initTheme() {
    if (localStorage.getItem('ssaee_admin_theme') === 'light') {
      document.body.classList.add('light');
    }
    if (localStorage.getItem('ssaee_admin_side') === '1') {
      document.body.classList.add('side-collapsed');
    }
    syncThemeIcon();
    syncLogos();
    $('#themeBtn')?.addEventListener('click', toggleTheme);
    $('#loginThemeBtn')?.addEventListener('click', toggleTheme);
    $('#sideCollapse')?.addEventListener('click', () => {
      const on = document.body.classList.toggle('side-collapsed');
      localStorage.setItem('ssaee_admin_side', on ? '1' : '0');
      syncCollapseIcon();
    });
    syncCollapseIcon();
  }

  function syncCollapseIcon() {
    const btn = $('#sideCollapse');
    if (!btn) return;
    const collapsed = document.body.classList.contains('side-collapsed');
    btn.innerHTML = `<i data-lucide="${collapsed ? 'panel-right-open' : 'panel-right-close'}"></i>`;
    btn.title = collapsed ? 'باز کردن منو' : 'جمع کردن منو';
    refreshIcons();
  }

  function toggleTheme() {
    document.body.classList.toggle('light');
    localStorage.setItem('ssaee_admin_theme', document.body.classList.contains('light') ? 'light' : 'dark');
    syncThemeIcon();
    syncLogos();
    if (state.section === 'dashboard') renderDashboard();
  }

  function syncThemeIcon() {
    const light = document.body.classList.contains('light');
    const icon = light ? 'moon' : 'sun';
    ['#themeBtn', '#loginThemeBtn'].forEach(sel => {
      const btn = $(sel);
      if (btn) btn.innerHTML = `<i data-lucide="${icon}"></i>`;
    });
    refreshIcons();
  }

  /* ---------------- boot ---------------- */

  async function boot() {
    initTheme();
    /* Chart.js is vendored locally; CDN fallback if missing */
    if (!window.Chart) {
      const s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
      document.head.appendChild(s);
    }
    try {
      const res = await api('me');
      state.admin = res.admin ?? res.data?.admin ?? null;
      if (!state.admin) throw new Error('no admin');
      await loadMeta();
      renderPanel();
    } catch (err) {
      /* login view rendered on 401 */
    }
  }

  async function loadMeta() {
    const res = await api('meta');
    const d = res.data ?? res;
    if (!d.entities || !d.sections) throw new Error('meta ناقص است');
    state.meta = d;
  }

  /* ---------------- grouped sidebar (collapsible categories) ---------------- */

  const NAV_GROUPS = [
    { key: 'general',   title: 'عمومی',      icon: 'layout-dashboard', keys: ['dashboard'] },
    { key: 'members',   title: 'اعضا',       icon: 'id-card',          keys: ['members', 'cardtpl'] },
    { key: 'workshop',  title: 'کارگاه',     icon: 'rocket',           keys: ['registrations', 'workshops', 'banners'] },
    { key: 'academy',   title: 'آموزش',      icon: 'graduation-cap',   keys: ['certificates', 'certtpl', 'classes'] },
    { key: 'people',    title: 'روابط عمومی', icon: 'handshake',       keys: ['collab', 'board', 'activities'] },
    { key: 'content',   title: 'اطلاع‌رسانی', icon: 'megaphone',     keys: ['announce'] },
    { key: 'system',    title: 'سیستم',      icon: 'settings',         keys: ['bot', 'users', 'errcodes', 'settings', 'helpcontent', 'audit', 'trash'] },
  ];

  function navGroupOf(key) {
    const g = NAV_GROUPS.find(gr => gr.keys.includes(key));
    return g ? g.key : 'general';
  }

  function renderGroupedNav() {
    const openGroups = JSON.parse(localStorage.getItem('ssaee_admin_groups') || '["general"]');
    const visible = state.meta.sections;
    const pendingActs = state.alerts?.activities ?? 0;

    return NAV_GROUPS.map(group => {
      const items = visible.filter(s => group.keys.includes(s.key));
      if (!items.length) return '';
      const isOpen = openGroups.includes(group.key);
      const groupAlert = group.keys.includes('activities') && pendingActs > 0;
      return `
        <div class="nav-group" data-group="${esc(group.key)}">
          <button class="nav-group-head" type="button" data-gtoggle="${esc(group.key)}">
            <i data-lucide="${esc(group.icon)}"></i>
            <span class="ng-title">${esc(group.title)}${groupAlert ? '<span class="alert-dot" title="فعالیت‌های نیازمند تکمیل"></span>' : ''}</span>
            <i data-lucide="chevron-down" class="ng-chev ${isOpen ? 'open' : ''}"></i>
          </button>
          <div class="nav-group-body ${isOpen ? 'open' : ''}">
            ${items.map(s => `
              <button class="side-link" data-section="${esc(s.key)}" type="button" title="${esc(s.title)}">
                <i data-lucide="${esc(s.icon)}"></i> <span class="ng-lbl">${esc(s.title)}${(s.key === 'activities' && pendingActs > 0) ? '<span class="alert-dot" title="فعالیت‌های نیازمند تکمیل"></span>' : ''}</span>
              </button>`).join('')}
          </div>
        </div>`;
    }).join('');
  }

  function syncActivityAlerts(count) {
    state.alerts = state.alerts || {};
    state.alerts.activities = count;
    $$('#sideNav .alert-dot').forEach(d => d.remove());
    if (!(count > 0)) return;
    $$('#sideNav .side-link[data-section="activities"] .ng-lbl, #sideNav .nav-group[data-group="activities"] .ng-title').forEach(el => {
      const dot = document.createElement('span');
      dot.className = 'alert-dot';
      dot.title = 'فعالیت‌های نیازمند تکمیل اطلاعات';
      el.appendChild(dot);
    });
  }

  function wireGroupNav() {
    $$('#sideNav [data-gtoggle]').forEach(btn => {
      btn.addEventListener('click', () => {
        const key = btn.dataset.gtoggle;
        const body = $(`#sideNav .nav-group[data-group="${key}"] .nav-group-body`);
        const chev = btn.querySelector('.ng-chev');
        if (!body) return;
        const nowOpen = body.classList.toggle('open');
        chev?.classList.toggle('open', nowOpen);
        const stored = JSON.parse(localStorage.getItem('ssaee_admin_groups') || '[]');
        const idx = stored.indexOf(key);
        if (nowOpen && idx < 0) stored.push(key);
        if (!nowOpen && idx >= 0) stored.splice(idx, 1);
        localStorage.setItem('ssaee_admin_groups', JSON.stringify(stored));
      });
    });
  }

  function openSectionGroup(sectionKey) {
    const gkey = navGroupOf(sectionKey);
    const stored = JSON.parse(localStorage.getItem('ssaee_admin_groups') || '[]');
    if (!stored.includes(gkey)) {
      stored.push(gkey);
      localStorage.setItem('ssaee_admin_groups', JSON.stringify(stored));
    }
    $$('#sideNav .nav-group').forEach(g => {
      const is = g.dataset.group === gkey;
      g.querySelector('.nav-group-body')?.classList.toggle('open', is);
      g.querySelector('.ng-chev')?.classList.toggle('open', is);
    });
  }

  /* ---------------- login ---------------- */

  let loginWired = false;

  function wirePwToggle() {
    const btn = $('#pwToggle'), inp = $('#lgPass');
    if (!btn || !inp || btn.dataset.wired) return;
    btn.dataset.wired = '1';
    btn.addEventListener('click', () => {
      const show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', String(show));
      btn.innerHTML = `<i data-lucide="${show ? 'eye-off' : 'eye'}"></i>`;
      refreshIcons();
      inp.focus();
    });
  }

  function showLoginStep(step) {
    const s1 = $('#loginStep1'), s2 = $('#loginStep2');
    if (!s1 || !s2) return;
    s1.hidden = step !== 1;
    s2.hidden = step !== 2;
    (step === 2 ? $('#lgCode') : $('#lgUser'))?.focus();
  }

  function renderLogin() {
    state.admin = null;
    $('#loginShell').style.display = 'block';
    $('#appRoot').style.display = 'none';
    $('#pageTitle').textContent = 'ورود به پنل';
    syncThemeIcon();
    refreshIcons();

    if (loginWired) return;
    loginWired = true;

    wirePwToggle();
    showLoginStep(1);

    $('#loginStep1').addEventListener('submit', async e => {
      e.preventDefault();
      const username = $('#lgUser').value.trim();
      const password = $('#lgPass').value;
      if (!username || !password) { toast('نام کاربری و رمز عبور الزامی است', false); return; }
      busy($('#lgBtn'), true, 'در حال ورود…');
      try {
        const res = await api('login', { method: 'POST', body: { username, password } });
        if (res.step === '2fa') {
          busy($('#lgBtn'), false);
          showLoginStep(2);
        } else {
          busy($('#lgBtn'), true, 'خوش آمدید…');
          location.reload();
        }
      } catch (err) {
        busy($('#lgBtn'), false);
        toast(err.message || 'ورود ناموفق بود', false);
      }
    });

    $('#loginStep2').addEventListener('submit', async e => {
      e.preventDefault();
      const code = $('#lgCode').value.trim();
      if (!code) { toast('کد تأیید را وارد کنید', false); return; }
      busy($('#lg2faBtn'), true, 'در حال بررسی…');
      try {
        await api('2fa', { method: 'POST', body: { code } });
        location.reload();
      } catch (err) {
        busy($('#lg2faBtn'), false);
        toast(err.message || 'کد وارد شده صحیح نیست', false);
      }
    });

    document.querySelector('.tip')?.addEventListener('click', () =>
      toast('برای بازیابی رمز عبور به پشتیبانی پیام دهید: t.me/saee_admin'));
  }

  /* ---------------- panel shell ---------------- */

  function renderPanel() {
    $('#loginShell').style.display = 'none';
    $('#appRoot').style.display = 'flex';

    $('#pageTitle').textContent = 'داشبورد';
    $('#topbarActions').innerHTML = `
      <span class="muted" style="font-size:.8rem">${esc(state.admin.full_name)} — ${esc(state.admin.role_label)}</span>
      <button class="icon-btn" id="themeBtn" type="button" title="تغییر تم"></button>
      <button class="btn btn-outline btn-sm" id="logoutBtn" type="button"><i data-lucide="log-out"></i> خروج</button>`;

    $('#sideUser').innerHTML = `
      <div class="side-user-name">${esc(state.admin.full_name)}</div>
      <div class="side-user-role">${esc(state.admin.role_label)}</div>
      <button class="side-logout" id="sideLogout" type="button"><i data-lucide="log-out"></i> خروج</button>`;

    $('#sideNav').innerHTML = renderGroupedNav();

    $('#logoutBtn').addEventListener('click', doLogout);
    $('#sideLogout').addEventListener('click', doLogout);
    $('#themeBtn').addEventListener('click', toggleTheme);
    $('#burger').addEventListener('click', () => $('#sidebar').classList.toggle('open'));
    syncCollapseIcon();

    wireGroupNav();
    $$('#sideNav .side-link').forEach(btn => {
      btn.addEventListener('click', () => openSection(btn.dataset.section));
    });

    openSection('dashboard');
    syncThemeIcon();
  }

  async function doLogout() {
    await apiTry('logout', { method: 'POST', body: {} }, true);
    location.reload();
  }

  function openSection(key) {
    state.section = key;
    state.page = 1;
    state.search = '';

    $$('#sideNav .side-link').forEach(b => b.classList.toggle('active', b.dataset.section === key));
    openSectionGroup(key);
    $('#sidebar').classList.remove('open');

    const sec = state.meta.sections.find(s => s.key === key);
    if (!sec) return;

    if (sec.type === 'dashboard') { renderDashboard(); return; }
    if (sec.type === 'settings') { renderSettings(); return; }
    if (sec.type === 'errorcodes') { renderErrors(); return; }
    if (sec.type === 'certtpl') { renderCertTemplateEditor(); return; }
    if (sec.type === 'cardtpl') { renderCardTemplateEditor(); return; }
    if (sec.type === 'helpeditor') { renderHelpEditor(); return; }
    if (sec.type === 'trash') { renderTrashbin(); return; }
    if (sec.type === 'snapshots') { renderSnapshots(); return; }
    if (sec.broadcast) { renderBroadcastSection(sec); return; }

    if (sec.entities.length > 1) {
      renderTabbedEntities(sec);
    } else {
      renderEntityPage(sec.entities[0], sec.title);
    }
  }

  /* ---------------- dashboard (charts) ---------------- */

  function destroyCharts() {
    charts.forEach(c => { try { c.destroy(); } catch (err) { /* noop */ } });
    charts = [];
  }

  async function renderDashboard() {
    destroyCharts();
    $('#pageTitle').textContent = 'داشبورد';

    const res = await apiTry('stats');
    if (!res) return;

    const { cards, members_stats: ms, charts, sections, alerts } = res.data;
    const canCharts = sections.charts && window.Chart;
    const canMembers = sections.members_stats;

    if (alerts) syncActivityAlerts(alerts.activities ?? 0);
    document.querySelectorAll('#content .stat[data-goto-entity]').forEach(el => el.addEventListener('click', () => openSection(el.dataset.gotoEntity)));

    $('#content').innerHTML = `
      <div class="stats">${cards.map(c =>
        `<div class="stat${c.danger ? ' stat-danger' : ''}"${c.entity ? ` data-goto-entity="${esc(c.entity)}"` : ''}><div class="num"><i data-lucide="${esc(c.icon)}"></i> ${faNum(c.value)}</div><div class="lbl">${esc(c.label)}</div></div>`
      ).join('')}</div>

      ${canCharts ? `
      <div class="charts-grid">
        <div class="chart-panel chart-wide">
          <h3><i data-lucide="trending-up"></i> روند ثبت‌نام کارگاه (۱۴ روز اخیر)</h3>
          <div class="chart-wrap"><canvas id="chLine"></canvas></div>
        </div>
        <div class="chart-panel">
          <h3><i data-lucide="pie-chart"></i> وضعیت ثبت‌نامی‌ها</h3>
          <div class="chart-wrap"><canvas id="chStatus"></canvas></div>
        </div>
        <div class="chart-panel">
          <h3><i data-lucide="bar-chart-3"></i> آمار کلی بخش‌ها</h3>
          <div class="chart-wrap"><canvas id="chBars"></canvas></div>
        </div>
      </div>` : ''}

      ${canMembers ? `
      <div class="panel">
        <h2><i data-lucide="users"></i> آمار اعضا</h2>
        <div class="stats" style="margin-bottom:0">
          <div class="stat"><div class="num">${faNum(ms.count)}</div><div class="lbl">تعداد اعضا</div></div>
          <div class="stat"><div class="num">${faNum(ms.participation)}٪</div><div class="lbl">درصد مشارکت اعضا</div></div>
          <div class="stat"><div class="num">${faNum(ms.total_points)}</div><div class="lbl">جمع امتیازات صادر شده</div></div>
          <div class="stat"><div class="num">${faNum(ms.satisfaction)}٪</div><div class="lbl">رضایت از فعالیت‌ها</div></div>
          <div class="stat"><div class="num">${faNum(ms.total_registrations)}</div><div class="lbl">کل ثبت‌نامی‌های کارگاه</div></div>
          <div class="stat"><div class="num">${faNum(ms.club_score)}</div><div class="lbl">امتیاز باشگاه پژوهشگران</div></div>
        </div>
      </div>` : ''}`;

    refreshIcons();
    if (canCharts) drawCharts(charts);
    showDeviceHint();
  }

  function showDeviceHint() {
    if (window.innerWidth >= 901) return;
    if (sessionStorage.getItem('ssaee_admin_hint') === '1') return;
    sessionStorage.setItem('ssaee_admin_hint', '1');
    const el = document.createElement('div');
    el.className = 'device-toast';
    el.innerHTML = '<i data-lucide="monitor-smartphone"></i><span>برای تجربه بهتر، از لپ‌تاپ یا کامپیوتر استفاده کنید</span><button type="button">باشه</button>';
    document.body.appendChild(el);
    refreshIcons();
    el.querySelector('button').addEventListener('click', () => el.remove());
    setTimeout(() => el.remove(), 8000);
  }

  function drawCharts(data) {
    const accent = cssVar('--accent') || '#ffcc00';
    const muted = cssVar('--muted') || '#8b8b90';
    const grid = cssVar('--grid-line') || '#26262a';
    const palette = ['#f5b800', '#4caf50', '#2196f3', '#ff5252', '#9c27b0', '#ffa726', '#00bcd4'];

    Chart.defaults.font.family = 'Vazirmatn, Tahoma, sans-serif';
    Chart.defaults.color = muted;

    const line = new Chart($('#chLine'), {
      type: 'line',
      data: {
        labels: data.registrations_daily.labels,
        datasets: [{
          label: 'ثبت‌نام',
          data: data.registrations_daily.values,
          borderColor: accent,
          backgroundColor: 'rgba(245,184,0,.12)',
          fill: true,
          tension: .35,
          pointRadius: 3,
          pointBackgroundColor: accent,
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { color: grid } },
          y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: grid } },
        }
      }
    });

    const doughnut = new Chart($('#chStatus'), {
      type: 'doughnut',
      data: {
        labels: data.registration_status.labels,
        datasets: [{
          data: data.registration_status.values,
          backgroundColor: ['#ffa726', '#4caf50', '#ff5252', '#8b8b90'],
          borderWidth: 0,
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        cutout: '62%',
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14 } } }
      }
    });

    const bars = new Chart($('#chBars'), {
      type: 'bar',
      data: {
        labels: data.entity_counts.labels,
        datasets: [{
          label: 'تعداد',
          data: data.entity_counts.values,
          backgroundColor: palette,
          borderRadius: 8,
          maxBarThickness: 42,
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: grid } },
        }
      }
    });

    charts.push(line, doughnut, bars);
  }

  /* ---------------- error codes catalogue (tech_admin) ---------------- */

  /* ---------------- خطاها (codes + logs) — tech admin only ---------------- */

  async function renderErrors() {
    $('#pageTitle').textContent = 'خطاها';
    $('#content').innerHTML = `
      <div class="err-tabs">
        <button class="err-tab active" data-errtab="codes" type="button"><i data-lucide="tags"></i> هندلینگ خطاها (کدها)</button>
        <button class="err-tab" data-errtab="logs" type="button"><i data-lucide="file-warning"></i> لاگ خطاهای سایت</button>
      </div>
      <div id="errPane"></div>`;
    refreshIcons();
    const go = tab => {
      $$('.err-tab').forEach(b => b.classList.toggle('active', b.dataset.errtab === tab));
      if (tab === 'codes') renderErrorCodes($('#errPane'));
      else renderErrorLogs($('#errPane'));
    };
    $$('.err-tab').forEach(b => b.addEventListener('click', () => go(b.dataset.errtab)));
    go('codes');
  }

  async function renderErrorCodes(pane) {
    pane = pane || $('#content');
    const res = await apiTry('errorcodes');
    const codes = res?.data?.codes || {};

    const rows = Object.entries(codes).map(([code, info]) => `
      <div class="ecode-item" data-code="${esc(code)}">
        <div class="ecode-head">
          <button class="ip-btn ecode-icon" type="button" data-icon="${esc(info[3] || 'circle-help')}" title="انتخاب آیکن"><i data-lucide="${esc(info[3] || 'circle-help')}"></i></button>
          <span class="ecode-num">${faNum(code)}</span>
          <input class="ecode-in ecode-title" value="${esc(info[0])}" placeholder="عنوان">
        </div>
        <label style="font-size:.7rem;color:var(--muted)">چه چیزی به کاربر نمایش داده می‌شود</label>
        <textarea class="ecode-in ecode-desc" rows="2" placeholder="توضیح خطا">${esc(info[1])}</textarea>
        <label style="font-size:.7rem;color:var(--muted)">چه راهکاری به کاربر پیشنهاد شود</label>
        <div class="ecode-fixrow">
          <i data-lucide="wrench"></i>
          <input class="ecode-in ecode-fix" value="${esc(info[2])}" placeholder="راه‌حل پیشنهادی">
        </div>
        <div class="ecode-actions">
          <button class="btn btn-primary btn-sm ecode-save" type="button"><i data-lucide="save"></i> ذخیره</button>
        </div>
      </div>`).join('');

    pane.innerHTML = `
      <div class="panel" style="margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <p class="muted" style="font-size:.78rem;line-height:1.9;margin:0"><i data-lucide="info"></i>
        این کدها همان چیزی است که کاربر در خطاهای سایت/پنل می‌بیند. عنوان، توضیح، راهکار و آیکن هر کد قابل ویرایش است؛ فقط ادمین فنی دسترسی دارد.</p>
        <button class="btn btn-primary btn-sm" id="ecodeSaveAll" type="button" style="flex:none"><i data-lucide="save"></i> ذخیره همه</button>
      </div>
      <div class="ecode-grid">${rows}</div>`;
    refreshIcons();

    pane.querySelector('#ecodeSaveAll')?.addEventListener('click', async function() {
      const items = $$('#errPane .ecode-item, #content .ecode-item').map(item => ({
        code: item.dataset.code,
        title: item.querySelector('.ecode-title').value.trim(),
        desc: item.querySelector('.ecode-desc').value.trim(),
        fix: item.querySelector('.ecode-fix').value.trim(),
        icon: item.querySelector('.ecode-icon')?.dataset.icon || 'circle-help',
      }));
      if (items.some(i => !i.title)) { toast('عنوان همه کدها باید پر باشد', false); return; }
      busy(this, true, 'در حال ذخیره…');
      const r = await apiTry('errorcodes', { method: 'POST', body: { items } });
      busy(this, false);
      if (r) { toast(r.message || 'همه ذخیره شد'); renderErrorCodes(pane); }
    });


    $$('#errPane .ecode-item, #content .ecode-item').forEach(item => {
      const iconBtn = item.querySelector('.ecode-icon');
      iconBtn?.addEventListener('click', () => openIconPicker(iconBtn, ERR_ICONS));
      item.querySelector('.ecode-save').addEventListener('click', async () => {
        const body = {
          code: item.dataset.code,
          title: item.querySelector('.ecode-title').value.trim(),
          desc: item.querySelector('.ecode-desc').value.trim(),
          fix: item.querySelector('.ecode-fix').value.trim(),
          icon: iconBtn?.dataset.icon || 'circle-help',
        };
        if (!body.title) { toast('عنوان الزامی است', false); return; }
        const r = await apiTry('errorcodes', { method: 'POST', body });
        if (r) toast(r.message || 'ذخیره شد');
      });
    });
  }

  async function renderErrorLogs(pane) {
    pane.innerHTML = `
      <div class="panel">
        <div class="errlogs-bar">
          <input id="errlogQ" class="ecode-in" style="flex:1;min-width:160px" placeholder="جستجو در متن خطا… (مثلاً site:login یا Fatal)">
          <button class="btn btn-outline btn-sm" id="errlogGo" type="button"><i data-lucide="search"></i> اعمال</button>
          <button class="btn btn-outline btn-sm" id="errlogReload" type="button"><i data-lucide="refresh-rotate"></i> تازه‌سازی</button>
          <button class="btn btn-outline btn-sm" id="errlogCopy" type="button" title="کپی همه خطاهای نمایش‌داده‌شده برای ارسال به پشتیبانی"><i data-lucide="copy"></i> کپی خطاها</button>
        </div>
        <div id="errlogMeta" class="muted" style="font-size:.72rem;margin:8px 0;line-height:1.9"></div>
        <div class="errlog-list" id="errlogList"><div class="loading">در حال خواندن لاگ…</div></div>
      </div>`;
    refreshIcons();

    let lastItems = [], lastFiles = '', lastQ = '';
    const load = async q => {
      const box = pane.querySelector('#errlogList');
      const meta = pane.querySelector('#errlogMeta');
      box.innerHTML = '<div class="loading">در حال خواندن…</div>';
      const r = await apiTry('errorlogs&limit=150' + (q ? '&q=' + encodeURIComponent(q) : ''), {}, true);
      if (!r) { box.innerHTML = '<p class="muted">خواندن لاگ ناموفق بود.</p>'; return; }
      const items = r.data.items || [];
      lastItems = items; lastFiles = (r.data.files || []).map(f => `${f.name} (${(f.size / 1024).toFixed(0)} KB)`).join(' · '); lastQ = q || '';
      const files = lastFiles;
      meta.innerHTML = `<i data-lucide="file-text"></i> منابع: ${esc(files || '—')} — ${faNum(items.length)} مورد اخیر (قدیمی‌ترین در پایین). ${esc(r.data.hint || '')}`;
      if (!items.length) { box.innerHTML = '<p class="muted">موردی یافت نشد.</p>'; refreshIcons(); return; }
      box.innerHTML = items.map(it => {
        const cls = it.level === 'error' ? 'lv-error' : it.level === 'warn' ? 'lv-warn' : 'lv-info';
        return `<div class="errlog-row ${cls}">
          <div class="errlog-time"><b>${esc(it.jtime || it.time || '—')}</b><span>${esc(it.time || '')}</span><em>${esc(it.file || '')}</em></div>
          <div class="errlog-msg">${esc(it.msg)}</div>
        </div>`;
      }).join('');
      refreshIcons();
    };
    await load('');
    pane.querySelector('#errlogGo').addEventListener('click', () => load(pane.querySelector('#errlogQ').value.trim()));
    pane.querySelector('#errlogReload').addEventListener('click', () => load(pane.querySelector('#errlogQ').value.trim()));
    pane.querySelector('#errlogQ').addEventListener('keydown', e => { if (e.key === 'Enter') load(e.target.value.trim()); });
    pane.querySelector('#errlogCopy')?.addEventListener('click', async () => {
      if (!lastItems.length) { toast('موردی برای کپی نیست', false); return; }
      const head = `=== لاگ خطاهای سایت — ${lastItems.length} مورد — منابع: ${lastFiles || '—'}${lastQ ? ' — فیلتر: ' + lastQ : ''} ===`;
      const body = lastItems.map(it => `${it.jtime || it.time || ''} | ${it.level || ''} | ${it.file || ''} | ${it.msg || ''}`).join('\n');
      const ok = await copyToClipboard(head + '\n' + body);
      toast(ok ? `${faNum(lastItems.length)} خطا کپی شد — اکنون می‌توانید ارسال کنید` : 'کپی ناموفق بود', ok);
    });
  }

  async function renderSnapshots() {
    await renderTrashbin(true);
  }

  /* ---------------- trashbin (deleted records + files, tech_admin) ---------------- */

  const TRASH_ENTITY_TITLES = {
    banners: 'کارگاه‌ها (بنرها)', members: 'اعضا', membersreq: 'درخواست‌های عضویت',
    certificates: 'گواهینامه‌ها', activities: 'فعالیت‌ها', announcements: 'اطلاعیه‌ها',
    class_links: 'لینک کلاس‌ها', board_members: 'هیئت مدیره', associations: 'انجمن‌ها',
    teachers: 'اساتید', companies: 'شرکت‌ها', sponsor: 'اسپانسرها', suggestions: 'پیام‌ها',
    workshopreq: 'درخواست کارگاه', classreq: 'درخواست کلاس', workshop_registrations: 'ثبت‌نام کارگاه',
    bot_admins: 'ادمین‌های ربات', users: 'مدیران',
  };

  async function renderTrashbin(legacy = false) {
    $('#pageTitle').textContent = 'سبد بازیافت';
    const res = await apiTry(legacy ? 'snapshots&sub=list' : 'snapshots&sub=trash_list');
    const rows = legacy ? (res?.data?.snapshots || []) : (res?.data?.items || []);

    const tableRows = rows.map(r => {
      const isFile = legacy ? false : (r.is_file === true || !!r.file_path);
      const entity = legacy ? r.table_name : r.table_name;
      const recId = legacy ? r.record_id : r.record_id;
      const title = (!legacy && r.payload && (r.payload.FullName || r.payload.NameFa || r.payload.Name || r.payload.Title || r.payload.title)) || '';
      return `
      <tr>
        <td>${faNum(r.id)}</td>
        <td>${esc(TRASH_ENTITY_TITLES[entity] || entity)}</td>
        <td>${esc(String(recId))}${title ? `<div class="muted" style="font-size:.72rem">${esc(String(title))}</div>` : ''}</td>
        <td>${isFile ? '<span class="badge badge-yellow">شامل فایل</span>' : '<span class="badge badge-gray">رکورد</span>'}</td>
        <td>${esc(r.admin_username || '—')}</td>
        <td>${esc(r.deleted_at || r.created_at || '')}</td>
        <td style="white-space:nowrap">
          <button class="btn btn-outline btn-sm trash-restore" data-id="${esc(String(r.id))}" type="button" title="بازیابی"><i data-lucide="undo-2"></i> بازیابی</button>
          ${legacy ? '' : `<button class="btn btn-danger btn-sm trash-purge" data-id="${esc(String(r.id))}" type="button" title="حذف همیشگی"><i data-lucide="trash-2"></i></button>`}
        </td>
      </tr>`;
    }).join('');

    $('#content').innerHTML = `
      <div class="panel">
        <h2><i data-lucide="trash-2"></i> سبد بازیافت</h2>
        <p class="muted" style="margin-bottom:14px;font-size:.82rem">
          موارد حذف‌شده (رکوردها و فایل‌های مرتبط) ۳۰ روز در سبد بازیافت نگه داشته می‌شوند و سپس خودکار پاک می‌شوند.
          ${legacy ? '<br><span class="badge badge-yellow">نمایش اسنپ‌شات‌های قدیمی (قبل از سیستم سبد بازیافت)</span>' : ''}
        </p>
        ${rows.length ? `
          <div class="table-wrap"><table class="data-table">
            <thead><tr><th>#</th><th>بخش</th><th>شناسه</th><th>نوع</th><th>حذف توسط</th><th>تاریخ</th><th></th></tr></thead>
            <tbody>${tableRows}</tbody>
          </table></div>` : '<div class="loading">سبد بازیافت خالی است</div>'}
      </div>`;
    refreshIcons();

    $$('#content .trash-restore').forEach(btn => {
      btn.addEventListener('click', async () => {
        const ok = await uiConfirm('بازگردانی این مورد؟ رکورد و فایل مرتبط به‌صورت کامل برمی‌گردند.', { danger: false, okLabel: 'بازیابی' });
        if (!ok) return;
        const r = await apiTry('snapshots', { method: 'POST', body: { sub: legacy ? 'restore' : 'trash_restore', id: btn.dataset.id } });
        if (r) {
          toast(r.message || 'بازیابی شد');
          legacy ? renderSnapshots() : renderTrashbin();
        }
      });
    });

    $$('#content .trash-purge').forEach(btn => {
      btn.addEventListener('click', async () => {
        const ok = await uiConfirm('حذف همیشگی این مورد؟ این عمل قابل بازگشت نیست!', { danger: true, okLabel: 'حذف همیشگی' });
        if (!ok) return;
        const r = await apiTry('snapshots', { method: 'POST', body: { sub: 'trash_purge', id: btn.dataset.id } });
        if (r) {
          toast(r.message || 'برای همیشه حذف شد');
          renderTrashbin();
        }
      });
    });
  }
  /* ---------------- site help content editor (tech_admin, task 1) ---------------- */

  /* 36 hand-picked lucide icons (all verified present in the vendored pack) */
  const HELP_ICONS = [
    'id-card', 'users', 'user-plus', 'user-check', 'award', 'trophy',
    'graduation-cap', 'book-open', 'book-copy', 'bot', 'send', 'bell',
    'message-circle', 'message-square', 'phone', 'at-sign', 'calendar', 'calendar-clock',
    'clock', 'star', 'activity', 'rocket', 'target', 'zap',
    'shield-check', 'life-buoy', 'settings', 'lightbulb', 'sparkles', 'search',
    'clipboard-check', 'credit-card', 'school', 'globe', 'gem', 'crown',
  ];

  // 50 icons for error handling
  const ERR_ICONS = [
    'alert-triangle', 'alert-circle', 'alert-octagon', 'shield-alert', 'shield-off', 'shield',
    'bug', 'hammer', 'wrench', 'construction', 'traffic-cone', 'skull', 'flame', 'zap',
    'wifi-off', 'server-crash', 'database', 'database-backup', 'file-x', 'file-warning', 'file-question',
    'lock', 'key-round', 'key-square', 'log-in', 'log-out', 'monitor-off', 'user-x', 'user-check',
    'id-card', 'graduation-cap', 'users', 'clock', 'timer-off', 'timer', 'refresh-rotate', 'send',
    'mail-warning', 'mail-check', 'phone-call', 'message-circle', 'bell-off', 'search-x', 'eye-off',
    'receipt', 'coins', 'wallet', 'ban', 'circle-help', 'circle-slash', 'megaphone-off', 'bell-ring',
  ];

  /* shared graphical icon picker (fixed overlay) — pass a list to reuse for other editors */
  let iconPickerTarget = null;

  function openIconPicker(btn, list) {
    iconPickerTarget = btn;
    const icons = list || HELP_ICONS;
    let pop = document.getElementById('helpIconPicker');
    if (!pop) {
      pop = document.createElement('div');
      pop.className = 'modal-bg icon-picker-overlay';
      pop.id = 'helpIconPicker';
      pop.innerHTML = `
        <div class="modal icon-picker-modal">
          <div class="modal-head">
            <h3><i data-lucide="sparkles"></i> انتخاب آیکون</h3>
            <button class="icon-btn" type="button" data-ipclose><i data-lucide="x"></i></button>
          </div>
          <div class="ip-grid"></div>
        </div>`;
      document.body.appendChild(pop);

      const grid = pop.querySelector('.ip-grid');
      grid.innerHTML = icons.map(ic =>
        `<button type="button" class="ip-tile" data-icon="${ic}" title="${ic}"><i data-lucide="${ic}"></i></button>`
      ).join('');
      pop._iconList = icons.join(',');

      grid.addEventListener('click', e => {
        const tile = e.target.closest('.ip-tile');
        if (!tile || !iconPickerTarget) return;
        const icon = tile.dataset.icon;
        iconPickerTarget.dataset.icon = icon;
        iconPickerTarget.innerHTML = `<i data-lucide="${icon}"></i>`;
        $$('#helpItems .he-icon-btn, #helpItems .ip-tile, #errPane .ecode-icon').forEach(t => t.classList.remove('selected'));
        iconPickerTarget.classList.add('selected');
        pop.classList.remove('open');
        iconPickerTarget = null;
        refreshIcons();
      });

      pop.addEventListener('click', e => {
        if (e.target === pop || e.target.closest('[data-ipclose]')) {
          pop.classList.remove('open');
          iconPickerTarget = null;
        }
      });

      document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && pop.classList.contains('open')) {
          pop.classList.remove('open');
          iconPickerTarget = null;
        }
      });
    } else {
      // rebuild the tile set if a different icon list was requested
      if (pop._iconList !== icons.join(',')) {
        pop.querySelector('.ip-grid').innerHTML = icons.map(ic =>
          `<button type="button" class="ip-tile" data-icon="${ic}" title="${ic}"><i data-lucide="${ic}"></i></button>`
        ).join('');
        pop._iconList = icons.join(',');
      }
      // refresh selection highlight to current target's icon
      const cur = btn.dataset.icon;
      pop.querySelectorAll('.ip-tile').forEach(t => t.classList.toggle('selected', t.dataset.icon === cur));
    }
    pop.classList.add('open');
    refreshIcons();
  }

  async function renderHelpEditor() {
    $('#pageTitle').textContent = 'متن راهنمای سایت';
    const res = await apiTry('helpcontent');
    let items = res?.data?.items || [];
    if (!items.length) {
      items = [{ icon: 'id-card', title: 'عضویت در انجمن', body: '' }];
    }

    $('#content').innerHTML = `
      <div class="panel">
        <h2><i data-lucide="circle-help"></i> ویرایش راهنمای سایت اصلی</h2>
        <p class="muted" style="font-size:.78rem;margin-bottom:12px;line-height:1.9">
          بخش‌های راهنمای پنل کاربران را مدیریت کنید. هر بخش: یک آیکن، یک عنوان و متن توضیح.
          روی مربع آیکن کلیک کنید تا از میان ۳۶ آیکن، گرافیکی انتخاب کنید.
          تغییرات پس از ذخیره فوراً در سایت کاربران نمایش داده می‌شود.
        </p>
        <div id="helpItems"></div>
        <div class="modal-actions" style="margin-top:14px">
          <button class="btn btn-outline" id="helpAdd" type="button"><i data-lucide="plus"></i> افزودن بخش</button>
          <button class="btn btn-primary" id="helpSave" type="button"><i data-lucide="save"></i> ذخیره راهنما</button>
        </div>
      </div>`;

    const wrap = $('#helpItems');

    const rowHtml = (it) => `
      <div class="help-edit-row" style="border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:10px">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
          <button type="button" class="he-icon-btn" data-icon="${esc(it.icon || 'info')}" title="انتخاب آیکن">
            <i data-lucide="${esc(it.icon || 'info')}"></i>
          </button>
          <input class="he-title" value="${esc(it.title || '')}" placeholder="عنوان بخش" style="flex:1;min-width:160px;background:var(--bg-3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:8px 12px;font-family:inherit">
          <button class="btn btn-danger btn-sm he-del" type="button" title="حذف بخش"><i data-lucide="trash-2"></i></button>
        </div>
        <textarea class="he-body" rows="4" placeholder="متن توضیح این بخش…" style="width:100%;background:var(--bg-3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 12px;font-family:inherit;line-height:1.9;resize:vertical">${esc(it.body || '')}</textarea>
      </div>`;

    const bindRow = (row) => {
      row.querySelector('.he-del').addEventListener('click', () => row.remove());
      row.querySelector('.he-icon-btn').addEventListener('click', function() {
        openIconPicker(this);
      });
    };

    const renderRows = () => {
      wrap.innerHTML = items.map(rowHtml).join('');
      $$('#helpItems .help-edit-row').forEach(bindRow);
      refreshIcons();
    };
    renderRows();

    $('#helpAdd').addEventListener('click', () => {
      const div = document.createElement('div');
      div.innerHTML = rowHtml({ icon: 'info', title: '', body: '' });
      const row = div.firstElementChild;
      wrap.appendChild(row);
      bindRow(row);
      refreshIcons();
      row.querySelector('.he-title').focus();
    });

    $('#helpSave').addEventListener('click', async () => {
      const out = [];
      $$('#helpItems .help-edit-row').forEach(row => {
        out.push({
          icon: row.querySelector('.he-icon-btn').dataset.icon || 'info',
          title: row.querySelector('.he-title').value.trim(),
          body: row.querySelector('.he-body').value,
        });
      });
      const r = await apiTry('helpcontent', { method: 'POST', body: { items: out } });
      if (r) toast(r.message || 'ذخیره شد');
    });

    refreshIcons();
  }

  /* ---------------- cert template editor (upload + click-to-position) ---------------- */

  async function renderCertTemplateEditor() {
    $('#pageTitle').textContent = 'قالب گواهینامه';
    const res = await apiTry('certtpl&sub=get');
    const d = res?.data || {};

    const templateInfo = d.template || null;
    const sigInfo = d.signature || null;
    const fields = d.fields || [];
    const hidden = d.hidden || [];
    const renderInfo = d.render || {};
    let activeFieldKey = fields.length ? fields[0].field_key : null;

    const fieldLabels = {
      name_en: 'Name (EN)',
      name_fa: 'Name (FA)',
      national_id: 'National ID',
      cert_id: 'Certificate No.',
      course: 'Course',
      issue_date: 'Issue Date',
      print_date: 'Print Date',
      president: 'Secretary Name',
      custom_text: 'Description / Type',
      signature: 'Stamp / Signature',
    };

    const renderBadge = renderInfo.ok
      ? `<span class="badge badge-green">موتور تصویر: ${esc(renderInfo.renderer || '')} · فونت Bold: ${esc(renderInfo.font_bold || '—')} · Regular: ${esc(renderInfo.font_reg || '—')} ${renderInfo.shaping_ok ? '· اتصال حروف: کامل ✓' : '· اتصال حروف: ناقص!'}</span>`
      : '<span class="badge badge-red">موتور تصویر آماده نیست — ' + esc(renderInfo.note || '') + '</span>';

    let html = `
      <div class="panel">
        <h2><i data-lucide="image-up"></i> آپلود قالب گواهینامه</h2>
        <p class="muted" style="margin-bottom:10px;font-size:.8rem">قالب PNG گواهینامه (بدون متن) را آپلود کنید، سپس فیلدها را روی قالب جای‌گذاری کنید. نقطه‌ای که برای فیلدهای متنی انتخاب می‌کنید <b>سمت چپِ متن</b> است (متن‌های انگلیسی چپ‌چین درج می‌شوند) و برای «مهر / امضای دبیر» و «QR» نقطه = مرکز کادر مربع و عرض = ضلع مربع است.</p>

        <div style="margin-bottom:12px">${renderBadge}</div>
        ${d.secretary ? `<div class="badge badge-green" style="display:block;margin-bottom:10px;font-size:.72rem;line-height:1.9"><i data-lucide="pen-tool"></i> Secretary: <b>${esc(d.secretary.name_en || d.secretary.name)}</b> · Signature: ${d.secretary.has_signature ? 'auto-loaded from board' : 'NOT uploaded — add it in هیئت مدیره'}</div>` : `<div class="badge badge-red" style="display:block;margin-bottom:10px;font-size:.72rem;line-height:1.9"><i data-lucide="alert-triangle"></i> عضو «دبیر» در دورهٔ فعال تعریف نشده — نام و امضای دبیر خودکار برداشت نمی‌شود</div>`}
        ${renderInfo.missing && renderInfo.missing.length ? `<p class="badge badge-red" style="margin-bottom:10px;font-size:.72rem">گلیف‌های اتصال کم است در فونت انتخابی: ${esc(renderInfo.missing.join(' '))} — فونت‌ها فقط به‌صورت لوکال از assets/fonts بارگذاری می‌شوند؛ فایل‌های YekanBakh-Bold/Regular.ttf را همان‌جا قرار دهید.</p>` : ''}

        <div class="upl-widget">
          <div class="upl-drop cert-drop" tabindex="0">
            <i data-lucide="image-up"></i>
            <b>قالب گواهینامه را اینجا رها کنید</b>
            <span>یا کلیک کنید — PNG / JPG (حداکثر ۱۰MB)</span>
          </div>
          <input type="file" class="cert-file" accept="image/png,image/jpeg" style="display:none">
        </div>
        <div style="margin-top:14px">
          <h3 style="font-size:.85rem;margin-bottom:8px"><i data-lucide="stamp"></i> مهر / امضای دبیر</h3>
          <p class="muted" style="font-size:.76rem;margin-bottom:8px">تصویر مهر یا امضای دبیر را آپلود کنید (ترجیحاً PNG با پس‌زمینه شفاف). سپس در بخش موقعیت فیلدها، «مهر / امضای دبیر» را روی قالب جای‌گذاری و عرض آن را تنظیم کنید.</p>
          <div class="upl-widget">
            <div class="upl-drop cert-sig-drop" tabindex="0">
              <i data-lucide="stamp"></i>
              <b>تصویر مهر / امضای دبیر را اینجا رها کنید</b>
              <span>یا کلیک کنید — PNG / JPG (حداکثر ۵MB)</span>
            </div>
            <input type="file" class="cert-sig-file" accept="image/png,image/jpeg" style="display:none">
          </div>
          ${sigInfo ? `<div class="upl-preview active" style="display:flex;margin-top:10px"><img src="../${esc(sigInfo.path)}?t=${Date.now()}" alt="مهر / امضای دبیر"></div>` : ''}
        </div>
      </div>`;

    if (templateInfo) {
      const chipBtn = (f) => `<button type="button" class="btn btn-sm cert-chip ${f.field_key === activeFieldKey ? 'btn-primary' : 'btn-outline'}" data-key="${esc(f.field_key)}" style="border-radius:999px;padding:7px 16px">${esc(fieldLabels[f.field_key] || f.label)}</button>`;
      const hiddenChipBtn = (h) => `<button type="button" class="btn btn-sm cert-chip cert-chip-hidden" data-hkey="${esc(h.field_key)}" title="از خروجی گواهی حذف شده — برای بازیابی کلیک کنید" style="border-radius:999px;padding:7px 14px"><i data-lucide="undo-2"></i> ${esc(fieldLabels[h.field_key] || h.label || h.field_key)}</button>`;

      html += `
      <div class="panel">
        <h2><i data-lucide="mouse-pointer-click"></i> تعیین موقعیت فیلدها روی قالب</h2>
        <p class="muted" style="margin-bottom:10px;font-size:.78rem">فیلد را از دکمه‌های زیر انتخاب کنید (یا روی ردیف آن بزنید)، سپس روی تصویر کلیک کنید. نقطه کلیک برای فیلدهای متنی «سمت چپِ متن» است (متن‌های انگلیسی چپ‌چین درج می‌شوند)؛ برای «مهر / امضای دبیر» نقطه مرکز کادرِ مربع است. آیکن کمرنگ = فیلد حذف‌شده از خروجی (با کلیک، بازیابی می‌شود). اندازه فونت متن‌ها و ضلع کادر مهر/QR (پیکسل) قابل تنظیم است.</p>

        <div id="certFieldChips" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px">
          ${fields.map(chipBtn).join('')}
          ${hidden.map(hiddenChipBtn).join('')}
          ${hidden.length ? `<button class="btn btn-outline btn-sm" id="certRestoreBtn" type="button" style="border-radius:999px;padding:7px 14px"><i data-lucide="undo-2"></i> بازیابی همه</button>` : ''}
        </div>

        <div style="position:relative;display:inline-block;max-width:100%;overflow:auto;border:1px solid var(--border);border-radius:8px">
          <img id="certTplImg" src="../${esc(templateInfo.path)}" style="max-width:100%;cursor:crosshair;display:block" data-w="${templateInfo.width}" data-h="${templateInfo.height}">
        </div>
        <div class="skel-rows" style="margin-top:12px">
          ${fields.map(f => {
            const isSig = f.field_key === 'signature';
            return `
            <div class="skel-row cert-frow" data-key="${esc(f.field_key)}" style="gap:8px;cursor:pointer">
              <span style="font-size:.78rem;min-width:120px;color:var(--muted)">${esc(fieldLabels[f.field_key] || f.label)}</span>
              <span class="badge badge-yellow" style="min-width:80px;text-align:center">X: ${faNum(f.x_pos)} Y: ${faNum(f.y_pos)}</span>
              ${isSig
                ? `<label style="font-size:.72rem;color:var(--muted);display:flex;align-items:center;gap:6px">عرض (px): <input class="cert-w-input" data-key="${esc(f.field_key)}" type="number" min="0" max="4000" step="10" value="${parseInt(f.width ?? 0, 10) || 0}" style="width:76px;background:var(--bg-3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:4px 8px;font-family:inherit"></label>`
                : `<label style="font-size:.72rem;color:var(--muted);display:flex;align-items:center;gap:6px">اندازه (px): <input class="cert-s-input" data-key="${esc(f.field_key)}" type="number" min="16" max="500" value="${parseInt(f.font_size, 10) || 32}" style="width:64px;background:var(--bg-3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:4px 8px;font-family:inherit"></label>`}
            </div>`;
          }).join('')}
        </div>
        <div class="modal-actions" style="margin-top:16px">
          <button class="btn btn-primary" id="certSaveFields" type="button"><i data-lucide="save"></i> ذخیره موقعیت‌ها</button>
          <button class="btn btn-outline" id="certSampleGen" type="button"><i data-lucide="eye"></i> تولید نمونه</button>
          <button class="btn btn-outline" id="certTextSelftest" type="button"><i data-lucide="type"></i> تست متن فارسی</button>
          <button class="btn btn-outline" id="certGlyphSheet" type="button"><i data-lucide="grid-3x3"></i> برگه گلیف فونت</button>
        </div>
        <div id="certSampleBox" class="hidden" style="margin-top:16px"></div>

      </div>`;
    }

    $('#content').innerHTML = html;
    refreshIcons();

    // Upload handlers
    const drop = $('.cert-drop');
    const fileInput = $('.cert-file');
    if (drop && fileInput) {
      drop.addEventListener('click', () => fileInput.click());
      drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('drag'); });
      drop.addEventListener('dragleave', () => drop.classList.remove('drag'));
      drop.addEventListener('drop', e => {
        e.preventDefault();
        drop.classList.remove('drag');
        if (e.dataTransfer.files.length) uploadCertTemplate(e.dataTransfer.files[0]);
      });
      fileInput.addEventListener('change', () => {
        if (fileInput.files.length) uploadCertTemplate(fileInput.files[0]);
      });
    }
    const sigDrop = $('.cert-sig-drop');
    const sigFile = $('.cert-sig-file');
    if (sigDrop && sigFile) {
      sigDrop.addEventListener('click', () => sigFile.click());
      sigDrop.addEventListener('dragover', e => { e.preventDefault(); sigDrop.classList.add('drag'); });
      sigDrop.addEventListener('dragleave', () => sigDrop.classList.remove('drag'));
      sigDrop.addEventListener('drop', e => {
        e.preventDefault();
        sigDrop.classList.remove('drag');
        if (e.dataTransfer.files.length) uploadCertSignature(e.dataTransfer.files[0]);
      });
      sigFile.addEventListener('change', () => {
        if (sigFile.files.length) uploadCertSignature(sigFile.files[0]);
      });
    }

    // Inline size / width editors (applied on save)
    $$('#content .cert-s-input').forEach(inp => {
      inp.addEventListener('change', () => {
        const f = fields.find(x => x.field_key === inp.dataset.key);
        if (f) f.font_size = Math.max(16, parseInt(inp.value, 10) || 32);
      });
    });
    $$('#content .cert-w-input').forEach(inp => {
      inp.addEventListener('change', () => {
        const f = fields.find(x => x.field_key === inp.dataset.key);
        if (f) {
          f.width = Math.max(0, parseInt(inp.value, 10) || 0);
          // live-resize the stamp preview box on the template
          const tpl = $('#certTplImg');
          if (tpl && templateInfo) {
            showCertMarker(tpl, f.x_pos, f.y_pos, 'signature', fieldLabels.signature || 'مهر', {
              width: templateInfo.width, height: templateInfo.height,
              sigW: f.width || 260, sigImgW: sigInfo ? sigInfo.width : 200,
            });
          }
        }
      });
    });

    // Field selection via chips + rows
    const selectCertField = (key) => {
      if (!key) return;
      activeFieldKey = key;
      $$('#content .cert-chip').forEach(c => {
        c.classList.toggle('btn-primary', c.dataset.key === key);
        c.classList.toggle('btn-outline', c.dataset.key !== key);
      });
    };
    $$('#content .cert-chip').forEach(c => c.addEventListener('click', () => selectCertField(c.dataset.key)));
    $$('#content .cert-frow').forEach(row => row.addEventListener('click', (e) => {
      if (e.target.closest('input')) return;
      selectCertField(row.dataset.key);
    }));

    // ✕ on a marker (or a field row) hides that field from the certificate
    $('#content').addEventListener('click', async (e) => {
      const x = e.target.closest('.cm-x');
      if (!x) return;
      e.stopPropagation();
      const key = x.dataset.hkey;
      const ok = await uiConfirm('این فیلد از تصویر گواهی حذف شود؟ آیکن آن به‌صورت کمرنگ در کادر بالای قالب می‌ماند و با کلیک روی آن قابل بازیابی است.', { okLabel: 'حذف' });
      if (!ok) return;
      const r = await apiTry('certtpl&sub=hide_field', { method: 'POST', body: { key } });
      if (r) { toast(r.message || 'حذف شد'); renderCertTemplateEditor(); }
    });

    // faded chips above the template → restore that single field
    $$('#content .cert-chip-hidden').forEach(c => c.addEventListener('click', async () => {
      const r = await apiTry('certtpl&sub=restore_field', { method: 'POST', body: { key: c.dataset.hkey } });
      if (r) { toast(r.message || 'بازیابی شد'); renderCertTemplateEditor(); }
    }));

    $('#certRestoreBtn')?.addEventListener('click', async function() {
      busy(this, true, '…');
      const r = await apiTry('certtpl&sub=restore_fields', { method: 'POST', body: {} });
      busy(this, false);
      if (r) { toast(r.message || 'بازیابی شد'); renderCertTemplateEditor(); }
    });

    // Click on template image to set position
    const tplImg = $('#certTplImg');
    if (tplImg) {
      tplImg.addEventListener('click', e => {
        const rect = tplImg.getBoundingClientRect();
        const scaleX = templateInfo ? templateInfo.width / rect.width : 1;
        const scaleY = templateInfo ? templateInfo.height / rect.height : 1;
        const x = Math.round((e.clientX - rect.left) * scaleX);
        const y = Math.round((e.clientY - rect.top) * scaleY);

        if (!activeFieldKey) return;
        const field = fields.find(f => f.field_key === activeFieldKey);
        if (field) {
          field.x_pos = x;
          field.y_pos = y;
          const sigF = fields.find(q => q.field_key === 'signature');
          showCertMarker(tplImg, x, y, activeFieldKey, fieldLabels[activeFieldKey] || field.label, {
            width: templateInfo.width, height: templateInfo.height,
            sigW: (activeFieldKey === 'signature' || activeFieldKey === 'qr') ? (parseInt(field.width, 10) || 260) : (sigF ? (parseInt(sigF.width, 10) || 260) : 260),
            sigImgW: sigInfo ? sigInfo.width : 200,
          });
          toast(`موقعیت «${fieldLabels[activeFieldKey]}» ثبت شد (X: ${faNum(x)}, Y: ${faNum(y)}) — دوباره کلیک کنید یا ذخیره کنید`);
        }
      });
      // paint existing field positions so each marker is labeled / sized
      const sigF0 = fields.find(q => q.field_key === 'signature');
      fields.forEach(fd => {
        if (fd.x_pos || fd.y_pos) {
          showCertMarker(tplImg, fd.x_pos, fd.y_pos, fd.field_key, fieldLabels[fd.field_key] || fd.label, {
            width: templateInfo.width, height: templateInfo.height,
            sigW: (fd.field_key === 'signature' || fd.field_key === 'qr') ? (parseInt(fd.width, 10) || 260) : (sigF0 ? (parseInt(sigF0.width, 10) || 260) : 260),
            sigImgW: sigInfo ? sigInfo.width : 200,
          });
        }
      });
    }

    // Save positions button
    $('#certSaveFields')?.addEventListener('click', async () => {
      const r = await apiTry('certtpl&sub=save_fields', {
        method: 'POST',
        body: { fields }
      });
      if (r) toast(r.message || 'ذخیره شد');
    });

    // Image previews: sample / self-test corpus / glyph sheet
    const showPng = (title, b64, hint) => {
      const box = $('#certSampleBox');
      if (!box) return;
      box.classList.remove('hidden');
      box.innerHTML = `
        <h3 style="font-size:.85rem;margin-bottom:8px"><i data-lucide="image"></i> ${title}</h3>
        <div style="max-height:60vh;overflow:auto;border:1px solid var(--border);border-radius:10px"><img src="data:image/png;base64,${b64}" alt="${title}" style="max-width:100%"></div>
        ${hint ? `<p class="muted" style="font-size:.72rem;margin-top:6px">${hint}</p>` : ''}`;
      refreshIcons();
      box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };
    $('#certSampleGen')?.addEventListener('click', async function() {
      busy(this, true, 'در حال تولید…');
      try {
        const r = await api('certtpl&sub=sample', { method: 'POST', body: {} });
        busy(this, false);
        if (r?.data?.png_base64) showPng('نمونه گواهی (داده تست)', r.data.png_base64, 'اگر حروف جدا یا بی‌شکل است، نشان فونت بالا (فونت لوکال YekanBakh و «اتصال حروف: کامل») را بررسی کنید.');
        else toast(r?.message || 'تولید نمونه ناموفق بود', false);
      } catch (err) {
        busy(this, false);
        toast(err.message || 'تولید نمونه ناموفق بود', false);
      }
    });
    $('#certTextSelftest')?.addEventListener('click', async function() {
      busy(this, true, 'در حال تست…');
      try {
        const r = await api('certtpl&sub=selftest', { method: 'POST', body: {} });
        busy(this, false);
        if (r?.data?.png_base64) {
          const st = r.data.render || {};
          showPng('تست رندر متن فارسی', r.data.png_base64, `فونت: ${esc(st.font_bold || '—')} · وضعیت اتصال: ${st.shaping_ok ? 'کامل ✓' : 'ناقص ✗'} ${(st.missing || []).length ? '· کمبود: ' + esc(st.missing.join(' ')) : ''}`);
        } else toast(r?.message || 'تست ناموفق بود', false);
      } catch (err) {
        busy(this, false);
        toast(err.message || 'تست ناموفق بود', false);
      }
    });
    $('#certGlyphSheet')?.addEventListener('click', async function() {
      busy(this, true, 'در حال ساخت…');
      try {
        const r = await api('certtpl&sub=glyphsheet', { method: 'POST', body: {} });
        busy(this, false);
        if (r?.data?.png_base64) showPng('برگه گلیف — base/iso/init/med/fina با کدپوینت', r.data.png_base64, 'هر گلیف باید زیر کد U+ خودش دیده شود؛ اختلاف یعنی فونت نامناسب.');
        else toast(r?.message || 'برگه گلیف ناموفق بود', false);
      } catch (err) {
        busy(this, false);
        toast(err.message || 'برگه گلیف ناموفق بود', false);
      }
    });

  }

  async function uploadCertSignature(file) {
    if (!['image/png', 'image/jpeg'].includes(file.type)) { toast('فقط PNG یا JPG', false); return; }
    if (file.size > 5 * 1024 * 1024) { toast('حجم بیش از ۵MB', false); return; }

    const fd = new FormData();
    fd.append('signature', file);
    const headers = { 'X-CSRF-Token': window.ADMIN.csrf };
    try {
      const r = await fetch(endpoint('certtpl&sub=upload_signature'), { method: 'POST', headers, body: fd });
      const j = await r.json();
      if (j.status === 'success') {
        toast('امضای دبیر آپلود شد');
        renderCertTemplateEditor();
      } else {
        toast(j.message || 'آپلود ناموفق', false);
      }
    } catch (err) { toast('خطا در آپلود', false); }
  }

  function showCertMarker(imgEl, x, y, fieldKey, caption, opts) {
    opts = opts || {};
    const container = imgEl.parentElement;
    container.querySelectorAll('.cert-marker[data-field]').forEach(m => {
      if (m.dataset.field === fieldKey) m.remove();
    });
    const marker = document.createElement('div');
    marker.className = 'cert-marker';
    marker.dataset.field = fieldKey;

    if ((fieldKey === 'signature' || fieldKey === 'qr') && opts.sigW) {
      // square box: the point (x,y) is the CENTER, side = configured width px
      const side = Math.max(24, opts.sigW);
      marker.style.cssText = `position:absolute;left:${((x - side / 2) / opts.width * 100)}%;top:${((y - side / 2) / opts.height * 100)}%;` +
        `width:${(side / opts.width * 100)}%;height:${(side / opts.height * 100)}%;` +
        `pointer-events:none;z-index:10;display:block`;
      marker.innerHTML = `<span class="cm-box"></span>` +
        (caption ? `<span class="cm-label cm-label-box">${esc(caption)}</span>` : '') +
        `<button type="button" class="cm-x" data-hkey="${esc(fieldKey)}" title="حذف این فیلد از خروجی">✕</button>`;
    } else {
      // ▶ marks the LEFT edge of the (English, left-aligned) text; caption shows which field it is
      marker.style.cssText = `position:absolute;left:${(x / opts.width * 100)}%;top:${(y / opts.height * 100)}%;transform:translate(0,-50%);display:flex;flex-direction:column;align-items:flex-start;gap:3px;pointer-events:none;z-index:10`;
      marker.innerHTML = `<span class="cm-arrow">▶</span>` +
        (caption ? `<span class="cm-label">${esc(caption)}</span>` : '') +
        `<button type="button" class="cm-x" data-hkey="${esc(fieldKey)}" title="حذف این فیلد از خروجی">✕</button>`;
    }

    container.appendChild(marker);
  }

  async function uploadCertTemplate(file) {
    if (!['image/png', 'image/jpeg'].includes(file.type)) { toast('فقط PNG یا JPG', false); return; }
    if (file.size > 10 * 1024 * 1024) { toast('حجم بیش از ۱۰MB', false); return; }

    const fd = new FormData();
    fd.append('template', file);
    const headers = { 'X-CSRF-Token': window.ADMIN.csrf };
    try {
      const r = await fetch(endpoint('certtpl&sub=upload'), { method: 'POST', headers, body: fd });
      const j = await r.json();
      if (j.status === 'success') {
        toast('قالب آپلود شد');
        renderCertTemplateEditor();
      } else {
        toast(j.message || 'آپلود ناموفق', false);
      }
    } catch (err) { toast('خطا در آپلود', false); }
  }

  /* ---------------- membership card template editor (upload + click-to-position) ---------------- */

  const CARD_FIELD_LABELS = {
    name_en:     'Member Name (EN)',
    name_fa:     'Member Name (FA)',
    member_id:   'Member No.',
    student_id:  'Student No.',
    national_id: 'National ID',
    issue_date:  'Issue Date',
    exp_date:    'Valid Until',
    points:      'Points',
    qr:          'QR Code (member no.)',
    president:   'Secretary Name',
    signature:   'Secretary Stamp',
  };
  const CARD_BOX_KEYS = ['qr', 'signature'];

  async function renderCardTemplateEditor() {
    $('#pageTitle').textContent = 'قالب کارت عضویت';
    const res = await apiTry('cardtpl&sub=get');
    const d = res?.data || {};
    const templateInfo = d.template || null;
    let fields = Array.isArray(d.fields) ? d.fields : [];

    // default field list (persisted on first save)
    if (!fields.length) {
      fields = Object.entries(CARD_FIELD_LABELS).map(([k, label]) => ({
        field_key: k, label, x_pos: 0, y_pos: 0, font_size: 22, width: 0, font_color: '#1a1a1a',
      }));
    }

    let html = `
      <div class="panel">
        <h2><i data-lucide="id-card"></i> آپلود قالب کارت عضویت</h2>
        <p class="muted" style="margin-bottom:12px;font-size:.8rem">قالب کارت (PNG یا JPG، ترجیحاً ۱۰۱۲×۶۳۸ پیکسل) را آپلود کنید. سپس روی تصویر کلیک کنید تا موقعیت هر فیلد را تعیین کنید.</p>
        <div class="upl-widget">
          <div class="upl-drop card-drop" tabindex="0">
            <i data-lucide="image-up"></i>
            <b>قالب کارت عضویت را اینجا رها کنید</b>
            <span>یا کلیک کنید — PNG / JPG (حداکثر ۱۰MB)</span>
          </div>
          <input type="file" class="card-file" accept="image/png,image/jpeg" style="display:none">
        </div>
      </div>`;

    if (templateInfo) {
      html += `
      <div class="panel">
        <h2><i data-lucide="mouse-pointer-click"></i> تعیین موقعیت فیلدها روی کارت</h2>
        <p class="muted" style="margin-bottom:10px;font-size:.78rem">فیلد موردنظر را از لیست انتخاب کنید، سپس روی تصویر کلیک کنید. موقعیت و اندازه فونت هر فیلد قابل تنظیم است.</p>
        <div style="margin-bottom:12px">
          <label style="font-size:.78rem;color:var(--muted)">فیلد فعال</label>
          <select id="cardFieldSel" style="background:var(--bg-3);border:1px solid var(--border);border-radius:10px;padding:9px 14px;color:var(--text);font-family:inherit;width:100%">
            ${fields.map(f => `<option value="${esc(f.field_key)}">${esc(f.label || CARD_FIELD_LABELS[f.field_key] || f.field_key)}</option>`).join('')}
          </select>
        </div>
        <div style="position:relative;display:inline-block;max-width:100%;overflow:auto;border:1px solid var(--border);border-radius:8px">
          <img id="cardTplImg" src="../${esc(templateInfo.path)}" style="max-width:100%;cursor:crosshair;display:block" data-w="${templateInfo.width}" data-h="${templateInfo.height}">
        </div>
        <div class="skel-rows" style="margin-top:12px">
          ${fields.map(f => {
            const isBox = CARD_BOX_KEYS.includes(f.field_key);
            return `
            <div class="skel-row card-frow" data-key="${esc(f.field_key)}" style="gap:8px;cursor:pointer">
              <span style="font-size:.78rem;min-width:130px;color:var(--muted)">${esc(f.label || CARD_FIELD_LABELS[f.field_key] || f.field_key)}</span>
              <span class="badge badge-yellow" style="min-width:96px;text-align:center">X: ${faNum(f.x_pos ?? 0)} Y: ${faNum(f.y_pos ?? 0)}</span>
              ${isBox
                ? `<label style="font-size:.72rem;color:var(--muted);display:flex;align-items:center;gap:6px">Side (px): <input class="card-w-input" data-key="${esc(f.field_key)}" type="number" min="24" max="2000" step="10" value="${parseInt(f.width, 10) || 140}" style="width:66px;background:var(--bg-3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:4px 8px;font-family:inherit"></label>`
                : `<label style="font-size:.72rem;color:var(--muted);display:flex;align-items:center;gap:6px">Size (px): <input class="card-s-input" data-key="${esc(f.field_key)}" type="number" min="10" max="400" value="${parseInt(f.font_size, 10) || 22}" style="width:60px;background:var(--bg-3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:4px 8px;font-family:inherit"></label>`}
              <button type="button" class="card-x" data-key="${esc(f.field_key)}" title="حذف این فیلد از کارت (با ذخیره موقعیت‌ها قطعی می‌شود)">✕</button>
            </div>`;
          }).join('')}
        </div>
        <div class="modal-actions" style="margin-top:16px;align-items:center">
          <button class="btn btn-primary" id="cardSaveFields" type="button"><i data-lucide="save"></i> ذخیره موقعیت‌ها</button>
          <button class="btn btn-outline" id="cardRestoreBtn" type="button"><i data-lucide="undo-2"></i> بازگردانی فیلدهای پیش‌فرض</button>
          <button class="btn btn-outline" id="cardTestGen" type="button"><i data-lucide="eye"></i> تولید کارت نمونه (عضو اول)</button>
        </div>
      </div>`;
    }

    $('#content').innerHTML = html;
    refreshIcons();

    // Upload handler
    const drop = $('.card-drop');
    const fileInput = $('.card-file');
    if (drop && fileInput) {
      drop.addEventListener('click', () => fileInput.click());
      drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('drag'); });
      drop.addEventListener('dragleave', () => drop.classList.remove('drag'));
      drop.addEventListener('drop', e => {
        e.preventDefault();
        drop.classList.remove('drag');
        if (e.dataTransfer.files.length) uploadCardTemplate(e.dataTransfer.files[0]);
      });
      fileInput.addEventListener('change', () => {
        if (fileInput.files.length) uploadCardTemplate(fileInput.files[0]);
      });
    }

    if (!templateInfo) return;

    // Click on template image to set position
    const tplImg = $('#cardTplImg');
    tplImg.addEventListener('click', e => {
      const rect = tplImg.getBoundingClientRect();
      const scaleX = templateInfo.width / rect.width;
      const scaleY = templateInfo.height / rect.height;
      const x = Math.round((e.clientX - rect.left) * scaleX);
      const y = Math.round((e.clientY - rect.top) * scaleY);

      const fieldKey = $('#cardFieldSel')?.value;
      if (!fieldKey) return;
      const field = fields.find(f => f.field_key === fieldKey);
      if (!field) return;

      field.x_pos = x;
      field.y_pos = y;
      showCardMarker(tplImg, x, y, fieldKey, CARD_FIELD_LABELS[fieldKey] || field.label || fieldKey, CARD_BOX_KEYS.includes(fieldKey) ? (parseInt(field.width, 10) || 140) : 0);
      updateCardRow(fieldKey, field);
      toast(`موقعیت «${CARD_FIELD_LABELS[fieldKey] || fieldKey}» ثبت شد (X: ${faNum(x)}, Y: ${faNum(y)})`);
    });

    // size / side editors + initial marker paint
    const paintCardMarkers = () => {
      if (!tplImg) return;
      tplImg.parentElement.querySelectorAll(".cert-marker").forEach(m => m.remove());
      fields.forEach(fd => {
        if (fd.x_pos || fd.y_pos) {
          showCardMarker(tplImg, fd.x_pos, fd.y_pos, fd.field_key, CARD_FIELD_LABELS[fd.field_key] || fd.field_key, CARD_BOX_KEYS.includes(fd.field_key) ? (parseInt(fd.width, 10) || 140) : 0);
        }
      });
    };
    paintCardMarkers();
    $$('#content .card-s-input').forEach(inp => inp.addEventListener('change', () => {
      const fd = fields.find(x => x.field_key === inp.dataset.key);
      if (fd) fd.font_size = Math.max(10, parseInt(inp.value, 10) || 22);
    }));
    $$('#content .card-w-input').forEach(inp => inp.addEventListener('change', () => {
      const fd = fields.find(x => x.field_key === inp.dataset.key);
      if (fd) { fd.width = Math.max(24, parseInt(inp.value, 10) || 140); paintCardMarkers(); }
    }));

    // Clicking a row selects the field
    $$('#content .card-frow').forEach(row => {
      row.addEventListener('click', (e) => { if (e.target.closest('input, .card-x')) return;
        $('#cardFieldSel').value = row.dataset.key;
      });
    });

    // ✕ removes a card field from the list (persisted with "save positions")
    $$('#content .card-x').forEach(b => b.addEventListener('click', (e) => {
      e.stopPropagation();
      const key = b.dataset.key;
      if (!confirm('این فیلد از کارت حذف شود؟ (با «بازگردانی فیلدهای پیش‌فرض» برمی‌گردد)')) return;
      fields = fields.filter(f => (f.field_key || f.key) !== key);
      renderCardTemplateEditor();
      toast('فیلد حذف شد — برای قطعی شدن «ذخیره موقعیت‌ها» را بزنید');
    }));
    // Restore any missing default fields (positions 0 until placed)
    $('#cardRestoreBtn')?.addEventListener('click', () => {
      Object.entries(CARD_FIELD_LABELS).forEach(([k, label]) => {
        if (!fields.some(f => (f.field_key || f.key) === k)) {
          fields.push({ field_key: k, label, x_pos: 0, y_pos: 0, font_size: 22, width: CARD_BOX_KEYS.includes(k) ? 140 : 0, font_color: '#1a1a1a' });
        }
      });
      renderCardTemplateEditor();
      toast('فیلدهای پیش‌فرض بازگردانده شدند');
    });

    // Save positions
    $('#cardSaveFields').addEventListener('click', async () => {
      const r = await apiTry('cardtpl&sub=save_fields', {
        method: 'POST',
        body: { fields }
      });
      if (r) toast(r.message || 'ذخیره شد');
    });

    // Test-generate card for the first member
    $('#cardTestGen').addEventListener('click', async () => {
      const lst = await apiTry('list&entity=members&per_page=1&s=', {}, true);
      const first = lst?.data?.rows?.[0];
      if (!first) { toast('هیچ عضوی برای تست یافت نشد', false); return; }
      const id = first.ID ?? first.id;
      busy($('#cardTestGen'), true, 'در حال تولید…');
      const r = await apiTry('cardforws', { method: 'POST', body: { id: String(id) } }, true);
      busy($('#cardTestGen'), false);
      if (r?.data?.path) {
        toast('کارت نمونه تولید شد');
        window.open('../' + r.data.path, '_blank');
      } else {
        toast(r?.message || 'تولید کارت ناموفق بود — قالب و فونت TTF را بررسی کنید', false);
      }
    });
  }

  function showCardMarker(imgEl, x, y, fieldKey, caption, side) {
    const container = imgEl.parentElement;
    container.querySelectorAll(".cert-marker[data-field]").forEach(m => {
      if (m.dataset.field === fieldKey) m.remove();
    });
    const marker = document.createElement("div");
    marker.className = "cert-marker";
    marker.dataset.field = fieldKey;
    const W = parseFloat(imgEl.dataset.w), H = parseFloat(imgEl.dataset.h);
    if (side > 0) {
      marker.style.cssText = `position:absolute;left:${((x - side / 2) / W * 100)}%;top:${((y - side / 2) / H * 100)}%;width:${(side / W * 100)}%;height:${(side / H * 100)}%;pointer-events:none;z-index:10;display:block`;
      marker.innerHTML = `<span class="cm-box"></span>` + (caption ? `<span class="cm-label cm-label-box">${esc(caption)}</span>` : "");
    } else {
      marker.style.cssText = `position:absolute;left:${(x / W * 100)}%;top:${(y / H * 100)}%;transform:translate(-100%,-50%);display:flex;flex-direction:column;align-items:flex-end;gap:3px;pointer-events:none;z-index:10`;
      marker.innerHTML = `<span class="cm-arrow">◀</span>` + (caption ? `<span class="cm-label">${esc(caption)}</span>` : "");
    }
    container.appendChild(marker);
  }
  function updateCardRow(fieldKey, field) {
    const row = $(`#content .card-frow[data-key="${fieldKey}"]`);
    if (!row) return;
    const pos = row.querySelector('.badge-yellow');
    if (pos) pos.textContent = `X: ${faNum(field.x_pos ?? 0)} Y: ${faNum(field.y_pos ?? 0)}`;
  }

  async function uploadCardTemplate(file) {
    if (!['image/png', 'image/jpeg'].includes(file.type)) { toast('فقط PNG یا JPG', false); return; }
    if (file.size > 10 * 1024 * 1024) { toast('حجم بیش از ۱۰MB', false); return; }

    const fd = new FormData();
    fd.append('template', file);
    const headers = { 'X-CSRF-Token': window.ADMIN.csrf };
    try {
      const r = await fetch(endpoint('cardtpl&sub=upload'), { method: 'POST', headers, body: fd });
      const j = await r.json();
      if (j.status === 'success') {
        toast('قالب کارت آپلود شد');
        renderCardTemplateEditor();
      } else {
        toast(j.message || 'آپلود ناموفق', false);
      }
    } catch (err) { toast('خطا در آپلود', false); }
  }

  /* ---------------- settings (two cards: general / technical) ---------------- */

  async function renderSettings() {
    $('#pageTitle').textContent = 'تنظیمات';
    const isTech = state.admin.roles?.includes('tech_admin');
    const res = await apiTry('settings_get');
    const d = res?.data || {};

    $('#content').innerHTML = `
      <div class="settings-grid">

        <!-- ===== Card 1: General ===== -->
        <div class="panel settings-card">
          <h2><i data-lucide="settings"></i> تنظیمات عمومی</h2>
          <div class="form-grid" style="max-width:560px">
            <div class="fg"><label>نام کاربری ربات تلگرام (بدون @ — برای اتصال حساب)</label><input id="setBot" value="${esc(d.bot_username || '')}" placeholder="ssaee_bot"></div>
            <div class="fg"><label>امتیاز باشگاه پژوهشگران</label><input id="setClub" type="number" min="0" value="${esc(d.researchers_club_score || '0')}"></div>
          </div>
          <p class="muted" style="font-size:.74rem;margin-top:10px">📊 درصد رضایت به‌صورت خودکار از میانگین امتیاز نظرسنجی‌های کارگاه محاسبه می‌شود و ورودی دستی نیست.</p>

          <div class="maint-box" style="margin-top:18px">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none">
              <input type="checkbox" id="setMaint" style="width:17px;height:17px;accent-color:var(--accent);cursor:pointer">
              <span style="font-size:.86rem;font-weight:600">🔧 فعال‌سازی حالت تعمیر / ارتقا</span>
            </label>
            <p class="muted" style="font-size:.72rem;margin:8px 0 0;line-height:1.9">در این حالت سایت برای بازدیدکنندگان بسته می‌شود (API هم بلاک می‌شود، به‌جز وب‌هوک تلگرام). ادمین‌های واردشده به پنل عادی می‌بینند و نوار هشدار دریافت می‌کنند. ربات تلگرام بدون وقفه کار می‌کند.</p>
            <div class="fg full" id="maintMsgWrap" style="margin-top:10px;display:${(d.maintenance_mode === '1') ? 'flex' : 'none'}">
              <label>پیام صفحه تعمیر</label>
              <textarea id="setMaintMsg" rows="2" placeholder="سایت در حال بروزرسانی است. به‌زودی برمی‌گردیم.">${esc(d.maintenance_message || '')}</textarea>
            </div>
          </div>
          <div class="modal-actions" style="margin-top:16px">
            <button class="btn btn-primary" id="setSave" type="button"><i data-lucide="save"></i> ذخیره تنظیمات عمومی</button>
          </div>
        </div>

        ${isTech ? `
        <!-- ===== Card 2: Technical (tech_admin only) ===== -->
        <div class="panel settings-card settings-tech">
          <h2><i data-lucide="wrench"></i> تنظیمات فنی</h2>
          <p class="muted" style="font-size:.78rem;margin-bottom:14px">فقط برای ادمین فنی نمایش داده می‌شود.</p>
          <div id="techStatus" class="tech-status-box muted"><span class="spin"></span> در حال دریافت وضعیت…</div>
          <div class="tech-actions">
            <a class="btn btn-primary" href="../bot/status.php" style="text-decoration:none">📡 مدیریت وب‌هوک تلگرام</a>
            <a class="btn btn-outline" href="../migrate.php" style="text-decoration:none">🗄️ مهاجرت دیتابیس</a>
          </div>
        </div>` : ''}
      </div>`;
    refreshIcons();

    // Maintenance toggle: show/hide message textarea
    $('#setMaint')?.addEventListener('change', function() {
      $('#maintMsgWrap').style.display = this.checked ? 'flex' : 'none';
    });
    $('#setMaint').checked = (d.maintenance_mode === '1');

    $('#setSave').addEventListener('click', async () => {
      const res2 = await apiTry('settings_save', {
        method: 'POST',
        body: {
          bot_username: $('#setBot').value.trim(),
          researchers_club_score: $('#setClub').value,
          maintenance_mode: $('#setMaint')?.checked ? '1' : '0',
          maintenance_message: $('#setMaintMsg')?.value.trim() || '',
        }
      });
      if (res2) toast(res2.message || 'ذخیره شد');
    });

    // Technical card: webhook + db status
    if (isTech) {
      apiTry('tech_status', {}, true).then(r => {
        const box = $('#techStatus');
        if (!box) return;
        const t = r?.data;
        if (!t) { box.innerHTML = '<span style="color:var(--danger)">دریافت وضعیت ناموفق بود (دسترسی فنی required)</span>'; return; }
        const w = t.webhook;
        const hookHtml = !t.bot_set
          ? '<span class="badge badge-red">توکن ربات تنظیم نشده (.env)</span>'
          : (w
            ? `<div class="tech-row"><span>وب‌هوک:</span><code>${esc(w.url || '—')}</code> ${w.url ? '<span class="badge badge-green">متصل</span>' : '<span class="badge badge-red">تنظیم نشده</span>'}</div>
               ${w.pending_updates ? `<div class="tech-row"><span>آپدیت‌های در صف:</span><b>${faNum(w.pending_updates)}</b></div>` : ''}
               ${w.last_error ? `<div class="tech-row"><span>آخرین خطا:</span><span style="color:var(--danger);font-size:.76rem">${esc(w.last_error)} — ${esc(w.last_error_date || '')}</span></div>` : ''}`
            : '<span class="badge badge-red">وضعیت وب‌هوک دریافت نشد</span>');
        box.classList.remove('muted');
        box.innerHTML = `
          ${hookHtml}
          <div class="tech-row"><span>دیتابیس:</span><b>${esc(t.db?.size || '—')}</b> <span class="muted">(${faNum(t.db?.tables ?? '—')} جدول — MySQL ${esc(t.db?.server || '')})</span></div>`;
      });
    }
  }

  /* ---------------- broadcast ---------------- */

  /* Drain the server-side broadcast queue chunk-by-chunk (10 msgs per request).
     onProgress receives {total, sent, failed, blocked, done, progress}. */
  async function drainBroadcast(onProgress) {
    let d = null;
    let guard = 0;
    while (guard++ < 2000) {
      const r = await api('broadcast', { method: 'POST', body: { sub: 'next' } });
      d = r.data || {};
      onProgress?.(d);
      if (d.done) return d;
    }
    return d;
  }

  /* Targeted workshop broadcast — same chunked queue, filtered to one workshop's registrants */
  function openWsBroadcastModal(tag, wsTitle) {
    let modal = document.getElementById('wsBroadcastModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.className = 'modal-bg';
      modal.id = 'wsBroadcastModal';
      modal.innerHTML = `
        <div class="modal">
          <div class="modal-head">
            <h3 id="wsBcHead"><i data-lucide="megaphone"></i> ارسال پیام به ثبت‌نامی‌ها</h3>
            <button class="icon-btn" data-close type="button"><i data-lucide="x"></i></button>
          </div>
          <div class="form-grid" style="grid-template-columns:1fr">
            <div class="fg full"><label>عنوان پیام</label><input id="wsBcTitle"></div>
            <div class="fg full"><label>متن پیام</label><textarea id="wsBcMsg" rows="4"></textarea></div>
          </div>
          <p class="muted" id="wsBcInfo" style="font-size:.76rem;margin:8px 0 0;line-height:1.9">گیرندگان: فقط ثبت‌نامی‌های تأییدشده این کارگاه که تلگرام آن‌ها متصل است.</p>
          <div id="wsBcProgressWrap" class="hidden" style="margin-top:12px">
            <div style="display:flex;justify-content:space-between;font-size:.76rem;margin-bottom:5px">
              <span id="wsBcProgressLabel" class="muted">در حال ارسال…</span>
              <span id="wsBcProgressPct" style="font-weight:700;color:var(--accent)">۰٪</span>
            </div>
            <div class="upl-bar" style="height:10px"><div class="upl-fill" id="wsBcProgressBar" style="width:0%"></div></div>
          </div>
          <div class="modal-actions" style="margin-top:14px">
            <button class="btn btn-primary" id="wsBcSend" type="button"><i data-lucide="send"></i> ارسال</button>
            <button class="btn btn-outline" type="button" data-close>انصراف</button>
          </div>
        </div>`;
      document.body.appendChild(modal);
    }

    modal.querySelector('#wsBcHead').innerHTML =
      '<i data-lucide="megaphone"></i> پیام به ثبت‌نامی‌های: ' + esc(wsTitle || tag);
    modal.dataset.tag = tag || '';
    modal.querySelector('#wsBcTitle').value = '';
    modal.querySelector('#wsBcMsg').value = '';
    modal.querySelector('#wsBcProgressWrap').classList.add('hidden');
    modal.querySelector('#wsBcSend').disabled = false;
    modal.classList.add('open');
    refreshIcons();

    if (!modal.dataset.wired) {
      modal.dataset.wired = '1';

      const applyProgress = (d) => {
        const pct = Math.max(0, Math.min(100, Number(d.progress) || 0));
        modal.querySelector('#wsBcProgressBar').style.width = pct + '%';
        modal.querySelector('#wsBcProgressPct').textContent = faNum(pct) + '٪';
        modal.querySelector('#wsBcProgressLabel').textContent =
          `ارسال: ${faNum(d.sent ?? 0)} از ${faNum(d.total ?? 0)}` +
          ((d.failed || d.blocked) ? ` — ${faNum(d.failed || 0)} خطا، ${faNum(d.blocked || 0)} بلاک` : '');
      };

      modal.querySelector('#wsBcSend').addEventListener('click', async () => {
        const tag = modal.dataset.tag;
        const title = modal.querySelector('#wsBcTitle').value.trim();
        const message = modal.querySelector('#wsBcMsg').value.trim();
        if (!tag) { toast('کد کارگاه یافت نشد', false); return; }
        if (!title || !message) { toast('عنوان و متن پیام الزامی است', false); return; }

        const ok = await uiConfirm(
          'ارسال پیام فقط به ثبت‌نامی‌های تأییدشده این کارگاه؟',
          { danger: false, okLabel: 'ارسال' }
        );
        if (!ok) return;

        const sendBtn = modal.querySelector('#wsBcSend');
        sendBtn.disabled = true;
        sendBtn.textContent = 'در حال ارسال…';
        modal.querySelector('#wsBcProgressWrap').classList.remove('hidden');
        const infoEl = modal.querySelector('#wsBcInfo');

        try {
          const res = await api('broadcast', {
            method: 'POST',
            body: { title, message, tag }
          });
          let d = res.data || {};
          applyProgress(d);
          infoEl.textContent = `گیرندگان: ${faNum(d.total ?? 0)} نفر`;

          d = await drainBroadcast(applyProgress);
          toast(`ارسال کامل شد: ${faNum(d.sent)} موفق از ${faNum(d.total)}` +
            (d.blocked ? ` — ${faNum(d.blocked)} بلاک` : '') + (d.failed ? ` — ${faNum(d.failed)} ناموفق` : ''));
          modal.classList.remove('open');
        } catch (err) {
          toast(err.message || 'خطا در ارسال پیام', false);
        } finally {
          sendBtn.disabled = false;
          sendBtn.innerHTML = '<i data-lucide="send"></i> ارسال';
          refreshIcons();
        }
      });
    }
  }

  const BC_AUDIENCES = [
    { key: 'connected', label: 'همه کاربران ربات تلگرام' },
    { key: 'members',   label: 'اعضای انجمن (تلگرام)' },
    { key: 'all',       label: 'همه (ربات + اعضا)' },
    { key: 'admins',    label: 'مدیران سایت' },
    { key: 'bot_admins', label: 'ادمین‌های ربات (پشتیبانی)' },
  ];

  function renderBroadcastSection(sec) {
    $('#pageTitle').textContent = sec.title;
    $('#content').innerHTML = `
      <div class="panel">
        <h2><i data-lucide="send"></i> ارسال پیام سراسری تلگرام</h2>
        <p class="muted" style="margin-bottom:14px">پیام به چت‌های تلگرام ارسال می‌شود. کاربرانی که ربات را بلاک کرده یا استارت نکرده‌اند قابل ارسال نیستند و در نتیجه گزارش می‌شوند.</p>
        <div class="form-grid" style="max-width:600px">
          <div class="fg full"><label>عنوان</label><input id="bcTitle"></div>
          <div class="fg full"><label>متن پیام</label><textarea id="bcMsg" rows="4"></textarea></div>
          <div class="fg full"><label>مخاطبان</label>
            <select id="bcAudience">
              ${BC_AUDIENCES.map(a => `<option value="${a.key}">${a.label}</option>`).join('')}
            </select>
          </div>
        </div>
        <div class="bc-stat muted" id="bcAudCount" style="margin:10px 0 0;font-size:.8rem"></div>
        <div id="bcProgressWrap" class="hidden" style="margin-top:14px">
          <div style="display:flex;justify-content:space-between;font-size:.76rem;margin-bottom:5px">
            <span id="bcProgressLabel" class="muted">در حال ارسال…</span>
            <span id="bcProgressPct" style="font-weight:700;color:var(--accent)">۰٪</span>
          </div>
          <div class="upl-bar" style="height:10px"><div class="upl-fill" id="bcProgressBar" style="width:0%"></div></div>
        </div>
        <div class="modal-actions" style="margin-top:16px">
          <button class="btn btn-primary" id="bcSend" type="button"><i data-lucide="send"></i> ارسال</button>
        </div>
      </div>
      <div id="entityContainer"></div>`;
    refreshIcons();

    /* live recipient count for the selected audience */
    async function updateAudCount() {
      const el = $('#bcAudCount');
      const a = $('#bcAudience')?.value;
      if (!el || !a) return;
      el.textContent = 'در حال شمارش مخاطبان…';
      const res = await apiTry('broadcast&sub=stats', {}, true);
      const n = res?.data?.audiences?.[a];
      el.textContent = n === undefined
        ? ''
        : `👥 گیرندگان این مخاطب: ${faNum(n)} نفر`;
    }
    $('#bcAudience')?.addEventListener('change', updateAudCount);
    updateAudCount();

    $('#bcSend').addEventListener('click', async () => {
      const title = $('#bcTitle').value.trim();
      const message = $('#bcMsg').value.trim();
      if (!title || !message) { toast('عنوان و متن الزامی است', false); return; }
      const audience = $('#bcAudience').value;
      const ok = await uiConfirm(
        `ارسال پیام به همه گیرندگان مخاطب انتخاب‌شده؟ این عمل قابل بازگشت نیست.`,
        { danger: false, okLabel: 'ارسال پیام' }
      );
      if (!ok) return;

      const btn = $('#bcSend');
      const progWrap = $('#bcProgressWrap');
      const progBar = $('#bcProgressBar');
      const progPct = $('#bcProgressPct');
      const progLabel = $('#bcProgressLabel');
      btn.disabled = true;
      btn.textContent = 'در حال ارسال…';
      progWrap.classList.remove('hidden');

      const applyProgress = (d) => {
        const pct = Math.max(0, Math.min(100, Number(d.progress) || 0));
        progBar.style.width = pct + '%';
        progPct.textContent = faNum(pct) + '٪';
        progLabel.textContent = `ارسال: ${faNum(d.sent ?? 0)} از ${faNum(d.total ?? 0)}` +
          ((d.failed || d.blocked) ? ` — ${faNum(d.failed || 0)} خطا، ${faNum(d.blocked || 0)} بلاک` : '');
      };

      try {
        // Phase 1: create the job (resolves recipients, stores them server-side)
        const res = await api('broadcast', {
          method: 'POST',
          body: { title, message, audience }
        });
        let d = res.data || {};
        applyProgress(d);

        // Phase 2: drain the queue chunk-by-chunk (shared helper)
        d = await drainBroadcast(applyProgress);
        progBar.style.width = '100%';
        progPct.textContent = '۱۰۰٪';
        progLabel.textContent = `تمام شد — ${faNum(d.sent ?? 0)} موفق از ${faNum(d.total ?? 0)}` +
          (d.blocked ? ` — ${faNum(d.blocked)} بلاک` : '') + (d.failed ? ` — ${faNum(d.failed)} ناموفق` : '');
        toast(d.done ? `ارسال کامل شد: ${faNum(d.sent)} موفق از ${faNum(d.total)}` : 'ارسال انجام شد');
      } catch (err) {
        toast(err.message || 'خطا در ارسال پیام سراسری', false);
        progLabel.textContent = 'خطا — ارسال متوقف شد';
      } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="send"></i> ارسال';
        refreshIcons();
      }
    });

    renderEntityInto($('#entityContainer'), 'announcements', 'اطلاعیه‌های سایت');

    // Workshop survey panel
    const surveyPanel = document.createElement('div');
    surveyPanel.className = 'panel';
    surveyPanel.innerHTML = `<h2><i data-lucide="clipboard-check"></i> نظرسنجی کارگاه</h2>
      <p class="muted" style="margin-bottom:12px;font-size:.82rem">ارسال نظرسنجی رضایت به ثبت‌نامی‌های یک کارگاه (۴ ساعت بعد از آخرین جلسه هم خودکار ارسال می‌شود)</p>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <select id="surveyWsSelect" style="background:var(--bg-3);border:1px solid var(--border);border-radius:10px;padding:9px 14px;color:var(--text);font-family:inherit;min-width:200px">
          <option value="">انتخاب کارگاه…</option>
        </select>
        <button class="btn btn-primary" id="surveySendBtn" type="button" disabled><i data-lucide="send"></i> ارسال نظرسنجی</button>
      </div>`;
    $('#entityContainer').parentNode.insertBefore(surveyPanel, $('#entityContainer'));
    refreshIcons();

    // Wire survey dropdown (workshops come from banners: TargetTag/Title)
    const surveySel = surveyPanel.querySelector('#surveyWsSelect');
    apiTry('list&entity=banners&per_page=100&s=', {}, true).then(res => {
      if (res?.data?.rows) {
        const seen = new Set();
        res.data.rows.forEach(b => {
          if (!b.TargetTag || seen.has(b.TargetTag)) return;
          seen.add(b.TargetTag);
          const opt = document.createElement('option');
          opt.value = b.TargetTag;
          opt.textContent = b.Title || b.TargetTag;
          surveySel.appendChild(opt);
        });
      }
    });
    surveyPanel.querySelector('#surveySendBtn').addEventListener('click', async () => {
      if (!surveySel.value) { toast('انتخاب کارگاه الزامی است', false); return; }
      const ok = await uiConfirm('ارسال نظرسنجی برای همه ثبت‌نامی‌های این کارگاه؟', { danger: false, okLabel: 'ارسال' });
      if (!ok) return;
      busy(surveyPanel.querySelector('#surveySendBtn'), true, 'در حال ارسال…');
      const r = await apiTry('send_survey', { method: 'POST', body: { tag: surveySel.value } });
      busy(surveyPanel.querySelector('#surveySendBtn'), false);
      if (r) toast(r.message || 'نظرسنجی ارسال شد');
    });
  }

  /* ---------------- entity pages ---------------- */

  function renderTabbedEntities(sec) {
    $('#pageTitle').textContent = sec.title;
    $('#content').innerHTML = `
      <div class="tabs">${sec.entities.map((e, i) =>
        `<button class="tab-btn ${i === 0 ? 'active' : ''}" data-ent="${esc(e)}" type="button">${esc(state.meta.entities[e].title)}</button>`
      ).join('')}</div>
      <div id="entityContainer"></div>`;

    $$('#content .tab-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        $$('#content .tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        renderEntityInto($('#entityContainer'), btn.dataset.ent, state.meta.entities[btn.dataset.ent].title);
      });
    });

    renderEntityInto($('#entityContainer'), sec.entities[0], state.meta.entities[sec.entities[0]].title);
  }

  function renderEntityPage(entityKey, title) {
    $('#pageTitle').textContent = title;
    $('#content').innerHTML = '<div id="entityContainer"></div>';
    renderEntityInto($('#entityContainer'), entityKey, title);
  }

  /* Auto-points settings panel (members section — task 2) */
  function mountAutoPointsPanel(container, entityKey) {
    if (entityKey !== 'members') return;
    const canManage = (state.admin.roles || []).some(r => ['superadmin', 'tech_admin', 'members_admin'].includes(r));
    if (!canManage) return;

    const panel = document.createElement('div');
    panel.className = 'panel';
    panel.style.marginBottom = '14px';
    panel.innerHTML = `
      <h2><i data-lucide="star"></i> امتیاز خودکار</h2>
      <p class="muted" style="font-size:.78rem;margin-bottom:12px;line-height:1.9">
        پس از اتمام هر کارگاه (۲۴ ساعت بعد از آخرین جلسه)، این امتیاز به‌صورت خودکار به ثبت‌نامی‌های تأییدشده‌ی عضو اهدا می‌شود (هر کارگاه فقط یک‌بار).
        مقدار «فعالیت» به‌عنوان مبلغ پیش‌فرض دکمه ‎+امتیاز در جدول اعضا استفاده می‌شود. ۰ = غیرفعال.
      </p>
      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <label style="font-size:.8rem;display:flex;align-items:center;gap:8px">
          امتیاز کارگاه
          <input id="apWorkshop" type="number" min="0" max="100" style="width:80px">
        </label>
        <label style="font-size:.8rem;display:flex;align-items:center;gap:8px">
          امتیاز پیش‌فرض فعالیت
          <input id="apActivity" type="number" min="0" max="100" style="width:80px">
        </label>
        <button class="btn btn-primary btn-sm" id="apSave" type="button"><i data-lucide="save"></i> ذخیره</button>
      </div>`;
    container.insertBefore(panel, container.firstChild);
    refreshIcons();

    apiTry('autopoints', {}, true).then(res => {
      const d = res?.data;
      if (!d) return;
      panel.querySelector('#apWorkshop').value = d.auto_points_workshop ?? 0;
      panel.querySelector('#apActivity').value = d.auto_points_activity ?? 5;
    });

    panel.querySelector('#apSave').addEventListener('click', async () => {
      const body = {
        auto_points_workshop: panel.querySelector('#apWorkshop').value,
        auto_points_activity: panel.querySelector('#apActivity').value,
      };
      const res = await apiTry('autopoints', { method: 'POST', body });
      if (res) toast(res.message || 'ذخیره شد');
    });
  }

  async function renderEntityInto(container, entityKey, title) {
    const meta = state.meta.entities[entityKey];
    if (!meta) {
      container.innerHTML = '<div class="loading">دسترسی ندارید</div>';
      return;
    }

    container.dataset.entity = entityKey;
    mountAutoPointsPanel(container, entityKey);
    container.innerHTML = `
      ${entityKey === 'certificates' && (state.meta.cert_untranslated || 0) > 0 ? `<div class="cert-trans-warn"><i data-lucide="alert-triangle"></i> <b>${faNum(state.meta.cert_untranslated)} گواهی</b> هنوز به انگلیسی ترجمه نشده‌اند (نام یا دورهٔ انگلیسی خالی است) — برای صدور صحیح گواهی، در ویرایش هر گواهی ترجمه کنید.</div>` : ''}
      <div class="panel">
        <div class="toolbar">
          <div class="search-box">
            <i data-lucide="search"></i>
            <input type="search" class="ent-search" placeholder="جستجوی زنده در ${esc(meta.title)}…">
          </div>
          <div class="spacer"></div>
          <button class="btn btn-outline ent-pdf" type="button"><i data-lucide="file-down"></i> خروجی PDF</button>
          ${entityKey === 'registrations' ? '<select class="ent-filter-ws" style="padding:8px 12px;border-radius:10px;border:1px solid var(--border);background:var(--bg-3);color:var(--text);font-family:inherit"><option value="">همه کارگاه‌ها</option></select>' : ''}
          ${entityKey === 'registrations' ? '<button class="btn btn-primary ent-cert-ws" type="button" disabled><i data-lucide="award"></i> صدور گواهی حضور</button>' : ''}
          ${entityKey === 'registrations' ? '<button class="btn btn-outline ent-survey" type="button" disabled><i data-lucide="bar-chart-3"></i> نظرسنجی</button>' : ''}
          ${(meta.readonly || entityKey === 'workshopreq' || entityKey === 'classreq') ? '' : '<button class="btn btn-primary ent-add" type="button" title="افزودن"><i data-lucide="plus"></i></button>'}
          ${(entityKey === 'certificates' || entityKey === 'class_links') ? '<button class="btn btn-outline ent-bulk" type="button"><i data-lucide="file-spreadsheet"></i> افزودن گروهی</button>' : ''}

        </div>
        <div class="table-wrap"><div class="loading">در حال بارگذاری…</div></div>
        <div class="pagination"></div>
      </div>`;
    refreshIcons();

    // live search (debounced)
    const searchInput = container.querySelector('.ent-search');
    searchInput.addEventListener('input', debounce(() => {
      state.page = 1;
      state.search = searchInput.value.trim();
      loadEntityRows(container, entityKey);
    }, 400));

    container.querySelector('.ent-pdf').addEventListener('click', () => exportEntityPdf(entityKey));

    const addBtn = container.querySelector('.ent-add');
    if (addBtn) addBtn.addEventListener('click', () => openEntityForm(entityKey, null, container));

    const bulkBtn = container.querySelector('.ent-bulk');
    if (bulkBtn) bulkBtn.addEventListener('click', () => openBulkModal(entityKey, container));

    container.querySelectorAll('.ent-sessions-row').forEach(btn => {
      btn.addEventListener('click', () => openSessionsModal(container, btn.dataset.tag, btn.dataset.title));
    });

    /* Registrations: single workshop filter drives cert issuance + survey */
    if (entityKey === 'registrations') {
      const wsFilter = container.querySelector('.ent-filter-ws');
      const certBtn = container.querySelector('.ent-cert-ws');
      const surveyBtn = container.querySelector('.ent-survey');

      // Populate workshops from banners (TargetTag = workshop tag)
      apiTry('list&entity=banners&per_page=100&s=', {}, true).then(res => {
        if (res?.data?.rows) {
          const seen = new Set();
          res.data.rows.forEach(b => {
            if (!b.TargetTag || seen.has(b.TargetTag)) return;
            seen.add(b.TargetTag);
            const opt = document.createElement('option');
            opt.value = b.TargetTag;
            opt.textContent = b.Title || b.TargetTag;
            wsFilter?.appendChild(opt);
          });
        }
      });

      wsFilter?.addEventListener('change', () => {
        state.page = 1;
        state.filter = wsFilter.value;
        state.filterCol = 'WorkshopTag';
        loadEntityRows(container, entityKey);
        if (certBtn) certBtn.disabled = !wsFilter.value;
        if (surveyBtn) surveyBtn.disabled = !wsFilter.value;
      });

      // Issue attendance certificates for the selected workshop
      certBtn?.addEventListener('click', async () => {
        if (!wsFilter?.value) return;
        const certType = await uiPrompt('نوع گواهی برای درج روی قالب (اختیاری — در بخش «توضیح/نوع گواهی نامه» قرار می‌گیرد):', {
          placeholder: 'مثلاً: گواهی حضور در کارگاه',
          okLabel: 'صدور گواهی',
        });
        if (certType === null) return;
        busy(certBtn, true, 'در حال صدور…');
        const res = await apiTry('certforws', { method: 'POST', body: { tag: wsFilter.value, cert_type: certType } });
        busy(certBtn, false);
        if (res) {
          toast(res.message || 'گواهی‌ها صادر شد');
          loadEntityRows(container, entityKey);
        }
      });

      // Send satisfaction survey for the selected workshop
      surveyBtn?.addEventListener('click', async () => {
        if (!wsFilter?.value) return;
        const ok = await uiConfirm('ارسال نظرسنجی برای همه ثبت‌نامی‌های متصل به تلگرام؟', { danger: false, okLabel: 'ارسال' });
        if (!ok) return;
        busy(surveyBtn, true, 'در حال ارسال…');
        const res = await apiTry('send_survey', { method: 'POST', body: { tag: wsFilter.value } });
        busy(surveyBtn, false);
        if (res) toast(res.message || 'نظرسنجی ارسال شد');
      });
    }

    loadEntityRows(container, entityKey);
  }

  async function loadEntityRows(container, entityKey) {
    const meta = state.meta.entities[entityKey];
    const wrap = container.querySelector('.table-wrap');
    wrap.innerHTML = `<div class="skel-rows">
      ${Array.from({ length: 6 }, () => `
        <div class="skel-row">
          <div class="skeleton skel-av"></div>
          <div class="skel-lines">
            <div class="skeleton skel-line w60"></div>
            <div class="skeleton skel-line w40"></div>
          </div>
          <div class="skeleton skel-line w80" style="width:90px"></div>
        </div>`).join('')}
    </div>`;

    const qs = new URLSearchParams({
      entity: entityKey,
      page: state.page,
      s: state.search,
    });

    const res = await apiTry('list&' + qs.toString(), {}, true);
    if (!res) {
      wrap.innerHTML = '<div class="loading">خطا در بارگذاری داده‌ها</div>';
      return;
    }

    const { rows, total, pages } = res.data;

    if (!rows.length) {
      wrap.innerHTML = `<div class="loading">${state.search ? 'نتیجه‌ای برای جستجو یافت نشد' : 'موردی یافت نشد'}</div>`;
    } else {
      const head = Object.values(meta.columns).map(l => `<th>${esc(l)}</th>`).join('') + '<th>عملیات</th>';
      const body = rows.map(r => {
        const id = r[meta.id.split('.').pop()] ?? r[meta.id];
        const tds = Object.keys(meta.columns).map(col => {
          let val = r[col.split('.').pop()] ?? r[col] ?? '';
          if (col === 'Active' || col === 'is_active') {
            if (entityKey === 'members') {
              // task 3 semantics: Active=0 → «عادی» (بدون کارت) · Active=1 → «فعال» (دارای کارت عضویت)
              val = Number(val)
                ? '<span class="badge badge-green" title="دارای کارت عضویت">فعال</span>'
                : '<span class="badge badge-yellow" title="عضویت عادی (بدون کارت)">عادی</span>';
            } else {
              val = Number(val) ? '<span class="badge badge-green">فعال</span>' : '<span class="badge badge-gray">غیرفعال</span>';
            }
          } else if (col === '_reg_status') {
            const cls = { 'باز است': 'badge-green', 'به‌زودی': 'badge-yellow', 'بسته است': 'badge-red', 'غیرفعال': 'badge-gray' }[val] || 'badge-gray';
            val = '<span class="badge ' + cls + '">' + esc(val) + '</span>';
          } else if (['NEW', 'PENDING'].includes(val)) {
            val = '<span class="badge badge-yellow">' + esc(statusFa(val)) + '</span>';
          } else if (['CONFIRMED', 'تایید شده', 'فعال'].includes(val)) {
            val = '<span class="badge badge-green">' + esc(statusFa(val)) + '</span>';
          } else if (['REJECTED', 'رد شده', 'غیرفعال'].includes(val)) {
            val = '<span class="badge badge-red">' + esc(statusFa(val)) + '</span>';
          } else if (typeof val === 'string' && val.startsWith('http')) {
            val = '<img class="thumb" src="' + esc(val) + '" alt="">';
          } else {
            val = esc(String(val));
          }
          return `<td>${val}</td>`;
        }).join('');

        const actions = [];
        if (entityKey === 'banners' && r._reg_status === 'باز است') {
          actions.unshift(`<span class="ws-live-dot" title="ثبت‌نام فعال"></span>`);
        }
        (meta.row_actions || []).forEach(ra => {
          if (ra.when && String(r[ra.when[0]]) !== ra.when[1]) return;
          actions.push(`<button class="btn btn-outline btn-sm row-action" data-do="${esc(ra.key)}" data-id="${esc(String(id))}" type="button" title="${esc(ra.label)}"><i data-lucide="${{ points: 'plus', toggle: 'power', approve_req: 'check', reject_req: 'x' }[ra.key] || 'check'}"></i></button>`);
        });
        if (entityKey === 'banners' && r.TargetTag) {
          actions.push(`<button class="btn btn-outline btn-sm ent-sessions-row" data-tag="${esc(r.TargetTag)}" data-title="${esc(r.Title)}" type="button" title="جلسات"><i data-lucide="calendar-clock"></i></button>`);
          actions.push(`<button class="btn btn-outline btn-sm ent-ws-broadcast" data-tag="${esc(r.TargetTag)}" data-title="${esc(r.Title)}" type="button" title="ارسال پیام به ثبت‌نامی‌های این کارگاه"><i data-lucide="megaphone"></i></button>`);
        }
        if (entityKey === 'members') {
          actions.push(`<button class="btn btn-outline btn-sm ent-card-gen" data-id="${esc(String(id))}" type="button" title="تولید کارت عضویت"><i data-lucide="id-card"></i></button>`);
        }
        if (!meta.readonly) {
          actions.push(`<button class="btn btn-outline btn-sm ent-edit" data-id="${esc(String(id))}" type="button" title="ویرایش"><i data-lucide="pencil"></i></button>`);
          actions.push(`<button class="btn btn-danger btn-sm ent-delete" data-id="${esc(String(id))}" type="button" title="حذف"><i data-lucide="trash-2"></i></button>`);
        }

        return `<tr>${tds}<td style="white-space:nowrap">${actions.join(' ')}</td></tr>`;
      }).join('');

      wrap.innerHTML = `<table class="data-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
    }

    refreshIcons();

    const pag = container.querySelector('.pagination');
    if (pages > 1) {
      let html = '';
      const start = Math.max(1, state.page - 3);
      const end = Math.min(pages, state.page + 3);
      for (let i = start; i <= end; i++) {
        html += `<a data-page="${i}" class="${i === state.page ? 'current' : ''}">${faNum(i)}</a>`;
      }
      pag.innerHTML = html;
      pag.querySelectorAll('a').forEach(a => {
        a.addEventListener('click', () => {
          state.page = Number(a.dataset.page);
          loadEntityRows(container, entityKey);
        });
      });
    } else {
      pag.innerHTML = '';
    }

    wrap.querySelectorAll('.ent-edit').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const row = rows.find(r => String(r[meta.id.split('.').pop()] ?? r[meta.id]) === id);
        openEntityForm(entityKey, row, container);
      });
    });

    wrap.querySelectorAll('.ent-delete').forEach(btn => {
      btn.addEventListener('click', async () => {
        const ok = await uiConfirm('آیا از حذف این مورد مطمئن هستید؟');
        if (!ok) return;
        const res = await apiTry('delete', { method: 'POST', body: { entity: entityKey, id: btn.dataset.id } });
        if (res) {
          toast(res.message || 'حذف شد');
          loadEntityRows(container, entityKey);
        }
      });
    });

    wrap.querySelectorAll('.ent-card-gen').forEach(btn => {
      btn.addEventListener('click', async () => {
        busy(btn, true, '');
        const res = await apiTry('cardforws', { method: 'POST', body: { id: btn.dataset.id } }, true);
        busy(btn, false);
        if (res?.data?.path) {
          toast(res.message || 'کارت عضویت تولید شد');
          window.open('../' + res.data.path, '_blank');
        } else {
          toast(res?.message || 'تولید کارت ناموفق بود — ابتدا قالب کارت را در بخش اعضا تنظیم کنید', false);
        }
      });
    });

    // Sessions modal buttons are rendered with the rows — wire them here
    wrap.querySelectorAll('.ent-sessions-row').forEach(btn => {
      btn.addEventListener('click', () => openSessionsModal(container, btn.dataset.tag, btn.dataset.title));
    });

    // Targeted workshop broadcast — reuse the chunked broadcast queue
    wrap.querySelectorAll('.ent-ws-broadcast').forEach(btn => {
      btn.addEventListener('click', () => openWsBroadcastModal(btn.dataset.tag, btn.dataset.title));
    });

    wrap.querySelectorAll('.row-action').forEach(btn => {
      btn.addEventListener('click', async () => {
        const doKey = btn.dataset.do;
        if (doKey === 'pdf') {
          window.open(endpoint('certtpl&sub=preview_cert&id=' + encodeURIComponent(btn.dataset.id)), '_blank', 'noopener');
          return;
        }
        if (doKey === 'approve_req' && !(await uiConfirm('تأیید درخواست عضویت این شخص؟', { danger: false, okLabel: 'تأیید عضویت' }))) return;
        if (doKey === 'reject_req' && !(await uiConfirm('رد درخواست عضویت؟'))) return;
        const res = await apiTry('row_action', { method: 'POST', body: { do: doKey, id: btn.dataset.id, amount: 5 } });
        if (res) {
          toast(res.message || 'انجام شد');
          loadEntityRows(container, entityKey);
        }
      });
    });
  }

  function statusFa(s) {
    return ({
      NEW: 'جدید', PENDING: 'در انتظار', CONFIRMED: 'تأیید شده',
      REJECTED: 'رد شده', CANCELLED: 'لغو شده', 'تایید شده': 'تأیید شده',
    })[s] || s;
  }

  /* ---------------- PDF export (light theme, print-to-PDF) ---------------- */

  function exportEntityPdf(entityKey) {
    const meta = state.meta.entities[entityKey];
    const table = $('#entityContainer .data-table');
    if (!table) { toast('جدولی برای خروجی یافت نشد', false); return; }

    const clone = table.cloneNode(true);
    const headRow = clone.querySelector('thead tr');
    if (headRow) headRow.deleteCell(-1);
    clone.querySelectorAll('tbody tr').forEach(tr => tr.deleteCell(-1));
    clone.querySelectorAll('img').forEach(img => img.remove());

    const count = clone.querySelectorAll('tbody tr').length;
    const w = window.open('', '_blank', 'width=960,height=680');
    if (!w) { toast('پنجره خروجی مسدود شد (Pop-up Blocker)', false); return; }

    w.document.write(`<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>SSAEE — ${esc(meta.title)}</title>
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Vazirmatn, Tahoma, sans-serif; background: #ffffff; color: #1a1a1a; padding: 28px; }
  .head { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 3px solid #b8860b; padding-bottom: 12px; margin-bottom: 18px; }
  .head h1 { font-size: 1.15rem; color: #9a6f08; }
  .head .brand { font-size: .78rem; color: #888; margin-top: 4px; }
  .meta { text-align: left; font-size: .8rem; color: #555; line-height: 1.9; }
  table { width: 100%; border-collapse: collapse; font-size: .78rem; }
  th, td { border: 1px solid #ddd; padding: 8px 10px; text-align: right; }
  th { background: #f5f0e0; color: #7a5c00; font-weight: 700; }
  tr:nth-child(even) td { background: #fafafa; }
  @page { size: A4; margin: 12mm; }
  @media print { body { padding: 0; } }
</style>
</head>
<body>
  <div class="head">
    <div>
      <h1>${esc(meta.title)}</h1>
      <div class="brand">انجمن علمی برق — SSAEE</div>
    </div>
    <div class="meta">
      تاریخ گزارش: ${faToday()}<br>
      تعداد رکورد: ${faNum(count)}
    </div>
  </div>
  ${clone.outerHTML}
</body>
</html>`);
    w.document.close();
    w.focus();
    setTimeout(() => w.print(), 500);
  }

  /* ---------------- entity add/edit form ---------------- */

  function openEntityForm(entityKey, row, container) {
    const meta = state.meta.entities[entityKey];
    const form = $('#entityForm');
    const isEdit = !!row;

    $('#formModalTitle').innerHTML = '<i data-lucide="' + esc(meta.icon) + '"></i> ' +
      (isEdit ? 'ویرایش — ' : 'افزودن — ') + esc(meta.title);

    form.innerHTML = meta.fields.map(f => {
      const [name, label, type, required, options] = f;
      const val = row ? (row[name] ?? '') : '';
      const req = required ? ' required' : '';
      const full = ['textarea', 'password'].includes(type) || (type === 'text' && /لینک|آدرس/.test(label)) ? ' full' : '';

      let field;
      if (type === 'textarea') {
        field = `<textarea name="${esc(name)}" rows="3"${req}>${esc(val)}</textarea>`;
      } else if (type === 'select') {
        const opts = Object.entries(options || {}).map(([v, l]) =>
          `<option value="${esc(v)}" ${String(val) === String(v) ? 'selected' : ''}>${esc(l)}</option>`).join('');
        field = `<select name="${esc(name)}"${req}>${opts}</select>`;
      } else if (type === 'image_upload') {
        field = `<div class="upl-widget" data-kind="${esc(entityKey === 'banners' ? 'banner' : 'board')}">
          <div class="upl-drop" tabindex="0">
            <i data-lucide="image-up"></i>
            <b>تصویر را اینجا رها کنید</b>
            <span>یا کلیک کنید — JPG / PNG / WebP (حداکثر ۲MB)</span>
          </div>
          <input type="file" class="upl-file" accept="image/jpeg,image/png,image/webp" style="display:none">
          <div class="upl-progress hidden">
            <div class="upl-bar"><div class="upl-fill"></div></div>
            <span class="upl-pct">۰٪</span>
          </div>
          <div class="upl-preview" style="display:none"><img alt=""><button class="upl-remove btn btn-danger btn-sm" type="button"><i data-lucide="x"></i> حذف</button></div>
          <input name="${esc(name)}" type="hidden" class="upl-input" value="${esc(val)}">
          ${val ? `<div class="upl-preview active" style="display:flex"><img src="${esc(val.startsWith('assets/') ? '../' + val : val)}" alt=""></div>` : ''}
        </div>`;
      } else if (type === 'roles_multi') {
        const current = row ? (row.roles || []) : [];
        const list = Object.entries(window.ADMIN_ROLES || {}).map(([v, l]) =>
          `<label class="role-chk"><input type="checkbox" name="roles" value="${esc(v)}" ${current.includes(v) ? 'checked' : ''}> ${esc(l)}</label>`
        ).join('');
        field = `<div class="roles-list">${list}</div>`;
      } else {
        const hint = type === 'date' ? ' placeholder="۱۴۰۴/۰۷/۱۵"' : '';
        field = `<input name="${esc(name)}" type="${type === 'password' ? 'password' : 'text'}" value="${esc(val)}"${hint}${req}>`;
      }

      return `<div class="fg${full}"><label>${esc(label)}</label>${field}</div>`;
    }).join('') + `
      <div class="modal-actions full">
        <button class="btn btn-primary" type="submit">ذخیره</button>
        <button class="btn btn-outline" type="button" data-close>انصراف</button>
      </div>`;

    form.onsubmit = async e => {
      e.preventDefault();
      const body = { entity: entityKey, id: isEdit ? String(row[meta.id.split('.').pop()] ?? row[meta.id]) : '' };
      new FormData(form).forEach((v, k) => { body[k] = v; });

      // multi-role checkboxes
      if (entityKey === 'users') {
        body.roles = $$('input[name="roles"]:checked', form).map(i => i.value);
      }

      const res = await apiTry('save', { method: 'POST', body });
      if (res) {
        toast(res.message || 'ذخیره شد');
        $('#formModal').classList.remove('open');
        // Activities edited → review flag cleared server-side; refresh the alert dot
        if (entityKey === 'activities' && state.alerts?.activities > 0) {
          state.alerts.activities = Math.max(0, state.alerts.activities - 1);
          syncActivityAlerts(state.alerts.activities);
        }
        loadEntityRows(container, entityKey);
      }
    };

    $$('.upl-widget', form).forEach(wireUploadWidget);

    $('#formModal').classList.add('open');
    refreshIcons();
  }

  /* ---------------- upload widget (drag & drop + progress) ---------------- */

  function faPct(n) {
    return String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]) + '٪';
  }

  function wireUploadWidget(widget) {
    const drop = widget.querySelector('.upl-drop');
    const file = widget.querySelector('.upl-file');
    const input = widget.querySelector('.upl-input');
    const progress = widget.querySelector('.upl-progress');
    const fill = widget.querySelector('.upl-fill');
    const pct = widget.querySelector('.upl-pct');
    const preview = widget.querySelector('.upl-preview');
    const kind = widget.dataset.kind || 'board';

    function setPath(path) {
      input.value = path;
      if (path) {
        const imgUrl = path.startsWith('assets/') ? '../' + path : path;
        preview.innerHTML = `<img src="${esc(imgUrl)}" alt=""><button class="upl-remove btn btn-danger btn-sm" type="button"><i data-lucide="x"></i> حذف</button>`;
        preview.style.display = 'flex';
      } else {
        preview.innerHTML = '';
        preview.style.display = 'none';
      }
      refreshIcons();
    }

    // initial state
    if (input.value) {
      const initUrl = input.value.startsWith('assets/') ? '../' + input.value : input.value;
      preview.style.display = 'flex';
      preview.innerHTML = `<img src="${esc(initUrl)}" alt=""><button class="upl-remove btn btn-danger btn-sm" type="button"><i data-lucide="x"></i> حذف</button>`;
    }

    // remove
    preview.addEventListener('click', e => {
      if (e.target.closest('.upl-remove')) {
        input.value = '';
        preview.style.display = 'none';
      }
    });

    // click / keyboard → file picker
    drop.addEventListener('click', () => file.click());
    drop.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); file.click(); }
    });
    file.addEventListener('change', () => {
      if (file.files.length) uploadFile(file.files[0]);
    });

    // drag & drop
    ['dragenter', 'dragover'].forEach(ev =>
      drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach(ev =>
      drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
    drop.addEventListener('drop', e => {
      const f = e.dataTransfer?.files?.[0];
      if (f) uploadFile(f);
    });

    function uploadFile(f) {
      // client-side sanity
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(f.type)) {
        toast('فقط JPG / PNG / WebP مجاز است', false);
        return;
      }
      if (f.size > 2 * 1024 * 1024) {
        toast('حجم فایل بیش از ۲ مگابایت است', false);
        return;
      }

      const fd = new FormData();
      fd.append('photo_file', f);
      fd.append('kind', kind);

      progress.style.display = 'flex';
      fill.style.width = '0%';
      pct.textContent = faPct(0);
      drop.style.opacity = '.5';

      const xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint('upload&kind=' + kind), true);
      xhr.setRequestHeader('X-CSRF-Token', window.ADMIN.csrf);
      xhr.setRequestHeader('Accept', 'application/json');

      xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
          const p = Math.round((e.loaded / e.total) * 100);
          fill.style.width = p + '%';
          pct.textContent = faPct(p);
        }
      };

      xhr.onload = () => {
        progress.style.display = 'none';
        drop.style.opacity = '';
        let j = null;
        try { j = JSON.parse(xhr.responseText); } catch (err) { /* ignore */ }
        if (xhr.status === 200 && j?.status === 'success') {
          setPath(j.data.path);
          toast(j.message || 'تصویر آپلود شد');
        } else {
          toast(j?.message || 'آپلود ناموفق بود', false);
        }
        file.value = '';
      };

      xhr.onerror = () => {
        progress.style.display = 'none';
        drop.style.opacity = '';
        toast('خطا در ارتباط با سرور هنگام آپلود', false);
        file.value = '';
      };

      xhr.send(fd);
    }
  }

  /* ---------------- bulk import (xlsx) ---------------- */

  function openBulkModal(entityKey, container) {
    const labels = {
      certificates: {
        title: 'افزودن گروهی گواهینامه',
        cols: 'نام فارسی | نام انگلیسی | کد ملی | شماره گواهینامه | عنوان دوره | نوع گواهی | تاریخ انقضا',
        required: 'نام فارسی، کد ملی، عنوان دوره الزامی‌اند',
        kind: 'certificates',
      },
      class_links: {
        title: 'افزودن گروهی لینک کلاس',
        cols: 'نام درس | استاد | روز | ساعت | لینک',
        required: 'نام درس، استاد، لینک الزامی‌اند',
        kind: 'class_links',
      },
    };
    const cfg = labels[entityKey];

    let modal = document.getElementById('bulkModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.className = 'modal-bg';
      modal.id = 'bulkModal';
      modal.innerHTML = `
        <div class="modal">
          <div class="modal-head">
            <h3 id="bulkTitle"></h3>
            <button class="icon-btn" data-close type="button"><i data-lucide="x"></i></button>
          </div>
          <div class="upl-widget">
            <div class="upl-drop bulk-drop" tabindex="0">
              <i data-lucide="file-spreadsheet"></i>
              <b>فایل اکسل را اینجا رها کنید</b>
              <span>یا کلیک کنید — xlsx (حداکثر ۵MB)</span>
            </div>
            <input type="file" class="bulk-file" accept=".xlsx,.xls" style="display:none">
            <div class="upl-progress hidden">
              <div class="upl-bar"><div class="upl-fill"></div></div>
              <span class="upl-pct">۰٪</span>
            </div>
          </div>
          <p class="muted" style="font-size:.76rem;margin:10px 0">ستون‌ها (سطر اول): <b id="bulkCols"></b></p>
          <div id="bulkResult" class="hidden"></div>
          <div class="modal-actions">
            <button class="btn btn-primary" id="bulkGo" type="button" disabled>شروع واردسازی</button>
            <button class="btn btn-outline" type="button" data-close>انصراف</button>
          </div>
        </div>`;
      document.body.appendChild(modal);
    }

    modal.querySelector('#bulkTitle').innerHTML = '<i data-lucide="file-spreadsheet"></i> ' + esc(cfg.title);
    modal.querySelector('#bulkCols').textContent = cfg.cols;
    modal.querySelector('.bulk-file').value = '';
    modal.querySelector('#bulkResult').classList.add('hidden');
    modal.querySelector('#bulkGo').disabled = true;
    modal.dataset.kind = cfg.kind;
    modal.dataset.entity = entityKey;
    modal.dataset.container = '1';
    modal._container = container;

    const drop = modal.querySelector('.bulk-drop');
    const file = modal.querySelector('.bulk-file');
    drop.onclick = () => file.click();
    file.onchange = () => {
      modal.querySelector('#bulkGo').disabled = !file.files.length;
    };
    drop.ondragover = e => { e.preventDefault(); drop.classList.add('drag'); };
    drop.ondragleave = () => drop.classList.remove('drag');
    drop.ondrop = e => {
      e.preventDefault();
      drop.classList.remove('drag');
      if (e.dataTransfer.files.length) {
        file.files = e.dataTransfer.files;
        modal.querySelector('#bulkGo').disabled = false;
      }
    };

    const go = modal.querySelector('#bulkGo');
    go.onclick = () => {
      if (!file.files.length) return;
      const fd = new FormData();
      fd.append('xlsx', file.files[0]);
      fd.append('kind', cfg.kind);
      const progress = modal.querySelector('.upl-progress');
      const fill = modal.querySelector('.upl-fill');
      const pct = modal.querySelector('.upl-pct');
      progress.style.display = 'flex';
      go.disabled = true;

      const xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint('bulkimport&kind=' + cfg.kind), true);
      xhr.setRequestHeader('X-CSRF-Token', window.ADMIN.csrf);
      xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
          const p = Math.round((e.loaded / e.total) * 100);
          fill.style.width = p + '%';
          pct.textContent = faNum(p) + '٪';
        }
      };
      xhr.onload = () => {
        progress.style.display = 'none';
        go.disabled = false;
        let j = null;
        try { j = JSON.parse(xhr.responseText); } catch (err) { /* noop */ }
        const box = modal.querySelector('#bulkResult');
        box.classList.remove('hidden');
        if (j?.status === 'success') {
          const d = j.data;
          box.innerHTML = '<div class="alert alert-success">' + esc(j.message) +
            (d.errors && d.errors.length ? '<br><small>' + d.errors.map(esc).join('<br>') + '</small>' : '') + '</div>';
          toast(j.message || 'واردسازی انجام شد');
          if (modal._container) loadEntityRows(modal._container, entityKey);
        } else {
          box.innerHTML = '<div class="alert alert-error">' + esc(j?.message || 'واردسازی ناموفق') + '</div>';
          toast(j?.message || 'واردسازی ناموفق', false);
        }
        refreshIcons();
        file.value = '';
      };
      xhr.onerror = () => {
        progress.style.display = 'none';
        go.disabled = false;
        toast('خطا در آپلود', false);
      };
      xhr.send(fd);
    };

    modal.classList.add('open');
    refreshIcons();
  }

  /* ---------------- workshop sessions (multi-session) ---------------- */

  function openSessionsModal(container, tag = null, title = null) {
    // Get the currently selected workshop from the filter
    if (!tag) { toast('کد کارگاه یافت نشد', false); return; }

    let modal = document.getElementById('sessionsModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.className = 'modal-bg';
      modal.id = 'sessionsModal';
      modal.innerHTML = `
        <div class="modal" style="max-width:560px">
          <div class="modal-head">
            <h3 id="sessionsTitle"></h3>
            <button class="icon-btn" data-close type="button"><i data-lucide="x"></i></button>
          </div>
          <div class="fg full" style="margin-bottom:12px">
            <label>تعداد جلسات</label>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <input type="number" id="wsSessionCount" value="1" min="1" max="20" style="width:90px">
              <button class="btn btn-outline btn-sm" id="wsGenFields" type="button"><i data-lucide="wand-sparkles"></i> تولید فیلدها</button>
            </div>
          </div>
          <div id="wsSessionFields"></div>
          <div class="modal-actions">
            <button class="btn btn-primary" id="wsSessionsSave" type="button"><i data-lucide="save"></i> ذخیره جلسات</button>
            <button class="btn btn-outline" type="button" data-close>انصراف</button>
          </div>
        </div>`;
      document.body.appendChild(modal);
    }

    modal.querySelector('#sessionsTitle').textContent = 'جلسات کارگاه: ' + (title || tag);
    modal.dataset.tag = tag;
    modal.querySelector('#wsSessionFields').innerHTML = '';

    // Generate fields button
    modal.querySelector('#wsGenFields').onclick = () => {
      const count = Math.min(20, Math.max(1, parseInt(modal.querySelector('#wsSessionCount').value) || 1));
      let html = '';
      for (let i = 1; i <= count; i++) {
        html += `
          <div class="ses-row">
            <span class="badge badge-yellow ses-num">جلسه ${faNum(i)}</span>
            <input type="text" class="ses-date" data-num="${i}" placeholder="تاریخ (۱۴۰۴/۰۷/۱۵)" required>
            <input type="text" class="ses-time" data-num="${i}" placeholder="ساعت (۱۸:۰۰)" required>
          </div>`;
      }
      modal.querySelector('#wsSessionFields').innerHTML = html;
    };

    // Save button
    modal.querySelector('#wsSessionsSave').onclick = async () => {
      const body = { sub: 'save', tag: modal.dataset.tag, count: parseInt(modal.querySelector('#wsSessionCount').value) || 1 };
      modal.querySelectorAll('.ses-date').forEach(inp => { body['date_' + inp.dataset.num] = inp.value; });
      modal.querySelectorAll('.ses-time').forEach(inp => { body['time_' + inp.dataset.num] = inp.value; });

      const res = await apiTry('sessions', { method: 'POST', body });
      if (res) {
        toast(res.message || 'ذخیره شد');
        modal.classList.remove('open');
      }
    };

    // Load existing sessions
    apiTry('sessions', { method: 'POST', body: { sub: 'list', tag } }).then(res => {
      if (res?.data?.sessions?.length) {
        const sessions = res.data.sessions;
        modal.querySelector('#wsSessionCount').value = sessions.length;
        modal.querySelector('#wsGenFields').click();
        // Fill existing values
        setTimeout(() => {
          sessions.forEach(s => {
            const dateInp = modal.querySelector(`.ses-date[data-num="${s.session_number}"]`);
            const timeInp = modal.querySelector(`.ses-time[data-num="${s.session_number}"]`);
            if (dateInp) dateInp.value = s.session_date_jalali || '';
            if (timeInp) timeInp.value = (s.session_time || '').substring(0, 5);
          });
        }, 50);
      }
    });

    modal.classList.add('open');
    refreshIcons();
  }

  /* ---------------- modal close handlers ---------------- */

  document.addEventListener('click', e => {
    const closer = e.target.closest('[data-close]');
    if (closer) closer.closest('.modal-bg')?.classList.remove('open');
    if (e.target.classList?.contains('modal-bg')) e.target.classList.remove('open');
  });

  // expose roles map for the users form
  window.ADMIN_ROLES = {
    superadmin: 'مدیر ارشد',
    banners_admin: 'ادمین بنرها و اطلاعیه‌ها',
    classes_admin: 'ادمین لینک کلاس‌ها',
    cert_admin: 'مدیر گواهینامه‌ها',
    members_admin: 'مدیر اعضا',
    associations_admin: 'مدیر روابط عمومی',
    workshops_admin: 'مدیر کارگاه‌ها',
  };

  window.addEventListener('DOMContentLoaded', boot);

})();
