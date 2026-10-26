<?php
/**
 * Central sync: sends each saved lead to Deft Central (the deftCoreIngest Cloud Function).
 *
 * Off until Settings > Deft > Deft Central has an endpoint, site ID and secret and is switched on. The visitor never
 * waits on it: a new lead is queued and sent by WP-Cron right after. Every request is signed
 * (X-Deft-Signature: v1=HMAC-SHA256(secret, "<timestamp>.<body>")) and carries the lead's submission ID, so Central
 * stores it once however many times it's retried. Server errors and timeouts retry after 1 min, 5 min, 30 min, 2 h,
 * 6 h and 24 h; a refusal (bad secret, unknown site) stops and waits for "Send to Central now". Contract:
 * docs/deft-core-connector.md in the Deft Central repository.
 */

defined( 'ABSPATH' ) || exit;

const DEFT_CENTRAL_BACKOFF = array( 60, 300, 1800, 7200, 21600, 86400 );

function deft_central_secret() {
	return defined( 'DEFT_CORE_CENTRAL_SECRET' ) ? (string) DEFT_CORE_CENTRAL_SECRET : (string) deft_core_setting( 'central_secret' );
}

/** The endpoint, if usable: https, or plain http only on this machine (local testing). */
function deft_central_url() {
	$url    = (string) deft_core_setting( 'central_url' );
	$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
	$host   = wp_parse_url( $url, PHP_URL_HOST );
	$ok     = $host && ( 'https' === $scheme || ( 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) );
	return $ok ? $url : '';
}

/** Whether the settings let us talk to Central at all (ignores the on/off switch). */
function deft_central_configured() {
	return deft_central_url() && deft_core_setting( 'central_site_id' ) && strlen( deft_central_secret() ) >= 32;
}

function deft_central_ready() {
	return deft_core_setting( 'central_enabled' ) && deft_central_configured();
}

/* ---------- Requests from Central ---------- */

/**
 * Checks a request Deft Central signed with this site's secret, the same way Central checks ours:
 * X-Deft-Timestamp (within 5 minutes) and X-Deft-Signature: v1=HMAC-SHA256(secret, "<timestamp>.<METHOD>.<route>.<body>").
 * The method and route are signed too, so a signature for one call can't be replayed against another, and each
 * signature is accepted once.
 */
function deft_central_verify_request( WP_REST_Request $request ) {
	$secret = deft_central_secret();
	if ( strlen( $secret ) < 32 || ! deft_core_setting( 'central_site_id' ) ) {
		return new WP_Error( 'deft_unsigned', __( 'This site has no Deft Central secret yet.', 'deft-core' ), array( 'status' => 401 ) );
	}
	$timestamp = (string) $request->get_header( 'X-Deft-Timestamp' );
	$signature = (string) $request->get_header( 'X-Deft-Signature' );
	if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 || 0 !== strpos( $signature, 'v1=' ) ) {
		return new WP_Error( 'deft_unsigned', __( 'Missing or expired signature.', 'deft-core' ), array( 'status' => 401 ) );
	}
	$signed   = $timestamp . '.' . strtoupper( $request->get_method() ) . '.' . $request->get_route() . '.' . $request->get_body();
	$expected = 'v1=' . hash_hmac( 'sha256', $signed, $secret );
	if ( ! hash_equals( $expected, $signature ) ) {
		return new WP_Error( 'deft_bad_signature', __( 'The signature does not match this site\'s Deft Central secret.', 'deft-core' ), array( 'status' => 401 ) );
	}
	$seen = 'deft_sig_' . substr( sha1( $signature ), 0, 32 );
	if ( get_transient( $seen ) ) {
		return new WP_Error( 'deft_replayed', __( 'That signed request was already used.', 'deft-core' ), array( 'status' => 401 ) );
	}
	set_transient( $seen, 1, 600 );
	return true;
}

/* ---------- Queue ---------- */

