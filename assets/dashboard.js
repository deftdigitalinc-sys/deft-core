/*
 * Deft Core: Site report dashboard. Draws window.deftDash (see modules/dashboard.php) with plain SVG and HTML,
 * and switches months through GET /wp-json/deft/v1/report. No libraries.
 * Months saved by Deft Core 0.4.0 lack some fields (map points, best times, daily contacts); those panels say so.
 */
(function () {
  'use strict';
  var D = window.deftDash;
  var root = document.getElementById('deft-dash');
  if (!D || !root) return;

  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var nf = new Intl.NumberFormat();
  var state = { data: D.start, heat: 'visits', page: 0 };
  var tip;

  /* ---------- Helpers ---------- */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function n(v) { return nf.format(Math.round(+v || 0)); }
  function sum(list, key) { return (list || []).reduce(function (a, r) { return a + (+r[key] || 0); }, 0); }
  function pct(part, whole) { return whole ? Math.round(100 * part / whole) : 0; }
  function change(now, before) {
    if (!before) return null;
    return Math.round(100 * (now - before) / before);
  }
  function chip(c) {
    if (c === null || c === undefined) return '';
    var cls = c > 0 ? 'up' : c < 0 ? 'down' : 'flat';
    var arrow = c > 0 ? '▲' : c < 0 ? '▼' : '•';
    return '<span class="dd-chip ' + cls + '"><span aria-hidden="true">' + arrow + '</span>' + (c > 0 ? '+' : '') + c + '%</span>';
  }
  function count(v) { return '<span data-count="' + (+v || 0) + '">' + (still ? n(v) : '0') + '</span>'; }
  function end(s) { return /[.!?…]$/.test(String(s || '')) ? '' : '.'; }
  function shortSource(name) { return String(name || '').replace(/\s*\(.*\)\s*$/, ''); }
  function dayOf(iso) { return +String(iso).slice(8, 10); }
  function daysIn(month) { var p = month.split('-'); return new Date(+p[0], +p[1], 0).getDate(); }
  function monthShort(month) { var p = month.split('-'); return new Date(+p[0], +p[1] - 1, 15).toLocaleDateString(undefined, { month: 'short' }); }
  function isGA(rep) { return !!(rep && rep.origin === 'ga4'); }
  // "vs. September", noting Google Analytics when only one side of the comparison comes from it.
  function vsLabel() {
    var d = state.data;
    return prevLabel() + (isGA(d.prev) !== isGA(d.report) && isGA(d.prev) ? ' (Google Analytics)' : '');
  }
  var regionName = (function () {
    try { var dn = new Intl.DisplayNames(undefined, { type: 'region' }); return function (c) { try { return dn.of(c); } catch { return c; } }; }
    catch { return function (c) { return c; }; }
  })();
  function prevLabel() { return D.months.length ? new Date(+state.data.month.slice(0, 4), +state.data.month.slice(5, 7) - 2, 15).toLocaleDateString(undefined, { month: 'long' }) : ''; }

  var ICON = {
    call: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
    whatsapp: '<path d="M3 21l1.65-3.8A9 9 0 1 1 7.8 20L3 21"/><path d="M9 10a.5.5 0 0 0 1 0V9a.5.5 0 0 0-1 0v1a5 5 0 0 0 5 5h1a.5.5 0 0 0 0-1h-1a.5.5 0 0 0 0 1"/>',
    text: '<path d="M4 5h16v11H8l-4 4z"/>',
    email: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    directions: '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>',
    form: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h3"/>',
    chart: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    full: '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'
  };
  function icon(k) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICON[k] || ICON.form) + '</svg>'; }

  // Colors follow the source, not its rank (validated categorical set, see dashboard.css).
  var SOURCE_SLOT = { direct: 1, google: 2, facebook: 3, instagram: 4, yelp: 5, nextdoor: 6 };
  function sourceColor(key) { return SOURCE_SLOT[key] ? 'var(--s' + SOURCE_SLOT[key] + ')' : 'var(--s-other)'; }

  /* ---------- Tooltip ---------- */
  function showTip(html, x, y) {
    tip.innerHTML = html;
    tip.classList.add('on');
    var w = tip.offsetWidth, h = tip.offsetHeight;
    var left = Math.min(window.innerWidth - w - 8, Math.max(8, x + 14));
    var top = y - h - 14 < 8 ? y + 18 : y - h - 14;
    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
  }
  function hideTip() { tip.classList.remove('on'); }
  function bindTips(scope) {
    scope.querySelectorAll('[data-tip]').forEach(function (el) {
      el.addEventListener('mousemove', function (e) { showTip(el.getAttribute('data-tip'), e.clientX, e.clientY); });
      el.addEventListener('mouseleave', hideTip);
    });
  }

  /* ---------- Shell ---------- */
  function shell() {
    var months = D.months.slice(0, 6).map(function (m) {
      return '<button type="button" class="dd-month" data-month="' + esc(m.value) + '" aria-pressed="false" title="' + esc(m.label) + '">' + esc(m.short) + (m.live ? ' ·&nbsp;now' : '') + (m.ga ? '<small>GA</small>' : '') + '</button>';
    }).join('');
    var older = D.months.length > 6 ? '<select class="dd-select" aria-label="Earlier months"><option value="">Earlier…</option>' + D.months.slice(6).map(function (m) {
      return '<option value="' + esc(m.value) + '">' + esc(m.label) + (m.ga ? ' (Google Analytics)' : '') + '</option>';
    }).join('') + '</select>' : '';
    root.innerHTML =
      '<div class="dd-wrap">' +
        '<header class="dd-top">' +
          '<div class="dd-brand"><div class="dd-mark"><img src="' + esc(D.icon) + '" alt="Deft"></div><div style="min-width:0">' +
            '<div class="dd-eyebrow">Site report <span class="dd-live-slot"></span></div>' +
            '<h1 class="dd-site">' + esc(D.site) + '<span class="dd-host">' + esc(D.host) + '</span></h1></div></div>' +
          '<div class="dd-controls"><div class="dd-months" role="group" aria-label="Month">' + months + '</div>' + older +
            (D.gaSync ? '<button type="button" class="dd-btn' + (D.gaSync.due ? ' dd-btn-due' : '') + '" data-deft-ga-sync data-deft-ga-reload title="' + esc(D.gaSync.due ? 'Last month isn’t fully synced yet' : 'Up to date') + (D.gaSync.last ? ' · last synced ' + esc(new Date(D.gaSync.last.replace(' ', 'T') + 'Z').toLocaleDateString(undefined, { month: 'short', day: 'numeric' })) : '') + '">' + icon('chart') + '<span>Sync Google Analytics</span></button>' : '') +
            '<button type="button" class="dd-btn" data-full title="Full screen">' + icon('full') + '<span>Full screen</span></button>' +
            (D.canSend ? '<button type="button" class="dd-btn dd-btn-sun" data-send>' + icon('mail') + '<span>Email report</span></button>' : '') +
          '</div>' +
        '</header>' +
        (D.on ? '' : '<div class="dd-banner">' + icon('gear') + '<div><b>Visitor statistics are switched off.</b> Nothing new is being counted. <a href="' + esc(D.settings) + '">Switch them on in Settings &gt; Deft</a>.</div></div>') +
        '<div class="dd-body"></div>' +
        '<footer class="dd-foot"><span class="dd-foot-note">Visits are counted once per visitor per day. Locations are estimated from internet addresses and are approximate. No cookies, nothing that identifies a person.</span>' +
          '<span>IP location by <a href="https://db-ip.com" target="_blank" rel="noopener">DB-IP</a> (CC BY 4.0) · ZIP and county data by <a href="https://www.geonames.org" target="_blank" rel="noopener">GeoNames</a> (CC BY 4.0) · Deft Core by Deft Digital</span></footer>' +
      '</div>';
    tip = document.createElement('div');
    tip.className = 'dd-tip';
    tip.setAttribute('role', 'tooltip');
    root.appendChild(tip);

    root.querySelectorAll('.dd-month').forEach(function (b) {
      b.addEventListener('click', function () { load(b.getAttribute('data-month')); });
    });
    var sel = root.querySelector('.dd-select');
    if (sel) sel.addEventListener('change', function () { if (sel.value) load(sel.value); });
    root.querySelector('[data-full]').addEventListener('click', function () {
      if (document.fullscreenElement) document.exitFullscreen();
      else if (root.requestFullscreen) root.requestFullscreen();
    });
    var send = root.querySelector('[data-send]');
    if (send) send.addEventListener('click', function () {
      var f = document.createElement('form');
      f.method = 'post';
      f.action = D.send.url;
      [['action', 'deft_report_send'], ['month', state.data.month], ['_wpnonce', D.send.nonce]].forEach(function (p) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = p[0]; i.value = p[1];
        f.appendChild(i);
      });
      document.body.appendChild(f);
      f.submit();
    });
    if (D.sent !== null && D.sent !== undefined) {
      var t = document.createElement('div');
      t.className = 'dd-toast';
      t.textContent = D.sent ? 'Report emailed.' : 'WordPress couldn’t send the report. Check the site’s email (SMTP) setup.';
      root.appendChild(t);
      setTimeout(function () { t.remove(); }, 5000);
    }
  }

  function load(month) {
    if (month === state.data.month) return;
    root.classList.add('dd-loading');
    fetch(D.rest + (D.rest.indexOf('?') === -1 ? '?' : '&') + 'month=' + encodeURIComponent(month), { headers: { 'X-WP-Nonce': D.nonce }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (data) {
        state.data = data;
        state.page = 0;
        var url = new URL(location.href);
        url.searchParams.set('month', month);
        url.searchParams.delete('deft_sent');
        history.replaceState(null, '', url);
        render();
      })
      .catch(function () { alert('Couldn’t load that month. Please reload the page.'); })
      .then(function () { root.classList.remove('dd-loading'); });
  }

  /* ---------- Numbers for the month ---------- */
  function figures() {
    var d = state.data, r = d.report || {}, p = d.prev || {};
    var tap = function (rep, kind) { var row = (rep.taps || []).filter(function (t) { return t.label === kind; })[0]; return row ? +row.taps : 0; };
    var f = {
      visits: +r.visits || 0, views: +r.views || 0, contacts: +r.contacts || 0, forms: +r.form_sends || 0,
      calls: tap(r, 'call'), whatsapp: tap(r, 'whatsapp'),
      contactVisits: r.contact_visits !== undefined ? +r.contact_visits : null,
      prev: { visits: +p.visits || 0, views: +p.views || 0, contacts: +p.contacts || 0, forms: +p.form_sends || 0, calls: tap(p, 'call'), whatsapp: tap(p, 'whatsapp'), contactVisits: p.contact_visits !== undefined ? +p.contact_visits : null }
    };
    f.rate = f.contactVisits !== null && f.visits ? 100 * f.contactVisits / f.visits : null;
    f.prev.rate = f.prev.contactVisits !== null && f.prev.visits ? 100 * f.prev.contactVisits / f.prev.visits : null;
    // This month so far is compared with the same days of last month.
    f.through = d.live ? dayOf(d.today) : 31;
    f.pace = {
      visits: sum((p.daily || []).filter(function (x) { return dayOf(x.day) <= f.through; }), 'visits'),
      contacts: p.daily_contacts ? sum(p.daily_contacts.filter(function (x) { return dayOf(x.day) <= f.through; }), 'contacts') : null
    };
    return f;
  }

  /* ---------- Render ---------- */
  function render() {
    var d = state.data;
    root.querySelectorAll('.dd-month').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-month') === d.month ? 'true' : 'false'); });
    var sel = root.querySelector('.dd-select');
    if (sel) sel.value = D.months.slice(6).some(function (m) { return m.value === d.month; }) ? d.month : '';
    var ga = isGA(d.report), mixed = !!(d.report && d.report.origin === 'mixed');
    root.querySelector('.dd-foot-note').textContent = ga ? 'This month’s numbers come from Google Analytics (which uses cookies) through Site Kit, as monthly totals. US counties and map positions are worked out from the cities it reports.' : mixed ? 'The first days of this month come from Google Analytics (which uses cookies) through Site Kit. After that, visits are counted once per visitor per day by Deft Core, with no cookies. Locations are approximate.' : 'Visits are counted once per visitor per day. Locations are estimated from internet addresses and are approximate. No cookies, nothing that identifies a person.';
    root.querySelector('.dd-live-slot').innerHTML = d.live ? '<span class="dd-live"><i></i>Live · ' + esc(d.label) + ' so far</span>' : '· ' + esc(d.label) + (ga ? ' · from Google Analytics' : '');

    var f = figures();
    var body = root.querySelector('.dd-body');
    if (ga) body.innerHTML = gaBanner() + '<div class="dd-grid">' + hero(f) + searchCard() + gaTiles(f) + sourcesCard() + countriesCard() + mapCard() + countiesCard() + heatCard('c12') + leadsCard() + pagesCard() + '</div>';
    else body.innerHTML = (mixed ? mixedBanner() : '') + '<div class="dd-grid">' +
      hero(f) + contactsCard(f) +
      kpi('Page views', f.views, d.live ? null : change(f.views, f.prev.views), d.live ? 'Last month ' + n(f.prev.views) : '', null) +
      kpi('Calls', f.calls, d.live ? null : change(f.calls, f.prev.calls), d.live ? 'Last month ' + n(f.prev.calls) : '', null) +
      kpi('Form leads', f.forms, d.live ? null : change(f.forms, f.prev.forms), d.live ? 'Last month ' + n(f.prev.forms) : '', null) +
      rateTile(f) +
      sourcesCard() + contactSourcesCard() +
      mapCard() + countiesCard() +
      heatCard('c7') + funnelCard(f) +
      sectionsCard() +
      leadsCard() + pagesCard() +
      '</div>' + gaSection();
    body.querySelector('.dd-grid').insertAdjacentHTML('afterbegin', '<svg width="0" height="0" style="position:absolute"><defs><linearGradient id="dd-sun-fade" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#FFC700" stop-opacity=".35"/><stop offset="1" stop-color="#FFC700" stop-opacity="0"/></linearGradient></defs></svg>');

    wireHero(body);
    wireDonut(body);
    wireHeat(body);
    wireSections(body);
    bindTips(body);
    countUp(body);
    root.setAttribute('aria-busy', 'false');
  }

  function countUp(scope) {
    var els = scope.querySelectorAll('[data-count]');
    if (still) return;
    var t0 = performance.now();
    (function frame(t) {
      var k = Math.min(1, (t - t0) / 1100), e = 1 - Math.pow(1 - k, 3);
      els.forEach(function (el) {
        var v = +el.getAttribute('data-count');
        var dec = el.hasAttribute('data-dec') ? 1 : 0;
        el.textContent = dec ? (v * e).toFixed(1) : n(v * e);
      });
      if (k < 1) requestAnimationFrame(frame);
    })(t0);
  }

  /* ---- Hero: visits per day ---- */
  function series() {
    var d = state.data, r = d.report || {}, p = d.prev || {};
    var len = daysIn(d.month), shown = d.live ? Math.min(len, dayOf(d.today)) : len;
    var byDay = function (rows, key) { var m = {}; (rows || []).forEach(function (x) { m[dayOf(x.day)] = +x[key] || 0; }); return m; };
    var v = byDay(r.daily, 'visits'), c = byDay(r.daily_contacts, 'contacts'), g = byDay(p.daily, 'visits');
    var prevLen = daysIn(p.month || d.month);
    var out = [];
    for (var i = 1; i <= len; i++) out.push({ day: i, visits: i <= shown ? (v[i] || 0) : null, contacts: i <= shown ? (c[i] || 0) : null, ghost: i <= prevLen ? (g[i] || 0) : null });
    return { days: out, len: len, shown: shown, hasContacts: !!r.daily_contacts, hasGhost: (p.daily || []).length > 0 };
  }

  function hero(f) {
    var d = state.data;
    var cmp = d.live ? change(f.visits, f.pace.visits) : change(f.visits, f.prev.visits);
    var vs = d.live ? 'vs. the same days in ' + vsLabel() : 'vs. ' + vsLabel();
    var s = series();
    var max = Math.max(4, Math.max.apply(null, s.days.map(function (x) { return Math.max(x.visits || 0, s.hasGhost ? x.ghost || 0 : 0); })));
    max = Math.ceil(max * 1.15);
    var W = 1000, H = 200, X = function (i) { return s.len > 1 ? (i / (s.len - 1)) * W : W / 2; }, Y = function (v) { return H - (v / max) * H; };
    var line = '', area = '', ghost = '';
    s.days.forEach(function (x, i) {
      if (x.visits !== null) line += (line ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(x.visits).toFixed(1);
      if (x.ghost !== null) ghost += (i ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(x.ghost).toFixed(1);
    });
    if (line) area = line + 'L' + X(s.shown - 1).toFixed(1) + ' ' + H + 'L0 ' + H + 'Z';
    var grid = [0.25, 0.5, 0.75].map(function (k) { return '<line class="dd-grid-line" x1="0" x2="' + W + '" y1="' + (H * k) + '" y2="' + (H * k) + '" vector-effect="non-scaling-stroke"/>'; }).join('');
    var cmax = Math.max(1, Math.max.apply(null, s.days.map(function (x) { return x.contacts || 0; })));
    var bw = W / s.len;
    var bars = s.days.map(function (x, i) {
      if (!x.contacts) return '';
      var h = Math.max(4, (x.contacts / cmax) * 46);
      return '<rect x="' + (i * bw + bw * 0.22).toFixed(1) + '" width="' + (bw * 0.56).toFixed(1) + '" y="' + (46 - h).toFixed(1) + '" height="' + h.toFixed(1) + '" rx="2" fill="var(--blue-light)"/>';
    }).join('');
    var ticks = [1, Math.round(s.len / 4), Math.round(s.len / 2), Math.round(3 * s.len / 4), s.len].filter(function (v, i, a) { return a.indexOf(v) === i; });
    var mon = monthShort(d.month);
    var axis = ticks.map(function (t) { return '<span style="left:' + ((t - 1) / (s.len - 1) * 100) + '%">' + mon + ' ' + t + '</span>'; }).join('');

    return '<section class="dd-card dd-hero c8" aria-label="Visits">' +
      '<div class="dd-hero-top"><div><div class="dd-label">' + (isGA(d.report) ? 'Visits (Google Analytics sessions)' : 'Visits' + (d.live ? ' so far' : '')) + '</div>' +
        '<div class="dd-big">' + count(f.visits) + '</div>' +
        '<div style="display:flex;gap:8px;align-items:center;margin-top:10px">' + chip(cmp) + (cmp !== null ? '<span class="dd-vs">' + esc(vs) + '</span>' : '') + '</div></div>' +
        '<p class="dd-story">' + story(f) + '</p></div>' +
      '<div class="dd-chart">' +
        '<div class="dd-legend" style="margin-bottom:10px"><span><i class="dd-key"></i>Visits per day</span>' + (s.hasGhost ? '<span><i class="dd-key ghost"></i>' + esc(prevLabel()) + '</span>' : '') + (s.hasContacts ? '<span><i class="dd-key bar"></i>Got in touch per day</span>' : '') + '</div>' +
        '<div style="position:relative">' +
          '<svg class="dd-area" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img" aria-label="Visits per day">' + grid +
            (s.hasGhost ? '<path class="dd-ghost" d="' + ghost + '" vector-effect="non-scaling-stroke"/>' : '') +
            (area ? '<path d="' + area + '" fill="url(#dd-sun-fade)"/>' : '') +
            (line ? '<path class="dd-line' + (still ? '' : ' dd-draw') + '" pathLength="1" d="' + line + '" vector-effect="non-scaling-stroke"/>' : '') +
          '</svg>' +
          (s.hasContacts ? '<svg class="dd-strip" viewBox="0 0 ' + W + ' 46" preserveAspectRatio="none" aria-hidden="true">' + bars + '<line class="dd-grid-line" x1="0" x2="' + W + '" y1="46" y2="46" vector-effect="non-scaling-stroke"/></svg>' : '') +
          '<div class="dd-cross"></div><div class="dd-dot"></div><div class="dd-hover" data-max="' + max + '"></div>' +
        '</div>' +
        '<div class="dd-axis">' + axis + '</div>' +
      '</div></section>';
  }

  function wireHero(scope) {
    var hov = scope.querySelector('.dd-hover');
    if (!hov) return;
    var s = series(), max = +hov.getAttribute('data-max'), mon = monthShort(state.data.month);
    var cross = scope.querySelector('.dd-cross'), dot = scope.querySelector('.dd-dot'), svg = scope.querySelector('.dd-area');
    hov.addEventListener('mousemove', function (e) {
      var box = hov.getBoundingClientRect();
      var i = Math.max(0, Math.min(s.len - 1, Math.round((e.clientX - box.left) / box.width * (s.len - 1))));
      var x = s.days[i], px = s.len > 1 ? i / (s.len - 1) * box.width : box.width / 2;
      cross.style.left = px + 'px'; cross.style.opacity = 1;
      if (x.visits !== null) {
        dot.style.left = px + 'px';
        dot.style.top = (svg.clientHeight * (1 - x.visits / max)) + 'px';
        dot.style.opacity = 1;
      } else dot.style.opacity = 0;
      var html = '<b>' + mon + ' ' + x.day + '</b><br>' + (x.visits === null ? '<span class="dd-muted">Still to come</span>' :
        n(x.visits) + ' visit' + (x.visits === 1 ? '' : 's') + (s.hasContacts ? ' · ' + n(x.contacts) + ' got in touch' : '')) +
        (s.hasGhost && x.ghost !== null ? '<span class="dd-muted">' + esc(prevLabel()) + ' ' + x.day + ': ' + n(x.ghost) + ' visits</span>' : '');
      showTip(html, e.clientX, e.clientY);
    });
    hov.addEventListener('mouseleave', function () { cross.style.opacity = 0; dot.style.opacity = 0; hideTip(); });
  }

  function story(f) {
    var d = state.data, r = d.report || {};
    if (!f.visits && !f.contacts) return d.live ? 'No visits counted yet this month. Numbers appear here as soon as people visit the site.' : 'No visits were counted this month.';
    var parts = [];
    if (isGA(r)) {
      parts.push('<b>' + n(f.visits) + ' visits</b> in ' + esc(d.label) + ', counted by Google Analytics' + (r.users ? ' (' + n(r.users) + ' visitors)' : '') + '.');
      var src = groupedSources()[0], country = (r.countries || [])[0];
      if (src && f.visits) parts.push('Most came from <b>' + esc(src.name) + '</b> (' + pct(src.visits, f.visits) + '%).');
      if (country && r.located) parts.push('<b>' + pct(country.visits, r.located) + '%</b> were from ' + esc(regionName(country.country)) + '.');
      return parts.join(' ');
    }
    parts.push('<b>' + n(f.visits) + ' visits</b> ' + (d.live ? 'so far in ' : 'in ') + esc(d.label.split(' ')[0]) + '.');
    if (f.contacts) {
      var top = (r.contacts_by_source || [])[0];
      parts.push('People got in touch <b>' + n(f.contacts) + ' time' + (f.contacts === 1 ? '' : 's') + '</b>' + (top ? ', most often after arriving from <b>' + esc(top.name) + '</b>' : '') + '.');
    } else parts.push('Nobody has called or sent a form yet.');
    var county = (r.counties || [])[0];
    if (county && r.us_located) parts.push('The biggest area is <b>' + esc(county.county) + '</b> (' + pct(county.visits, r.us_located) + '% of located visits).');
    return parts.join(' ');
  }

  /* ---- Got in touch ---- */
  function contactsCard(f) {
    var d = state.data, r = d.report || {};
    var ways = (r.taps || []).map(function (t) { return { k: t.label, name: t.name || t.label, v: +t.taps }; });
    if (r.form_sends) ways.push({ k: 'form', name: 'Form leads', v: +r.form_sends });
    ways.sort(function (a, b) { return b.v - a.v; });
    var max = Math.max(1, ways.length ? ways[0].v : 1);
    var cmp = d.live ? (f.pace.contacts !== null ? change(f.contacts, f.pace.contacts) : null) : change(f.contacts, f.prev.contacts);
    return '<section class="dd-card dd-contacts c4" aria-label="Got in touch">' +
      '<div class="dd-label">Got in touch</div>' +
      '<div class="dd-big">' + count(f.contacts) + '</div>' +
      '<div style="display:flex;gap:8px;align-items:center;margin-top:10px">' + chip(cmp) + '<span class="dd-vs">calls, texts, WhatsApp, emails, directions and forms</span></div>' +
      (ways.length ? '<div class="dd-ways">' + ways.map(function (w, i) {
        return '<div class="dd-way"><span class="dd-way-ico">' + icon(w.k) + '</span><div><div class="dd-way-name">' + esc(w.name) + '</div><div class="dd-way-bar"><i style="width:' + (100 * w.v / max) + '%;animation-delay:' + (0.25 + i * 0.08) + 's"></i></div></div><span class="dd-way-n">' + n(w.v) + '</span></div>';
      }).join('') + '</div>' : '<p class="dd-empty">No calls, taps or forms yet.</p>') +
      '</section>';
  }

  function kpi(label, v, cmp, note, spark) {
    return '<section class="dd-card dd-kpi c3"><div class="dd-label">' + esc(label) + '</div><div class="dd-kpi-n">' + count(v) + '</div>' +
      '<div class="dd-kpi-row">' + (cmp !== null ? chip(cmp) + '<span class="dd-vs">vs. ' + esc(vsLabel()) + '</span>' : '<span class="dd-vs">' + esc(note) + '</span>') + '</div>' + (spark || '') + '</section>';
  }

  function rateTile(f) {
    var d = state.data;
    if (f.rate === null) return kpi('WhatsApp', f.whatsapp, d.live ? null : change(f.whatsapp, f.prev.whatsapp), d.live ? 'Last month ' + n(f.prev.whatsapp) : '', null);
    var diff = f.prev.rate !== null ? Math.round((f.rate - f.prev.rate) * 10) / 10 : null;
    var note = diff === null ? 'of visits ended in a call, tap or form' : (diff > 0 ? '▲ +' : diff < 0 ? '▼ ' : '• ') + diff + ' pts vs. ' + prevLabel();
    return '<section class="dd-card dd-kpi c3"><div class="dd-label">Contact rate</div><div class="dd-kpi-n"><span data-count="' + f.rate.toFixed(1) + '" data-dec>' + (still ? f.rate.toFixed(1) : '0') + '</span>%</div>' +
      '<div class="dd-kpi-row"><span class="dd-vs">' + esc(note) + '</span></div>' +
      '<div class="dd-way-bar" style="margin-top:8px;height:6px" title="Share of visits that got in touch (bar scale: 0 to 20%)"><i style="width:' + Math.min(100, f.rate * 5) + '%"></i></div></section>';
  }

  /* ---- Sources ---- */
  function groupedSources() {
    var r = state.data.report || {}, map = {}, order = [];
    (r.sources || []).forEach(function (s) {
      if (!map[s.source]) { map[s.source] = { key: s.source, name: shortSource(s.name || s.source), visits: 0, parts: [] }; order.push(map[s.source]); }
      map[s.source].visits += +s.visits;
      map[s.source].parts.push((s.name || s.source) + ': ' + n(s.visits));
    });
    order.sort(function (a, b) { return b.visits - a.visits; });
    // Keep six named sources; fold the rest into "Other".
    var named = order.filter(function (s) { return SOURCE_SLOT[s.key]; }).slice(0, 6);
    var rest = order.filter(function (s) { return named.indexOf(s) === -1; });
    if (rest.length) named.push({ key: '_other', name: rest.length === 1 ? rest[0].name : 'Other sites', visits: sum(rest, 'visits'), parts: rest.map(function (s) { return s.name + ': ' + n(s.visits); }) });
    return named;
  }

  function sourcesCard() {
    var list = groupedSources(), total = sum(list, 'visits');
    if (!total) return card('c5', 'Where visitors came from', '', '<p class="dd-empty">No visits yet.</p>');
    var R = 42, C = 2 * Math.PI * R, gap = list.length > 1 ? 1.2 : 0, at = 0;
    var segs = list.map(function (s, i) {
      var len = Math.max(0.0001, C * s.visits / total - gap);
      var seg = '<circle class="seg" data-i="' + i + '" cx="50" cy="50" r="' + R + '" stroke="' + sourceColor(s.key) + '" stroke-dasharray="' + len.toFixed(2) + ' ' + (C - len).toFixed(2) + '" stroke-dashoffset="' + (-at).toFixed(2) + '" data-tip="' + esc('<b>' + esc(s.name) + '</b> · ' + n(s.visits) + ' visits (' + pct(s.visits, total) + '%)<span class="dd-muted">' + s.parts.map(esc).join('<br>') + '</span>') + '"/>';
      at += C * s.visits / total;
      return seg;
    }).join('');
    var legend = list.map(function (s, i) {
      return '<li data-i="' + i + '"><span class="dd-sw" style="background:' + sourceColor(s.key) + '"></span><span>' + esc(s.name) + '</span><span class="dd-n">' + n(s.visits) + '</span><span class="dd-pct">' + pct(s.visits, total) + '%</span></li>';
    }).join('');
    return card('c5', 'Where visitors came from', 'Each visit counted by how it arrived', '<div class="dd-donut-wrap"><div class="dd-donut"><svg viewBox="0 0 100 100" role="img" aria-label="Visits by source">' + segs + '</svg><div class="dd-donut-c"><b>' + n(total) + '</b><span>visits</span></div></div><ul class="dd-list">' + legend + '</ul></div>');
  }

  function wireDonut(scope) {
    var segs = scope.querySelectorAll('.dd-donut .seg'), rows = scope.querySelectorAll('.dd-donut-wrap .dd-list li');
    function hot(i) {
      segs.forEach(function (s) { var on = s.getAttribute('data-i') === i; s.classList.toggle('hot', on); s.classList.toggle('dim', i !== null && !on); });
      rows.forEach(function (r) { r.classList.toggle('hot', r.getAttribute('data-i') === i); });
    }
    segs.forEach(function (s) { s.addEventListener('mouseenter', function () { hot(s.getAttribute('data-i')); }); s.addEventListener('mouseleave', function () { hot(null); }); });
    rows.forEach(function (r) { r.addEventListener('mouseenter', function () { hot(r.getAttribute('data-i')); }); r.addEventListener('mouseleave', function () { hot(null); }); });
  }

  function contactSourcesCard() {
    var r = state.data.report || {}, rows = (r.contacts_by_source || []).slice(0, 7);
    if (!rows.length) return card('c7', 'What brought in the calls and leads', 'Calls, taps and forms by where the visitor came from', '<p class="dd-empty">No calls, taps or forms yet.</p>');
    var visitsBy = {};
    (r.sources || []).forEach(function (s) { visitsBy[s.source + '|' + s.medium] = +s.visits; });
    var max = Math.max.apply(null, rows.map(function (x) { return +x.contacts; }));
    var html = rows.map(function (x, i) {
      var v = visitsBy[x.source + '|' + x.medium], per = v ? (100 * x.contacts / v) : null;
      return '<div><div class="dd-bar-top"><span>' + esc(x.name) + '</span><span><b class="dd-n">' + n(x.contacts) + '</b>' + (per !== null ? ' <span class="dd-muted">· ' + (per >= 10 ? Math.round(per) : per.toFixed(1)) + ' per 100 visits</span>' : '') + '</span></div>' +
        '<div class="dd-bar"><i style="width:' + (100 * x.contacts / max) + '%;background:' + sourceColor(x.source) + ';animation-delay:' + (0.15 + i * 0.07) + 's"></i></div></div>';
    }).join('');
    return card('c7', 'What brought in the calls and leads', 'Calls, taps and forms by where the visitor came from, and how often each source’s visits got in touch', '<div class="dd-bars">' + html + '</div>');
  }

  /* ---- Map ---- */
  function mapCard() {
    var r = state.data.report || {};
    if (!r.points) return card('c7', 'Where visitors are', '', '<p class="dd-empty">The map started with Deft Core 0.4.1, so it isn’t available for this month.</p>');
    var pts = r.points.filter(function (p) { return isFinite(p.lat) && isFinite(p.lon); });
    if (!pts.length) return card('c7', 'Where visitors are', 'Approximate, by ZIP area', '<div class="dd-map"><p class="dd-empty" style="position:relative;padding-top:22%">No located US visits yet.</p></div>');

    // Frame the busiest 90% of visits around the main area (within about 300 miles of the middle visit), so a few
    // far-away visitors or data centers don't shrink the map. They're counted below the map instead.
    var total = sum(pts, 'visits');
    function median(key) {
      var s = pts.slice().sort(function (a, b) { return a[key] - b[key]; }), acc = 0;
      for (var i = 0; i < s.length; i++) { acc += s[i].visits; if (acc >= total / 2) return s[i][key]; }
      return s[s.length - 1][key];
    }
    var mLat = median('lat'), mLon = median('lon');
    var near = pts.filter(function (p) { return Math.abs(p.lat - mLat) <= 4.5 && Math.abs(p.lon - mLon) <= 5.5; });
    var nearTotal = sum(near, 'visits');
    function q(key, k) {
      var s = near.slice().sort(function (a, b) { return a[key] - b[key]; }), acc = 0;
      for (var i = 0; i < s.length; i++) { acc += s[i].visits; if (acc >= nearTotal * k) return s[i][key]; }
      return s[s.length - 1][key];
    }
    var la0 = q('lat', 0.05), la1 = q('lat', 0.95), lo0 = q('lon', 0.05), lo1 = q('lon', 0.95);
    var midLat = (la0 + la1) / 2, kx = Math.cos(midLat * Math.PI / 180);
    var W = 1000, H = 625;
    var spanX = Math.max((lo1 - lo0) * kx, 0.5), spanY = Math.max(la1 - la0, 0.5);
    if (spanX / spanY > W / H) spanY = spanX * H / W; else spanX = spanY * W / H;
    spanX *= 1.45; spanY *= 1.45;
    var cx = (lo0 + lo1) / 2 * kx, cy = midLat;
    var P = function (p) { return [W / 2 + (p.lon * kx - cx) / spanX * W, H / 2 - (p.lat - cy) / spanY * H]; };
    var max = Math.max.apply(null, pts.map(function (p) { return p.visits; }));
    var inside = 0, dots = '', halos = '', pulses = '';
    pts.slice().sort(function (a, b) { return a.visits - b.visits; }).forEach(function (p, i) {
      var xy = P(p);
      if (xy[0] < -10 || xy[0] > W + 10 || xy[1] < -10 || xy[1] > H + 10) return;
      inside += p.visits;
      var rad = 3.5 + 11 * Math.sqrt(p.visits / max);
      halos += '<circle class="halo" cx="' + xy[0].toFixed(1) + '" cy="' + xy[1].toFixed(1) + '" r="' + (rad * 2.2).toFixed(1) + '"/>';
      dots += '<circle class="pt" style="animation-delay:' + (0.2 + i * 0.015).toFixed(3) + 's" cx="' + xy[0].toFixed(1) + '" cy="' + xy[1].toFixed(1) + '" r="' + rad.toFixed(1) + '" data-tip="' + esc('<b>' + esc(p.city || 'Unknown city') + '</b> · ' + n(p.visits) + ' visit' + (p.visits === 1 ? '' : 's') + '<span class="dd-muted">' + esc(p.county) + (p.zip ? ' · around ZIP ' + esc(p.zip) : '') + '</span>') + '"/>';
    });
    pts.slice().sort(function (a, b) { return b.visits - a.visits; }).slice(0, 3).forEach(function (p) {
      var xy = P(p);
      pulses += '<circle class="pulse" cx="' + xy[0].toFixed(1) + '" cy="' + xy[1].toFixed(1) + '" r="' + (3.5 + 11 * Math.sqrt(p.visits / max)).toFixed(1) + '"/>';
    });
    // Label the biggest cities (visits summed per city, placed at their visit-weighted center).
    var cities = {};
    pts.forEach(function (p) {
      var k = p.city || p.zip, c = cities[k] || (cities[k] = { name: p.city, v: 0, lat: 0, lon: 0 });
      c.v += p.visits; c.lat += p.lat * p.visits; c.lon += p.lon * p.visits;
    });
    var placed = [];
    // Labels are fixed-size text, so collisions are checked in screen pixels converted to map units.
    var mapPx = Math.max(280, Math.min(root.clientWidth, 1480) * (window.innerWidth > 1180 ? 0.55 : 0.88) - 60), u = W / mapPx;
    var labelCap = mapPx < 500 ? 3 : 5;
    var labels = Object.keys(cities).map(function (k) { var c = cities[k]; return { name: c.name, v: c.v, lat: c.lat / c.v, lon: c.lon / c.v }; })
      .sort(function (a, b) { return b.v - a.v; }).filter(function (c) {
        if (!c.name || placed.length >= labelCap) return false;
        var xy = P(c);
        if (xy[0] < 0 || xy[0] > W - 150 * u || xy[1] < 22 * u || xy[1] > H - 22 * u) return false;
        if (placed.some(function (o) { return Math.abs(o[0] - xy[0]) < 160 * u && Math.abs(o[1] - xy[1]) < 40 * u; })) return false;
        placed.push(xy); c.xy = xy; return true;
      }).map(function (c) {
        return '<div class="dd-map-label" style="left:' + (c.xy[0] / W * 100) + '%;top:' + (c.xy[1] / H * 100) + '%">' + esc(c.name) + '<small>' + n(c.v) + ' visits</small></div>';
      }).join('');
    var land = (state.data.land || []).map(function (ll) {
      var xy = P({ lat: ll[0], lon: ll[1] });
      return xy[0] < -4 || xy[0] > W + 4 || xy[1] < -4 || xy[1] > H + 4 ? '' : '<circle cx="' + xy[0].toFixed(1) + '" cy="' + xy[1].toFixed(1) + '" r="3"/>';
    }).join('');
    var far = total - inside;
    return card('c7', 'Where visitors are', isGA(r) ? 'US visits by city, from Google Analytics. Bigger dots, more visits.' : 'Approximate, by ZIP area. Bigger dots, more visits.',
      '<div class="dd-map' + (land ? ' has-land' : '') + '"><svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Map of approximate visitor locations"><g class="land">' + land + '</g><g>' + halos + '</g><g>' + pulses + '</g><g>' + dots + '</g></svg>' + labels + '</div>' +
      '<div class="dd-map-foot"><span>' + n(r.us_located) + ' of ' + n(r.visits) + ' visits located in the US' + (r.located > r.us_located ? ', ' + n(r.located - r.us_located) + ' from other countries' : '') + '</span>' + (far > 0 ? '<span>' + n(far) + ' farther away, off this view</span>' : '') + '</div>');
  }

  function areaNames() {
    var strip = function (s) { return String(s).toLowerCase().split(',')[0].trim().replace(/\s+(county|parish|borough)$/, '').trim(); };
    return { list: String((state.data.report || {}).service_area || '').split(',').map(strip).filter(Boolean), strip: strip };
  }

  function countiesCard() {
    var r = state.data.report || {}, rows = (r.counties || []).slice(0, 8), a = areaNames();
    var meter = '';
    if (r.outside_area !== null && r.outside_area !== undefined && r.us_located) {
      var insidePct = 100 - pct(r.outside_area, r.us_located), C = 2 * Math.PI * 26;
      meter = '<div class="dd-area-meter"><div class="dd-ring"><svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="26" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="7"/><circle cx="32" cy="32" r="26" fill="none" stroke="var(--sun)" stroke-width="7" stroke-linecap="round" stroke-dasharray="' + (C * insidePct / 100).toFixed(1) + ' ' + C.toFixed(1) + '"/></svg><b>' + insidePct + '%</b></div>' +
        '<div><b style="color:#fff">' + insidePct + '% inside your service area</b><div class="dd-sub">' + esc(r.service_area) + ' · ' + n(r.outside_area) + ' located visits came from outside it</div></div></div>';
    } else if (!r.service_area) {
      meter = '<div class="dd-sub" style="margin-bottom:12px">Add your service area in <a href="' + esc(D.settings) + '">Settings &gt; Deft</a> to see how many visits come from outside it.</div>';
    }
    if (!rows.length) return card('c5', 'Top counties', '', meter + '<p class="dd-empty">No located US visits yet.</p>');
    var max = +rows[0].visits;
    var list = rows.map(function (c, i) {
      var inArea = a.list.indexOf(a.strip(c.county)) !== -1;
      return '<div><div class="dd-bar-top"><span>' + esc(c.county) + (inArea ? '<span class="dd-tag">in area</span>' : '') + '</span><span><b class="dd-n">' + n(c.visits) + '</b> <span class="dd-muted">' + pct(c.visits, r.us_located) + '%</span></span></div>' +
        '<div class="dd-bar"><i style="width:' + (100 * c.visits / max) + '%;background:linear-gradient(90deg,rgba(255,199,0,.45),var(--sun));animation-delay:' + (0.15 + i * 0.06) + 's"></i></div></div>';
    }).join('');
    return card('c5', 'Top counties', 'Share of located US visits', meter + '<div class="dd-bars" style="gap:11px">' + list + '</div>');
  }

  /* ---- Best times ---- */
  function heatCard(cls) {
    var r = state.data.report || {};
    if (!r.times) return card(cls, 'Best times', '', '<p class="dd-empty">Best times started with Deft Core 0.4.1, so they aren’t available for this month.</p>');
    if (!r.times.contacts) return card(cls, 'Best times', 'When people visit, in the site’s time zone', '<div class="dd-heat-slot"></div>');
    return card(cls, 'Best times', 'When people visit and get in touch, in the site’s time zone', '<div class="dd-heat-slot"></div>',
      '<div class="dd-toggle" role="group" aria-label="Show"><button type="button" data-heat="visits">Visits</button><button type="button" data-heat="contacts">Got in touch</button></div>');
  }

  function drawHeat(scope) {
    var slot = scope.querySelector('.dd-heat-slot');
    if (!slot) return;
    if (!state.data.report.times[state.heat]) state.heat = 'visits';
    var grid = state.data.report.times[state.heat] || [], max = 0, best = null, week = D.week || 0;
    var unit = state.heat === 'visits' ? (isGA(state.data.report) ? ' visits' : ' page views') : ' calls, taps and forms';
    var names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var full = ['Sundays', 'Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays'];
    grid.forEach(function (row) { row.forEach(function (v) { max = Math.max(max, v); }); });
    var hr = function (h) { return h === 0 ? '12 am' : h < 12 ? h + ' am' : h === 12 ? '12 pm' : (h - 12) + ' pm'; };
    var html = '<div class="dd-heat"><span></span>';
    for (var h = 0; h < 24; h++) html += '<span class="hr">' + (h % 6 === 0 ? hr(h).replace(' ', '') : '') + '</span>';
    for (var k = 0; k < 7; k++) {
      var d = (k + week) % 7;
      html += '<span class="day">' + names[d] + '</span>';
      for (h = 0; h < 24; h++) {
        var v = (grid[d] || [])[h] || 0, a = max ? 0.1 + 0.9 * Math.pow(v / max, 0.75) : 0;
        html += '<span class="cell" style="' + (v ? 'background:rgba(255,199,0,' + a.toFixed(3) + ')' : '') + '" data-tip="' + esc('<b>' + full[d] + ', ' + hr(h) + '–' + hr((h + 1) % 24) + '</b><br>' + n(v) + unit) + '"></span>';
      }
    }
    html += '</div><div class="dd-scale"><span>Fewer</span>' + [0.1, 0.35, 0.6, 0.8, 1].map(function (a) { return '<i style="background:rgba(255,199,0,' + a + ')"></i>'; }).join('') + '<span>More</span></div>';
    // Busiest two-hour window.
    for (var dd = 0; dd < 7; dd++) for (var hh = 0; hh < 23; hh++) {
      var two = ((grid[dd] || [])[hh] || 0) + ((grid[dd] || [])[hh + 1] || 0);
      if (!best || two > best.v) best = { v: two, d: dd, h: hh };
    }
    if (best && best.v) html += '<div class="dd-callout">Busiest: <b>' + full[best.d] + ', ' + hr(best.h) + ' to ' + hr(best.h + 2) + '</b>. ' + (state.heat === 'visits' ? 'A good time for posts and ads to go out.' : 'Make sure someone can pick up the phone then.') + '</div>';
    slot.innerHTML = html;
    bindTips(slot);
  }

  function wireHeat(scope) {
    var btns = scope.querySelectorAll('[data-heat]');
    btns.forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-heat') === state.heat ? 'true' : 'false');
      b.addEventListener('click', function () {
        state.heat = b.getAttribute('data-heat');
        btns.forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
        drawHeat(scope);
      });
    });
    drawHeat(scope);
  }

  /* ---- Funnel ---- */
  function funnelCard(f) {
    var r = state.data.report || {};
    var started = r.form_visits !== undefined ? +r.form_visits : +r.form_starts || 0;
    var steps = [
      ['Visits', f.visits, 'everyone who came to the site'],
      ['Started a form', started, 'typed into a form'],
      ['Sent a form', f.forms, 'became a lead in WordPress']
    ];
    var top = Math.max(1, f.visits);
    var html = steps.map(function (s, i) {
      var w = i ? Math.max(2, 100 * s[1] / top) : 100;
      var note = i ? (i === 2 && started ? pct(s[1], started) + '% of those who started' : pct(s[1], f.visits) + '% of visits') : s[2];
      return '<div class="dd-step"><div class="dd-step-fill" style="width:' + w + '%;animation-delay:' + (0.2 + i * 0.15) + 's"></div><div><span>' + esc(s[0]) + '<br><small>' + esc(note) + '</small></span><b>' + n(s[1]) + '</b></div></div>';
    }).join('');
    var extra = f.contactVisits !== null ? '<div class="dd-sub" style="margin-top:12px">Counting calls, texts and WhatsApp too, <b style="color:#fff">' + n(f.contactVisits) + ' visits</b> (' + pct(f.contactVisits, f.visits) + '%) got in touch.</div>' : '';
    return card('c5', 'From visit to lead', 'How many visits started and sent a form', '<div class="dd-funnel">' + html + '</div>' + extra);
  }

  /* ---- Sections ---- */
  function sectionsCard() {
    var pages = (state.data.report || {}).sections || [];
    if (!pages.length) return card('c12', 'How far people get on each page', '', '<p class="dd-empty">No section times yet. They appear once visitors scroll through pages.</p>');
    var tabs = pages.map(function (p, i) { return '<button type="button" class="dd-tab" data-page="' + i + '" aria-pressed="false">' + esc(p.path) + '<small>' + n(p.views) + '</small></button>'; }).join('');
    return card('c12', 'How far people get on each page', 'Reached: share of the page’s views where the section was on screen. Time: average seconds on screen.', '<div class="dd-tabs" role="group" aria-label="Page">' + tabs + '</div><div class="dd-sec-slot"></div>');
  }

  function drawSections(scope) {
    var slot = scope.querySelector('.dd-sec-slot');
    if (!slot) return;
    var page = state.data.report.sections[state.page], secs = page.sections || [];
    var longest = secs.reduce(function (a, s) { return !a || s.seconds > a.seconds ? s : a; }, null);
    var drop = null;
    for (var i = 1; i < secs.length; i++) {
      var dlt = secs[i - 1].reach - secs[i].reach;
      if (!drop || dlt > drop.d) drop = { d: dlt, from: secs[i - 1], to: secs[i] };
    }
    var rows = '<div class="dd-sec dd-sec-head"><span class="dd-sec-name">Section</span><span></span><span class="dd-sec-n">Reached</span><span class="dd-sec-t">Time</span></div>' + secs.map(function (s, i) {
      return '<div class="dd-sec"><span class="dd-sec-name" title="' + esc(s.section) + '">' + esc(s.section) + '</span><div class="dd-sec-bar"><i style="width:' + s.reach + '%;animation-delay:' + (i * 0.05) + 's"></i></div><span class="dd-sec-n">' + s.reach + '%</span><span class="dd-sec-t' + (s === longest && s.seconds > 0 ? ' long' : '') + '">' + s.seconds + 's</span></div>';
    }).join('');
    var ins = '';
    if (drop && drop.d >= 10) ins += '<div class="dd-insight">Biggest drop: <b>' + drop.d + '%</b> of views stop between <b>' + esc(drop.from.section) + '</b> and <b>' + esc(drop.to.section) + '</b>' + end(drop.to.section) + '</div>';
    if (longest && longest.seconds > 0) ins += '<div class="dd-insight">Read the longest: <b>' + esc(longest.section) + '</b>, about <b>' + longest.seconds + ' seconds</b> on screen.</div>';
    if (secs.length) ins += '<div class="dd-insight"><b>' + secs[secs.length - 1].reach + '%</b> of views reach the last section, <b>' + esc(secs[secs.length - 1].section) + '</b>' + end(secs[secs.length - 1].section) + '</div>';
    slot.innerHTML = '<div class="dd-secs">' + rows + '</div>' + (ins ? '<div class="dd-insights">' + ins + '</div>' : '');
  }

  function wireSections(scope) {
    var tabs = scope.querySelectorAll('[data-page]');
    tabs.forEach(function (t) {
      t.setAttribute('aria-pressed', +t.getAttribute('data-page') === state.page ? 'true' : 'false');
      t.addEventListener('click', function () {
        state.page = +t.getAttribute('data-page');
        tabs.forEach(function (o) { o.setAttribute('aria-pressed', o === t ? 'true' : 'false'); });
        drawSections(scope);
      });
    });
    drawSections(scope);
  }

  /* ---- Leads, pages, campaigns, devices ---- */
  function leadsCard() {
    var L = state.data.leads || {}, list = L.list || [];
    var body = list.length ? '<div class="dd-leads">' + list.map(function (l) {
      var initial = (String(l.title || '?').trim()[0] || '?').toUpperCase();
      var sub = [l.trade, l.source].filter(Boolean).join(' · ');
      return '<a class="dd-lead" href="' + esc(l.link) + '"><span class="dd-av">' + esc(initial) + '</span><span style="min-width:0"><div class="dd-lead-t">' + esc(l.title) + '</div><div class="dd-lead-s">' + esc(sub) + '</div></span><span class="dd-lead-w">' + esc(l.when) + (l.sync ? '<br>' + esc(l.sync) : '') + '</span></a>';
    }).join('') + '</div>' : '<p class="dd-empty">No form leads ' + (state.data.live ? 'yet this month' : 'this month') + '.</p>';
    var more = L.all ? '<a href="' + esc(L.all) + '" class="dd-btn">All leads' + (L.total > list.length ? ' (' + n(L.total) + ' this month)' : '') + '</a>' : '';
    return card('c7', 'Latest leads', 'Form sends saved in WordPress', body, more);
  }

  function pagesCard() {
    var r = state.data.report || {};
    var pages = (r.pages || []).slice(0, 6);
    var html = pages.length ? '<ul class="dd-rows">' + pages.map(function (p) {
      return '<li><span class="dd-path" title="' + esc(p.path) + '">' + esc(p.path) + '</span><span><b class="dd-n">' + n(p.views) + '</b> <span class="dd-muted">views</span></span></li>';
    }).join('') + '</ul>' : '<p class="dd-empty">No page views yet.</p>';

    var dev = r.devices || [], dt = sum(dev, 'visits');
    var DEV = { phone: 'var(--s2)', desktop: 'var(--s3)', tablet: 'var(--s5)' };
    if (dt) {
      html += '<div class="dd-label dd-subhead">Devices</div><div class="dd-stack">' + dev.map(function (d) {
        return '<i style="width:' + (100 * d.visits / dt) + '%;background:' + (DEV[d.device] || 'var(--s-other)') + '" data-tip="' + esc('<b>' + esc(d.name) + '</b> · ' + n(d.visits) + ' visits (' + pct(d.visits, dt) + '%)') + '"></i>';
      }).join('') + '</div><div class="dd-legend">' + dev.map(function (d) {
        return '<span><i class="dd-sw" style="background:' + (DEV[d.device] || 'var(--s-other)') + '"></i>' + esc(d.name) + ' ' + pct(d.visits, dt) + '%</span>';
      }).join('') + '</div>';
    }
    var camps = (r.campaigns || []).slice(0, 5);
    if (camps.length) {
      html += '<div class="dd-label dd-subhead">Ad campaigns</div><ul class="dd-rows">' + camps.map(function (c) {
        return '<li><span><span class="dd-sw" style="display:inline-block;margin-right:8px;background:' + sourceColor(c.source) + '"></span>' + esc(c.campaign) + ' <span class="dd-muted">· ' + esc(c.name) + '</span></span><b class="dd-n">' + n(c.visits) + '</b></li>';
      }).join('') + '</ul>';
    }
    return card('c5', 'Top pages', 'Most viewed this month', html);
  }

  /* ---- Google Analytics months ---- */
  function gaBanner() {
    var r = state.data.report || {};
    var when = r.imported ? new Date(r.imported.replace(' ', 'T') + 'Z').toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
    return '<div class="dd-banner dd-banner-ga">' + icon('chart') + '<div><b>From Google Analytics.</b> This month is from before Deft Core started counting, so its numbers were copied from Google Analytics' + (when ? ' on ' + esc(when) : '') + '. Visits are Google Analytics sessions, and they include some bots and data centers. Calls, taps and form leads weren’t counted then.</div></div>';
  }

  // This month before Deft Core started counting: those days come from Google Analytics.
  function mixedBanner() {
    var d = state.data, r = d.report || {};
    var day = function (s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); };
    var through = r.ga_through ? day(r.ga_through) : '';
    var first = day(d.month + '-01');
    var range = through === first ? first : first + '–' + through;
    var own = (r.visits || 0) - (r.ga_visits || 0);
    return '<div class="dd-banner dd-banner-ga">' + icon('chart') + '<div><b>' + esc(range) + ' from Google Analytics.</b> ' +
      (own > 0
        ? 'Deft Core started counting after that, so those days (' + n(r.ga_visits) + ' visits) were copied from Google Analytics and the rest are Deft Core’s own numbers.'
        : 'Deft Core hasn’t counted any visits this month yet, so the days so far (' + n(r.ga_visits) + ' visits) come from Google Analytics. Deft Core’s own numbers are added from the day it starts counting.') +
      ' Google Analytics visits include some bots and data centers, and it didn’t count calls, taps or form leads.</div></div>';
  }

  function searchCard(q, cls) {
    if (arguments.length === 0) q = (state.data.report || {}).search;
    cls = cls || 'c4';
    if (!q) return '<section class="dd-card ' + cls + '"><div class="dd-label">Google search</div><p class="dd-empty">Search Console wasn’t connected in Site Kit when this month was synced.</p></section>';
    var ctr = q.impressions ? 100 * q.clicks / q.impressions : 0;
    var rows = (q.queries || []).slice(0, 6).map(function (x) {
      return '<li><span>' + esc(x.query) + '</span><span><b class="dd-n">' + n(x.impressions) + '</b> <span class="dd-muted">seen · ' + n(x.clicks) + (x.clicks === 1 ? ' click' : ' clicks') + '</span></span></li>';
    }).join('');
    return '<section class="dd-card dd-contacts ' + cls + '" aria-label="Google search">' +
      '<div class="dd-label">Seen in Google search</div><div class="dd-big">' + count(q.impressions) + '</div>' +
      '<div style="display:flex;gap:8px;align-items:center;margin-top:10px"><span class="dd-chip">' + n(q.clicks) + ' clicks</span><span class="dd-vs">' + (ctr >= 10 ? Math.round(ctr) : ctr.toFixed(1)) + '% of people who saw the site in search clicked</span></div>' +
      (rows ? '<div class="dd-label dd-subhead">Top searches</div><ul class="dd-rows">' + rows + '</ul>' : '') + '</section>';
  }

  function gaTiles(f) {
    var r = state.data.report || {}, p = state.data.prev || {}, q = r.search;
    var same = isGA(p);
    return kpi('Page views', f.views, change(f.views, f.prev.views), '', null) +
      kpi('Visitors', r.users || 0, same ? change(r.users || 0, p.users || 0) : null, 'People, counted by Google Analytics', null) +
      '<section class="dd-card dd-kpi c3"><div class="dd-label">Pages per visit</div><div class="dd-kpi-n">' + (f.visits ? (f.views / f.visits).toFixed(2) : '0') + '</div><div class="dd-kpi-row"><span class="dd-vs">Page views ÷ visits</span></div></section>' +
      kpi('Search clicks', q ? q.clicks : 0, same && p.search ? change(q ? q.clicks : 0, p.search.clicks) : null, q ? 'Clicks from Google search' : 'Search Console not connected', null);
  }

  function countriesCard(rep) {
    var r = rep || state.data.report || {}, rows = (r.countries || []).slice(0, 8);
    if (!rows.length) return card('c7', 'Countries', '', '<p class="dd-empty">No located visits.</p>');
    var max = +rows[0].visits;
    var html = rows.map(function (c, i) {
      return '<div><div class="dd-bar-top"><span>' + esc(regionName(c.country)) + '</span><span><b class="dd-n">' + n(c.visits) + '</b> <span class="dd-muted">' + pct(c.visits, r.located) + '%</span></span></div>' +
        '<div class="dd-bar"><i style="width:' + (100 * c.visits / max) + '%;background:' + (c.country === 'US' ? 'linear-gradient(90deg,rgba(255,199,0,.45),var(--sun))' : 'var(--s-other)') + ';animation-delay:' + (0.15 + i * 0.06) + 's"></i></div></div>';
    }).join('');
    return card('c7', 'Countries', 'Share of located visits. Visits from far away are often bots and data centers.', '<div class="dd-bars" style="gap:11px">' + html + '</div>');
  }

  // Months with our own numbers: Google Analytics' totals for the same month, below ours.
  function gaSection() {
    var d = state.data, G = d.ga;
    if (!G || !G.report) return '';
    var g = G.report, p = G.prev || {}, q = g.search, pq = p.search;
    var cmp = function (v, pv) { return d.live || pv === undefined || pv === null ? null : change(v, pv); };
    var last = function (pv) { return d.live && pv !== undefined && pv !== null ? 'Last month ' + n(pv) : ''; };
    var when = g.imported ? new Date(g.imported.replace(' ', 'T') + 'Z').toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '';
    return '<div class="dd-section"><h2 class="dd-section-title">' + icon('chart') + 'Google Analytics</h2>' +
      '<p class="dd-sub">The same ' + (d.live ? 'month so far' : 'month') + ', counted by Google Analytics' + (when ? ' (synced ' + esc(when) + ')' : '') + '. It counts sessions and uses cookies, and it includes some bots, so its numbers usually differ from Deft Core’s above.</p></div>' +
      '<div class="dd-grid">' +
        kpi('Sessions', +g.visits || 0, cmp(+g.visits || 0, p.visits), last(p.visits) || 'Deft Core counted ' + n(d.report.visits || 0) + ' visits', null) +
        kpi('Visitors', +g.users || 0, cmp(+g.users || 0, p.users), last(p.users) || 'People, counted by Google Analytics', null) +
        kpi('Search clicks', q ? q.clicks : 0, q && pq ? cmp(q.clicks, pq.clicks) : null, q ? (last(pq ? pq.clicks : null) || 'Clicks from Google search') : 'Search Console not connected', null) +
        '<section class="dd-card dd-kpi c3"><div class="dd-label">Pages per session</div><div class="dd-kpi-n">' + (+g.visits ? (g.views / g.visits).toFixed(2) : '0') + '</div><div class="dd-kpi-row"><span class="dd-vs">' + n(g.views) + ' page views</span></div></section>' +
        searchCard(q || null, 'c5') + countriesCard(g) +
      '</div>';
  }

  function card(cls, title, sub, body, side) {
    return '<section class="dd-card ' + cls + '"><div class="dd-head"><div><h2 class="dd-title">' + esc(title) + '</h2>' + (sub ? '<div class="dd-sub">' + esc(sub) + '</div>' : '') + '</div>' + (side || '') + '</div>' + body + '</section>';
  }

  shell();
  render();
})();
