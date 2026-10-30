# Deft move

Moves a client site off Elementor and its form plugins and onto the rebuilt HTML pages, using Deft pages, with every form sending to Deft Core. Decided 2026-10-30 by Daniel: no Forminator or WPForms stopgap, every site comes onto our setup.

You need Deft Core 0.9.6 or later on the site, so the extra questions a form asks are kept.

## 1. Make the bundle (on a PC, from the rebuild folder)

```
pip install beautifulsoup4
python deft_move.py "B:\Deft Digital\Agents\sites-html\amazing-glass-html" --domain amazingglassandmirrors.com --out "B:\Deft Digital\Agents\site-move"
```

Add `--call-only` for the sites that take calls, not forms. Add `--map estimate=free-estimate` when a rebuilt page should land on a WordPress page with a different address.

**Read `REPORT.md` before you go on.** It lists every page and its new address, and every form with each question and what it now sends as. It also lists everything worth a look: a field that was mislabelled (a "Phone Number" box that was really an email field), a file upload that had to be removed, a Mailchimp signup turned into a lead form, and files the rebuild points to but doesn't have.

What it changes:
- **Head:** keeps the styles and scripts. Drops the title, canonical, Open Graph and robots tags, because WordPress and Deft Core print those.
- **Paths:** every local file moves under `/wp-content/uploads/deft-site/`. Links between pages use the WordPress addresses (`/about/`, not `about.html`).
- **Forms:** each field gets a name from its label. Plugin tokens, old spam traps and file uploads are removed. A honeypot and a status line are added, and `deft-forms.js` posts the form to `/wp-json/deft/v1/forms/contact`. The "preview only" notices from the rebuild are removed.
- **Two files for one page** (`about.html` and `about/index.html`): the folder copy goes up and the report says so.
- **Review notices** left in the rebuild ("Preview form, sending will be connected…") are taken out.
- **Uploads:** only plain web files go up. A `.php` file, or anything else a server could run, is refused.

## 2. Put it live (on the server, with Daniel's OK naming the sites)

Copy `deft-move.sh`, `deft-move.php` and the bundles to the server (bundles go in `~/deft-move/bundles/`), then:

```
bash ~/deft-move.sh check amazingglassandmirrors.com
MOVE_APPROVED=yes bash ~/deft-move.sh apply amazingglassandmirrors.com
```

- **check** is read-only. It shows which WordPress page each rebuilt page lands on, any page that would be new, where leads go, which plugins are on, and whether the forms endpoint answers (with a send Deft Core drops, so no lead is made). It also lists what still uses Elementor or a form plugin.
- **apply** works through these steps:
  1. Backs up the database to `~/deft-move/backups/`, then updates Deft Core to 0.9.6 from `~/deft-move/deft-core-client.zip` if the site runs an older version.
  2. Uploads the files with permissions 755/644, plus a `.htaccess` that stops anything in that folder running as a script.
  3. Puts every page live through Deft Core, so each one gets a revision, a cache clear and a note to Central.
  4. Checks each page as a visitor would, plus the form script, the endpoint and `wp-login.php`.
  5. Undoes the move by itself if the login page breaks.
- **undo** puts every page back as it was, Elementor included. Pages the move made are trashed.

Then send one real test from a phone on the live site. It must show the thank-you line, appear under Leads and arrive in the inbox.

## 3. Clean up (after the test send, with Daniel's OK)

```
CLEANUP_APPROVED=yes bash ~/deft-move.sh cleanup amazingglassandmirrors.com
```

This step:
1. Exports every stored submission from the old form plugins to private CSVs in `~/deft-move/exports/`. Copy those to the Vault and delete them from the server.
2. Drops those tables, but only when the export row count matches.
3. Deletes Elementor, Elementor Pro, their add-ons, Forminator, WPForms, Contact Form 7, Mailchimp for WP and Flamingo.
4. Checks every page again.

It stops if anything published still uses Elementor or a form plugin. Use `FORCE=yes` to go ahead anyway.

## Tests

```
python3 -m unittest discover -s tools/site-move/test
node tools/site-move/test/forms-js-test.cjs
```
