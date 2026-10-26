<?php
/**
 * Deft pages: WordPress pages whose markup is stored HTML, shown through a plain Deft template instead of Elementor.
 *
 * How a Deft page is kept:
 * - The live markup is in post meta (_deft_page_html, plus optional <head> extras in _deft_page_head), exactly as
 *   written. It never passes through post_content, so the block editor, wpautop and kses can't rewrite it.
 * - Changes can wait as a draft (_deft_page_draft) with a preview link, then go live in one step.
 * - Every version that goes live is kept in the deft_page_revisions table for 30 days (the latest 10 always), so any
 *   change can be undone. Going live also clears the page cache and tells Deft Central, which clears Hostinger's CDN.
 * - Editable elements carry data-deft-edit markers (includes/html.php), added whenever a version goes live.
 *
 * Who can change them:
 * - Deft staff: the administrators ticked under Settings > Deft > Deft pages (all administrators until someone is
 *   ticked). Clients don't edit their own sites, so on client sites only Deft's own accounts should be ticked.
 * - Deft Studio, through Deft Central, with requests signed by the site's Central secret. Off until "Let Deft Studio
 *   publish pages" is ticked.
 *
 * REST API (namespace deft/v1), documented in Deft Central's docs/deft-pages.md:
 *   GET    /pages                          Deft pages on this site
 *   POST   /pages                          new page (saved as a draft)
 *   GET    /pages/{id}                     one page: live and draft markup, preview link, editable outline
 *   POST   /pages/{id}/draft               save a draft
 *   DELETE /pages/{id}/draft               throw the draft away
 *   POST   /pages/{id}/publish             make the draft (or the markup sent) live
 *   GET    /pages/{id}/revisions           versions that went live
 *   POST   /pages/{id}/restore             put an earlier version back live
 */

defined( 'ABSPATH' ) || exit;

const DEFT_PAGES_DB_VERSION = '1';
const DEFT_PAGES_MAX_BYTES  = 3145728; // 3 MB of markup per page is far more than any page we build.
const DEFT_PAGES_KEEP_DAYS  = 30;
const DEFT_PAGES_KEEP_MIN   = 10;

/** The page templates Deft pages can use: template slug => label. */
function deft_pages_templates() {
	return array(
		'deft-canvas' => __( 'Deft page', 'deft-core' ),
		'deft-theme'  => __( 'Deft page with the theme header and footer', 'deft-core' ),
	);
}

/* ---------- Settings ---------- */

add_filter( 'deft_core_defaults', 'deft_pages_defaults' );
function deft_pages_defaults( $defaults ) {
	return $defaults + array(
		'pages_api'   => 0,
		'pages_staff' => array(),
	);
}

add_filter( 'deft_core_sanitize', 'deft_pages_sanitize', 10, 2 );
function deft_pages_sanitize( $out, $input ) {
	$out['pages_api'] = empty( $input['pages_api'] ) ? 0 : 1;
	$staff            = array();
	foreach ( (array) ( $input['pages_staff'] ?? array() ) as $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		if ( $user && user_can( $user, 'manage_options' ) ) {
			$staff[] = (int) $user->ID;
		}
	}
	$out['pages_staff'] = array_values( array_unique( $staff ) );
	if ( $out['pages_api'] && ! deft_central_configured() ) {
		add_settings_error( 'deft_core', 'pages_api', __( 'Deft Studio publishes through Deft Central, so it needs the Central endpoint, site ID and secret first. Saved, but Studio can\'t publish until those are set.', 'deft-core' ), 'warning' );
	}
	return $out;
}

