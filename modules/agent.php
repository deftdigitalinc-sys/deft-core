<?php
/**
 * Agent access (off by default): an MCP server at /wp-json/deft/v1/mcp so AI agents (Claude Code, Claude Desktop,
 * Codex, Gemini CLI, Antigravity) can work on this site with our own tools instead of a paid hosted service.
 *
 * How it is kept safe:
 * - Nothing is registered until "Let AI agents work on this site" is ticked in Settings > Deft. The constant
 *   DEFT_AGENT_DISABLED in wp-config.php switches it off for good.
 * - Each agent signs in with its own token (Authorization: Bearer …). Only a hash is stored; tokens are shown once,
 *   can be revoked, and act as the admin who made them, so WordPress's normal permission checks apply to every action.
 * - Three levels: read only (default), changes (posts, pages, media, options, Elementor), and theme/plugin files.
 *   Files can only be written under themes/ and plugins/ (never Deft Core itself), PHP is syntax-checked first, and
 *   every overwritten file or Elementor page is backed up.
 * - Permanent deletes, deleting plugins and deleting users need confirm: true, which the agent is told to ask for.
 * - No PHP execution, no SQL, no secrets: options that look like passwords, keys or tokens are never returned.
 * - Every call is written to the agent log (Settings > Deft), kept 90 days. Calls are rate limited per token.
 *
 * Protocol: MCP Streamable HTTP, stateless JSON responses (no SSE stream). Setup: docs/deft-core-agent.md in Deft Central.
 */

defined( 'ABSPATH' ) || exit;

const DEFT_AGENT_DB_VERSION = '1';
const DEFT_AGENT_PROTOCOLS  = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );
const DEFT_AGENT_MAX_OUTPUT = 300000;

/* ---------- Settings ---------- */

add_filter( 'deft_core_defaults', 'deft_agent_defaults' );
function deft_agent_defaults( $defaults ) {
	return $defaults + array(
		'agent_enabled' => 0,
		'agent_write'   => 0,
		'agent_files'   => 0,
	);
}

add_filter( 'deft_core_sanitize', 'deft_agent_sanitize', 10, 2 );
function deft_agent_sanitize( $out, $input ) {
	$out['agent_enabled'] = empty( $input['agent_enabled'] ) ? 0 : 1;
	$out['agent_write']   = empty( $input['agent_write'] ) ? 0 : 1;
	$out['agent_files']   = empty( $input['agent_files'] ) ? 0 : 1;
	if ( $out['agent_files'] && ! $out['agent_write'] ) {
		$out['agent_write'] = 1; // Editing files is a kind of change.
	}
	if ( $out['agent_enabled'] ) {
		deft_agent_install();
	}
	return $out;
}

/** Whether the MCP endpoint exists at all. */
function deft_agent_enabled() {
	return ! ( defined( 'DEFT_AGENT_DISABLED' ) && DEFT_AGENT_DISABLED ) && (bool) deft_core_setting( 'agent_enabled' );
}

/** read, write or files: what this site lets agents do. */
function deft_agent_allows( $level ) {
	if ( 'read' === $level ) {
		return true;
	}
	if ( 'write' === $level ) {
		return (bool) deft_core_setting( 'agent_write' );
	}
	return (bool) deft_core_setting( 'agent_write' ) && (bool) deft_core_setting( 'agent_files' )
		&& ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
}

add_action( 'deft_core_settings_sections', 'deft_agent_settings_fields' );
function deft_agent_settings_fields( $s ) {
	?>
	<h2 id="deft-agent"><?php esc_html_e( 'AI agent access', 'deft-core' ); ?></h2>
	<p><?php esc_html_e( 'Lets Claude, Codex, Gemini or Antigravity work on this site through an MCP server, with a token per agent. Every action is logged. Leave it off on sites where nobody needs it.', 'deft-core' ); ?></p>
	<?php if ( defined( 'DEFT_AGENT_DISABLED' ) && DEFT_AGENT_DISABLED ) : ?>
		<p><strong><?php esc_html_e( 'Switched off in wp-config.php (DEFT_AGENT_DISABLED).', 'deft-core' ); ?></strong></p>
	<?php endif; ?>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><?php esc_html_e( 'Agent access', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[agent_enabled]" value="1" <?php checked( $s['agent_enabled'] ); ?>> <?php esc_html_e( 'Let AI agents work on this site (read only unless the boxes below are ticked)', 'deft-core' ); ?></label></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Changes', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[agent_write]" value="1" <?php checked( $s['agent_write'] ); ?>> <?php esc_html_e( 'Allow changes: posts, pages, media, menus, settings, plugins, Elementor pages', 'deft-core' ); ?></label>
			<p class="description"><?php esc_html_e( 'New posts and pages are saved as drafts unless the agent is told to publish. Deleting for good always needs a confirmation.', 'deft-core' ); ?></p></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Files', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[agent_files]" value="1" <?php checked( $s['agent_files'] ); ?>> <?php esc_html_e( 'Allow editing theme and plugin files', 'deft-core' ); ?></label>
			<p class="description">
			<?php
			echo ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS )
				? esc_html__( 'Blocked on this site by DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS in wp-config.php.', 'deft-core' )
				: esc_html__( 'PHP is syntax-checked before saving and every overwritten file is backed up for 30 days. Deft Core\'s own files can\'t be changed.', 'deft-core' );
			?>
			</p></td></tr>
	</table>
	<?php
}

