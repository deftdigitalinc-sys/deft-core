<?php
/**
 * Site watch: a health report Deft Central reads once a day, so a hacked, broken or out-of-date client site shows up
 * in Central before the client (or Google) notices.
 *
 * GET /deft/v1/health, signed with the site's Central secret like every request from Central (or opened by Deft staff
 * while logged in). Read-only: it reports and never changes anything. The report has the site's versions and pending
 * updates, plugins, administrators (new ones flagged), signs of spam injection (spam titles, posts with no author, PHP
 * files in uploads, anyone-can-register-as-admin), forms (failed emails, last lead), Deft pages and the Elementor
 * move, and WP-Cron. Each problem is also listed in "findings" with a level (bad, warn, info), which Central shows on
 * Today.
 */

defined( 'ABSPATH' ) || exit;

/** Words spam injections use in titles and addresses. Kept narrow ("slot" alone would match "time slot"). */
const DEFT_WATCH_SPAM = '/\b(casino|baccarat|roulette|blackjack|poker|slots?\s*(online|gacor|machine)|gacor|togel|judi|sportsbook|betting|bet365|viagra|cialis|levitra|payday\s+loans?|escort|porn|xxx|onlyfans|replica\s+(watch|bag)|essay\s+writ|crypto\s+casino|no\s+deposit\s+bonus)\b/i';

/** Findings that mean the site was hacked. They are fixed by the clean-up, so Central never lets them be muted. */
const DEFT_WATCH_HACK = array( 'spam_posts', 'uploads_php', 'open_registration', 'user_ini_prepend', 'spam_sitemap', 'core_modified', 'unknown_mu_plugin', 'hidden_admin', 'suspicious_user_meta', 'ioc_username', 'hidden_plugin', 'root_php', 'injected_posts' );

/** A short plain title for each finding, as the Site watch board shows it. */
const DEFT_WATCH_TITLES = array(
	'core_update'       => 'WordPress update waiting',
	'php_old'           => 'PHP is out of date',
	'plugin_updates'    => 'Plugin updates waiting',
	'new_admin'         => 'New administrator',
	'open_registration' => 'Anyone can sign up as staff',
	'spam_posts'        => 'Spam pages are published',
	'orphan_posts'      => 'Posts with no author',
	'uploads_php'       => 'PHP file in uploads',
	'url_mismatch'      => 'Site addresses don\'t match',
	'noindex'           => 'Hidden from search engines',
	'mail_failed'       => 'An email from the site failed',
	'cron_late'         => 'Scheduled jobs are late',
	// From the file check (modules/security.php).
	'user_ini_prepend'     => 'A file runs before every page',
	'spam_sitemap'         => 'Spam sitemaps in the web root',
	'core_modified'        => 'WordPress files were changed',
	'unknown_mu_plugin'    => 'Unknown must-use plugin',
	'hidden_admin'         => 'Hidden administrator',
	'suspicious_user_meta' => 'Malware marks on user records',
	'ioc_username'         => 'Malware username',
	'hidden_plugin'        => 'Hidden plugin',
	'root_php'             => 'Unknown PHP in the web root',
	'injected_posts'       => 'Posts written straight into the database',
	'plugin_modified'      => 'Plugin files were changed',
	'root_archive'         => 'Backup files anyone can download',
	'files_changed'        => 'Code files changed',
	'dropin_unknown'       => 'Unknown drop-in',
	'app_passwords'        => 'Application passwords exist',
	'bad_plugin'           => 'Plugin Deft doesn\'t run',
	'admin_no_2fa'         => 'Administrator without two-factor',
	'security_off'         => 'Protections switched off',
	'integrity_pending'    => 'File check not finished',
);

add_filter( 'deft_core_defaults', 'deft_watch_defaults' );
function deft_watch_defaults( $defaults ) {
	return $defaults + array( 'watch' => 1, 'cleaning' => 0 );
}

add_filter( 'deft_core_sanitize', 'deft_watch_sanitize', 10, 2 );
function deft_watch_sanitize( $out, $input ) {
	$out['watch']    = empty( $input['watch'] ) ? 0 : 1;
	$out['cleaning'] = empty( $input['cleaning'] ) ? 0 : 1;
	if ( (int) deft_core_setting( 'cleaning' ) !== $out['cleaning'] ) {
		delete_transient( 'deft_migrate_hack_signs' );
	}
	return $out;
}

