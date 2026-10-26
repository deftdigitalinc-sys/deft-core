<?php
/**
 * Coming soon and maintenance mode.
 *
 * One switch in Settings > Deft puts a Deft-styled page in front of the whole site: "Coming soon" while it's being
 * built, or "Maintenance" while it's being worked on (same page, different wording, and search engines are told to
 * come back in an hour instead of a day). Visitors and search engines get it with a 503 (so Google waits instead of indexing a half-built site);
 * anyone logged in who can edit posts sees the real site, with a reminder in the admin bar. A private link lets the
 * client see the site without an account. Login, wp-admin, the REST API (so forms and Deft Central keep working),
 * Deft preview links and robots.txt are never blocked. Switching it on or off clears the page cache, and both pages
 * are sent uncached so a cache never shows one in place of the other.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'deft_core_defaults', 'deft_soon_defaults' );
function deft_soon_defaults( $defaults ) {
	return $defaults + array(
		'soon'         => '',
		'soon_message' => '',
		'soon_phone'   => '',
		'soon_email'   => '',
	);
}

add_filter( 'deft_core_sanitize', 'deft_soon_sanitize', 10, 2 );
function deft_soon_sanitize( $out, $input ) {
	$out['soon']         = isset( $input['soon'] ) && in_array( $input['soon'], array( 'soon', 'maintenance' ), true ) ? $input['soon'] : '';
	$out['soon_message'] = isset( $input['soon_message'] ) ? sanitize_textarea_field( $input['soon_message'] ) : '';
	$out['soon_phone']   = isset( $input['soon_phone'] ) ? sanitize_text_field( $input['soon_phone'] ) : '';
	$out['soon_email']   = isset( $input['soon_email'] ) && is_email( trim( $input['soon_email'] ) ) ? sanitize_email( $input['soon_email'] ) : '';
	return $out;
}

function deft_soon_default_message( $mode ) {
	return 'maintenance' === $mode
		? __( "We're making a few updates and will be back shortly. Thanks for your patience.", 'deft-core' )
		: __( "We're building something new. Check back soon.", 'deft-core' );
}

function deft_soon_label( $mode ) {
	return 'maintenance' === $mode ? __( 'Back shortly', 'deft-core' ) : __( 'Coming soon', 'deft-core' );
}

/** The private link that lets someone without an account see the real site. */
function deft_soon_share_url() {
	$key = get_option( 'deft_soon_key' );
	if ( ! $key ) {
		$key = wp_generate_password( 20, false );
		update_option( 'deft_soon_key', $key, false );
	}
	return add_query_arg( 'deft_soon', $key, home_url( '/' ) );
}

