<?php
/**
 * Visitor statistics: page views, where visitors came from, approximate location, taps on call / WhatsApp / email /
 * directions, form starts and sends, and time spent on each section. Off until switched on in Settings > Deft.
 *
 * No cookies and no browser storage. Visitors are counted with a daily code: a hash of their IP address and browser
 * with a secret that changes every day and is then deleted, so a code can't be traced back to a person or followed
 * from one day to the next. The IP address itself is never stored. Detailed records are deleted after 90 days
 * (setting); monthly totals are kept for the reports.
 */

defined( 'ABSPATH' ) || exit;

const DEFT_TRACK_DB_VERSION = '1';

/* ---------- Settings ---------- */

add_filter( 'deft_core_defaults', 'deft_track_defaults' );
function deft_track_defaults( $defaults ) {
	return $defaults + array(
		'tracking_enabled'        => 0,
		'tracking_exclude_staff'  => 1,
		'tracking_service_area'   => '',
		'tracking_retention_days' => 90,
		'report_enabled'          => 0,
		'report_recipients'       => '',
	);
}

add_filter( 'deft_core_sanitize', 'deft_track_sanitize', 10, 2 );
function deft_track_sanitize( $out, $input ) {
	$was_on                         = (int) deft_core_setting( 'tracking_enabled' );
	$out['tracking_enabled']        = empty( $input['tracking_enabled'] ) ? 0 : 1;
	$out['tracking_exclude_staff']  = empty( $input['tracking_exclude_staff'] ) ? 0 : 1;
	$out['tracking_retention_days'] = max( 31, min( 400, absint( $input['tracking_retention_days'] ?? 90 ) ) );
	$counties                       = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', (string) ( $input['tracking_service_area'] ?? '' ) ) ) ) );
	$out['tracking_service_area']   = implode( ', ', $counties );
	$out['report_enabled']          = empty( $input['report_enabled'] ) ? 0 : 1;
	$emails                         = array_filter( array_map( 'sanitize_email', explode( ',', (string) ( $input['report_recipients'] ?? '' ) ) ) );
	$out['report_recipients']       = implode( ', ', $emails );
	if ( $out['report_enabled'] && ! $emails ) {
		$out['report_enabled'] = 0;
		add_settings_error( 'deft_core', 'report_recipients', __( 'Add at least one email for the monthly report. Left it off.', 'deft-core' ) );
	}
	if ( $out['tracking_enabled'] && ! $was_on ) {
		deft_track_install();
		if ( ! deft_geo_status()['installed'] ) {
			wp_schedule_single_event( time(), 'deft_geo_update_event' );
		}
	}
	return $out;
}

add_action( 'deft_core_settings_sections', 'deft_track_settings_fields' );
function deft_track_settings_fields( $s ) {
	?>
	<h2 id="deft-tracking"><?php esc_html_e( 'Visitor statistics', 'deft-core' ); ?></h2>
	<p><?php esc_html_e( 'Counts visits, where visitors came from, their approximate city and county, taps on call, WhatsApp, email and directions, form starts and sends, and how long people spend on each section. No cookies and nothing that identifies a person, so no consent banner is needed. Add the privacy paragraph below to the privacy page.', 'deft-core' ); ?></p>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><?php esc_html_e( 'Count visitors', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[tracking_enabled]" value="1" <?php checked( $s['tracking_enabled'] ); ?>> <?php esc_html_e( 'On', 'deft-core' ); ?></label></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Leave out', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[tracking_exclude_staff]" value="1" <?php checked( $s['tracking_exclude_staff'] ); ?>> <?php esc_html_e( 'Visits by logged-in staff (editors and admins)', 'deft-core' ); ?></label></td></tr>
		<tr><th scope="row"><label for="deft-area"><?php esc_html_e( 'Service area', 'deft-core' ); ?></label></th>
			<td><input id="deft-area" class="regular-text" name="deft_core[tracking_service_area]" value="<?php echo esc_attr( $s['tracking_service_area'] ); ?>" placeholder="Palm Beach, Broward">
			<p class="description"><?php esc_html_e( 'Counties the business serves, separated by commas. The report shows how many visitors come from outside them.', 'deft-core' ); ?></p></td></tr>
		<tr><th scope="row"><label for="deft-keep"><?php esc_html_e( 'Keep detailed records for', 'deft-core' ); ?></label></th>
			<td><input id="deft-keep" type="number" min="31" max="400" class="small-text" name="deft_core[tracking_retention_days]" value="<?php echo esc_attr( $s['tracking_retention_days'] ); ?>"> <?php esc_html_e( 'days', 'deft-core' ); ?>
			<p class="description"><?php esc_html_e( 'Monthly totals are kept for the reports after that.', 'deft-core' ); ?></p></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Monthly report', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[report_enabled]" value="1" <?php checked( $s['report_enabled'] ); ?>> <?php esc_html_e( 'Email last month\'s report on the 1st', 'deft-core' ); ?></label></td></tr>
		<tr><th scope="row"><label for="deft-report-to"><?php esc_html_e( 'Send the report to', 'deft-core' ); ?></label></th>
			<td><input id="deft-report-to" class="regular-text" name="deft_core[report_recipients]" value="<?php echo esc_attr( $s['report_recipients'] ); ?>">
			<p class="description"><?php esc_html_e( 'The business owner, plus Deft if you want a copy. Separate with commas.', 'deft-core' ); ?></p></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Location database', 'deft-core' ); ?></th>
			<td>
			<?php
			$geo = deft_geo_status();
			echo $geo['installed']
				? esc_html( sprintf( /* translators: %s: date */ __( 'Installed, updated %s. Refreshes monthly.', 'deft-core' ), wp_date( get_option( 'date_format' ), $geo['updated'] ) ) )
				: esc_html__( 'Not downloaded yet. It downloads by itself a minute after visitor statistics are switched on (about 60 MB), or use the button below.', 'deft-core' );
			?>
			</td></tr>
	</table>
	<details style="margin:0 0 1em">
		<summary><?php esc_html_e( 'Privacy page paragraph', 'deft-core' ); ?></summary>
		<textarea class="large-text" rows="6" readonly onclick="this.select()"><?php echo esc_textarea( deft_track_privacy_text() ); ?></textarea>
	</details>
	<?php
}

