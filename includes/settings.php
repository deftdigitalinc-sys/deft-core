<?php
/**
 * Settings > Deft: who gets lead emails, the From line, the auto-reply, how long leads are kept and the Deft Central link.
 * Modules add their own settings through the deft_core_defaults / deft_core_sanitize filters and the
 * deft_core_settings_sections / deft_core_settings_after actions.
 * Everything lives in one option, deft_core.
 */

defined( 'ABSPATH' ) || exit;

/** This site's own wording (data/site.json, shipped only in our own site's zip), or one key of it. */
function deft_core_site_profile( $key = '' ) {
	static $profile = null;
	if ( null === $profile ) {
		$file    = DEFT_CORE_DIR . 'data/site.json';
		$profile = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$profile = is_array( $profile ) ? $profile : array();
	}
	return '' === $key ? $profile : ( $profile[ $key ] ?? null );
}

/** Neutral defaults that suit any site; our own site's wording comes from data/site.json. */
function deft_core_defaults() {
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	return apply_filters( 'deft_core_defaults', array_merge( array(
		'recipients'          => (string) get_option( 'admin_email' ),
		'from_name'           => $site,
		'from_email'          => '',
		'autoreply'           => 1,
		'autoreply_subject'   => 'Thanks, {name}. We got your message',
		'autoreply_body'      => "Hi {name},\n\nThanks for getting in touch. We got your message and will get back to you soon.\n\n" . $site,
		'retention_months'    => 24,
		'delete_on_uninstall' => 0,
		'central_enabled'     => 0,
		'central_url'         => '',
		'central_site_id'     => '',
		'central_secret'      => '',
	), array_intersect_key( (array) deft_core_site_profile( 'defaults' ), array_flip( array( 'recipients', 'from_name', 'from_email', 'autoreply_subject', 'autoreply_body' ) ) ) ) );
}

function deft_core_setting( $key ) {
	$settings = wp_parse_args( get_option( 'deft_core', array() ), deft_core_defaults() );
	return $settings[ $key ];
}

/** Lead email recipients as a clean list. */
function deft_core_recipients() {
	$list = array_map( 'trim', explode( ',', (string) deft_core_setting( 'recipients' ) ) );
	return array_values( array_filter( $list, 'is_email' ) );
}

add_action( 'admin_init', 'deft_core_register_settings' );
function deft_core_register_settings() {
	register_setting(
		'deft_core',
		'deft_core',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'deft_core_sanitize',
			'default'           => deft_core_defaults(),
		)
	);
}

function deft_core_sanitize( $input ) {
	$input = is_array( $input ) ? $input : array();
	$out   = deft_core_defaults();

	$recipients = array_filter( array_map( 'sanitize_email', explode( ',', (string) ( $input['recipients'] ?? '' ) ) ) );
	if ( $recipients ) {
		$out['recipients'] = implode( ', ', $recipients );
	} else {
		add_settings_error( 'deft_core', 'recipients', __( 'Add at least one valid email for lead notifications. Kept the default.', 'deft-core' ) );
	}
	$out['from_name']           = sanitize_text_field( $input['from_name'] ?? '' );
	$out['from_email']          = sanitize_email( $input['from_email'] ?? '' );
	$out['autoreply']           = empty( $input['autoreply'] ) ? 0 : 1;
	$out['autoreply_subject']   = sanitize_text_field( $input['autoreply_subject'] ?? '' );
	$out['autoreply_body']      = sanitize_textarea_field( $input['autoreply_body'] ?? '' );
	$out['retention_months']    = max( 0, min( 120, absint( $input['retention_months'] ?? 24 ) ) );
	$out['delete_on_uninstall'] = empty( $input['delete_on_uninstall'] ) ? 0 : 1;

	// Deft Central. An empty secret field keeps the saved secret, so saving the page never wipes it.
	$out['central_url']     = esc_url_raw( trim( (string) ( $input['central_url'] ?? '' ) ), array( 'https', 'http' ) );
	$host                   = wp_parse_url( $out['central_url'], PHP_URL_HOST );
	if ( $out['central_url'] && 'https' !== wp_parse_url( $out['central_url'], PHP_URL_SCHEME ) && ! in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
		$out['central_url'] = '';
		add_settings_error( 'deft_core', 'central_url', __( 'The Deft Central endpoint must start with https://.', 'deft-core' ) );
	}
	$out['central_site_id'] = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) ( $input['central_site_id'] ?? '' ) ) );
	$secret                 = trim( (string) ( $input['central_secret'] ?? '' ) );
	$out['central_secret']  = '' === $secret ? (string) deft_core_setting( 'central_secret' ) : $secret;
	if ( defined( 'DEFT_CORE_CENTRAL_SECRET' ) ) {
		$out['central_secret'] = ''; // The wp-config secret wins; saving once wipes the old copy from the database.
	}
	if ( '' !== $secret && strlen( $secret ) < 32 ) {
		add_settings_error( 'deft_core', 'central_secret', __( 'The Deft Central secret looks too short. Paste the whole secret from the register-site script.', 'deft-core' ) );
	}
	$out['central_enabled'] = empty( $input['central_enabled'] ) ? 0 : 1;
	if ( function_exists( 'deft_central_connected_by_file' ) && deft_central_connected_by_file() ) {
		// Deft Central's connection file wins; saving once wipes any pasted copy from the database.
		$out['central_url']     = '';
		$out['central_site_id'] = '';
		$out['central_secret']  = '';
	} elseif ( $out['central_enabled'] && ! ( $out['central_url'] && $out['central_site_id'] && ( strlen( $out['central_secret'] ) >= 32 || defined( 'DEFT_CORE_CENTRAL_SECRET' ) ) ) ) {
		$out['central_enabled'] = 0;
		add_settings_error( 'deft_core', 'central_enabled', __( 'Deft Central needs an endpoint, a site ID and a secret before it can be switched on. Left it off.', 'deft-core' ) );
	}
	return apply_filters( 'deft_core_sanitize', $out, $input );
}

