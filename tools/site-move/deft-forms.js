/*
 * Deft forms: sends every <form data-deft-form> on the page to the site's Deft Core
 * (POST /wp-json/deft/v1/forms/contact) and shows the answer in the form's status line.
 * Put on client sites by deft-move. Contract: deft-brain studio/site-forms.md.
 */
(function () {
  'use strict';
  var shown = Date.now();
  var q = new URLSearchParams(location.search);
  var source = { landing: location.href, referrer: document.referrer };
  ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'].forEach(function (k) {
    if (q.get(k)) source[k] = q.get(k);
  });
  var ids = new WeakMap();
  function idFor(form) {
    if (!ids.has(form)) ids.set(form, window.crypto && crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2));
    return ids.get(form);
  }
  function statusOf(form) {
    var s = form.querySelector('.form-status');
    if (!s) { s = document.createElement('p'); s.className = 'form-status'; s.setAttribute('role', 'status'); form.appendChild(s); }
    s.hidden = false;
    return s;
  }
  function fail(form, text) {
    var s = statusOf(form), phone = form.getAttribute('data-deft-phone');
    s.textContent = text + ' ';
    if (phone) {
      var a = document.createElement('a');
      a.href = phone; a.textContent = 'Call ' + phone.replace(/^tel:(\+1)?/, '');
      s.appendChild(a);
    }
  }

  // Capture on window runs before any other submit handler on the page (old preview scripts included).
  window.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches('form[data-deft-form]')) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    if (form.reportValidity && !form.reportValidity()) return;
    var button = form.querySelector('[type=submit],button:not([type])');
    var labels = {};
    try { labels = JSON.parse(form.getAttribute('data-deft-labels') || '{}'); } catch (err) { labels = {}; }
    var body = { page: location.href, _elapsed: Date.now() - shown, _submission_id: idFor(form), _source: source, _labels: labels };
    new FormData(form).forEach(function (v, k) {
      if (typeof v !== 'string') return;
      if (k in body && k !== 'page' && k.charAt(0) !== '_') body[k] = [].concat(body[k], v); else body[k] = v;
    });
    if (!body.name) {
      body.name = [body.first_name, body.last_name].filter(Boolean).join(' ') || '(no name given)';
    }
    form.querySelectorAll('[aria-invalid]').forEach(function (el) { el.removeAttribute('aria-invalid'); });
    if (button) button.disabled = true;
    statusOf(form).textContent = 'Sending…';
    fetch('/wp-json/deft/v1/forms/contact', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body)
    })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (res) {
        if (res && res.ok) {
          var p = document.createElement('p');
          p.className = 'form-thanks';
          p.setAttribute('role', 'status');
          p.textContent = res.message || 'Thank you. We got your message and will be in touch soon.';
          form.replaceChildren(p);
          form.classList.add('is-sent');
          return;
        }
        (res && res.fields || []).forEach(function (n) {
          var el = form.elements[n] || (n === 'name' && form.elements.first_name);
          if (el && el.setAttribute) el.setAttribute('aria-invalid', 'true');
        });
        fail(form, (res && res.message) || 'That didn’t go through.');
        if (button) button.disabled = false;
      })
      .catch(function () {
        fail(form, 'That didn’t go through. Please call us instead.');
        if (button) button.disabled = false;
      });
  }, true);
})();