/** The paragraph to add to the site's privacy page. */
function deft_track_privacy_text() {
	/* translators: %d: days detailed records are kept */
	return sprintf( __( "Visitor statistics. To understand how our website is used, we count page visits, which website or ad brought visitors here, taps on our call, WhatsApp, email and directions links, form starts and sends, and how long each part of a page is viewed. We also estimate each visitor's general area (city and county) from their internet address. That address is used once for this estimate and is not stored. We don't use cookies for this and we don't record anything that identifies you. Detailed records are deleted after %d days; only monthly totals are kept.", 'deft-core' ), (int) deft_core_setting( 'tracking_retention_days' ) );
}

/* ---------- Tables ---------- */

function deft_track_table() {
	global $wpdb;
	return $wpdb->prefix . 'deft_hits';
}

function deft_track_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$hits    = deft_track_table();
	$reports = $wpdb->prefix . 'deft_reports';
	dbDelta(
		"CREATE TABLE $hits (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created datetime NOT NULL,
			day date NOT NULL,
			visitor char(16) NOT NULL,
			view varchar(12) NOT NULL DEFAULT '',
			type varchar(16) NOT NULL,
			path varchar(191) NOT NULL DEFAULT '',
			label varchar(100) NOT NULL DEFAULT '',
			value int(10) unsigned NOT NULL DEFAULT 0,
			source varchar(64) NOT NULL DEFAULT '',
			medium varchar(32) NOT NULL DEFAULT '',
			campaign varchar(100) NOT NULL DEFAULT '',
			referrer varchar(100) NOT NULL DEFAULT '',
			device varchar(8) NOT NULL DEFAULT '',
			country char(2) NOT NULL DEFAULT '',
			region varchar(64) NOT NULL DEFAULT '',
			city varchar(80) NOT NULL DEFAULT '',
			county varchar(80) NOT NULL DEFAULT '',
			zip varchar(10) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY day_type (day,type),
			KEY visitor_day (day,visitor)
		) $charset;
		CREATE TABLE $reports (
			month char(7) NOT NULL,
			data longtext NOT NULL,
			sent datetime NULL,
			PRIMARY KEY  (month)
		) $charset;"
	);
	update_option( 'deft_track_db', DEFT_TRACK_DB_VERSION );
}

// Plugin updates replace files without re-activation, so make sure the tables exist once tracking is in use.
add_action( 'admin_init', 'deft_track_maybe_install' );
function deft_track_maybe_install() {
	if ( deft_core_setting( 'tracking_enabled' ) && DEFT_TRACK_DB_VERSION !== get_option( 'deft_track_db' ) ) {
		deft_track_install();
	}
}

/* ---------- Front end ---------- */

