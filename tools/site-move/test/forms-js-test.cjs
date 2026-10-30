// node tools/site-move/test/forms-js-test.cjs  (uses the preinstalled Playwright Chromium)
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const js = fs.readFileSync(path.join(__dirname, '..', 'deft-forms.js'), 'utf8');
const page = `<!doctype html><html><head><script>${js}</script></head><body>
<form class="preview-form" data-deft-form="contact" data-deft-labels='{"address":"Address"}' data-deft-phone="tel:+15615551234">
<input name="first_name" value="Ana"><input name="last_name" value="Ruiz"><input name="phone" type="tel" value="5615550000">
<input name="address" value="1 Main"><label><input type="checkbox" name="rooms" value="Bath" checked></label><label><input type="checkbox" name="rooms" value="Kitchen" checked></label>
<input name="website_url"><button type="submit">Send</button><p class="form-status" hidden></p></form>
<script>document.querySelectorAll('form.preview-form').forEach(f=>f.addEventListener('submit',e=>{e.preventDefault();f.querySelector('.form-status').textContent='Preview only'}))</script>
</body></html>`;
(async () => {
  const browser = await chromium.launch();
  const p = await browser.newPage();
  let sent = null, fail = 0;
  await p.route('https://acme.test/', r => r.fulfill({ contentType: 'text/html', body: page }));
  await p.route('https://acme.test/wp-json/deft/v1/forms/contact', r => {
    sent = JSON.parse(r.request().postData());
    r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, message: 'Thanks, we got it.' }) });
  });
  await p.goto('https://acme.test/');
  await p.click('button');
  await p.waitForSelector('.form-thanks');
  const ok = (c, m) => { if (!c) { fail++; console.log('FAIL', m); } };
  ok(sent && sent.name === 'Ana Ruiz', 'name built from first and last');
  ok(sent.phone === '5615550000' && sent.address === '1 Main', 'answers sent');
  ok(Array.isArray(sent.rooms) && sent.rooms.length === 2, 'checkboxes as a list');
  ok(sent._labels.address === 'Address' && sent._submission_id && sent._source.landing, 'meta fields');
  ok((await p.textContent('form')).includes('Thanks, we got it.'), 'thank-you shown');
  ok(!(await p.textContent('form')).includes('Preview only'), 'old preview handler never ran');
  // Error answer keeps the form and offers the phone.
  await p.route('https://acme.test/wp-json/deft/v1/forms/contact', r => r.fulfill({ status: 400, contentType: 'application/json', body: JSON.stringify({ ok: false, message: 'Please fill in the highlighted fields.', fields: ['name'] }) }));
  await p.goto('https://acme.test/');
  await p.fill('[name=first_name]', ''); await p.fill('[name=last_name]', '');
  await p.click('button');
  await p.waitForFunction(() => /highlighted/.test(document.querySelector('.form-status').textContent));
  ok(await p.getAttribute('[name=first_name]', 'aria-invalid') === 'true', 'missing name marked');
  ok(await p.$('.form-status a[href="tel:+15615551234"]'), 'phone offered on error');
  ok(!(await p.isDisabled('button')), 'button usable again');
  // A former Mailchimp signup also posts to the list under its old field names.
  const mcPage = page.replace('data-deft-form="contact"', 'data-deft-form="contact" data-deft-mailchimp="https://acme.us21.list-manage.com/subscribe/post?u=abc123&amp;id=def456" data-deft-mailchimp-fields=\'{"first_name":"FNAME","email":"EMAIL","rooms":"MMERGE5"}\'')
    .replace('<input name="address" value="1 Main">', '<input name="address" value="1 Main"><input name="email" value="ana@example.com">');
  let mc = null;
  await p.route('https://acme.test/', r => r.fulfill({ contentType: 'text/html', body: mcPage }));
  await p.route('https://acme.test/wp-json/deft/v1/forms/contact', r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, message: 'Thanks' }) }));
  await p.route('https://acme.us21.list-manage.com/**', r => { mc = r.request().postData(); r.fulfill({ body: 'ok' }); });
  await p.goto('https://acme.test/');
  await p.click('button');
  await p.waitForSelector('.form-thanks');
  await p.waitForTimeout(300);
  const q = new URLSearchParams(mc || '');
  ok(q.get('FNAME') === 'Ana' && q.get('EMAIL') === 'ana@example.com', 'Mailchimp gets the old field names');
  ok(q.getAll('MMERGE5').length === 2 && q.has('b_abc123_def456'), 'Mailchimp gets the group and its bot field');
  await browser.close();
  console.log(fail ? fail + ' failed' : 'all forms-js checks pass');
  process.exit(fail ? 1 : 0);
})();
