=== Deft Core ===
Contributors: deftdigital
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.10.0
License: GPLv2 or later

Deft Digital's site plugin: makes our designed forms work and reports leads to Deft Central. Piloted on deftdigitalmedia.com, then client sites.

== Description ==

Forms: the Contact page's short form posts to /wp-json/deft/v1/forms/preview. Each request is saved under Leads,
emailed to the addresses in Settings > Deft through wp_mail (so any SMTP plugin applies), and the visitor gets an
auto-reply when they gave an email. Spam is dropped by a honeypot, a 3-second minimum and a rate limit (5 per visitor
per 10 minutes). Leads older than the retention setting (default 24 months) are deleted daily. Export CSV from Leads.

Central sync (off by default): Settings > Deft > Deft Central. Each lead is sent, signed with HMAC-SHA256, to
Deft Central's deftCoreIngest function by WP-Cron, with retries; the Leads list shows Sent / Waiting / Failed.
Contract: docs/deft-core-connector.md in Deft Central.

Visitor statistics (off by default): Settings > Deft > Visitor statistics. Cookieless visits, sources, approximate
city/county, taps on call/WhatsApp/email/directions, form starts and sends, and time per section, with a Site report
screen and a monthly email. Details: dist/wp/TRACKING.md.

IP geolocation by DB-IP (db-ip.com), CC BY 4.0. ZIP and county data from GeoNames (geonames.org), CC BY 4.0.

Setup and tests: dist/wp/FORMS.md in the Deft website project.

== Changelog ==

= 0.10.0 =
* Site security (Settings > Deft > Site security), after the October 2026 hacks. On by default: the file editor off, PHP blocked in uploads, archives, backups, config files and folder listings refused from the web root (checked with a home page request and taken back out if the site stops loading), XML-RPC off unless Jetpack is active, application passwords off, a lockout after 5 wrong logins (skipped while Wordfence runs), usernames hidden from visitors, and nosniff and referrer headers. Off by default: HSTS and plugin auto-updates. Turning the plugin off takes the .htaccess blocks back out.
* Logins: two-factor codes from an authenticator app (TOTP), required for administrators by default (they are sent to set it up, nobody is locked out; users with Wordfence two-factor are left to Wordfence; DEFT_CORE_NO_2FA in wp-config.php turns it off in an emergency). An "Ask for new passwords" button logs administrators (or everyone) out and sends each a reset link on their next login. New passwords that are in Have I Been Pwned's list are refused (only 5 characters of the SHA-1 leave the site).
* Nightly file check: WordPress core and wordpress.org plugins against the official checksums, Deft Core against data/manifest.json, premium plugins, the theme, must-use plugins and the web root's config files against a baseline. Also looks for auto_prepend_file in .user.ini, unknown PHP, spam sitemaps and archives in the web root, unknown must-use plugins and drop-ins, plugins hidden from the admin list, hidden administrators, malware usermeta keys and usernames, application passwords, admins without two-factor (where Wordfence Login Security runs) and plugins Deft doesn't run (file managers, MainWP, code snippets, temporary logins). Findings go into Site watch's health report and new problems are emailed to the alert address. Nothing is deleted or moved.

= 0.9.5 =
* Connect from Deft Central: Central's Connect button uploads wp-content/mu-plugins/deft-central.php, which defines DEFT_CORE_CENTRAL_URL, DEFT_CORE_CENTRAL_SITE_ID and DEFT_CORE_CENTRAL_SECRET. When it's there the site is connected with nothing to paste: leads go to Central, Site watch's inside checks work, and Settings > Deft shows "Set by Deft Central". The file survives plugin updates and keeps the secret out of the database. Delete it to disconnect. The endpoint and site ID can also be set by hand in wp-config.php, like the secret.

= 0.9.4 =
* Coming soon and maintenance mode: Settings > Deft > Coming soon and maintenance. Pick Coming soon (site still being built) or Maintenance (down for a short while) and visitors get a clean Deft-styled page with the site's logo and name, a message, and optional Call and Email buttons. Anyone logged in who can edit posts sees the real site, with a yellow reminder in the admin bar. Search engines get a 503 so nothing half-built is indexed. A private link lets the client see the site without an account. Login, wp-admin, forms, Deft Central and preview links keep working. Switching clears the page cache. Replaces separate coming soon plugins and Hostinger's generic page.

= 0.9.3 =
* Link previews: every page gets Open Graph and X card tags, so a shared link shows its title, a short description and a picture on Facebook, WhatsApp, iMessage, LinkedIn and X. The separate Open Graph plugin is no longer needed. The picture is the featured image, else the first picture on the page, else a fallback set in Settings > Deft > Link previews, else the site icon.
* Stays quiet while Yoast, Rank Math, The SEO Framework, All in One SEO, SEOPress or the Open Graph plugin is active, and on a Deft page whose own head already has the tags. On by default; Settings > Deft turns it off.

