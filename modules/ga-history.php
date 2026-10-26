<?php
/**
 * Google Analytics in the Site report (off by default; needs Google Site Kit connected to Google Analytics).
 *
 * When "Show Google Analytics in the Site report" is ticked in Settings > Deft, a Sync button copies Google
 * Analytics' monthly totals (and Search Console's, when connected) into the deft_ga table, one row per month, in the
 * same shape as our own months. The Site report then shows:
 * - months with our own numbers: our numbers, plus a Google Analytics section (sessions, visitors, Google search);
 * - months from before Deft Core started counting: Google Analytics' numbers, tagged GA;
 * - the month Deft Core started counting in: Google Analytics for the days before, then ours.
 * Syncing again each month brings in the month that just ended and refreshes the current one. Nothing about
 * individual visitors is copied, only totals. Sites without Site Kit (or with the box unticked) stay Deft Core only.
 *
 * Syncing runs in the browser of the admin who connected Site Kit (one request per month), because Site Kit only
 * talks to Google as that signed-in user.
 */


defined( 'ABSPATH' ) || exit;

/** Whether Google Site Kit is installed and active. */
function deft_ga_sitekit_active() {
	return defined( 'GOOGLESITEKIT_VERSION' );
}

/* ---------- Asking Site Kit ---------- */

/** Runs a Site Kit data request in-process as the current user. Returns the response as arrays, or a WP_Error. */
function deft_ga_sitekit( $module, $datapoint, $params ) {
	$request = new WP_REST_Request( 'GET', '/google-site-kit/v1/modules/' . $module . '/data/' . $datapoint );
	$request->set_query_params( $params );
	$response = rest_do_request( $request );
	if ( $response->is_error() ) {
		$error = $response->as_error();
		return new WP_Error( 'deft_ga_sitekit', sprintf( /* translators: %s: error from Site Kit */ __( 'Site Kit said: %s', 'deft-core' ), $error->get_error_message() ) );
	}
	$data = $response->get_data();
	if ( is_object( $data ) && method_exists( $data, 'toSimpleObject' ) ) {
		$data = $data->toSimpleObject(); // Google API models.
	}
	return json_decode( wp_json_encode( $data ), true );
}

/**
 * One Google Analytics report. Returns rows as flat arrays: dimension values first, then metric values (numbers).
 *
 * @param string $dimensions Comma-separated GA4 dimensions ('' for totals).
 * @param string $metrics    Comma-separated GA4 metrics.
 */
function deft_ga_rows( $start, $end, $dimensions, $metrics, $limit = 250, $order_by = '' ) {
	$params = array(
		'startDate' => $start,
		'endDate'   => $end,
		'metrics'   => $metrics,
		'limit'     => $limit,
	);
	if ( $dimensions ) {
		$params['dimensions'] = $dimensions;
	}
	if ( $order_by ) {
		$params['orderby'] = array( array( 'metric' => array( 'metricName' => $order_by ), 'desc' => true ) );
	}
	$data = deft_ga_sitekit( 'analytics-4', 'report', $params );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$rows = array();
	foreach ( (array) ( $data['rows'] ?? array() ) as $row ) {
		$out = array();
		foreach ( (array) ( $row['dimensionValues'] ?? array() ) as $v ) {
			$out[] = (string) ( $v['value'] ?? '' );
		}
		foreach ( (array) ( $row['metricValues'] ?? array() ) as $v ) {
			$out[] = (float) ( $v['value'] ?? 0 );
		}
		$rows[] = $out;
	}
	return $rows;
}


/* ---------- Setting ---------- */

add_filter( 'deft_core_defaults', 'deft_ga_defaults' );
function deft_ga_defaults( $defaults ) {
	return $defaults + array( 'ga_enabled' => 0 );
}

add_filter( 'deft_core_sanitize', 'deft_ga_sanitize', 10, 2 );
function deft_ga_sanitize( $out, $input ) {
	$out['ga_enabled'] = empty( $input['ga_enabled'] ) ? 0 : 1;
	if ( $out['ga_enabled'] ) {
		deft_ga_install();
	}
	return $out;
}

/** Whether Google Analytics numbers are part of this site's report. */
function deft_ga_enabled() {
	return deft_ga_sitekit_active() && (bool) deft_core_setting( 'ga_enabled' );
}

