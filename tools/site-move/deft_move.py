#!/usr/bin/env python3
"""
Turns a rebuilt client site (a standalone static folder: index.html, other pages as page.html or page/index.html,
assets) into a bundle that deft-move.sh puts on the site's WordPress as Deft pages, on the same addresses.

    python3 deft_move.py SITE_FOLDER --domain example.com [--call-only] [--map estimate=free-estimate] [--out DIR]

What it does to each page:
  - keeps the <head> styles and scripts (with their paths moved under /wp-content/uploads/deft-site/), drops what
    WordPress and Deft Core already print (title, charset, viewport, canonical, robots, Open Graph)
  - moves every local file reference (src, href, srcset, url(...)) under the assets folder, and links between pages
    to the WordPress addresses (/about/, not about.html)
  - connects every form to Deft Core (POST /wp-json/deft/v1/forms/contact): fields are named from their labels
    (a field labelled "Phone Number" becomes phone, whatever its old name or type said), a honeypot and a status
    line are added, and deft-forms.js does the sending. On a call-only site each form is replaced by a Call button.

Output: OUT/<domain>/ with manifest.json, REPORT.md and site/ (the assets), plus OUT/<domain>.zip of the same.
Nothing here talks to a live site. Read REPORT.md before applying: every form and every guess is listed there.
"""

import argparse
import hashlib
import json
import os
import posixpath
import re
import shutil
import sys
import zipfile
from urllib.parse import unquote

from bs4 import BeautifulSoup, Comment, NavigableString

HERE = os.path.dirname(os.path.abspath(__file__))
ASSET_BASE = '/wp-content/uploads/deft-site/'

# Only plain web files go up. Anything a server could run (php, phtml, htaccess, cgi...) is refused and reported.
ASSET_EXT = {
    'css', 'js', 'mjs', 'map', 'json', 'txt', 'xml',
    'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
    'woff', 'woff2', 'ttf', 'otf', 'eot',
    'mp4', 'webm', 'ogv', 'mov', 'm4v', 'mp3', 'ogg', 'wav', 'vtt', 'pdf',
}
SKIP_DIRS = {'components', '.git', '__pycache__', 'node_modules', 'history', 'reference', 'references'}
SKIP_FILES = {'readme.md', 'build_standalone.py', '.ds_store', 'thumbs.db', 'desktop.ini'}

URL_ATTRS = ('src', 'href', 'poster', 'data-src', 'data-bg', 'data-background', 'data-lazy-src', 'action')
SRCSET_ATTRS = ('srcset', 'data-srcset', 'imagesrcset')
HEAD_DROP_META = re.compile(r'^(viewport|robots|generator|description|keywords|twitter:.*|msapplication.*|theme-color)$', re.I)

FIELD_SKIP_TYPES = {'submit', 'button', 'reset', 'image', 'hidden'}
PLUGIN_FORM_CLASSES = re.compile(r'^(preview-form|mc4wp-form.*|wpforms-.*|forminator-.*|wpcf7-form|elementor-form-.*-ajax|fluentform.*)$')


class Report:
    def __init__(self):
        self.lines, self.warnings = [], []

    def add(self, text):
        self.lines.append(text)

    def warn(self, text):
        self.warnings.append(text)


def slug(text, limit=40):
    s = re.sub(r'[^a-z0-9]+', '_', (text or '').lower()).strip('_')
    return s[:limit].strip('_')


def clean_text(text):
    return re.sub(r'\s+', ' ', text or '').strip().rstrip(':*').strip()


# ---------- Finding the pages ----------

def find_pages(root, mapping):
    """{rel_html_path: wp_path}. index.html is the front page (wp_path ''). about.html and about/index.html are 'about'."""
    pages = {}
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d.lower() not in SKIP_DIRS and not d.startswith('.') and d != 'assets']
        for name in filenames:
            if not name.lower().endswith('.html'):
                continue
            rel = os.path.relpath(os.path.join(dirpath, name), root).replace(os.sep, '/')
            if rel == 'index.html':
                path = ''
            elif name == 'index.html':
                path = rel[: -len('/index.html')]
            else:
                path = rel[: -len('.html')]
            if path in ('404', '404/index'):
                continue
            pages[rel] = mapping.get(path, path)
    return pages


# ---------- Paths ----------

def is_external(ref):
    r = ref.strip().lower()
    return (not r) or r.startswith(('#', 'http:', 'https:', '//', 'mailto:', 'tel:', 'sms:', 'data:', 'javascript:', 'blob:', 'whatsapp:'))