add_action( 'deft_core_settings_after', 'deft_agent_settings_tokens' );
function deft_agent_settings_tokens() {
	if ( ! deft_agent_enabled() ) {
		return;
	}
	$tokens = deft_agent_tokens();
	$new    = get_transient( 'deft_agent_new_token_' . get_current_user_id() );
	if ( false !== $new ) {
		delete_transient( 'deft_agent_new_token_' . get_current_user_id() );
	}
	?>
	<hr>
	<h2 id="deft-agent-tokens"><?php esc_html_e( 'Agent tokens', 'deft-core' ); ?></h2>
	<p>
		<?php esc_html_e( 'MCP server address:', 'deft-core' ); ?>
		<code><?php echo esc_html( deft_agent_endpoint() ); ?></code>
	</p>
	<?php if ( is_array( $new ) ) : ?>
		<div class="notice notice-success inline"><p><strong><?php esc_html_e( 'Copy this token now. It will not be shown again.', 'deft-core' ); ?></strong></p>
			<p><input class="large-text code" readonly value="<?php echo esc_attr( $new['token'] ); ?>" onclick="this.select()"></p></div>
		<details open style="margin:1em 0">
			<summary><?php esc_html_e( 'Setup for each agent', 'deft-core' ); ?></summary>
			<?php foreach ( deft_agent_client_configs( $new['token'] ) as $client => $config ) : ?>
				<p><strong><?php echo esc_html( $client ); ?></strong></p>
				<textarea class="large-text code" rows="<?php echo esc_attr( min( 9, substr_count( $config, "\n" ) + 2 ) ); ?>" readonly onclick="this.select()"><?php echo esc_textarea( $config ); ?></textarea>
			<?php endforeach; ?>
		</details>
	<?php endif; ?>
	<?php if ( $tokens ) : ?>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th><?php esc_html_e( 'Name', 'deft-core' ); ?></th><th><?php esc_html_e( 'Acts as', 'deft-core' ); ?></th><th><?php esc_html_e( 'Created', 'deft-core' ); ?></th><th><?php esc_html_e( 'Last used', 'deft-core' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $tokens as $id => $token ) : ?>
				<?php $user = get_userdata( $token['user'] ); ?>
				<tr>
					<td><?php echo esc_html( $token['label'] ); ?> <code><?php echo esc_html( $token['hint'] ); ?>…</code></td>
					<td><?php echo esc_html( $user ? $user->user_login : __( '(deleted user)', 'deft-core' ) ); ?></td>
					<td><?php echo esc_html( wp_date( 'M j, Y', $token['created'] ) ); ?></td>
					<td><?php echo $token['used'] ? esc_html( wp_date( 'M j, Y g:i a', $token['used'] ) ) : esc_html__( 'Never', 'deft-core' ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="deft_agent_revoke">
							<input type="hidden" name="token" value="<?php echo esc_attr( $id ); ?>">
							<?php wp_nonce_field( 'deft_agent_revoke' ); ?>
							<?php submit_button( __( 'Revoke', 'deft-core' ), 'delete small', 'submit', false ); ?>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em">
		<input type="hidden" name="action" value="deft_agent_create">
		<?php wp_nonce_field( 'deft_agent_create' ); ?>
		<label for="deft-agent-label"><?php esc_html_e( 'New token for', 'deft-core' ); ?></label>
		<input id="deft-agent-label" name="label" class="regular-text" placeholder="<?php esc_attr_e( 'Daniel\'s Claude Code', 'deft-core' ); ?>" required maxlength="60">
		<?php submit_button( __( 'Create token', 'deft-core' ), 'secondary', 'submit', false ); ?>
		<p class="description"><?php esc_html_e( 'The token acts as you, with your permissions. One token per agent and computer, so each can be revoked on its own.', 'deft-core' ); ?></p>
	</form>
	<h3><?php esc_html_e( 'Recent agent activity', 'deft-core' ); ?></h3>
	<?php $rows = deft_agent_log_recent( 25 ); ?>
	<?php if ( ! $rows ) : ?>
		<p><?php esc_html_e( 'Nothing yet.', 'deft-core' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:1100px">
			<thead><tr><th><?php esc_html_e( 'When', 'deft-core' ); ?></th><th><?php esc_html_e( 'Token', 'deft-core' ); ?></th><th><?php esc_html_e( 'Action', 'deft-core' ); ?></th><th><?php esc_html_e( 'On', 'deft-core' ); ?></th><th><?php esc_html_e( 'Result', 'deft-core' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( wp_date( 'M j, g:i:s a', strtotime( $row['created'] . ' UTC' ) ) ); ?></td>
					<td><?php echo esc_html( $tokens[ $row['token'] ]['label'] ?? $row['token'] ); ?></td>
					<td><code><?php echo esc_html( $row['tool'] ); ?></code></td>
					<td><?php echo esc_html( $row['target'] ); ?></td>
					<td><?php echo $row['ok'] ? esc_html__( 'Done', 'deft-core' ) : esc_html( wp_trim_words( $row['detail'], 14 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}

/** Ready-to-paste setup for each agent we use. */
function deft_agent_client_configs( $token ) {
	$url  = deft_agent_endpoint();
	$name = 'deft-' . sanitize_key( str_replace( '.', '-', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	$json = function ( $data ) {
		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	};
	return array(
		'Claude Code (terminal)'                             => sprintf( 'claude mcp add --transport http --scope user %s %s --header "Authorization: Bearer %s"', $name, $url, $token ),
		'Codex (~/.codex/config.toml)'                       => sprintf( "[mcp_servers.%s]\nurl = \"%s\"\nhttp_headers = { \"Authorization\" = \"Bearer %s\" }", str_replace( '-', '_', $name ), $url, $token ),
		'Gemini CLI (~/.gemini/settings.json, mcpServers)'   => $json( array( 'mcpServers' => array( $name => array( 'httpUrl' => $url, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) ) ) ),
		'Antigravity (Manage MCP servers > View raw config)' => $json( array( 'mcpServers' => array( $name => array( 'serverUrl' => $url, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) ) ) ),
		'Claude Desktop (claude_desktop_config.json)'        => $json( array( 'mcpServers' => array( $name => array( 'command' => 'npx', 'args' => array( '-y', 'mcp-remote', $url, '--header', 'Authorization:${DEFT_AUTH}' ), 'env' => array( 'DEFT_AUTH' => 'Bearer ' . $token ) ) ) ) ),
	);
}

function deft_agent_endpoint() {
	return rest_url( 'deft/v1/mcp' );
}

/* ---------- Tokens ---------- */

/** Saved tokens: id => label, hash (sha256 of the token), hint, user, created, used. Never autoloaded. */
function deft_agent_tokens() {
	$tokens = get_option( 'deft_agent_tokens', array() );
	return is_array( $tokens ) ? $tokens : array();
}

add_action( 'admin_post_deft_agent_create', 'deft_agent_create_token' );
function deft_agent_create_token() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'deft-core' ) );
	}
	check_admin_referer( 'deft_agent_create' );
	$label  = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
	$token  = 'dft_' . bin2hex( random_bytes( 24 ) );
	$id     = bin2hex( random_bytes( 4 ) );
	$tokens = deft_agent_tokens();

	$tokens[ $id ] = array(
		'label'   => '' === $label ? __( 'Agent', 'deft-core' ) : mb_substr( $label, 0, 60 ),
		'hash'    => hash( 'sha256', $token ),
		'hint'    => substr( $token, 0, 8 ),
		'user'    => get_current_user_id(),
		'created' => time(),
		'used'    => 0,
	);
	update_option( 'deft_agent_tokens', $tokens, false );
	set_transient( 'deft_agent_new_token_' . get_current_user_id(), array( 'token' => $token ), 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'options-general.php?page=deft-core#deft-agent-tokens' ) );
	exit;
}

add_action( 'admin_post_deft_agent_revoke', 'deft_agent_revoke_token' );
function deft_agent_revoke_token() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'deft-core' ) );
	}
	check_admin_referer( 'deft_agent_revoke' );
	$tokens = deft_agent_tokens();
	unset( $tokens[ sanitize_key( wp_unslash( $_POST['token'] ?? '' ) ) ] );
	update_option( 'deft_agent_tokens', $tokens, false );
	wp_safe_redirect( admin_url( 'options-general.php?page=deft-core#deft-agent-tokens' ) );
	exit;
}

/** The token sent with this request, from Authorization: Bearer or X-Deft-Token (for hosts that strip Authorization). */
function deft_agent_request_token() {
	foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) && preg_match( '/^Bearer\s+(\S+)$/i', trim( wp_unslash( $_SERVER[ $key ] ) ), $m ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return $m[1];
		}
	}
	return isset( $_SERVER['HTTP_X_DEFT_TOKEN'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DEFT_TOKEN'] ) ) ) : '';
}

/** The saved token matching this request, with its id, or null. Checked once per request. */
function deft_agent_authenticate() {
	static $found = false;
	if ( false !== $found ) {
		return $found;
	}
	$found = null;
	$given = deft_agent_request_token();
	if ( 0 !== strpos( $given, 'dft_' ) ) {
		return $found;
	}
	$hash = hash( 'sha256', $given );
	foreach ( deft_agent_tokens() as $id => $token ) {
		if ( hash_equals( $token['hash'], $hash ) && get_userdata( $token['user'] ) ) {
			$found = $token + array( 'id' => $id );
			break;
		}
	}
	return $found;
}

/** Whether this is a request for our MCP route, before WordPress has parsed it. */
function deft_agent_is_mcp_request() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$path = untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
	if ( '/' . rest_get_url_prefix() . '/deft/v1/mcp' === substr( $path, -strlen( '/' . rest_get_url_prefix() . '/deft/v1/mcp' ) ) ) {
		return true;
	}
	return isset( $_GET['rest_route'] ) && '/deft/v1/mcp' === untrailingslashit( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

// Sign the agent in early, so security plugins that block anonymous REST requests see a logged-in user.
add_filter( 'determine_current_user', 'deft_agent_determine_user', 30 );
function deft_agent_determine_user( $user_id ) {
	if ( ! deft_agent_enabled() || ! deft_agent_is_mcp_request() ) {
		return $user_id;
	}
	$token = deft_agent_authenticate();
	return $token ? (int) $token['user'] : $user_id;
}

// Our token is the authentication, so WordPress's cookie nonce check must not log the agent out again.
add_filter( 'rest_authentication_errors', 'deft_agent_rest_auth', 200 );
function deft_agent_rest_auth( $result ) {
	if ( deft_agent_enabled() && deft_agent_is_mcp_request() && deft_agent_authenticate() ) {
		wp_set_current_user( (int) deft_agent_authenticate()['user'] );
		return true;
	}
	return $result;
}

/* ---------- Log ---------- */

function deft_agent_table() {
	global $wpdb;
	return $wpdb->prefix . 'deft_agent_log';
}

function deft_agent_install() {
	if ( DEFT_AGENT_DB_VERSION === get_option( 'deft_agent_db' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = deft_agent_table();
	dbDelta(
		"CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created datetime NOT NULL,
			token varchar(16) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tool varchar(64) NOT NULL DEFAULT '',
			target varchar(191) NOT NULL DEFAULT '',
			ok tinyint(1) NOT NULL DEFAULT 1,
			detail text NOT NULL,
			PRIMARY KEY  (id),
			KEY created (created)
		) {$wpdb->get_charset_collate()};"
	);
	update_option( 'deft_agent_db', DEFT_AGENT_DB_VERSION, false );
}

add_action( 'admin_init', 'deft_agent_maybe_install' );
function deft_agent_maybe_install() {
	if ( deft_agent_enabled() ) {
		deft_agent_install();
	}
}

function deft_agent_log( $tool, $target, $ok, $detail ) {
	global $wpdb;
	$token = deft_agent_authenticate();
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		deft_agent_table(),
		array(
			'created' => gmdate( 'Y-m-d H:i:s' ),
			'token'   => $token ? $token['id'] : '',
			'user_id' => get_current_user_id(),
			'tool'    => mb_substr( (string) $tool, 0, 64 ),
			'target'  => mb_substr( (string) $target, 0, 191 ),
			'ok'      => $ok ? 1 : 0,
			'detail'  => mb_substr( (string) $detail, 0, 2000 ),
		)
	);
}

function deft_agent_log_recent( $limit ) {
	global $wpdb;
	$table = deft_agent_table();
	if ( DEFT_AGENT_DB_VERSION !== get_option( 'deft_agent_db' ) ) {
		return array();
	}
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
}

// The daily clean-up the forms module already schedules: 90 days of log, 30 days of backups.
add_action( 'deft_forms_cleanup', 'deft_agent_cleanup' );
function deft_agent_cleanup() {
	global $wpdb;
	if ( DEFT_AGENT_DB_VERSION === get_option( 'deft_agent_db' ) ) {
		$table = deft_agent_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created < %s", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}
	$dir = deft_agent_backup_dir( false );
	if ( $dir ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && ! in_array( $file->getFilename(), array( '.htaccess', 'index.php' ), true ) && $file->getMTime() < time() - 30 * DAY_IN_SECONDS ) {
				wp_delete_file( $file->getPathname() );
			}
		}
	}
}

/* ---------- MCP endpoint ---------- */

add_action( 'rest_api_init', 'deft_agent_register_route' );
function deft_agent_register_route() {
	if ( ! deft_agent_enabled() ) {
		return;
	}
	register_rest_route(
		'deft/v1',
		'/mcp',
		array(
			array(
				'methods'             => 'POST',
				'callback'            => 'deft_agent_handle',
				'permission_callback' => 'deft_agent_permission',
			),
			array(
				// No server-to-client stream: the spec says answer GET with 405.
				'methods'             => 'GET, DELETE',
				'callback'            => function () {
					return new WP_REST_Response( array( 'error' => 'Use POST. This server does not open an event stream.' ), 405, array( 'Allow' => 'POST' ) );
				},
				'permission_callback' => 'deft_agent_permission',
			),
		)
	);
}

function deft_agent_permission() {
	$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ), PHP_URL_HOST ) : '';
	if ( '' !== $origin && wp_parse_url( home_url(), PHP_URL_HOST ) !== $origin ) {
		return new WP_Error( 'deft_agent_origin', 'Requests from web pages on other sites are not accepted.', array( 'status' => 403 ) );
	}
	$token = deft_agent_authenticate();
	if ( ! $token ) {
		return new WP_Error( 'deft_agent_token', 'Missing or unknown agent token. Create one in Settings > Deft > Agent tokens and send it as "Authorization: Bearer <token>".', array( 'status' => 401 ) );
	}
	wp_set_current_user( (int) $token['user'] );

	// Rate limit per token, per minute.
	$limit = (int) apply_filters( 'deft_agent_rate_limit', 240 );
	$key   = 'deft_agent_rl_' . $token['id'] . '_' . gmdate( 'YmdHi' );
	$count = (int) get_transient( $key ) + 1;
	set_transient( $key, $count, 2 * MINUTE_IN_SECONDS );
	if ( $limit > 0 && $count > $limit ) {
		return new WP_Error( 'deft_agent_rate', 'Too many requests this minute. Wait a moment and try again.', array( 'status' => 429 ) );
	}

	if ( ! $token['used'] || $token['used'] < time() - 5 * MINUTE_IN_SECONDS ) {
		$tokens = deft_agent_tokens();
		if ( isset( $tokens[ $token['id'] ] ) ) {
			$tokens[ $token['id'] ]['used'] = time();
			update_option( 'deft_agent_tokens', $tokens, false );
		}
	}
	return true;
}

