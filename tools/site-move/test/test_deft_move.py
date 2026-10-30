"""python3 -m unittest discover -s tools/site-move/test"""
import json, os, re, sys, tempfile, unittest, zipfile
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))
import deft_move

INDEX = '''<!doctype html><html><head><title>Home - Acme Glass</title><meta charset="utf-8">
<meta name="viewport" content="width=device-width"><meta property="og:title" content="x">
<link rel="stylesheet" href="assets/a.css"><link rel="canonical" href="https://x">
<script src="https://code.jquery.com/jquery.js"></script></head>
<body class="home elementor-page"><a href="about.html#team">About</a> <a href="/services/">Services</a>
<img src="assets/logo.png" srcset="assets/logo.png 1x, /assets/logo@2x.png 2x"><a href="tel:+15615551234">Call</a>
<div style="background:url('assets/logo.png')"></div>
<form action="#" class="elementor-form preview-form" aria-label="New Form" method="post">
<input type="hidden" name="form_id" value="1">
<label for="n">Name</label><input id="n" name="form_fields[name]">
<label for="e">Phone Number</label><input id="e" type="email" name="form_fields[email]" required>
<label for="s">I need an estimate for</label><select id="s" name="form_fields[field_1]"><option>Mirror</option></select>
<label for="a">Address</label><input id="a" name="form_fields[field_2]">
<label for="f">Photos</label><input id="f" type="file" name="form_fields[upload]">
<button type="submit">Send</button><p class="form-status" hidden></p></form>
<p class="form-preview-note">Preview form, delivery will be connected during upload.</p>
</body></html>'''
ABOUT = '''<html><head><title>About</title></head><body><form class="mc4wp-form"><p><label>First Name</label><input name="FNAME"></p>
<p><label>Last Name</label><input name="LNAME"></p><p><label>Email address: <input type="email" name="EMAIL"></label></p>
<div style="position: absolute; left: -5000px;" aria-hidden="true"><input type="text" name="b_123abc_456def" tabindex="-1"></div>
<textarea name="msg" placeholder="How can we help?"></textarea><input type="submit" value="Go">
<p class="note"><em>Preview form — sending will be connected when the upload method is confirmed.</em></p></form></body></html>'''
ABOUT_FOLDER = '<html><body><h1>About (folder copy)</h1></body></html>'
SERVICES = '<html><body><a href="../index.html">Home</a><a href="tel:5615550000">Call</a><form><input name="x" placeholder="Name"></form></body></html>'


class Base(unittest.TestCase):
    def build(self, call_only=False):
        self.tmp = tempfile.mkdtemp()
        src = os.path.join(self.tmp, 'src')
        for d in ('assets', 'services', 'about'):
            os.makedirs(os.path.join(src, d))
        for name, body in (('index.html', INDEX), ('about.html', ABOUT), ('services/index.html', SERVICES), ('about/index.html', ABOUT_FOLDER),
                           ('assets/a.css', 'body{background:url(/assets/logo.png)} i{background:url(logo.png)}'),
                           ('assets/logo.png', 'png'), ('assets/logo@2x.png', 'png'), ('shell.php', '<?php'), ('README.md', 'x')):
            with open(os.path.join(src, name), 'w') as fh:
                fh.write(body)
        dest, zpath, warnings = deft_move.build(src, 'acmeglass.com', os.path.join(self.tmp, 'out'), call_only)
        self.dest, self.zip, self.warnings = dest, zpath, warnings
        with open(os.path.join(dest, 'manifest.json')) as fh:
            self.m = json.load(fh)
        self.pages = {p['path']: p for p in self.m['pages']}


class Convert(Base):
    def build_flat(self):
        os.remove(os.path.join(self.tmp, 'src', 'about', 'index.html'))
        dest, zpath, self.warnings = deft_move.build(os.path.join(self.tmp, 'src'), 'acmeglass.com', os.path.join(self.tmp, 'out2'))
        with open(os.path.join(dest, 'manifest.json')) as fh:
            self.pages = {p['path']: p for p in json.load(fh)['pages']}

    def setUp(self):
        self.build()

    def test_pages_and_addresses(self):
        self.assertEqual(sorted(self.pages), ['', 'about', 'services'])
        self.assertTrue(self.pages['']['front'])
        home = self.pages['']['html']
        self.assertIn('href="/about/#team"', home)
        self.assertIn('href="/services/"', home)
        self.assertIn('/wp-content/uploads/deft-site/assets/logo.png 1x, /wp-content/uploads/deft-site/assets/logo@2x.png 2x', home)
        self.assertIn("url('/wp-content/uploads/deft-site/assets/logo.png')", home)
        self.assertIn('href="/"', self.pages['services']['html'])

    def test_head(self):
        head = self.pages['']['head']
        self.assertIn('/wp-content/uploads/deft-site/assets/a.css', head)
        for gone in ('<title', 'viewport', 'og:title', 'canonical', 'jquery'):
            self.assertNotIn(gone, head)
        self.assertIn('deft-forms.js', head)
        self.assertIn('classList.add("home","elementor-page")', self.pages['']['html'])

    def test_form_fields_from_labels(self):
        html = self.pages['']['html']
        form = re.search(r'<form.*?</form>', html, re.S).group(0)
        self.assertIn('data-deft-form="contact"', form)
        self.assertNotIn('action=', form); self.assertNotIn('preview-form', form)
        self.assertRegex(form, r'name="phone"[^>]*type="tel"|type="tel"[^>]*name="phone"')
        self.assertIn('name="service"', form); self.assertIn('name="address"', form)
        self.assertNotIn('type="file"', form); self.assertNotIn('form_id', form)
        self.assertIn('name="website_url"', form)
        self.assertIn('data-deft-phone="tel:+15615551234"', form)
        self.assertNotIn('delivery will be connected', html)
        self.assertTrue(any('file upload' in w for w in self.warnings))

    def test_duplicate_page_files(self):
        self.assertIn('folder copy', self.pages['about']['html'])
        self.assertEqual(sum(1 for p in self.m['pages'] if p['path'] == 'about'), 1)
        self.assertTrue(any('Two rebuild files for /about/' in w for w in self.warnings))

    def test_mailchimp_form(self):
        self.build_flat()
        form = self.pages['about']['html']
        for n in ('first_name', 'last_name', 'email', 'message'):
            self.assertIn('name="%s"' % n, form)
        self.assertNotIn('b_123abc_456def', form)
        self.assertTrue(any('Mailchimp' in w for w in self.warnings))
        self.assertNotIn('sending will be connected', form)
        self.assertIn('name="email"', form)

    def test_missing_contact_field_warns(self):
        self.assertTrue(any('/services/' in w and 'no phone or email' in w for w in self.warnings))

    def test_assets(self):
        names = zipfile.ZipFile(self.zip).namelist()
        self.assertIn('site/assets/logo.png', names); self.assertIn('site/deft-forms.js', names)
        self.assertFalse([n for n in names if n.endswith(('.php', '.html', 'README.md'))])
        css = open(os.path.join(self.dest, 'site/assets/a.css')).read()
        self.assertIn('url(/wp-content/uploads/deft-site/assets/logo.png)', css)
        self.assertIn('url(logo.png)', css)
        self.assertTrue(any('shell.php' in w for w in self.warnings))


class CallOnly(Base):
    def test_forms_become_call_buttons(self):
        self.build(call_only=True)
        home = self.pages['']['html']
        self.assertNotIn('<form', home)
        self.assertIn('href="tel:+15615551234"', home)
        self.assertNotIn('deft-forms.js', self.pages['']['head'])


if __name__ == '__main__':
    unittest.main()
