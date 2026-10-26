<?php
/**
 * The "Site report" dashboard in WP admin: a dark, Deft-styled screen drawn by assets/dashboard.js from the
 * month's totals (modules/reports.php). Switching months uses GET /wp-json/deft/v1/report, so the page doesn't reload.
 */

defined( 'ABSPATH' ) || exit;

function deft_dash_capability() {
	return apply_filters( 'deft_report_capability', 'edit_posts' );
}

add_action( 'admin_menu', 'deft_report_menu' );
function deft_report_menu() {
	if ( ! deft_core_setting( 'tracking_enabled' ) && ! get_option( 'deft_track_db' ) ) {
		return;
	}
	add_menu_page( __( 'Site report', 'deft-core' ), __( 'Site report', 'deft-core' ), deft_dash_capability(), 'deft-report', 'deft_report_page', 'dashicons-chart-area', 26 );
}

/* ---------- Data for one month ---------- */

/** Everything the dashboard draws for a month: its report, last month's for comparison, and the month's leads. */
function deft_dash_payload( $month ) {
	$prev_month = wp_date( 'Y-m', strtotime( $month . '-15 -1 month' ) );
	$report     = deft_report_get( $month );
	return array(
		'month'  => $month,
		'label'  => deft_report_month_label( $month ),
		'live'   => wp_date( 'Y-m' ) === $month,
		'today'  => wp_date( 'Y-m-d' ),
		'report' => deft_dash_labels( $report ),
		'land'   => deft_dash_land( $report['points'] ?? array() ),
		'prev'   => deft_dash_labels( deft_report_get( $prev_month ) ),
		'leads'  => deft_dash_leads( $month ),
		'ga'     => deft_dash_ga( $month, $report ),
	);
}

/** Google Analytics' totals for a month that has our own numbers too (with last month's, for comparisons). */
function deft_dash_ga( $month, $report ) {
	if ( ! function_exists( 'deft_ga_enabled' ) || ! deft_ga_enabled() || 'ga4' === ( $report['origin'] ?? '' ) ) {
		return null;
	}
	$ga = deft_ga_get( $month );
	if ( ! $ga ) {
		return null;
	}
	$prev = deft_ga_get( wp_date( 'Y-m', strtotime( $month . '-15 -1 month' ) ) );
	return array(
		'report' => deft_dash_labels( $ga ),
		'prev'   => $prev ? array_intersect_key( $prev, array_flip( array( 'visits', 'views', 'users', 'search' ) ) ) : null,
	);
}

/** ZIP centers around the middle of the month's visits, for the map's dot-field background. */
function deft_dash_land( $points ) {
	if ( ! $points ) {
		return array();
	}
	// The visit-weighted median, so a few far-away visits don't move it.
	$total = array_sum( wp_list_pluck( $points, 'visits' ) );
	$mid   = array();
	foreach ( array( 'lat', 'lon' ) as $axis ) {
		usort( $points, function ( $a, $b ) use ( $axis ) {
			return $a[ $axis ] <=> $b[ $axis ];
		} );
		$acc = 0;
		foreach ( $points as $p ) {
			$acc += $p['visits'];
			if ( $acc >= $total / 2 ) {
				$mid[ $axis ] = $p[ $axis ];
				break;
			}
		}
	}
	return deft_geo_zips_around( $mid['lat'], $mid['lon'] );
}

/** Adds readable names (source, tap kind, device) so the script doesn't need its own translations. */
function deft_dash_labels( $r ) {
	foreach ( array( 'sources', 'contacts_by_source' ) as $key ) {
		foreach ( (array) ( $r[ $key ] ?? array() ) as $i => $row ) {
			$r[ $key ][ $i ]['name'] = deft_report_source_label( $row['source'], $row['medium'] ?? '' );
		}
	}
	foreach ( (array) ( $r['campaigns'] ?? array() ) as $i => $row ) {
		$r['campaigns'][ $i ]['name'] = deft_report_source_label( $row['source'] );
	}
	foreach ( (array) ( $r['taps'] ?? array() ) as $i => $row ) {
		$r['taps'][ $i ]['name'] = deft_report_tap_label( $row['label'] );
	}
	$devices = array( 'phone' => __( 'Phone', 'deft-core' ), 'tablet' => __( 'Tablet', 'deft-core' ), 'desktop' => __( 'Computer', 'deft-core' ) );
	foreach ( (array) ( $r['devices'] ?? array() ) as $i => $row ) {
		$r['devices'][ $i ]['name'] = $devices[ $row['device'] ] ?? $row['device'];
	}
	return $r;
}

