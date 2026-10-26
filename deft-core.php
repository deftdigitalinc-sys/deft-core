<?php
/**
 * Plugin Name:       Deft Core
 * Description:       Deft Digital's own site tools. Forms: the Contact page's short form is saved under Leads and emailed through WP Mail, with an auto-reply and spam checks. Central sync (off until set up) sends each lead to Deft Central. Visitor statistics (off until switched on): cookieless visits, sources, approximate location, taps, form sends and time per section, with a Site report dashboard and a monthly email. Google Analytics (off until switched on, needs Site Kit): a monthly Sync puts Google Analytics and Search Console totals in the Site report next to Deft Core's own, including the months before Deft Core started counting. Site basics: old addresses redirect to their new pages, and missing pages show the Deft 404 design. Deft pages: pages stored as plain HTML on a Deft template, with drafts, preview links, 30 days of history, and publishing from Deft Studio (off until switched on). Live Edit (off until switched on): Deft staff change words, images and links right on the page. Move off Elementor: lifts HTML-widget pages out of Elementor one at a time, with a side-by-side compare and a way back. Site watch: a signed, read-only health report for Deft Central's daily check. AI agent access (off until switched on): an MCP server for Claude, Codex, Gemini and Antigravity, with a token per agent and a log of every action.
 * Version:           0.8.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Deft Digital
 * Author URI:        https://deftdigitalmedia.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       deft-core
 */

defined( 'ABSPATH' ) || exit;

define( 'DEFT_CORE_VERSION', '0.8.1' );
define( 'DEFT_CORE_FILE', __FILE__ );
define( 'DEFT_CORE_DIR', plugin_dir_path( __FILE__ ) );

require_once DEFT_CORE_DIR . 'includes/settings.php';
require_once DEFT_CORE_DIR . 'includes/geo.php';
require_once DEFT_CORE_DIR . 'includes/cache.php';
require_once DEFT_CORE_DIR . 'includes/html.php';
require_once DEFT_CORE_DIR . 'modules/forms.php';
require_once DEFT_CORE_DIR . 'modules/central.php';
require_once DEFT_CORE_DIR . 'modules/tracking.php';
require_once DEFT_CORE_DIR . 'modules/reports.php';
require_once DEFT_CORE_DIR . 'modules/dashboard.php';
require_once DEFT_CORE_DIR . 'modules/ga-history.php';
require_once DEFT_CORE_DIR . 'modules/site.php';
require_once DEFT_CORE_DIR . 'modules/pages.php';
require_once DEFT_CORE_DIR . 'modules/live-edit.php';
require_once DEFT_CORE_DIR . 'modules/migrate.php';
require_once DEFT_CORE_DIR . 'modules/pages-admin.php';
require_once DEFT_CORE_DIR . 'modules/watch.php';
require_once DEFT_CORE_DIR . 'modules/agent.php';

register_activation_hook( __FILE__, 'deft_core_activate' );
register_deactivation_hook( __FILE__, 'deft_core_deactivate' );

function deft_core_activate() {
	deft_forms_register_post_type();
	if ( ! wp_next_scheduled( 'deft_forms_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'deft_forms_cleanup' );
	}
	if ( false === get_option( 'deft_core' ) ) {
		add_option( 'deft_core', deft_core_defaults() );
	}
}

function deft_core_deactivate() {
	wp_clear_scheduled_hook( 'deft_forms_cleanup' );
	wp_clear_scheduled_hook( 'deft_central_sweep' );
	wp_clear_scheduled_hook( 'deft_track_daily' );
}