function deft_agent_handle( WP_REST_Request $request ) {
	$body = json_decode( $request->get_body(), true );
	if ( ! is_array( $body ) ) {
		return deft_agent_json( deft_agent_rpc_error( null, -32700, 'Parse error: the body must be JSON-RPC 2.0.' ), 400 );
	}
	$batch = array_keys( $body ) === range( 0, count( $body ) - 1 ) && count( $body ) > 0;
	$out   = array();
	foreach ( $batch ? $body : array( $body ) as $message ) {
		$reply = is_array( $message ) ? deft_agent_dispatch( $message ) : deft_agent_rpc_error( null, -32600, 'Invalid request.' );
		if ( null !== $reply ) {
			$out[] = $reply;
		}
	}
	if ( ! $out ) {
		return deft_agent_json( null, 202 ); // Only notifications or responses: nothing to say.
	}
	return deft_agent_json( $batch ? $out : $out[0], 200 );
}

/** A response we serve ourselves, so empty objects stay {} and a 202 has no body. */
function deft_agent_json( $data, $status ) {
	$response = new Deft_Agent_Response( null, $status );
	$response->header( 'Content-Type', 'application/json; charset=utf-8' );
	$response->body = null === $data ? '' : (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	return $response;
}

add_action( 'rest_api_init', 'deft_agent_response_class', 0 );
function deft_agent_response_class() {
	if ( ! class_exists( 'Deft_Agent_Response' ) ) {
		/** WP_REST_Response carrying the exact JSON to send. */
		class Deft_Agent_Response extends WP_REST_Response { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
			/** @var string */
			public $body = '';
		}
	}
}

add_filter( 'rest_pre_serve_request', 'deft_agent_serve', 10, 2 );
function deft_agent_serve( $served, $result ) {
	if ( $served || ! $result instanceof Deft_Agent_Response ) {
		return $served;
	}
	echo $result->body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
	return true;
}

function deft_agent_rpc_error( $id, $code, $message ) {
	return array(
		'jsonrpc' => '2.0',
		'id'      => $id,
		'error'   => array( 'code' => $code, 'message' => $message ),
	);
}

function deft_agent_rpc_result( $id, $result ) {
	return array(
		'jsonrpc' => '2.0',
		'id'      => $id,
		'result'  => $result,
	);
}

/** One JSON-RPC message. Returns the reply, or null for notifications and client responses. */
function deft_agent_dispatch( array $message ) {
	$has_id = array_key_exists( 'id', $message );
	$id     = $has_id ? $message['id'] : null;
	$method = isset( $message['method'] ) ? (string) $message['method'] : '';
	$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

	if ( '' === $method ) {
		return $has_id && ( isset( $message['result'] ) || isset( $message['error'] ) ) ? null : deft_agent_rpc_error( $id, -32600, 'Invalid request.' );
	}
	if ( ! $has_id ) {
		return null; // notifications/initialized, notifications/cancelled and the rest need no answer.
	}

	switch ( $method ) {
		case 'initialize':
			$asked = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
			return deft_agent_rpc_result(
				$id,
				array(
					'protocolVersion' => in_array( $asked, DEFT_AGENT_PROTOCOLS, true ) ? $asked : DEFT_AGENT_PROTOCOLS[0],
					'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
					'serverInfo'      => array(
						'name'    => 'deft-core',
						'title'   => 'Deft Core: ' . get_bloginfo( 'name' ),
						'version' => DEFT_CORE_VERSION,
					),
					'instructions'    => deft_agent_instructions(),
				)
			);
		case 'ping':
			return deft_agent_rpc_result( $id, new stdClass() );
		case 'tools/list':
			return deft_agent_rpc_result( $id, array( 'tools' => deft_agent_tool_list() ) );
		case 'tools/call':
			return deft_agent_rpc_result( $id, deft_agent_call_tool( (string) ( $params['name'] ?? '' ), isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array() ) );
		case 'resources/list':
			return deft_agent_rpc_result( $id, array( 'resources' => array() ) );
		case 'resources/templates/list':
			return deft_agent_rpc_result( $id, array( 'resourceTemplates' => array() ) );
		case 'prompts/list':
			return deft_agent_rpc_result( $id, array( 'prompts' => array() ) );
		default:
			return deft_agent_rpc_error( $id, -32601, 'Method not found: ' . $method );
	}
}

function deft_agent_instructions() {
	$levels = array( 'read' );
	if ( deft_agent_allows( 'write' ) ) {
		$levels[] = 'changes';
	}
	if ( deft_agent_allows( 'files' ) ) {
		$levels[] = 'theme/plugin files';
	}
	return sprintf(
		"Deft Core agent tools for %s (%s). Allowed here: %s.\n"
		. "Start with site_info. Most WordPress data is reachable through rest_get / rest_write on the core REST API (/wp/v2/...), which runs as the token's user with WordPress's normal permission checks.\n"
		. "Rules: create posts and pages as drafts unless the user asked to publish. Pages built with Elementor are edited with elementor_get / elementor_update, not by changing post content. "
		. "Anything that deletes for good, creates users or changes their access, or changes the site address needs confirm: true, which you may only send after the user agreed in this conversation. "
		. "After changing a live page, run flush_caches and check it with get_page_html. Every call is logged on the site.",
		get_bloginfo( 'name' ),
		home_url(),
		implode( ', ', $levels )
	);
}

/* ---------- Tools ---------- */

/**
 * name => level (read, write, files), description, input schema properties, required, callback.
 * Other modules can add tools with the deft_agent_tools filter.
 */
function deft_agent_tools() {
	$s = function ( $type, $description, $extra = array() ) {
		return array( 'type' => $type, 'description' => $description ) + $extra;
	};
	$path_help = 'Path inside wp-content, e.g. "themes/hello-elementor-child/style.css".';
	$tools     = array(
		'site_info'        => array( 'read', 'WordPress and PHP versions, the active theme, active plugins, Elementor, post types, caching plugin, what the agent may do here and who it acts as. Call this first.', array(), array(), 'deft_agent_tool_site_info' ),
		'rest_get'         => array(
			'read',
			'GET any WordPress REST API route as the token\'s user, e.g. /wp/v2/pages?search=about, /wp/v2/pages/12?context=edit, /wp/v2/plugins, /wp/v2/settings, /wp/v2/menu-items, /wp/v2/types. Returns status, total counts and the data. Use context=edit to see raw content.',
			array(
				'path'  => $s( 'string', 'Route starting with /, e.g. /wp/v2/posts. A query string is allowed.' ),
				'query' => $s( 'object', 'Query parameters, e.g. {"per_page": 20, "status": "draft"}.' ),
			),
			array( 'path' ),
			'deft_agent_tool_rest_get',
		),
		'rest_write'       => array(
			'write',
			'POST, PUT, PATCH or DELETE on a WordPress REST API route as the token\'s user: create or update posts and pages (status draft unless the user asked to publish), terms, menus, menu items, users, settings (/wp/v2/settings), install or activate plugins (/wp/v2/plugins), and so on. DELETE moves posts to the trash. Permanent deletes (force=true, plugins, users), creating users or changing their role, password or email, and changing the site address or admin email need confirm: true, sent only after the user agreed.',
			array(
				'method'  => $s( 'string', 'POST, PUT, PATCH or DELETE.', array( 'enum' => array( 'POST', 'PUT', 'PATCH', 'DELETE' ) ) ),
				'path'    => $s( 'string', 'Route starting with /, e.g. /wp/v2/pages/12.' ),
				'body'    => $s( 'object', 'JSON body, e.g. {"title": "About", "status": "draft"}.' ),
				'query'   => $s( 'object', 'Query parameters, e.g. {"force": true}.' ),
				'confirm' => $s( 'boolean', 'Only for permanent deletes, and only after the user said yes.' ),
			),
			array( 'method', 'path' ),
			'deft_agent_tool_rest_write',
		),
		'get_page_html'    => array(
			'read',
			'Fetch a page of this site as a logged-out visitor sees it (after caches), to check a change. Returns the status and the HTML, or just the visible text.',
			array(
				'url'       => $s( 'string', 'Full URL on this site, or a path like /contact/.' ),
				'text_only' => $s( 'boolean', 'Return the visible text instead of HTML.' ),
				'max_chars' => $s( 'integer', 'Cut the answer to this many characters (default 60000).' ),
			),
			array( 'url' ),
			'deft_agent_tool_get_page_html',
		),
		'list_files'       => array(
			'read',
			'List files and folders inside wp-content (themes, plugins, uploads...).',
			array(
				'path'  => $s( 'string', 'Folder inside wp-content, e.g. "themes" or "themes/astra-child". Empty for wp-content itself.' ),
				'depth' => $s( 'integer', 'How many levels deep, 1 to 4 (default 1).' ),
			),
			array(),
			'deft_agent_tool_list_files',
		),
		'read_file'        => array(
			'read',
			'Read a text file inside wp-content. ' . $path_help . ' Use start_line and end_line for long files. Lines come back numbered.',
			array(
				'path'       => $s( 'string', $path_help ),
				'start_line' => $s( 'integer', 'First line, from 1.' ),
				'end_line'   => $s( 'integer', 'Last line.' ),
			),
			array( 'path' ),
			'deft_agent_tool_read_file',
		),
		'search_files'     => array(
			'read',
			'Search the text of files inside a wp-content folder. Returns matching lines with their file and line number.',
			array(
				'path'       => $s( 'string', 'Folder inside wp-content to search, e.g. "themes/astra-child".' ),
				'pattern'    => $s( 'string', 'Text to find (case-insensitive), or a PHP regular expression like /add_action\(.+init/ when regex is true.' ),
				'regex'      => $s( 'boolean', 'Treat pattern as a regular expression with delimiters.' ),
				'extensions' => $s( 'string', 'Only these file types, comma-separated, e.g. "php,css". Default: common text files.' ),
			),
			array( 'path', 'pattern' ),
			'deft_agent_tool_search_files',
		),
		'write_file'       => array(
			'files',
			'Create or overwrite a file under themes/ or plugins/ (not Deft Core). PHP is syntax-checked first and the old version is backed up. Prefer edit_file for small changes, and a child theme over a parent theme.',
			array(
				'path'    => $s( 'string', $path_help ),
				'content' => $s( 'string', 'The whole new file.' ),
			),
			array( 'path', 'content' ),
			'deft_agent_tool_write_file',
		),
		'edit_file'        => array(
			'files',
			'Replace exact text in a file under themes/ or plugins/ (not Deft Core). old_text must appear exactly once unless replace_all is true. PHP is syntax-checked and the old version is backed up.',
			array(
				'path'        => $s( 'string', $path_help ),
				'old_text'    => $s( 'string', 'Text to replace, copied exactly from read_file (without the line numbers).' ),
				'new_text'    => $s( 'string', 'Replacement text.' ),
				'replace_all' => $s( 'boolean', 'Replace every occurrence.' ),
			),
			array( 'path', 'old_text', 'new_text' ),
			'deft_agent_tool_edit_file',
		),
		'get_post_meta'    => array(
			'read',
			'Read custom fields of a post or page. Without key, lists every field (long values are shortened).',
			array(
				'post_id' => $s( 'integer', 'Post or page ID.' ),
				'key'     => $s( 'string', 'One field name.' ),
			),
			array( 'post_id' ),
			'deft_agent_tool_get_post_meta',
		),
		'update_post_meta' => array(
			'write',
			'Set one custom field on a post or page (for SEO plugins, ACF and similar). For Elementor content use elementor_update instead.',
			array(
				'post_id' => $s( 'integer', 'Post or page ID.' ),
				'key'     => $s( 'string', 'Field name.' ),
				'value'   => $s( 'string', 'New value as text. For a number, list or object send JSON text and set json: true.' ),
				'json'    => $s( 'boolean', 'value is JSON to decode first.' ),
			),
			array( 'post_id', 'key', 'value' ),
			'deft_agent_tool_update_post_meta',
		),
		'elementor_get'    => array(
			'read',
			'Read an Elementor page. By default returns an outline (each element\'s id, type, widget and a short text preview). Pass element_id for that element in full, or full: true for the whole page data.',
			array(
				'post_id'    => $s( 'integer', 'Page, post or template ID.' ),
				'element_id' => $s( 'string', 'One element\'s id from the outline.' ),
				'full'       => $s( 'boolean', 'Return the whole element tree.' ),
			),
			array( 'post_id' ),
			'deft_agent_tool_elementor_get',
		),
		'elementor_update' => array(
			'write',
			'Change an Elementor page and rebuild its CSS. Either merge settings into one element (element_id + settings, e.g. {"title": "New heading"}), or replace the whole tree with data. The previous version is backed up. Check the page with get_page_html afterwards.',
			array(
				'post_id'    => $s( 'integer', 'Page, post or template ID.' ),
				'element_id' => $s( 'string', 'Element to change.' ),
				'settings'   => $s( 'object', 'Settings to merge into that element.' ),
				'data'       => $s( 'array', 'Whole new element tree (as elementor_get full returns it).', array( 'items' => array( 'type' => 'object' ) ) ),
			),
			array( 'post_id' ),
			'deft_agent_tool_elementor_update',
		),
		'upload_media'     => array(
			'write',
			'Download an image or file from a URL into the media library. Returns the attachment ID and its URL.',
			array(
				'url'     => $s( 'string', 'Public https URL of the file.' ),
				'title'   => $s( 'string', 'Title in the media library.' ),
				'alt'     => $s( 'string', 'Alt text for images.' ),
				'post_id' => $s( 'integer', 'Attach to this post or page.' ),
			),
			array( 'url' ),
			'deft_agent_tool_upload_media',
		),
		'get_option'       => array(
			'read',
			'Read a WordPress option (setting) by name, e.g. blogname, elementor_active_kit, theme_mods_astra-child. Options that look like passwords, keys or tokens are never returned.',
			array( 'name' => $s( 'string', 'Option name.' ) ),
			array( 'name' ),
			'deft_agent_tool_get_option',
		),
		'update_option'    => array(
			'write',
			'Change a WordPress option. Site address, admin email, user roles, registration, active plugins, the theme and Deft Core\'s own settings can\'t be changed here.',
			array(
				'name'  => $s( 'string', 'Option name.' ),
				'value' => $s( 'string', 'New value as text. For a number, list or object send JSON text and set json: true.' ),
				'json'  => $s( 'boolean', 'value is JSON to decode first.' ),
			),
			array( 'name', 'value' ),
			'deft_agent_tool_update_option',
		),
		'flush_caches'     => array( 'write', 'Clear the WordPress object cache, Elementor\'s CSS and the page cache of any known caching plugin (LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, SiteGround, Autoptimize, Hostinger). Run after changing a live page.', array(), array(), 'deft_agent_tool_flush_caches' ),
		'agent_log'        => array(
			'read',
			'The latest agent actions on this site (all agents), newest first.',
			array( 'limit' => $s( 'integer', 'How many, up to 100 (default 20).' ) ),
			array(),
			'deft_agent_tool_agent_log',
		),
	);
	return apply_filters( 'deft_agent_tools', $tools );
}

function deft_agent_tool_list() {
	$list = array();
	foreach ( deft_agent_tools() as $name => $tool ) {
		list( $level, $description, $properties, $required ) = $tool;
		if ( ! deft_agent_allows( $level ) ) {
			continue;
		}
		$schema = array(
			'type'       => 'object',
			'properties' => $properties ? $properties : new stdClass(),
		);
		if ( $required ) {
			$schema['required'] = $required;
		}
		$list[] = array(
			'name'        => $name,
			'description' => $description,
			'inputSchema' => $schema,
			'annotations' => array(
				'readOnlyHint'    => 'read' === $level,
				'destructiveHint' => 'read' !== $level && in_array( $name, array( 'rest_write', 'write_file', 'edit_file', 'update_option', 'elementor_update' ), true ),
				'openWorldHint'   => false,
			),
		);
	}
	return $list;
}

function deft_agent_call_tool( $name, array $args ) {
	$tools = deft_agent_tools();
	if ( ! isset( $tools[ $name ] ) ) {
		return deft_agent_tool_error( 'Unknown tool: ' . $name );
	}
	list( $level, , , $required, $callback ) = $tools[ $name ];
	if ( ! deft_agent_allows( $level ) ) {
		$why = 'files' === $level ? 'Editing theme and plugin files is switched off on this site (Settings > Deft > AI agent access).' : 'Changes are switched off on this site (Settings > Deft > AI agent access). Ask the site admin to allow changes.';
		deft_agent_log( $name, '', false, $why );
		return deft_agent_tool_error( $why );
	}
	foreach ( $required as $key ) {
		if ( ! array_key_exists( $key, $args ) ) {
			return deft_agent_tool_error( 'Missing argument: ' . $key );
		}
	}
	$target = (string) ( $args['path'] ?? $args['url'] ?? $args['name'] ?? ( isset( $args['post_id'] ) ? 'post ' . (int) $args['post_id'] : '' ) );
	if ( isset( $args['method'] ) ) {
		$target = strtoupper( (string) $args['method'] ) . ' ' . $target;
	}
	try {
		$result = call_user_func( $callback, $args );
	} catch ( Throwable $e ) {
		$result = new WP_Error( 'deft_agent_exception', $e->getMessage() );
	}
	if ( is_wp_error( $result ) ) {
		deft_agent_log( $name, $target, false, $result->get_error_message() );
		return deft_agent_tool_error( $result->get_error_message() );
	}
	deft_agent_log( $name, $target, true, 'read' === $level ? '' : deft_agent_log_summary( $args ) );
	$text = is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( strlen( (string) $text ) > DEFT_AGENT_MAX_OUTPUT ) {
		$text = mb_strcut( $text, 0, DEFT_AGENT_MAX_OUTPUT ) . "\n…[cut at " . DEFT_AGENT_MAX_OUTPUT . ' bytes: ask for less, e.g. fewer items per page or a line range]';
	}
	return array(
		'content' => array( array( 'type' => 'text', 'text' => (string) $text ) ),
		'isError' => false,
	);
}

function deft_agent_tool_error( $message ) {
	return array(
		'content' => array( array( 'type' => 'text', 'text' => $message ) ),
		'isError' => true,
	);
}

/** What the log keeps of a change: the arguments, with file contents reduced to their size. */
function deft_agent_log_summary( array $args ) {
	foreach ( array( 'content', 'old_text', 'new_text', 'data' ) as $key ) {
		if ( isset( $args[ $key ] ) ) {
			$args[ $key ] = '[' . strlen( is_string( $args[ $key ] ) ? $args[ $key ] : wp_json_encode( $args[ $key ] ) ) . ' bytes]';
		}
	}
	return (string) wp_json_encode( deft_agent_redact( $args ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/* ---------- Tool: site ---------- */

function deft_agent_tool_site_info() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$theme   = wp_get_theme();
	$all     = get_plugins();
	$plugins = array();
	foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
		$plugins[] = array(
			'file'    => $file,
			'name'    => $all[ $file ]['Name'] ?? $file,
			'version' => $all[ $file ]['Version'] ?? '',
		);
	}
	$user = wp_get_current_user();
	return array(
		'site'        => array(
			'name'        => get_bloginfo( 'name' ),
			'url'         => home_url( '/' ),
			'admin'       => admin_url(),
			'rest'        => rest_url(),
			'language'    => get_locale(),
			'timezone'    => wp_timezone_string(),
			'permalinks'  => (string) get_option( 'permalink_structure' ),
			'front_page'  => 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 'latest posts',
			'multisite'   => is_multisite(),
		),
		'versions'    => array(
			'wordpress' => get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'deft_core' => DEFT_CORE_VERSION,
			'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementor_pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
		),
		'theme'       => array(
			'name'       => $theme->get( 'Name' ),
			'stylesheet' => $theme->get_stylesheet(),
			'template'   => $theme->get_template(),
			'is_child'   => $theme->get_stylesheet() !== $theme->get_template(),
			'block'      => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
		),
		'plugins'     => $plugins,
		'post_types'  => array_values( get_post_types( array( 'show_in_rest' => true ) ) ),
		'cache'       => deft_agent_cache_plugins(),
		'agent'       => array(
			'acts_as'      => $user->user_login,
			'roles'        => array_values( $user->roles ),
			'allowed'      => array(
				'read'    => true,
				'changes' => deft_agent_allows( 'write' ),
				'files'   => deft_agent_allows( 'files' ),
			),
			'file_editing_blocked_by_wp_config' => ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
		),
	);
}

/* ---------- Tools: REST ---------- */

/** Split "/wp/v2/pages?search=a" into a route and its query, refusing our own endpoint. */
function deft_agent_parse_route( $path, $query ) {
	$path = trim( (string) $path );
	if ( '' === $path || '/' !== $path[0] ) {
		return new WP_Error( 'deft_agent_path', 'The path must start with /, e.g. /wp/v2/pages.' );
	}
	$parts = explode( '?', $path, 2 );
	$route = untrailingslashit( $parts[0] );
	$route = '' === $route ? '/' : $route;
	$args  = array();
	if ( isset( $parts[1] ) ) {
		parse_str( $parts[1], $args );
	}
	if ( is_array( $query ) ) {
		$args = array_merge( $args, $query );
	}
	if ( 0 === strpos( $route, '/deft/v1/mcp' ) ) {
		return new WP_Error( 'deft_agent_path', 'That is this agent endpoint itself.' );
	}
	return array( $route, $args );
}

function deft_agent_rest( $method, $route, $query, $body ) {
	$request = new WP_REST_Request( $method, $route );
	$request->set_query_params( $query );
	if ( null !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		$request->set_body_params( array() );
	}
	$response = rest_do_request( $request );
	$server   = rest_get_server();
	$data     = $server->response_to_data( $response, false );
	$headers  = $response->get_headers();
	$out      = array(
		'status' => $response->get_status(),
	);
	foreach ( array( 'X-WP-Total' => 'total', 'X-WP-TotalPages' => 'total_pages' ) as $header => $key ) {
		if ( isset( $headers[ $header ] ) ) {
			$out[ $key ] = (int) $headers[ $header ];
		}
	}
	$out['data'] = deft_agent_strip_links( $data );
	return $out;
}

/** REST answers carry _links on every item; they are noise for an agent. */
function deft_agent_strip_links( $data ) {
	if ( is_array( $data ) ) {
		unset( $data['_links'] );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = deft_agent_strip_links( $value );
			}
		}
	}
	return $data;
}

