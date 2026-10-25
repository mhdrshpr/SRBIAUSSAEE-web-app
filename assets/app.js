/* ============================================================
   SSAEE Smart Panel v2 — App logic
   ============================================================ */
'use strict';

(() => {

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  let me = null;          // current profile payload
  let bannerTimer = null;
  let bannerIndex = 0;
  let bannerList = [];

  /* ---------------- utils ---------------- */

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

  function fa2en(v) {
    return String(v ?? '').replace(/[۰-۹]/g, d => '0123456789'['۰۱۲۳۴۵۶۷۸۹'.indexOf(d)])
      .replace(/[٠-٩]/g, d => '0123456789'['٠١٢٣٤٥٦٧٨٩'.indexOf(d)]);
  }

  let toastTimer;
  function toast(msg, ok = true) {
    const el = $('#toast');
    el.textContent = msg;
    el.style.borderColor = ok ? '' : 'var(--danger)';
    el.classList.remove('hidden');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.add('hidden'), 4500);
  }

  function formData(form) {
    const out = {};
    new FormData(form).forEach((v, k) => { out[k] = v; });
    return out;
  }

  function busy(btn, on, label) {
    if (on) {
      btn.dataset.label = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = label || 'در حال ارسال…';
    } else {
      btn.disabled = false;
      btn.innerHTML = btn.dataset.label || '';
    }
  }

  function openLink(url) {
    if (window.Telegram?.WebApp?.openLink) Telegram.WebApp.openLink(url);
    else window.open(url, '_blank', 'noopener');
  }

  function refreshIcons() {
    if (window.lucide) lucide.createIcons();
  }

  function splashError(msg, errCode = null) {
    const errBox = $('#splashError');
    const msgEl = $('#splashErrorMsg');
    if (errCode) {
      // user-facing: only the code
      msgEl.innerHTML = 'خطا در بارگذاری پنل<br><b class="err-code-big">کد خطا: ' + faNum(errCode) + '</b>' +
        '<span style="display:block;font-size:.72rem;color:var(--muted);margin-top:6px">این کد را برای پشتیبانی (t.me/saee_admin) ارسال کنید</span>';
    } else {
      msgEl.textContent = msg;
    }
    errBox.classList.remove('hidden');
    $('.splash-spinner')?.classList.add('hidden');
    refreshIcons();
  }

  function splashDone() {
    $('#splash').classList.add('done');
  }

  /* ---------------- API ---------------- */

  const API = {
    token: () => {
      try {
        return localStorage.getItem('ssaee_token') || sessionStorage.getItem('ssaee_token') || '';
      } catch (_) {
        return '';
      }
    },
    setToken(t) {
      try {
        if (t) {
          localStorage.setItem('ssaee_token', t);
          sessionStorage.setItem('ssaee_token', t);
        } else {
          localStorage.removeItem('ssaee_token');
          sessionStorage.removeItem('ssaee_token');
        }
      } catch (_) {}
    },

    initData() {
      if (window.Telegram?.WebApp?.initData) {
        return window.Telegram.WebApp.initData;
      }
      try {
        const hash = (location.hash || '').replace(/^#/, '');
        if (hash.includes('tgWebAppData=')) {
          const params = new URLSearchParams(hash);
          const raw = params.get('tgWebAppData');
          if (raw && (raw.includes('hash=') || raw.includes('user='))) return raw;

          const match = hash.match(/tgWebAppData=([^&]+(?:&(?!(?:tgWebApp[A-Za-z]+)=)[^&]+)*)/);
          if (match && match[1]) {
            return decodeURIComponent(match[1]);
          }
        }
        if ((location.search || '').includes('tgWebAppData=')) {
          const params = new URLSearchParams(location.search);
          const raw = params.get('tgWebAppData');
          if (raw && (raw.includes('hash=') || raw.includes('user='))) return raw;
          const match = location.search.match(/tgWebAppData=([^&]+(?:&(?!(?:tgWebApp[A-Za-z]+)=)[^&]+)*)/);
          if (match && match[1]) {
            return decodeURIComponent(match[1]);
          }
        }
      } catch (e) {}
      return '';
    },

    async call(path, { method = 'GET', body = null, auth = true } = {}) {
      const headers = { Accept: 'application/json' };
      let payload = body;

      if (body && !(body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
      }
      if (auth && this.token()) {
        headers['Authorization'] = 'Bearer ' + this.token();
      }
      // pass initData for telegram-context calls
      if (this.initData() && method === 'POST' && body && typeof body === 'object' && body.initData === undefined) {
        const obj = JSON.parse(payload);
        obj.initData = this.initData();
        payload = JSON.stringify(obj);
      }

      let res;
      try {
        res = await fetch(path, { method, headers, body: method === 'GET' ? undefined : payload });
      } catch (err) {
        const e = new Error('خطا در ارتباط با سرور');
        e.errCode = 1000;
        throw e;
      }

      let data;
      try {
        data = await res.json();
      } catch (err) {
        const e = new Error('پاسخ نامعتبر از سرور');
        e.errCode = 1001;
        throw e;
      }

      if (res.status === 401) {
        if (auth) {
          this.setToken('');
          me = null;
          updateHeaderAuth();
          document.dispatchEvent(new CustomEvent('auth:required'));
        }
        const e = new Error(data.message || 'احراز هویت لازم است');
        e.errCode = data.err || 1010;
        throw e;
      }
      if (!res.ok || data.status === 'error') {
        const e = new Error(data.message || 'خطای غیرمنتظره سرور');
        e.errCode = data.err || null;
        throw e;
      }
      return data;
    },

    // wrapper that surfaces errors as toasts
    async try(path, opts, btn, busyLabel) {
      if (btn) busy(btn, true, busyLabel);
      try {
        return await this.call(path, opts);
      } catch (err) {
        toast(err.message, false);
        return null;
      } finally {
        if (btn) busy(btn, false);
      }
    }
  };

  const API_BASE = '/?api=';
  function endpoint(name, qs = '') {
    return API_BASE + name + (qs ? '&' + qs : '');
  }

  /* ---------------- theme ---------------- */

  function syncLogos() {
    const light = document.body.classList.contains('light');
    $$('img[data-logo-dark]').forEach(img => {
      img.src = light ? (img.dataset.logoLight || img.dataset.logoDark) : img.dataset.logoDark;
    });
  }

  function initTheme() {
    if (localStorage.getItem('ssaee_theme') === 'light') document.body.classList.add('light');
    syncThemeIcon();
    syncLogos();
    $('#themeToggle')?.addEventListener('click', () => {
      document.body.classList.toggle('light');
      localStorage.setItem('ssaee_theme', document.body.classList.contains('light') ? 'light' : 'dark');
      syncThemeIcon();
      syncLogos();
    });
  }
  function syncThemeIcon() {
    const btn = $('#themeToggle');
    const light = document.body.classList.contains('light');
    btn.innerHTML = light ? '<i data-lucide="sun"></i>' : '<i data-lucide="moon"></i>';
    refreshIcons();
  }

  /* ---------------- navigation ---------------- */

  function goto(view) {
    $$('.view').forEach(v => v.classList.remove('active'));
    $('#view-' + view)?.classList.add('active');
    $$('.tab').forEach(t => t.classList.toggle('active', t.dataset.goto === view));
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (view === 'profile') loadProfile();
    if (view === 'myworkshops') loadMyWorkshopsFull();
    if (view === 'classes') loadClasses();
    if (view === 'members') loadMemberStatus();
  }

  function initNav() {
    // header auth controls
    $('#authBtn')?.addEventListener('click', () => {
      if (me) goto('profile');
      else openAuth();
    });
    $('#logoutBtn')?.addEventListener('click', doLogout);
    $('#authClose')?.addEventListener('click', closeAuth);
    $('#userBadge')?.addEventListener('click', () => { if (!me) openAuth(); });

    document.addEventListener('click', e => {
      const openAuthEl = e.target.closest('[data-open-auth]');
      if (openAuthEl) { openAuth(); return; }

      const go = e.target.closest('[data-goto]');
      if (go) { goto(go.dataset.goto); return; }

      const openM = e.target.closest('[data-modal]');
      if (openM) {
        openModal(openM.dataset.modal);
        return;
      }

      const accBtn = e.target.closest('[data-acc]');
      if (accBtn) {
        const body = document.getElementById(accBtn.dataset.acc);
        if (body) {
          const nowOpen = body.classList.toggle('open');
          accBtn.querySelector('.acc-chev')?.classList.toggle('open', nowOpen);
        }
        return;
      }

      const tabBtn = e.target.closest('[data-tab], [data-authtab]');
      if (tabBtn) {
        const wrap = tabBtn.closest('.collab-tabs, .auth-tabs');
        $$('.tab-btn', wrap).forEach(b => b.classList.remove('active'));
        tabBtn.classList.add('active');
        if (wrap.classList.contains('auth-tabs')) {
          const tab = tabBtn.dataset.authtab;
          $('#loginForm').classList.toggle('hidden', tab !== 'login');
          $('#registerForm').classList.toggle('hidden', tab !== 'register');
        } else {
          $$('.tab-pane').forEach(p => p.classList.remove('active'));
          $('#' + tabBtn.dataset.tab)?.classList.add('active');
        }
        return;
      }

      const closer = e.target.closest('[data-close]');
      if (closer) {
        const bg = closer.closest('.modal-bg');
        bg?.classList.remove('open');
        if (bg?.id === 'modalBoard') clearInterval(boardTimer);
        return;
      }

      if (e.target.classList?.contains('modal-bg')) {
        e.target.classList.remove('open');
        if (e.target.id === 'modalBoard') clearInterval(boardTimer);
        return;
      }
    });

    $$('.modal-bg').forEach(m => {
      m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });
  }

  function openModal(id) {
    const m = $('#' + id);
    if (!m) return;
    m.classList.add('open');
    if (id === 'modalActivities') loadActivities();
    if (id === 'modalMemberStats') loadMemberStats();
    if (id === 'modalBoard') loadBoard();
    refreshIcons();
  }

  /* ---------------- auth (optional — guest browsing allowed) ---------------- */

  function showApp() {
    $('#appView').classList.remove('hidden');
  }

  function openAuth() {
    $('#authView').classList.remove('hidden');
    refreshIcons();
  }

  function closeAuth() {
    $('#authView').classList.add('hidden');
  }

  function showAuth() { openAuth(); }

  function updateHeaderAuth() {
    const authBtn = $('#authBtn');
    const logoutBtn = $('#logoutBtn');
    if (me) {
      authBtn.innerHTML = '<i data-lucide="user"></i>';
      authBtn.title = 'پروفایل من';
      logoutBtn.classList.remove('hidden');
    } else {
      authBtn.innerHTML = '<i data-lucide="log-in"></i>';
      authBtn.title = 'ورود / ثبت‌نام';
      logoutBtn.classList.add('hidden');
    }
    // compact home chip (name on its own line, statuses under it)
    const badge = $('#userBadge');
    if (me && me.full_name) {
      const profPill = me.is_profile_complete
        ? '<span class="chip-pill ok"><i data-lucide="badge-check"></i> پروفایل کامل</span>'
        : '<span class="chip-pill warn"><i data-lucide="user-pen"></i> پروفایل ناقص</span>';
      badge.innerHTML = '<div class="chip-name"><i data-lucide="user"></i> ' + esc(me.full_name) + '</div>' +
        '<div class="chip-status">' + profPill +
        '<span class="chip-pill member" id="chipMember"><i data-lucide="id-card"></i> <span>در حال بررسی عضویت…</span></span></div>';
      badge.classList.remove('hidden');
      badge.setAttribute('role', 'button');
      badge.tabIndex = 0;
    } else {
      badge.innerHTML = '<div class="chip-name"><i data-lucide="log-in"></i> ورود / ساخت حساب</div>' +
        '<div class="chip-status"><span class="chip-pill muted">برای گواهینامه‌ها و امتیازها وارد شوید</span></div>';
      badge.classList.remove('hidden');
    }
    refreshIcons();
  }

  async function doLogout() {
    await API.try(endpoint('logout'), { method: 'POST', body: {} });
    API.setToken('');
    me = null;
    myWsCache = null;
    sessionStorage.removeItem('ssaee_national');
    updateHeaderAuth();
    $('#tgConnectCard').classList.add('hidden');
    $('#privacyCard')?.classList.add('hidden');
    toast('از حساب خود خارج شدید');
    goto('home');
  }

  function isTelegramContext() {
    if (window.__TG_OK === false) return false;
    try {
      return !!(API.initData() || window.Telegram?.WebApp);
    } catch (err) {
      return false;
    }
  }

  function applyProfile(profile) {
    me = profile;
    sessionStorage.setItem('ssaee_national', profile.national_id || '');
    updateHeaderAuth();
    // privacy card: only for logged-in users with a profile
    $('#privacyCard')?.classList.toggle('hidden', !(profile && profile.full_name));
    // prefill forms with known data (2.2)
    $$('[data-prefill]').forEach(form => {
      $$('[data-pf]', form).forEach(inp => {
        const key = inp.dataset.pf;
        if (profile[key]) { inp.value = inp.dataset.name === 'fa' ? faNameOnly(profile[key]) : profile[key]; }
      });
    });
    // telegram connect card (2.7)
    $('#tgConnectCard').classList.toggle('hidden', !!profile.telegram_connected || isTelegramContext());
    refreshIcons();
  }

  async function bootAuth() {
    const existingToken = API.token();

    // Telegram Mini App auto-login via Telegram numeric ID / initData
    const tgInit = API.initData();
    if (tgInit) {
      try {
        const res = await API.call(endpoint('telegram_login'), {
          method: 'POST',
          body: { initData: tgInit },
          auth: false
        });
        if (res?.data?.token) {
          API.setToken(res.data.token);
          applyProfile(res.data.profile);
          return;
        }
      } catch (err) {
        console.warn('[tg auto-login]', err);
      }
    }

    // existing web session?
    if (existingToken) {
      try {
        const res = await API.call(endpoint('me'));
        applyProfile(res.data.profile);
        return;
      } catch (err) { /* guest mode */ }
    }

    // Guest mode — browse freely, no forced login
    me = null;
    updateHeaderAuth();
  }

  function initAuthForms() {
    $('#loginForm')?.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = $('button[type=submit]', e.target);
      const res = await API.try(endpoint('login'), { method: 'POST', body: formData(e.target), auth: false }, btn, 'ورود…');
      if (res?.data?.token) {
        API.setToken(res.data.token);
        toast(res.message || 'خوش آمدید');
        location.reload();
      }
    });

    $('#registerForm')?.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = $('button[type=submit]', e.target);
      const res = await API.try(endpoint('register'), { method: 'POST', body: formData(e.target), auth: false }, btn, 'ثبت‌نام…');
      if (res?.data?.token) {
        API.setToken(res.data.token);
        toast('حساب شما ساخته شد. خوش آمدید! 🎉');
        location.reload();
      }
    });

    $('#forgotBtn')?.addEventListener('click', () => openModal('modalForgot'));

    // Quick login via Telegram bot — handshake + 20s countdown + auto reload
    $('#tgQuickLogin')?.addEventListener('click', async function() {
      const btn = this;
      if (btn.dataset.wl) return;
      btn.dataset.wl = '1';
      const res = await API.try(endpoint('weblogin_start'), { method: 'POST', body: {} }, btn, 'در حال اتصال…');
      delete btn.dataset.wl;
      if (!res?.data?.url) return;
      openLink(res.data.url);
      startTgWebLoginWait(res.data.code);
    });
    $('#forgotForm')?.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = $('button[type=submit]', e.target);
      const res = await API.try(endpoint('reset_request'), { method: 'POST', body: formData(e.target), auth: false }, btn);
      if (res) toast(res.message || 'اگر حساب متصل به تلگرام باشد، لینک ارسال می‌شود');
      $('#forgotForm').classList.add('hidden');
    });
  }

  /* ---------------- web quick-login wait (20s countdown handshake) ---------------- */

  let tgWlIv = null;
  function stopTgWl() {
    if (tgWlIv) { clearInterval(tgWlIv); tgWlIv = null; }
  }

  function startTgWebLoginWait(code) {
    stopTgWl();
    openModal('modalTgWait');
    const countEl = $('#tgWlCount');
    const msgEl = $('#tgWlMsg');
    const cancelBtn = $('#tgWlCancel');
    let left = 20;
    let finished = false;

    const render = (msg, failed) => {
      if (countEl) countEl.textContent = left > 0 ? String(left) : (failed ? '!' : '0');
      if (msgEl) {
        msgEl.textContent = msg;
        msgEl.classList.toggle('err', !!failed);
      }
    };
    const setCancel = label => {
      if (!cancelBtn) return;
      cancelBtn.innerHTML = '<i data-lucide="x"></i> ' + label;
      refreshIcons();
    };
    render('در انتظار پاسخ از تلگرام…', false);
    setCancel(API.token() ? 'لغو و خروج از حساب' : 'لغو');

    if (cancelBtn) {
      cancelBtn.onclick = () => {
        finished = true;
        stopTgWl();
        $('#modalTgWait')?.classList.remove('open');
        // لغو = اگر لاگین بود، خروج از حساب؛ در غیر این صورت فقط بستن
        if (API.token()) {
          API.setToken('');
          toast('لغو شد — از حساب خارج شدید');
          setTimeout(() => location.reload(), 350);
        } else {
          toast('لغو شد');
        }
      };
    }

    tgWlIv = setInterval(async () => {
      if (finished) return;
      left--;
      if (left <= 0) {
        finished = true;
        stopTgWl();
        render('پاسخی از تلگرام دریافت نشد. دوباره تلاش کنید.', true);
        setCancel('بستن');
        return;
      }
      render('در انتظار پاسخ از تلگرام…', false);
      try {
        const p = await API.call(endpoint('weblogin_poll', 'code=' + encodeURIComponent(code)), { auth: false });
        if (p?.data?.token) {
          finished = true;
          stopTgWl();
          API.setToken(p.data.token);
          $('#modalTgWait')?.classList.remove('open');
          toast('با موفقیت وارد شدید 🎉');
          await bootAuth();
          await loadProfile();
        } else if (p?.data?.expired) {
          finished = true;
          stopTgWl();
          render('کد ورود منقضی شد. دوباره تلاش کنید.', true);
          setCancel('بستن');
        }
      } catch (e) { /* شبکه/خطا — تا پایان شمارنده ادامه بده */ }
    }, 1000);
  }

  /* ---------------- profile ---------------- */

  async function loadProfile() {
    const box = document.getElementById('profileBox');
    if (!box) return;

    if (!me) {
      box.innerHTML =
        '<div class="login-prompt"><i data-lucide="lock"></i>' +
        '<p>برای مشاهده پروفایل، گواهینامه‌ها و امتیازها وارد حساب شوید</p>' +
        '<button class="btn btn-primary" data-open-auth type="button">ورود / ثبت‌نام</button></div>';
      $('#myWorkshops').innerHTML = '<p class="muted">—</p>';
      $('#myCertsMini').innerHTML = '<p class="muted">—</p>';
      $('#tgConnectCard').classList.add('hidden');
      refreshIcons();
      return;
    }

    const res = await API.try(endpoint('me'));
    if (!res) {
      box.innerHTML = '<p class="muted">خطا در بارگذاری پروفایل.</p>';
      return;
    }

    const p = res.data.profile;
    const m = res.data.member;

    let html = '<div class="profile-head"><div class="profile-id"><i data-lucide="user-circle-2"></i>' +
      '<div><b>' + esc(p.full_name || 'کاربر') + '</b><span class="muted">' + esc(p.phone || '—') + '</span></div></div>' +
      '<button class="profile-edit-btn" data-modal="modalProfileEdit" type="button" title="ویرایش اطلاعات"><i data-lucide="pencil"></i><span>ویرایش</span></button></div>';

    const row = (k, v) => '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px dashed var(--border)">' +
      '<span class="muted">' + k + '</span><b>' + v + '</b></div>';

    html += row('موبایل', esc(p.phone || '—'));
    html += row('کد ملی', esc(p.national_id || '—'));
    html += row('شماره دانشجویی', esc(p.student_id || '—'));

    if (m) {
      html += row('نوع عضویت', m.Active ? '<span class="pill ok">فعال (دارای کارت)</span>' : '<span class="pill">عادی</span>');
      html += row('امتیاز', faNum(m.Points));
    } else {
      html += '<div style="margin-top:10px"><span class="pill warn">هنوز عضو انجمن نشده‌اید</span></div>';
    }

    // sync the home chip membership dot
    const chipM = document.getElementById('chipMember');
    if (chipM) {
      chipM.className = 'chip-pill ' + (m ? 'ok' : 'warn');
      chipM.innerHTML = '<i data-lucide="id-card"></i> <span>' + (m ? (m.Active ? 'عضو فعال' : 'عضو انجمن') : 'عضو نیستید') + '</span>';
    }

    box.innerHTML = html;
    refreshIcons();

    // fill edit form
    const f = $('#profileEditForm');
    f.first_name.value = faNameOnly(p.first_name || '');
    f.last_name.value = faNameOnly(p.last_name || '');
    f.national_id.value = p.national_id || '';
    f.student_id.value = p.student_id || '';
    f.phone.value = p.phone || '';

    // my workshops + certs — mini lists: last 5 only + «مشاهده بیشتر»
    const ws = await API.try(endpoint('my_registrations'));
    const wsBox = $('#myWorkshops');
    myWsCache = ws?.data?.registrations || [];
    if (myWsCache.length) {
      const items = myWsCache.slice(0, 5).map(r => {
        const pill = r.status === 'CONFIRMED'
          ? '<span class="pill ok">تأیید شده</span>'
          : (r.status === 'REJECTED' || r.status === 'CANCELLED')
            ? '<span class="pill err">رد شده</span>'
            : '<span class="pill warn">در انتظار</span>';
        const linkBtn = r.group_link
          ? ' <a class="pill ws-link" href="' + esc(r.group_link) + '" target="_blank" rel="noopener"><i data-lucide="send"></i> گروه</a>'
          : '';
        return '<div class="item"><span>' + esc(r.title) + '</span><span>' + pill + linkBtn + '</span></div>';
      }).join('');
      wsBox.innerHTML = items +
        '<button class="btn btn-outline btn-sm" data-goto="myworkshops" type="button" style="margin-top:8px;width:100%">' +
        '<i data-lucide="list"></i> مشاهده بیشتر (' + faNum(myWsCache.length) + ' مورد)</button>';
      refreshIcons();
    } else {
      wsBox.innerHTML = '<p class="muted">ثبت‌نامی وجود ندارد</p>';
    }

    const certs = await API.try(endpoint('my_certificates'));
    const cBox = $('#myCertsMini');
    if (certs?.data?.certificates?.length) {
      const all = certs.data.certificates;
      const items = all.slice(0, 5).map(c =>
        '<div class="item"><span class="cert-mini"><i data-lucide="award"></i> ' + esc(c.course) + '</span>' +
        '<span class="pill ' + (c.is_valid ? 'ok' : 'err') + '">' + esc(c.exp_jalali) + '</span></div>'
      ).join('');
      cBox.innerHTML = items +
        '<button class="btn btn-outline btn-sm" data-goto="certificates" type="button" style="margin-top:8px;width:100%">' +
        '<i data-lucide="list"></i> مشاهده بیشتر (' + faNum(all.length) + ' گواهی)</button>';
      refreshIcons();
    } else {
      cBox.innerHTML = '<p class="muted">گواهینامه‌ای ثبت نشده است</p>';
    }
  }

  /* full «کارگاه‌های من» page */
  let myWsCache = null;
  async function loadMyWorkshopsFull() {
    const box = $('#myWorkshopsFull');
    if (!box) return;
    if (!myWsCache) myWsCache = (await API.try(endpoint('my_registrations')))?.data?.registrations || [];
    if (!myWsCache.length) { box.innerHTML = '<p class="muted">ثبت‌نامی وجود ندارد</p>'; return; }
    box.innerHTML = myWsCache.map(r => {
      const pill = r.status === 'CONFIRMED'
        ? '<span class="pill ok">تأیید شده</span>'
        : (r.status === 'REJECTED' || r.status === 'CANCELLED')
          ? '<span class="pill err">رد شده</span>'
          : '<span class="pill warn">در انتظار</span>';
      const linkBtn = r.group_link
        ? ' <a class="pill ws-link" href="' + esc(r.group_link) + '" target="_blank" rel="noopener"><i data-lucide="send"></i> گروه</a>'
        : '';
      return '<div class="item"><span>' + esc(r.title) + ' <small class="muted">' + esc(r.date_jalali || '') + '</small></span><span>' + pill + linkBtn + '</span></div>';
    }).join('');
    refreshIcons();
  }

  function initProfileEdit() {
    $('#profileEditForm')?.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = $('button[type=submit]', e.target);
      const res = await API.try(endpoint('profile_update'), { method: 'POST', body: formData(e.target) }, btn);
      if (res) {
        toast(res.message || 'ذخیره شد');
        $('.modal-bg#modalProfileEdit').classList.remove('open');
        loadProfile();
      }
    });
  }

  /* ---------------- Persian-name inputs + sponsor form ---------------- */

  // letters of the Arabic/Persian blocks only (پ چ ژ گ ک ی آزاد); strips
  // Latin/other scripts + stray symbols; keeps space + ZWNJ/ZWJ
  function faNameOnly(v) {
    return String(v || '')
      .replace(/[\u0600-\u06FF\uFB50-\uFDFF\uFE70-\uFEFF \u200C\u200D]/g, m => m)
      .replace(/[^\u0600-\u06FF\uFB50-\uFDFF\uFE70-\uFEFF \u200C\u200D]/g, '')
      .replace(/\s{2,}/g, ' ');
  }

  function initFaNames() {
    $$('input[data-name="fa"]').forEach(inp => {
      inp.addEventListener('input', () => {
        const clean = faNameOnly(inp.value);
        if (clean !== inp.value) inp.value = clean;
      });
      inp.addEventListener('paste', () => setTimeout(() => { inp.value = faNameOnly(inp.value); }, 0));
    });
  }

  function initSponsor() {
    const form = $('#sponsorForm');
    if (!form) return;
    const hiddenType = $('#spTypeValue');
    const moneyWrap = $('#spMoneyWrap');
    const goodsWrap = $('#spGoodsWrap');
    const serviceWrap = $('#spServiceWrap');
    const otherHint = $('#spOtherHint');
    const money = $('#spMoney');
    const chips = $$('#spTypeGrid .sp-type-chip');

    const fmt = () => {
      let d = fa2en(money.value).replace(/[^\d]/g, '');
      money.value = d ? Number(d).toLocaleString('en-US').replace(/,/g, ',') : '';
    };
    money.addEventListener('input', fmt);
    money.addEventListener('blur', fmt);

    chips.forEach(ch => ch.addEventListener('click', () => {
      chips.forEach(x => x.classList.remove('is-active'));
      ch.classList.add('is-active');
      const t = ch.dataset.spType;
      hiddenType.value = t;
      moneyWrap.classList.toggle('hidden', !(t === 'مالی' || t === 'محصول'));
      money.closest('label').firstChild.textContent = t === 'محصول' ? 'ارزش تقریبی محصول (تومان)' : 'مبلغ (تومان)';
      goodsWrap.classList.toggle('hidden', t !== 'محصول');
      serviceWrap.classList.toggle('hidden', t !== 'خدمات');
      otherHint.classList.toggle('hidden', t !== 'سایر');
      if (t === 'سایر') money.value = '';
    }));

    // submit: keep the form generic
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = $('button[type=submit]', form);
      const body = formData(form);
      if (body.Type === 'مالی' || body.Type === 'محصول') {
        body.Money = fa2en(body.Money || '').replace(/[^\d]/g, '');
      } else {
        body.Money = '0';
      }
      const res = await API.try(endpoint('collab'), { method: 'POST', body }, btn);
      if (res) { toast(res.message || 'ثبت شد'); form.reset(); }
    });
  }

  /* ---------------- account deletion (privacy) ---------------- */

  function initAccountDelete() {
    $('#accountDeleteBtn')?.addEventListener('click', () => openModal('modalAcctDelete'));

    $('#acctDelSendCode')?.addEventListener('click', async e => {
      const btn = e.currentTarget;
      btn.disabled = true;
      const res = await API.try(endpoint('account_delete_request'), { method: 'POST', body: {} }, btn);
      btn.disabled = false;
      if (!res) return;
      toast(res.message || 'کد به تلگرام شما ارسال شد');
      $('#modalAcctDelete').classList.remove('open');
      $('#modalAcctDeleteCode').classList.add('open');
      $('#acctDelCodeForm').code.value = '';
      $('#acctDelCodeForm').code.focus();
    });

    $('#acctDelCodeForm')?.addEventListener('submit', async e => {
      e.preventDefault();
      const code = e.target.code.value.trim();
      if (!/^\d{6}$/.test(code)) { toast('کد ۶ رقمی را وارد کنید', false); return; }
      const btn = $('button[type=submit]', e.target);
      const res = await API.try(endpoint('account_delete_confirm'), {
        method: 'POST',
        body: { code }
      }, btn);
      if (!res) return;
      $('#modalAcctDeleteCode').classList.remove('open');
      // session is gone server-side — clear local state and reload to guest view
      API.setToken('');
      me = null;
      sessionStorage.removeItem('ssaee_national');
      toast(res.message || 'حساب شما حذف شد');
      setTimeout(() => location.reload(), 1200);
    });
  }

  /* ---------------- help guide (dynamic content from panel) ---------------- */

  async function loadHelpModal() {
    const box = $('#helpList');
    if (!box || box.dataset.loaded) return;
    box.dataset.loaded = '1';
    box.innerHTML = '<div class="loading">در حال بارگذاری…</div>';

    const res = await API.try(endpoint('help'), { auth: false });
    const items = res?.data?.items || [];
    if (!items.length) {
      box.innerHTML = '<p class="muted" style="padding:8px 4px">راهنمایی ثبت نشده است.</p>';
      return;
    }
    box.innerHTML = items.map(it =>
      '<details class="help-item">' +
        '<summary><i data-lucide="' + esc(it.icon || 'info') + '"></i> ' + esc(it.title) + '</summary>' +
        '<div class="help-body"><p>' + esc(it.body) + '</p></div>' +
      '</details>'
    ).join('');
    refreshIcons();
  }

  /* ---------------- help guide (static fallback — texts filled by admin) ---------------- */

  /* ---------------- workshop registration success + auto-join countdown ---------------- */

  let wsJoinTimer = null;

  function openExternalLink(link) {
    const tgApp = window.Telegram?.WebApp;
    if (/t\.me\//.test(link) && tgApp?.openTelegramLink) {
      tgApp.openTelegramLink(link);
      return;
    }
    if (tgApp?.openLink) {
      tgApp.openLink(link);
      return;
    }
    // plain browser: navigate directly (window.open from a timer would be popup-blocked)
    location.href = link;
  }

  function showWsJoinedModal(title, regId, groupLink) {
    $('#wsJoinedTitle').textContent = title || '';
    $('#wsJoinedRegId').textContent = regId || '';
    const linkWrap = $('#wsJoinedLinkWrap');
    const noLink = $('#wsJoinedNoLink');

    if (groupLink) {
      $('#wsJoinedLink').href = groupLink;
      linkWrap?.classList.remove('hidden');
      noLink?.classList.add('hidden');
    } else {
      linkWrap?.classList.add('hidden');
      noLink?.classList.remove('hidden');
    }
    openModal('modalWsJoined');
  }

  function initWsJoinedClose() {
    // no auto-redirect anymore; nothing extra to clean up
  }

  /* ---------------- banners slideshow (2.4) ---------------- */

  async function loadBanners() {
    const res = await API.try(endpoint('banners'), { auth: false });
    if (!res?.data?.banners?.length) return;
    bannerList = res.data.banners;

    const box = $('#bannersBox');
    const dots = $('#bannersDots');
    box.innerHTML = '';
    dots.innerHTML = '';
    box.classList.remove('hidden');
    dots.classList.remove('hidden');

    bannerList.forEach((b, i) => {
      const slide = document.createElement('a');
      slide.className = 'banner-slide' + (i === 0 ? ' active' : '');
      slide.href = '#';

      if (b.image) {
        const img = document.createElement('img');
        img.src = b.image;
        img.alt = b.title;
        img.loading = 'lazy';
        img.addEventListener('error', () => {
          img.remove();
          slide.prepend(makeFallback(b));
        });
        slide.appendChild(img);
      } else {
        slide.appendChild(makeFallback(b));
      }

      const bt = document.createElement('div');
      bt.className = 'bt';
      bt.innerHTML = '<i data-lucide="graduation-cap"></i> ' + esc(b.title);
      slide.appendChild(bt);

      // closed-registration ribbon on the banner itself
      if (b.reg_open === false) {
        const ribbon = document.createElement('div');
        ribbon.className = 'banner-closed-ribbon';
        ribbon.innerHTML = '<i data-lucide="lock"></i> ثبت‌نام بسته است';
        slide.appendChild(ribbon);
      }

      slide.addEventListener('click', async ev => {
        ev.preventDefault();
        if (b.id) API.call(endpoint('banner_click', 'id=' + encodeURIComponent(b.id)), { method: 'POST', body: {}, auth: false }).catch(() => {});

        // closed registration → warning instead of the form (still allows external links)
        if (b.reg_open === false) {
          if (b.link) { openLink(b.link); return; }
          toast('⛔ ثبت‌نام این کارگاه بسته است' +
            (b.ws_start_jalali && b.ws_start_jalali !== '—' ? ' (شروع کارگاه: ' + b.ws_start_jalali + ')' : '') +
            (b.end_jalali && b.end_jalali !== '—' ? ' — مهلت: ' + b.end_jalali : ''), false);
          return;
        }

        if (b.link) { openLink(b.link); return; }
        // default: open workshop registration modal
        $('#wsModalTitle').textContent = 'ثبت‌نام در: ' + (b.title || 'کارگاه');
        $('#workshopRegForm').WorkshopTag.value = b.tag || '';
        $('#wsRegResult').classList.add('hidden');
        openModal('modalWorkshop');
      });

      const dot = document.createElement('span');
      if (i === 0) dot.classList.add('on');
      dot.addEventListener('click', () => showBanner(i));
      dots.appendChild(dot);

      box.appendChild(slide);
    });

    refreshIcons();

    if (bannerList.length > 1) {
      clearInterval(bannerTimer);
      startBannerCountdown(10);
    }

    // swipe navigation (touch + mouse drag)
    const holder = $('#bannersBox');
    if (holder && !holder.dataset.swipeWired) {
      holder.dataset.swipeWired = '1';
      let startX = null;
      holder.addEventListener('touchstart', e => { startX = e.touches[0].clientX; }, { passive: true });
      holder.addEventListener('touchend', e => {
        if (startX === null) return;
        const dx = e.changedTouches[0].clientX - startX;
        startX = null;
        if (Math.abs(dx) > 40) bannerSwipe(dx < 0 ? 1 : -1);
      }, { passive: true });
      let mouseDown = null;
      holder.addEventListener('mousedown', e => { mouseDown = e.clientX; });
      holder.addEventListener('mouseup', e => {
        if (mouseDown === null) return;
        const dx = e.clientX - mouseDown;
        mouseDown = null;
        if (Math.abs(dx) > 40) bannerSwipe(dx < 0 ? 1 : -1);
      });
    }
  }

  const BANNER_INTERVAL = 10; // seconds per slide

  function startBannerCountdown(seconds) {
    clearInterval(bannerTimer);
    let left = seconds;
    renderBannerRing(left, seconds);
    bannerTimer = setInterval(() => {
      left--;
      if (left <= 0) {
        bannerTimer && clearInterval(bannerTimer);
        bannerTimer = null;
        showBanner((bannerIndex + 1) % bannerList.length);
        startBannerCountdown(BANNER_INTERVAL);
        return;
      }
      renderBannerRing(left, seconds);
    }, 1000);
  }

  function bannerSwipe(dir) {
    if (bannerList.length < 2) return;
    showBanner((bannerIndex + dir + bannerList.length) % bannerList.length);
    startBannerCountdown(BANNER_INTERVAL); // restart the 10s timer on manual swipe
  }

  function renderBannerRing(left, total) {
    // small circular countdown over the active slide
    const active = $('#bannersBox .banner-slide.active');
    if (!active) return;
    let ring = active.querySelector('.banner-timer');
    if (!ring) {
      ring = document.createElement('div');
      ring.className = 'banner-timer';
      ring.innerHTML = '<svg viewBox="0 0 36 36"><circle class="ring-bg" cx="18" cy="18" r="16"></circle><circle class="ring-fg" cx="18" cy="18" r="16"></circle></svg><b></b>';
      active.appendChild(ring);
    }
    const pct = left / total;
    const fg = ring.querySelector('.ring-fg');
    fg.style.strokeDashoffset = String(100 * (1 - pct));
    ring.querySelector('b').textContent = faNum(left);
  }

  function makeFallback(b) {
    const div = document.createElement('div');
    div.className = 'banner-fallback';
    div.innerHTML = '<i data-lucide="rocket"></i><b>' + esc(b.title) + '</b>' +
      (b.start_jalali && b.start_jalali !== '—' ? '<span style="font-size:.75rem">ثبت‌نام: ' + esc(b.start_jalali) + ' تا ' + esc(b.end_jalali) + '</span>' : '');
    return div;
  }

  function showBanner(i) {
    bannerIndex = i;
    $$('#bannersBox .banner-slide').forEach((s, idx) => s.classList.toggle('active', idx === i));
    $$('#bannersDots span').forEach((d, idx) => d.classList.toggle('on', idx === i));
  }

  /* ---------------- announcements ---------------- */

  async function loadAnnouncements() {
    const res = await API.try(endpoint('announcements'), { auth: false });
    if (!res?.data?.announcements?.length) return;
    const a = res.data.announcements[0];
    const bar = $('#annBar');
    bar.textContent = '📢 ' + a.title + ' — ' + a.message;
    bar.classList.remove('hidden');
  }

  /* ---------------- activities & stats modals (3) ---------------- */

  async function loadActivities() {
    const res = await API.try(endpoint('activities'), { auth: false });
    const list = $('#activitiesList');
    if (!res) {
      list.innerHTML = '<div class="loading">خطا در بارگذاری فعالیت‌ها</div>';
      return;
    }

    const stats = await API.try(endpoint('stats'), { auth: false });
    const s = stats?.data;
    $('#activitiesStats').innerHTML = s ? (
      statBox('smile', 'رضایت از فعالیت‌ها', faNum(s.satisfaction) + '٪') +
      statBox('clipboard-list', 'کل ثبت‌نامی‌ها', faNum(s.total_registrations)) +
      statBox('flask-conical', 'امتیاز باشگاه پژوهشگران', faNum(s.club_score))
    ) : '';
    refreshIcons();

    const acts = res.data.activities;
    if (!acts.length) {
      list.innerHTML = '<div class="loading">فعالیتی ثبت نشده است</div>';
      return;
    }
    list.innerHTML = acts.map(a =>
      '<div class="act-item">' +
      (a.image ? '<img src="' + esc(a.image) + '" alt="' + esc(a.title) + '" loading="lazy" onerror="this.remove()">' : '') +
      '<div class="body"><h4>' + esc(a.title) + '</h4>' +
      '<span class="date"><i data-lucide="calendar"></i> ' + esc(a.date_jalali) + '</span>' +
      (a.description ? '<p>' + esc(a.description) + '</p>' : '') +
      '</div></div>'
    ).join('');
    refreshIcons();
  }

  function statBox(icon, label, value) {
    return '<div class="stat-box"><i data-lucide="' + icon + '"></i>' +
      '<div class="v">' + value + '</div><div class="k">' + esc(label) + '</div></div>';
  }

  async function loadMemberStats() {
    const res = await API.try(endpoint('stats'), { auth: false });
    const box = $('#memberStatsBox');
    if (!res?.data) {
      box.innerHTML = '<div class="loading">خطا در بارگذاری آمار</div>';
      return;
    }
    const m = res.data.members;
    box.innerHTML =
      statBox('users', 'تعداد اعضا', faNum(m.count)) +
      statBox('trending-up', 'درصد مشارکت اعضا', faNum(m.participation) + '٪') +
      statBox('star', 'جمع امتیازات صادر شده', faNum(m.total_points));
    refreshIcons();
  }

  /* ---------------- members ---------------- */

  function initMemberForms() {
    $('#memberReqForm')?.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = $('button[type=submit]', e.target);
      const res = await API.try(endpoint('member_request'), { method: 'POST', body: formData(e.target), auth: false }, btn);
      if (res) {
        toast(res.message || 'درخواست ثبت شد');
        e.target.reset();
        loadMemberStatus();
      }
    });
  }

  /* Membership status: red box for non-members (form), green + auto points for members */
  async function loadMemberStatus() {
    const box = $('#memberStatus');
    const joinCard = $('#joinCard');
    if (!box || !joinCard) return;
    refreshIcons();

    if (!me) {
      box.className = 'status-box neutral';
      box.innerHTML =
        '<i data-lucide="info"></i><span>برای بررسی وضعیت عضویت و امتیاز، وارد حساب خود شوید</span>' +
        '<button class="btn btn-outline btn-sm" data-open-auth type="button">ورود / ثبت‌نام</button>';
      joinCard.classList.remove('hidden');
      refreshIcons();
      return;
    }

    const res = await API.try(endpoint('me'));
    if (!res?.data) {
      box.className = 'status-box neutral';
      box.innerHTML = '<i data-lucide="alert-triangle"></i><span>خطا در بررسی وضعیت عضویت</span>';
      return;
    }

    const m = res.data.member;
    const profileComplete = !!res.data.profile.is_profile_complete;

    if (m) {
      const expired = !!(m.ExpDate && new Date(String(m.ExpDate).slice(0, 10) + 'T23:59:59') < new Date());
      box.className = 'status-box member-card';
      box.innerHTML =
        '<div class="mc-row1">' +
          '<span class="mc-title"><i data-lucide="badge-check"></i> شما عضو انجمن هستید</span>' +
          '<span class="mc-badges">' +
            '<span class="pill ' + (m.Active ? 'ok' : 'warn') + '">' + (m.Active ? 'فعال (دارای کارت)' : 'عادی') + '</span>' +
            (m.ExpDate ? '<span class="mc-exp">' + (expired ? 'منقضی: ' : 'اعتبار تا: ') + esc(m.ExpDate) + '</span>' : '<span class="mc-exp">اعتبار: دائمی</span>') +
          '</span>' +
        '</div>' +
        '<div class="mc-row2">' +
          '<span class="pts-wrap"><i data-lucide="star"></i> امتیاز: <b id="memberPts">' + faNum(m.Points) + '</b>' +
          '<button class="icon-btn pts-refresh" type="button" title="به‌روزرسانی امتیاز"><i data-lucide="refresh-cw"></i></button></span>' +
          '<span class="mc-actions">' +
            (expired ? '<button class="btn btn-outline btn-sm" id="renewBtn" type="button"><i data-lucide="calendar-clock"></i> درخواست تمدید عضویت</button>' : '') +
            (m.Active ? '<button class="btn btn-primary btn-sm" id="dlCardBtn" type="button"><i data-lucide="id-card"></i> دانلود کارت عضویت</button>' : '') +
          '</span>' +
        '</div>';
      joinCard.classList.add('hidden');

      box.querySelector('.pts-refresh')?.addEventListener('click', async () => {
        const r2 = await API.try(endpoint('me'));
        const m2 = r2?.data?.member;
        const pts = $('#memberPts');
        if (m2 && pts) {
          pts.textContent = faNum(m2.Points);
          toast('امتیاز به‌روزرسانی شد');
        }
      });
      box.querySelector('#dlCardBtn')?.addEventListener('click', async function() {
        const btn = this;
        busy(btn, true, 'در حال صدور کارت…');
        try {
          const resp = await fetch(endpoint('member_card'), { headers: { Authorization: 'Bearer ' + (API.token() || '') } });
          const ct = (resp.headers.get('content-type') || '').toLowerCase();
          if (!resp.ok || ct.includes('application/json')) {
            let msg = 'صدور کارت ناموفق بود';
            try { const j = await resp.json(); msg = j.message || msg; } catch (err) {}
            toast(msg, false);
          } else {
            const blob = await resp.blob();
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'SSAEE-Member-Card.png';
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(() => URL.revokeObjectURL(a.href), 8000);
          }
        } catch (err) { toast('خطای شبکه در صدور کارت', false); }
        busy(btn, false);
      });

      box.querySelector('#renewBtn')?.addEventListener('click', () => {
        joinCard.classList.remove('hidden');
        $('#joinCard h3')?.scrollIntoView({ behavior: 'smooth' });
        toast('برای تمدید، فرم عضویت را یک‌بار دیگر ارسال کنید');
      });
    } else {
      box.className = 'status-box err';
      box.innerHTML = profileComplete
        ? '<i data-lucide="user-x"></i><span><b>شما عضو انجمن نیستید</b> — فرم زیر را پر کنید تا درخواست عضویت شما بررسی شود</span>'
        : '<i data-lucide="user-x"></i><span><b>شما عضو انجمن نیستید</b> — فرم زیر را پر کنید (کد ملی و شماره دانشجویی برای استعلام لازم است)</span>';
      joinCard.classList.remove('hidden');
    }
    refreshIcons();
  }