add_action( 'deft_core_settings_sections', 'deft_ga_settings_fields', 15 );
function deft_ga_settings_fields( $s ) {
	?>
	<h2 id="deft-ga-setting"><?php esc_html_e( 'Google Analytics', 'deft-core' ); ?></h2>
	<p><?php esc_html_e( 'If this site uses Google Analytics through the Google Site Kit plugin, its monthly totals can be shown in the Site report next to Deft Core’s own numbers, including Google search clicks and searches from Search Console, and the months from before Deft Core started counting.', 'deft-core' ); ?></p>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><?php esc_html_e( 'Google Analytics', 'deft-core' ); ?></th>
			<td>
			<?php if ( deft_ga_sitekit_active() ) : ?>
				<label><input type="checkbox" name="deft_core[ga_enabled]" value="1" <?php checked( $s['ga_enabled'] ); ?>> <?php esc_html_e( 'Show Google Analytics in the Site report', 'deft-core' ); ?></label>
				<p class="description"><?php esc_html_e( 'Then press Sync Google Analytics (on the Site report or below) once a month. Google Analytics uses cookies, so mention it in the privacy policy.', 'deft-core' ); ?></p>
			<?php else : ?>
				<input type="hidden" name="deft_core[ga_enabled]" value="<?php echo esc_attr( $s['ga_enabled'] ); ?>">
				<em><?php esc_html_e( 'Not available: needs the Google Site Kit plugin, connected to Google Analytics. Without it the Site report uses Deft Core’s numbers only.', 'deft-core' ); ?></em>
			<?php endif; ?>
			</td></tr>
	</table>
	<?php
}

/* ---------- Storage ---------- */

function deft_ga_table() {
	global $wpdb;
	return $wpdb->prefix . 'deft_ga';
}

function deft_ga_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = deft_ga_table();
	dbDelta(
		"CREATE TABLE $table (
			month char(7) NOT NULL,
			data longtext NOT NULL,
			synced datetime NOT NULL,
			PRIMARY KEY  (month)
		) {$wpdb->get_charset_collate()};"
	);
	update_option( 'deft_ga_db', '1', false );
}