function deft_agent_tool_rest_get( $args ) {
	$parsed = deft_agent_parse_route( $args['path'], $args['query'] ?? array() );
	if ( is_wp_error( $parsed ) ) {
		return $parsed;
	}
	return deft_agent_rest( 'GET', $parsed[0], $parsed[1], null );
}

function deft_agent_tool_rest_write( $args ) {
	$method = strtoupper( (string) $args['method'] );
	if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
		return new WP_Error( 'deft_agent_method', 'Use POST, PUT, PATCH or DELETE (rest_get for reading).' );
	}
	$parsed = deft_agent_parse_route( $args['path'], $args['query'] ?? array() );
	if ( is_wp_error( $parsed ) ) {
		return $parsed;
	}
	list( $route, $query ) = $parsed;
	$body                  = isset( $args['body'] ) && is_array( $args['body'] ) ? $args['body'] : array();

	// Changes that can't be undone, or that hand out access, need the user's yes first.
	$force = ! empty( $query['force'] ) && 'false' !== $query['force'] || ! empty( $body['force'] );
	$why   = '';
	if ( 'DELETE' === $method && ( $force || preg_match( '#^/wp/v2/(plugins|users)(/|$)#', $route ) ) ) {
		$why = 'This deletes for good.';
	} elseif ( preg_match( '#^/wp/v2/users(/|$)#', $route ) && ( '/wp/v2/users' === $route || isset( $body['roles'] ) || isset( $body['password'] ) || isset( $body['email'] ) ) ) {
		$why = 'This creates a user or changes a user\'s role, password or email.';
	} elseif ( '/wp/v2/settings' === $route && array_intersect( array_keys( $body ), array( 'url', 'email' ) ) ) {
		$why = 'This changes the site address or the admin email.';
	}
	if ( $why && true !== ( $args['confirm'] ?? false ) ) {
		return new WP_Error( 'deft_agent_confirm', $why . ' Ask the user first; if they agree, call again with confirm: true.' );
	}
	return deft_agent_rest( $method, $route, $query, $body );
}

