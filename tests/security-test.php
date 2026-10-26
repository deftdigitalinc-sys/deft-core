<?php
/**
 * Tests for modules/security.php without WordPress: php tests/security-test.php
 * A few WordPress functions are stubbed below; the web root is a temporary folder built per test.
 */

$fails = 0;
function check( $label, $ok ) {
	global $fails;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	$fails += $ok ? 0 : 1;
}

$root = sys_get_temp_dir() . '/deft-sec-' . getmypid() . '/';
mkdir( $root . 'wp-content/mu-plugins', 0777, true );
mkdir( $root . 'wp-content/plugins', 0777, true );
mkdir( $root . 'wp-content/uploads', 0777, true );
define( 'ABSPATH', $root );
define( 'WP_CONTENT_DIR', $root . 'wp-content' );
define( 'WPMU_PLUGIN_DIR', $root . 'wp-content/mu-plugins' );
define( 'WP_PLUGIN_DIR', $root . 'wp-content/plugins' );
define( 'DEFT_CORE_VERSION', '0.10.0' );
define( 'DEFT_CORE_DIR', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
const DEFT_WATCH_SPAM = '/\b(casino|poker|gacor|viagra)\b/i';

// ---- Stubs ----
$GLOBALS['opts']     = array();
$GLOBALS['settings'] = array();
class WP_Error {
	public $code;
	public function __construct( $code = '', $msg = '' ) {
		$this->code = $code;
	}
}
function is_wp_error( $x ) {
	return $x instanceof WP_Error;
}
function add_filter() {}
function add_action() {}
function apply_filters( $hook, $value ) {
	return $value;
}
function get_option( $k, $d = false ) {
	return $GLOBALS['opts'][ $k ] ?? $d;
}
function update_option( $k, $v ) {
	$GLOBALS['opts'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['opts'][ $k ] );
}
function get_transient( $k ) {
	return $GLOBALS['opts'][ '_t_' . $k ] ?? false;
}
function set_transient( $k, $v ) {
	$GLOBALS['opts'][ '_t_' . $k ] = $v;
}
function deft_core_setting( $k ) {
	$all = deft_sec_defaults( array() );
	return array_key_exists( $k, $GLOBALS['settings'] ) ? $GLOBALS['settings'][ $k ] : $all[ $k ];
}
function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_email( $s ) {
	return filter_var( trim( $s ), FILTER_VALIDATE_EMAIL ) ? trim( $s ) : '';
}
function is_email( $s ) {
	return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL );
}
function wp_unslash( $s ) {
	return $s;
}
function size_format( $b ) {
	return $b . ' B';
}
function __( $s ) {
	return $s;
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function wp_upload_dir() {
	return array( 'basedir' => WP_CONTENT_DIR . '/uploads' );
}

require dirname( __DIR__ ) . '/modules/security.php';
require dirname( __DIR__ ) . '/modules/security-login.php';

function put( $rel, $body ) {
	$path = ABSPATH . $rel;
	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0777, true );
	}
	file_put_contents( $path, $body );
}
function codes( $findings ) {
	return array_column( $findings, 'code' );
}

// ---- Code signs ----
put( 'evil.php', '<?php @eval(base64_decode($_POST["x"])); ' );
put( 'fine.php', '<?php echo "hello"; wp_enqueue_script("a");' );
put( 'pclzip.php', '<?php class PclZip {}' );
check( 'eval(base64_decode()) looks like malware', deft_sec_code_signs( ABSPATH . 'evil.php' ) );
check( 'PclZip looks like malware', deft_sec_code_signs( ABSPATH . 'pclzip.php' ) );
check( 'plain PHP does not', ! deft_sec_code_signs( ABSPATH . 'fine.php' ) );
unlink( ABSPATH . 'evil.php' );
unlink( ABSPATH . 'fine.php' );
unlink( ABSPATH . 'pclzip.php' );

// ---- Web root ----
$core_sums = array( 'index.php' => 'x', 'wp-login.php' => 'x' );
put( 'index.php', '<?php // core' );
put( 'wp-login.php', '<?php // core' );
put( 'wp-config.php', '<?php // config' );
put( 'typography.php', '<?php // attacker' );
put( '1index.php', '<?php // attacker' );
put( 'default.php', str_repeat( 'x', 16369 ) );
put( 'sitemap16.xml', '<urlset><url><loc>https://x.com/?p=123</loc></url></urlset>' );
put( 'sitemap.xml', '<urlset></urlset>' );
put( 'backup.zip', 'PK' );
put( '.htaccess.bk', '#' );
put( 'casino-online/header.php', '<?php // PclZip' );
put( 'old-site/readme.txt', 'no php here' );
put( 'update-log.txt', str_repeat( 'QUJD', 80 ) );
put( 'robots.txt', "User-agent: *\nSitemap: https://x.com/?feed=xmlsitemap02\n" );
put( 'google1234abcd5678ef90.html', 'google-site-verification' );
put( '.user.ini', "auto_prepend_file = /home/u1/public_html/wp-content/c1b12371.php\n" );