add_action( 'wp_enqueue_scripts', 'deft_track_enqueue' );
function deft_track_enqueue() {
	if ( ! deft_core_setting( 'tracking_enabled' ) || is_preview() || is_customize_preview() ) {
		return;
	}
	if ( deft_core_setting( 'tracking_exclude_staff' ) && current_user_can( 'edit_posts' ) ) {
		return;
	}
	wp_enqueue_script( 'deft-track', plugins_url( 'assets/track.js', DEFT_CORE_FILE ), array(), DEFT_CORE_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_add_inline_script( 'deft-track', 'window.deftTrack=' . wp_json_encode( array( 'url' => rest_url( 'deft/v1/t' ) ) ) . ';', 'before' );
}

/* ---------- Collecting ---------- */

add_action( 'rest_api_init', 'deft_track_register_route' );
function deft_track_register_route() {
	register_rest_route(
		'deft/v1',
		'/t',
		array(
			'methods'             => 'POST',
			'callback'            => 'deft_track_collect',
			'permission_callback' => '__return_true',
		)
	);
}

/** The visitor's IP address (see deft_core_client_ip: Cloudflare's header only counts from Cloudflare). */
function deft_track_ip() {
	return deft_core_client_ip();
}

function deft_track_user_agent() {
	return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
}

function deft_track_is_bot( $ua ) {
	return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|embedly|headless|lighthouse|pagespeed|pingdom|uptime|monitor|preview|python|curl|wget|httpclient|okhttp|java\/|go-http|axios|node-fetch|scrapy/i', $ua );
}

/** Today's secret for the visitor codes, made fresh each day (the old one is overwritten). */
function deft_track_salt() {
	$today = wp_date( 'Y-m-d' );
	$salt  = get_option( 'deft_track_salt' );
	if ( ! is_array( $salt ) || ( $salt['day'] ?? '' ) !== $today ) {
		$salt = array( 'day' => $today, 'salt' => wp_generate_password( 32, true, true ) );
		update_option( 'deft_track_salt', $salt, false );
	}
	return $salt['salt'];
}

/** Today's visitor code. The secret behind it is replaced every day, so codes can't be linked across days. */
function deft_track_visitor() {
	return substr( hash( 'sha256', deft_track_salt() . '|' . deft_track_ip() . '|' . deft_track_user_agent() . '|' . home_url() ), 0, 16 );
}

/** Today's code for the IP address alone, for the rate limit and location cache (changing the browser name can't dodge it). */
function deft_track_ip_code() {
	return substr( hash( 'sha256', deft_track_salt() . '|ip|' . deft_track_ip() ), 0, 16 );
}

/** Approximate location for this visitor today, looked up once and then cached under the day's IP code. */
function deft_track_location() {
	$key = 'deft_tg_' . deft_track_ip_code();
	$geo = get_transient( $key );
	if ( ! is_array( $geo ) ) {
		$geo = deft_geo_lookup( deft_track_ip() );
		set_transient( $key, $geo, DAY_IN_SECONDS );
	}
	return wp_parse_args( $geo, array( 'country' => '', 'region' => '', 'city' => '', 'county' => '', 'zip' => '' ) );
}

/** Where a visit came from: campaign tags win, then click IDs, then the referring site. */
function deft_track_source( $referrer, $tags ) {
	$host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
	$host = preg_replace( '/^(www|m|l|lm|mobile)\./', '', $host );
	$self = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	$out  = array( 'source' => 'direct', 'medium' => '', 'campaign' => '', 'referrer' => $host === $self ? '' : $host );

	if ( ! empty( $tags['utm_source'] ) ) {
		$out['source']   = strtolower( $tags['utm_source'] );
		$out['medium']   = strtolower( $tags['utm_medium'] ?? '' );
		$out['campaign'] = $tags['utm_campaign'] ?? '';
	} elseif ( ! empty( $tags['gclid'] ) ) {
		$out['source'] = 'google';
		$out['medium'] = 'paid';
	} elseif ( ! empty( $tags['fbclid'] ) ) {
		$out['source'] = false !== strpos( $host, 'instagram' ) ? 'instagram' : 'facebook';
		$out['medium'] = 'social';
	} elseif ( $host && $host === $self ) {
		$out['source'] = '(internal)';
	} elseif ( $host ) {
		$known = array(
			'/(^|\.)google\./'          => array( 'google', 'search' ),
			'/(^|\.)bing\.com$/'        => array( 'bing', 'search' ),
			'/(^|\.)duckduckgo\.com$/'  => array( 'duckduckgo', 'search' ),
			'/(^|\.)yahoo\./'           => array( 'yahoo', 'search' ),
			'/(^|\.)facebook\.com$/'    => array( 'facebook', 'social' ),
			'/(^|\.)instagram\.com$/'   => array( 'instagram', 'social' ),
			'/^(t\.co|x\.com|twitter\.com)$/' => array( 'x', 'social' ),
			'/(^|\.)linkedin\.com$/'    => array( 'linkedin', 'social' ),
			'/(^|\.)youtube\.com$/'     => array( 'youtube', 'social' ),
			'/(^|\.)tiktok\.com$/'      => array( 'tiktok', 'social' ),
			'/(^|\.)nextdoor\.com$/'    => array( 'nextdoor', 'social' ),
			'/(^|\.)yelp\.com$/'        => array( 'yelp', 'referral' ),
			'/(^|\.)chatgpt\.com$|(^|\.)openai\.com$/' => array( 'chatgpt', 'ai' ),
		);
		$out['source'] = $host;
		$out['medium'] = 'referral';
		foreach ( $known as $pattern => $name ) {
			if ( preg_match( $pattern, $host ) ) {
				list( $out['source'], $out['medium'] ) = $name;
				break;
			}
		}
	}
	$out['source']   = mb_substr( sanitize_text_field( $out['source'] ), 0, 64 );
	$out['medium']   = mb_substr( sanitize_text_field( $out['medium'] ), 0, 32 );
	$out['campaign'] = mb_substr( sanitize_text_field( $out['campaign'] ), 0, 100 );
	$out['referrer'] = mb_substr( $out['referrer'], 0, 100 );
	return $out;
}

function deft_track_clean_path( $path ) {
	$path = '/' . ltrim( (string) wp_parse_url( (string) $path, PHP_URL_PATH ), '/' );
	return mb_substr( sanitize_text_field( $path ), 0, 191 );
}

/** Handles one event from track.js. Always answers 204 so the page never waits or shows errors. */
function deft_track_collect( WP_REST_Request $request ) {
	$done = new WP_REST_Response( null, 204 );
	if ( ! deft_core_setting( 'tracking_enabled' ) || deft_track_is_bot( deft_track_user_agent() ) ) {
		return $done;
	}
	$body = $request->get_body();
	if ( strlen( $body ) > 8192 ) {
		return $done;
	}
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) || ! in_array( $data['t'] ?? '', array( 'view', 'tap', 'form_start', 'sections' ), true ) ) {
		return $done;
	}

	// Rate limit per IP address, counted in saved rows (a "sections" event can save up to 40), so a script can't fill
	// the table by changing its browser name or batching. More than 300 rows in 10 minutes isn't a person.
	$visitor = deft_track_visitor();
	$limit   = 'deft_tl_' . deft_track_ip_code();
	$count   = (int) get_transient( $limit );
	$rows    = 'sections' === $data['t'] ? max( 1, min( 40, count( (array) ( $data['s'] ?? array() ) ) ) ) : 1;
	if ( $count + $rows > 300 ) {
		return $done;
	}
	set_transient( $limit, $count + $rows, 10 * MINUTE_IN_SECONDS );

	$row = array(
		'visitor' => $visitor,
		'view'    => preg_replace( '/[^a-z0-9]/', '', substr( (string) ( $data['v'] ?? '' ), 0, 12 ) ),
		'type'    => 'sections' === $data['t'] ? 'section' : $data['t'],
		'path'    => deft_track_clean_path( $data['p'] ?? '/' ),
	);

	switch ( $data['t'] ) {
		case 'view':
			$tags  = array_map( 'strval', array_intersect_key( (array) ( $data['q'] ?? array() ), array_flip( array( 'utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'fbclid' ) ) ) );
			$width = absint( $data['w'] ?? 0 );
			$row  += deft_track_source( (string) ( $data['r'] ?? '' ), $tags );
			$row  += deft_track_location();
			$row['device'] = $width ? ( $width < 768 ? 'phone' : ( $width < 1100 ? 'tablet' : 'desktop' ) ) : '';
			deft_track_insert( $row );
			break;
		case 'tap':
			if ( in_array( $data['l'] ?? '', array( 'call', 'text', 'email', 'whatsapp', 'directions' ), true ) ) {
				deft_track_insert( $row + array( 'label' => $data['l'] ) );
			}
			break;
		case 'form_start':
			deft_track_insert( $row + array( 'label' => mb_substr( sanitize_text_field( (string) ( $data['l'] ?? 'form' ) ), 0, 100 ) ) );
			break;
		case 'sections':
			foreach ( array_slice( (array) ( $data['s'] ?? array() ), 0, 40 ) as $section ) {
				if ( is_array( $section ) && isset( $section[0], $section[1] ) ) {
					deft_track_insert(
						$row + array(
							'label' => mb_substr( sanitize_text_field( (string) $section[0] ), 0, 100 ),
							'value' => min( 1800000, absint( $section[1] ) ),
						)
					);
				}
			}
			break;
	}
	return $done;
}

function deft_track_insert( $row ) {
	global $wpdb;
	$now = time();
	$row = array_merge(
		array(
			'created' => gmdate( 'Y-m-d H:i:s', $now ),
			'day'     => wp_date( 'Y-m-d', $now ),
		),
		$row
	);
	$wpdb->insert( deft_track_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
}

// Form sends are counted when the lead is saved, so they're exact and tied to the same visitor as their page views.
add_action( 'deft_forms_lead_saved', 'deft_track_form_sent', 20, 3 );
function deft_track_form_sent( $lead_id, $form_key, $values ) {
	if ( ! deft_core_setting( 'tracking_enabled' ) || DEFT_TRACK_DB_VERSION !== get_option( 'deft_track_db' ) ) {
		return;
	}
	deft_track_insert(
		array(
			'visitor' => deft_track_visitor(),
			'type'    => 'form_send',
			'path'    => deft_track_clean_path( $values['page'] ?? '/' ),
			'label'   => mb_substr( (string) $form_key, 0, 100 ),
		)
	);
}

/* ---------- Daily upkeep ---------- */

add_action( 'init', 'deft_track_schedule' );
function deft_track_schedule() {
	if ( ! wp_next_scheduled( 'deft_track_daily' ) ) {
		wp_schedule_event( strtotime( 'tomorrow 01:30' ), 'daily', 'deft_track_daily' );
	}
}

add_action( 'deft_geo_update_event', 'deft_geo_update' );

add_action( 'deft_track_daily', 'deft_track_daily' );
function deft_track_daily() {
	global $wpdb;
	if ( DEFT_TRACK_DB_VERSION !== get_option( 'deft_track_db' ) ) {
		return;
	}
	// Last month's totals (kept after the details are deleted) and the monthly email.
	deft_report_close_month( gmdate( 'Y-m', strtotime( 'first day of last month', current_time( 'timestamp' ) ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

	$days = (int) deft_core_setting( 'tracking_retention_days' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . deft_track_table() . ' WHERE day < %s', wp_date( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

	if ( deft_core_setting( 'tracking_enabled' ) ) {
		$geo = deft_geo_status();
		if ( ! $geo['installed'] || ( 'geo.mmdb' !== $geo['file'] && $geo['updated'] < time() - 32 * DAY_IN_SECONDS ) ) {
			deft_geo_update();
		}
	}
}

add_action( 'admin_post_deft_geo_update', 'deft_track_geo_update_now' );
function deft_track_geo_update_now() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'deft-core' ) );
	}
	check_admin_referer( 'deft_geo_update' );
	$result = deft_geo_update();
	set_transient( 'deft_geo_msg_' . get_current_user_id(), is_wp_error( $result ) ? $result->get_error_message() : 'ok', 60 );
	wp_safe_redirect( admin_url( 'options-general.php?page=deft-core#deft-tracking' ) );
	exit;
}

add_action( 'deft_core_settings_after', 'deft_track_settings_actions' );
function deft_track_settings_actions() {
	$msg = get_transient( 'deft_geo_msg_' . get_current_user_id() );
	?>
	<hr>
	<h2><?php esc_html_e( 'Location database', 'deft-core' ); ?></h2>
	<?php if ( false !== $msg ) : ?>
		<?php delete_transient( 'deft_geo_msg_' . get_current_user_id() ); ?>
		<div class="notice <?php echo 'ok' === $msg ? 'notice-success' : 'notice-error'; ?> inline"><p>
			<?php echo 'ok' === $msg ? esc_html__( 'Location database downloaded.', 'deft-core' ) : esc_html( $msg ); ?>
		</p></div>
	<?php endif; ?>
	<p><?php esc_html_e( 'Approximate locations use the free DB-IP City Lite database, stored on this site. Downloading takes a minute.', 'deft-core' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="deft_geo_update">
		<?php wp_nonce_field( 'deft_geo_update' ); ?>
		<?php submit_button( __( 'Download location database now', 'deft-core' ), 'secondary', 'submit', false ); ?>
	</form>
	<?php
}