// 0.6.0-0.6.3 kept imported months in deft_reports with 'origin' => 'ga4'. Move them here once, and keep showing them.
add_action( 'admin_init', 'deft_ga_upgrade' );
function deft_ga_upgrade() {
	global $wpdb;
	if ( get_option( 'deft_ga_db' ) || ! get_option( 'deft_track_db' ) ) {
		return;
	}
	deft_ga_install();
	$reports = $wpdb->prefix . 'deft_reports';
	$rows    = $wpdb->get_results( "SELECT month, data FROM $reports WHERE data LIKE '%\"origin\":\"ga4\"%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $rows as $row ) {
		$data = json_decode( $row->data, true );
		if ( is_array( $data ) ) {
			deft_ga_save( $row->month, $data, $data['imported'] ?? current_time( 'mysql', true ) );
			$wpdb->delete( $reports, array( 'month' => $row->month ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
	if ( $rows ) {
		$settings               = get_option( 'deft_core', array() );
		$settings['ga_enabled'] = 1; // They were imported on purpose, so keep showing them.
		update_option( 'deft_core', $settings );
	}
}

/** A month's saved Google Analytics totals, or null. */
function deft_ga_get( $month ) {
	global $wpdb;
	if ( ! get_option( 'deft_ga_db' ) ) {
		return null;
	}
	$data = $wpdb->get_var( $wpdb->prepare( 'SELECT data FROM ' . deft_ga_table() . ' WHERE month = %s', $month ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	$data = $data ? json_decode( $data, true ) : null;
	return is_array( $data ) ? $data : null;
}

function deft_ga_save( $month, $data, $synced = '' ) {
	global $wpdb;
	$data['imported'] = $synced ? $synced : current_time( 'mysql', true );
	$wpdb->replace( deft_ga_table(), array( 'month' => $month, 'data' => wp_json_encode( $data ), 'synced' => $data['imported'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** Synced months and when, newest first: array( 'Y-m' => 'Y-m-d H:i:s' UTC ). */
function deft_ga_synced() {
	global $wpdb;
	if ( ! get_option( 'deft_ga_db' ) ) {
		return array();
	}
	$rows = $wpdb->get_results( 'SELECT month, synced FROM ' . deft_ga_table() . ' ORDER BY month DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	return wp_list_pluck( $rows, 'synced', 'month' );
}

/** Months the report shows from Google Analytics alone (no numbers of our own), newest first. */
function deft_ga_only_months() {
	if ( ! deft_ga_enabled() ) {
		return array();
	}
	$this_month = wp_date( 'Y-m' );
	return array_values( array_filter( array_keys( deft_ga_synced() ), function ( $m ) use ( $this_month ) {
		return $m < $this_month && ! deft_ga_own_month( $m );
	} ) );
}

/* ---------- Which months ---------- */

/** Months (Y-m) that already have our own numbers: a saved month of ours, or detailed records. */
function deft_ga_own_month( $month ) {
	global $wpdb;
	$saved = $wpdb->get_var( $wpdb->prepare( "SELECT data FROM {$wpdb->prefix}deft_reports WHERE month = %s", $month ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $saved && false === strpos( $saved, '"origin":"ga4"' ) ) {
		return true;
	}
	$start = $month . '-01';
	$end   = gmdate( 'Y-m-d', strtotime( $start . ' 00:00:00 UTC +1 month' ) );
	$t     = deft_track_table();
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $t WHERE day >= %s AND day < %s LIMIT 1", $start, $end ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/* ---------- One month ---------- */

/* ---------- One month ---------- */

/** Our source/medium for a GA session source and medium, using the same names as our own counting. */
function deft_ga_source( $source, $medium ) {
	$source = strtolower( trim( $source ) );
	$medium = strtolower( trim( $medium ) );
	if ( '' === $source || '(direct)' === $source || '(not set)' === $source ) {
		return array( 'direct', '' );
	}
	$host = false !== strpos( $source, '.' ) ? $source : $source . '.com';
	$out  = deft_track_source( 'https://' . $host . '/', array() );
	if ( 0 === strpos( $out['source'], $source ) || $out['source'] === $host ) {
		$out['source'] = preg_replace( '/^(www|m|l|lm|mobile)\./', '', $source ); // Unknown site: keep GA's name.
	}
	if ( in_array( $medium, array( 'cpc', 'ppc', 'paid', 'paidsearch', 'paid_social', 'paidsocial' ), true ) ) {
		$out['medium'] = 'paid';
	} elseif ( 'organic' === $medium && 'referral' === $out['medium'] ) {
		$out['medium'] = 'search';
	}
	return '(internal)' === $out['source'] ? array( 'direct', '' ) : array( $out['source'], $out['medium'] );
}

/**
 * A month's totals from Google Analytics (and Search Console when connected), in the deft_report_summary() shape.
 * With $through (Y-m-d), only the days up to it: the part of this month before Deft Core started counting.
 */
function deft_ga_month_summary( $month, $through = '' ) {
	$start = $month . '-01';
	$end   = $through ? $through : gmdate( 'Y-m-d', strtotime( $start . ' 00:00:00 UTC +1 month -1 day' ) );

	$daily_rows = deft_ga_rows( $start, $end, 'date', 'sessions,screenPageViews,totalUsers', 40 );
	if ( is_wp_error( $daily_rows ) ) {
		return $daily_rows;
	}
	$visits = 0;
	$views  = 0;
	$daily  = array();
	foreach ( $daily_rows as $row ) {
		$visits += $row[1];
		$views  += $row[2];
		$daily[] = array( 'day' => substr( $row[0], 0, 4 ) . '-' . substr( $row[0], 4, 2 ) . '-' . substr( $row[0], 6, 2 ), 'visits' => (int) $row[1] );
	}
	usort( $daily, function ( $a, $b ) {
		return strcmp( $a['day'], $b['day'] );
	} );
	$users = deft_ga_rows( $start, $end, '', 'totalUsers', 1 );
	$users = is_wp_error( $users ) || ! $users ? null : (int) $users[0][0];

	// Where visits came from.
	$by_source = array();
	foreach ( (array) deft_ga_rows( $start, $end, 'sessionSource,sessionMedium', 'sessions', 100, 'sessions' ) as $row ) {
		list( $source, $medium ) = deft_ga_source( $row[0], $row[1] );
		$key                       = $source . '|' . $medium;
		$by_source[ $key ]         = ( $by_source[ $key ] ?? 0 ) + (int) $row[2];
	}
	arsort( $by_source );
	$sources = array();
	foreach ( array_slice( $by_source, 0, 12, true ) as $key => $n ) {
		list( $source, $medium ) = explode( '|', $key );
		$sources[]               = array( 'source' => $source, 'medium' => $medium, 'visits' => $n );
	}

	$campaigns = array();
	foreach ( (array) deft_ga_rows( $start, $end, 'sessionCampaignName,sessionSource,sessionMedium', 'sessions', 30, 'sessions' ) as $row ) {
		if ( '' === $row[0] || '(' === $row[0][0] ) {
			continue; // (direct), (organic), (referral), (not set)
		}
		$campaigns[] = array( 'campaign' => $row[0], 'source' => deft_ga_source( $row[1], $row[2] )[0], 'visits' => (int) $row[3] );
	}
	$campaigns = array_slice( $campaigns, 0, 10 );

	$devices = array();
	foreach ( (array) deft_ga_rows( $start, $end, 'deviceCategory', 'sessions', 10, 'sessions' ) as $row ) {
		$names     = array( 'mobile' => 'phone', 'desktop' => 'desktop', 'tablet' => 'tablet' );
		$devices[] = array( 'device' => $names[ strtolower( $row[0] ) ] ?? strtolower( $row[0] ), 'visits' => (int) $row[1] );
	}

	$pages = array();
	foreach ( (array) deft_ga_rows( $start, $end, 'pagePath', 'screenPageViews,sessions', 15, 'screenPageViews' ) as $row ) {
		$pages[] = array( 'path' => deft_track_clean_path( $row[0] ), 'views' => (int) $row[1], 'visits' => (int) $row[2] );
	}

	// Places: GA reports cities; US cities get their county and center from our ZIP table.
	$cities     = array();
	$countries  = array();
	$counties   = array();
	$points     = array();
	$located    = 0;
	$us_located = 0;
	foreach ( (array) deft_ga_rows( $start, $end, 'city,region,countryId', 'sessions', 250, 'sessions' ) as $row ) {
		list( $city, $region, $country ) = $row;
		$n = (int) $row[3];
		if ( '' === $country || '(not set)' === $country ) {
			continue;
		}
		$located              += $n;
		$countries[ $country ] = ( $countries[ $country ] ?? 0 ) + $n;
		if ( '' === $city || '(not set)' === $city ) {
			continue;
		}
		if ( count( $cities ) < 15 ) {
			$cities[] = array( 'city' => $city, 'region' => '(not set)' === $region ? '' : $region, 'country' => $country, 'visits' => $n );
		}
		if ( 'US' === $country ) {
			$at = deft_geo_city_point( $city, deft_geo_state_code( $region ) );
			if ( $at ) {
				$us_located               += $n;
				$counties[ $at['county'] ] = ( $counties[ $at['county'] ] ?? 0 ) + $n;
				$points[]                  = array( 'zip' => '', 'county' => $at['county'], 'city' => $city, 'visits' => $n, 'lat' => $at['lat'], 'lon' => $at['lon'] );
			}
		}
	}
	arsort( $counties );
	arsort( $countries );
	$county_rows = array();
	foreach ( array_slice( $counties, 0, 15, true ) as $county => $n ) {
		$county_rows[] = array( 'county' => $county, 'visits' => $n );
	}
	$country_rows = array();
	foreach ( array_slice( $countries, 0, 10, true ) as $code => $n ) {
		$country_rows[] = array( 'country' => $code, 'visits' => $n );
	}

	// Best times (GA's hour is in the property's time zone; dayOfWeek 0 = Sunday, like ours).
	$times = array( 'visits' => array_fill( 0, 7, array_fill( 0, 24, 0 ) ) );
	// A part month is added to our own grid, which counts page views, so it asks for page views too.
	foreach ( (array) deft_ga_rows( $start, $end, 'dayOfWeek,hour', $through ? 'screenPageViews' : 'sessions', 200 ) as $row ) {
		$d = (int) $row[0];
		$h = (int) $row[1];
		if ( $d >= 0 && $d < 7 && $h >= 0 && $h < 24 ) {
			$times['visits'][ $d ][ $h ] += (int) $row[2];
		}
	}

	return array(
		'month'              => $month,
		'origin'             => 'ga4',
		'imported'           => current_time( 'mysql', true ),
		'visits'             => (int) $visits,
		'views'              => (int) $views,
		'users'              => $users,
		'taps'               => array(),
		'tap_total'          => 0,
		'form_starts'        => 0,
		'form_sends'         => 0,
		'contacts'           => 0,
		'sources'            => $sources,
		'campaigns'          => $campaigns,
		'contacts_by_source' => array(),
		'devices'            => $devices,
		'pages'              => $pages,
		'counties'           => $county_rows,
		'cities'             => $cities,
		'countries'          => $country_rows,
		'located'            => $located,
		'us_located'         => $us_located,
		'outside_area'       => deft_report_outside_area( $county_rows, $us_located ),
		'service_area'       => (string) deft_core_setting( 'tracking_service_area' ),
		'daily'              => $daily,
		'points'             => $points,
		'times'              => $times,
		'sections'           => array(),
		'search'             => deft_ga_search_console( $start, $end ),
	);
}

/** Google search clicks, impressions and top searches for a date range, or null if Search Console isn't connected. */
function deft_ga_search_console( $start, $end ) {
	$totals = deft_ga_sitekit( 'search-console', 'searchanalytics', array( 'startDate' => $start, 'endDate' => $end, 'dimensions' => 'date', 'limit' => 40 ) );
	if ( is_wp_error( $totals ) || ! is_array( $totals ) ) {
		return null;
	}
	$clicks      = 0;
	$impressions = 0;
	foreach ( $totals as $row ) {
		$clicks      += (int) ( $row['clicks'] ?? 0 );
		$impressions += (int) ( $row['impressions'] ?? 0 );
	}
	$queries = array();
	$rows    = deft_ga_sitekit( 'search-console', 'searchanalytics', array( 'startDate' => $start, 'endDate' => $end, 'dimensions' => 'query', 'limit' => 10 ) );
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$queries[] = array( 'query' => (string) ( $row['keys'][0] ?? '' ), 'clicks' => (int) ( $row['clicks'] ?? 0 ), 'impressions' => (int) ( $row['impressions'] ?? 0 ) );
	}
	usort( $queries, function ( $a, $b ) {
		return $b['impressions'] <=> $a['impressions'];
	} );
	return array( 'clicks' => $clicks, 'impressions' => $impressions, 'queries' => $queries );
}

/* ---------- Syncing ---------- */

/** Whether a month's saved copy is final: synced at least two days after the month ended (Google needs a day or so). */
function deft_ga_is_final( $month, $synced ) {
	return $synced >= gmdate( 'Y-m-d H:i:s', strtotime( $month . '-01 00:00:00 UTC +1 month +2 days' ) );
}

/**
 * What a sync would do: months Google Analytics has visits for (up to 3 years back) that are missing, or were synced
 * before they were over, plus the current month. Returns array( 'Y-m' => sessions ), newest first, or a WP_Error.
 */
function deft_ga_sync_plan() {
	$this_month = wp_date( 'Y-m' );
	$start      = gmdate( 'Y-m-01', strtotime( $this_month . '-01 00:00:00 UTC -36 months' ) );
	$rows       = deft_ga_rows( $start, wp_date( 'Y-m-d' ), 'yearMonth', 'sessions', 100 );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	$synced = deft_ga_synced();
	$months = array();
	foreach ( $rows as $row ) {
		$month = substr( $row[0], 0, 4 ) . '-' . substr( $row[0], 4, 2 );
		if ( $row[1] > 0 && ( $month === $this_month || ! isset( $synced[ $month ] ) || ! deft_ga_is_final( $month, $synced[ $month ] ) ) ) {
			$months[ $month ] = (int) $row[1];
		}
	}
	krsort( $months );
	return $months;
}

/** Copies one month from Google Analytics. Returns its totals or a WP_Error. */
function deft_ga_sync_month( $month ) {
	if ( $month > wp_date( 'Y-m' ) ) {
		return new WP_Error( 'deft_ga_future', __( 'That month hasn’t started yet.', 'deft-core' ) );
	}
	$data = deft_ga_month_summary( $month );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	deft_ga_save( $month, $data );
	if ( wp_date( 'Y-m' ) === $month ) {
		deft_ga_bridge_refresh( true );
	}
	return $data;
}

/** When the newest month that's over was last synced as final; '' if the sync is due. For the Site report's button. */
function deft_ga_sync_status() {
	$synced = deft_ga_synced();
	$last   = wp_date( 'Y-m', strtotime( wp_date( 'Y-m' ) . '-15 -1 month' ) );
	return array(
		'last' => $synced ? max( $synced ) : '',
		'due'  => ! isset( $synced[ $last ] ) || ! deft_ga_is_final( $last, $synced[ $last ] ),
	);
}

/* ---------- The month Deft Core started counting in: Google Analytics until then ---------- */

/** The first day of $month with our own numbers, or ''. */
function deft_ga_first_own_day( $month ) {
	global $wpdb;
	$start = $month . '-01';
	$end   = gmdate( 'Y-m-d', strtotime( $start . ' 00:00:00 UTC +1 month' ) );
	$t     = deft_track_table();
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(day) FROM $t WHERE day >= %s AND day < %s", $start, $end ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/** The last day of this month that Google Analytics should fill in: the day before our own counting began, or yesterday. '' when there is none. */
function deft_ga_bridge_through( $month ) {
	$first   = deft_ga_first_own_day( $month );
	$through = $first ? gmdate( 'Y-m-d', strtotime( $first . ' 00:00:00 UTC -1 day' ) ) : gmdate( 'Y-m-d', strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 UTC -1 day' ) );
	return $through >= $month . '-01' ? $through : '';
}

/** Saved part months by month. Last month's stays until its totals are saved on the 1st. */
function deft_ga_bridges() {
	$all = get_option( 'deft_ga_bridge' );
	return is_array( $all ) ? $all : array();
}

/** The saved part month for $month, or null. */
function deft_ga_bridge( $month ) {
	$bridge = deft_ga_bridges()[ $month ] ?? null;
	return is_array( $bridge ) && ! empty( $bridge['data'] ) ? $bridge : null;
}

/** Saves (or with null removes) $month's part month, and drops older ones whose month is closed. */
function deft_ga_bridge_save( $month, $bridge ) {
	global $wpdb;
	$all = deft_ga_bridges();
	if ( $bridge ) {
		$all[ $month ] = $bridge;
	} else {
		unset( $all[ $month ] );
	}
	foreach ( array_keys( $all ) as $m ) {
		$closed = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}deft_reports WHERE month = %s", $m ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $m < $month && ( $closed || $m < gmdate( 'Y-m', strtotime( $month . '-01 00:00:00 UTC -2 months' ) ) ) ) {
			unset( $all[ $m ] );
		}
	}
	if ( $all ) {
		update_option( 'deft_ga_bridge', $all, false );
	} else {
		delete_option( 'deft_ga_bridge' );
	}
}

/**
 * Copies this month's days before Deft Core started counting from Google Analytics. Asks Google again only when
 * those days changed (once a day until counting starts, then never) or when $force is set. Needs the Site Kit admin.
 */
function deft_ga_bridge_refresh( $force = false ) {
	$month   = wp_date( 'Y-m' );
	$through = deft_ga_bridge_through( $month );
	$saved   = deft_ga_bridge( $month );
	if ( ! $through ) {
		if ( $saved ) {
			deft_ga_bridge_save( $month, null );
		}
		return null;
	}
	if ( $saved && $saved['through'] === $through && ! $force ) {
		return $saved;
	}
	if ( ! deft_ga_enabled() || ! current_user_can( 'manage_options' ) ) {
		return $saved;
	}
	// Don't ask Google more than once an hour if it keeps failing.
	if ( ! $force && get_transient( 'deft_ga_bridge_tried' ) ) {
		return $saved;
	}
	set_transient( 'deft_ga_bridge_tried', 1, HOUR_IN_SECONDS );
	$data = deft_ga_month_summary( $month, $through );
	if ( is_wp_error( $data ) ) {
		return $force ? $data : $saved;
	}
	$bridge = array( 'month' => $month, 'through' => $through, 'data' => $data );
	deft_ga_bridge_save( $month, $bridge );
	return $bridge;
}

/** Adds the rows of $b to $a, matching on $keys and summing $sums, biggest first. */
function deft_ga_merge_rows( $a, $b, $keys, $sums, $limit = 15 ) {
	$out = array();
	foreach ( array_merge( (array) $a, (array) $b ) as $row ) {
		$id = implode( '|', array_map( function ( $k ) use ( $row ) {
			return (string) ( $row[ $k ] ?? '' );
		}, $keys ) );
		if ( ! isset( $out[ $id ] ) ) {
			$out[ $id ] = $row;
			foreach ( $sums as $s ) {
				$out[ $id ][ $s ] = 0;
			}
		}
		foreach ( $sums as $s ) {
			$out[ $id ][ $s ] += (int) ( $row[ $s ] ?? 0 );
		}
	}
	usort( $out, function ( $x, $y ) use ( $sums ) {
		return $y[ $sums[0] ] <=> $x[ $sums[0] ];
	} );
	return array_slice( $out, 0, $limit );
}

/*
 * The report's numbers for a month that isn't saved yet: Google Analytics alone for months from before Deft Core
 * started counting, and for the month counting started in, Google Analytics' days first, then ours.
 */
add_filter( 'deft_report_month_data', 'deft_ga_report_data', 10, 2 );
function deft_ga_report_data( $own, $month ) {
	if ( ! deft_ga_enabled() ) {
		return $own;
	}
	// A finished month with nothing of our own: Google Analytics' numbers on their own.
	if ( $month < wp_date( 'Y-m' ) && ! deft_ga_own_month( $month ) ) {
		$ga = deft_ga_get( $month );
		if ( $ga ) {
			return $ga;
		}
	}
	$bridge = deft_ga_bridge( $month );
	if ( ! $bridge ) {
		return $own;
	}
	$ga  = $bridge['data'];
	$out = $own;
	foreach ( array( 'visits', 'views', 'located', 'us_located' ) as $key ) {
		$out[ $key ] = (int) ( $own[ $key ] ?? 0 ) + (int) ( $ga[ $key ] ?? 0 );
	}
	$out['sources']   = deft_ga_merge_rows( $own['sources'] ?? array(), $ga['sources'] ?? array(), array( 'source', 'medium' ), array( 'visits' ), 12 );
	$out['campaigns'] = deft_ga_merge_rows( $own['campaigns'] ?? array(), $ga['campaigns'] ?? array(), array( 'campaign', 'source' ), array( 'visits' ), 10 );
	$out['devices']   = deft_ga_merge_rows( $own['devices'] ?? array(), $ga['devices'] ?? array(), array( 'device' ), array( 'visits' ), 10 );
	$out['pages']     = deft_ga_merge_rows( $own['pages'] ?? array(), $ga['pages'] ?? array(), array( 'path' ), array( 'views', 'visits' ) );
	$out['counties']  = deft_ga_merge_rows( $own['counties'] ?? array(), $ga['counties'] ?? array(), array( 'county' ), array( 'visits' ) );
	$out['cities']    = deft_ga_merge_rows( $own['cities'] ?? array(), $ga['cities'] ?? array(), array( 'city', 'region', 'country' ), array( 'visits' ) );
	$out['countries'] = $ga['countries'] ?? array();
	$out['points']    = array_merge( (array) ( $own['points'] ?? array() ), (array) ( $ga['points'] ?? array() ) );
	$out['daily']     = deft_ga_merge_rows( $own['daily'] ?? array(), array_filter( (array) ( $ga['daily'] ?? array() ), function ( $d ) use ( $bridge ) {
		return $d['day'] <= $bridge['through'];
	} ), array( 'day' ), array( 'visits' ), 40 );
	usort( $out['daily'], function ( $x, $y ) {
		return strcmp( $x['day'], $y['day'] );
	} );
	foreach ( (array) ( $ga['times']['visits'] ?? array() ) as $d => $hours ) {
		foreach ( (array) $hours as $h => $v ) {
			if ( isset( $out['times']['visits'][ $d ][ $h ] ) ) {
				$out['times']['visits'][ $d ][ $h ] += (int) $v;
			}
		}
	}
	$out['outside_area'] = deft_report_outside_area( $out['counties'], $out['us_located'] );
	$out['search']       = $ga['search'] ?? null;
	$out['origin']       = 'mixed';
	$out['ga_through']   = $bridge['through'];
	$out['ga_visits']    = (int) ( $ga['visits'] ?? 0 );
	return $out;
}

/* ---------- REST: the Sync buttons ---------- */

add_action( 'rest_api_init', 'deft_ga_routes' );
function deft_ga_routes() {
	register_rest_route(
		'deft/v1',
		'/ga-sync',
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'month' => array(
					'validate_callback' => function ( $v ) {
						return is_string( $v ) && preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $v );
					},
				),
			),
			'callback'            => function ( WP_REST_Request $request ) {
				if ( ! deft_ga_enabled() ) {
					return new WP_Error( 'deft_ga_off', __( 'Google Analytics is switched off in Settings > Deft, or Google Site Kit isn’t active.', 'deft-core' ), array( 'status' => 400 ) );
				}
				if ( ! get_option( 'deft_ga_db' ) ) {
					deft_ga_install();
				}
				if ( ! $request['month'] ) {
					$months = deft_ga_sync_plan();
					return is_wp_error( $months ) ? new WP_Error( $months->get_error_code(), $months->get_error_message(), array( 'status' => 502 ) ) : rest_ensure_response( array( 'months' => $months ) );
				}
				$data = deft_ga_sync_month( $request['month'] );
				if ( is_wp_error( $data ) ) {
					return new WP_Error( $data->get_error_code(), $data->get_error_message(), array( 'status' => 502 ) );
				}
				return rest_ensure_response( array( 'month' => $request['month'], 'visits' => $data['visits'] ) );
			},
		)
	);
}

/* ---------- Settings > Deft ---------- */

add_action( 'admin_post_deft_ga_remove', 'deft_ga_remove' );
function deft_ga_remove() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'deft-core' ) );
	}
	check_admin_referer( 'deft_ga_remove' );
	if ( get_option( 'deft_ga_db' ) ) {
		$wpdb->query( 'DELETE FROM ' . deft_ga_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}
	delete_option( 'deft_ga_bridge' );
	wp_safe_redirect( admin_url( 'options-general.php?page=deft-core#deft-ga' ) );
	exit;
}

add_action( 'deft_core_settings_after', 'deft_ga_settings_panel', 20 );
function deft_ga_settings_panel() {
	if ( ! current_user_can( 'manage_options' ) || ! deft_ga_enabled() ) {
		return;
	}
	$synced = deft_ga_synced();
	?>
	<hr>
	<h2 id="deft-ga"><?php esc_html_e( 'Sync Google Analytics', 'deft-core' ); ?></h2>
	<p>
		<?php
		echo esc_html(
			$synced
				? sprintf( /* translators: 1: number, 2: first month, 3: last month, 4: date */ __( '%1$s months synced (%2$s to %3$s), last on %4$s.', 'deft-core' ), number_format_i18n( count( $synced ) ), deft_report_month_label( array_key_last( $synced ) ), deft_report_month_label( array_key_first( $synced ) ), wp_date( get_option( 'date_format' ), strtotime( max( $synced ) . ' UTC' ) ) )
				: __( 'Nothing synced yet.', 'deft-core' )
		);
		?>
	</p>
	<p><button type="button" class="button button-primary" data-deft-ga-sync><?php esc_html_e( 'Sync Google Analytics', 'deft-core' ); ?></button> <span data-deft-ga-status style="margin-left:10px" aria-live="polite"></span></p>
	<p class="description"><?php esc_html_e( 'Press it once a month, logged in as the admin who connected Site Kit. It brings in the month that just ended, any missing months up to 3 years back, and this month so far. Keep the page open until it says done.', 'deft-core' ); ?></p>
	<?php if ( $synced ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove all synced Google Analytics numbers from the Site report?', 'deft-core' ) ); ?>');">
			<input type="hidden" name="action" value="deft_ga_remove">
			<?php wp_nonce_field( 'deft_ga_remove' ); ?>
			<?php submit_button( __( 'Remove synced Google Analytics numbers', 'deft-core' ), 'link-delete', 'submit', false ); ?>
		</form>
	<?php endif; ?>
	<?php
	deft_ga_enqueue_sync();
}

/** The script behind the Sync buttons (Settings > Deft and the Site report). */
function deft_ga_enqueue_sync() {
	wp_enqueue_script( 'deft-ga-sync', plugins_url( 'assets/ga-sync.js', DEFT_CORE_FILE ), array(), DEFT_CORE_VERSION, true );
	wp_add_inline_script( 'deft-ga-sync', 'window.deftGaSync = ' . wp_json_encode( deft_ga_sync_config() ) . ';', 'before' );
}

/** What the Sync buttons need. */
function deft_ga_sync_config() {
	return array(
		'url'    => esc_url_raw( rest_url( 'deft/v1/ga-sync' ) ),
		'nonce'  => wp_create_nonce( 'wp_rest' ),
		'report' => admin_url( 'admin.php?page=deft-report' ),
	);
}