add_action( 'deft_forms_lead_saved', 'deft_central_queue_new_lead' );
function deft_central_queue_new_lead( $lead_id ) {
	if ( deft_central_ready() ) {
		deft_central_queue( $lead_id, true );
		// Send it as soon as the visitor has their answer. WP-Cron only runs when someone visits, so on a quiet site the
		// scheduled send could wait hours; it stays as the fallback (and handles retries).
		add_action(
			'shutdown',
			function () use ( $lead_id ) {
				if ( function_exists( 'litespeed_finish_request' ) ) {
					litespeed_finish_request();
				} elseif ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				}
				if ( 'synced' === deft_central_send( $lead_id ) ) {
					wp_clear_scheduled_hook( 'deft_central_send', array( (int) $lead_id ) );
				}
			}
		);
	}
}

function deft_central_queue( $lead_id, $now = false ) {
	update_post_meta( $lead_id, '_deft_sync', 'pending' );
	update_post_meta( $lead_id, '_deft_sync_attempts', 0 );
	update_post_meta( $lead_id, '_deft_sync_next', time() );
	delete_post_meta( $lead_id, '_deft_sync_error' );
	if ( $now ) {
		wp_schedule_single_event( time(), 'deft_central_send', array( (int) $lead_id ) );
	}
}

add_filter( 'cron_schedules', 'deft_central_cron_schedule' );
function deft_central_cron_schedule( $schedules ) {
	$schedules['deft_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => __( 'Every 5 minutes', 'deft-core' ) );
	return $schedules;
}

add_action( 'init', 'deft_central_schedule_sweep' );
function deft_central_schedule_sweep() {
	if ( ! wp_next_scheduled( 'deft_central_sweep' ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'deft_five_minutes', 'deft_central_sweep' );
	}
}

// Every 5 minutes: send whatever is due (retries, and anything the single event missed).
add_action( 'deft_central_sweep', 'deft_central_sweep' );
function deft_central_sweep() {
	if ( ! deft_central_ready() ) {
		return;
	}
	$due = get_posts(
		array(
			'post_type'   => 'deft_lead',
			'post_status' => 'publish',
			'numberposts' => 20,
			'fields'      => 'ids',
			'orderby'     => 'date',
			'order'       => 'ASC',
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array( 'key' => '_deft_sync', 'value' => 'pending' ),
				array( 'key' => '_deft_sync_next', 'value' => time(), 'compare' => '<=', 'type' => 'NUMERIC' ),
			),
		)
	);
	foreach ( $due as $lead_id ) {
		deft_central_send( $lead_id );
	}
}

/* ---------- Sending ---------- */

add_action( 'deft_central_send', 'deft_central_send' );

/** Sends one lead. Returns the new sync status: synced, pending (will retry) or failed. */
function deft_central_send( $lead_id ) {
	if ( ! deft_central_ready() || 'deft_lead' !== get_post_type( $lead_id ) ) {
		return 'off';
	}
	if ( 'synced' === get_post_meta( $lead_id, '_deft_sync', true ) ) {
		return 'synced';
	}
	$lock = 'deft_central_lock_' . $lead_id;
	if ( get_transient( $lock ) ) {
		return 'pending';
	}
	set_transient( $lock, 1, 60 );

	$response = deft_central_post( deft_central_payload( $lead_id ) );
	$attempts = (int) get_post_meta( $lead_id, '_deft_sync_attempts', true ) + 1;
	update_post_meta( $lead_id, '_deft_sync_attempts', $attempts );

	if ( $response['ok'] ) {
		update_post_meta( $lead_id, '_deft_sync', 'synced' );
		update_post_meta( $lead_id, '_deft_sync_at', time() );
		update_post_meta( $lead_id, '_deft_central', array_intersect_key( $response['body'], array_flip( array( 'inboxId', 'leadId', 'duplicate' ) ) ) );
		delete_post_meta( $lead_id, '_deft_sync_error' );
		$status = 'synced';
	} else {
		update_post_meta( $lead_id, '_deft_sync_error', $response['error'] );
		if ( $response['retry'] && $attempts <= count( DEFT_CENTRAL_BACKOFF ) ) {
			update_post_meta( $lead_id, '_deft_sync_next', time() + DEFT_CENTRAL_BACKOFF[ $attempts - 1 ] );
			$status = 'pending';
		} else {
			update_post_meta( $lead_id, '_deft_sync', 'failed' );
			$status = 'failed';
		}
	}
	if ( 'pending' === $status ) {
		update_post_meta( $lead_id, '_deft_sync', 'pending' );
	}
	delete_transient( $lock );
	return $status;
}