add_action( 'deft_core_settings_sections', 'deft_pages_settings_fields', 5 );
function deft_pages_settings_fields( $s ) {
	$admins = get_users( array( 'role' => 'administrator', 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
	$staff  = array_map( 'intval', (array) $s['pages_staff'] );
	?>
	<h2 id="deft-pages"><?php esc_html_e( 'Deft pages', 'deft-core' ); ?></h2>
	<p><?php esc_html_e( 'Pages built in Deft Studio (or moved off Elementor) are stored as plain HTML and shown with the Deft page template. Every version that goes live is kept for 30 days.', 'deft-core' ); ?></p>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><?php esc_html_e( 'Deft staff', 'deft-core' ); ?></th>
			<td>
				<fieldset>
				<?php foreach ( $admins as $admin ) : ?>
					<label style="display:block;margin:2px 0"><input type="checkbox" name="deft_core[pages_staff][]" value="<?php echo esc_attr( $admin->ID ); ?>" <?php checked( in_array( (int) $admin->ID, $staff, true ) ); ?>>
						<?php echo esc_html( $admin->display_name ); ?> <span class="description">(<?php echo esc_html( $admin->user_email ); ?>)</span></label>
				<?php endforeach; ?>
				</fieldset>
				<p class="description"><?php esc_html_e( 'Only these accounts can change Deft pages, move pages off Elementor or use Live Edit. Clients never edit their own pages, so tick Deft\'s own accounts only. While nobody is ticked, every administrator counts as staff.', 'deft-core' ); ?></p>
			</td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Deft Studio', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[pages_api]" value="1" <?php checked( $s['pages_api'] ); ?>> <?php esc_html_e( 'Let Deft Studio publish pages from Deft Central', 'deft-core' ); ?></label>
			<p class="description"><?php esc_html_e( 'Studio\'s requests are signed with the Deft Central secret above. New pages arrive as drafts; nothing goes live until someone presses Go live.', 'deft-core' ); ?></p></td></tr>
	</table>
	<?php
}

/** Whether a user is Deft staff on this site (see the file comment). */
function deft_pages_is_staff( $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
	if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) || ! user_can( $user_id, 'unfiltered_html' ) ) {
		return false;
	}
	$staff = array_map( 'intval', (array) deft_core_setting( 'pages_staff' ) );
	return $staff ? in_array( $user_id, $staff, true ) : true;
}

/* ---------- Revisions table ---------- */

function deft_pages_table() {
	global $wpdb;
	return $wpdb->prefix . 'deft_page_revisions';
}

function deft_pages_install() {
	if ( DEFT_PAGES_DB_VERSION === get_option( 'deft_pages_db' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = deft_pages_table();
	dbDelta(
		"CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			created datetime NOT NULL,
			hash char(40) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor varchar(100) NOT NULL DEFAULT '',
			note varchar(255) NOT NULL DEFAULT '',
			changes longtext NOT NULL,
			html longtext NOT NULL,
			head longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY post_created (post_id, created)
		) {$wpdb->get_charset_collate()};"
	);
	update_option( 'deft_pages_db', DEFT_PAGES_DB_VERSION, false );
}

add_action( 'deft_forms_cleanup', 'deft_pages_cleanup' );
/** Drops versions older than 30 days, always keeping each page's latest 10. */
function deft_pages_cleanup() {
	if ( DEFT_PAGES_DB_VERSION !== get_option( 'deft_pages_db' ) ) {
		return;
	}
	global $wpdb;
	$table  = deft_pages_table();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - DEFT_PAGES_KEEP_DAYS * DAY_IN_SECONDS );
	$posts  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM $table WHERE created < %s", $cutoff ) ); // phpcs:ignore WordPress.DB
	foreach ( $posts as $post_id ) {
		$keep = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE post_id = %d ORDER BY id DESC LIMIT %d", $post_id, DEFT_PAGES_KEEP_MIN ) ); // phpcs:ignore WordPress.DB
		$keep = $keep ? implode( ',', array_map( 'intval', $keep ) ) : '0';
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE post_id = %d AND created < %s AND id NOT IN ($keep)", $post_id, $cutoff ) ); // phpcs:ignore WordPress.DB
	}
}

/* ---------- Storage ---------- */

function deft_pages_hash( $html, $head ) {
	return sha1( $html . "\0" . $head );
}

/** Whether a post is a Deft page (it uses one of our templates). */
function deft_pages_is( $post ) {
	$post = get_post( $post );
	return $post && 'page' === $post->post_type && isset( deft_pages_templates()[ get_page_template_slug( $post ) ] );
}

/**
 * A Deft page's state: live html/head/hash, the draft (html, head, hash, saved, by) or null, and when it last went live.
 */
function deft_pages_get( $post_id ) {
	$html  = (string) get_post_meta( $post_id, '_deft_page_html', true );
	$head  = (string) get_post_meta( $post_id, '_deft_page_head', true );
	$draft = get_post_meta( $post_id, '_deft_page_draft', true );
	return array(
		'html'      => $html,
		'head'      => $head,
		'hash'      => '' === $html && '' === $head ? '' : deft_pages_hash( $html, $head ),
		'draft'     => is_array( $draft ) && isset( $draft['html'] ) ? $draft : null,
		'published' => (string) get_post_meta( $post_id, '_deft_page_published', true ),
	);
}