class Paths:
    def __init__(self, root, pages, report):
        self.root, self.report = root, report
        self.page_by_file = pages
        self.used, self.missing = set(), set()

    def resolve(self, ref, base_dir):
        """A local reference seen in a file under base_dir: the new URL, or None to leave it alone."""
        if is_external(ref):
            return None
        raw = ref.strip()
        frag = ''
        if '#' in raw:
            raw, frag = raw.split('#', 1)
            frag = '#' + frag
        query = ''
        if '?' in raw:
            raw, query = raw.split('?', 1)
            query = '?' + query
        if raw.startswith('/'):
            if raw.startswith(ASSET_BASE):
                return None
            target = posixpath.normpath(unquote(raw.lstrip('/')))
        else:
            target = posixpath.normpath(posixpath.join(base_dir, unquote(raw))) if raw else ''
        if target in ('.', ''):
            target = ''
        if target.startswith('..'):
            self.missing.add(ref)
            return None
        # A page?
        for cand in (target, posixpath.join(target, 'index.html') if target else 'index.html', target + '.html'):
            if cand in self.page_by_file:
                wp = self.page_by_file[cand]
                return ('/' + wp + '/' if wp else '/') + query + frag
        if raw == '' and frag:
            return None
        full = os.path.join(self.root, target)
        if os.path.isfile(full):
            self.used.add(target)
            return ASSET_BASE + target + query + frag
        self.missing.add(ref)
        return None

    def rewrite_srcset(self, value, base_dir):
        out = []
        for part in value.split(','):
            bits = part.strip().split()
            if not bits:
                continue
            new = self.resolve(bits[0], base_dir)
            out.append(' '.join([new or bits[0]] + bits[1:]))
        return ', '.join(out)

    def rewrite_css(self, css, base_dir, root_only=False):
        def fix(m):
            q, ref = m.group(1), m.group(2)
            if root_only and not ref.startswith('/'):
                return m.group(0)
            new = self.resolve(ref, base_dir)
            return 'url(' + q + (new or ref) + q + ')' if new else m.group(0)
        css = re.sub(r'url\(\s*([\'"]?)([^\'")]+)\1\s*\)', fix, css)

        def imp(m):
            new = self.resolve(m.group(2), base_dir)
            return '@import ' + m.group(1) + (new or m.group(2)) + m.group(1) if new and (not root_only or m.group(2).startswith('/')) else m.group(0)
        return re.sub(r'@import\s+([\'"])([^\'"]+)\1', imp, css)


def rewrite_refs(soup, paths, base_dir):
    for tag in soup.find_all(True):
        for attr in URL_ATTRS:
            if tag.has_attr(attr) and isinstance(tag[attr], str):
                if attr == 'action':
                    continue
                new = paths.resolve(tag[attr], base_dir)
                if new:
                    tag[attr] = new
        for attr in SRCSET_ATTRS:
            if tag.has_attr(attr):
                tag[attr] = paths.rewrite_srcset(tag[attr], base_dir)
        if tag.has_attr('style') and 'url(' in tag['style']:
            tag['style'] = paths.rewrite_css(tag['style'], base_dir)
    for style in soup.find_all('style'):
        if style.string:
            style.string.replace_with(paths.rewrite_css(style.string, base_dir))


# ---------- Forms ----------

def label_for(field, form):
    fid = field.get('id')
    if fid:
        lab = form.find('label', attrs={'for': fid})
        if lab:
            return clean_text(lab.get_text(' '))
    parent = field.find_parent('label')
    if parent:
        copy = BeautifulSoup(str(parent), 'html.parser')
        for x in copy.find_all(['input', 'select', 'textarea']):
            x.decompose()
        t = clean_text(copy.get_text(' '))
        if t:
            return t
    for attr in ('aria-label', 'placeholder', 'title'):
        if field.get(attr):
            return clean_text(field[attr])
    return ''


def group_label(field, form):
    """The question a radio or checkbox group answers: the fieldset legend, or the label of its field group."""
    fs = field.find_parent('fieldset')
    if fs and fs.find('legend'):
        return clean_text(fs.find('legend').get_text(' '))
    node = field.parent
    for _ in range(5):
        if node is None or node is form:
            break
        for lab in node.find_all('label', recursive=False) + node.find_all(class_=re.compile('field-label|label$')):
            if not lab.find(['input', 'select', 'textarea']):
                t = clean_text(lab.get_text(' '))
                if t:
                    return t
        node = node.parent
    return ''


