<?php
/**
 * Forms: the site's own form handler, replacing Elementor Pro forms.
 *
 * The page's script posts the answers to /wp-json/deft/v1/forms/<form> (JSON or form-encoded). This module checks them
 * against the form's field list, drops spam quietly (honeypot, too-fast sends, rate limit), saves the lead under
 * Leads, emails it to the addresses in Settings > Deft through wp_mail (so any SMTP plugin applies), and sends the
 * visitor an auto-reply when they gave an email. Forms are defined in deft_forms_definitions(); add more there or
 * through the deft_forms filter.
 *
 * Every lead also keeps where it came from (_source: the page, referrer and UTM campaign tags) and a unique submission
 * ID (_submission_id, made by the page). A repeat of the same ID (double-click, retry) answers "sent" again without a
 * second lead or email; the ID is also what Deft Central will use to drop duplicates when the sync module arrives.
 *
 * Forms with 'extras' on (the contact form) also keep any other field the page sends, so a form designed for one site
 * can ask its own questions ("project type", "budget") without a change here. See deft_forms_extras().
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field types: text, email, phone, url, textarea, list (an array or comma list, stored as "a, b").
 * one_of: at least one of these fields must be filled in.
 */
function deft_forms_definitions() {
	$forms = array(
		'preview' => array(
			'label'   => __( 'Free preview (Contact page)', 'deft-core' ),
			'subject' => __( 'New preview request: {business} ({trade})', 'deft-core' ),
			'title'   => '{business}, {name}',
			'fields'  => array(
				'name'     => array( 'label' => __( 'Name', 'deft-core' ), 'required' => true, 'max' => 120 ),
				'business' => array( 'label' => __( 'Business', 'deft-core' ), 'required' => true, 'max' => 160 ),
				'trade'    => array( 'label' => __( 'What they do', 'deft-core' ), 'required' => true, 'max' => 160 ),
				'phone'    => array( 'label' => __( 'Phone', 'deft-core' ), 'type' => 'phone', 'max' => 40 ),
				'email'    => array( 'label' => __( 'Email', 'deft-core' ), 'type' => 'email', 'max' => 160 ),
				'area'     => array( 'label' => __( 'Areas', 'deft-core' ), 'max' => 160 ),
				'site'     => array( 'label' => __( 'Current website', 'deft-core' ), 'max' => 300 ),
				'plan'     => array( 'label' => __( 'Website plan', 'deft-core' ), 'max' => 80 ),
				'also'     => array( 'label' => __( 'Also interested in', 'deft-core' ), 'type' => 'list', 'max' => 300 ),
				'note'     => array( 'label' => __( 'Anything else', 'deft-core' ), 'type' => 'textarea', 'max' => 2000 ),
				'page'     => array( 'label' => __( 'Sent from', 'deft-core' ), 'type' => 'url', 'max' => 500 ),
			),
			'one_of'  => array( 'phone', 'email' ),
			'list'    => array( 'business', 'name', 'phone', 'email', 'plan' ),
		),
		// A plain contact form for client sites: POST /wp-json/deft/v1/forms/contact.
		'contact' => array(
			'label'   => __( 'Contact form', 'deft-core' ),
			'subject' => __( 'New message from {name}', 'deft-core' ),
			'title'   => '{name}',
			'fields'  => array(
				'name'    => array( 'label' => __( 'Name', 'deft-core' ), 'required' => true, 'max' => 120 ),
				'phone'   => array( 'label' => __( 'Phone', 'deft-core' ), 'type' => 'phone', 'max' => 40 ),
				'email'   => array( 'label' => __( 'Email', 'deft-core' ), 'type' => 'email', 'max' => 160 ),
				'service' => array( 'label' => __( 'Service', 'deft-core' ), 'max' => 160 ),
				'message' => array( 'label' => __( 'Message', 'deft-core' ), 'type' => 'textarea', 'max' => 4000 ),
				'page'    => array( 'label' => __( 'Sent from', 'deft-core' ), 'type' => 'url', 'max' => 500 ),
			),
			'one_of'  => array( 'phone', 'email' ),
			'list'    => array( 'name', 'phone', 'email', 'service' ),
			'extras'  => true,
		),
	);
	return apply_filters( 'deft_forms', $forms );
}

/* ---------- Leads post type ---------- */

/**
 * Who can see, export and delete leads (names, phones and emails): editors and admins by default, never authors or
 * contributors. Change it with the deft_leads_capability filter.
 */