/** Who is acting, for revisions: the signed-in user, or the name Studio sends. */
function deft_pages_actor( $actor = '' ) {
	if ( '' !== $actor ) {
		return mb_substr( sanitize_text_field( $actor ), 0, 100 );
	}
	$user = wp_get_current_user();
	return $user->exists() ? $user->display_name : 'Deft Studio';
}

function deft_pages_check_size( $html, $head ) {
	if ( strlen( $html ) + strlen( $head ) > DEFT_PAGES_MAX_BYTES ) {
		return new WP_Error( 'deft_page_too_big', __( 'That page is over 3 MB of HTML. Move images and scripts to files instead of inlining them.', 'deft-core' ), array( 'status' => 413 ) );
	}
	if ( '' === trim( $html ) ) {
		return new WP_Error( 'deft_page_empty', __( 'The page has no HTML.', 'deft-core' ), array( 'status' => 400 ) );
	}
	return true;
}

/**
 * Saves a draft next to the live page. $base is the live hash the editor started from: if the page went live since,
 * the draft is still saved but the result says so (stale), so Studio can show the difference.
 */
function deft_pages_save_draft( $post_id, $html, $head = '', array $args = array() ) {
	$ok = deft_pages_check_size( $html, $head );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$current = deft_pages_get( $post_id );
	$draft   = array(
		'html'   => $html,
		'head'   => $head,
		'hash'   => deft_pages_hash( $html, $head ),
		'base'   => $current['hash'],
		'saved'  => gmdate( 'c' ),
		'by'     => deft_pages_actor( $args['actor'] ?? '' ),
		'source' => sanitize_key( $args['source'] ?? 'admin' ),
		'note'   => mb_substr( sanitize_text_field( $args['note'] ?? '' ), 0, 255 ),
	);
	update_post_meta( $post_id, '_deft_page_draft', wp_slash( $draft ) );
	clean_post_cache( $post_id );
	return array(
		'draft' => array_diff_key( $draft, array( 'html' => 1, 'head' => 1 ) ),
		'stale' => isset( $args['base'] ) && '' !== (string) $args['base'] && (string) $args['base'] !== $current['hash'],
	);
}

function deft_pages_discard_draft( $post_id ) {
	delete_post_meta( $post_id, '_deft_page_draft' );
	clean_post_cache( $post_id );
}

/**
 * Makes a version live. $args:
 *   html, head   the markup to publish; left out, the draft goes live
 *   base         the live hash the change was made against; a different live hash is a conflict (409)
 *   source       studio, live-edit, migration, restore or admin
 *   actor, note, changes (a list of { id, kind, before, after } for the history)
 *   keep_draft   true when a draft should survive (Live Edit fixes a typo while a Studio draft waits)
 * Returns the new revision's id, hash, url and what was cleared, or a WP_Error.
 */