$findings = array();
$add      = function ( $level, $code, $text ) use ( &$findings ) {
	$findings[] = array( 'level' => $level, 'code' => $code, 'text' => $text );
};
$r = deft_sec_check_root( $core_sums, $add );
check( 'root: typography.php and 1index.php flagged', in_array( 'typography.php', $r['php'], true ) && in_array( '1index.php', $r['php'], true ) );
check( 'root: core files and wp-config.php not flagged', ! array_intersect( array( 'index.php', 'wp-login.php', 'wp-config.php' ), $r['php'] ) );
check( 'root: Hostinger default.php (16369 B) not flagged', ! in_array( 'default.php', $r['php'], true ) );
check( 'root: attacker folder with PHP flagged', (bool) preg_grep( '/^casino-online\//', $r['php'] ) );
check( 'root: folder without PHP not flagged', ! preg_grep( '/^old-site\//', $r['php'] ) );
check( 'root: spam sitemap flagged, plain sitemap.xml not', (bool) preg_grep( '/^sitemap16\.xml/', $r['sitemaps'] ) && ! preg_grep( '/^sitemap\.xml/', $r['sitemaps'] ) );
check( 'root: base64 update-log.txt flagged', in_array( 'update-log.txt (encoded upload log)', $r['php'], true ) );
check( 'root: robots.txt listing spam feeds flagged', in_array( 'robots.txt (lists spam sitemaps)', $r['sitemaps'], true ) );
check( 'root: unknown Google verification file listed', array( 'google1234abcd5678ef90.html' ) === $r['google'] );
check( 'root: backup.zip and .htaccess.bk flagged', 2 === count( $r['archives'] ) );
check( 'root: .user.ini auto_prepend_file flagged', in_array( 'user_ini_prepend', codes( $findings ), true ) );
check( 'root: all four findings', ! array_diff( array( 'root_php', 'spam_sitemap', 'root_archive', 'user_ini_prepend' ), codes( $findings ) ) );

// Wordfence's own firewall loader is fine.
put( '.user.ini', "auto_prepend_file = '" . ABSPATH . "wordfence-waf.php'\n" );
put( 'wordfence-waf.php', "<?php // Before removing this file, please verify the PHP ini setting `auto_prepend_file` does not point to this.\n// This file was the current value of auto_prepend_file during the Wordfence WAF installation\n" );
$findings = array();
$r        = deft_sec_check_root( $core_sums, $add );
check( 'root: Wordfence WAF prepend accepted', ! in_array( 'user_ini_prepend', codes( $findings ), true ) );
check( 'root: wordfence-waf.php not flagged as unknown PHP', ! in_array( 'wordfence-waf.php', $r['php'], true ) );

// Expected files.
$GLOBALS['settings']['sec_allow'] = "typography.php\ncasino-online/";
$findings                         = array();
$r                                = deft_sec_check_root( $core_sums, $add );
check( 'root: expected files are skipped', ! in_array( 'typography.php', $r['php'], true ) && ! preg_grep( '/^casino-online\//', $r['php'] ) );
$GLOBALS['settings']['sec_allow'] = '';

// ---- Must-use plugins ----
put( 'wp-content/mu-plugins/deft-central.php', "<?php\ndefine( 'DEFT_CORE_CENTRAL_URL', 'x' );\n" );
put( 'wp-content/mu-plugins/wp-abc123-loader.php', '<?php eval(base64_decode("aGk="));' );
function get_dropins() {
	return array();
}
$findings = array();
deft_sec_check_mu_dropins( $add );
check( 'mu: unknown loader flagged with malware note', in_array( 'unknown_mu_plugin', codes( $findings ), true ) && false !== strpos( $findings[0]['text'], 'wp-abc123-loader.php (malware-like code)' ) );
check( 'mu: our connection file accepted', false === strpos( $findings[0]['text'], 'deft-central.php' ) );
put( 'wp-content/mu-plugins/deft-central.php', "<?php\ndefine( 'DEFT_CORE_CENTRAL_URL', 'x' ); eval(\$_POST['a']);\n" );
$findings = array();
deft_sec_check_mu_dropins( $add );
check( 'mu: our connection file with extra code flagged', false !== strpos( $findings[0]['text'], 'deft-central.php (changed)' ) );

