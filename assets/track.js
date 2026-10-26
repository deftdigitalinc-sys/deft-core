/*
 * Deft Core visitor statistics. No cookies, no storage, nothing that identifies a person.
 * Sends: one "view" per page, taps on call / WhatsApp / email / directions links, the first time a form is touched,
 * and on leaving the page how long each section was on screen.
 * Sections: elements with data-deft-section="Name" if the page has any; otherwise the page's <section> elements and
 * Elementor top-level sections/containers, named by their heading.
 */
(function () {
  'use strict';
  var C = window.deftTrack;
  if (!C || !C.url || navigator.webdriver || !window.fetch) return;

  var path = location.pathname;
  var view = Math.random().toString(36).slice(2, 12);

  function send(data) {
    data.v = view;
    data.p = path;
    var body = JSON.stringify(data);
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(C.url, new Blob([body], { type: 'text/plain' }))) return;
    } catch { /* fall through */ }
    fetch(C.url, { method: 'POST', body: body, keepalive: true, headers: { 'Content-Type': 'text/plain' } }).catch(function () {});
  }

  /* ---- Page view ---- */
  var q = new URLSearchParams(location.search);
  var tags = {};
  ['utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'fbclid'].forEach(function (k) {
    if (q.get(k)) tags[k] = q.get(k).slice(0, 100);
  });
  send({ t: 'view', r: document.referrer.slice(0, 300), q: tags, w: window.innerWidth || 0 });

  /* ---- Taps ---- */
  function tapKind(href) {
    if (/^tel:/i.test(href)) return 'call';
    if (/^sms:/i.test(href)) return 'text';
    if (/^mailto:/i.test(href)) return 'email';
    if (/(wa\.me|whatsapp\.com|^whatsapp:)/i.test(href)) return 'whatsapp';
    if (/(google\.[a-z.]+\/maps|maps\.google\.|maps\.app\.goo\.gl|goo\.gl\/maps|maps\.apple\.com)/i.test(href)) return 'directions';
    return '';
  }
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    var kind = tapKind(a.getAttribute('href') || '');
    if (kind) send({ t: 'tap', l: kind });
  }, true);

  /* ---- Form starts (sends are counted by the server) ---- */
  var started = [];
  document.addEventListener('focusin', function (e) {
    var el = e.target;
    if (!el || !el.form || !/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || el.type === 'hidden') return;
    if (started.indexOf(el.form) !== -1) return;
    started.push(el.form);
    var f = el.form;
    send({ t: 'form_start', l: (f.getAttribute('data-deft-form') || f.getAttribute('aria-label') || f.id || f.getAttribute('name') || 'form').slice(0, 60) });
  });

  /* ---- Time on each section ---- */
  if (!window.IntersectionObserver) return;
  var nodes = Array.prototype.slice.call(document.querySelectorAll('[data-deft-section]'));
  if (!nodes.length) {
    var all = Array.prototype.slice.call(document.querySelectorAll('section, .elementor-top-section, .e-con.e-parent'));
    // Keep the innermost real sections: drop wrappers that contain other candidates, and slivers.
    nodes = all.filter(function (el) {
      for (var i = 0; i < all.length; i++) if (all[i] !== el && el.contains(all[i])) return false;
      return el.offsetHeight >= 120;
    });
  }
  if (!nodes.length || nodes.length > 40) return;

  function nameOf(el, i) {
    var n = el.getAttribute('data-deft-section');
    if (!n && el.getAttribute('aria-labelledby')) {
      var h = document.getElementById(el.getAttribute('aria-labelledby').split(' ')[0]);
      if (h) n = h.innerText || h.textContent;
    }
    if (!n) n = el.getAttribute('aria-label');
    if (!n) {
      var head = el.querySelector('h1, h2, h3');
      if (head) n = head.innerText || head.textContent;
    }
    if (!n) n = el.id;
    n = (n || '').replace(/\s+/g, ' ').trim();
    return (n || 'Section ' + (i + 1)).slice(0, 80);
  }

  var sections = nodes.map(function (el, i) { return { el: el, name: nameOf(el, i), ms: 0, since: 0, seen: false }; });
  var hidden = document.visibilityState === 'hidden';

  function start(s, now) { if (!s.since && !hidden) s.since = now; }
  function stop(s, now) { if (s.since) { s.ms += now - s.since; s.since = 0; } }

  var visible = [];
  var io = new IntersectionObserver(function (entries) {
    var now = Date.now();
    entries.forEach(function (en) {
      var s = sections[nodes.indexOf(en.target)];
      // "On screen": at least half the section shows, or it fills at least half the screen.
      var on = en.isIntersecting && (en.intersectionRatio >= 0.5 || en.intersectionRect.height >= window.innerHeight * 0.5);
      if (on) { s.seen = true; if (visible.indexOf(s) === -1) visible.push(s); start(s, now); }
      else { visible = visible.filter(function (x) { return x !== s; }); stop(s, now); }
    });
  }, { threshold: [0, 0.25, 0.5, 0.75, 1] });
  nodes.forEach(function (el) { io.observe(el); });

  function flush() {
    var now = Date.now();
    var out = [];
    sections.forEach(function (s) {
      stop(s, now);
      if (s.seen) out.push([s.name, Math.min(s.ms, 1800000)]);
      s.ms = 0;
      s.seen = false;
    });
    if (out.length) send({ t: 'sections', s: out });
    visible.forEach(function (s) { s.seen = true; });
  }

  document.addEventListener('visibilitychange', function () {
    var now = Date.now();
    if (document.visibilityState === 'hidden') {
      hidden = true;
      flush();
    } else {
      hidden = false;
      visible.forEach(function (s) { start(s, now); });
    }
  });
  window.addEventListener('pagehide', function () { if (!hidden) flush(); });
})();