add_action( 'deft_core_settings_sections', 'deft_soon_settings_fields', 1 );
function deft_soon_settings_fields( $s ) {
	?>
	<h2 id="deft-soon"><?php esc_html_e( 'Coming soon and maintenance', 'deft-core' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><?php esc_html_e( 'What visitors see', 'deft-core' ); ?></th>
			<td><fieldset>
				<label style="display:block;margin:2px 0"><input type="radio" name="deft_core[soon]" value="" <?php checked( $s['soon'], '' ); ?>> <?php esc_html_e( 'The site (off)', 'deft-core' ); ?></label>
				<label style="display:block;margin:2px 0"><input type="radio" name="deft_core[soon]" value="soon" <?php checked( $s['soon'], 'soon' ); ?>> <?php esc_html_e( 'Coming soon: the site is still being built', 'deft-core' ); ?></label>
				<label style="display:block;margin:2px 0"><input type="radio" name="deft_core[soon]" value="maintenance" <?php checked( $s['soon'], 'maintenance' ); ?>> <?php esc_html_e( 'Maintenance: the site is down for a short while', 'deft-core' ); ?></label>
			</fieldset>
			<p class="description"><?php esc_html_e( 'Anyone logged in who can edit posts still sees the real site. Search engines are told to come back later, so nothing half-built gets indexed. Forms, Deft Central and preview links keep working.', 'deft-core' ); ?></p>
			<?php if ( $s['soon'] ) : ?>
				<p><?php esc_html_e( 'Link for the client to see the site without logging in (works for 30 days on their device):', 'deft-core' ); ?><br>
				<input class="large-text code" readonly onclick="this.select()" value="<?php echo esc_url( deft_soon_share_url() ); ?>"></p>
				<p><a href="<?php echo esc_url( add_query_arg( 'deft_soon_view', 1, home_url( '/' ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See what visitors see', 'deft-core' ); ?></a></p>
			<?php endif; ?></td></tr>
		<tr><th scope="row"><label for="deft-soon-message"><?php esc_html_e( 'Message', 'deft-core' ); ?></label></th>
			<td><textarea id="deft-soon-message" class="large-text" rows="3" name="deft_core[soon_message]" placeholder="<?php echo esc_attr( deft_soon_default_message( $s['soon'] ? $s['soon'] : 'soon' ) ); ?>"><?php echo esc_textarea( $s['soon_message'] ); ?></textarea></td></tr>
		<tr><th scope="row"><label for="deft-soon-phone"><?php esc_html_e( 'Phone and email', 'deft-core' ); ?></label></th>
			<td><input id="deft-soon-phone" class="regular-text" name="deft_core[soon_phone]" value="<?php echo esc_attr( $s['soon_phone'] ); ?>" placeholder="(561) 555-0100">
			<input class="regular-text" type="email" name="deft_core[soon_email]" value="<?php echo esc_attr( $s['soon_email'] ); ?>" placeholder="hello@example.com" aria-label="<?php esc_attr_e( 'Email', 'deft-core' ); ?>">
			<p class="description"><?php esc_html_e( 'Optional. Shown as Call and Email buttons so customers can still reach the business.', 'deft-core' ); ?></p></td></tr>
	</table>
	<?php
}

// Turning it on or off clears the page cache, so nobody keeps getting the old page.
add_action( 'update_option_deft_core', 'deft_soon_option_changed', 10, 2 );
function deft_soon_option_changed( $old, $new ) {
	if ( ( $old['soon'] ?? '' ) !== ( $new['soon'] ?? '' ) && function_exists( 'deft_core_flush_caches' ) ) {
		deft_core_flush_caches();
	}
}

/** Whether this request gets the real site while coming soon is on. */
function deft_soon_lets_through() {
	if ( isset( $_GET['deft_soon_view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return false; // Staff checking what visitors see.
	}
	if ( current_user_can( 'edit_posts' ) || is_robots() || is_favicon() ) {
		return true;
	}
	if ( is_singular() && function_exists( 'deft_pages_previewing' ) && deft_pages_previewing( get_queried_object_id() ) ) {
		return true;
	}
	$key = (string) get_option( 'deft_soon_key' );
	if ( '' === $key ) {
		return false;
	}
	$given = isset( $_GET['deft_soon'] ) ? sanitize_text_field( wp_unslash( $_GET['deft_soon'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' !== $given && hash_equals( $key, $given ) ) {
		setcookie( 'deft_soon', hash_hmac( 'sha256', $key, wp_salt( 'auth' ) ), time() + 30 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		return true;
	}
	$cookie = isset( $_COOKIE['deft_soon'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['deft_soon'] ) ) : '';
	return '' !== $cookie && hash_equals( hash_hmac( 'sha256', $key, wp_salt( 'auth' ) ), $cookie );
}

function deft_soon_no_cache() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	header( 'X-LiteSpeed-Cache-Control: no-cache' );
	do_action( 'litespeed_control_set_nocache', 'deft coming soon' );
}

add_action( 'template_redirect', 'deft_soon_gate', 0 );
function deft_soon_gate() {
	if ( ! deft_core_setting( 'soon' ) ) {
		return;
	}
	deft_soon_no_cache();
	if ( deft_soon_lets_through() ) {
		return;
	}
	status_header( 503 );
	header( 'Retry-After: ' . ( 'maintenance' === deft_core_setting( 'soon' ) ? HOUR_IN_SECONDS : DAY_IN_SECONDS ) );
	deft_soon_render();
	exit;
}

function deft_soon_render() {
	$s       = wp_parse_args( get_option( 'deft_core', array() ), deft_core_defaults() );
	$name    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$mode    = 'maintenance' === $s['soon'] ? 'maintenance' : 'soon';
	$label   = deft_soon_label( $mode );
	$message = '' !== trim( $s['soon_message'] ) ? $s['soon_message'] : deft_soon_default_message( $mode );
	$phone   = trim( (string) $s['soon_phone'] );
	$tel     = preg_replace( '/[^0-9+]/', '', $phone );
	$logo    = get_theme_mod( 'custom_logo' ) ? wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'medium' ) : get_site_icon_url( 192 );
	$fonts   = plugins_url( 'assets/fonts/', DEFT_CORE_FILE );
	$mark    = plugins_url( 'assets/deft-icon.png', DEFT_CORE_FILE );
	?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( $name . ' | ' . $label ); ?></title>
<?php if ( get_site_icon_url() ) : ?><link rel="icon" href="<?php echo esc_url( get_site_icon_url( 32 ) ); ?>"><?php endif; ?>
<style>
@font-face{font-family:Geist;src:url(<?php echo esc_url( $fonts . 'Geist.woff2' ); ?>) format("woff2");font-weight:100 900;font-display:swap}
@font-face{font-family:"Archivo SemiExpanded";src:url(<?php echo esc_url( $fonts . 'Archivo-SemiExpanded.woff2' ); ?>) format("woff2");font-weight:100 900;font-display:swap}
:root{--canvas:#ECECEA;--paper:#F7F7F5;--ink:#111;--muted:#55554F;--line:rgba(17,17,17,.1);--sun:#FFC700;--focus:#3350F0}
@media (prefers-color-scheme:dark){:root{--canvas:#0B0B0C;--paper:#151517;--ink:#F7F7F5;--muted:#A3A39C;--line:rgba(247,247,245,.12);--focus:#8DA0FF}}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%}
body{min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:28px;padding:32px 16px;background:var(--canvas);color:var(--ink);font:400 17px/1.55 Geist,system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
.card{width:100%;max-width:560px;background:var(--paper);border:1px solid var(--line);border-radius:20px;padding:48px 40px;text-align:center;animation:rise .6s cubic-bezier(.2,.7,.2,1) both}
.logo{display:block;max-height:64px;max-width:200px;margin:0 auto 28px;object-fit:contain}
.label{display:inline-block;font:600 12px/1 "Archivo SemiExpanded",Geist,sans-serif;letter-spacing:.12em;text-transform:uppercase;background:var(--sun);color:#111;border-radius:999px;padding:9px 14px}
h1{font-weight:650;font-size:clamp(32px,7vw,48px);line-height:1.05;letter-spacing:-.025em;margin:22px 0 14px;text-wrap:balance}
p{margin:0 auto;max-width:42ch;color:var(--muted);white-space:pre-line}
.actions{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin-top:30px}
.btn{display:inline-flex;align-items:center;min-height:48px;padding:0 22px;border-radius:999px;font-weight:550;text-decoration:none;color:var(--ink);border:1px solid var(--line);transition:transform .2s,background .2s}
.btn.primary{background:var(--ink);color:var(--paper);border-color:var(--ink)}
.btn:hover{transform:translateY(-1px)}
.btn:focus-visible,.by:focus-visible{outline:2px solid var(--focus);outline-offset:3px}
.by{display:inline-flex;align-items:center;gap:8px;font:500 12px/1 "Archivo SemiExpanded",Geist,sans-serif;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);text-decoration:none;border-radius:999px;padding:6px}
.by i{width:18px;height:18px;background:currentColor;-webkit-mask:url(<?php echo esc_url( $mark ); ?>) center/contain no-repeat;mask:url(<?php echo esc_url( $mark ); ?>) center/contain no-repeat}
.by:hover{color:var(--ink)}
@keyframes rise{from{opacity:0;transform:translateY(12px)}}
@media (max-width:480px){.card{padding:36px 22px}}
@media (prefers-reduced-motion:reduce){.card{animation:none}.btn{transition:none}}
</style>
</head>
<body>
<main class="card">
	<?php if ( $logo ) : ?><img class="logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $name ); ?>"><?php endif; ?>
	<span class="label"><?php echo esc_html( $label ); ?></span>
	<h1><?php echo esc_html( $name ); ?></h1>
	<p><?php echo esc_html( $message ); ?></p>
	<?php if ( $tel || $s['soon_email'] ) : ?>
	<div class="actions">
		<?php if ( $tel ) : ?><a class="btn primary" href="tel:<?php echo esc_attr( $tel ); ?>"><?php echo esc_html( sprintf( /* translators: %s: phone number */ __( 'Call %s', 'deft-core' ), $phone ) ); ?></a><?php endif; ?>
		<?php if ( $s['soon_email'] ) : ?><a class="btn<?php echo $tel ? '' : ' primary'; ?>" href="mailto:<?php echo esc_attr( $s['soon_email'] ); ?>"><?php esc_html_e( 'Email us', 'deft-core' ); ?></a><?php endif; ?>
	</div>
	<?php endif; ?>
</main>
<a class="by" href="https://deftdigitalmedia.com/?utm_source=coming-soon&amp;utm_medium=referral" target="_blank" rel="noopener"><i aria-hidden="true"></i><?php esc_html_e( 'Site by Deft Digital', 'deft-core' ); ?></a>
</body>
</html>
<?php
}

// A reminder for whoever is logged in, so the site doesn't stay hidden by accident.
add_action( 'admin_bar_menu', 'deft_soon_admin_bar', 100 );
function deft_soon_admin_bar( $bar ) {
	if ( ! deft_core_setting( 'soon' ) || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$bar->add_node(
		array(
			'id'    => 'deft-soon',
			'title' => '<span style="background:#FFC700;color:#111;border-radius:999px;padding:3px 10px;font-weight:600">' . esc_html( 'maintenance' === deft_core_setting( 'soon' ) ? __( 'Maintenance is on', 'deft-core' ) : __( 'Coming soon is on', 'deft-core' ) ) . '</span>',
			'href'  => current_user_can( 'manage_options' ) ? admin_url( 'options-general.php?page=deft-core#deft-soon' ) : false,
			'meta'  => array( 'title' => __( 'Visitors see the Deft holding page, not the site. Click to change it.', 'deft-core' ) ),
		)
	);
}