function deft_pages_publish( $post_id, array $args = array() ) {
	$post = get_post( $post_id );
	if ( ! $post || 'page' !== $post->post_type ) {
		return new WP_Error( 'deft_page_missing', __( 'No such page.', 'deft-core' ), array( 'status' => 404 ) );
	}
	$current = deft_pages_get( $post_id );
	if ( isset( $args['base'] ) && '' !== (string) $args['base'] && (string) $args['base'] !== $current['hash'] ) {
		return new WP_Error( 'deft_page_conflict', __( 'Someone changed this page since you opened it. Reload to see their change, then make yours again.', 'deft-core' ), array( 'status' => 409, 'hash' => $current['hash'] ) );
	}
	$from_draft = ! array_key_exists( 'html', $args );
	if ( $from_draft ) {
		if ( ! $current['draft'] ) {
			return new WP_Error( 'deft_page_no_draft', __( 'There is no draft to put live.', 'deft-core' ), array( 'status' => 400 ) );
		}
		$html = (string) $current['draft']['html'];
		$head = (string) $current['draft']['head'];
	} else {
		$html = (string) $args['html'];
		$head = (string) ( $args['head'] ?? $current['head'] );
	}
	$ok = deft_pages_check_size( $html, $head );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$html = deft_html_mark( $html );
	$hash = deft_pages_hash( $html, $head );

	// The first time a page goes live, keep what was there before (an Elementor page's content), so it can be restored.
	deft_pages_install();
	if ( '' === $current['hash'] && ! deft_pages_revisions( $post_id, 1 ) && '' !== trim( (string) $post->post_content ) && ! get_post_meta( $post_id, '_deft_page_original', true ) ) {
		update_post_meta( $post_id, '_deft_page_original', wp_slash( array( 'content' => $post->post_content, 'template' => get_page_template_slug( $post ), 'saved' => gmdate( 'c' ) ) ) );
	}

	update_post_meta( $post_id, '_deft_page_html', wp_slash( $html ) );
	update_post_meta( $post_id, '_deft_page_head', wp_slash( $head ) );
	update_post_meta( $post_id, '_deft_page_published', gmdate( 'c' ) );
	if ( $from_draft || empty( $args['keep_draft'] ) ) {
		delete_post_meta( $post_id, '_deft_page_draft' );
	}
	if ( ! isset( deft_pages_templates()[ get_page_template_slug( $post ) ] ) ) {
		update_post_meta( $post_id, '_wp_page_template', 'deft-canvas' );
	}

	// Search, excerpts and SEO plugins read post_content, so it carries the page's text (never shown: the template
	// prints the stored HTML). Saving also bumps the modified date and keeps WordPress's own revision history going.
	$update = array(
		'ID'           => $post_id,
		'post_content' => deft_pages_search_text( $html ),
	);
	if ( 'publish' !== $post->post_status && ! empty( $args['go_live'] ) ) {
		$update['post_status'] = 'publish';
	}
	wp_update_post( wp_slash( $update ) );

	global $wpdb;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		deft_pages_table(),
		array(
			'post_id' => $post_id,
			'created' => current_time( 'mysql', true ),
			'hash'    => $hash,
			'source'  => sanitize_key( $args['source'] ?? 'admin' ),
			'user_id' => get_current_user_id(),
			'actor'   => deft_pages_actor( $args['actor'] ?? '' ),
			'note'    => mb_substr( sanitize_text_field( $args['note'] ?? '' ), 0, 255 ),
			'changes' => wp_json_encode( array_slice( (array) ( $args['changes'] ?? array() ), 0, 200 ) ),
			'html'    => $html,
			'head'    => $head,
		)
	);
	$revision = (int) $wpdb->insert_id;
	$cleared  = deft_core_flush_caches( $post_id );
	do_action( 'deft_core_page_changed', $post_id, $revision, sanitize_key( $args['source'] ?? 'admin' ) );
	return array(
		'revision' => $revision,
		'hash'     => $hash,
		'url'      => get_permalink( $post_id ),
		'cleared'  => $cleared,
	);
}

/** The visible text of a page, for post_content: headings and paragraphs on their own lines, no markup. */
function deft_pages_search_text( $html ) {
	$html = preg_replace( '/<(script|style|svg|template|noscript)\b[^>]*>.*?<\/\1\s*>/is', ' ', $html );
	$html = preg_replace( '/<\/(h[1-6]|p|li|div|section|blockquote|figcaption|td|th|dt|dd)>/i', "\n", $html );
	$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( "/[ \t]+/", ' ', $text );
	$text = preg_replace( "/\s*\n\s*/", "\n", $text );
	return esc_html( trim( $text ) );
}

/** Versions that went live, newest first, without their markup. */
function deft_pages_revisions( $post_id, $limit = 50 ) {
	if ( DEFT_PAGES_DB_VERSION !== get_option( 'deft_pages_db' ) ) {
		return array();
	}
	global $wpdb;
	$table = deft_pages_table();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, created, hash, source, user_id, actor, note, changes FROM $table WHERE post_id = %d ORDER BY id DESC LIMIT %d", $post_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	return array_map(
		function ( $row ) {
			return array(
				'id'      => (int) $row['id'],
				'created' => mysql2date( 'c', $row['created'], false ),
				'hash'    => $row['hash'],
				'source'  => $row['source'],
				'actor'   => $row['actor'],
				'note'    => $row['note'],
				'changes' => json_decode( $row['changes'], true ) ?: array(),
			);
		},
		(array) $rows
	);
}

function deft_pages_revision( $post_id, $revision_id ) {
	if ( DEFT_PAGES_DB_VERSION !== get_option( 'deft_pages_db' ) ) {
		return null;
	}
	global $wpdb;
	$table = deft_pages_table();
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE post_id = %d AND id = %d", $post_id, $revision_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
}

/** Puts an earlier version back live (as a new version, so the restore itself can be undone). */
function deft_pages_restore( $post_id, $revision_id, array $args = array() ) {
	$row = deft_pages_revision( $post_id, $revision_id );
	if ( ! $row ) {
		return new WP_Error( 'deft_page_no_revision', __( 'That version is not kept any more.', 'deft-core' ), array( 'status' => 404 ) );
	}
	return deft_pages_publish(
		$post_id,
		$args + array(
			'html'       => $row['html'],
			'head'       => $row['head'],
			'source'     => 'restore',
			'note'       => sprintf( /* translators: %s: date */ __( 'Restored the version from %s', 'deft-core' ), get_date_from_gmt( $row['created'], 'M j, g:i a' ) ),
			'keep_draft' => true,
		)
	);
}