= 0.9.2 =
* Live Edit: Desktop and Phone switch. Phone shows the page 390 px wide with your unsaved changes in it; click any outlined part to go back and edit it.
* Live Edit: pick a block of plain words and ask Claude for a version (Make it shorter, In their voice, Add the city, or your own words). Claude writes from what Deft Central has on file for the client and never invents facts. You see Now and Claude suggests side by side, then Use this or Keep mine. Nothing changes until you publish. Needs the site connected to Deft Central.
* Live Edit: a Replace photo button on the image under the pointer opens the Media Library straight away.
* Live Edit: Revisions shows what changed, who and when, with Live on the current one and Restore on the others. Still kept 30 days.

= 0.9.1 =
* Move off Elementor: every page row shows a thumbnail of the page. Compare opens right under the board with the live page and the Deft draft side by side, scrolling together, and what the check found is outlined and numbered on the page. Click a difference to jump to it. Full screen still has the slider and flip.
* The check now saves where each difference is, so Compare can outline it.
* Pages shown inside Deft Core's own screens (thumbnails, Compare, the check) no longer count as visits.

= 0.9.0 =
* Client reports: Deft Central can read a month's totals (visits, calls and taps, forms, leads, sources, top pages, Google searches, and the month before) through a signed, read-only GET /deft/v1/stats/YYYY-MM, to draft the client's monthly report. No visitor-level details leave the site. On by default; Settings > Deft > Client reports turns it off.

= 0.8.1 =
* Move off Elementor checks each converted page by itself: the live page and the draft are loaded at phone, tablet and desktop widths and compared (height, words, images, links, where headings sit). Pages that match go to a new Checked lane; pages that differ list the differences and can still "Go live anyway".
* The last step removes Elementor safely: with a backup confirmed and staff's OK, Elementor is deactivated, every page is loaded as a visitor would, and Elementor is only uninstalled if they all pass. If one fails, Elementor goes straight back on.
* Hacked sites are locked as "Cleaning first" (a new Site watch setting, or automatically while spam posts or PHP files in uploads are found), so nothing infected is copied into a Deft page.
* The health report gives each finding a plain title and marks hack findings, which Deft Central never lets be muted.
* Deft pages and their previews now always win over a page builder's own template.

= 0.8.0 =
* Deft pages: pages stored as plain HTML on a Deft template (with or without the theme header and footer), with drafts, one-hour preview links, 30 days of history and restore. Going live clears the page cache and tells Deft Central, which clears Hostinger's cache and CDN.
* Deft Studio can publish pages through Deft Central with signed requests. Off until "Let Deft Studio publish pages" is ticked.
* Live Edit for Deft staff: change headings, text, images, alt text and links right on the page, review the changes, publish, undo. Off until switched on.
* Deft pages screen in wp-admin: every Deft page with its draft, HTML and history, plus new pages.
* Move off Elementor: convert HTML-widget pages into Deft pages one at a time, compare draft and live side by side, go live, and go back to Elementor at any time.
* Site watch: a signed, read-only health report (versions, updates, administrators, spam posts, PHP files in uploads, failed emails) for Deft Central's daily check.
* Settings > Deft lists only Deft's own staff as able to change Deft pages; clients opening one in the editor are told Deft looks after it.

= 0.7.2 =
* Form error messages no longer mention WhatsApp on client sites (our own site keeps its wording through data/site.json).

= 0.7.1 =
* Client-ready: Deft's own wording (lead email address, sender, auto-reply, thank-you message) moved to data/site.json, which only ships in our own site's zip. Without it, the defaults use the site's own name and admin email and a neutral auto-reply and thank-you message.
* New plain "contact" form (name, phone, email, service, message) at /wp-json/deft/v1/forms/contact for client sites. Our "preview" form is unchanged.
* The build also writes deft-core-client.zip: the same plugin without our 404 page, redirects or wording.

= 0.7.0 =
* Google Analytics is now a full part of the Site report instead of a one-off import. New setting in Settings > Deft: "Show Google Analytics in the Site report" (off by default; only offered when Google Site Kit is active). Sites without it stay Deft Core only.
* A "Sync Google Analytics" button on the Site report (and in Settings > Deft) brings in the month that just ended, any missing months up to 3 years back, and the current month so far. A small yellow dot shows when last month still needs syncing.
* Months with Deft Core numbers now also show a Google Analytics section: sessions, visitors, Google search clicks, pages per session, Seen in Google search with top searches, and countries. Months from before Deft Core keep their Google Analytics layout.
* Google Analytics totals are stored in their own table (deft_ga). Months imported by 0.6.x move there by themselves, and the setting is switched on for sites that had imported months.