function deft_forms_capability() {
	return apply_filters( 'deft_leads_capability', 'edit_others_posts' );
}

add_action( 'init', 'deft_forms_register_post_type' );
function deft_forms_register_post_type() {
	register_post_type(
		'deft_lead',
		array(
			'labels'          => array(
				'name'               => __( 'Leads', 'deft-core' ),
				'singular_name'      => __( 'Lead', 'deft-core' ),
				'edit_item'          => __( 'Lead', 'deft-core' ),
				'search_items'       => __( 'Search leads', 'deft-core' ),
				'not_found'          => __( 'No leads yet.', 'deft-core' ),
				'not_found_in_trash' => __( 'No leads in the trash.', 'deft-core' ),
				'all_items'          => __( 'All leads', 'deft-core' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'show_in_rest'    => false,
			'menu_position'   => 26,
			'menu_icon'       => 'dashicons-email-alt',
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'capabilities'    => array(
				'create_posts'           => 'do_not_allow',
				'edit_posts'             => deft_forms_capability(),
				'edit_others_posts'      => deft_forms_capability(),
				'edit_published_posts'   => deft_forms_capability(),
				'edit_private_posts'     => deft_forms_capability(),
				'publish_posts'          => deft_forms_capability(),
				'read_private_posts'     => deft_forms_capability(),
				'delete_posts'           => deft_forms_capability(),
				'delete_others_posts'    => deft_forms_capability(),
				'delete_published_posts' => deft_forms_capability(),
				'delete_private_posts'   => deft_forms_capability(),
			),
			'map_meta_cap'    => true,
			'rewrite'         => false,
			'query_var'       => false,
		)
	);
}

/* ---------- Endpoint ---------- */

add_action( 'rest_api_init', 'deft_forms_register_route' );
function deft_forms_register_route() {
	register_rest_route(
		'deft/v1',
		'/forms/(?P<form>[a-z0-9-]+)',
		array(
			'methods'             => 'POST',
			'callback'            => 'deft_forms_submit',
			'permission_callback' => '__return_true', // Public contact form; spam checks below.
		)
	);
}

function deft_forms_submit( WP_REST_Request $request ) {
	$forms = deft_forms_definitions();
	$key   = $request['form'];
	if ( ! isset( $forms[ $key ] ) ) {
		return deft_forms_error( 'unknown_form', __( 'This form is not set up.', 'deft-core' ), 404 );
	}
	$form   = $forms[ $key ];
	$params = $request->get_json_params();
	if ( ! is_array( $params ) ) {
		$params = $request->get_body_params();
	}
	$thanks = (string) ( deft_core_site_profile( 'thanks' ) ?? __( 'Thanks! We got your message and will be in touch soon.', 'deft-core' ) );
	$thanks = (string) apply_filters( 'deft_forms_thanks', $thanks, $key );

	// Spam: bots fill the hidden website_url field and send instantly. Pretend it worked so they don't adapt.
	$elapsed = isset( $params['_elapsed'] ) ? (int) $params['_elapsed'] : 0;
	if ( ! empty( $params['website_url'] ) || ( $elapsed > 0 && $elapsed < 3000 ) ) {
		return rest_ensure_response( array( 'ok' => true, 'message' => $thanks ) );
	}

	// Same submission sent twice (double-click, a retry after a dropped connection): answer as before, change nothing.
	$submission_id = isset( $params['_submission_id'] ) && is_string( $params['_submission_id'] ) && preg_match( '/^[A-Za-z0-9-]{8,64}$/', $params['_submission_id'] )
		? $params['_submission_id'] : wp_generate_uuid4();
	if ( deft_forms_find_submission( $submission_id ) ) {
		return rest_ensure_response( array( 'ok' => true, 'message' => $thanks ) );
	}

	// Rate limit: 5 sends per visitor per 10 minutes. Only a hash of the IP is kept, for 10 minutes.
	$ip_key = 'deft_rl_' . substr( wp_hash( deft_core_client_ip() ), 0, 20 );
	$count  = (int) get_transient( $ip_key );
	if ( $count >= apply_filters( 'deft_forms_rate_limit', 5 ) ) {
		return deft_forms_error( 'rate_limited', deft_forms_message( 'rate_limited', __( 'Too many sends in a row. Please wait a few minutes and try again.', 'deft-core' ) ), 429 );
	}

	// Clean and check every field.
	$values  = array();
	$missing = array();
	$invalid = array();
	foreach ( $form['fields'] as $name => $field ) {
		$values[ $name ] = deft_forms_clean( $params[ $name ] ?? '', $field );
		if ( ! empty( $field['required'] ) && '' === $values[ $name ] ) {
			$missing[] = $name;
		}
		$raw = isset( $params[ $name ] ) && is_scalar( $params[ $name ] ) ? trim( sanitize_text_field( (string) $params[ $name ] ) ) : '';
		if ( 'email' === ( $field['type'] ?? '' ) && '' !== $raw && ! is_email( $raw ) ) {
			$invalid[] = $name;
		}
	}
	if ( ! empty( $form['one_of'] ) && ! array_filter( array_intersect_key( $values, array_flip( $form['one_of'] ) ) ) ) {
		$missing = array_merge( $missing, $form['one_of'] );
	}
	if ( $invalid ) {
		return deft_forms_error( 'invalid', __( 'That email doesn\'t look right. Please check it.', 'deft-core' ), 400, $invalid );
	}
	if ( $missing ) {
		return deft_forms_error( 'missing', __( 'Please fill in the highlighted fields.', 'deft-core' ), 400, $missing );
	}
	set_transient( $ip_key, $count + 1, 10 * MINUTE_IN_SECONDS );

	$labels = array();
	foreach ( $form['fields'] as $name => $field ) {
		$labels[ $name ] = $field['label'];
	}
	if ( ! empty( $form['extras'] ) ) {
		list( $extra_values, $extra_labels ) = deft_forms_extras( $params, $form );
		$values += $extra_values;
		$labels += $extra_labels;
	}

	// Save the lead.
	$lead_id = wp_insert_post(
		array(
			'post_type'   => 'deft_lead',
			'post_status' => 'publish',
			'post_title'  => wp_strip_all_tags( deft_forms_fill( $form['title'], $values ) ),
		),
		true
	);
	if ( is_wp_error( $lead_id ) ) {
		return deft_forms_error( 'save_failed', deft_forms_message( 'save_failed', __( 'That didn\'t go through. Please try again in a moment.', 'deft-core' ) ), 500 );
	}
	update_post_meta( $lead_id, '_deft_form', $key );
	update_post_meta( $lead_id, '_deft_fields', $values );
	update_post_meta( $lead_id, '_deft_labels', $labels );
	update_post_meta( $lead_id, '_deft_submission_id', $submission_id );
	$source = deft_forms_source( $params['_source'] ?? array(), $request );
	update_post_meta( $lead_id, '_deft_source', $source );

	// Email the lead, then the auto-reply. A failed email never loses the lead: it's saved and marked under Leads.
	$lines = array();
	foreach ( $values as $name => $value ) {
		if ( '' !== $value ) {
			$lines[] = $labels[ $name ] . ': ' . $value;
		}
	}
	if ( $source ) {
		$lines[] = '';
		$lines[] = __( 'Came from:', 'deft-core' ) . ' ' . deft_forms_source_line( $source );
	}
	$lines[] = '';
	$lines[] = __( 'Open in WordPress:', 'deft-core' ) . ' ' . admin_url( 'post.php?post=' . $lead_id . '&action=edit' );
	$sent    = wp_mail(
		deft_core_recipients(),
		deft_forms_fill( $form['subject'], $values ),
		implode( "\n", $lines ),
		deft_core_mail_headers( $values['email'] ?? '' )
	);
	update_post_meta( $lead_id, '_deft_mail', $sent ? 'sent' : 'failed' );

	if ( ! empty( $values['email'] ) && deft_core_setting( 'autoreply' ) && deft_forms_autoreply_allowed( $values['email'] ) ) {
		$recipients = deft_core_recipients();
		$replied    = wp_mail(
			$values['email'],
			deft_forms_fill( deft_core_setting( 'autoreply_subject' ), $values ),
			deft_forms_fill( deft_core_setting( 'autoreply_body' ), $values ),
			deft_core_mail_headers( $recipients ? $recipients[0] : '' )
		);
		update_post_meta( $lead_id, '_deft_autoreply', $replied ? 'sent' : 'failed' );
	}

	do_action( 'deft_forms_lead_saved', $lead_id, $key, $values );
	return rest_ensure_response( array( 'ok' => true, 'message' => $thanks ) );
}

/**
 * Keeps the auto-reply from being used to send our email to strangers: anyone can type someone else's address into
 * the form. One auto-reply per address per day, and at most 20 an hour for the whole site (deft_forms_autoreply_limit).
 * The lead itself is always saved and emailed to us.
 */
function deft_forms_autoreply_allowed( $email ) {
	$address = 'deft_ar_' . substr( wp_hash( strtolower( $email ) ), 0, 20 );
	$hour    = 'deft_ar_hour_' . gmdate( 'YmdH' );
	$count   = (int) get_transient( $hour );
	if ( get_transient( $address ) || $count >= (int) apply_filters( 'deft_forms_autoreply_limit', 20 ) ) {
		return false;
	}
	set_transient( $address, 1, DAY_IN_SECONDS );
	set_transient( $hour, $count + 1, HOUR_IN_SECONDS );
	return true;
}

/** A visitor-facing message: this site's own wording from data/site.json if it has one, else $default. */
function deft_forms_message( $code, $default ) {
	$own = (array) deft_core_site_profile( 'messages' );
	return isset( $own[ $code ] ) && is_string( $own[ $code ] ) ? $own[ $code ] : $default;
}

function deft_forms_error( $code, $message, $status, $fields = array() ) {
	return new WP_REST_Response(
		array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'fields'  => array_values( array_unique( $fields ) ),
		),
		$status
	);
}

/**
 * The fields a page sent beyond the form's own list. Names are lowercase letters, digits and underscores; names starting
 * with an underscore and the website_url honeypot are ours, never answers. A list (checkboxes) becomes "a, b". The page
 * may send _labels ({name: "Label"}) for how each answer is titled in the email and in WordPress; without one the name
 * is used ("project_type" becomes "Project type"). At most 20 extra fields of 2000 characters each.
 * Returns array( values, labels ).
 */
function deft_forms_extras( $params, $form ) {
	$values = array();
	$labels = array();
	$given  = isset( $params['_labels'] ) && is_array( $params['_labels'] ) ? $params['_labels'] : array();
	foreach ( $params as $name => $raw ) {
		if ( count( $values ) >= 20 ) {
			break;
		}
		if ( ! is_string( $name ) || isset( $form['fields'][ $name ] ) || 'website_url' === $name || ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $name ) ) {
			continue;
		}
		$value = deft_forms_clean( $raw, array( 'type' => is_array( $raw ) ? 'list' : 'textarea', 'max' => 2000 ) );
		if ( '' === $value ) {
			continue;
		}
		$label = isset( $given[ $name ] ) && is_scalar( $given[ $name ] ) ? mb_substr( sanitize_text_field( (string) $given[ $name ] ), 0, 80 ) : '';
		$values[ $name ] = $value;
		$labels[ $name ] = '' !== $label ? $label : ucfirst( str_replace( '_', ' ', $name ) );
	}
	return array( $values, $labels );
}

