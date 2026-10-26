=== Deft Core ===
Contributors: deftdigital
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.8.1
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