function deft_agent_tool_get_page_html( $args ) {
	$url  = trim( (string) $args['url'] );
	$url  = '/' === substr( $url, 0, 1 ) ? home_url( $url ) : $url;
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! $host || strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
		return new WP_Error( 'deft_agent_url', 'Only pages of this site can be fetched.' );
	}
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 20,
			'redirection' => 3,
			'user-agent'  => 'DeftCoreAgent/' . DEFT_CORE_VERSION . '; ' . home_url(),
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'deft_agent_fetch', 'Could not load the page: ' . $response->get_error_message() . '. Some hosts block a site from loading itself.' );
	}
	$html = (string) wp_remote_retrieve_body( $response );
	if ( ! empty( $args['text_only'] ) ) {
		$html = preg_replace( '#<(script|style|noscript|svg)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
	}
	$max  = max( 1000, min( DEFT_AGENT_MAX_OUTPUT - 1000, (int) ( $args['max_chars'] ?? 60000 ) ) );
	$size = strlen( $html );
	return array(
		'status'    => wp_remote_retrieve_response_code( $response ),
		'url'       => $url,
		'bytes'     => $size,
		'truncated' => $size > $max,
		'body'      => mb_strcut( $html, 0, $max ),
	);
}

/* ---------- Tools: files ---------- */