add_action( 'admin_menu', 'deft_core_settings_menu' );
function deft_core_settings_menu() {
	add_options_page( __( 'Deft', 'deft-core' ), __( 'Deft', 'deft-core' ), 'manage_options', 'deft-core', 'deft_core_settings_page' );
}

function deft_core_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = wp_parse_args( get_option( 'deft_core', array() ), deft_core_defaults() );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Deft', 'deft-core' ); ?></h1>
		<?php $test = isset( $_GET['deft_test'] ) ? sanitize_key( $_GET['deft_test'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<?php if ( $test ) : ?>
			<div class="notice <?php echo 'sent' === $test ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p>
				<?php
				echo 'sent' === $test
					? esc_html__( 'Test email handed to WordPress mail. Check the inbox (and spam).', 'deft-core' )
					: esc_html__( 'WordPress could not send the test email. Check your SMTP plugin settings.', 'deft-core' );
				?>
			</p></div>
		<?php endif; ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'deft_core' ); ?>
			<h2><?php esc_html_e( 'Lead emails', 'deft-core' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="deft-recipients"><?php esc_html_e( 'Send leads to', 'deft-core' ); ?></label></th>
					<td><input id="deft-recipients" class="regular-text" name="deft_core[recipients]" value="<?php echo esc_attr( $s['recipients'] ); ?>">
					<p class="description"><?php esc_html_e( 'One or more emails, separated by commas.', 'deft-core' ); ?></p></td></tr>
				<tr><th scope="row"><label for="deft-from-name"><?php esc_html_e( 'From name', 'deft-core' ); ?></label></th>
					<td><input id="deft-from-name" class="regular-text" name="deft_core[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="deft-from-email"><?php esc_html_e( 'From email', 'deft-core' ); ?></label></th>
					<td><input id="deft-from-email" class="regular-text" name="deft_core[from_email]" value="<?php echo esc_attr( $s['from_email'] ); ?>">
					<p class="description"><?php esc_html_e( 'Use an address on this domain that your SMTP plugin is allowed to send from. Leave empty to use the SMTP plugin\'s setting.', 'deft-core' ); ?></p></td></tr>
			</table>
			<h2><?php esc_html_e( 'Auto-reply to the visitor', 'deft-core' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Send an auto-reply', 'deft-core' ); ?></th>
					<td><label><input type="checkbox" name="deft_core[autoreply]" value="1" <?php checked( $s['autoreply'] ); ?>> <?php esc_html_e( 'When the visitor gives an email', 'deft-core' ); ?></label></td></tr>
				<tr><th scope="row"><label for="deft-ar-subject"><?php esc_html_e( 'Subject', 'deft-core' ); ?></label></th>
					<td><input id="deft-ar-subject" class="large-text" name="deft_core[autoreply_subject]" value="<?php echo esc_attr( $s['autoreply_subject'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="deft-ar-body"><?php esc_html_e( 'Message', 'deft-core' ); ?></label></th>
					<td><textarea id="deft-ar-body" class="large-text" rows="10" name="deft_core[autoreply_body]"><?php echo esc_textarea( $s['autoreply_body'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Placeholders: {name}, {business}, {trade}.', 'deft-core' ); ?></p></td></tr>
			</table>
			<h2><?php esc_html_e( 'Keeping leads', 'deft-core' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="deft-retention"><?php esc_html_e( 'Delete leads after', 'deft-core' ); ?></label></th>
					<td><input id="deft-retention" type="number" min="0" max="120" class="small-text" name="deft_core[retention_months]" value="<?php echo esc_attr( $s['retention_months'] ); ?>"> <?php esc_html_e( 'months', 'deft-core' ); ?>
					<p class="description"><?php esc_html_e( '0 keeps them forever. 24 matches the privacy page.', 'deft-core' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Removing the plugin', 'deft-core' ); ?></th>
					<td><label><input type="checkbox" name="deft_core[delete_on_uninstall]" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete leads when the plugin is deleted', 'deft-core' ); ?></label></td></tr>
			</table>
			<h2 id="deft-central"><?php esc_html_e( 'Deft Central', 'deft-core' ); ?></h2>
			<p><?php esc_html_e( 'Sends each lead to Deft Central as well. The email and the Leads list keep working either way. Get the endpoint, site ID and secret from the register-site script in Deft Central.', 'deft-core' ); ?></p>
			<?php if ( function_exists( 'deft_central_connected_by_file' ) && deft_central_connected_by_file() ) : ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Connection', 'deft-core' ); ?></th>
					<td><p><strong><?php esc_html_e( 'Set by Deft Central.', 'deft-core' ); ?></strong> <?php esc_html_e( 'Leads go to Central and Central can check this site. Nothing to paste here.', 'deft-core' ); ?></p>
					<p class="description"><?php esc_html_e( 'Stored in wp-content/mu-plugins/deft-central.php. Deleting that file disconnects the site.', 'deft-core' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Endpoint', 'deft-core' ); ?></th>
					<td><code><?php echo esc_html( deft_central_url() ? deft_central_url() : __( 'not https, so not used', 'deft-core' ) ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Site ID', 'deft-core' ); ?></th>
					<td><code><?php echo esc_html( deft_central_site_id() ); ?></code></td></tr>
			</table>
			<?php else : ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Send leads to Central', 'deft-core' ); ?></th>
					<td><label><input type="checkbox" name="deft_core[central_enabled]" value="1" <?php checked( $s['central_enabled'] ); ?>> <?php esc_html_e( 'On', 'deft-core' ); ?></label></td></tr>
				<tr><th scope="row"><label for="deft-central-url"><?php esc_html_e( 'Endpoint', 'deft-core' ); ?></label></th>
					<td><input id="deft-central-url" type="url" class="large-text" name="deft_core[central_url]" value="<?php echo esc_attr( $s['central_url'] ); ?>" placeholder="https://us-east1-PROJECT.cloudfunctions.net/deftCoreIngest"></td></tr>
				<tr><th scope="row"><label for="deft-central-site"><?php esc_html_e( 'Site ID', 'deft-core' ); ?></label></th>
					<td><input id="deft-central-site" class="regular-text" name="deft_core[central_site_id]" value="<?php echo esc_attr( $s['central_site_id'] ); ?>" placeholder="deftdigitalmedia"></td></tr>
				<tr><th scope="row"><label for="deft-central-secret"><?php esc_html_e( 'Secret', 'deft-core' ); ?></label></th>
					<td>
					<?php if ( defined( 'DEFT_CORE_CENTRAL_SECRET' ) ) : ?>
						<p><?php esc_html_e( 'Set in wp-config.php (DEFT_CORE_CENTRAL_SECRET).', 'deft-core' ); ?></p>
					<?php else : ?>
						<input id="deft-central-secret" type="password" class="regular-text" name="deft_core[central_secret]" value="" autocomplete="new-password" placeholder="<?php echo $s['central_secret'] ? esc_attr__( 'Saved. Leave empty to keep it.', 'deft-core' ) : ''; ?>">
						<p class="description"><?php esc_html_e( 'Or keep it out of the database: add define( \'DEFT_CORE_CENTRAL_SECRET\', \'...\' ); to wp-config.php.', 'deft-core' ); ?></p>
					<?php endif; ?>
					</td></tr>
			</table>
			<?php endif; ?>
			<?php do_action( 'deft_core_settings_sections', $s ); ?>
			<?php submit_button(); ?>
		</form>
		<hr>
		<h2><?php esc_html_e( 'Deft Central connection', 'deft-core' ); ?></h2>
		<?php $ping = get_transient( 'deft_central_ping_' . get_current_user_id() ); ?>
		<?php if ( false !== $ping ) : ?>
			<?php delete_transient( 'deft_central_ping_' . get_current_user_id() ); ?>
			<div class="notice <?php echo 'ok' === $ping ? 'notice-success' : 'notice-error'; ?> inline"><p>
				<?php echo 'ok' === $ping ? esc_html__( 'Connected. Deft Central accepted the signed test.', 'deft-core' ) : esc_html( sprintf( /* translators: %s: error */ __( 'Could not connect: %s', 'deft-core' ), $ping ) ); ?>
			</p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Save first, then test. The test sends no lead.', 'deft-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
			<input type="hidden" name="action" value="deft_central_ping">
			<?php wp_nonce_field( 'deft_central_ping' ); ?>
			<?php submit_button( __( 'Test connection', 'deft-core' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php if ( function_exists( 'deft_central_ready' ) && deft_central_ready() ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
			<input type="hidden" name="action" value="deft_central_backfill">
			<?php wp_nonce_field( 'deft_central_backfill' ); ?>
			<?php submit_button( __( 'Send unsent leads to Central', 'deft-core' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php endif; ?>
		<hr>
		<h2><?php esc_html_e( 'Test email', 'deft-core' ); ?></h2>
		<p><?php esc_html_e( 'Sends a test to the lead address(es) above, through the same WordPress mail the forms use.', 'deft-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="deft_test_email">
			<?php wp_nonce_field( 'deft_test_email' ); ?>
			<?php submit_button( __( 'Send test email', 'deft-core' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php do_action( 'deft_core_settings_after' ); ?>
	</div>
	<?php
}

add_action( 'admin_post_deft_test_email', 'deft_core_test_email' );
function deft_core_test_email() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'deft-core' ) );
	}
	check_admin_referer( 'deft_test_email' );
	$sent = wp_mail(
		deft_core_recipients(),
		__( 'Deft Core test email', 'deft-core' ),
		__( 'This is a test from Settings > Deft. If you can read it, lead emails will arrive here too.', 'deft-core' ),
		deft_core_mail_headers()
	);
	wp_safe_redirect( add_query_arg( 'deft_test', $sent ? 'sent' : 'failed', admin_url( 'options-general.php?page=deft-core' ) ) );
	exit;
}

/**
 * The visitor's IP address, for rate limits and location. Cloudflare's CF-Connecting-IP header is only believed when
 * the request really comes from a Cloudflare server, so nobody can fake their address by sending that header.
 * Hosts behind another proxy or CDN can correct it with the deft_track_ip filter.
 */
function deft_core_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && deft_core_is_cloudflare( $ip ) ) {
		$real = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		if ( filter_var( $real, FILTER_VALIDATE_IP ) ) {
			$ip = $real;
		}
	}
	return (string) apply_filters( 'deft_track_ip', $ip );
}

/** Whether an address is one of Cloudflare's published edge ranges (cloudflare.com/ips). */
function deft_core_is_cloudflare( $ip ) {
	$ranges = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
		'190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
		'2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);
	$bin = filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;
	if ( false === $bin ) {
		return false;
	}
	foreach ( $ranges as $range ) {
		list( $net, $bits ) = explode( '/', $range );
		$net = inet_pton( $net );
		if ( strlen( $net ) !== strlen( $bin ) ) {
			continue;
		}
		$bytes = intdiv( (int) $bits, 8 );
		$rest  = (int) $bits % 8;
		if ( substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			continue;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		if ( ! $rest || ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask ) ) {
			return true;
		}
	}
	return false;
}

/** From header shared by every email the plugin sends. */
function deft_core_mail_headers( $reply_to = '' ) {
	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	$email   = deft_core_setting( 'from_email' );
	if ( is_email( $email ) ) {
		$name      = str_replace( array( "\r", "\n", '"' ), '', (string) deft_core_setting( 'from_name' ) );
		$headers[] = $name ? sprintf( 'From: "%s" <%s>', $name, $email ) : 'From: ' . $email;
	}
	if ( $reply_to && is_email( $reply_to ) ) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}
	return $headers;
}
