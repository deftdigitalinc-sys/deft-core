<?php
/**
 * Site security (0.10.0, after the October 2026 hacks): close the usual ways in, and notice fast when files change.
 *
 * Protections, one switch each under Settings > Deft > Site security (on by default unless noted; switching one off,
 * or turning the plugin off, removes it again):
 * - The theme and plugin file editor is off (DISALLOW_FILE_EDIT), so a stolen login can't write PHP from wp-admin.
 * - PHP can't run in uploads (a marked block in uploads/.htaccess). Uploads only ever holds media.
 * - The web root refuses archives, dumps and backups (backup.zip, *.sql, *.bak, .env, .git), stops folder listings
 *   and hides readme.html and wp-config.php (a marked block in .htaccess, checked with a request to the home page
 *   right after, and taken back out if the site stops loading).
 * - XML-RPC is off (it's how most password guessing arrives), unless Jetpack needs it.
 * - Application passwords are off (Daniel, 2026-10-26: none are used on Deft sites).
 * - Failed logins: 5 wrong tries from one address lock that address out for 15 minutes, and a third lockout in a day
 *   locks it for 24 hours. Error messages no longer say whether the username was right. Skipped while Wordfence runs,
 *   since it already does this.
 * - Author pages and the users API don't list usernames to visitors.
 * - Security headers: nosniff and a referrer policy (HSTS is its own switch, off by default, because browsers remember
 *   it for a year).
 * - Plugin auto-updates (off by default; Elementor and WooCommerce are left to update by hand).
 * - Two-factor codes for administrators, a "new passwords for everyone" button and a breached-password check live in
 *   modules/security-login.php.
 *
 * File check (daily, and on demand): WordPress core and wordpress.org plugins against the official checksums, our own
 * files against data/manifest.json, everything else (premium plugins, the theme, must-use plugins, the web root)
 * against a baseline taken the first time, plus the signs from the incident's indicator list (iocs.md): auto_prepend_file
 * in .user.ini, PHP files in the web root, spam sitemaps, archives, unknown must-use plugins and drop-ins, plugins hidden
 * from the admin list, hidden administrators and the usermeta keys the 2026 malware families leave behind. Results go
 * into Site watch's health report for Deft Central, and new problems are emailed to the security alert address.
 *
 * Nothing here deletes or moves a file: clean-up is a person's job (iocs.md section F has the order).
 */

defined( 'ABSPATH' ) || exit;

const DEFT_SEC_MARKER = 'Deft Core security';

/** The protections, in the order the settings and the health report list them. */
const DEFT_SEC_SWITCHES = array( 'file_edit', 'uploads_php', 'root_files', 'xmlrpc', 'app_passwords', 'login_limit', 'enumeration', 'headers', 'hsts', 'auto_updates' );

/**
 * Plugins Deft doesn't run on client sites: file managers and database tools (the classic way in), remote management
 * (MainWP isn't used, Daniel 2026-10-26; Child was on 21 of 39 sites), code injection and temporary logins.
 */
const DEFT_SEC_DENY_PLUGINS = array( 'wp-file-manager', 'file-manager-advanced', 'wp-filemanager', 'filester', 'file-manager', 'mainwp-child', 'mainwp', 'mainwp-child-reports', 'wp-phpmyadmin-extension', 'adminer', 'code-snippets', 'code-snippets-extended', 'insert-php', 'insert-headers-and-footers', 'header-footer-code-manager', 'woody-ad-snippets', 'temporary-login-without-password', 'wp-temporary-login', 'php-everywhere', 'wpcode-premium' );

/** Must-use plugins we know. Anything else in mu-plugins is flagged until it's added to the allowed list. */
const DEFT_SEC_KNOWN_MU = array( 'deft-central.php', 'index.php' );

/** Usermeta keys and usernames the 2026 malware families leave behind (iocs.md section B). */
const DEFT_SEC_BAD_META = array( '_wps_sig', '_sys_token', '_adm_key' );
const DEFT_SEC_BAD_LOGIN = '/^(usr_[0-9a-f]{8}|officialwp|wpadmin_[0-9a-f]{4,}|adm1n[0-9]*)$/i';

/** Code that only malware needs (iocs.md section C), used to say how bad a changed file looks. */
const DEFT_SEC_CODE_SIGNS = '/(eval\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13|\$_(POST|GET|REQUEST|COOKIE))|assert\s*\(\s*\$_|preg_replace\s*\(\s*[\'"].{1,40}\/e[\'"]|create_function\s*\(|\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}|\$GLOBALS\[[\'"][a-z0-9_]{1,12}[\'"]\]\[\d+\]\(|PclZip|move_uploaded_file\s*\(\s*\$_FILES|shell_exec\s*\(|passthru\s*\(|base64_decode\s*\(\s*[\'"][A-Za-z0-9+\/=]{400,})/i';

add_filter( 'deft_core_defaults', 'deft_sec_defaults' );
function deft_sec_defaults( $defaults ) {
	return $defaults + array(
		'sec_file_edit'     => 1,
		'sec_uploads_php'   => 1,
		'sec_root_files'    => 1,
		'sec_xmlrpc'        => 1,
		'sec_app_passwords' => 1,
		'sec_login_limit'   => 1,
		'sec_enumeration'   => 1,
		'sec_headers'       => 1,
		'sec_hsts'          => 0,
		'sec_auto_updates'  => 0,
		'sec_scan'          => 1,
		'sec_alert_email'   => '',
		'sec_allow'         => '',
	);
}

add_filter( 'deft_core_sanitize', 'deft_sec_sanitize', 10, 2 );
function deft_sec_sanitize( $out, $input ) {
	foreach ( DEFT_SEC_SWITCHES as $key ) {
		$out[ 'sec_' . $key ] = empty( $input[ 'sec_' . $key ] ) ? 0 : 1;
	}
	$out['sec_scan']        = empty( $input['sec_scan'] ) ? 0 : 1;
	$emails                 = array_filter( array_map( 'sanitize_email', explode( ',', (string) ( $input['sec_alert_email'] ?? '' ) ) ) );
	$out['sec_alert_email'] = implode( ', ', $emails );
	// One path per line, relative to the web root (wp-content/mu-plugins/host-cache.php, old-site/).
	$allow = array();
	foreach ( preg_split( '/[\r\n]+/', (string) ( $input['sec_allow'] ?? '' ) ) as $line ) {
		$line = trim( str_replace( '\\', '/', sanitize_text_field( $line ) ), " /\t" );
		if ( '' !== $line && false === strpos( $line, '..' ) ) {
			$allow[] = $line;
		}
	}
	$out['sec_allow'] = implode( "\n", array_slice( array_unique( $allow ), 0, 50 ) );
	return $out;
}

function deft_sec_on( $key ) {
	return (bool) deft_core_setting( 'sec_' . $key );
}

/** Paths staff marked as expected (exact files, or folders ending in /). */
function deft_sec_allowed( $rel ) {
	$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
	foreach ( preg_split( '/\n/', (string) deft_core_setting( 'sec_allow' ) ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line && ( $rel === $line || 0 === strpos( $rel, rtrim( $line, '/' ) . '/' ) ) ) {
			return true;
		}
	}
	return false;
}

/** Where alerts go: the setting, else the address Central wrote into wp-content/mu-plugins/deft-central.php. */
function deft_sec_alert_recipients() {
	$list = (string) deft_core_setting( 'sec_alert_email' );
	if ( '' === $list && defined( 'DEFT_CORE_SECURITY_EMAIL' ) ) {
		$list = (string) DEFT_CORE_SECURITY_EMAIL;
	}
	return array_values( array_filter( array_map( 'trim', explode( ',', $list ) ), 'is_email' ) );
}

/* ---------- Protections that run on every request ---------- */