def old_name(field):
    n = field.get('name') or ''
    m = re.match(r'^[a-z_-]*\[([^\]]+)\](\[\])?$', n, re.I)  # form_fields[name], wpforms[fields][1]
    if m:
        n = m.group(1)
    return n


def classify(label, field):
    """The Deft Core field this answers, from its label first, then its type and old name."""
    l = (label or '').lower()
    t = (field.get('type') or '').lower()
    n = old_name(field).lower()
    if field.name == 'textarea':
        if re.search(r'address|street', l):
            return None
        return 'message'
    if re.search(r'e-?mail|correo', l):
        return 'email'
    if re.search(r'phone|tel[eé]fono|cell|mobile|celular|\bnumber\b|whats ?app', l):
        return 'phone'
    if re.search(r'first name|nombre de pila|^first$', l):
        return 'first_name'
    if re.search(r'last name|apellido|^last$', l):
        return 'last_name'
    if re.search(r'^(your |full )?name$|^nombre( completo)?$|^name and|^your name', l):
        return 'name'
    if field.name == 'select' and re.search(r'service|interest|estimate|need|looking|project|help with|servicio', l):
        return 'service'
    if re.search(r'message|comment|details|tell us|how can we help|questions?|mensaje', l):
        return 'message'
    if not l:
        if t == 'email' or 'email' in n:
            return 'email'
        if t == 'tel' or 'phone' in n or n == 'tel':
            return 'phone'
        if n in ('name', 'fname', 'your-name', 'full_name'):
            return 'name' if n != 'fname' else 'first_name'
        if n in ('lname', 'last_name'):
            return 'last_name'
        if n in ('message', 'your-message', 'comments'):
            return 'message'
    return None