// ---- Baseline ----
$baseline = array();
$files    = array( 'a.php' => ABSPATH . 'index.php', 'b.css' => ABSPATH . 'sitemap.xml' );
check( 'baseline: first look takes the baseline and reports nothing', array() === deft_sec_baseline_compare( $baseline, 'theme:x@1', $files ) && isset( $baseline['theme:x@1']['a.php'] ) );
check( 'baseline: only code files are kept', ! isset( $baseline['theme:x@1']['b.css'] ) );
put( 'index.php', '<?php // changed' );
put( 'new.php', '<?php // new' );
$files['new.php'] = ABSPATH . 'new.php';
$diff             = deft_sec_baseline_compare( $baseline, 'theme:x@1', $files );
check( 'baseline: changed and new files reported', in_array( 'a.php', $diff, true ) && in_array( 'new.php (new)', $diff, true ) );
deft_sec_baseline_save( $baseline );
check( 'baseline: saves and loads back', deft_sec_baseline_load() === $baseline );

// ---- Logins ----
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
for ( $i = 0; $i < 4; $i++ ) {
	deft_sec_login_failed();
}
check( 'login: 4 wrong tries do not lock', ! is_wp_error( deft_sec_login_gate( null ) ) );
deft_sec_login_failed();
check( 'login: the 5th locks the address', is_wp_error( deft_sec_login_gate( null ) ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
check( 'login: another address is not locked', ! is_wp_error( deft_sec_login_gate( null ) ) );
check( 'login: lockout counted', 1 === deft_sec_lockouts_24h() );

// ---- Settings ----
$out = deft_sec_sanitize( array(), array( 'sec_file_edit' => '1', 'sec_alert_email' => 'ops@example.com, nope', 'sec_allow' => "wp-content/mu-plugins/x.php\n../../etc/passwd\n/old-site/\n" ) );
check( 'sanitize: switches not sent are off', 0 === $out['sec_uploads_php'] && 1 === $out['sec_file_edit'] );
check( 'sanitize: bad emails dropped', 'ops@example.com' === $out['sec_alert_email'] );
check( 'sanitize: paths with .. dropped, slashes trimmed', "wp-content/mu-plugins/x.php\nold-site" === $out['sec_allow'] );

// ---- .htaccess rules ----
$rules = implode( "\n", deft_sec_root_rules() );
$pattern = '#' . substr( $rules, strpos( $rules, '"^' ) + 1, strpos( $rules, '$"' ) - strpos( $rules, '"^' ) ) . '#';
foreach ( array( 'backup.zip', 'db.sql', '.env', 'wp-config.php', '.user.ini', 'site.wpress', '.htaccess.bk' ) as $name ) {
	check( "rules: $name refused", 1 === preg_match( $pattern, $name ) );
}
foreach ( array( 'index.php', 'wp-login.php', 'style.css', 'logo.png' ) as $name ) {
	check( "rules: $name allowed", 0 === preg_match( $pattern, $name ) );
}

// ---- Two-factor (RFC 6238 test vector: secret "12345678901234567890", T=59 -> 94287082, 6 digits 287082) ----
$b32 = deft_sec_b32_encode( '12345678901234567890' );
check( 'totp: base32 encodes the RFC secret', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ' === $b32 );
check( 'totp: base32 round-trips', '12345678901234567890' === deft_sec_b32_decode( $b32 ) );
check( 'totp: RFC 6238 code at T=59', '287082' === deft_sec_totp( $b32, 1 ) );
check( 'totp: RFC 6238 code at T=1111111109', '081804' === deft_sec_totp( $b32, (int) floor( 1111111109 / 30 ) ) );
check( 'totp: a code from one step ago still matches', false !== deft_sec_totp_match( $b32, '287082', 59 + 30 ) );
check( 'totp: a code from two steps ago does not', false === deft_sec_totp_match( $b32, '287082', 59 + 60 ) );
check( 'totp: spaces in the code are fine', 1 === deft_sec_totp_match( $b32, '287 082', 59 ) );
check( 'totp: wrong code refused', false === deft_sec_totp_match( $b32, '000000', 59 ) );

exec( 'rm -rf ' . escapeshellarg( $root ) );
echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit( $fails ? 1 : 0 );