function deft_forms_clean( $value, $field ) {
	$type = $field['type'] ?? 'text';
	if ( 'list' === $type && is_array( $value ) ) {
		$value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
	}
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	$value = 'textarea' === $type ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
	if ( 'email' === $type ) {
		$value = sanitize_email( $value );
	} elseif ( 'url' === $type ) {
		$value = esc_url_raw( $value, array( 'http', 'https' ) );
	}
	return mb_substr( trim( $value ), 0, $field['max'] ?? 500 );
}

function deft_forms_find_submission( $submission_id ) {
	$found = get_posts(
		array(
			'post_type'   => 'deft_lead',
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_key'    => '_deft_submission_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'  => $submission_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	return $found ? $found[0] : 0;
}

/**
 * Where the lead came from: the page it was sent from, the site that referred the visitor, and UTM campaign tags.
 * No personal data; this is what traffic reports and Deft Central will group leads by.
 */
function deft_forms_source( $raw, WP_REST_Request $request ) {
	$raw    = is_array( $raw ) ? $raw : array();
	$source = array();
	foreach ( array( 'landing', 'referrer' ) as $key ) {
		$url = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? esc_url_raw( mb_substr( $raw[ $key ], 0, 500 ), array( 'http', 'https' ) ) : '';
		if ( $url ) {
			$source[ $key ] = $url;
		}
	}
	foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid' ) as $key ) {
		if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && '' !== trim( $raw[ $key ] ) ) {
			$source[ $key ] = mb_substr( sanitize_text_field( $raw[ $key ] ), 0, 150 );
		}
	}
	// A referrer from this same site says nothing about where the visitor came from.
	if ( isset( $source['referrer'] ) && wp_parse_url( $source['referrer'], PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		unset( $source['referrer'] );
	}
	return $source;
}

/** One readable line, e.g. "facebook / paid / preview-oct, via instagram.com". */
function deft_forms_source_line( $source ) {
	$parts = array();
	$utm   = array_filter( array( $source['utm_source'] ?? '', $source['utm_medium'] ?? '', $source['utm_campaign'] ?? '' ) );
	if ( $utm ) {
		$parts[] = implode( ' / ', $utm );
	} elseif ( ! empty( $source['gclid'] ) ) {
		$parts[] = __( 'Google Ads', 'deft-core' );
	} elseif ( ! empty( $source['fbclid'] ) ) {
		$parts[] = __( 'Facebook / Instagram', 'deft-core' );
	}
	if ( ! empty( $source['referrer'] ) ) {
		$parts[] = __( 'via', 'deft-core' ) . ' ' . wp_parse_url( $source['referrer'], PHP_URL_HOST );
	}
	if ( ! $parts ) {
		$parts[] = __( 'Direct or unknown', 'deft-core' );
	}
	return implode( ', ', $parts );
}

/** Replaces {field} placeholders with the lead's values. */
function deft_forms_fill( $template, $values ) {
	return preg_replace_callback(
		'/\{(\w+)\}/',
		function ( $m ) use ( $values ) {
			return isset( $values[ $m[1] ] ) ? str_replace( array( "\r", "\n" ), ' ', $values[ $m[1] ] ) : '';
		},
		(string) $template
	);
}

/* ---------- Leads screens ---------- */

add_filter( 'manage_deft_lead_posts_columns', 'deft_forms_columns' );
function deft_forms_columns( $columns ) {
	return array(
		'cb'         => $columns['cb'],
		'title'      => __( 'Lead', 'deft-core' ),
		'deft_phone' => __( 'Phone', 'deft-core' ),
		'deft_email' => __( 'Email', 'deft-core' ),
		'deft_plan'  => __( 'Plan', 'deft-core' ),
		'deft_src'   => __( 'Came from', 'deft-core' ),
		'deft_mail'  => __( 'Emailed', 'deft-core' ),
		'date'       => __( 'Received', 'deft-core' ),
	);
}

add_action( 'manage_deft_lead_posts_custom_column', 'deft_forms_column', 10, 2 );
function deft_forms_column( $column, $post_id ) {
	$values = (array) get_post_meta( $post_id, '_deft_fields', true );
	switch ( $column ) {
		case 'deft_phone':
			echo empty( $values['phone'] ) ? '&mdash;' : '<a href="' . esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', $values['phone'] ) ) . '">' . esc_html( $values['phone'] ) . '</a>';
			break;
		case 'deft_email':
			echo empty( $values['email'] ) ? '&mdash;' : '<a href="' . esc_url( 'mailto:' . $values['email'] ) . '">' . esc_html( $values['email'] ) . '</a>';
			break;
		case 'deft_plan':
			echo esc_html( $values['plan'] ?? '' );
			break;
		case 'deft_src':
			echo esc_html( deft_forms_source_line( (array) get_post_meta( $post_id, '_deft_source', true ) ) );
			break;
		case 'deft_mail':
			$mail = get_post_meta( $post_id, '_deft_mail', true );
			echo 'failed' === $mail ? '<strong style="color:#b32d2e">' . esc_html__( 'Failed', 'deft-core' ) . '</strong>' : esc_html__( 'Yes', 'deft-core' );
			break;
	}
}

add_action( 'add_meta_boxes_deft_lead', 'deft_forms_meta_box' );
function deft_forms_meta_box() {
	add_meta_box( 'deft-lead', __( 'What they sent', 'deft-core' ), 'deft_forms_render_lead', 'deft_lead', 'normal', 'high' );
	remove_meta_box( 'slugdiv', 'deft_lead', 'normal' );
}

function deft_forms_render_lead( $post ) {
	$forms  = deft_forms_definitions();
	$key    = get_post_meta( $post->ID, '_deft_form', true );
	$values = (array) get_post_meta( $post->ID, '_deft_fields', true );
	$fields = $forms[ $key ]['fields'] ?? array();
	$saved  = (array) get_post_meta( $post->ID, '_deft_labels', true );
	echo '<table class="widefat striped"><tbody>';
	foreach ( $values as $name => $value ) {
		if ( '' === $value ) {
			continue;
		}
		$label = $saved[ $name ] ?? $fields[ $name ]['label'] ?? $name;
		echo '<tr><th style="width:180px">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
	}
	$source = (array) get_post_meta( $post->ID, '_deft_source', true );
	echo '<tr><th>' . esc_html__( 'Came from', 'deft-core' ) . '</th><td>' . esc_html( deft_forms_source_line( $source ) );
	if ( ! empty( $source['landing'] ) ) {
		echo '<br><small>' . esc_html( $source['landing'] ) . '</small>';
	}
	echo '</td></tr>';
	echo '</tbody></table>';
	$mail  = get_post_meta( $post->ID, '_deft_mail', true );
	$reply = get_post_meta( $post->ID, '_deft_autoreply', true );
	echo '<p>' . esc_html( sprintf(
		/* translators: 1: form name, 2: lead email status, 3: auto-reply status */
		__( 'Form: %1$s. Lead email: %2$s. Auto-reply: %3$s.', 'deft-core' ),
		$forms[ $key ]['label'] ?? $key,
		$mail ? $mail : '-',
		$reply ? $reply : __( 'not sent', 'deft-core' )
	) ) . '</p>';
}

// Export: a CSV of every lead, from a button above the Leads list.
add_action( 'manage_posts_extra_tablenav', 'deft_forms_export_button' );
function deft_forms_export_button( $which ) {
	$screen = get_current_screen();
	if ( 'top' !== $which || ! $screen || 'deft_lead' !== $screen->post_type ) {
		return;
	}
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=deft_export_leads' ), 'deft_export_leads' );
	echo '<div class="alignleft actions"><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Export CSV', 'deft-core' ) . '</a></div>';
}