/** Creates a page that uses the Deft template, with the markup as its draft. */
function deft_pages_create( array $args ) {
	$title = sanitize_text_field( $args['title'] ?? '' );
	if ( '' === $title ) {
		return new WP_Error( 'deft_page_title', __( 'A new page needs a title.', 'deft-core' ), array( 'status' => 400 ) );
	}
	$ok = deft_pages_check_size( (string) ( $args['html'] ?? '' ), (string) ( $args['head'] ?? '' ) );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$template = isset( deft_pages_templates()[ $args['template'] ?? '' ] ) ? $args['template'] : 'deft-canvas';
	$post_id  = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_name'    => sanitize_title( $args['slug'] ?? $title ),
			'post_parent'  => absint( $args['parent'] ?? 0 ),
			'post_excerpt' => sanitize_textarea_field( $args['description'] ?? '' ),
			'meta_input'   => array( '_wp_page_template' => $template ),
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	$draft = deft_pages_save_draft( $post_id, (string) $args['html'], (string) ( $args['head'] ?? '' ), $args );
	return is_wp_error( $draft ) ? $draft : $post_id;
}

/* ---------- Showing the page ---------- */

add_filter( 'theme_page_templates', 'deft_pages_register_templates' );
function deft_pages_register_templates( $templates ) {
	return array_merge( $templates, deft_pages_templates() );
}

add_filter( 'template_include', 'deft_pages_template', 99 );
function deft_pages_template( $template ) {
	if ( deft_pages_showing() ) {
		return DEFT_CORE_DIR . 'templates/deft-page.php';
	}
	return $template;
}

/**
 * Whether the page being viewed is shown with the Deft template: a Deft page, or an Elementor page whose converted
 * draft is being previewed before it moves over.
 */
function deft_pages_showing() {
	if ( ! is_singular( 'page' ) ) {
		return false;
	}
	$post_id = get_queried_object_id();
	return deft_pages_is( $post_id ) || ( deft_pages_previewing( $post_id ) && get_post_meta( $post_id, '_deft_page_draft', true ) );
}

/** The markup to show for the page being viewed: the draft when previewing, otherwise live. */
function deft_pages_current( $post_id ) {
	$page = deft_pages_get( $post_id );
	if ( deft_pages_previewing( $post_id ) && $page['draft'] ) {
		return array(
			'html'    => deft_html_mark( (string) $page['draft']['html'] ),
			'head'    => (string) $page['draft']['head'],
			'preview' => true,
			'hash'    => (string) ( $page['draft']['hash'] ?? '' ),
		);
	}
	return array( 'html' => $page['html'], 'head' => $page['head'], 'preview' => false, 'hash' => $page['hash'] );
}

add_action( 'wp_head', 'deft_pages_head', 1 );
function deft_pages_head() {
	if ( ! deft_pages_showing() ) {
		return;
	}
	$current = deft_pages_current( get_queried_object_id() );
	echo '<style id="deft-page-base">body.deft-page{margin:0}</style>' . "\n";
	// Which version this is, so Live Edit can check the public page really shows what was just published.
	echo '<meta name="deft-page" content="' . esc_attr( substr( $current['hash'], 0, 12 ) ) . '">' . "\n";
	echo $current['head']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- staff-written markup, stored as-is on purpose.
}

add_filter( 'body_class', 'deft_pages_body_class' );
function deft_pages_body_class( $classes ) {
	if ( deft_pages_showing() ) {
		$classes[] = 'deft-page';
		$classes[] = 'deft-page-' . sanitize_html_class( get_page_template_slug( get_queried_object_id() ) );
	}
	return $classes;
}

/* ---------- Previews ---------- */

/** A link that shows the draft to anyone holding it, for an hour (Studio's before-and-after uses it). */
function deft_pages_preview_url( $post_id, $ttl = HOUR_IN_SECONDS ) {
	$expires = time() + $ttl;
	$sig     = substr( hash_hmac( 'sha256', $post_id . '|' . $expires, wp_salt( 'nonce' ) . 'deft-preview' ), 0, 32 );
	$base    = 'publish' === get_post_status( $post_id ) ? get_permalink( $post_id ) : add_query_arg( 'page_id', $post_id, home_url( '/' ) );
	return add_query_arg( 'deft_preview', $expires . '.' . $sig, $base );
}

