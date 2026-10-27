<?php
/**
 * Site security, logins (0.10.0): two-factor codes for administrators, "everyone picks a new password", and a check
 * that new passwords aren't in a known breach.
 *
 * Why: in October 2026 forensics found a plaintext copy of a Deft admin password from January 2025, so a reused
 * password is a live way in. A second factor makes a stolen password useless on its own.
 *
 * - Two-factor: a 6-digit code from an authenticator app (Google Authenticator, 1Password, Authy; standard TOTP,
 *   RFC 6238), set up under Users > Profile. With "Administrators must use two-factor" on (the default), an
 *   administrator without it is sent to that setup until it's done; nobody is locked out by switching it on.
 *   Users who already have Wordfence Login Security two-factor are left to Wordfence. Each code works once.
 *   If a phone is lost: another administrator turns it off on that user's profile, or define DEFT_CORE_NO_2FA in
 *   wp-config.php for a moment.
 * - New passwords for everyone: one button marks every administrator (or every user); each one's next login is
 *   refused with a reset link sent to their email, and the mark goes when they set a new password.
 * - Breached passwords: a new password is checked against Have I Been Pwned's list (only the first 5 characters of
 *   its SHA-1 leave the site) and refused if it's there. If the service doesn't answer, the password is allowed.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'deft_core_defaults', 'deft_sec_login_defaults' );
function deft_sec_login_defaults( $defaults ) {
	return $defaults + array( 'sec_2fa_admins' => 1, 'sec_breached' => 1 );
}

add_filter( 'deft_core_sanitize', 'deft_sec_login_sanitize', 10, 2 );
function deft_sec_login_sanitize( $out, $input ) {
	$out['sec_2fa_admins'] = empty( $input['sec_2fa_admins'] ) ? 0 : 1;
	$out['sec_breached']   = empty( $input['sec_breached'] ) ? 0 : 1;
	return $out;
}

/* ---------- TOTP ---------- */

function deft_sec_b32_encode( $bin ) {
	$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$bits  = '';
	foreach ( str_split( $bin ) as $c ) {
		$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
	}
	$out = '';
	foreach ( str_split( $bits, 5 ) as $chunk ) {
		$out .= $alpha[ bindec( str_pad( $chunk, 5, '0' ) ) ];
	}
	return $out;
}

function deft_sec_b32_decode( $text ) {
	$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$text  = strtoupper( preg_replace( '/[^A-Za-z2-7]/', '', (string) $text ) );
	$bits  = '';
	foreach ( str_split( $text ) as $c ) {
		$bits .= str_pad( decbin( strpos( $alpha, $c ) ), 5, '0', STR_PAD_LEFT );
	}
	$out = '';
	foreach ( str_split( $bits, 8 ) as $byte ) {
		if ( 8 === strlen( $byte ) ) {
			$out .= chr( bindec( $byte ) );
		}
	}
	return $out;
}

/** The 6-digit code for one 30-second step. */
function deft_sec_totp( $secret_b32, $step ) {
	$hash   = hash_hmac( 'sha1', pack( 'N2', 0, $step ), deft_sec_b32_decode( $secret_b32 ), true );
	$offset = ord( $hash[19] ) & 0x0f;
	$value  = ( ( ord( $hash[ $offset ] ) & 0x7f ) << 24 ) | ( ord( $hash[ $offset + 1 ] ) << 16 ) | ( ord( $hash[ $offset + 2 ] ) << 8 ) | ord( $hash[ $offset + 3 ] );
	return str_pad( (string) ( $value % 1000000 ), 6, '0', STR_PAD_LEFT );
}

/** The step a code matches (now, or one step either side for a slow phone clock), or false. */
function deft_sec_totp_match( $secret_b32, $code, $now = null ) {
	$code = preg_replace( '/\D/', '', (string) $code );
	if ( 6 !== strlen( $code ) || '' === (string) $secret_b32 ) {
		return false;
	}
	$step = (int) floor( ( null === $now ? time() : $now ) / 30 );
	foreach ( array( 0, -1, 1 ) as $drift ) {
		if ( hash_equals( deft_sec_totp( $secret_b32, $step + $drift ), $code ) ) {
			return $step + $drift;
		}
	}
	return false;
}

function deft_sec_2fa_secret( $user_id ) {
	return (string) get_user_meta( $user_id, 'deft_2fa_secret', true );
}

function deft_sec_2fa_on( $user_id ) {
	return '' !== deft_sec_2fa_secret( $user_id ) && get_user_meta( $user_id, 'deft_2fa_on', true );
}

