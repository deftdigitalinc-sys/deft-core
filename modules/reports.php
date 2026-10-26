<?php
/**
 * Reports for the visitor statistics: the "Site report" screen in WP admin and the monthly email.
 * A month's totals are worked out from the detailed records; once the month is over they are saved in the
 * deft_reports table, so the report still works after the details are deleted.
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Totals ---------- */

/**
 * Totals for one month (Y-m), from the detailed records.
 * Visits are counted per visitor per day (codes change daily), so a person who comes back on 3 days is 3 visits.
 */
function deft_report_summary( $month ) {
	global $wpdb;
	$t     = deft_track_table();
	$month = preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month ) ? $month : gmdate( 'Y-m' );
	$start = $month . '-01';
	$end   = gmdate( 'Y-m-d', strtotime( $start . ' 00:00:00 UTC +1 month' ) ); // Plain date math: wp_date() would shift it a day in US time zones.
	$in    = $wpdb->prepare( 'day >= %s AND day < %s', $start, $end );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

	$views  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $in AND type = 'view'" );
	$visits = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM $t WHERE $in AND type = 'view' GROUP BY day, visitor) v" );

	// Each visit's first page view decides where it came from and where it was.
	$entries = "SELECT h.* FROM $t h JOIN (SELECT MIN(id) id FROM $t WHERE $in AND type = 'view' GROUP BY day, visitor) f ON f.id = h.id";

	$sources = $wpdb->get_results( "SELECT CASE WHEN source = '(internal)' THEN 'direct' ELSE source END src, medium, COUNT(*) visits FROM ($entries) e GROUP BY CASE WHEN source = '(internal)' THEN 'direct' ELSE source END, medium ORDER BY visits DESC LIMIT 12", ARRAY_A );
	$campaigns = $wpdb->get_results( "SELECT campaign, source, COUNT(*) visits FROM ($entries) e WHERE campaign <> '' GROUP BY campaign, source ORDER BY visits DESC LIMIT 10", ARRAY_A );
	$devices = $wpdb->get_results( "SELECT device, COUNT(*) visits FROM ($entries) e WHERE device <> '' GROUP BY device ORDER BY visits DESC", ARRAY_A );
	$counties = $wpdb->get_results( "SELECT county, COUNT(*) visits FROM ($entries) e WHERE county <> '' GROUP BY county ORDER BY visits DESC LIMIT 15", ARRAY_A );
	$cities = $wpdb->get_results( "SELECT city, region, country, COUNT(*) visits FROM ($entries) e WHERE city <> '' GROUP BY city, region, country ORDER BY visits DESC LIMIT 15", ARRAY_A );
	$located = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ($entries) e WHERE country <> ''" );
	$us_located = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ($entries) e WHERE county <> ''" );

	$pages = $wpdb->get_results( "SELECT path, COUNT(*) views, COUNT(DISTINCT CONCAT(day, visitor)) visits FROM $t WHERE $in AND type = 'view' GROUP BY path ORDER BY views DESC LIMIT 15", ARRAY_A );
	$taps  = $wpdb->get_results( "SELECT label, COUNT(*) taps FROM $t WHERE $in AND type = 'tap' GROUP BY label ORDER BY taps DESC", ARRAY_A );
	$form_starts = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT CONCAT(visitor, day, label)) FROM $t WHERE $in AND type = 'form_start'" );
	$form_sends  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $in AND type = 'form_send'" );

	// Contacts (taps and form sends) by where the visit came from.
	$contacts_by_source = $wpdb->get_results(
		"SELECT CASE WHEN e.source = '(internal)' THEN 'direct' ELSE e.source END src, e.medium, COUNT(*) contacts
		FROM $t a JOIN ($entries) e ON e.day = a.day AND e.visitor = a.visitor
		WHERE " . $wpdb->prepare( 'a.day >= %s AND a.day < %s', $start, $end ) . " AND a.type IN ('tap', 'form_send')
		GROUP BY CASE WHEN e.source = '(internal)' THEN 'direct' ELSE e.source END, e.medium ORDER BY contacts DESC LIMIT 12",
		ARRAY_A
	);

	foreach ( array( &$sources, &$contacts_by_source ) as &$list ) {
		foreach ( $list as &$row ) {
			$row = array( 'source' => $row['src'] ) + $row;
			unset( $row['src'] );
		}
		unset( $row );
	}
	unset( $list );

	$daily = $wpdb->get_results( "SELECT day, COUNT(DISTINCT visitor) visits FROM $t WHERE $in AND type = 'view' GROUP BY day ORDER BY day", ARRAY_A );
	$daily_contacts = $wpdb->get_results( "SELECT day, COUNT(*) contacts FROM $t WHERE $in AND type IN ('tap', 'form_send') GROUP BY day ORDER BY day", ARRAY_A );
	$contact_visits = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM $t WHERE $in AND type IN ('tap', 'form_send') GROUP BY day, visitor) c" );
	$form_visits    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM $t WHERE $in AND type = 'form_start' GROUP BY day, visitor) f" );

	// Map points: visits per approximate ZIP, placed at the ZIP's center.
	$points = array();
	foreach ( $wpdb->get_results( "SELECT zip, county, city, COUNT(*) visits FROM ($entries) e WHERE zip <> '' GROUP BY zip, county, city ORDER BY visits DESC LIMIT 300", ARRAY_A ) as $row ) {
		$at = deft_geo_zip_point( $row['zip'] );
		if ( $at ) {
			$points[] = array( 'zip' => $row['zip'], 'county' => $row['county'], 'city' => $row['city'], 'visits' => (int) $row['visits'], 'lat' => $at[0], 'lon' => $at[1] );
		}
	}

	// Best times: visits and contacts by weekday and hour, in the site's time zone (0 = Sunday).
	$tz    = wp_timezone();
	$times = array(
		'visits'   => array_fill( 0, 7, array_fill( 0, 24, 0 ) ),
		'contacts' => array_fill( 0, 7, array_fill( 0, 24, 0 ) ),
	);
	foreach ( array( 'visits' => "type = 'view'", 'contacts' => "type IN ('tap', 'form_send')" ) as $key => $where ) {
		foreach ( $wpdb->get_results( "SELECT SUBSTR(created, 1, 13) hr, COUNT(*) n FROM $t WHERE $in AND $where GROUP BY SUBSTR(created, 1, 13)", ARRAY_A ) as $row ) {
			$local = ( new DateTime( $row['hr'] . ':00:00', new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
			$times[ $key ][ (int) $local->format( 'w' ) ][ (int) $local->format( 'G' ) ] += (int) $row['n'];
		}
	}

	// Sections of the busiest pages, in page order: how many page views reached each one and the average time on screen.
	$sections = array();
	foreach ( array_slice( $pages, 0, 8 ) as $page ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT label, COUNT(DISTINCT view) reached, SUM(value) ms FROM $t WHERE $in AND type = 'section' AND path = %s GROUP BY label ORDER BY MIN(id)",
				$page['path']
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			continue;
		}
		$page_views = (int) $page['views'];
		$list       = array();
		foreach ( $rows as $row ) {
			$reached = (int) $row['reached'];
			$list[]  = array(
				'section' => $row['label'],
				'reach'   => $page_views ? min( 100, round( 100 * $reached / $page_views ) ) : 0,
				'seconds' => $reached ? round( $row['ms'] / $reached / 1000, 1 ) : 0,
			);
		}
		$sections[] = array( 'path' => $page['path'], 'views' => $page_views, 'sections' => array_slice( $list, 0, 15 ) );
	}
	// phpcs:enable

	$outside = deft_report_outside_area( $counties, $us_located );

	$tap_total = array_sum( array_map( 'intval', wp_list_pluck( $taps, 'taps' ) ) );

	return array(
		'month'              => $month,
		'visits'             => $visits,
		'views'              => $views,
		'taps'               => $taps,
		'tap_total'          => $tap_total,
		'form_starts'        => $form_starts,
		'form_sends'         => $form_sends,
		'contacts'           => $tap_total + $form_sends,
		'sources'            => $sources,
		'campaigns'          => $campaigns,
		'contacts_by_source' => $contacts_by_source,
		'devices'            => $devices,
		'pages'              => $pages,
		'counties'           => $counties,
		'cities'             => $cities,
		'located'            => $located,
		'us_located'         => $us_located,
		'outside_area'       => $outside,
		'service_area'       => (string) deft_core_setting( 'tracking_service_area' ),
		'daily'              => $daily,
		'daily_contacts'     => $daily_contacts,
		'contact_visits'     => $contact_visits,
		'form_visits'        => $form_visits,
		'points'             => $points,
		'times'              => $times,
		'sections'           => $sections,
	);
}