add_action( 'admin_post_deft_export_leads', 'deft_forms_export' );
function deft_forms_export() {
	if ( ! current_user_can( deft_forms_capability() ) ) {
		wp_die( esc_html__( 'Not allowed.', 'deft-core' ) );
	}
	check_admin_referer( 'deft_export_leads' );
	$names = array();
	foreach ( deft_forms_definitions() as $form ) {
		$names = array_merge( $names, array_keys( $form['fields'] ) );
	}
	$names = array_values( array_unique( $names ) );
	$leads = get_posts( array( 'post_type' => 'deft_lead', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC' ) );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=deft-leads-' . gmdate( 'Y-m-d' ) . '.csv' );
	$out = fopen( 'php://output', 'w' );
	$tags = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'referrer', 'landing' );
	fputcsv( $out, array_merge( array( 'received', 'form', 'submission_id' ), $names, array( 'other_answers' ), $tags ) );
	foreach ( $leads as $lead ) {
		$values = (array) get_post_meta( $lead->ID, '_deft_fields', true );
		$source = (array) get_post_meta( $lead->ID, '_deft_source', true );
		$row    = array( get_the_date( 'Y-m-d H:i', $lead ), get_post_meta( $lead->ID, '_deft_form', true ), get_post_meta( $lead->ID, '_deft_submission_id', true ) );
		foreach ( $names as $name ) {
			$row[] = $values[ $name ] ?? '';
		}
		// Answers to a site's own questions (extras), as "Label: answer; Label: answer".
		$labels = (array) get_post_meta( $lead->ID, '_deft_labels', true );
		$other  = array();
		foreach ( array_diff_key( $values, array_flip( $names ) ) as $name => $value ) {
			$other[] = ( $labels[ $name ] ?? $name ) . ': ' . $value;
		}
		$row[] = implode( '; ', $other );
		foreach ( $tags as $tag ) {
			$row[] = $source[ $tag ] ?? '';
		}
		// Leading =, +, -, @, tab or carriage return would run as a formula in Excel or Sheets.
		$row = array_map(
			function ( $cell ) {
				return preg_replace( '/^([=+\-@\t\r])/', "'$1", (string) $cell );
			},
			$row
		);
		fputcsv( $out, $row );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}

/* ---------- Clean-up ---------- */

// Daily: delete leads older than the months set in Settings > Deft (0 keeps them).
add_action( 'deft_forms_cleanup', 'deft_forms_cleanup' );
function deft_forms_cleanup() {
	$months = (int) deft_core_setting( 'retention_months' );
	if ( $months < 1 ) {
		return;
	}
	$old = get_posts(
		array(
			'post_type'   => 'deft_lead',
			'post_status' => 'any',
			'numberposts' => 200,
			'fields'      => 'ids',
			'date_query'  => array( array( 'before' => $months . ' months ago' ) ),
		)
	);
	foreach ( $old as $id ) {
		wp_delete_post( $id, true );
	}
}