/** Two-factor through Wordfence Login Security, which keeps its own table. */
function deft_sec_2fa_wordfence( $user_id ) {
	global $wpdb;
	static $has_table = null;
	$table = $wpdb->base_prefix . 'wfls_2fa_secrets';
	if ( null === $has_table ) {
		$has_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	return $has_table && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

function deft_sec_2fa_disabled() {
	return defined( 'DEFT_CORE_NO_2FA' ) && DEFT_CORE_NO_2FA;
}

/* ---------- Login ---------- */

add_action( 'login_form', 'deft_sec_2fa_field' );
function deft_sec_2fa_field() {
	if ( deft_sec_2fa_disabled() ) {
		return;
	}
	?>
	<p><label for="deft_otp"><?php esc_html_e( 'Two-factor code (if you set one up)', 'deft-core' ); ?></label>
	<input type="text" name="deft_otp" id="deft_otp" class="input" value="" size="20" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*"></p>
	<?php
}

// After the password check (and the lockout at 99): a right password alone isn't enough for a user with two-factor.
add_filter( 'authenticate', 'deft_sec_login_second_step', 100, 1 );
function deft_sec_login_second_step( $user ) {
	if ( ! $user instanceof WP_User ) {
		return $user;
	}
	// A forced new password: refuse this login and send the reset link.
	if ( get_user_meta( $user->ID, 'deft_must_reset', true ) ) {
		if ( function_exists( 'retrieve_password' ) ) {
			retrieve_password( $user->user_login );
		}
		return new WP_Error( 'deft_must_reset', __( 'This site asked everyone for a new password. We emailed you a link to set one.', 'deft-core' ) );
	}
	if ( deft_sec_2fa_disabled() || ! deft_sec_2fa_on( $user->ID ) ) {
		return $user;
	}
	// Only the normal login form can carry a code; other ways in (XML-RPC, app passwords) are refused for these users.
	$code = isset( $_POST['deft_otp'] ) ? sanitize_text_field( wp_unslash( $_POST['deft_otp'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$step = deft_sec_totp_match( deft_sec_2fa_secret( $user->ID ), $code );
	if ( false === $step || $step <= (int) get_user_meta( $user->ID, 'deft_2fa_last', true ) ) {
		// WordPress fires wp_login_failed for this error, so a wrong code counts toward the lockout like a wrong password.
		return new WP_Error( 'deft_2fa', __( '<strong>Error:</strong> Enter the 6-digit code from your authenticator app.', 'deft-core' ) );
	}
	update_user_meta( $user->ID, 'deft_2fa_last', $step );
	return $user;
}

// Setting a new password clears the "must reset" mark.
add_action( 'after_password_reset', 'deft_sec_reset_done' );
add_action( 'profile_update', 'deft_sec_profile_reset_done', 10, 3 );
function deft_sec_reset_done( $user ) {
	delete_user_meta( $user->ID, 'deft_must_reset' );
}
function deft_sec_profile_reset_done( $user_id, $old = null, $data = array() ) {
	if ( $old instanceof WP_User && isset( $data['user_pass'] ) && $data['user_pass'] !== $old->user_pass ) {
		delete_user_meta( $user_id, 'deft_must_reset' );
	}
}

/* ---------- Required for administrators ---------- */

function deft_sec_2fa_needed( $user_id ) {
	return deft_core_setting( 'sec_2fa_admins' ) && ! deft_sec_2fa_disabled() && user_can( $user_id, 'manage_options' ) && ! deft_sec_2fa_on( $user_id ) && ! deft_sec_2fa_wordfence( $user_id );
}

// An administrator without two-factor can only reach their profile until it's set up (no lockout, just a detour).
add_action( 'admin_init', 'deft_sec_2fa_detour', 1 );
function deft_sec_2fa_detour() {
	if ( wp_doing_ajax() || ! is_user_logged_in() || ! deft_sec_2fa_needed( get_current_user_id() ) ) {
		return;
	}
	global $pagenow;
	if ( in_array( $pagenow, array( 'profile.php', 'admin-post.php' ), true ) ) {
		return;
	}
	wp_safe_redirect( admin_url( 'profile.php#deft-2fa' ) );
	exit;
}

add_action( 'admin_notices', 'deft_sec_2fa_notice' );
function deft_sec_2fa_notice() {
	global $pagenow;
	if ( 'profile.php' === $pagenow && deft_sec_2fa_needed( get_current_user_id() ) ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Administrators on this site need two-factor login. Set it up below to continue.', 'deft-core' ) . '</p></div>';
	}
}

/* ---------- Profile: set up, turn off ---------- */

add_action( 'show_user_profile', 'deft_sec_2fa_profile' );
add_action( 'edit_user_profile', 'deft_sec_2fa_profile' );
function deft_sec_2fa_profile( $user ) {
	$self = get_current_user_id() === $user->ID;
	$on   = deft_sec_2fa_on( $user->ID );
	?>
	<h2 id="deft-2fa"><?php esc_html_e( 'Two-factor login', 'deft-core' ); ?></h2>
	<table class="form-table" role="presentation"><tr><th scope="row"><?php esc_html_e( 'Authenticator app', 'deft-core' ); ?></th><td>
	<?php if ( $on ) : ?>
		<p><?php esc_html_e( 'On. Each login asks for the 6-digit code from the app.', 'deft-core' ); ?></p>
		<label><input type="checkbox" name="deft_2fa_off" value="1"> <?php esc_html_e( 'Turn two-factor off for this user', 'deft-core' ); ?></label>
	<?php elseif ( $self ) :
		$secret = deft_sec_2fa_secret( $user->ID );
		if ( '' === $secret ) {
			$secret = deft_sec_b32_encode( random_bytes( 20 ) );
			update_user_meta( $user->ID, 'deft_2fa_secret', $secret );
		}
		$label = rawurlencode( wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . $user->user_login );
		$uri   = 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode( wp_parse_url( home_url(), PHP_URL_HOST ) );
		?>
		<p><?php esc_html_e( 'On your phone, tap the link below, or add an account in your authenticator app and type this key:', 'deft-core' ); ?></p>
		<p><code style="font-size:15px;letter-spacing:1px"><?php echo esc_html( trim( chunk_split( $secret, 4, ' ' ) ) ); ?></code> &nbsp; <a href="<?php echo esc_attr( $uri ); ?>"><?php esc_html_e( 'Open in authenticator app', 'deft-core' ); ?></a></p>
		<p><label for="deft-2fa-code"><?php esc_html_e( 'Then enter the code it shows:', 'deft-core' ); ?></label>
		<input id="deft-2fa-code" name="deft_2fa_code" type="text" inputmode="numeric" autocomplete="one-time-code" size="8"> <?php esc_html_e( 'and press Update Profile.', 'deft-core' ); ?></p>
		<?php wp_nonce_field( 'deft_2fa_' . $user->ID, 'deft_2fa_nonce' ); ?>
	<?php else : ?>
		<p><?php esc_html_e( 'Off. Only this user can set it up, from their own profile.', 'deft-core' ); ?></p>
	<?php endif; ?>
	</td></tr></table>
	<?php
}

add_action( 'personal_options_update', 'deft_sec_2fa_save' );
add_action( 'edit_user_profile_update', 'deft_sec_2fa_save' );
function deft_sec_2fa_save( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the profile form's own nonce was checked by WordPress.
	if ( ! empty( $_POST['deft_2fa_off'] ) && deft_sec_2fa_on( $user_id ) ) {
		delete_user_meta( $user_id, 'deft_2fa_on' );
		delete_user_meta( $user_id, 'deft_2fa_secret' );
		return;
	}
	$code = isset( $_POST['deft_2fa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['deft_2fa_code'] ) ) : '';
	// phpcs:enable
	if ( '' === $code || get_current_user_id() !== $user_id || ! isset( $_POST['deft_2fa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['deft_2fa_nonce'] ) ), 'deft_2fa_' . $user_id ) ) {
		return;
	}
	$step = deft_sec_totp_match( deft_sec_2fa_secret( $user_id ), $code );
	if ( false !== $step ) {
		update_user_meta( $user_id, 'deft_2fa_on', 1 );
		update_user_meta( $user_id, 'deft_2fa_last', $step );
	} else {
		set_transient( 'deft_2fa_wrong_' . $user_id, 1, 60 );
	}
}

add_action( 'user_profile_update_errors', 'deft_sec_profile_errors', 10, 3 );
function deft_sec_profile_errors( $errors, $update, $user ) {
	if ( $update && ! empty( $user->ID ) && get_transient( 'deft_2fa_wrong_' . $user->ID ) ) {
		delete_transient( 'deft_2fa_wrong_' . $user->ID );
		$errors->add( 'deft_2fa', __( 'That two-factor code didn\'t match. Check the phone\'s clock and try the next code.', 'deft-core' ) );
	}
	if ( ! empty( $user->user_pass ) ) {
		deft_sec_breached_error( $errors, $user->user_pass );
	}
}

/* ---------- Breached passwords ---------- */

/** How many times a password shows up in known breaches (0 if none, null if the service didn't answer). */
function deft_sec_breached_count( $password ) {
	$sha  = strtoupper( sha1( $password ) );
	$resp = wp_remote_get( 'https://api.pwnedpasswords.com/range/' . substr( $sha, 0, 5 ), array( 'timeout' => 5, 'headers' => array( 'Add-Padding' => 'true' ) ) );
	if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
		return null;
	}
	foreach ( preg_split( '/\r?\n/', wp_remote_retrieve_body( $resp ) ) as $line ) {
		$parts = explode( ':', trim( $line ) );
		if ( 2 === count( $parts ) && substr( $sha, 5 ) === $parts[0] ) {
			return (int) $parts[1];
		}
	}
	return 0;
}

function deft_sec_breached_error( $errors, $password ) {
	if ( ! deft_core_setting( 'sec_breached' ) || '' === (string) $password ) {
		return;
	}
	if ( deft_sec_breached_count( $password ) ) {
		$errors->add( 'deft_breached', __( '<strong>Error:</strong> That password is in a list of leaked passwords. Pick a different one.', 'deft-core' ) );
	}
}

add_action( 'validate_password_reset', 'deft_sec_reset_check', 10, 1 );
function deft_sec_reset_check( $errors ) {
	if ( ! empty( $_POST['pass1'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress checked the reset key.
		deft_sec_breached_error( $errors, wp_unslash( $_POST['pass1'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- a password, compared not stored.
	}
}

/* ---------- Settings: switches and the "new passwords" button ---------- */

add_action( 'deft_sec_settings_rows', 'deft_sec_login_settings_rows' );
function deft_sec_login_settings_rows( $s ) {
	?>
	<tr><th scope="row"><?php esc_html_e( 'Logins', 'deft-core' ); ?></th>
		<td><label style="display:block;margin:2px 0"><input type="checkbox" name="deft_core[sec_2fa_admins]" value="1" <?php checked( $s['sec_2fa_admins'] ); ?>> <?php esc_html_e( 'Administrators must use two-factor (they are sent to set it up; nobody is locked out)', 'deft-core' ); ?></label>
		<label style="display:block;margin:2px 0"><input type="checkbox" name="deft_core[sec_breached]" value="1" <?php checked( $s['sec_breached'] ); ?>> <?php esc_html_e( 'Refuse new passwords that are in known leaks', 'deft-core' ); ?></label></td></tr>
	<?php
}

add_action( 'admin_post_deft_sec_force_reset', 'deft_sec_force_reset' );
function deft_sec_force_reset() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'deft-core' ), 403 );
	}
	check_admin_referer( 'deft_sec_force_reset' );
	$who   = isset( $_POST['who'] ) && 'all' === $_POST['who'] ? array() : array( 'role' => 'administrator' );
	$users = get_users( $who + array( 'fields' => 'ID' ) );
	foreach ( $users as $id ) {
		if ( (int) $id !== get_current_user_id() ) {
			update_user_meta( (int) $id, 'deft_must_reset', time() );
			if ( class_exists( 'WP_Session_Tokens' ) ) {
				WP_Session_Tokens::get_instance( (int) $id )->destroy_all(); // Logs them out everywhere now.
			}
		}
	}
	update_option( 'deft_sec_force_reset', array( 'at' => time(), 'count' => count( $users ) - 1, 'who' => $who ? 'admins' : 'all' ), false );
	wp_safe_redirect( admin_url( 'options-general.php?page=deft-core#deft-security' ) );
	exit;
}

add_action( 'deft_core_settings_after', 'deft_sec_force_reset_button', 11 );
function deft_sec_force_reset_button() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$last = get_option( 'deft_sec_force_reset' );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
		<input type="hidden" name="action" value="deft_sec_force_reset">
		<?php wp_nonce_field( 'deft_sec_force_reset' ); ?>
		<select name="who"><option value="admins"><?php esc_html_e( 'Administrators', 'deft-core' ); ?></option><option value="all"><?php esc_html_e( 'Every user', 'deft-core' ); ?></option></select>
		<?php submit_button( __( 'Ask for new passwords', 'deft-core' ), 'secondary', 'reset', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Everyone chosen (except you) is logged out, and their next login sends them a link to set a new password. Go ahead?', 'deft-core' ) ) . "')" ) ); ?>
		<?php if ( is_array( $last ) ) : ?><span class="description"><?php echo esc_html( sprintf( /* translators: 1: time ago, 2: count */ __( 'Last asked %1$s ago (%2$d users).', 'deft-core' ), human_time_diff( $last['at'] ), $last['count'] ) ); ?></span><?php endif; ?>
		<p class="description"><?php esc_html_e( 'Change your own password on your profile first.', 'deft-core' ); ?></p>
	</form>
	<?php
}

/** For the file check's report: administrators without any two-factor, and how many still owe a new password. */
function deft_sec_login_status() {
	$admins  = get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'user_login' ) ) );
	$without = array();
	$owing   = 0;
	foreach ( $admins as $a ) {
		if ( ! deft_sec_2fa_on( $a->ID ) && ! deft_sec_2fa_wordfence( $a->ID ) ) {
			$without[] = $a->user_login;
		}
		if ( get_user_meta( $a->ID, 'deft_must_reset', true ) ) {
			$owing++;
		}
	}
	return array( 'admins' => count( $admins ), 'without_2fa' => $without, 'must_reset' => $owing );
}