/**
 * A path inside wp-content, resolved and checked. For writing it must also sit under themes/ or plugins/
 * (deft_agent_writable_roots filter), outside Deft Core, with a text extension.
 */
function deft_agent_resolve( $path, $write = false, $must_exist = true ) {
	$path = str_replace( '\\', '/', trim( (string) $path ) );
	$path = ltrim( preg_replace( '#^/?wp-content/#', '', $path ), '/' );
	if ( preg_match( '#(^|/)\.\.(/|$)#', $path ) || false !== strpos( $path, "\0" ) ) {
		return new WP_Error( 'deft_agent_path', 'Paths can\'t go up with "..".' );
	}
	$base = realpath( WP_CONTENT_DIR );
	$full = $base . ( '' === $path ? '' : '/' . $path );
	$real = realpath( $full );
	if ( false === $real ) {
		if ( $must_exist ) {
			return new WP_Error( 'deft_agent_missing', 'Not found: wp-content/' . $path );
		}
		$parent = realpath( dirname( $full ) );
		$real   = $parent ? $parent . '/' . basename( $full ) : false;
		if ( ! $real ) {
			// New folders: check the nearest folder that exists.
			$probe = dirname( $full );
			while ( ! is_dir( $probe ) && strlen( $probe ) > strlen( $base ) ) {
				$probe = dirname( $probe );
			}
			$probe = realpath( $probe );
			if ( ! $probe || 0 !== strpos( $probe . '/', $base . '/' ) ) {
				return new WP_Error( 'deft_agent_path', 'Only files inside wp-content can be used.' );
			}
			$real = $base . '/' . $path;
		}
	}
	$real = str_replace( '\\', '/', $real );
	$base = str_replace( '\\', '/', $base );
	if ( $real !== $base && 0 !== strpos( $real, $base . '/' ) ) {
		return new WP_Error( 'deft_agent_path', 'Only files inside wp-content can be used.' );
	}
	$relative = ltrim( substr( $real, strlen( $base ) ), '/' );
	$name     = strtolower( basename( $real ) );
	if ( ( 'debug.log' !== $name && preg_match( '/^(wp-config\.php|\.env.*|\.htpasswd|.*\.(pem|key|p12|pfx|sql|sqlite|db|log))$/', $name ) ) || 0 === strpos( $relative, 'database/' ) ) {
		return new WP_Error( 'deft_agent_private', 'That file is private and can\'t be used by agents.' );
	}
	if ( $write ) {
		$roots = (array) apply_filters( 'deft_agent_writable_roots', array( 'themes/', 'plugins/' ) );
		$ok    = false;
		foreach ( $roots as $root ) {
			$ok = $ok || 0 === strpos( $relative, $root );
		}
		if ( ! $ok ) {
			return new WP_Error( 'deft_agent_readonly', 'Agents can only write under: ' . implode( ', ', $roots ) );
		}
		$own = str_replace( '\\', '/', realpath( DEFT_CORE_DIR ) );
		if ( 0 === strpos( $real . '/', $own . '/' ) ) {
			return new WP_Error( 'deft_agent_readonly', 'Deft Core\'s own files can\'t be changed by agents.' );
		}
		$ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, deft_agent_text_extensions(), true ) ) {
			return new WP_Error( 'deft_agent_type', 'Only text files can be written: ' . implode( ', ', deft_agent_text_extensions() ) . '.' );
		}
		$cap = 0 === strpos( $relative, 'themes/' ) ? 'edit_themes' : 'edit_plugins';
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'deft_agent_cap', 'The token\'s user is not allowed to edit these files (' . $cap . ').' );
		}
	}
	return array( $real, $relative );
}

function deft_agent_text_extensions() {
	return array( 'php', 'css', 'scss', 'js', 'mjs', 'json', 'html', 'htm', 'txt', 'md', 'svg', 'xml', 'twig', 'po', 'pot', 'csv', 'yml', 'yaml' );
}

function deft_agent_tool_list_files( $args ) {
	$resolved = deft_agent_resolve( $args['path'] ?? '' );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $dir, $relative ) = $resolved;
	if ( ! is_dir( $dir ) ) {
		return new WP_Error( 'deft_agent_dir', 'Not a folder: wp-content/' . $relative );
	}
	$depth    = max( 1, min( 4, (int) ( $args['depth'] ?? 1 ) ) );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	$iterator->setMaxDepth( $depth - 1 );
	$entries = array();
	foreach ( $iterator as $item ) {
		if ( count( $entries ) >= 1000 ) {
			$entries[] = array( 'note' => 'Stopped at 1000 entries. List a smaller folder.' );
			break;
		}
		$name = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $dir ) ) ), '/' );
		if ( preg_match( '#(^|/)(\.git|node_modules)(/|$)#', $name ) ) {
			continue;
		}
		$entries[] = $item->isDir()
			? array( 'path' => $name . '/', 'type' => 'dir' )
			: array( 'path' => $name, 'type' => 'file', 'bytes' => $item->getSize(), 'modified' => gmdate( 'c', $item->getMTime() ) );
	}
	usort(
		$entries,
		function ( $a, $b ) {
			return strcmp( $a['path'] ?? '~', $b['path'] ?? '~' );
		}
	);
	return array( 'folder' => 'wp-content/' . $relative, 'entries' => $entries );
}

function deft_agent_tool_read_file( $args ) {
	$resolved = deft_agent_resolve( $args['path'] );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $file, $relative ) = $resolved;
	if ( ! is_file( $file ) ) {
		return new WP_Error( 'deft_agent_file', 'Not a file: wp-content/' . $relative );
	}
	if ( filesize( $file ) > 2 * MB_IN_BYTES ) {
		return new WP_Error( 'deft_agent_big', 'The file is larger than 2 MB.' );
	}
	$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false !== strpos( $content, "\0" ) ) {
		return array( 'path' => $relative, 'bytes' => strlen( $content ), 'note' => 'Binary file, not shown.' );
	}
	$lines = preg_split( '/\r\n|\n|\r/', $content );
	$total = count( $lines );
	$start = max( 1, (int) ( $args['start_line'] ?? 1 ) );
	$end   = min( $total, (int) ( $args['end_line'] ?? $total ) );
	$out   = array();
	for ( $i = $start; $i <= $end; $i++ ) {
		$out[] = str_pad( (string) $i, 5, ' ', STR_PAD_LEFT ) . "\t" . $lines[ $i - 1 ];
	}
	return sprintf( "wp-content/%s (lines %d-%d of %d)\n%s", $relative, $start, $end, $total, implode( "\n", $out ) );
}