/** The deft.lead.v1 body for one lead. */
function deft_central_payload( $lead_id ) {
	$forms  = deft_forms_definitions();
	$key    = get_post_meta( $lead_id, '_deft_form', true );
	$values = (array) get_post_meta( $lead_id, '_deft_fields', true );
	$labels = array();
	foreach ( $values as $name => $value ) {
		$labels[ $name ] = $forms[ $key ]['fields'][ $name ]['label'] ?? $name;
	}
	// Leads saved before 0.2.0 have no submission ID: give them one now and keep it, so retries still dedupe.
	$submission_id = get_post_meta( $lead_id, '_deft_submission_id', true );
	if ( ! $submission_id ) {
		$submission_id = wp_generate_uuid4();
		update_post_meta( $lead_id, '_deft_submission_id', $submission_id );
	}
	return array(
		'schema'       => 'deft.lead.v1',
		'submissionId' => $submission_id,
		'site'         => array( 'id' => deft_core_setting( 'central_site_id' ), 'url' => home_url( '/' ) ),
		'form'         => array( 'key' => $key, 'label' => $forms[ $key ]['label'] ?? $key ),
		'submittedAt'  => get_post_time( 'c', true, $lead_id ),
		'fields'       => $values,
		'labels'       => $labels,
		'contact'      => array(
			'name'     => $values['name'] ?? '',
			'business' => $values['business'] ?? '',
			'phone'    => $values['phone'] ?? '',
			'email'    => $values['email'] ?? '',
		),
		'source'       => (array) get_post_meta( $lead_id, '_deft_source', true ),
		'wordpress'    => array( 'leadId' => (int) $lead_id, 'pluginVersion' => DEFT_CORE_VERSION ),
	);
}

/**
 * Signs and posts a body to Central.
 * Returns ok, retry (worth trying again later), body (decoded reply) and a short error for the Leads screen.
 */
function deft_central_post( $payload ) {
	$body      = wp_json_encode( $payload );
	$timestamp = time();
	$response  = wp_remote_post(
		deft_central_url(),
		array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'     => 'application/json',
				'X-Deft-Site'      => deft_core_setting( 'central_site_id' ),
				'X-Deft-Timestamp' => (string) $timestamp,
				'X-Deft-Signature' => 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, deft_central_secret() ),
			),
			'body'    => $body,
		)
	);
	if ( is_wp_error( $response ) ) {
		return array( 'ok' => false, 'retry' => true, 'body' => array(), 'error' => $response->get_error_message() );
	}
	$code  = (int) wp_remote_retrieve_response_code( $response );
	$reply = json_decode( wp_remote_retrieve_body( $response ), true );
	$reply = is_array( $reply ) ? $reply : array();
	if ( 200 === $code && ! empty( $reply['ok'] ) ) {
		return array( 'ok' => true, 'retry' => false, 'body' => $reply, 'error' => '' );
	}
	$error = trim( $code . ' ' . ( $reply['code'] ?? '' ) . ( isset( $reply['message'] ) ? ': ' . $reply['message'] : '' ) );
	return array( 'ok' => false, 'retry' => $code >= 500 || in_array( $code, array( 0, 408, 429 ), true ), 'body' => $reply, 'error' => mb_substr( $error, 0, 300 ) );
}

/* ---------- Leads screen ---------- */

add_filter( 'manage_deft_lead_posts_columns', 'deft_central_column', 20 );
function deft_central_column( $columns ) {
	$date = $columns['date'];
	unset( $columns['date'] );
	$columns['deft_central'] = __( 'Central', 'deft-core' );
	$columns['date']         = $date;
	return $columns;
}