function deft_pages_preview_token_ok( $post_id ) {
	$token = isset( $_GET['deft_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['deft_preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! preg_match( '/^(\d+)\.([a-f0-9]{32})$/', $token, $m ) || (int) $m[1] < time() ) {
		return false;
	}
	return hash_equals( substr( hash_hmac( 'sha256', $post_id . '|' . $m[1], wp_salt( 'nonce' ) . 'deft-preview' ), 0, 32 ), $m[2] );
}

/** Whether this request shows the draft: staff with ?deft_preview=1, or anyone with a valid preview link. */
function deft_pages_previewing( $post_id ) {
	if ( ! isset( $_GET['deft_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return false;
	}
	return deft_pages_is_staff() || deft_pages_preview_token_ok( $post_id );
}

// A page that has never been published is a WordPress draft too: let a valid preview link show it.
add_filter( 'posts_results', 'deft_pages_preview_unpublished', 10, 2 );
function deft_pages_preview_unpublished( $posts, $query ) {
	if ( ! isset( $_GET['deft_preview'] ) || ! $query->is_main_query() || 1 !== count( $posts ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $posts;
	}
	$post = $posts[0];
	if ( 'page' === $post->post_type && 'publish' !== $post->post_status && deft_pages_previewing( $post->ID ) && get_post_meta( $post->ID, '_deft_page_draft', true ) ) {
		$post->post_status = 'publish'; // Only for this request's copy; nothing is saved.
		nocache_headers();
	}
	return $posts;
}

// WordPress would "correct" ?page_id=… links to an unpublished page and drop the preview.
add_filter( 'redirect_canonical', 'deft_pages_preview_no_redirect' );
function deft_pages_preview_no_redirect( $redirect ) {
	return isset( $_GET['deft_preview'] ) ? false : $redirect; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

add_filter( 'wp_robots', 'deft_pages_preview_robots' );
function deft_pages_preview_robots( $robots ) {
	if ( is_singular( 'page' ) && deft_pages_previewing( get_queried_object_id() ) ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
	}
	return $robots;
}

add_action( 'template_redirect', 'deft_pages_preview_headers' );
function deft_pages_preview_headers() {
	if ( is_singular( 'page' ) && deft_pages_previewing( get_queried_object_id() ) ) {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_control_set_nocache', 'Deft page preview' );
		}
	}
}

/* ---------- Central: tell it a page changed (it clears Hostinger's CDN) ---------- */

add_action( 'deft_core_page_changed', 'deft_pages_queue_notice', 10, 3 );
function deft_pages_queue_notice( $post_id, $revision, $source ) {
	if ( function_exists( 'deft_central_configured' ) && deft_central_configured() ) {
		wp_schedule_single_event( time(), 'deft_pages_notify_central', array( (int) $post_id, (int) $revision, (string) $source ) );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}
}

add_action( 'deft_pages_notify_central', 'deft_pages_notify_central', 10, 3 );
function deft_pages_notify_central( $post_id, $revision, $source ) {
	deft_central_post(
		array(
			'schema'   => 'deft.page_changed.v1',
			'page'     => array(
				'id'    => (int) $post_id,
				'url'   => get_permalink( $post_id ),
				'title' => get_the_title( $post_id ),
			),
			'revision' => (int) $revision,
			'source'   => $source,
			'site'     => home_url( '/' ),
			'at'       => gmdate( 'c' ),
		)
	);
}

/* ---------- REST API ---------- */

add_action( 'rest_api_init', 'deft_pages_routes' );
function deft_pages_routes() {
	$id   = array( 'id' => array( 'sanitize_callback' => 'absint' ) );
	$perm = 'deft_pages_rest_permission';
	register_rest_route( 'deft/v1', '/pages', array(
		array( 'methods' => 'GET', 'callback' => 'deft_pages_rest_list', 'permission_callback' => $perm ),
		array( 'methods' => 'POST', 'callback' => 'deft_pages_rest_create', 'permission_callback' => $perm ),
	) );
	register_rest_route( 'deft/v1', '/pages/(?P<id>\d+)', array(
		array( 'methods' => 'GET', 'callback' => 'deft_pages_rest_get', 'permission_callback' => $perm, 'args' => $id ),
	) );
	register_rest_route( 'deft/v1', '/pages/(?P<id>\d+)/draft', array(
		array( 'methods' => 'POST', 'callback' => 'deft_pages_rest_draft', 'permission_callback' => $perm, 'args' => $id ),
		array( 'methods' => 'DELETE', 'callback' => 'deft_pages_rest_discard', 'permission_callback' => $perm, 'args' => $id ),
	) );
	register_rest_route( 'deft/v1', '/pages/(?P<id>\d+)/publish', array(
		array( 'methods' => 'POST', 'callback' => 'deft_pages_rest_publish', 'permission_callback' => $perm, 'args' => $id ),
	) );
	register_rest_route( 'deft/v1', '/pages/(?P<id>\d+)/revisions', array(
		array( 'methods' => 'GET', 'callback' => 'deft_pages_rest_revisions', 'permission_callback' => $perm, 'args' => $id ),
	) );
	register_rest_route( 'deft/v1', '/pages/(?P<id>\d+)/revisions/(?P<revision>\d+)', array(
		array( 'methods' => 'GET', 'callback' => 'deft_pages_rest_revision', 'permission_callback' => $perm, 'args' => $id ),
	) );
	register_rest_route( 'deft/v1', '/pages/(?P<id>\d+)/restore', array(
		array( 'methods' => 'POST', 'callback' => 'deft_pages_rest_restore', 'permission_callback' => $perm, 'args' => $id ),
	) );
}

/** Deft staff signed in (with the REST nonce), or Deft Studio with a signed request when publishing is on. */
function deft_pages_rest_permission( WP_REST_Request $request ) {
	if ( is_user_logged_in() ) {
		return deft_pages_is_staff() ? true : new WP_Error( 'deft_not_staff', __( 'Only Deft staff can change Deft pages on this site.', 'deft-core' ), array( 'status' => 403 ) );
	}
	if ( ! deft_core_setting( 'pages_api' ) ) {
		return new WP_Error( 'deft_pages_off', __( 'Deft Studio publishing is switched off on this site (Settings > Deft > Deft pages).', 'deft-core' ), array( 'status' => 403 ) );
	}
	$signed = deft_central_verify_request( $request );
	if ( is_wp_error( $signed ) ) {
		return $signed;
	}
	$request->set_param( '_deft_signed', true );
	return true;
}

/** The page a route points at, if it is a page this API may touch. */
function deft_pages_rest_page( WP_REST_Request $request, $must_be_deft = true ) {
	$post = get_post( (int) $request['id'] );
	if ( ! $post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
		return new WP_Error( 'deft_page_missing', __( 'No such page.', 'deft-core' ), array( 'status' => 404 ) );
	}
	if ( $must_be_deft && ! deft_pages_is( $post ) ) {
		return new WP_Error( 'deft_page_not_deft', __( 'That page doesn\'t use the Deft page template yet. Move it off Elementor first.', 'deft-core' ), array( 'status' => 409 ) );
	}
	return $post;
}

/** The actor and source for a write, from the request. */
function deft_pages_rest_args( WP_REST_Request $request ) {
	$signed = (bool) $request->get_param( '_deft_signed' );
	$person = trim( (string) $request->get_header( 'X-Deft-Actor' ) );
	return array(
		'actor'   => $signed ? ( '' === $person ? 'Deft Studio' : $person . ' (Studio)' ) : '',
		'source'  => $signed ? 'studio' : sanitize_key( $request['source'] ?: 'admin' ),
		'note'    => (string) $request['note'],
		'base'    => (string) $request['base'],
		'changes' => is_array( $request['changes'] ) ? $request['changes'] : array(),
	);
}

/** A page as the API returns it. With $full, the markup and the editable outline too. */
function deft_pages_rest_item( WP_Post $post, $full = false ) {
	$page = deft_pages_get( $post->ID );
	$item = array(
		'id'        => $post->ID,
		'title'     => get_the_title( $post ),
		'slug'      => $post->post_name,
		'status'    => $post->post_status,
		'template'  => get_page_template_slug( $post ),
		'deft'      => deft_pages_is( $post ),
		'url'       => get_permalink( $post ),
		'hash'      => $page['hash'],
		'published' => $page['published'],
		'modified'  => mysql2date( 'c', '0000-00-00 00:00:00' === $post->post_modified_gmt ? $post->post_modified : $post->post_modified_gmt, false ),
		'front'     => (int) get_option( 'page_on_front' ) === $post->ID,
		'draft'     => $page['draft'] ? array_diff_key( $page['draft'], array( 'html' => 1, 'head' => 1 ) ) + array( 'preview' => deft_pages_preview_url( $post->ID ) ) : null,
		'edit'      => admin_url( 'admin.php?page=deft-pages&post=' . $post->ID ),
	);
	if ( $full ) {
		$item['html']    = $page['html'];
		$item['head']    = $page['head'];
		$item['outline'] = deft_html_outline( $page['html'] );
		if ( $page['draft'] ) {
			$item['draft']['html'] = $page['draft']['html'];
			$item['draft']['head'] = $page['draft']['head'];
		}
		$item['original'] = (bool) get_post_meta( $post->ID, '_deft_page_original', true );
	}
	return $item;
}

function deft_pages_rest_list( WP_REST_Request $request ) {
	$query = new WP_Query(
		array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => 500,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => '_wp_page_template', 'value' => array_keys( deft_pages_templates() ), 'compare' => 'IN' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	return rest_ensure_response( array( 'pages' => array_map( 'deft_pages_rest_item', $query->posts ) ) );
}

function deft_pages_rest_get( WP_REST_Request $request ) {
	$post = deft_pages_rest_page( $request );
	return is_wp_error( $post ) ? $post : rest_ensure_response( deft_pages_rest_item( $post, true ) );
}

function deft_pages_rest_create( WP_REST_Request $request ) {
	$post_id = deft_pages_create(
		deft_pages_rest_args( $request ) + array(
			'title'       => (string) $request['title'],
			'slug'        => (string) $request['slug'],
			'parent'      => (int) $request['parent'],
			'template'    => (string) $request['template'],
			'description' => (string) $request['description'],
			'html'        => (string) $request['html'],
			'head'        => (string) $request['head'],
		)
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	$response = rest_ensure_response( deft_pages_rest_item( get_post( $post_id ) ) );
	$response->set_status( 201 );
	return $response;
}

function deft_pages_rest_draft( WP_REST_Request $request ) {
	$post = deft_pages_rest_page( $request, false );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( ! deft_pages_is( $post ) && ! $request['adopt'] ) {
		return new WP_Error( 'deft_page_not_deft', __( 'That page doesn\'t use the Deft page template yet. Send adopt: true to make it a Deft page when the draft goes live.', 'deft-core' ), array( 'status' => 409 ) );
	}
	$page  = deft_pages_get( $post->ID );
	$head  = null === $request['head'] ? ( $page['draft']['head'] ?? $page['head'] ) : (string) $request['head'];
	$saved = deft_pages_save_draft( $post->ID, (string) $request['html'], $head, deft_pages_rest_args( $request ) );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	return rest_ensure_response( $saved + array( 'preview' => deft_pages_preview_url( $post->ID ), 'page' => deft_pages_rest_item( get_post( $post->ID ) ) ) );
}

function deft_pages_rest_discard( WP_REST_Request $request ) {
	$post = deft_pages_rest_page( $request, false );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	deft_pages_discard_draft( $post->ID );
	return rest_ensure_response( array( 'ok' => true, 'page' => deft_pages_rest_item( get_post( $post->ID ) ) ) );
}

function deft_pages_rest_publish( WP_REST_Request $request ) {
	$post = deft_pages_rest_page( $request, false );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$args = deft_pages_rest_args( $request ) + array( 'go_live' => true );
	if ( null !== $request['html'] ) {
		$args['html'] = (string) $request['html'];
		if ( null !== $request['head'] ) {
			$args['head'] = (string) $request['head'];
		}
	}
	$result = deft_pages_publish( $post->ID, $args );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( $result + array( 'page' => deft_pages_rest_item( get_post( $post->ID ) ) ) );
}

function deft_pages_rest_revisions( WP_REST_Request $request ) {
	$post = deft_pages_rest_page( $request, false );
	return is_wp_error( $post ) ? $post : rest_ensure_response( array( 'revisions' => deft_pages_revisions( $post->ID ) ) );
}

function deft_pages_rest_revision( WP_REST_Request $request ) {
	$post = deft_pages_rest_page( $request, false );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$row = deft_pages_revision( $post->ID, (int) $request['revision'] );
	if ( ! $row ) {
		return new WP_Error( 'deft_page_no_revision', __( 'That version is not kept any more.', 'deft-core' ), array( 'status' => 404 ) );
	}
	return rest_ensure_response( array( 'id' => (int) $row['id'], 'created' => mysql2date( 'c', $row['created'], false ), 'html' => $row['html'], 'head' => $row['head'] ) );
}

function deft_pages_rest_restore( WP_REST_Request $request ) {
	$post = deft_pages_rest_page( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$args = deft_pages_rest_args( $request );
	unset( $args['source'], $args['note'], $args['changes'] );
	$result = deft_pages_restore( $post->ID, (int) $request['revision'], $args );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( $result + array( 'page' => deft_pages_rest_item( get_post( $post->ID ) ) ) );
}
