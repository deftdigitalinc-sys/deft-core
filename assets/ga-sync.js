/* Deft Core: the "Sync Google Analytics" buttons. Asks which months need syncing, then syncs them one at a time. */
(function () {
  'use strict';
  var C = window.deftGaSync;
  if (!C) return;

  function post(body) {
    return fetch(C.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j && j.message ? j.message : 'Error ' + r.status); return j; }); });
  }

  function sync(btn) {
    var status = document.querySelector('[data-deft-ga-status]') || btn;
    var label = btn.textContent;
    var say = function (t) { if (status === btn) btn.textContent = t; else status.textContent = t; };
    btn.disabled = true;
    say('Asking Google Analytics…');
    post({}).then(function (res) {
      var months = Object.keys(res.months || {}), done = 0, failed = [];
      if (!months.length) { say('Already up to date.'); btn.disabled = false; return; }
      return months.reduce(function (chain, m) {
        return chain.then(function () {
          say('Syncing ' + m + ' (' + (done + 1) + ' / ' + months.length + ')…');
          return post({ month: m }).then(function () { done++; }, function (e) { done++; failed.push(m + ': ' + e.message); });
        });
      }, Promise.resolve()).then(function () {
        if (btn.hasAttribute('data-deft-ga-reload') && !failed.length) { window.location.reload(); return; }
        say((months.length - failed.length) + ' / ' + months.length + ' months synced.' + (failed.length ? ' Problems: ' + failed.join('; ') : ''));
        if (status !== btn) {
          btn.textContent = label;
          var a = document.createElement('a');
          a.href = C.report; a.textContent = ' Open the Site report';
          status.appendChild(a);
        }
        btn.disabled = false;
      });
    }).catch(function (e) { say(e.message); btn.disabled = false; if (status !== btn) btn.textContent = label; });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-deft-ga-sync]');
    if (btn && !btn.disabled) { e.preventDefault(); sync(btn); }
  });
})();