= 0.6.3 =
* Central sync: a new lead is sent to Deft Central right after the visitor sees the thank-you message, instead of waiting for WP-Cron (which only runs when someone visits the site, so leads could sit on "Waiting"). Retries still use WP-Cron.

= 0.6.2 =
* Google Analytics history now fills in this month too: the days before Deft Core started counting come from Google Analytics and the rest from Deft Core, shown as one month with a note saying which days are which. It updates by itself when an admin opens the Site report (at most once a day, and never again once Deft Core is counting), and the Import button refreshes it. When the month ends, the combined totals are saved.

= 0.6.1 =
* AI agent access (off by default): an MCP server at /wp-json/deft/v1/mcp for Claude, Codex, Gemini and Antigravity, with a token per agent, read / changes / files levels, backups of changed files and a log of every action. Settings > Deft.
* Google Analytics history and the dashboard changes below, in the same build.

= 0.6.0 =
* Google Analytics history: Settings > Deft can copy monthly totals from Google Analytics (through Google Site Kit, plus Search Console if connected) into the Site report for the months before Deft Core started counting. Visits (GA sessions), page views, visitors, sources, campaigns, devices, top pages, countries, cities with their US county and map position, best times, and Google search impressions, clicks and top searches. Months with Deft Core numbers and the current month are never replaced; imported months never trigger the monthly email and can be removed in one click.
* Dashboard: imported months are tagged GA and say where their numbers came from; they show a Google search card and countries instead of calls and forms, which GA didn't count. The map now frames the main area (about 300 miles around the middle visit) and counts far-away visits below it.

= 0.5.2 =
* Security review (Oct 25). Leads (and the Export CSV and the "Latest leads" panel) are now for editors and admins only; authors and contributors can no longer see or export them (deft_leads_capability filter).
* Cloudflare's visitor-address header is only trusted when the request really comes from Cloudflare, so the address can't be faked to dodge rate limits or fake locations. The contact form's rate limit uses the same real address.
* Visitor statistics: the rate limit now counts per IP address and per saved row, so a script can't flood the tables by changing its browser name or batching section events.
* Auto-reply: at most one per email address per day and 20 an hour per site, so the form can't be used to email strangers. Leads are still saved and emailed to us.
* CSV export also neutralises cells that start with a tab or carriage return. Report queries fully prepared.
* The location database folder blocks web downloads (.htaccess) and is removed when the plugin is deleted.
* The privacy paragraph shows the retention days actually set.
* With DEFT_CORE_CENTRAL_SECRET in wp-config.php, saving Settings > Deft once removes any secret saved in the database.

= 0.5.1 =
* Site basics: the old addresses (custom-website-design, meet-the-team, website-plans and the rest) redirect to their new pages with a 301, and missing pages show the Deft 404 design instead of the theme's.

= 0.5.0 =
* Site report is now a dark Deft-styled dashboard: visits per day with last month for comparison, ways people got in touch, contact rate, sources donut, what brought in the calls and leads, a map of approximate visitor areas, top counties and service-area share, best times heatmap, visit-to-lead steps, how far people get on each page, latest leads, top pages, devices and campaigns. Months switch without reloading (GET /wp-json/deft/v1/report, editors and admins only). Full screen button.
* Fonts (Geist, Archivo; SIL OFL) are bundled, nothing loads from outside the site.
* Fixed: in US time zones the last day of each month was left out of that month's totals, and the monthly email could be skipped when the daily check ran before about 5 am.

= 0.4.0 =
* Visitor statistics module: cookieless counting of visits, sources and campaigns, approximate city/county (local DB-IP database, IP never stored), taps, form starts/sends and time per section. Site report screen, monthly email, 90-day detail retention with monthly totals kept. Off until switched on.
* Settings hooks for modules (deft_core_defaults / deft_core_sanitize filters, deft_core_settings_sections / deft_core_settings_after actions).

= 0.3.0 =
* Central sync module: queues each new lead for Deft Central, signed requests, retries (1 min to 24 h), Central column and "Send to Central now" in Leads, Test connection and "Send unsent leads" in Settings. Off until set up.

= 0.2.0 =
* Kept the name Deft Core (it is the Deft Connector from the Deft Central plan).
* Each lead keeps where it came from: page, referrer, UTM tags, gclid/fbclid. Shown in Leads, the lead email and the CSV.
* Unique submission IDs: a repeat send (double-click, retry) never creates a second lead or email.

= 0.1.0 =
* First version: forms module (Contact page free preview form).