def convert_form(form, page_label, report, soup, phone_link, call_only):
    form_name = clean_text(form.get('aria-label') or form.get('name') or form.get('data-name') or '')
    where = '%s, form "%s"' % (page_label, form_name or 'unnamed')

    if call_only:
        a = soup.new_tag('a', href=phone_link or '#')
        a['class'] = 'deft-call-button'
        a.string = 'Call us' if not phone_link else 'Call ' + phone_link.replace('tel:', '').replace('+1', '')
        p = soup.new_tag('p')
        p['class'] = 'deft-call'
        p.append(a)
        form.replace_with(p)
        report.add('- %s: **replaced by a Call button** (call-only site)%s' % (where, '' if phone_link else ' **but no phone number was found on the page**'))
        if not phone_link:
            report.warn('%s: call-only, but the page has no tel: link for the Call button.' % where)
        return

    used, labels, rows = set(), {}, []
    groups_done = set()
    has = lambda k: k in used

    if any(re.match(r'mc4wp|mailchimp|mc-embedded', c) for c in (form.get('class') or [])) or 'list-manage.com' in (form.get('action') or ''):
        report.warn('%s: was a Mailchimp signup. It now sends a lead to Deft Core instead, so new people no longer join the Mailchimp list.' % where)
    for trap in form.find_all(['input', 'textarea']):
        n = (trap.get('name') or '').lower()
        hidden_box = trap.find_parent(attrs={'aria-hidden': 'true'}) or trap.find_parent(style=re.compile(r'left:\s*-\d{3,}|display:\s*none'))
        if 'honeypot' in n or n in ('website_url', 'b_email', 'hp') or re.match(r'^b_[0-9a-f]+_[0-9a-f]+$', n) or (hidden_box and trap.get('tabindex') == '-1'):
            (hidden_box if hidden_box and hidden_box is not form else trap).decompose()  # an old spam trap; ours is added below
    for lab in form.find_all('label'):
        if re.search(r"leave this field empty|if you'?re human", lab.get_text(' '), re.I) and not lab.find(['select']):
            lab.decompose()
    for hidden in form.find_all('input', attrs={'type': 'hidden'}):
        hidden.decompose()  # plugin tokens (nonces, form ids, referrers): meaningless to Deft Core
    for f in form.find_all('input', attrs={'type': 'file'}):
        rows.append('| %s | file upload | **removed** (Deft Core takes no files) |' % (label_for(f, form) or old_name(f)))
        report.warn('%s: had a file upload, which was removed. Ask for photos by text or email instead.' % where)
        wrap = f.find_parent(class_=re.compile('field-group|form-group|field'))
        (wrap or f).decompose()

    for field in form.find_all(['input', 'select', 'textarea']):
        ftype = (field.get('type') or 'text').lower()
        if field.name == 'input' and ftype in FIELD_SKIP_TYPES:
            continue
        if field.name == 'input' and ftype in ('checkbox', 'radio'):
            grp = field.get('name') or ''
            if grp in groups_done and grp:
                field['name'] = groups_done_names[grp]
                continue
            label = group_label(field, form) or old_name(field)
            key = slug(label) or 'choice'
            while key in used:
                key += '_2'
            used.add(key)
            labels[key] = label
            if grp:
                groups_done.add(grp)
                groups_done_names[grp] = key
            field['name'] = key
            rows.append('| %s | %s | `%s` |' % (label, ftype, key))
            continue
        label = label_for(field, form)
        kind = classify(label, field)
        if kind and has(kind):
            kind = None
        key = kind or slug(label) or slug(old_name(field)) or 'answer'
        while key in used:
            key += '_2'
        used.add(key)
        if key not in ('name', 'phone', 'email', 'service', 'message'):
            labels[key] = label or key.replace('_', ' ').capitalize()
        before = '%s %s' % (field.get('name', '(no name)'), ftype if field.name == 'input' else field.name)
        field['name'] = key
        if key == 'phone' and field.name == 'input':
            if ftype != 'tel':
                report.warn('%s: "%s" was a %s field; it is now a phone field.' % (where, label, ftype))
            field['type'] = 'tel'
            field['autocomplete'] = 'tel'
        elif key == 'email' and field.name == 'input':
            field['type'] = 'email'
            field['autocomplete'] = 'email'
        elif key in ('name', 'first_name') and field.name == 'input':
            field['autocomplete'] = 'name' if key == 'name' else 'given-name'
        elif key == 'last_name':
            field['autocomplete'] = 'family-name'
        rows.append('| %s | %s | `%s` |' % (label or '(no label)', before, key))

    if not (has('phone') or has('email')):
        report.warn('%s: no phone or email field, so Deft Core will refuse every send. Add one before going live.' % where)
    if not (has('name') or has('first_name')):
        report.warn('%s: no name field; leads will come in as "(no name given)".' % where)

    for attr in ('action', 'method', 'target', 'onsubmit', 'enctype', 'data-preview-form', 'novalidate'):
        if form.has_attr(attr):
            del form[attr]
    classes = [c for c in (form.get('class') or []) if not PLUGIN_FORM_CLASSES.match(c)]
    if classes:
        form['class'] = classes
    elif form.has_attr('class'):
        del form['class']
    fname = slug(form_name, 30)
    form['data-deft-form'] = 'contact' if not fname or re.match(r'^(new_)?form(_\d+)?$|contact_form$', fname) else fname
    if labels:
        form['data-deft-labels'] = json.dumps(labels, separators=(',', ':'))
    if phone_link:
        form['data-deft-phone'] = phone_link

    status = form.find(class_='form-status')
    if status is None:
        status = soup.new_tag('p')
        status['class'] = 'form-status'
        form.append(status)
    status['role'] = 'status'
    status['aria-live'] = 'polite'
    if status.has_attr('hidden'):
        del status['hidden']
    status.string = ''

    trap = BeautifulSoup('<div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">'
                         '<label>Leave this empty <input name="website_url" tabindex="-1" autocomplete="off"></label></div>', 'html.parser')
    form.append(trap)

    report.add('- %s: **connected to Deft Core**' % where)
    report.add('')
    report.add('  | Question | Was | Sends as |')
    report.add('  |---|---|---|')
    for r in rows:
        report.add('  ' + r)
    report.add('')


groups_done_names = {}


# ---------- Pages ----------