/** The month's form leads, newest first (at most 8), for the "Latest leads" panel. */
function deft_dash_leads( $month ) {
	if ( ! post_type_exists( 'deft_lead' ) || ! current_user_can( deft_forms_capability() ) ) {
		return array();
	}
	$start = $month . '-01 00:00:00';
	$query = new WP_Query(
		array(
			'post_type'      => 'deft_lead',
			'post_status'    => 'publish',
			'posts_per_page' => 8,
			'no_found_rows'  => false,
			'date_query'     => array(
				array(
					'after'     => $start,
					'before'    => gmdate( 'Y-m-d 00:00:00', strtotime( $month . '-01 00:00:00 UTC +1 month' ) ),
					'inclusive' => false,
				),
			),
		)
	);
	$sync  = array( 'synced' => __( 'In Central', 'deft-core' ), 'pending' => __( 'Waiting for Central', 'deft-core' ), 'failed' => __( 'Central failed', 'deft-core' ) );
	$leads = array();
	foreach ( $query->posts as $post ) {
		$fields  = (array) get_post_meta( $post->ID, '_deft_fields', true );
		$leads[] = array(
			'title'  => get_the_title( $post ),
			'trade'  => (string) ( $fields['trade'] ?? '' ),
			'when'   => get_the_date( 'M j, g:i a', $post ),
			'source' => deft_forms_source_line( (array) get_post_meta( $post->ID, '_deft_source', true ) ),
			'sync'   => $sync[ get_post_meta( $post->ID, '_deft_sync', true ) ] ?? '',
			'link'   => get_edit_post_link( $post->ID, 'raw' ),
		);
	}
	return array( 'list' => $leads, 'total' => (int) $query->found_posts, 'all' => admin_url( 'edit.php?post_type=deft_lead' ) );
}

/* ---------- REST: switch months without reloading ---------- */

add_action( 'rest_api_init', 'deft_dash_routes' );
function deft_dash_routes() {
	register_rest_route(
		'deft/v1',
		'/report',
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( deft_dash_capability() );
			},
			'args'                => array(
				'month' => array(
					'required'          => true,
					'validate_callback' => function ( $v ) {
						return is_string( $v ) && preg_match( '/^\d{4}-\d{2}$/', $v );
					},
				),
			),
			'callback'            => function ( WP_REST_Request $request ) {
				return rest_ensure_response( deft_dash_payload( $request['month'] ) );
			},
		)
	);
}

/* ---------- The screen ---------- */

add_action( 'admin_enqueue_scripts', 'deft_dash_enqueue' );
function deft_dash_enqueue( $hook ) {
	if ( 'toplevel_page_deft-report' !== $hook ) {
		return;
	}
	$url = plugins_url( 'assets/', DEFT_CORE_FILE );
	wp_enqueue_style( 'deft-dash', $url . 'dashboard.css', array(), DEFT_CORE_VERSION );
	wp_enqueue_script( 'deft-dash', $url . 'dashboard.js', array(), DEFT_CORE_VERSION, true );
	if ( function_exists( 'deft_ga_enabled' ) && deft_ga_enabled() && current_user_can( 'manage_options' ) ) {
		deft_ga_enqueue_sync();
	}
}

add_filter( 'admin_body_class', 'deft_dash_body_class' );
function deft_dash_body_class( $classes ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	return $screen && 'toplevel_page_deft-report' === $screen->id ? $classes . ' deft-dash-screen' : $classes;
}

function deft_report_page() {
	global $wpdb;
	$this_month = wp_date( 'Y-m' );
	$months     = $wpdb->get_col( "SELECT month FROM {$wpdb->prefix}deft_reports ORDER BY month DESC LIMIT 36" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ga         = function_exists( 'deft_ga_only_months' ) ? deft_ga_only_months() : array();
	$months     = array_slice( array_merge( $months, $ga ), 0, 72 );
	array_unshift( $months, $this_month );
	$months = array_values( array_unique( $months ) );
	rsort( $months );
	if ( function_exists( 'deft_ga_bridge_refresh' ) ) {
		deft_ga_bridge_refresh(); // This month's days before Deft Core started counting.
	}
	$month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : $this_month; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! in_array( $month, $months, true ) ) {
		$month = $this_month;
	}

	$data = array(
		'site'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'host'     => wp_parse_url( home_url(), PHP_URL_HOST ),
		'icon'     => plugins_url( 'assets/deft-icon.png', DEFT_CORE_FILE ),
		'months'   => array_map(
			function ( $m ) use ( $this_month, $ga ) {
				return array( 'value' => $m, 'label' => deft_report_month_label( $m ), 'short' => wp_date( 'M', strtotime( $m . '-15' ) ), 'live' => $m === $this_month, 'ga' => in_array( $m, $ga, true ) );
			},
			$months
		),
		'rest'     => esc_url_raw( rest_url( 'deft/v1/report' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'on'       => (bool) deft_core_setting( 'tracking_enabled' ),
		'week'     => (int) get_option( 'start_of_week', 0 ),
		'settings' => admin_url( 'options-general.php?page=deft-core' ),
		'canSend'  => current_user_can( 'manage_options' ),
		'send'     => array(
			'url'   => admin_url( 'admin-post.php' ),
			'nonce' => wp_create_nonce( 'deft_report_send' ),
		),
		'gaSync'   => function_exists( 'deft_ga_enabled' ) && deft_ga_enabled() && current_user_can( 'manage_options' ) ? deft_ga_sync_status() : null,
		'sent'     => isset( $_GET['deft_sent'] ) ? (int) $_GET['deft_sent'] : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'start'    => deft_dash_payload( $month ),
	);
	?>
	<div id="deft-dash" class="deft-dash" aria-busy="true">
		<noscript><p style="padding:24px"><?php esc_html_e( 'The Site report needs JavaScript switched on.', 'deft-core' ); ?></p></noscript>
	</div>
	<script>window.deftDash = <?php echo wp_json_encode( $data ); ?>;</script>
	<?php
}