function deft_agent_tool_search_files( $args ) {
	$resolved = deft_agent_resolve( $args['path'] );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $dir, $relative ) = $resolved;
	if ( ! is_dir( $dir ) ) {
		return new WP_Error( 'deft_agent_dir', 'Not a folder: wp-content/' . $relative );
	}
	$pattern = (string) $args['pattern'];
	$regex   = ! empty( $args['regex'] );
	if ( $regex && false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return new WP_Error( 'deft_agent_regex', 'That regular expression is not valid. Include delimiters, e.g. /foo.+bar/i.' );
	}
	$exts    = ! empty( $args['extensions'] ) ? array_map( 'trim', explode( ',', strtolower( (string) $args['extensions'] ) ) ) : deft_agent_text_extensions();
	$matches = array();
	$files   = 0;
	$walk    = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			function ( $item ) {
				return ! ( $item->isDir() && in_array( $item->getFilename(), array( '.git', 'node_modules', 'vendor', 'cache' ), true ) );
			}
		)
	);
	foreach ( $walk as $item ) {
		if ( ! $item->isFile() || $item->getSize() > MB_IN_BYTES || ! in_array( strtolower( $item->getExtension() ), $exts, true ) ) {
			continue;
		}
		if ( ++$files > 5000 ) {
			$matches[] = array( 'note' => 'Stopped after 5000 files. Search a smaller folder.' );
			break;
		}
		$handle = fopen( $item->getPathname(), 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$line   = 0;
		while ( $handle && false !== ( $text = fgets( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;
			if ( $regex ? preg_match( $pattern, $text ) : false !== stripos( $text, $pattern ) ) {
				$matches[] = array(
					'file' => ltrim( $relative . '/' . str_replace( '\\', '/', substr( $item->getPathname(), strlen( $dir ) + 1 ) ), '/' ),
					'line' => $line,
					'text' => mb_substr( rtrim( $text ), 0, 300 ),
				);
				if ( count( $matches ) >= 200 ) {
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return array( 'matches' => $matches, 'note' => 'Stopped at 200 matches.' );
				}
			}
		}
		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}
	return array( 'matches' => $matches );
}

/** Where backups go: uploads/deft-core/agent-backups-<random>/, closed to the web. */
function deft_agent_backup_dir( $create = true ) {
	$suffix = get_option( 'deft_agent_backup_key' );
	if ( ! $suffix ) {
		if ( ! $create ) {
			return '';
		}
		$suffix = wp_generate_password( 12, false );
		update_option( 'deft_agent_backup_key', $suffix, false );
	}
	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . 'deft-core/agent-backups-' . $suffix;
	if ( ! is_dir( $dir ) ) {
		if ( ! $create ) {
			return '';
		}
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	return $dir;
}

/** Keep a copy before changing something. Returns the backup's name. */
function deft_agent_backup( $label, $content ) {
	$name = gmdate( 'Ymd-His' ) . '-' . sanitize_file_name( str_replace( '/', '__', $label ) ) . '.bak';
	file_put_contents( deft_agent_backup_dir() . '/' . $name, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	return $name;
}

/** PHP that would not parse is never saved: one bad character in functions.php takes the whole site down. */
function deft_agent_check_php( $file, $content ) {
	if ( 'php' !== strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
		return true;
	}
	try {
		token_get_all( $content, TOKEN_PARSE );
	} catch ( ParseError $e ) {
		return new WP_Error( 'deft_agent_php', sprintf( 'Not saved: PHP syntax error on line %d: %s', $e->getLine(), $e->getMessage() ) );
	}
	return true;
}

function deft_agent_save_file( $file, $relative, $content ) {
	$check = deft_agent_check_php( $file, $content );
	if ( is_wp_error( $check ) ) {
		return $check;
	}
	$backup = null;
	if ( is_file( $file ) ) {
		$backup = deft_agent_backup( $relative, (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	} elseif ( ! wp_mkdir_p( dirname( $file ) ) ) {
		return new WP_Error( 'deft_agent_write', 'Could not create the folder.' );
	}
	if ( false === file_put_contents( $file, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return new WP_Error( 'deft_agent_write', 'Could not write the file. Check the folder permissions.' );
	}
	if ( function_exists( 'opcache_invalidate' ) && 'php' === strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
		opcache_invalidate( $file, true );
	}
	return array(
		'saved'  => 'wp-content/' . $relative,
		'bytes'  => strlen( $content ),
		'backup' => $backup,
	);
}

function deft_agent_tool_write_file( $args ) {
	$resolved = deft_agent_resolve( $args['path'], true, false );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	return deft_agent_save_file( $resolved[0], $resolved[1], (string) $args['content'] );
}

function deft_agent_tool_edit_file( $args ) {
	$resolved = deft_agent_resolve( $args['path'], true );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $file, $relative ) = $resolved;
	$content                 = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$old                     = (string) $args['old_text'];
	$count                   = '' === $old ? 0 : substr_count( $content, $old );
	if ( 0 === $count ) {
		return new WP_Error( 'deft_agent_edit', 'old_text was not found. Read the file again and copy the text exactly, without line numbers.' );
	}
	if ( $count > 1 && empty( $args['replace_all'] ) ) {
		return new WP_Error( 'deft_agent_edit', sprintf( 'old_text appears %d times. Add surrounding lines to make it unique, or set replace_all.', $count ) );
	}
	$updated = empty( $args['replace_all'] ) ? substr_replace( $content, (string) $args['new_text'], strpos( $content, $old ), strlen( $old ) ) : str_replace( $old, (string) $args['new_text'], $content );
	$result  = deft_agent_save_file( $file, $relative, $updated );
	if ( ! is_wp_error( $result ) ) {
		$result['replaced'] = empty( $args['replace_all'] ) ? 1 : $count;
	}
	return $result;
}

/* ---------- Tools: content ---------- */

function deft_agent_post( $post_id, $edit ) {
	$post = get_post( (int) $post_id );
	if ( ! $post ) {
		return new WP_Error( 'deft_agent_post', 'No post with ID ' . (int) $post_id . '.' );
	}
	if ( ! current_user_can( $edit ? 'edit_post' : 'read_post', $post->ID ) ) {
		return new WP_Error( 'deft_agent_cap', 'The token\'s user can\'t ' . ( $edit ? 'edit' : 'read' ) . ' that post.' );
	}
	return $post;
}

function deft_agent_tool_get_post_meta( $args ) {
	$post = deft_agent_post( $args['post_id'], true );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( isset( $args['key'] ) && '' !== $args['key'] ) {
		$key = (string) $args['key'];
		return array( 'post_id' => $post->ID, 'key' => $key, 'exists' => metadata_exists( 'post', $post->ID, $key ), 'value' => deft_agent_redact_value( $key, get_post_meta( $post->ID, $key, true ) ) );
	}
	$all = array();
	foreach ( get_post_meta( $post->ID ) as $key => $values ) {
		$value = maybe_unserialize( $values[0] );
		$text  = is_string( $value ) ? $value : wp_json_encode( $value );
		if ( strlen( (string) $text ) > 500 ) {
			$value = '[' . strlen( $text ) . ' bytes, read it with key]';
		}
		$all[ $key ] = deft_agent_redact_value( $key, $value );
	}
	ksort( $all );
	return array( 'post_id' => $post->ID, 'type' => $post->post_type, 'meta' => $all );
}

function deft_agent_tool_update_post_meta( $args ) {
	$post = deft_agent_post( $args['post_id'], true );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$key = (string) $args['key'];
	if ( '' === $key || 0 === strpos( $key, '_elementor_' ) || in_array( $key, array( '_edit_lock', '_edit_last', '_wp_attached_file', '_wp_attachment_metadata' ), true ) ) {
		return new WP_Error( 'deft_agent_meta', '' === $key ? 'Give a field name.' : 'That field is managed by WordPress or Elementor. Use elementor_update or rest_write.' );
	}
	$value = deft_agent_value( $args );
	if ( is_wp_error( $value ) ) {
		return $value;
	}
	$before = get_post_meta( $post->ID, $key, true );
	update_post_meta( $post->ID, $key, wp_slash( $value ) );
	return array( 'post_id' => $post->ID, 'key' => $key, 'before' => deft_agent_redact_value( $key, $before ), 'after' => deft_agent_redact_value( $key, get_post_meta( $post->ID, $key, true ) ) );
}

function deft_agent_elementor_data( $post_id ) {
	$raw  = get_post_meta( $post_id, '_elementor_data', true );
	$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
	return is_array( $data ) ? $data : array();
}

/** Each element in a short line: id, type, widget and the first words of its text. */
function deft_agent_elementor_outline( array $elements, $depth = 0 ) {
	$out = array();
	foreach ( $elements as $element ) {
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$preview  = '';
		foreach ( array( 'title', 'editor', 'text', 'description_text', 'title_text', 'button_text', 'html', 'heading' ) as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== trim( $settings[ $key ] ) ) {
				$preview = wp_trim_words( wp_strip_all_tags( $settings[ $key ] ), 12 );
				break;
			}
		}
		$out[] = array_filter(
			array(
				'id'      => $element['id'] ?? '',
				'depth'   => $depth,
				'type'    => $element['elType'] ?? '',
				'widget'  => $element['widgetType'] ?? null,
				'text'    => $preview ? $preview : null,
				'image'   => isset( $settings['image']['url'] ) ? $settings['image']['url'] : null,
				'link'    => isset( $settings['link']['url'] ) && '' !== $settings['link']['url'] ? $settings['link']['url'] : null,
			),
			function ( $v ) {
				return null !== $v;
			}
		);
		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$out = array_merge( $out, deft_agent_elementor_outline( $element['elements'], $depth + 1 ) );
		}
	}
	return $out;
}

/** Find one element by id, or replace it when $settings is given. */
function deft_agent_elementor_find( array &$elements, $id, $settings = null ) {
	foreach ( $elements as &$element ) {
		if ( isset( $element['id'] ) && (string) $element['id'] === (string) $id ) {
			if ( null !== $settings ) {
				$element['settings'] = array_merge( isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array(), $settings );
			}
			return $element;
		}
		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$found = deft_agent_elementor_find( $element['elements'], $id, $settings );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	return null;
}

function deft_agent_tool_elementor_get( $args ) {
	$post = deft_agent_post( $args['post_id'], true );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$data = deft_agent_elementor_data( $post->ID );
	$info = array(
		'post_id'   => $post->ID,
		'title'     => get_the_title( $post ),
		'status'    => $post->post_status,
		'edit_mode' => get_post_meta( $post->ID, '_elementor_edit_mode', true ),
		'template'  => get_post_meta( $post->ID, '_elementor_template_type', true ),
		'version'   => get_post_meta( $post->ID, '_elementor_version', true ),
	);
	if ( ! $data ) {
		return $info + array( 'note' => 'This page has no Elementor data. Edit its content with rest_write instead.' );
	}
	if ( ! empty( $args['element_id'] ) ) {
		$element = deft_agent_elementor_find( $data, (string) $args['element_id'] );
		return null === $element ? new WP_Error( 'deft_agent_element', 'No element with that id. Get the outline first.' ) : $info + array( 'element' => $element );
	}
	if ( ! empty( $args['full'] ) ) {
		return $info + array( 'data' => $data );
	}
	return $info + array( 'outline' => deft_agent_elementor_outline( $data ) );
}

function deft_agent_tool_elementor_update( $args ) {
	$post = deft_agent_post( $args['post_id'], true );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$data = deft_agent_elementor_data( $post->ID );
	if ( isset( $args['data'] ) ) {
		if ( ! is_array( $args['data'] ) ) {
			return new WP_Error( 'deft_agent_element', 'data must be the element list.' );
		}
		$new = $args['data'];
	} elseif ( ! empty( $args['element_id'] ) && isset( $args['settings'] ) && is_array( $args['settings'] ) ) {
		$new = $data;
		if ( null === deft_agent_elementor_find( $new, (string) $args['element_id'], $args['settings'] ) ) {
			return new WP_Error( 'deft_agent_element', 'No element with that id. Get the outline first.' );
		}
	} else {
		return new WP_Error( 'deft_agent_element', 'Send element_id with settings, or the whole data.' );
	}

	$backup = deft_agent_backup( 'elementor-' . $post->ID . '.json', (string) get_post_meta( $post->ID, '_elementor_data', true ) );
	$saved  = false;
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
		// Elementor's own save keeps revisions and rebuilds the page CSS.
		$document = \Elementor\Plugin::$instance->documents->get( $post->ID, false );
		if ( $document ) {
			$saved = (bool) $document->save( array( 'elements' => $new ) );
		}
	}
	if ( ! $saved ) {
		update_post_meta( $post->ID, '_elementor_data', wp_slash( wp_json_encode( $new ) ) );
		update_post_meta( $post->ID, '_elementor_edit_mode', 'builder' );
		delete_post_meta( $post->ID, '_elementor_css' );
		delete_post_meta( $post->ID, '_elementor_element_cache' );
	}
	deft_agent_clear_elementor();
	return array( 'post_id' => $post->ID, 'saved' => true, 'via' => $saved ? 'elementor' : 'post meta', 'backup' => $backup, 'next' => 'Run flush_caches if the site has a page cache, then check with get_page_html.' );
}

function deft_agent_tool_upload_media( $args ) {
	if ( ! current_user_can( 'upload_files' ) ) {
		return new WP_Error( 'deft_agent_cap', 'The token\'s user can\'t upload files.' );
	}
	$url = esc_url_raw( (string) $args['url'], array( 'https', 'http' ) );
	if ( ! $url || ! wp_http_validate_url( $url ) ) {
		return new WP_Error( 'deft_agent_url', 'Give a public http(s) URL.' );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$tmp = download_url( $url, 60 );
	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}
	$name = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
	if ( ! pathinfo( $name, PATHINFO_EXTENSION ) ) {
		$mime = wp_get_image_mime( $tmp );
		$ext  = $mime ? array_search( $mime, wp_get_mime_types(), true ) : false;
		$name = ( $name ? $name : 'image' ) . '.' . ( $ext ? explode( '|', $ext )[0] : 'jpg' );
	}
	$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), (int) ( $args['post_id'] ?? 0 ), isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : null );
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $tmp );
		return $id;
	}
	if ( ! empty( $args['alt'] ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
	}
	return array( 'id' => $id, 'url' => wp_get_attachment_url( $id ), 'mime' => get_post_mime_type( $id ) );
}

/* ---------- Tools: options ---------- */

/** The value argument of update_post_meta / update_option, decoded when json is true. */
function deft_agent_value( array $args ) {
	if ( empty( $args['json'] ) || ! is_string( $args['value'] ) ) {
		return $args['value'];
	}
	$value = json_decode( $args['value'], true );
	if ( null === $value && 'null' !== trim( $args['value'] ) ) {
		return new WP_Error( 'deft_agent_json', 'value is not valid JSON.' );
	}
	return $value;
}

function deft_agent_secret_name( $name ) {
	return (bool) preg_match( '/(secret|passw|token|api_?key|apikey|private|salt|auth_key|license|credential|_key$|^deft_agent_)/i', (string) $name );
}

/** Blank out anything that looks like a secret, at any depth. */
function deft_agent_redact( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $key => $item ) {
			$value[ $key ] = is_string( $key ) && deft_agent_secret_name( $key ) && '' !== $item && null !== $item ? '[hidden]' : deft_agent_redact( $item );
		}
	} elseif ( is_object( $value ) ) {
		$value = deft_agent_redact( (array) $value );
	}
	return $value;
}