def convert_page(rel, wp_path, root, paths, report, call_only, forms_js_url):
    with open(os.path.join(root, rel), encoding='utf-8', errors='replace') as fh:
        soup = BeautifulSoup(fh.read(), 'html.parser')
    base_dir = posixpath.dirname(rel)
    label = '/' + wp_path + '/' if wp_path else 'home page'
    rewrite_refs(soup, paths, base_dir)

    title = clean_text(soup.title.get_text()) if soup.title else ''
    desc = soup.find('meta', attrs={'name': re.compile('^description$', re.I)})
    description = desc.get('content', '') if desc else ''

    head_parts = []
    head = soup.head
    if head:
        for el in head.children:
            if isinstance(el, Comment) or (isinstance(el, NavigableString) and not el.strip()):
                continue
            if isinstance(el, NavigableString):
                continue
            n = el.name
            if n in ('title', 'base'):
                continue
            if n == 'meta':
                if el.get('charset') or el.get('http-equiv') or HEAD_DROP_META.match(el.get('name', '')) or el.get('property', '').startswith(('og:', 'article:', 'fb:')):
                    continue
            if n == 'link' and (el.get('rel') or [''])[0].lower() in ('canonical', 'shortlink', 'alternate', 'pingback', 'edituri', 'wlwmanifest', 'profile', 'icon', 'shortcut', 'apple-touch-icon', 'manifest'):
                continue
            if n == 'script' and el.get('src') and re.search(r'jquery|elementor|wp-includes|wp-emoji', el['src']):
                report.warn('%s: head script %s looks like WordPress/Elementor and was dropped.' % (label, el['src']))
                continue
            head_parts.append(str(el))

    body = soup.body or soup
    phone = body.find('a', href=re.compile(r'^tel:', re.I))
    phone_link = phone['href'].strip() if phone else ''

    # The rebuilds carry "preview only / not sent" notices for review; they must not reach the live site.
    for note in body.find_all(class_=re.compile(r'preview-note|preview-notice|form-preview')):
        note.decompose()
    for node in body.find_all(string=re.compile(r'preview only|has not been sent|delivery will be connected|no request has been sent', re.I)):
        if node.parent and node.parent.name not in ('script', 'style'):
            report.warn('%s: a preview notice is still in the page: "%s". Remove it by hand.' % (label, clean_text(node)[:80]))

    forms = body.find_all('form')
    for form in forms:
        convert_form(form, label, report, soup, phone_link, call_only)

    for s in body.find_all('script', src=re.compile(r'jquery|elementor|wp-includes|wp-emoji')):
        report.warn('%s: body script %s looks like WordPress/Elementor and was dropped.' % (label, s['src']))
        s.decompose()

    inner = ''.join(str(c) for c in body.contents) if soup.body else str(soup)
    body_classes = ' '.join(soup.body.get('class') or []) if soup.body else ''
    if body_classes:
        inner = '<script>document.body.classList.add(%s)</script>\n' % ','.join(json.dumps(c) for c in body_classes.split()) + inner
    if forms and not call_only:
        head_parts.append('<script src="%s" defer></script>' % forms_js_url)

    return {
        'file': rel,
        'path': wp_path,
        'front': wp_path == '',
        'title': title.split(' | ')[0].split(' – ')[0].split(' - ')[0].strip() or (wp_path.split('/')[-1].replace('-', ' ').title() if wp_path else 'Home'),
        'full_title': title,
        'description': description,
        'head': '\n'.join(head_parts),
        'html': inner,
        'forms': len(forms),
    }


def copy_assets(root, outsite, paths, pages, report):
    copied, refused, size = [], [], 0
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d.lower() not in SKIP_DIRS and not d.startswith('.')]
        for name in filenames:
            rel = os.path.relpath(os.path.join(dirpath, name), root).replace(os.sep, '/')
            if rel in pages or name.lower() in SKIP_FILES or name.startswith('.'):
                continue
            ext = name.rsplit('.', 1)[-1].lower() if '.' in name else ''
            if name.lower().endswith('.html'):
                continue
            if ext not in ASSET_EXT:
                refused.append(rel)
                continue
            dest = os.path.join(outsite, rel)
            os.makedirs(os.path.dirname(dest), exist_ok=True)
            if ext == 'css':
                with open(os.path.join(root, rel), encoding='utf-8', errors='replace') as fh:
                    css = paths.rewrite_css(fh.read(), posixpath.dirname(rel), root_only=True)
                with open(dest, 'w', encoding='utf-8') as fh:
                    fh.write(css)
            elif ext in ('js', 'mjs'):
                with open(os.path.join(root, rel), encoding='utf-8', errors='replace') as fh:
                    js = fh.read()
                js = re.sub(r'([\'"`])/assets/', lambda m: m.group(1) + ASSET_BASE + 'assets/', js)
                js = re.sub(r'(document\.querySelectorAll\([\'"])form\.preview-form', r'\1form.deft-never-matches', js)
                with open(dest, 'w', encoding='utf-8') as fh:
                    fh.write(js)
            else:
                shutil.copyfile(os.path.join(root, rel), dest)
            copied.append(rel)
            size += os.path.getsize(dest)
    shutil.copyfile(os.path.join(HERE, 'deft-forms.js'), os.path.join(outsite, 'deft-forms.js'))
    if refused:
        report.warn('Not uploaded (not a plain web file): ' + ', '.join(sorted(refused)[:20]) + (' and %d more' % (len(refused) - 20) if len(refused) > 20 else ''))
    return copied, size