add_action( 'deft_core_settings_sections', 'deft_watch_settings_fields', 7 );
function deft_watch_settings_fields( $s ) {
	?>
	<h2 id="deft-watch"><?php esc_html_e( 'Site watch', 'deft-core' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><?php esc_html_e( 'Health report', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[watch]" value="1" <?php checked( $s['watch'] ); ?>> <?php esc_html_e( 'Let Deft Central read a daily health report', 'deft-core' ); ?></label>
			<p class="description"><?php esc_html_e( 'Versions, updates waiting, administrators and signs of a hack, so Deft hears about problems first. Only answers requests signed with the Central secret, and never changes anything.', 'deft-core' ); ?>
			<?php if ( deft_pages_is_staff() ) : ?>
				<a href="<?php echo esc_url( wp_nonce_url( rest_url( 'deft/v1/health' ), 'wp_rest', '_wpnonce' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See the report', 'deft-core' ); ?></a>
			<?php endif; ?></p></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Being cleaned', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[cleaning]" value="1" <?php checked( $s['cleaning'] ); ?>> <?php esc_html_e( 'This site was hacked and is being cleaned', 'deft-core' ); ?></label>
			<p class="description"><?php esc_html_e( 'Locks the move off Elementor ("Cleaning first") so nothing infected is copied into a Deft page. It also locks by itself while the site watch finds spam posts or PHP files in uploads. Untick it once the clean-up is checked.', 'deft-core' ); ?></p></td></tr>
	</table>
	<?php
}

/* ---------- Failed emails ---------- */

// WordPress only says an email failed through this hook, so keep a count and the last reason for the report.
add_action( 'wp_mail_failed', 'deft_watch_mail_failed' );
function deft_watch_mail_failed( $error ) {
	$log           = get_option( 'deft_watch_mail', array() );
	$log           = is_array( $log ) ? $log : array();
	$log['count']  = (int) ( $log['count'] ?? 0 ) + 1;
	$log['last']   = gmdate( 'c' );
	$log['reason'] = is_wp_error( $error ) ? substr( $error->get_error_message(), 0, 200 ) : '';
	update_option( 'deft_watch_mail', $log, false );
}

/* ---------- The report ---------- */

add_action( 'rest_api_init', 'deft_watch_routes' );
function deft_watch_routes() {
	register_rest_route(
		'deft/v1',
		'/health',
		array(
			'methods'             => 'GET',
			'callback'            => 'deft_watch_rest_health',
			'permission_callback' => 'deft_watch_rest_permission',
		)
	);
}

function deft_watch_rest_permission( WP_REST_Request $request ) {
	if ( is_user_logged_in() ) {
		return deft_pages_is_staff() ? true : new WP_Error( 'deft_not_staff', __( 'Only Deft staff can read the health report.', 'deft-core' ), array( 'status' => 403 ) );
	}
	if ( ! deft_core_setting( 'watch' ) ) {
		return new WP_Error( 'deft_watch_off', __( 'The health report is switched off on this site (Settings > Deft > Site watch).', 'deft-core' ), array( 'status' => 403 ) );
	}
	return deft_central_verify_request( $request );
}

function deft_watch_rest_health() {
	$started = microtime( true );
	$report  = deft_watch_report();
	$report['ms'] = (int) round( 1000 * ( microtime( true ) - $started ) );
	return rest_ensure_response( $report );
}

/** The whole report (see the file comment). */
function deft_watch_report() {
	global $wp_version;
	$findings = array();
	$add      = function ( $level, $code, $text ) use ( &$findings ) {
		$findings[] = array( 'level' => $level, 'code' => $code, 'title' => DEFT_WATCH_TITLES[ $code ] ?? '', 'text' => $text, 'hack' => in_array( $code, DEFT_WATCH_HACK, true ) );
	};

	// Versions and updates waiting.
	$core_update = get_site_transient( 'update_core' );
	$core_next   = null;
	if ( is_object( $core_update ) && ! empty( $core_update->updates ) ) {
		foreach ( $core_update->updates as $offer ) {
			if ( isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response ) {
				$core_next = $offer->current;
				break;
			}
		}
	}
	if ( $core_next ) {
		$add( 'warn', 'core_update', sprintf( 'WordPress %s is out (this site runs %s).', $core_next, $wp_version ) );
	}
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		$add( 'warn', 'php_old', sprintf( 'PHP %s no longer gets security fixes.', PHP_VERSION ) );
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$plugin_updates = get_site_transient( 'update_plugins' );
	$plugin_updates = is_object( $plugin_updates ) && ! empty( $plugin_updates->response ) ? $plugin_updates->response : array();
	$active         = (array) get_option( 'active_plugins', array() );
	$plugins        = array();
	foreach ( get_plugins() as $file => $data ) {
		$plugins[] = array(
			'file'    => $file,
			'name'    => $data['Name'],
			'version' => $data['Version'],
			'active'  => in_array( $file, $active, true ),
			'update'  => isset( $plugin_updates[ $file ]->new_version ) ? $plugin_updates[ $file ]->new_version : null,
		);
	}
	$waiting = array_filter( $plugins, function ( $p ) {
		return $p['active'] && $p['update'];
	} );
	if ( $waiting ) {
		$add( count( $waiting ) > 3 ? 'warn' : 'info', 'plugin_updates', sprintf( '%d active plugins have updates waiting: %s.', count( $waiting ), implode( ', ', wp_list_pluck( array_slice( array_values( $waiting ), 0, 6 ), 'name' ) ) ) );
	}
	$must_use = array_keys( get_mu_plugins() );
	$dropins  = array_keys( get_dropins() );

	$theme_updates = get_site_transient( 'update_themes' );
	$theme         = wp_get_theme();

	// Administrators. New ones are the clearest sign someone got in.
	$admins = array();
	$recent = gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS );
	foreach ( get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'user_login', 'user_email', 'user_registered' ) ) ) as $user ) {
		$is_new   = $user->user_registered > $recent;
		$admins[] = array(
			'login'      => $user->user_login,
			'email'      => $user->user_email,
			'registered' => mysql2date( 'c', $user->user_registered, false ),
			'new'        => $is_new,
		);
		if ( $is_new ) {
			$add( 'bad', 'new_admin', sprintf( 'New administrator "%s" (%s) was added %s.', $user->user_login, $user->user_email, mysql2date( 'M j', $user->user_registered ) ) );
		}
	}
	$default_role = get_option( 'default_role' );
	if ( get_option( 'users_can_register' ) && in_array( $default_role, array( 'administrator', 'editor', 'author' ), true ) ) {
		$add( 'bad', 'open_registration', sprintf( 'Anyone can sign up and becomes %s. Hacks often switch this on.', $default_role ) );
	}

	// Spam injected as posts or pages.
	$spam = deft_watch_spam_posts();
	if ( $spam['count'] ) {
		$add( 'bad', 'spam_posts', sprintf( '%d published posts or pages look like spam, for example "%s".', $spam['count'], $spam['sample'][0]['title'] ) );
	}
	if ( $spam['orphans'] ) {
		$add( 'warn', 'orphan_posts', sprintf( '%d posts published in the last 90 days have no author, which is how injected posts usually look.', $spam['orphans'] ) );
	}

	// PHP files in uploads: uploads only ever holds media, so a PHP file there is almost always a back door. (A tiny
	// index.php is the usual "silence is golden" placeholder and is skipped.)
	$uploads_php = deft_watch_uploads_php();
	if ( $uploads_php['count'] ) {
		$add( 'bad', 'uploads_php', sprintf( '%d PHP files are in the uploads folder, for example %s.', $uploads_php['count'], $uploads_php['sample'][0] ) );
	}

	// Addresses: a changed siteurl or home is how some hacks send visitors elsewhere.
	$home    = home_url( '/' );
	$siteurl = site_url( '/' );
	if ( wp_parse_url( $home, PHP_URL_HOST ) !== wp_parse_url( $siteurl, PHP_URL_HOST ) ) {
		$add( 'warn', 'url_mismatch', sprintf( 'The site address (%s) and WordPress address (%s) point to different hosts.', $home, $siteurl ) );
	}
	if ( ! get_option( 'blog_public' ) ) {
		$add( 'warn', 'noindex', 'Search engines are asked not to index this site (Settings > Reading).' );
	}

	// Forms.
	$mail      = get_option( 'deft_watch_mail', array() );
	$last_lead = get_posts( array( 'post_type' => 'deft_lead', 'post_status' => 'any', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids' ) );
	if ( ! empty( $mail['last'] ) && strtotime( $mail['last'] ) > time() - 7 * DAY_IN_SECONDS ) {
		$add( 'warn', 'mail_failed', sprintf( 'An email from the site failed on %s: %s', mysql2date( 'M j', $mail['last'] ), $mail['reason'] ?? '' ) );
	}

	// WP-Cron: scheduled jobs that are more than a day late mean nobody visits or cron is broken.
	$late = 0;
	foreach ( (array) _get_cron_array() as $time => $hooks ) {
		if ( (int) $time < time() - DAY_IN_SECONDS ) {
			$late += count( (array) $hooks );
		}
	}
	if ( $late ) {
		$add( 'warn', 'cron_late', sprintf( '%d scheduled jobs are more than a day late.', $late ) );
	}

	// Deft pages and the move off Elementor.
	$deft_pages = count(
		get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => 500,
				'fields'      => 'ids',
				'meta_query'  => array( array( 'key' => '_wp_page_template', 'value' => array_keys( deft_pages_templates() ), 'compare' => 'IN' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		)
	);
	$elementor  = (int) count(
		get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => 500,
				'fields'      => 'ids',
				'meta_key'    => '_elementor_edit_mode', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => 'builder', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		)
	);

	// The file check's findings (it runs nightly; the report reads its last result).
	$security = function_exists( 'deft_sec_report' ) ? deft_sec_report() : null;
	if ( $security ) {
		foreach ( $security['findings'] as $f ) {
			$add( $f['level'], $f['code'], $f['text'] );
		}
	}

	$order = array( 'bad' => 0, 'warn' => 1, 'info' => 2 );
	usort( $findings, function ( $a, $b ) use ( $order ) {
		return $order[ $a['level'] ] <=> $order[ $b['level'] ];
	} );

	return array(
		'schema'   => 'deft.health.v1',
		'site'     => deft_central_site_id(),
		'at'       => gmdate( 'c' ),
		'status'   => $findings ? $findings[0]['level'] : 'good',
		'findings' => $findings,
		'hacked'   => (bool) array_filter( wp_list_pluck( $findings, 'hack' ) ),
		'cleaning' => (bool) deft_core_setting( 'cleaning' ),
		'versions' => array(
			'wordpress'      => $wp_version,
			'wordpress_next' => $core_next,
			'php'            => PHP_VERSION,
			'deft_core'      => DEFT_CORE_VERSION,
			'theme'          => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'theme_update'   => is_object( $theme_updates ) && isset( $theme_updates->response[ $theme->get_stylesheet() ]['new_version'] ) ? $theme_updates->response[ $theme->get_stylesheet() ]['new_version'] : null,
		),
		'urls'     => array(
			'home'    => $home,
			'siteurl' => $siteurl,
			'public'  => (bool) get_option( 'blog_public' ),
		),
		'plugins'  => $plugins,
		'mu'       => $must_use,
		'dropins'  => $dropins,
		'admins'   => $admins,
		'signup'   => array(
			'open' => (bool) get_option( 'users_can_register' ),
			'role' => $default_role,
		),
		'spam'     => $spam,
		'uploads'  => $uploads_php,
		'forms'    => array(
			'last_lead'   => $last_lead ? get_post_time( 'c', true, $last_lead[0] ) : null,
			'mail_failed' => is_array( $mail ) ? $mail : array(),
		),
		'pages'    => array(
			'deft'      => $deft_pages,
			'elementor' => $elementor,
		),
		'cron'     => array(
			'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'late'     => $late,
		),
		'security' => $security ? $security['security'] : null,
	);
}

/** Published posts of any public type whose title or address reads like spam, and recent posts with no author. */
function deft_watch_spam_posts() {
	global $wpdb;
	$types = array_values( get_post_types( array( 'public' => true ) ) );
	$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	// Titles are short, so reading the newest 5000 is cheap and covers any injection batch we have seen.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_name, post_type, post_date_gmt, post_author FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) ORDER BY ID DESC LIMIT 5000", $types ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$found   = array();
	$orphans = 0;
	$cutoff  = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
	foreach ( (array) $rows as $row ) {
		if ( preg_match( DEFT_WATCH_SPAM, $row->post_title . ' ' . str_replace( '-', ' ', urldecode( $row->post_name ) ) ) ) {
			$found[] = $row;
		}
		if ( ! (int) $row->post_author && $row->post_date_gmt > $cutoff && 'deft_lead' !== $row->post_type ) {
			$orphans++;
		}
	}
	return array(
		'count'   => count( $found ),
		'orphans' => $orphans,
		'sample'  => array_map(
			function ( $row ) {
				return array(
					'id'    => (int) $row->ID,
					'title' => wp_strip_all_tags( $row->post_title ),
					'type'  => $row->post_type,
					'url'   => get_permalink( $row->ID ),
					'date'  => mysql2date( 'c', $row->post_date_gmt, false ),
				);
			},
			array_slice( $found, 0, 10 )
		),
	);
}

/** PHP files anywhere under uploads (capped, so a huge media library can't stall the report). */
function deft_watch_uploads_php() {
	$uploads = wp_upload_dir( null, false );
	$base    = $uploads['basedir'];
	$out     = array( 'count' => 0, 'sample' => array(), 'scanned' => 0, 'capped' => false );
	if ( ! is_dir( $base ) ) {
		return $out;
	}
	try {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY );
		foreach ( $files as $file ) {
			if ( ++$out['scanned'] > 60000 ) {
				$out['capped'] = true;
				break;
			}
			if ( preg_match( '/\.(php\d?|phtml|phar)$/i', $file->getFilename() ) && ! ( 'index.php' === $file->getFilename() && $file->getSize() < 100 ) ) {
				$out['count']++;
				if ( count( $out['sample'] ) < 10 ) {
					$out['sample'][] = 'uploads/' . ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $base ) ) ), '/' );
				}
			}
		}
	} catch ( Exception $e ) {
		$out['error'] = $e->getMessage();
	}
	return $out;
}