/* ---------------- workshops ---------------- */

  async function loadWorkshops() {
    const box = $('#wsCards');
    const res = await API.try(endpoint('banners'), { auth: false });
    if (!res?.data?.banners?.length) {
      box.innerHTML = '<div class="loading">در حال حاضر کارگاه فعالی وجود ندارد</div>';
      return;
    }
    box.innerHTML = res.data.banners.map(b => {
      // workshop start = first session date, or «اعلام می‌شود»
      const startHtml = (b.ws_start_jalali && b.ws_start_jalali !== '—')
        ? '<span><i data-lucide="rocket"></i> شروع کارگاه: ' + esc(b.ws_start_jalali) + '</span>'
        : '<span><i data-lucide="calendar-clock"></i> شروع کارگاه: اعلام می‌شود</span>';
      const closed = b.reg_open === false;
      const btn = closed
        ? '<button class="btn" disabled style="opacity:.55;cursor:not-allowed">ثبت‌نام بسته است</button>'
        : '<button class="btn btn-primary" type="button" data-wstag="' + esc(b.tag || '') + '" data-wstitle="' + esc(b.title) + '">ثبت‌نام</button>';
      return '<div class="ws-item"><h4><i data-lucide="graduation-cap"></i> ' + esc(b.title) + '</h4>' +
      '<div class="meta">' +
      (b.start_jalali && b.start_jalali !== '—' ? '<span><i data-lucide="calendar-check"></i> ثبت‌نام: ' + esc(b.start_jalali) + '</span>' : '') +
      (b.end_jalali && b.end_jalali !== '—' ? '<span><i data-lucide="calendar-x"></i> پایان: ' + esc(b.end_jalali) + '</span>' : '') +
      startHtml +
      '</div>' + btn + '</div>';
    }).join('');
    refreshIcons();

    $$('[data-wstag]', box).forEach(btn => {
      btn.addEventListener('click', () => {
        $('#wsModalTitle').textContent = 'ثبت‌نام در: ' + btn.dataset.wstitle;
        $('#workshopRegForm').WorkshopTag.value = btn.dataset.wstag || '';
        $('#wsRegResult').classList.add('hidden');
        openModal('modalWorkshop');
      });
    });
  }

  function initWorkshopForms() {
    // 2.3: not-SRU toggle
    $('#notSru')?.addEventListener('change', e => {
      const external = e.target.checked;
      $('#lblStudent').classList.toggle('hidden', external);
      $('#lblUniversity').classList.toggle('hidden', !external);
      $('#workshopRegForm').StudentID.required = !external;
      $('#workshopRegForm').University.required = external;
      if (external) $('#workshopRegForm').StudentID.value = '';
      else $('#workshopRegForm').University.value = '';
    });

    $('#workshopRegForm')?.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = $('button[type=submit]', e.target);
      const body = formData(e.target);
      body.is_external = body.not_sru === '1' ? '1' : '0';
      delete body.not_sru;
      const res = await API.try(endpoint('workshop_register'), { method: 'POST', body, auth: false }, btn);
      if (!res) return;
      e.target.reset();
      $('#notSru').checked = false;
      $('#lblStudent').classList.remove('hidden');
      $('#lblUniversity').classList.add('hidden');
      $('#wsRegResult').classList.add('hidden');
      // close the registration form modal, then show the success modal with auto-join countdown
      $('#modalWorkshop')?.classList.remove('open');
      showWsJoinedModal(
        $('#wsModalTitle').textContent.replace('ثبت‌نام در: ', ''),
        res.data?.registration_id,
        res.data?.group_link
      );
      // refresh profile lists (my workshops now includes the new registration)
      if (API.token()) loadProfile();
    });

    $('#workshopReqForm')?.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = $('button[type=submit]', e.target);
      const res = await API.try(endpoint('workshop_req'), { method: 'POST', body: formData(e.target), auth: false }, btn);
      if (res) { toast(res.message || 'ثبت شد'); e.target.reset(); }
    });

    $('#classReqForm')?.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = $('button[type=submit]', e.target);
      const res = await API.try(endpoint('class_req'), { method: 'POST', body: formData(e.target), auth: false }, btn);
      if (res) { toast(res.message || 'ثبت شد'); e.target.reset(); }
    });
  }

  /* ---------------- certificates (PNG master) ---------------- */

  /* Preview = the generated PNG; download reuses the SAME bytes via
     canvas blob (no second server render). cert_download stays as the
     no-canvas fallback. */

  function downloadCertImg(img, certId) {
    /* fallback = fetch با هدر Bearer (سایت با JWT احراز هویت می‌کند و
       ناوبری مستقیم href هدر نمی‌فرستد → 401) */
    const fallback = async () => {
      try {
        const headers = { Accept: 'image/png,application/json' };
        if (API.token()) headers['Authorization'] = 'Bearer ' + API.token();
        const res = await fetch(endpoint('cert_download', 'id=' + encodeURIComponent(certId)), { credentials: 'same-origin', headers });
        const ctype = (res.headers.get('content-type') || '').toLowerCase();
        if (!res.ok || !ctype.includes('image/')) { toast('دریافت فایل گواهی ناموفق بود', false); return; }
        const blob = await res.blob();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'certificate-' + String(certId).replace(/[^A-Za-z0-9\-]/g, '') + '.png';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 8000);
      } catch (err) { toast('دریافت فایل گواهی ناموفق بود', false); }
    };
    try {
      const cv = document.createElement('canvas');
      cv.width = img.naturalWidth;
      cv.height = img.naturalHeight;
      const ctx = cv.getContext('2d');
      if (!ctx) { fallback(); return; }
      ctx.drawImage(img, 0, 0);
      cv.toBlob(b => {
        if (!b) { fallback(); return; }
        const a = document.createElement('a');
        a.href = URL.createObjectURL(b);
        a.download = 'certificate-' + String(certId).replace(/[^A-Za-z0-9\-]/g, '') + '.png';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 8000);
      }, 'image/png');
    } catch (err) { fallback(); }
  }

  function certGenerateInto(wrap, certId) {
    return new Promise(resolve => {
      const fail = msg => {
        const box = document.createElement('div');
        box.className = 'certv2-nofile';
        box.innerHTML = '<i data-lucide="file-question"></i> ';
        const s = document.createElement('span');
        s.textContent = msg;
        box.appendChild(s);
        wrap.classList.remove('hidden');
        wrap.innerHTML = '';
        wrap.appendChild(box);
        refreshIcons();
        resolve(false);
      };
      const url = endpoint('cert_file', 'id=' + encodeURIComponent(certId) + '&t=' + Date.now());
      const headers = { Accept: 'image/png,application/json' };
      if (API.token()) headers['Authorization'] = 'Bearer ' + API.token();
      fetch(url, { credentials: 'same-origin', headers })
        .then(async res => {
          const ctype = (res.headers.get('content-type') || '').toLowerCase();
          if (res.ok && ctype.includes('image/')) {
            const blob = await res.blob();
            const img = new Image();
            img.alt = 'گواهی ' + certId;
            img.style.cssText = 'width:100%;border:1px solid var(--border);border-radius:12px;background:#fff';
            img.onload = () => {
              const dl = document.createElement('button');
              dl.type = 'button';
              dl.className = 'btn certv2-dl';
              dl.innerHTML = '<i data-lucide="download"></i> دانلود گواهینامه';
              dl.addEventListener('click', () => downloadCertImg(img, certId));
              wrap.classList.remove('hidden');
              wrap.innerHTML = '';
              wrap.appendChild(img);
              wrap.appendChild(dl);
              refreshIcons();
              resolve(true);
            };
            img.onerror = () => fail('نمایش تصویر تولیدشدهٔ گواهی ناموفق بود');
            img.src = URL.createObjectURL(blob);
            return;
          }
          // server answered JSON (401/403/503...) — show its REAL reason, not a generic text
          let msg = '';
          try { const j = await res.json(); msg = j.message || ''; } catch (e) { /* html/fatal */ }
          fail(msg || `تولید تصویر ناموفق بود (خطای HTTP ${res.status}) — قالب یا فونت سرور را بررسی کنید`);
        })
        .catch(() => fail('تولید تصویر ناموفق بود — ارتباط با سرور برقرار نشد'));
    });
  }

  function bindCertGenerate(btn) {
    btn.addEventListener('click', async function() {
      if (this.dataset.busy) return;
      const id = this.dataset.id;
      const wrap = this.parentElement.querySelector('.cert-gen-box');
      if (!wrap) return;
      this.dataset.busy = '1';
      busy(this, true, 'در حال تولید…');
      const ok = await certGenerateInto(wrap, id);
      busy(this, false);
      delete this.dataset.busy;
      if (ok) this.classList.add('hidden');
    });
  }

  function renderCert(res, box) {
    box.classList.remove('hidden');
    box.className = '';
    const c = res.data.certificate;
    const stClass = res.data.is_valid ? 'valid' : 'expired';
    // public verification: NO download — files belong to their holders only
    box.innerHTML = `
      <div class="certv2 ${stClass}" style="margin-top:16px">
        <div class="certv2-stamp"><i data-lucide="${res.data.is_valid ? 'check-circle' : 'clock'}"></i> ${esc(res.data.status_text)}</div>
        <div class="certv2-body">
          <b class="certv2-course">${esc(c.name_fa)}</b>
          <div class="certv2-meta">
            <span><i data-lucide="book-open"></i> ${esc(c.course)}</span>
            <span><i data-lucide="hash"></i> شماره گواهی: <code>${esc(c.id)}</code></span>
            <span><i data-lucide="calendar"></i> صدور: ${esc(c.issue_jalali)}</span>
            <span><i data-lucide="calendar-clock"></i> انقضا: ${esc(c.exp_jalali)}</span>
          </div>
        </div>
      </div>`;
    refreshIcons();
  }
  function renderCertChoices(options, box) {
    box.classList.remove('hidden');
    box.className = '';
    box.innerHTML = `
      <div class="cert-choices" style="margin-top:16px">
        <p class="muted" style="font-size:.8rem;line-height:1.9;margin-bottom:8px"><i data-lucide="list-filter"></i> چند گواهی با این شمارهٔ سریال پیدا شد — روی مورد درست کلیک کنید:</p>
        ${options.map(o => `
          <button type="button" class="cert-choice" data-id="${esc(o.id)}">
            <b>${esc(o.name_fa || o.name_en || '—')}</b>
            <span>${esc(o.course)}</span>
            <code dir="ltr">${esc(o.id)}</code>
            <span class="muted">صدور: ${esc(o.issue_jalali)}</span>
          </button>`).join('')}
      </div>`;
    $$('.cert-choice', box).forEach(btn => btn.addEventListener('click', () => runCertCheck(btn.dataset.id)));
    refreshIcons();
  }

  async function runCertCheck(value) {
    const box = $('#certResult');
    box.classList.add('hidden');
    const res = await API.try(endpoint('cert_check', 'id=' + encodeURIComponent(String(value).trim())), { auth: false });
    if (!res) return;
    if (res.data && res.data.multiple) renderCertChoices(res.data.options || [], box);
    else renderCert(res, box);
  }

  function initCertForms() {
    $('#certCheckForm')?.addEventListener('submit', e => {
      e.preventDefault();
      runCertCheck(e.target.elements.id.value);
    });


    $('#myCertsBtn')?.addEventListener('click', async () => {
      const box = $('#myCertsList');
      const btn = $('#myCertsBtn');
      busy(btn, true, 'در حال بارگذاری…');
      const res = await API.try(endpoint('my_certificates'));
      busy(btn, false);
      if (!res) return;

      box.classList.remove('hidden');
      const certs = res.data.certificates;

      if (!certs.length) {
        box.innerHTML =
          '<div class="cert-empty"><i data-lucide="file-x"></i>' +
          '<p>هنوز گواهینامه‌ای برای شما ثبت نشده است</p>' +
          '<span class="muted">پس از شرکت در کارگاه‌ها، گواهینامه‌های شما اینجا نمایش داده می‌شود</span></div>';
        refreshIcons();
        return;
      }

      box.innerHTML = certs.map((c, i) => {
        const stClass = c.is_valid ? 'valid' : 'expired';
        const stIcon = c.is_valid ? 'check-circle' : 'clock';
        const stText = c.is_valid ? 'معتبر' : 'منقضی شده';
        return `
          <div class="certv2 ${stClass}">
            <div class="certv2-stamp"><i data-lucide="${stIcon}"></i> ${stText}</div>
            <div class="certv2-body">
              <b class="certv2-course">${esc(c.course)}</b>
              ${c.cert_type ? `<div class="certv2-meta"><span><i data-lucide="award"></i> ${esc(c.cert_type)}</span></div>` : ''}
              <div class="certv2-meta">
                <span><i data-lucide="hash"></i> شماره گواهی: <code>${esc(c.id)}</code></span>
                <span><i data-lucide="calendar"></i> صدور: ${esc(c.issue_jalali)}</span>
                <span><i data-lucide="calendar-clock"></i> انقضا: ${esc(c.exp_jalali)}</span>
              </div>
            </div>
            <button class="btn certv2-dl cert-gen" data-id="${esc(c.id)}" type="button"><i data-lucide="file-image"></i> مشاهده و دانلود گواهینامه</button>
            <div class="cert-gen-box hidden" style="margin-top:12px;width:100%"></div>
          </div>`;
      }).join('');

      box.querySelectorAll('.cert-gen').forEach(bindCertGenerate);
      refreshIcons();
    });
  }

  /* ---------------- classes (term groups) ---------------- */

  async function loadClasses(q = '') {
    const list = $('#classesList');
    const noResult = $('#noResult');
    if (!list) return;

    try {
      const res = await API.try(endpoint('classes', q ? 'q=' + encodeURIComponent(q) : ''), { auth: false });
      if (!res) { list.innerHTML = '<div class="loading">خطا در بارگذاری</div>'; return; }

      const classes = res.data.classes;
      if (!classes.length) {
        list.innerHTML = '';
        noResult.classList.remove('hidden');
        return;
      }

      noResult.classList.add('hidden');
      list.innerHTML = classes.map(c => `
        <div class="class-item">
          <div class="cls-head">
            <span class="cls-course"><i data-lucide="book-open"></i> ${esc(c.course)}</span>
            ${(c.day || c.time) ? `<span class="cls-when">${esc(c.day || '')}${c.day && c.time ? ' — ' : ''}${c.time ? faNum(c.time) : ''}</span>` : ''}
          </div>
          <div class="cls-teacher"><i data-lucide="person-standing"></i> استاد: ${esc(c.teacher)}</div>
          ${c.link
            ? `<a class="btn btn-tg" href="${esc(c.link)}" target="_blank" rel="noopener"><i data-lucide="send"></i> ورود به گروه کلاس</a>`
            : '<span class="muted">لینک گروه به‌زودی اعلام می‌شود</span>'}
        </div>
      `).join('');
      refreshIcons();
    } catch (err) {
      list.innerHTML = '<div class="loading">خطا در بارگذاری کلاس‌ها</div>';
    }
  }

  function initClasses() {
    const search = $('#classSearch');
    if (!search) return;
    let timer = null;
    search.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => loadClasses(search.value.trim()), 400);
    });
  }
  /* ---------------- collab & feedback ---------------- */

  function initCollabForms() {
    const bind = (sel) => {
      $(sel).addEventListener('submit', async e => {
        e.preventDefault();
        const btn = $('button[type=submit]', e.target);
        const res = await API.try(endpoint('collab'), { method: 'POST', body: formData(e.target), auth: false }, btn);
        if (res) { toast(res.message || 'ثبت شد'); e.target.reset(); }
      });
    };
    bind('#assocForm');
    bind('#teacherForm');
    bind('#companyForm');
    // sponsor form has its own wired handler (initSponsor)

    $('#feedbackForm')?.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = $('button[type=submit]', e.target);
      const res = await API.try(endpoint('feedback'), { method: 'POST', body: formData(e.target), auth: false }, btn);
      if (res) { toast(res.message || 'ارسال شد'); e.target.reset(); }
    });
  }

  /* ---------------- telegram connect (2.7) ---------------- */

  function initTgConnect() {
    $('#tgConnectBtn')?.addEventListener('click', async () => {
      const res = await API.try(endpoint('tg_connect_start'));
      if (!res) return;
      if (res.data.already_connected) {
        toast(res.message || 'قبلاً متصل شده است');
        $('#tgConnectCard').classList.add('hidden');
        return;
      }
      openModal('modalTgConnect');
      const steps = $('#tgConnectSteps');
      steps.innerHTML =
        '<div class="card-like"><li><i data-lucide="hash"></i> کد اتصال شما: <b style="color:var(--accent);direction:ltr">' + esc(res.data.code) + '</b></li></div>' +
        '<ol class="benefits" style="counter-reset:step">' +
        '<li><i data-lucide="1"></i> روی دکمه زیر بزنید تا ربات تلگرام باز شود</li>' +
        '<li><i data-lucide="2"></i> دکمه Start را بزنید و شماره موبایل خود را اشتراک‌گذاری کنید</li>' +
        '<li><i data-lucide="3"></i> کد <b>' + esc(res.data.code) + '</b> به صورت خودکار اعمال می‌شود</li>' +
        '</ol>' +
        '<a class="btn btn-primary" style="width:100%" href="' + esc(res.data.url) + '" target="_blank" rel="noopener"><i data-lucide="send"></i> اتصال به ربات تلگرام</a>';
      refreshIcons();
    });
  }

  /* ---------------- password reset confirm ---------------- */

  function initResetFlow() {
    const hash = location.hash;
    const m = hash.match(/#reset=([a-f0-9]{64})/i);
    if (m) {
      $('#forgotBtn').click();
      $('#forgotForm').classList.add('hidden');
      $('#resetBox').classList.remove('hidden');
      $('#resetForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = $('button[type=submit]', e.target);
        const res = await API.try(endpoint('reset_confirm'), {
          method: 'POST',
          body: { token: m[1], password: e.target.password.value },
          auth: false
        }, btn);
        if (res) {
          toast(res.message || 'رمز تغییر یافت');
          $('#modalForgot').classList.remove('open');
          history.replaceState(null, '', location.pathname);
        }
      });
    }
  }

  /* ---------------- board members (About → slideshow) ---------------- */

  let boardTimer = null;
  let boardIndex = 0;
  let boardMembers = [];

  async function loadBoard(term = '') {
    const slider = $('#boardSlider');
    const dots = $('#boardDots');
    clearInterval(boardTimer);

    const res = await API.try(endpoint('board_members', term ? 'term=' + encodeURIComponent(term) : ''));
    if (!res?.data) {
      slider.innerHTML = '<div class="loading">خطا در بارگذاری اعضا</div>';
      return;
    }

    // term tabs
    const termsBox = $('#boardTerms');
    if (res.data.terms.length > 1) {
      termsBox.innerHTML = '<div class="board-terms-row">' +
        res.data.terms.map(t =>
          `<button class="tab-btn ${t === res.data.term ? 'active' : ''}" data-term="${esc(t)}" type="button">${esc(t)}</button>`
        ).join('') + '</div>';
      termsBox.querySelectorAll('[data-term]').forEach(b => {
        b.addEventListener('click', () => loadBoard(b.dataset.term));
      });
      termsBox.classList.remove('hidden');
    } else {
      termsBox.innerHTML = res.data.terms.length ? '<div class="board-terms-row"><span class="muted" style="font-size:.78rem">دوره ' + esc(res.data.term) + '</span></div>' : '';
    }

    boardMembers = res.data.members;
    if (!boardMembers.length) {
      slider.innerHTML = '<div class="loading">هنوز عضوی برای این دوره ثبت نشده است</div>';
      dots.innerHTML = '';
      return;
    }

    renderBoardSlide(0);
    if (boardMembers.length > 1) {
      boardTimer = setInterval(() => {
        renderBoardSlide((boardIndex + 1) % boardMembers.length);
      }, 6000);
    }
  }

  function renderBoardSlide(i) {
    boardIndex = i;
    const m = boardMembers[i];
    const slider = $('#boardSlider');

    const photo = m.photo
      ? `<img class="bm-photo" src="${esc(m.photo)}" alt="${esc(m.name)}" onerror="this.remove()">`
      : `<div class="bm-photo bm-fallback"><i data-lucide="user-round"></i></div>`;

    slider.innerHTML = `
      <div class="bm-card">
        ${photo}
        <div class="bm-info">
          <b class="bm-name">${esc(m.name)}</b>
          ${m.position ? `<span class="bm-position">${esc(m.position)}</span>` : ''}
          <div class="bm-links">
            ${m.telegram ? `<a class="icon-btn" href="${esc(m.telegram)}" target="_blank" rel="noopener" title="تلگرام"><i data-lucide="send"></i></a>` : ''}
            ${m.linkedin ? `<a class="icon-btn" href="${esc(m.linkedin)}" target="_blank" rel="noopener" title="لینکدین"><i data-lucide="linkedin"></i></a>` : ''}
          </div>
        </div>
        <div class="bm-nav">
          <button class="icon-btn" type="button" data-bm-prev><i data-lucide="chevron-right"></i></button>
          <button class="icon-btn" type="button" data-bm-next><i data-lucide="chevron-left"></i></button>
        </div>
      </div>`;

    $('#boardDots [data-bm-dot]').forEach((d, idx) => d.classList.toggle('on', idx === i));
    $('[data-bm-prev]', slider).addEventListener('click', () => renderBoardSlide((boardIndex - 1 + boardMembers.length) % boardMembers.length));
    $('[data-bm-next]', slider).addEventListener('click', () => renderBoardSlide((boardIndex + 1) % boardMembers.length));
    refreshIcons();
    renderBoardDots();
  }

  function renderBoardDots() {
    const dots = $('#boardDots');
    if (boardMembers.length < 2) { dots.innerHTML = ''; return; }
    if (dots.children.length !== boardMembers.length) {
      dots.innerHTML = boardMembers.map((_, i) =>
        `<span data-bm-dot data-idx="${i}"></span>`).join('');
      dots.querySelectorAll('[data-bm-dot]').forEach(d => {
        d.addEventListener('click', () => renderBoardSlide(Number(d.dataset.idx)));
      });
    }
    dots.querySelectorAll('[data-bm-dot]').forEach((d, idx) => d.classList.toggle('on', idx === boardIndex));
  }

  /* ---------------- boot ---------------- */

  let booted = false;

  async function boot() {
    if (booted) return;
    booted = true;

    // Server + environment diagnostics (helps catch .env/schema issues early)
    try {
      const ping = await API.call(endpoint('ping'), { auth: false });
      const d = ping?.data || {};
      if (d.err) {
        splashError('خطا در بارگذاری پنل', d.err);
        return;
      }
    } catch (err) {
      splashError(err.message, err.errCode || null);
      return;
    }

    // One-time auth link from the Telegram bot (#auth=<jwt>)
    const authHash = location.hash.match(/#auth=([A-Za-z0-9\-_.]+)/);
    if (authHash) {
      API.setToken(authHash[1]);
      try {
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', location.pathname + (location.search || ''));
        } else {
          location.hash = '';
        }
      } catch (_) {}
      toast('با موفقیت وارد شدید 🎉');
    }

    const inits = [initTheme, initNav, initAuthForms, initProfileEdit, initFaNames, initSponsor, initMemberForms, initWorkshopForms, initCertForms, initCollabForms, initClasses, initTgConnect, initResetFlow, initAccountDelete, initWsJoinedClose, loadHelpModal];
    for (const fn of inits) {
      try { fn(); } catch (err) { console.error('[init]', err); }
    }

    // Ensure Telegram WebApp initData is captured if Telegram SDK loads slightly after DOMContentLoaded
    if (!API.initData() && ((location.hash || '').includes('tgWebAppData') || /Telegram/i.test(navigator.userAgent))) {
      for (let i = 0; i < 6; i++) {
        if (window.Telegram?.WebApp?.initData) break;
        await new Promise(r => setTimeout(r, 50));
      }
    }

    // telegram env — safe-mode fallback if Telegram lib unreachable
    try {
      if (window.Telegram?.WebApp) {
        Telegram.WebApp.ready();
        Telegram.WebApp.expand();
      }
      window.__TG_OK = !!(window.Telegram?.WebApp || API.initData());
    } catch (err) {
      window.__TG_OK = false;
      console.warn('[tg] Telegram SDK unavailable - simple mode', err);
    }

    await bootAuth();
    await loadBanners();
    await loadWorkshops();
    await loadAnnouncements();

    splashDone();
  }

  $('#splashRetry')?.addEventListener('click', () => {
    $('#splashError')?.classList.add('hidden');
    $('.splash-spinner')?.classList.remove('hidden');
    booted = false;
    boot().catch(err => splashError(err.message, err.errCode || 1099));
  });

  document.addEventListener('auth:required', () => {
    toast('برای این امکان باید وارد حساب شوید', false);
  });

  window.addEventListener('DOMContentLoaded', () => {
    boot().catch(err => {
      console.error(err);
      splashError(err.message, err.errCode || 1099);
    });
  });

  // Unhandled async errors → splash error while loading, toast afterwards
  window.addEventListener('unhandledrejection', ev => {
    const msg = ev.reason?.message || 'خطای ناشناخته';
    if (!$('#splash').classList.contains('done')) splashError(msg, ev.reason?.errCode || 1099);
    else toast(msg, false);
  });

})();