function deft_agent_redact_value( $name, $value ) {
	return deft_agent_secret_name( $name ) ? '[hidden]' : deft_agent_redact( $value );
}

function deft_agent_tool_get_option( $args ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'deft_agent_cap', 'The token\'s user can\'t read settings.' );
	}
	$name = (string) $args['name'];
	if ( deft_agent_secret_name( $name ) ) {
		return new WP_Error( 'deft_agent_private', 'That option looks like a secret and is not shown to agents.' );
	}
	$missing = new stdClass();
	$value   = get_option( $name, $missing );
	if ( $value === $missing ) {
		return array( 'name' => $name, 'exists' => false );
	}
	return array( 'name' => $name, 'exists' => true, 'value' => deft_agent_redact( $value ) );
}

function deft_agent_tool_update_option( $args ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'deft_agent_cap', 'The token\'s user can\'t change settings.' );
	}
	$name    = (string) $args['name'];
	$blocked = array( 'siteurl', 'home', 'admin_email', 'new_admin_email', 'users_can_register', 'default_role', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'cron', 'rewrite_rules', 'deft_core', 'db_version', 'initial_db_version', 'recently_activated', 'uninstall_plugins', 'auto_update_plugins' );
	if ( '' === $name || in_array( $name, $blocked, true ) || deft_agent_secret_name( $name ) || preg_match( '/user_roles$/', $name ) || 0 === strpos( $name, '_transient' ) || 0 === strpos( $name, '_site_transient' ) ) {
		return new WP_Error( 'deft_agent_option', 'Agents can\'t change that option. Plugins and themes are managed through rest_write on /wp/v2/plugins; the site address and user roles stay with the admin.' );
	}
	$value = deft_agent_value( $args );
	if ( is_wp_error( $value ) ) {
		return $value;
	}
	$before = get_option( $name );
	update_option( $name, $value );
	return array( 'name' => $name, 'before' => deft_agent_redact( $before ), 'after' => deft_agent_redact( get_option( $name ) ) );
}

/* ---------- Tools: caches and log ---------- */

function deft_agent_cache_plugins() {
	return deft_core_cache_plugins();
}

function deft_agent_clear_elementor() {
	return deft_core_clear_elementor();
}

function deft_agent_tool_flush_caches() {
	return array( 'cleared' => deft_core_flush_caches() );
}

function deft_agent_tool_agent_log( $args ) {
	$tokens = deft_agent_tokens();
	$rows   = array();
	foreach ( deft_agent_log_recent( max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) ) ) as $row ) {
		$rows[] = array(
			'when'   => $row['created'] . ' UTC',
			'agent'  => $tokens[ $row['token'] ]['label'] ?? $row['token'],
			'tool'   => $row['tool'],
			'target' => $row['target'],
			'ok'     => (bool) $row['ok'],
			'detail' => $row['detail'],
		);
	}
	return $rows;
}