// The file editor. WordPress reads the constant when it checks capabilities, so defining it here is in time.
if ( ! defined( 'DISALLOW_FILE_EDIT' ) && deft_sec_on( 'file_edit' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

function deft_sec_jetpack() {
	return class_exists( 'Jetpack' ) || defined( 'JETPACK__VERSION' );
}

function deft_sec_wordfence() {
	return defined( 'WORDFENCE_VERSION' ) || class_exists( 'wordfence' );
}

add_action( 'plugins_loaded', 'deft_sec_runtime', 5 );
function deft_sec_runtime() {
	if ( deft_sec_on( 'xmlrpc' ) && ! deft_sec_jetpack() ) {
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', 'deft_sec_xmlrpc_methods' );
		add_filter( 'wp_headers', 'deft_sec_no_pingback_header' );
		add_filter( 'pings_open', '__return_false', 99 );
	}
	if ( deft_sec_on( 'app_passwords' ) ) {
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
	}
	if ( deft_sec_on( 'login_limit' ) && ! deft_sec_wordfence() ) {
		add_filter( 'authenticate', 'deft_sec_login_gate', 99, 1 );
		add_action( 'wp_login_failed', 'deft_sec_login_failed' );
		add_filter( 'login_errors', 'deft_sec_login_errors' );
	}
	if ( deft_sec_on( 'enumeration' ) ) {
		add_action( 'template_redirect', 'deft_sec_block_author_scan', 0 );
		add_filter( 'rest_endpoints', 'deft_sec_rest_users' );
		add_filter( 'wp_sitemaps_add_provider', 'deft_sec_no_user_sitemap', 10, 2 );
	}
	if ( deft_sec_on( 'headers' ) || deft_sec_on( 'hsts' ) ) {
		add_action( 'send_headers', 'deft_sec_headers' );
	}
	if ( deft_sec_on( 'auto_updates' ) ) {
		add_filter( 'auto_update_plugin', 'deft_sec_auto_update_plugin', 20, 2 );
	}
}

/** Even with XML-RPC "off", WordPress still answers pingbacks and multicall; take those out too. */
function deft_sec_xmlrpc_methods( $methods ) {
	unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'], $methods['system.multicall'] );
	return $methods;
}

function deft_sec_no_pingback_header( $headers ) {
	unset( $headers['X-Pingback'] );
	return $headers;
}

function deft_sec_headers() {
	if ( headers_sent() ) {
		return;
	}
	if ( deft_sec_on( 'headers' ) ) {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	}
	if ( deft_sec_on( 'hsts' ) && is_ssl() ) {
		header( 'Strict-Transport-Security: max-age=31536000' );
	}
}

/** Plugin auto-updates, except the builders and shops that need a person watching. */
function deft_sec_auto_update_plugin( $update, $item ) {
	$slug = isset( $item->slug ) ? (string) $item->slug : '';
	if ( in_array( $slug, apply_filters( 'deft_sec_manual_updates', array( 'elementor', 'elementor-pro', 'woocommerce' ) ), true ) ) {
		return $update;
	}
	return true;
}

/* ---------- Logins ---------- */

function deft_sec_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}

function deft_sec_login_key( $ip ) {
	return 'deft_sec_login_' . substr( md5( $ip ), 0, 16 );
}

/** Runs after WordPress checked the password, so a locked-out address is refused even with the right one. */
function deft_sec_login_gate( $user ) {
	$state = get_transient( deft_sec_login_key( deft_sec_ip() ) );
	if ( is_array( $state ) && ! empty( $state['until'] ) && $state['until'] > time() ) {
		$minutes = (int) ceil( ( $state['until'] - time() ) / 60 );
		/* translators: %d: minutes */
		return new WP_Error( 'deft_locked', sprintf( __( 'Too many wrong tries. Try again in %d minutes.', 'deft-core' ), $minutes ) );
	}
	return $user;
}

function deft_sec_login_failed() {
	$ip    = deft_sec_ip();
	$key   = deft_sec_login_key( $ip );
	$state = get_transient( $key );
	$state = is_array( $state ) ? $state : array( 'fails' => array(), 'locks' => array(), 'until' => 0 );
	$now   = time();
	$state['fails']   = array_values( array_filter( (array) $state['fails'], function ( $t ) use ( $now ) {
		return $t > $now - 15 * MINUTE_IN_SECONDS;
	} ) );
	$state['locks']   = array_values( array_filter( (array) $state['locks'], function ( $t ) use ( $now ) {
		return $t > $now - DAY_IN_SECONDS;
	} ) );
	$state['fails'][] = $now;
	if ( count( $state['fails'] ) >= 5 ) {
		$state['locks'][] = $now;
		$state['fails']   = array();
		$state['until']   = $now + ( count( $state['locks'] ) >= 3 ? DAY_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
		$log              = get_option( 'deft_sec_lockouts', array() );
		$log              = is_array( $log ) ? array_slice( $log, -49 ) : array();
		$log[]            = array( 'at' => $now, 'ip' => $ip, 'until' => $state['until'] );
		update_option( 'deft_sec_lockouts', $log, false );
	}
	set_transient( $key, $state, DAY_IN_SECONDS );
}

/** One message for a wrong username and a wrong password, so guessing which usernames exist gets nowhere. */
function deft_sec_login_errors( $error ) {
	global $errors;
	if ( is_wp_error( $errors ) && array_intersect( $errors->get_error_codes(), array( 'deft_locked' ) ) ) {
		return $error;
	}
	if ( is_wp_error( $errors ) && array_intersect( $errors->get_error_codes(), array( 'invalid_username', 'invalid_email', 'incorrect_password' ) ) ) {
		return __( '<strong>Error:</strong> The username or password is wrong.', 'deft-core' );
	}
	return $error;
}

function deft_sec_lockouts_24h() {
	$log = get_option( 'deft_sec_lockouts', array() );
	return count( array_filter( is_array( $log ) ? $log : array(), function ( $row ) {
		return ( $row['at'] ?? 0 ) > time() - DAY_IN_SECONDS;
	} ) );
}

/* ---------- Usernames ---------- */

/** ?author=1 is how scanners learn the admin's username: send visitors to the home page instead. */
function deft_sec_block_author_scan() {
	if ( is_user_logged_in() ) {
		return;
	}
	if ( isset( $_GET['author'] ) || is_author() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( home_url( '/' ), 301, 'Deft Core' );
		exit;
	}
}

function deft_sec_rest_users( $endpoints ) {
	if ( is_user_logged_in() ) {
		return $endpoints;
	}
	foreach ( array_keys( $endpoints ) as $route ) {
		if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
			unset( $endpoints[ $route ] );
		}
	}
	return $endpoints;
}

function deft_sec_no_user_sitemap( $provider, $name ) {
	return 'users' === $name ? false : $provider;
}

/* ---------- .htaccess blocks ---------- */

function deft_sec_uploads_rules() {
	return array(
		'# PHP never runs from uploads (media only).',
		'<FilesMatch "\.(?i:php[0-9]?|phtml|phar|pht|phps|cgi|pl|py|sh)$">',
		'<IfModule mod_authz_core.c>',
		'Require all denied',
		'</IfModule>',
		'<IfModule !mod_authz_core.c>',
		'Order allow,deny',
		'Deny from all',
		'</IfModule>',
		'</FilesMatch>',
		'<IfModule mod_php.c>',
		'php_flag engine off',
		'</IfModule>',
		'<IfModule mod_php7.c>',
		'php_flag engine off',
		'</IfModule>',
		'<IfModule mod_php8.c>',
		'php_flag engine off',
		'</IfModule>',
	);
}

function deft_sec_root_rules() {
	return array(
		'# No folder listings, and no archives, dumps, backups or config files from the web.',
		'Options -Indexes',
		'<FilesMatch "^(wp-config\.php|readme\.html|license\.txt|wp-config-sample\.php|\.user\.ini|php\.ini|\.env.*|debug\.log|error_log|.*\.(sql|sql\.gz|bak|bk|old|orig|save|swp|dist|zip|tar|tgz|gz|rar|7z|wpress|log))$">',
		'<IfModule mod_authz_core.c>',
		'Require all denied',
		'</IfModule>',
		'<IfModule !mod_authz_core.c>',
		'Order allow,deny',
		'Deny from all',
		'</IfModule>',
		'</FilesMatch>',
		'<IfModule mod_rewrite.c>',
		'RewriteEngine On',
		'RewriteRule (^|/)\.(git|svn|hg)(/|$) - [F,L]',
		'</IfModule>',
	);
}

/** Uploads keep working for media: zip downloads in uploads are still allowed, the archive rule is the web root's. */
function deft_sec_uploads_htaccess() {
	$uploads = wp_upload_dir( null, false );
	return trailingslashit( $uploads['basedir'] ) . '.htaccess';
}

function deft_sec_write_block( $file, $lines ) {
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}
	$dir = dirname( $file );
	if ( ! is_dir( $dir ) || ( file_exists( $file ) ? ! is_writable( $file ) : ! is_writable( $dir ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		return false;
	}
	return (bool) insert_with_markers( $file, DEFT_SEC_MARKER, $lines );
}

function deft_sec_has_block( $file ) {
	return is_readable( $file ) && false !== strpos( (string) file_get_contents( $file ), '# BEGIN ' . DEFT_SEC_MARKER ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

/** The uploads rule: an <FilesMatch> placed in uploads/.htaccess (other plugins' blocks there are left alone). */
function deft_sec_apply_uploads( $on ) {
	$file = deft_sec_uploads_htaccess();
	if ( ! $on ) {
		return deft_sec_has_block( $file ) ? deft_sec_write_block( $file, array() ) : true;
	}
	return deft_sec_write_block( $file, deft_sec_uploads_rules() );
}

/**
 * The web root rule. Written, then the home page is asked for once: if it now fails with a server error (a host that
 * doesn't allow Options in .htaccess), the block comes straight back out and the reason is kept for the report.
 */
function deft_sec_apply_root( $on ) {
	$file = ABSPATH . '.htaccess';
	if ( ! $on ) {
		return deft_sec_has_block( $file ) ? deft_sec_write_block( $file, array() ) : true;
	}
	$had = deft_sec_has_block( $file );
	if ( ! deft_sec_write_block( $file, deft_sec_root_rules() ) ) {
		return false;
	}
	if ( ! $had ) {
		$check = wp_remote_get( add_query_arg( 'deft_sec_check', wp_generate_password( 8, false ), home_url( '/' ) ), array( 'timeout' => 10, 'redirection' => 3, 'sslverify' => false, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		if ( ! is_wp_error( $check ) && wp_remote_retrieve_response_code( $check ) >= 500 ) {
			deft_sec_write_block( $file, array() );
			update_option( 'deft_sec_root_error', array( 'at' => time(), 'code' => wp_remote_retrieve_response_code( $check ) ), false );
			return false;
		}
		delete_option( 'deft_sec_root_error' );
	}
	return true;
}

/** What the switches say, as a short fingerprint, so the .htaccess blocks are only rewritten when something changed. */
function deft_sec_fingerprint() {
	return DEFT_CORE_VERSION . ':' . (int) deft_sec_on( 'uploads_php' ) . (int) deft_sec_on( 'root_files' );
}

// After an update or a settings change (both cheap to notice: one option read), put the blocks where the switches say.
// Only in wp-admin and WP-Cron, so a visitor never waits on the home page check below.
add_action( 'admin_init', 'deft_sec_maybe_apply', 20 );
add_action( 'init', 'deft_sec_maybe_apply_cron', 20 );
function deft_sec_maybe_apply_cron() {
	if ( wp_doing_cron() ) {
		deft_sec_maybe_apply();
	}
}

function deft_sec_maybe_apply( $force = false ) {
	if ( true !== $force && get_option( 'deft_sec_applied' ) === deft_sec_fingerprint() ) {
		return;
	}
	update_option( 'deft_sec_applied', deft_sec_fingerprint(), true ); // First, so two requests at once don't both write.
	$result = array(
		'uploads_php' => deft_sec_apply_uploads( deft_sec_on( 'uploads_php' ) ),
		'root_files'  => deft_sec_apply_root( deft_sec_on( 'root_files' ) ),
	);
	update_option( 'deft_sec_apply_result', $result + array( 'at' => time() ), false );
	// Our own .htaccess edits aren't news to the file check.
	deft_sec_baseline_refresh_root();
}

add_action( 'update_option_deft_core', 'deft_sec_settings_changed', 20, 2 );
function deft_sec_settings_changed( $old, $new ) {
	foreach ( array( 'sec_uploads_php', 'sec_root_files' ) as $key ) {
		if ( (int) ( $old[ $key ] ?? 1 ) !== (int) ( $new[ $key ] ?? 1 ) ) {
			delete_option( 'deft_sec_applied' );
			return;
		}
	}
}

/** Turning the plugin off takes the .htaccess blocks out, so the site is back to how it was. */
function deft_sec_deactivate() {
	deft_sec_apply_uploads( false );
	deft_sec_apply_root( false );
	delete_option( 'deft_sec_applied' );
	wp_clear_scheduled_hook( 'deft_sec_scan' );
}

/** Whether each protection is actually working now (not just switched on), for the health report. */
function deft_sec_applied() {
	return array(
		'file_edit'     => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
		'uploads_php'   => deft_sec_has_block( deft_sec_uploads_htaccess() ),
		'root_files'    => deft_sec_has_block( ABSPATH . '.htaccess' ),
		'xmlrpc'        => deft_sec_on( 'xmlrpc' ) ? ( deft_sec_jetpack() ? 'jetpack' : true ) : false,
		'app_passwords' => deft_sec_on( 'app_passwords' ),
		'login_limit'   => deft_sec_on( 'login_limit' ) ? ( deft_sec_wordfence() ? 'wordfence' : true ) : ( deft_sec_wordfence() ? 'wordfence' : false ),
		'enumeration'   => deft_sec_on( 'enumeration' ),
		'headers'       => deft_sec_on( 'headers' ),
		'hsts'          => deft_sec_on( 'hsts' ),
		'auto_updates'  => deft_sec_on( 'auto_updates' ),
	);
}

/* ---------- File check ---------- */

add_action( 'init', 'deft_sec_schedule' );
function deft_sec_schedule() {
	if ( deft_sec_on( 'scan' ) && ! wp_next_scheduled( 'deft_sec_scan' ) ) {
		// Night time on the site's clock, a few hours before Central's 6:30 New York check reads the result.
		wp_schedule_event( time() + wp_rand( 10, 120 ) * MINUTE_IN_SECONDS, 'daily', 'deft_sec_scan' );
	} elseif ( ! deft_sec_on( 'scan' ) && wp_next_scheduled( 'deft_sec_scan' ) ) {
		wp_clear_scheduled_hook( 'deft_sec_scan' );
	}
}

add_action( 'deft_sec_scan', 'deft_sec_run_scan' );

function deft_sec_rel( $path ) {
	$path = str_replace( '\\', '/', $path );
	$root = str_replace( '\\', '/', ABSPATH );
	return 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
}

/** Files under a folder, as relative path => absolute path, capped so a huge folder can't stall the check. */
function deft_sec_walk( $dir, $cap = 20000, $only_code = false ) {
	$out = array();
	if ( ! is_dir( $dir ) ) {
		return $out;
	}
	try {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY );
		foreach ( $it as $file ) {
			if ( count( $out ) >= $cap ) {
				break;
			}
			if ( ! $file->isFile() || ( $only_code && ! deft_sec_is_code( $file->getFilename() ) ) ) {
				continue;
			}
			$out[ deft_sec_rel( $file->getPathname() ) ] = $file->getPathname();
		}
	} catch ( Exception $e ) {
		return $out;
	}
	return $out;
}

function deft_sec_is_code( $name ) {
	return (bool) preg_match( '/\.(php[0-9]?|phtml|phar|pht|inc|ico\.php)$|^\.user\.ini$|^\.htaccess$/i', $name );
}

/** One file's look: does it carry code only malware needs? Reads the first 512 KB. */
function deft_sec_code_signs( $path ) {
	$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( ! $fh ) {
		return false;
	}
	$head = (string) fread( $fh, 524288 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return (bool) preg_match( DEFT_SEC_CODE_SIGNS, $head );
}

function deft_sec_http_json( $url ) {
	$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) ? $data : null;
}

/** WordPress core against the official checksums, plus files in wp-admin and wp-includes that core doesn't ship. */
function deft_sec_check_core( &$state ) {
	global $wp_version, $wp_local_package;
	if ( ! function_exists( 'get_core_checksums' ) ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}
	$sums = get_core_checksums( $wp_version, isset( $wp_local_package ) ? $wp_local_package : 'en_US' );
	if ( ! is_array( $sums ) || ! $sums ) {
		$sums = get_core_checksums( $wp_version, 'en_US' );
	}
	if ( ! is_array( $sums ) || ! $sums ) {
		$state['pending'][] = 'WordPress checksums could not be fetched';
		return array();
	}
	$changed = array();
	$extra   = array();
	foreach ( $sums as $rel => $md5 ) {
		if ( 0 === strpos( $rel, 'wp-content/' ) ) {
			continue;
		}
		$path = ABSPATH . $rel;
		if ( is_file( $path ) && md5_file( $path ) !== $md5 ) {
			$changed[] = $rel;
		}
	}
	foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
		foreach ( deft_sec_walk( ABSPATH . $dir, 20000, true ) as $rel => $path ) {
			if ( ! isset( $sums[ $rel ] ) && ! deft_sec_allowed( $rel ) ) {
				$extra[] = $rel;
			}
		}
	}
	$state['core_files'] = count( $sums );
	return array( 'changed' => $changed, 'extra' => $extra, 'sums' => $sums );
}

/** Files in the web root (one level) and dot-files there, against what WordPress ships and the incident's signs. */
function deft_sec_check_root( $core_sums, $add ) {
	$root   = ABSPATH;
	$php    = array();
	$maps   = array();
	$arch   = array();
	$ini    = array();
	$wf_waf = '';
	$google = array();

	// auto_prepend_file loads a file before every page. Wordfence's firewall uses it legitimately (wordfence-waf.php);
	// the "SC" mesh uses it to come back after a clean-up.
	$prepend_sources = array();
	$live            = (string) ini_get( 'auto_prepend_file' );
	if ( '' !== $live ) {
		$prepend_sources[] = array( 'from' => 'PHP settings', 'file' => $live );
	}
	foreach ( array( '.user.ini', 'php.ini', 'wp-admin/.user.ini', 'wp-content/.user.ini', '.htaccess' ) as $rel ) {
		$path = $root . $rel;
		if ( ! is_readable( $path ) ) {
			continue;
		}
		$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( preg_match_all( '/^\s*(?:php_value\s+)?auto_prepend_file\s*=?\s*["\']?([^"\'\r\n]+)/mi', $text, $m ) ) {
			foreach ( $m[1] as $target ) {
				$prepend_sources[] = array( 'from' => $rel, 'file' => trim( $target ) );
			}
		}
	}
	foreach ( $prepend_sources as $src ) {
		$file = $src['file'];
		if ( 'none' === strtolower( $file ) || '' === $file ) {
			continue;
		}
		$is_wf = 'wordfence-waf.php' === basename( $file ) && is_readable( $file ) && false !== strpos( (string) file_get_contents( $file ), 'Wordfence' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $is_wf ) {
			$wf_waf = deft_sec_rel( $file );
			continue;
		}
		if ( ! deft_sec_allowed( deft_sec_rel( $file ) ) ) {
			$ini[] = sprintf( '%s loads %s', $src['from'], deft_sec_rel( $file ) );
		}
	}
	if ( $ini ) {
		$add( 'bad', 'user_ini_prepend', sprintf( 'A file runs before every page: %s. This is how self-healing malware comes back after a clean-up. Neutralize it first (iocs.md section F, step 2).', implode( '; ', array_unique( $ini ) ) ) );
	}

	$entries = @scandir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	foreach ( (array) $entries as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		$path = $root . $name;
		if ( is_dir( $path ) ) {
			if ( in_array( $name, array( 'wp-admin', 'wp-includes', 'wp-content', 'cgi-bin', '.well-known', '.tmb', '.quarantine', '.git' ), true ) || deft_sec_allowed( $name . '/' ) ) {
				continue;
			}
			// A folder WordPress doesn't use with PHP in it: an old site copy, or an attacker's folder (casino-online/).
			$code = deft_sec_walk( $path, 200, true );
			if ( $code ) {
				$php[] = $name . '/ (' . count( $code ) . ' PHP files)';
			}
			continue;
		}
		$lower = strtolower( $name );
		if ( preg_match( '/\.(php[0-9]?|phtml|phar)$/i', $name ) ) {
			if ( isset( $core_sums[ $name ] ) || 'wp-config.php' === $name || deft_sec_allowed( $name ) || ( $wf_waf && $wf_waf === $name ) ) {
				continue;
			}
			// Hostinger's parked-page placeholder, seen on clean sites too (iocs.md "benign").
			if ( 'default.php' === $name && 16369 === (int) filesize( $path ) ) {
				continue;
			}
			$php[] = $name;
		} elseif ( preg_match( '/^sitemap[-_]?\d+\.xml$/i', $name ) ) {
			$size = (int) filesize( $path );
			$head = (string) file_get_contents( $path, false, null, 0, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $size > 50000 || preg_match( DEFT_WATCH_SPAM, $head ) || false !== strpos( $head, '?p=' ) ) {
				$maps[] = sprintf( '%s (%s)', $name, size_format( $size ) );
			}
		} elseif ( 'update-log.txt' === $lower ) {
			// The attacker's upload log is base64; a plain-text update-log.txt is benign (seen on clean sites).
			if ( preg_match( '/[A-Za-z0-9+\/=]{200,}/', (string) file_get_contents( $path, false, null, 0, 65536 ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$php[] = 'update-log.txt (encoded upload log)';
			}
		} elseif ( 'robots.txt' === $lower ) {
			$robots = (string) file_get_contents( $path, false, null, 0, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( preg_match( '/feed=xmlsitemap|sitemap\d{2,}/i', $robots ) || preg_match_all( '/^\s*sitemap:/mi', $robots ) > 5 ) {
				$maps[] = 'robots.txt (lists spam sitemaps)';
			}
		} elseif ( preg_match( '/^google[0-9a-f]{8,}\.html$/i', $name ) && ! deft_sec_allowed( $name ) ) {
			$google[] = $name;
		} elseif ( preg_match( '/\.(zip|tar|tgz|gz|rar|7z|sql|wpress|bak|bk|old)$/i', $lower ) || preg_match( '/^\.htaccess\.|^wp-config\.php\./i', $lower ) ) {
			if ( ! deft_sec_allowed( $name ) ) {
				$arch[] = sprintf( '%s (%s)', $name, size_format( (int) filesize( $path ) ) );
			}
		}
	}
	if ( $php ) {
		$add( 'bad', 'root_php', sprintf( 'PHP in the web root that WordPress doesn\'t ship: %s.', implode( ', ', array_slice( $php, 0, 8 ) ) . ( count( $php ) > 8 ? sprintf( ' and %d more', count( $php ) - 8 ) : '' ) ) );
	}
	if ( $maps ) {
		$add( 'bad', 'spam_sitemap', sprintf( 'Spam sitemaps in the web root: %s. They feed casino pages to Google.', implode( ', ', array_slice( $maps, 0, 6 ) ) ) );
	}
	if ( $arch ) {
		$add( 'bad', 'root_archive', sprintf( 'Archives or backups in the web root anyone may download: %s. They can hold the database password.', implode( ', ', array_slice( $arch, 0, 6 ) ) ) );
	}
	// The root .htaccess should still carry WordPress's own block; injected versions often replace it.
	$htaccess = is_readable( $root . '.htaccess' ) ? (string) file_get_contents( $root . '.htaccess' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$wp_block = '' === (string) get_option( 'permalink_structure' ) || false !== strpos( $htaccess, '# BEGIN WordPress' );
	return array( 'php' => $php, 'sitemaps' => $maps, 'archives' => $arch, 'prepend' => $ini, 'wordfence_waf' => $wf_waf, 'google' => $google, 'wp_block' => $wp_block );
}

/** Must-use plugins and drop-ins load on every page and don't show in the plugin list, so malware loves them. */
function deft_sec_check_mu_dropins( $add ) {
	$unknown = array();
	foreach ( deft_sec_walk( WPMU_PLUGIN_DIR, 500 ) as $rel => $path ) {
		$name = substr( $rel, strlen( deft_sec_rel( WPMU_PLUGIN_DIR ) ) + 1 );
		if ( in_array( $name, apply_filters( 'deft_sec_known_mu', DEFT_SEC_KNOWN_MU ), true ) || deft_sec_allowed( $rel ) ) {
			// Our connection file only ever defines three constants; anything more in it is someone else's code.
			if ( 'deft-central.php' === $name && preg_match( '/\b(eval|include|require|base64_decode|file_put_contents|curl_|fopen)\b/i', (string) file_get_contents( $path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$unknown[] = $name . ' (changed)';
			}
			continue;
		}
		if ( ! preg_match( '/\.(php[0-9]?|phtml|inc)$/i', $name ) && false === strpos( $name, '/' ) && 'index.html' === strtolower( $name ) ) {
			continue;
		}
		$unknown[] = $name . ( deft_sec_code_signs( $path ) ? ' (malware-like code)' : '' );
	}
	if ( $unknown ) {
		$add( 'bad', 'unknown_mu_plugin', sprintf( 'Must-use plugins nobody listed: %s. Recent malware hides here (iocs.md A). If one is expected, add it under Settings > Deft > Site security > Expected files.', implode( ', ', array_slice( $unknown, 0, 8 ) ) ) );
	}

	// Drop-ins: replace parts of WordPress itself. Caching plugins write advanced-cache.php and object-cache.php.
	$owners  = array( 'LiteSpeed', 'W3 Total Cache', 'WP Rocket', 'WP Super Cache', 'WP Fastest Cache', 'Redis', 'Memcache', 'Query Monitor', 'Hostinger', 'WP-Optimize', 'Breeze', 'SG Optimizer', 'Docket Cache', 'Object Cache Pro', 'Cache Enabler', 'Comet Cache', 'Swift Performance', 'Hummingbird', 'sqlite' );
	$unknown = array();
	if ( ! function_exists( 'get_dropins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( array_keys( get_dropins() ) as $file ) {
		$path = WP_CONTENT_DIR . '/' . $file;
		$text = (string) file_get_contents( $path, false, null, 0, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$owned = false;
		foreach ( $owners as $owner ) {
			if ( false !== stripos( $text, $owner ) ) {
				$owned = true;
				break;
			}
		}
		if ( ( ! $owned || deft_sec_code_signs( $path ) ) && ! deft_sec_allowed( 'wp-content/' . $file ) ) {
			$unknown[] = $file . ( deft_sec_code_signs( $path ) ? ' (malware-like code)' : '' );
		}
	}
	if ( $unknown ) {
		$add( 'warn', 'dropin_unknown', sprintf( 'Drop-ins that no known cache or tool wrote: %s. The 2026 "SC" malware plants db.php and advanced-cache.php.', implode( ', ', $unknown ) ) );
	}
	// Hidden copies next to wp-content (wp-content/.c1b12371.php style) and loose PHP in wp-content itself.
	$loose = array();
	foreach ( (array) @scandir( WP_CONTENT_DIR ) as $name ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$path = WP_CONTENT_DIR . '/' . $name;
		if ( is_file( $path ) && preg_match( '/\.(php[0-9]?|phtml)$/i', $name ) && ! in_array( $name, array( 'index.php', 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'maintenance.php', 'install.php', 'php-error.php', 'fatal-error-handler.php', 'sunrise.php', 'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php', 'wp-cache-config.php' ), true ) && ! deft_sec_allowed( 'wp-content/' . $name ) ) {
			$loose[] = 'wp-content/' . $name;
		}
	}
	return $loose;
}

/**
 * Plugins: wordpress.org ones against the official checksums (changed files, and PHP files the release doesn't have),
 * Deft Core against its own manifest, the rest against the baseline. Also plugins hidden from the admin list,
 * folders with PHP but no plugin, and plugins Deft doesn't run.
 */
function deft_sec_check_plugins( &$state, &$baseline, $add, $deadline ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	wp_cache_delete( 'plugins', 'plugins' );
	$all      = get_plugins();
	$listed   = apply_filters( 'all_plugins', $all ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WordPress's own filter, read to catch plugins that hide themselves from it.
	$hidden   = array_filter( array_diff_key( $all, (array) $listed ), function ( $file ) {
		return ! deft_sec_allowed( 'wp-content/plugins/' . ( false === strpos( $file, '/' ) ? $file : dirname( $file ) . '/' ) );
	}, ARRAY_FILTER_USE_KEY );
	$modified = array();
	$changed  = array();
	$denied   = array();
	$slugs    = array();
	foreach ( $all as $file => $data ) {
		$slug = false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file );
		$slugs[ $slug ] = true;
		if ( in_array( $slug, DEFT_SEC_DENY_PLUGINS, true ) ) {
			$denied[] = $data['Name'];
		}
	}
	if ( $hidden ) {
		$add( 'bad', 'hidden_plugin', sprintf( 'Plugins on disk that hide from the Plugins screen: %s.', implode( ', ', array_keys( $hidden ) ) ) );
	}
	$ghost = array();
	foreach ( (array) @scandir( WP_PLUGIN_DIR ) as $name ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( '.' === $name[0] || isset( $slugs[ $name ] ) || ! is_dir( WP_PLUGIN_DIR . '/' . $name ) ) {
			continue;
		}
		if ( deft_sec_walk( WP_PLUGIN_DIR . '/' . $name, 5, true ) && ! deft_sec_allowed( 'wp-content/plugins/' . $name . '/' ) ) {
			$ghost[] = $name;
		}
	}
	if ( is_dir( WP_PLUGIN_DIR . '/tmp' ) && ! in_array( 'tmp', $ghost, true ) ) {
		$ghost[] = 'tmp (an unzip folder the 2026 toolkit leaves, often with unzipsuccess)';
	}
	if ( $ghost ) {
		$add( 'bad', 'hidden_plugin', sprintf( 'Folders in plugins with PHP but no plugin WordPress can see: %s.', implode( ', ', array_slice( $ghost, 0, 8 ) ) ) );
	}
	if ( $denied ) {
		$add( 'warn', 'bad_plugin', sprintf( 'Plugins Deft doesn\'t run on client sites: %s. File managers, remote management, code snippets and temporary logins are common ways in. Deactivate and remove them.', implode( ', ', $denied ) ) );
	}

	$plugin_rel = deft_sec_rel( WP_PLUGIN_DIR );
	foreach ( $all as $file => $data ) {
		if ( microtime( true ) > $deadline ) {
			$state['pending'][] = 'some plugins were left for the next check (time limit)';
			break;
		}
		if ( false === strpos( $file, '/' ) ) {
			continue; // Single-file plugins (hello.php) are covered by the code look below.
		}
		$slug  = dirname( $file );
		$dir   = WP_PLUGIN_DIR . '/' . $slug;
		$ver   = (string) $data['Version'];
		$files = deft_sec_walk( $dir, 15000 );
		$sums  = null;
		$kind  = 'baseline';
		if ( 'deft-core' === $slug || DEFT_CORE_DIR === trailingslashit( $dir ) ) {
			$manifest = DEFT_CORE_DIR . 'data/manifest.json';
			$sums     = is_readable( $manifest ) ? json_decode( (string) file_get_contents( $manifest ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$sums     = is_array( $sums ) && ( $sums['version'] ?? '' ) === DEFT_CORE_VERSION ? $sums['files'] : null;
			$kind     = 'manifest';
		} else {
			$cache = get_transient( 'deft_sec_sums_' . md5( $slug . '@' . $ver ) );
			if ( 'none' === $cache ) {
				$sums = null;
			} elseif ( is_array( $cache ) ) {
				$sums = $cache;
				$kind = 'wporg';
			} else {
				$data_json = deft_sec_http_json( 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $slug ) . '/' . rawurlencode( $ver ) . '.json' );
				if ( is_array( $data_json ) && ! empty( $data_json['files'] ) ) {
					$sums = array();
					foreach ( $data_json['files'] as $rel => $row ) {
						$md5          = is_array( $row['md5'] ?? null ) ? $row['md5'] : array( $row['md5'] ?? '' );
						$sums[ $rel ] = $md5;
					}
					set_transient( 'deft_sec_sums_' . md5( $slug . '@' . $ver ), $sums, 7 * DAY_IN_SECONDS );
					$kind = 'wporg';
				} else {
					set_transient( 'deft_sec_sums_' . md5( $slug . '@' . $ver ), 'none', 7 * DAY_IN_SECONDS );
				}
			}
		}
		$prefix = $plugin_rel . '/' . $slug . '/';
		if ( is_array( $sums ) ) {
			foreach ( $files as $rel => $path ) {
				$inner = substr( $rel, strlen( $prefix ) );
				if ( 'manifest' === $kind && in_array( $inner, array( 'data/manifest.json', 'data/site.json' ), true ) ) {
					continue;
				}
				if ( isset( $sums[ $inner ] ) ) {
					$want = (array) $sums[ $inner ];
					if ( ! in_array( 'manifest' === $kind ? sha1_file( $path ) : md5_file( $path ), $want, true ) ) {
						$modified[] = $slug . '/' . $inner;
					}
				} elseif ( deft_sec_is_code( basename( $path ) ) && ! deft_sec_allowed( $rel ) ) {
					// A PHP file the release never had: the classic way to hide a back door inside a real plugin.
					$modified[] = $slug . '/' . $inner . ' (not in the release)';
				}
			}
			$state['verified'][ $slug ] = $kind;
			continue;
		}
		// Premium and custom plugins: compare with the baseline for this version (taken the first time we see it).
		$key  = 'plugin:' . $slug . '@' . $ver;
		$diff = deft_sec_baseline_compare( $baseline, $key, $files );
		if ( $diff ) {
			$changed = array_merge( $changed, $diff );
		}
		$state['verified'][ $slug ] = 'baseline';
	}
	if ( $modified ) {
		$add( 'bad', 'plugin_modified', sprintf( '%d plugin files differ from the official release, for example %s. Reinstall the plugin from its source.', count( $modified ), implode( ', ', array_slice( $modified, 0, 5 ) ) ) );
	}
	return array( 'modified' => $modified, 'changed' => $changed, 'hidden' => array_keys( $hidden ), 'ghost' => $ghost, 'denied' => $denied );
}

/**
 * Baseline: a sha1 per file the first time we see a theme, a premium plugin version, must-use plugins or the web root,
 * then the files added or changed since. Changes only matter for code files (PHP, .htaccess, .user.ini).
 */
function deft_sec_baseline_compare( &$baseline, $key, $files ) {
	$now = array();
	foreach ( $files as $rel => $path ) {
		if ( deft_sec_is_code( basename( $path ) ) ) {
			$now[ $rel ] = sha1_file( $path );
		}
	}
	if ( ! isset( $baseline[ $key ] ) ) {
		$baseline[ $key ] = $now;
		return array();
	}
	$diff = array();
	foreach ( $now as $rel => $sha ) {
		if ( ! isset( $baseline[ $key ][ $rel ] ) ) {
			$diff[] = $rel . ' (new)';
		} elseif ( $baseline[ $key ][ $rel ] !== $sha ) {
			$diff[] = $rel;
		}
	}
	return $diff;
}

function deft_sec_baseline_load() {
	$raw = get_option( 'deft_sec_baseline', '' );
	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}
	$data = function_exists( 'gzuncompress' ) ? @gzuncompress( base64_decode( $raw ) ) : base64_decode( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	$data = is_string( $data ) ? json_decode( $data, true ) : null;
	return is_array( $data ) ? $data : array();
}

function deft_sec_baseline_save( $baseline ) {
	$json = wp_json_encode( $baseline );
	$raw  = function_exists( 'gzcompress' ) ? base64_encode( gzcompress( $json, 6 ) ) : base64_encode( $json ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	update_option( 'deft_sec_baseline', $raw, false );
	update_option( 'deft_sec_baseline_at', time(), false );
}

/** Our own .htaccess edits shouldn't read as a change: re-take the web root's part of the baseline. */
function deft_sec_baseline_refresh_root() {
	$baseline = deft_sec_baseline_load();
	if ( isset( $baseline['root'] ) ) {
		unset( $baseline['root'] );
		deft_sec_baseline_compare( $baseline, 'root', deft_sec_root_code_files() );
		deft_sec_baseline_save( $baseline );
	}
}

function deft_sec_root_code_files() {
	$out = array();
	foreach ( array( '.htaccess', 'index.php', 'wp-config.php', '.user.ini', 'wp-content/.htaccess', 'wp-content/uploads/.htaccess' ) as $rel ) {
		if ( is_file( ABSPATH . $rel ) ) {
			$out[ $rel ] = ABSPATH . $rel;
		}
	}
	return $out;
}

/** Hidden administrators, the usermeta keys malware leaves, usernames from the indicator list, application passwords. */
function deft_sec_check_users( $add ) {
	global $wpdb;
	$cap_key   = $wpdb->get_blog_prefix() . 'capabilities';
	$raw_ids   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s", $cap_key, '%"administrator"%' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$api_ids   = array_map( 'intval', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
	$hidden    = array_diff( $raw_ids, $api_ids );
	$out       = array( 'admins' => count( $raw_ids ), 'hidden' => array(), 'bad_meta' => 0, 'bad_logins' => array(), 'app_passwords' => 0 );
	if ( $hidden ) {
		$logins = $wpdb->get_col( 'SELECT user_login FROM ' . $wpdb->users . ' WHERE ID IN (' . implode( ',', array_map( 'intval', $hidden ) ) . ')' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$out['hidden'] = $logins;
		$add( 'bad', 'hidden_admin', sprintf( 'Administrators the Users screen doesn\'t show: %s. Something is filtering them out of the list.', implode( ', ', $logins ) ) );
	}
	$in  = implode( ',', array_fill( 0, count( DEFT_SEC_BAD_META ), '%s' ) );
	$bad = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN ($in)", DEFT_SEC_BAD_META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$out['bad_meta'] = $bad;
	if ( $bad ) {
		$add( 'bad', 'suspicious_user_meta', sprintf( '%d user records carry keys 2026 malware leaves behind (%s).', $bad, implode( ', ', DEFT_SEC_BAD_META ) ) );
	}
	// Posts written straight into the database: a blank edit date, or an author who doesn't exist (the October 2026
	// toolkit inserted batches of these on 24+ sites, sharing fake author IDs).
	$injected = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->users} u ON u.ID = p.post_author WHERE p.post_status = 'publish' AND p.post_type NOT IN ('revision', 'nav_menu_item', 'deft_lead') AND ( p.post_modified = '0000-00-00 00:00:00' OR ( p.post_author <> 0 AND u.ID IS NULL ) )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$out['injected_posts'] = $injected;
	if ( $injected ) {
		$add( 'bad', 'injected_posts', sprintf( '%d published posts or pages were written straight into the database (no real author, or a blank edit date). Injected spam looks like this.', $injected ) );
	}
	$logins = (array) $wpdb->get_col( "SELECT user_login FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$out['bad_logins'] = array_values( preg_grep( DEFT_SEC_BAD_LOGIN, $logins ) );
	if ( $out['bad_logins'] ) {
		$add( 'bad', 'ioc_username', sprintf( 'Usernames that match known malware: %s.', implode( ', ', $out['bad_logins'] ) ) );
	}
	$out['app_passwords'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value NOT IN ('', 'a:0:{}')", '_application_passwords' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $out['app_passwords'] ) {
		$add( 'warn', 'app_passwords', sprintf( '%d users have application passwords. Deft sites don\'t use them; revoke them on each user\'s profile.', $out['app_passwords'] ) );
	}
	// Two-factor: Deft Core's own (modules/security-login.php) or Wordfence Login Security's.
	if ( function_exists( 'deft_sec_login_status' ) ) {
		$login = deft_sec_login_status();
		if ( $login['without_2fa'] ) {
			$add( 'warn', 'admin_no_2fa', sprintf( 'Administrators without two-factor login: %s (Users > Profile > Two-factor login).', implode( ', ', $login['without_2fa'] ) ) );
		}
		$out['two_factor'] = $login['admins'] - count( $login['without_2fa'] );
		$out['must_reset'] = $login['must_reset'];
	}
	return $out;
}

/** The whole file check. Takes a few seconds; runs from WP-Cron, or when staff press Check now. */
function deft_sec_run_scan() {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	$started  = microtime( true );
	$deadline = $started + 100;
	$findings = array();
	$add      = function ( $level, $code, $text ) use ( &$findings ) {
		$findings[] = array( 'level' => $level, 'code' => $code, 'text' => $text );
	};
	$state    = array( 'pending' => array(), 'verified' => array() );
	$baseline = deft_sec_baseline_load();
	$first    = ! $baseline;

	$core = deft_sec_check_core( $state );
	if ( ! empty( $core['changed'] ) || ! empty( $core['extra'] ) ) {
		$parts = array();
		if ( $core['changed'] ) {
			$parts[] = sprintf( '%d changed (for example %s)', count( $core['changed'] ), implode( ', ', array_slice( $core['changed'], 0, 3 ) ) );
		}
		if ( $core['extra'] ) {
			$parts[] = sprintf( '%d extra PHP files (for example %s)', count( $core['extra'] ), implode( ', ', array_slice( $core['extra'], 0, 3 ) ) );
		}
		$add( 'bad', 'core_modified', 'WordPress\'s own files don\'t match the official release: ' . implode( '; ', $parts ) . '. Reinstall WordPress core.' );
	}
	$root  = deft_sec_check_root( $core['sums'] ?? array(), $add );
	$loose = deft_sec_check_mu_dropins( $add );
	$plug  = deft_sec_check_plugins( $state, $baseline, $add, $deadline );
	$users = deft_sec_check_users( $add );

	// The theme (and its parent), must-use plugins and the web root's config files against the baseline.
	$changed = $plug['changed'];
	$theme   = wp_get_theme();
	foreach ( array_unique( array( $theme->get_stylesheet(), $theme->get_template() ) ) as $slug ) {
		$t = wp_get_theme( $slug );
		if ( $t->exists() ) {
			$changed = array_merge( $changed, deft_sec_baseline_compare( $baseline, 'theme:' . $slug . '@' . $t->get( 'Version' ), deft_sec_walk( $t->get_stylesheet_directory(), 8000 ) ) );
		}
	}
	$changed = array_merge( $changed, deft_sec_baseline_compare( $baseline, 'mu', deft_sec_walk( WPMU_PLUGIN_DIR, 500 ) ) );
	$changed = array_merge( $changed, deft_sec_baseline_compare( $baseline, 'root', deft_sec_root_code_files() ) );
	foreach ( $root['google'] as $rel ) {
		$changed[] = $rel . ' (Search Console verification file: check who owns it)';
	}
	if ( ! $root['wp_block'] ) {
		$changed[] = '.htaccess (WordPress\'s own block is missing)';
	}
	foreach ( $loose as $rel ) {
		$changed[] = $rel . ' (loose in wp-content)';
	}
	// Uploads: PHP is already reported by Site watch (uploads_php); here, a hidden .htaccess or .user.ini somewhere inside.
	foreach ( deft_sec_walk( wp_upload_dir( null, false )['basedir'], 60000 ) as $rel => $path ) {
		$name = basename( $path );
		if ( ( '.user.ini' === $name || ( '.htaccess' === $name && dirname( $path ) !== rtrim( wp_upload_dir( null, false )['basedir'], '/' ) ) ) && ! deft_sec_allowed( $rel ) ) {
			$changed[] = $rel . ' (config file inside uploads)';
		}
	}
	if ( $changed ) {
		$bad = array_filter( $changed, function ( $rel ) {
			$path = ABSPATH . preg_replace( '/ \(.*\)$/', '', $rel );
			return is_file( $path ) && deft_sec_code_signs( $path );
		} );
		$add( $bad ? 'bad' : 'warn', 'files_changed', sprintf( '%d code files changed or appeared since the baseline, for example %s.%s If these came from an update you made, press Accept changes under Settings > Deft > Site security.', count( $changed ), implode( ', ', array_slice( $changed, 0, 5 ) ), $bad ? sprintf( ' %d of them carry malware-like code.', count( $bad ) ) : '' ) );
	}
	if ( $state['pending'] ) {
		$add( 'info', 'integrity_pending', 'Part of the file check didn\'t finish: ' . implode( '; ', array_unique( $state['pending'] ) ) . '. It runs again tomorrow.' );
	}
	$off = array_keys( array_filter( deft_sec_applied(), function ( $v ) {
		return false === $v;
	} ) );
	$off = array_diff( $off, array( 'hsts', 'auto_updates' ) ); // Optional ones.
	if ( $off ) {
		$add( 'info', 'security_off', sprintf( 'Protections off on this site: %s (Settings > Deft > Site security).', implode( ', ', $off ) ) );
	}

	deft_sec_baseline_save( $baseline );
	$previous = get_option( 'deft_sec_scan', array() );
	$result   = array(
		'at'       => time(),
		'ms'       => (int) round( 1000 * ( microtime( true ) - $started ) ),
		'first'    => $first,
		'findings' => $findings,
		'core'     => array( 'files' => $state['core_files'] ?? 0, 'changed' => array_slice( $core['changed'] ?? array(), 0, 20 ), 'extra' => array_slice( $core['extra'] ?? array(), 0, 20 ) ),
		'root'     => $root,
		'plugins'  => array( 'verified' => $state['verified'], 'modified' => array_slice( $plug['modified'], 0, 30 ), 'hidden' => $plug['hidden'], 'ghost' => $plug['ghost'], 'denied' => $plug['denied'] ),
		'changed'  => array_slice( $changed, 0, 30 ),
		'users'    => $users,
	);
	update_option( 'deft_sec_scan', $result, false );
	deft_sec_alert( $previous, $result );
	return $result;
}

/** Email new bad findings (ones the last check didn't have) to the alert address. Central gets them every morning too. */
function deft_sec_alert( $previous, $result ) {
	$before = array();
	foreach ( (array) ( $previous['findings'] ?? array() ) as $f ) {
		$before[ $f['code'] . '|' . $f['text'] ] = true;
	}
	$new = array_filter( $result['findings'], function ( $f ) use ( $before ) {
		return 'bad' === $f['level'] && ! isset( $before[ $f['code'] . '|' . $f['text'] ] );
	} );
	$to = deft_sec_alert_recipients();
	if ( ! $new || ! $to ) {
		return;
	}
	$host  = wp_parse_url( home_url(), PHP_URL_HOST );
	$lines = array( sprintf( 'Deft Core\'s file check found something new on %s:', $host ), '' );
	foreach ( $new as $f ) {
		$lines[] = '- ' . ( DEFT_WATCH_TITLES[ $f['code'] ] ?? $f['code'] ) . ': ' . $f['text'];
	}
	$lines[] = '';
	$lines[] = 'Nothing was changed or deleted. Settings > Deft > Site security: ' . admin_url( 'options-general.php?page=deft-core#deft-security' );
	wp_mail( $to, sprintf( '[%s] Site security: %d new problem%s', $host, count( $new ), 1 === count( $new ) ? '' : 's' ), implode( "\n", $lines ) );
}

/** Findings from the last file check, for Site watch's health report. Starts a check if the last one is over a day old. */
function deft_sec_report() {
	$scan = get_option( 'deft_sec_scan', array() );
	if ( deft_sec_on( 'scan' ) && ( empty( $scan['at'] ) || $scan['at'] < time() - 26 * HOUR_IN_SECONDS ) && ! wp_next_scheduled( 'deft_sec_scan_now' ) ) {
		wp_schedule_single_event( time() + 30, 'deft_sec_scan_now' );
	}
	$findings = is_array( $scan['findings'] ?? null ) ? $scan['findings'] : array();
	if ( empty( $scan['at'] ) ) {
		$findings[] = array( 'level' => 'info', 'code' => 'integrity_pending', 'text' => 'The first file check hasn\'t run yet. It starts within a few minutes.' );
	}
	return array(
		'findings' => $findings,
		'security' => array(
			'applied'      => deft_sec_applied(),
			'baseline_at'  => ( $b = (int) get_option( 'deft_sec_baseline_at' ) ) ? gmdate( 'c', $b ) : null,
			'last_scan_at' => empty( $scan['at'] ) ? null : gmdate( 'c', $scan['at'] ),
			'scan_ms'      => (int) ( $scan['ms'] ?? 0 ),
			'lockouts_24h' => deft_sec_lockouts_24h(),
			'root_error'   => get_option( 'deft_sec_root_error' ) ? true : false,
			'verified'     => $scan['plugins']['verified'] ?? array(),
		),
	);
}
add_action( 'deft_sec_scan_now', 'deft_sec_run_scan' );

/* ---------- Settings ---------- */

add_action( 'admin_post_deft_sec_scan', 'deft_sec_admin_scan' );
function deft_sec_admin_scan() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'deft-core' ), 403 );
	}
	check_admin_referer( 'deft_sec_scan' );
	if ( ! empty( $_POST['accept'] ) ) {
		delete_option( 'deft_sec_baseline' ); // The next check takes a fresh one from the files as they are now.
	}
	deft_sec_run_scan();
	wp_safe_redirect( admin_url( 'options-general.php?page=deft-core#deft-security' ) );
	exit;
}

add_action( 'deft_core_settings_sections', 'deft_sec_settings_fields', 8 );
function deft_sec_settings_fields( $s ) {
	$labels = array(
		'file_edit'     => __( 'Turn off the theme and plugin file editor', 'deft-core' ),
		'uploads_php'   => __( 'Never run PHP from uploads', 'deft-core' ),
		'root_files'    => __( 'Refuse archives, backups and config files from the web, and folder listings', 'deft-core' ),
		'xmlrpc'        => __( 'Turn off XML-RPC (left on while Jetpack is active)', 'deft-core' ),
		'app_passwords' => __( 'Turn off application passwords', 'deft-core' ),
		'login_limit'   => __( 'Lock out an address after 5 wrong logins (Wordfence does this when it is active)', 'deft-core' ),
		'enumeration'   => __( 'Don\'t list usernames to visitors (author pages, users API, users sitemap)', 'deft-core' ),
		'headers'       => __( 'Send security headers (nosniff, referrer policy)', 'deft-core' ),
		'hsts'          => __( 'HTTPS only, remembered by browsers for a year (HSTS)', 'deft-core' ),
		'auto_updates'  => __( 'Update plugins by themselves (not Elementor or WooCommerce)', 'deft-core' ),
	);
	$applied = deft_sec_applied();
	$scan    = get_option( 'deft_sec_scan', array() );
	?>
	<h2 id="deft-security"><?php esc_html_e( 'Site security', 'deft-core' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><?php esc_html_e( 'Protections', 'deft-core' ); ?></th>
			<td><fieldset>
			<?php foreach ( DEFT_SEC_SWITCHES as $key ) : ?>
				<label style="display:block;margin:2px 0"><input type="checkbox" name="deft_core[sec_<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $s[ 'sec_' . $key ] ); ?>> <?php echo esc_html( $labels[ $key ] ); ?>
				<?php if ( $s[ 'sec_' . $key ] && false === $applied[ $key ] ) : ?><em style="color:#b32d2e"> <?php esc_html_e( '(on, but not working here)', 'deft-core' ); ?></em><?php endif; ?></label>
			<?php endforeach; ?>
			</fieldset>
			<p class="description"><?php esc_html_e( 'Each one can be switched off here. Turning Deft Core off also takes them all out.', 'deft-core' ); ?></p></td></tr>
		<?php do_action( 'deft_sec_settings_rows', $s ); ?>
		<tr><th scope="row"><?php esc_html_e( 'File check', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[sec_scan]" value="1" <?php checked( $s['sec_scan'] ); ?>> <?php esc_html_e( 'Check WordPress, plugins and the theme against their official files every night', 'deft-core' ); ?></label>
			<p class="description"><?php
			if ( empty( $scan['at'] ) ) {
				esc_html_e( 'Not checked yet.', 'deft-core' );
			} else {
				$bad = count( array_filter( (array) $scan['findings'], function ( $f ) {
					return 'info' !== $f['level'];
				} ) );
				/* translators: 1: time ago, 2: number of problems */
				echo esc_html( sprintf( __( 'Last checked %1$s ago: %2$s.', 'deft-core' ), human_time_diff( $scan['at'] ), $bad ? sprintf( _n( '%d problem', '%d problems', $bad, 'deft-core' ), $bad ) : __( 'nothing found', 'deft-core' ) ) );
			}
			?></p>
			<?php foreach ( (array) ( $scan['findings'] ?? array() ) as $f ) : ?>
				<?php if ( 'info' !== $f['level'] ) : ?><p style="margin:4px 0;color:<?php echo 'bad' === $f['level'] ? '#b32d2e' : '#996800'; ?>"><?php echo esc_html( $f['text'] ); ?></p><?php endif; ?>
			<?php endforeach; ?></td></tr>
		<tr><th scope="row"><label for="deft-sec-email"><?php esc_html_e( 'Alert email', 'deft-core' ); ?></label></th>
			<td><input id="deft-sec-email" class="regular-text" name="deft_core[sec_alert_email]" value="<?php echo esc_attr( $s['sec_alert_email'] ); ?>" placeholder="<?php echo esc_attr( defined( 'DEFT_CORE_SECURITY_EMAIL' ) ? DEFT_CORE_SECURITY_EMAIL : '' ); ?>">
			<p class="description"><?php esc_html_e( 'Who hears straight away when the file check finds something new. Use Deft\'s address, not the client\'s. Deft Central sees it every morning either way.', 'deft-core' ); ?></p></td></tr>
		<tr><th scope="row"><label for="deft-sec-allow"><?php esc_html_e( 'Expected files', 'deft-core' ); ?></label></th>
			<td><textarea id="deft-sec-allow" class="large-text code" rows="3" name="deft_core[sec_allow]" placeholder="wp-content/mu-plugins/host-tools.php"><?php echo esc_textarea( $s['sec_allow'] ); ?></textarea>
			<p class="description"><?php esc_html_e( 'One path per line from the web root (a folder ends in /). Only add a file after checking what it is.', 'deft-core' ); ?></p></td></tr>
	</table>
	<?php
}

// The buttons post to admin-post.php, so they sit after the settings form rather than inside it.
add_action( 'deft_core_settings_after', 'deft_sec_settings_buttons' );
function deft_sec_settings_buttons() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
		<input type="hidden" name="action" value="deft_sec_scan">
		<?php wp_nonce_field( 'deft_sec_scan' ); ?>
		<?php submit_button( __( 'Check files now', 'deft-core' ), 'secondary', 'scan', false ); ?>
		<?php submit_button( __( 'Accept changes', 'deft-core' ), 'secondary', 'accept', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Only after an update you made yourself, on a site you know is clean. Take a new baseline from the files as they are now?', 'deft-core' ) ) . "')" ) ); ?>
	</form>
	<?php
}