/**
 * Located US visits from counties outside the service area (Settings > Deft), or null when no area is set.
 * Counties beyond the listed rows count as outside.
 */
function deft_report_outside_area( $counties, $us_located ) {
	$area = array_filter( array_map( 'strtolower', array_map( 'trim', explode( ',', (string) deft_core_setting( 'tracking_service_area' ) ) ) ) );
	if ( ! $area || ! $us_located ) {
		return null;
	}
	$strip  = function ( $name ) {
		return trim( preg_replace( '/\s+(county|parish|borough)$/', '', strtolower( trim( $name ) ) ) );
	};
	$area   = array_map( $strip, $area );
	$inside = 0;
	foreach ( $counties as $row ) {
		if ( in_array( $strip( explode( ',', $row['county'] )[0] ), $area, true ) ) {
			$inside += (int) $row['visits'];
		}
	}
	return max( 0, $us_located - $inside );
}

/** A month's report: saved totals for finished months, worked out live for the current one. */
function deft_report_get( $month ) {
	global $wpdb;
	$saved = $wpdb->get_var( $wpdb->prepare( "SELECT data FROM {$wpdb->prefix}deft_reports WHERE month = %s", $month ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $saved ) {
		$data = json_decode( $saved, true );
		if ( is_array( $data ) ) {
			return $data;
		}
	}
	return apply_filters( 'deft_report_month_data', deft_report_summary( $month ), $month );
}

/** Saves a finished month's totals once and sends the monthly email if it's on. */
function deft_report_close_month( $month ) {
	global $wpdb;
	$table = $wpdb->prefix . 'deft_reports';
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT month, sent FROM $table WHERE month = %s", $month ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $row ) {
		$data = apply_filters( 'deft_report_month_data', deft_report_summary( $month ), $month );
		if ( 'ga4' === ( $data['origin'] ?? '' ) || ( ! $data['views'] && ! $data['form_sends'] ) ) {
			return; // Nothing tracked that month (e.g. before tracking was switched on, or only Google Analytics numbers).
		}
		$wpdb->insert( $table, array( 'month' => $month, 'data' => wp_json_encode( $data ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = (object) array( 'month' => $month, 'sent' => null );
	}
	if ( empty( $row->sent ) && deft_core_setting( 'report_enabled' ) && deft_report_send( $month ) ) {
		$wpdb->update( $table, array( 'sent' => current_time( 'mysql', true ) ), array( 'month' => $month ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

/* ---------- Labels ---------- */

function deft_report_source_label( $source, $medium = '' ) {
	$names = array(
		'direct'     => __( 'Direct (typed in, bookmarks, apps)', 'deft-core' ),
		'google'     => 'Google',
		'bing'       => 'Bing',
		'facebook'   => 'Facebook',
		'instagram'  => 'Instagram',
		'yelp'       => 'Yelp',
		'nextdoor'   => 'Nextdoor',
		'youtube'    => 'YouTube',
		'tiktok'     => 'TikTok',
		'linkedin'   => 'LinkedIn',
		'x'          => 'X (Twitter)',
		'chatgpt'    => 'ChatGPT',
		'duckduckgo' => 'DuckDuckGo',
		'yahoo'      => 'Yahoo',
	);
	$label = $names[ $source ] ?? $source;
	$kinds = array(
		'search' => __( 'search', 'deft-core' ),
		'paid'   => __( 'ad', 'deft-core' ),
		'cpc'    => __( 'ad', 'deft-core' ),
		'ppc'    => __( 'ad', 'deft-core' ),
		'social' => __( 'post or profile', 'deft-core' ),
	);
	if ( $medium && 'referral' !== $medium && 'direct' !== $source ) {
		$label .= ' (' . ( $kinds[ $medium ] ?? $medium ) . ')';
	}
	return $label;
}

function deft_report_tap_label( $kind ) {
	$names = array(
		'call'       => __( 'Calls', 'deft-core' ),
		'whatsapp'   => __( 'WhatsApp', 'deft-core' ),
		'text'       => __( 'Texts', 'deft-core' ),
		'email'      => __( 'Emails', 'deft-core' ),
		'directions' => __( 'Directions', 'deft-core' ),
	);
	return $names[ $kind ] ?? $kind;
}

function deft_report_month_label( $month ) {
	return wp_date( 'F Y', strtotime( $month . '-15' ) );
}

function deft_report_change( $now, $before ) {
	if ( ! $before ) {
		return '';
	}
	$pct = round( 100 * ( $now - $before ) / $before );
	return ( $pct > 0 ? '+' : '' ) . $pct . '%';
}

/* The Site report screen itself is in modules/dashboard.php. */

/* ---------- Monthly email ---------- */

/** Emails a month's report to the report recipients. Returns whether WordPress mail accepted it. */
function deft_report_send( $month, $to = null ) {
	$to = $to ?? array_values( array_filter( array_map( 'trim', explode( ',', (string) deft_core_setting( 'report_recipients' ) ) ), 'is_email' ) );
	if ( ! $to ) {
		return false;
	}
	$r       = deft_report_get( $month );
	$prev    = deft_report_get( wp_date( 'Y-m', strtotime( $month . '-15 -1 month' ) ) );
	$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$subject = sprintf( /* translators: 1: site, 2: month */ __( '%1$s website report: %2$s', 'deft-core' ), $site, deft_report_month_label( $month ) );

	$headers = array_values(
		array_filter(
			deft_core_mail_headers(),
			function ( $h ) {
				return 0 !== stripos( $h, 'Content-Type:' );
			}
		)
	);
	$headers[] = 'Content-Type: text/html; charset=UTF-8';
	return wp_mail( $to, $subject, deft_report_email_html( $r, $prev, $site ), $headers );
}

function deft_report_email_html( $r, $prev, $site ) {
	$num  = function ( $n ) {
		return esc_html( number_format_i18n( (int) $n ) );
	};
	$line = function ( $label, $now, $before ) use ( $num ) {
		$change = deft_report_change( $now, $before );
		return '<tr><td style="padding:6px 0;color:#444">' . esc_html( $label ) . '</td><td style="padding:6px 0;text-align:right;font-weight:bold">' . $num( $now ) . '</td><td style="padding:6px 0 6px 10px;text-align:right;color:#888;font-size:13px">' . esc_html( $change ) . '</td></tr>';
	};
	$list = function ( $title, $rows, $value, $label, $limit = 5 ) use ( $num ) {
		if ( ! $rows ) {
			return '';
		}
		$html = '<h3 style="font-size:15px;margin:22px 0 6px">' . esc_html( $title ) . '</h3><table style="width:100%;border-collapse:collapse">';
		foreach ( array_slice( $rows, 0, $limit ) as $row ) {
			$html .= '<tr><td style="padding:4px 0;border-bottom:1px solid #eee">' . esc_html( $label( $row ) ) . '</td><td style="padding:4px 0;border-bottom:1px solid #eee;text-align:right">' . $num( $row[ $value ] ) . '</td></tr>';
		}
		return $html . '</table>';
	};

	$html  = '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:560px;margin:0 auto;color:#1d2327;font-size:15px;line-height:1.5">';
	$html .= '<h2 style="margin:0 0 4px">' . esc_html( $site ) . '</h2><p style="margin:0 0 16px;color:#666">' . esc_html( sprintf( /* translators: %s: month */ __( 'Website report for %s', 'deft-core' ), deft_report_month_label( $r['month'] ) ) ) . '</p>';
	$html .= '<table style="width:100%;border-collapse:collapse">';
	$html .= $line( __( 'Visits', 'deft-core' ), $r['visits'], $prev['visits'] );
	foreach ( $r['taps'] as $tap ) {
		$before = 0;
		foreach ( $prev['taps'] as $p ) {
			if ( $p['label'] === $tap['label'] ) {
				$before = (int) $p['taps'];
			}
		}
		$html .= $line( deft_report_tap_label( $tap['label'] ), $tap['taps'], $before );
	}
	$html .= $line( __( 'Form leads', 'deft-core' ), $r['form_sends'], $prev['form_sends'] );
	$html .= '</table>';

	$html .= $list( __( 'Where visitors came from', 'deft-core' ), $r['sources'], 'visits', function ( $row ) {
		return deft_report_source_label( $row['source'], $row['medium'] );
	} );
	$html .= $list( __( 'Calls, taps and forms by source', 'deft-core' ), $r['contacts_by_source'], 'contacts', function ( $row ) {
		return deft_report_source_label( $row['source'], $row['medium'] );
	} );
	$html .= $list( __( 'Visitors by county (approximate)', 'deft-core' ), $r['counties'], 'visits', function ( $row ) {
		return $row['county'];
	} );
	if ( null !== $r['outside_area'] && $r['us_located'] ) {
		$html .= '<p style="color:#666;font-size:13px">' . esc_html( sprintf( /* translators: 1: percent, 2: counties */ __( '%1$s%% of located US visits came from outside your service area (%2$s).', 'deft-core' ), round( 100 * $r['outside_area'] / $r['us_located'] ), $r['service_area'] ) ) . '</p>';
	}
	$html .= $list( __( 'Top pages', 'deft-core' ), $r['pages'], 'views', function ( $row ) {
		return $row['path'];
	} );

	$html .= '<p style="margin-top:24px;color:#888;font-size:12px">' . esc_html__( 'Visits are counted per visitor per day. Locations are approximate. No cookies are used and no one is personally identified.', 'deft-core' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=deft-report&month=' . $r['month'] ) ) . '">' . esc_html__( 'See the full report', 'deft-core' ) . '</a></p>';
	return $html . '</div>';
}

// "Email this report now" from the report screen's settings (also handy for testing).
add_action( 'admin_post_deft_report_send', 'deft_report_send_now' );
function deft_report_send_now() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'deft-core' ) );
	}
	check_admin_referer( 'deft_report_send' );
	$month = isset( $_POST['month'] ) && preg_match( '/^\d{4}-\d{2}$/', sanitize_text_field( wp_unslash( $_POST['month'] ) ) ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : wp_date( 'Y-m' );
	$to    = array_values( array_filter( array_map( 'trim', explode( ',', (string) deft_core_setting( 'report_recipients' ) ) ), 'is_email' ) );
	if ( ! $to ) {
		$to = deft_core_recipients();
	}
	$sent = deft_report_send( $month, $to );
	wp_safe_redirect( add_query_arg( array( 'page' => 'deft-report', 'month' => $month, 'deft_sent' => $sent ? 1 : 0 ), admin_url( 'admin.php' ) ) );
	exit;
}