def build(root, domain, out, call_only=False, mapping=None):
    root = os.path.abspath(root)
    report = Report()
    global groups_done_names
    groups_done_names = {}
    pages = find_pages(root, mapping or {})
    if 'index.html' not in pages:
        raise SystemExit('No index.html in %s' % root)
    paths = Paths(root, pages, report)
    dest = os.path.join(out, domain)
    if os.path.isdir(dest):
        shutil.rmtree(dest)
    outsite = os.path.join(dest, 'site')
    os.makedirs(outsite)

    with open(os.path.join(HERE, 'deft-forms.js'), 'rb') as fh:
        forms_ver = hashlib.sha1(fh.read()).hexdigest()[:8]
    forms_js_url = ASSET_BASE + 'deft-forms.js?v=' + forms_ver

    page_report = Report()
    converted = []
    for rel in sorted(pages, key=lambda r: (pages[r] != '', pages[r])):
        converted.append(convert_page(rel, pages[rel], root, paths, page_report, call_only, forms_js_url))
    copied, size = copy_assets(root, outsite, paths, pages, report)

    for p in converted:
        if len(p['html'].encode()) + len(p['head'].encode()) > 3 * 1024 * 1024:
            report.warn('/%s/ is over 3 MB of HTML; Deft Core will refuse it. Move inline images to files.' % p['path'])

    manifest = {
        'domain': domain,
        'call_only': call_only,
        'asset_base': ASSET_BASE,
        'source': os.path.basename(root.rstrip('/')),
        'pages': converted,
        'tool': 'deft-move 1',
    }
    with open(os.path.join(dest, 'manifest.json'), 'w', encoding='utf-8') as fh:
        json.dump(manifest, fh, ensure_ascii=False)

    lines = ['# %s: move to Deft pages' % domain, '',
             'Source folder: `%s`. %s' % (manifest['source'], 'Call-only site: forms become Call buttons.' if call_only else 'Forms post to Deft Core.'), '',
             '## Pages', '', '| Rebuild file | WordPress address | Forms |', '|---|---|---|']
    for p in converted:
        lines.append('| %s | %s | %d |' % (p['file'], '/' + p['path'] + '/' if p['path'] else '/ (front page)', p['forms']))
    lines += ['', 'deft-move.sh check matches these to the WordPress pages and lists any page that would be new.', '',
              '## Forms', '']
    lines += page_report.lines or ['No forms.']
    warnings = report.warnings + page_report.warnings
    if paths.missing:
        warnings.append('References to files that are not in the rebuild (left as they were): ' + ', '.join(sorted(paths.missing)[:15]))
    lines += ['', '## Check these', '']
    lines += ['- ' + w for w in warnings] or ['Nothing flagged.']
    lines += ['', '## Files', '', '%d files, %.1f MB, go to `%s`.' % (len(copied) + 1, size / 1048576, ASSET_BASE)]
    with open(os.path.join(dest, 'REPORT.md'), 'w', encoding='utf-8') as fh:
        fh.write('\n'.join(lines) + '\n')

    zpath = os.path.join(out, domain + '.zip')
    with zipfile.ZipFile(zpath, 'w', zipfile.ZIP_DEFLATED) as z:
        for dirpath, _, filenames in os.walk(dest):
            for name in filenames:
                full = os.path.join(dirpath, name)
                z.write(full, os.path.relpath(full, dest))
    return dest, zpath, warnings


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('folder')
    ap.add_argument('--domain', required=True)
    ap.add_argument('--call-only', action='store_true')
    ap.add_argument('--map', action='append', default=[], help='rebuild=wordpress page path, e.g. estimate=free-estimate')
    ap.add_argument('--out', default='out')
    a = ap.parse_args(argv)
    if not re.match(r'^[a-z0-9.-]+\.[a-z]{2,}$', a.domain):
        raise SystemExit('Bad domain: %s' % a.domain)
    mapping = dict(m.split('=', 1) for m in a.map)
    dest, zpath, warnings = build(a.folder, a.domain, a.out, a.call_only, mapping)
    print('%s\t%s\t%d to check' % (a.domain, zpath, len(warnings)))


if __name__ == '__main__':
    sys.exit(main())
