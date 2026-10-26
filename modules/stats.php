<?php
/**
 * Monthly statistics for Deft Central's client reports.
 *
 * GET /deft/v1/stats/YYYY-MM, signed with the site's Central secret like /deft/v1/health (or opened by Deft staff
 * while logged in). Read-only. It answers with the month's totals from the visitor statistics (with Google Analytics
 * and Search Console folded in when that's on), the month before for comparisons, and the number of leads the site's
 * forms saved. The month is part of the route, so it's covered by the signature. Central pulls last month from every
 * site on the 1st and drafts each client's report from it.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'deft_core_defaults', 'deft_stats_defaults' );
function deft_stats_defaults( $defaults ) {
	return $defaults + array( 'stats' => 1 );
}

add_filter( 'deft_core_sanitize', 'deft_stats_sanitize', 10, 2 );
function deft_stats_sanitize( $out, $input ) {
	$out['stats'] = empty( $input['stats'] ) ? 0 : 1;
	return $out;
}

add_action( 'deft_core_settings_sections', 'deft_stats_settings_fields', 8 );
function deft_stats_settings_fields( $s ) {
	?>
	<h2 id="deft-stats"><?php esc_html_e( 'Client reports', 'deft-core' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><?php esc_html_e( 'Monthly numbers', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[stats]" value="1" <?php checked( $s['stats'] ); ?>> <?php esc_html_e( 'Let Deft Central read the monthly visitor statistics for the client report', 'deft-core' ); ?></label>
			<p class="description"><?php esc_html_e( 'Totals only: visits, calls, taps, forms, where visitors came from, top pages and Google searches. No visitor details leave the site. Only answers requests signed with the Central secret.', 'deft-core' ); ?></p></td></tr>
	</table>
	<?php
}

add_action( 'rest_api_init', 'deft_stats_routes' );
function deft_stats_routes() {
	register_rest_route(
		'deft/v1',
		'/stats/(?P<month>\d{4}-(?:0[1-9]|1[0-2]))',
		array(
			'methods'             => 'GET',
			'callback'            => 'deft_stats_rest',
			'permission_callback' => 'deft_stats_rest_permission',
		)
	);
}

function deft_stats_rest_permission( WP_REST_Request $request ) {
	if ( is_user_logged_in() ) {
		return deft_pages_is_staff() ? true : new WP_Error( 'deft_not_staff', __( 'Only Deft staff can read the monthly numbers here.', 'deft-core' ), array( 'status' => 403 ) );
	}
	if ( ! deft_core_setting( 'stats' ) ) {
		return new WP_Error( 'deft_stats_off', __( 'Monthly numbers for Deft Central are switched off on this site (Settings > Deft > Client reports).', 'deft-core' ), array( 'status' => 403 ) );
	}
	return deft_central_verify_request( $request );
}

function deft_stats_rest( WP_REST_Request $request ) {
	$month = (string) $request['month'];
	if ( $month > wp_date( 'Y-m' ) ) {
		return new WP_Error( 'deft_stats_future', __( 'That month hasn\'t started yet.', 'deft-core' ), array( 'status' => 400 ) );
	}
	$prev = gmdate( 'Y-m', strtotime( $month . '-15 00:00:00 UTC -1 month' ) );
	return rest_ensure_response(
		array(
			'schema'   => 'deft.stats.v1',
			'site'     => array( 'id' => deft_central_site_id(), 'url' => home_url( '/' ), 'version' => DEFT_CORE_VERSION ),
			'month'    => $month,
			'complete' => $month < wp_date( 'Y-m' ),
			'tracking' => (bool) deft_core_setting( 'tracking_enabled' ),
			'now'      => deft_stats_month( $month ),
			'prev'     => deft_stats_month( $prev ),
		)
	);
}

/** One month's totals in the shape Central stores: small, no visitor-level rows, never the map points. */
function deft_stats_month( $month ) {
	$r     = deft_report_get( $month );
	$taps  = array();
	foreach ( (array) ( $r['taps'] ?? array() ) as $row ) {
		$taps[ (string) $row['label'] ] = (int) $row['taps'];
	}
	$rows  = function ( $list, $keys, $limit ) {
		$out = array();
		foreach ( array_slice( (array) $list, 0, $limit ) as $row ) {
			$out[] = array_intersect_key( (array) $row, array_flip( $keys ) );
		}
		return $out;
	};
	$search = $r['search'] ?? null;
	if ( ! $search && function_exists( 'deft_ga_enabled' ) && deft_ga_enabled() ) {
		$ga     = deft_ga_get( $month );
		$search = $ga['search'] ?? null;
	}
	return array(
		'month'              => $month,
		'origin'             => (string) ( $r['origin'] ?? 'deft' ),
		'visits'             => (int) ( $r['visits'] ?? 0 ),
		'views'              => (int) ( $r['views'] ?? 0 ),
		'calls'              => (int) ( $taps['call'] ?? 0 ) + (int) ( $taps['whatsapp'] ?? 0 ),
		'taps'               => $taps,
		'tap_total'          => (int) ( $r['tap_total'] ?? 0 ),
		'form_sends'         => (int) ( $r['form_sends'] ?? 0 ),
		'contacts'           => (int) ( $r['contacts'] ?? 0 ),
		'leads'              => deft_stats_leads( $month ),
		'sources'            => $rows( $r['sources'] ?? array(), array( 'source', 'medium', 'visits' ), 8 ),
		'contacts_by_source' => $rows( $r['contacts_by_source'] ?? array(), array( 'source', 'medium', 'contacts' ), 8 ),
		'pages'              => $rows( $r['pages'] ?? array(), array( 'path', 'views', 'visits' ), 8 ),
		'devices'            => $rows( $r['devices'] ?? array(), array( 'device', 'visits' ), 4 ),
		'counties'           => $rows( $r['counties'] ?? array(), array( 'county', 'visits' ), 8 ),
		'outside_area'       => $r['outside_area'] ?? null,
		'daily_contacts'     => $rows( $r['daily_contacts'] ?? array(), array( 'day', 'contacts' ), 31 ),
		'search'             => $search ? array(
			'clicks'      => (int) ( $search['clicks'] ?? 0 ),
			'impressions' => (int) ( $search['impressions'] ?? 0 ),
			'queries'     => $rows( $search['queries'] ?? array(), array( 'query', 'clicks', 'impressions' ), 8 ),
		) : null,
	);
}

/** Leads the site's forms saved that month (spam is dropped before saving). */
function deft_stats_leads( $month ) {
	if ( ! post_type_exists( 'deft_lead' ) ) {
		return 0;
	}
	$query = new WP_Query(
		array(
			'post_type'      => 'deft_lead',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'date_query'     => array(
				array(
					'after'     => $month . '-01 00:00:00',
					'before'    => gmdate( 'Y-m-d 00:00:00', strtotime( $month . '-01 00:00:00 UTC +1 month' ) ),
					'inclusive' => false,
				),
			),
		)
	);
	return (int) $query->found_posts;
}