add_action( 'manage_deft_lead_posts_custom_column', 'deft_central_column_value', 10, 2 );
function deft_central_column_value( $column, $post_id ) {
	if ( 'deft_central' !== $column ) {
		return;
	}
	$status = get_post_meta( $post_id, '_deft_sync', true );
	$labels = array(
		'synced'  => __( 'Sent', 'deft-core' ),
		'pending' => __( 'Waiting', 'deft-core' ),
		'failed'  => __( 'Failed', 'deft-core' ),
	);
	if ( ! isset( $labels[ $status ] ) ) {
		echo '&mdash;';
		return;
	}
	$error = get_post_meta( $post_id, '_deft_sync_error', true );
	$style = 'failed' === $status ? ' style="color:#b32d2e;font-weight:600"' : '';
	echo '<span' . $style . ( $error ? ' title="' . esc_attr( $error ) . '"' : '' ) . '>' . esc_html( $labels[ $status ] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

add_filter( 'post_row_actions', 'deft_central_row_action', 10, 2 );
function deft_central_row_action( $actions, $post ) {
	if ( 'deft_lead' === $post->post_type && deft_central_ready() && 'synced' !== get_post_meta( $post->ID, '_deft_sync', true ) ) {
		$url                    = wp_nonce_url( admin_url( 'admin-post.php?action=deft_central_send&lead=' . $post->ID ), 'deft_central_send_' . $post->ID );
		$actions['deft_central'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Send to Central now', 'deft-core' ) . '</a>';
	}
	return $actions;
}

add_action( 'admin_post_deft_central_send', 'deft_central_send_now' );
function deft_central_send_now() {
	$lead_id = isset( $_GET['lead'] ) ? absint( $_GET['lead'] ) : 0;
	if ( ! current_user_can( 'edit_post', $lead_id ) ) {
		wp_die( esc_html__( 'Not allowed.', 'deft-core' ) );
	}
	check_admin_referer( 'deft_central_send_' . $lead_id );
	deft_central_queue( $lead_id );
	$status = deft_central_send( $lead_id );
	wp_safe_redirect( add_query_arg( 'deft_central', $status, admin_url( 'edit.php?post_type=deft_lead' ) ) );
	exit;
}

add_action( 'admin_notices', 'deft_central_notice' );
function deft_central_notice() {
	$status = isset( $_GET['deft_central'] ) ? sanitize_key( $_GET['deft_central'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$text   = array(
		'synced'  => __( 'Sent to Deft Central.', 'deft-core' ),
		'pending' => __( 'Deft Central didn\'t answer. It will be retried automatically.', 'deft-core' ),
		'failed'  => __( 'Deft Central refused the lead. Hover "Failed" in the Central column for the reason.', 'deft-core' ),
		'queued'  => __( 'Unsent leads queued. They go out over the next few minutes.', 'deft-core' ),
	);
	if ( isset( $text[ $status ] ) ) {
		$class = in_array( $status, array( 'synced', 'queued' ), true ) ? 'notice-success' : 'notice-warning';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text[ $status ] ) . '</p></div>';
	}
}

/* ---------- Settings actions ---------- */

add_action( 'admin_post_deft_central_ping', 'deft_central_ping' );
function deft_central_ping() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'deft-core' ) );
	}
	check_admin_referer( 'deft_central_ping' );
	$result = array( 'ok' => false, 'error' => __( 'Fill in the endpoint (https), site ID and secret, and save first.', 'deft-core' ) );
	if ( deft_central_configured() ) {
		$result = deft_central_post( array( 'schema' => 'deft.ping.v1', 'site' => array( 'id' => deft_core_setting( 'central_site_id' ) ) ) );
	}
	set_transient( 'deft_central_ping_' . get_current_user_id(), $result['ok'] ? 'ok' : $result['error'], 60 );
	wp_safe_redirect( admin_url( 'options-general.php?page=deft-core#deft-central' ) );
	exit;
}

// Leads saved before Central was switched on (or that failed): queue them all.
add_action( 'admin_post_deft_central_backfill', 'deft_central_backfill' );
function deft_central_backfill() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'deft-core' ) );
	}
	check_admin_referer( 'deft_central_backfill' );
	$unsent = get_posts(
		array(
			'post_type'   => 'deft_lead',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array( 'key' => '_deft_sync', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_deft_sync', 'value' => 'failed' ),
			),
		)
	);
	foreach ( $unsent as $lead_id ) {
		deft_central_queue( $lead_id );
	}
	wp_safe_redirect( add_query_arg( 'deft_central', 'queued', admin_url( 'edit.php?post_type=deft_lead' ) ) );
	exit;
}
