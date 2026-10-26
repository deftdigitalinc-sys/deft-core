<?php
/**
 * Live Edit (off by default): Deft staff change text, images and links on a live Deft page, in place.
 *
 * Signed-in staff see a small dock at the bottom of any Deft page. Press Edit (or E): the editable parts outline on
 * hover, a click lets you type in place, images swap from the Media Library. Review lists every change as a before and
 * after; Publish saves a new version (deft_pages_publish), clears the cache and says "Live" with how long it took.
 * Revisions lists the last 30 days of versions, each one a click from being restored. Phone shows the page at 390px
 * with the unsaved changes in it, and "ask Claude" sends one block of words to Central for a version (staff only).
 *
 * Only elements with a data-deft-edit marker can change, and only their text (inline tags), image or link address
 * (includes/html.php). The page's design, scripts and styles are never sent back from the browser.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'deft_core_defaults', 'deft_live_defaults' );
function deft_live_defaults( $defaults ) {
	return $defaults + array( 'live_edit' => 0 );
}

add_filter( 'deft_core_sanitize', 'deft_live_sanitize', 10, 2 );
function deft_live_sanitize( $out, $input ) {
	$out['live_edit'] = empty( $input['live_edit'] ) ? 0 : 1;
	return $out;
}

add_action( 'deft_core_settings_sections', 'deft_live_settings_fields', 6 );
function deft_live_settings_fields( $s ) {
	?>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><?php esc_html_e( 'Live Edit', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[live_edit]" value="1" <?php checked( $s['live_edit'] ); ?>> <?php esc_html_e( 'Let Deft staff change text, images and links on live Deft pages', 'deft-core' ); ?></label>
			<p class="description"><?php esc_html_e( 'Staff see an Edit button at the bottom of each Deft page. Every publish is a new version you can undo for 30 days.', 'deft-core' ); ?></p></td></tr>
	</table>
	<?php
}

function deft_live_enabled() {
	return (bool) deft_core_setting( 'live_edit' ) && ! ( defined( 'DEFT_LIVE_EDIT_DISABLED' ) && DEFT_LIVE_EDIT_DISABLED );
}

/** Whether the current visitor gets Live Edit on the page being viewed. */
function deft_live_available() {
	return deft_live_enabled() && is_singular( 'page' ) && deft_pages_is( get_queried_object_id() ) && deft_pages_is_staff()
		&& ! deft_pages_previewing( get_queried_object_id() ) && ! is_customize_preview() && ! isset( $_GET['deft_frame'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

add_action( 'wp_enqueue_scripts', 'deft_live_enqueue' );
function deft_live_enqueue() {
	if ( ! deft_live_available() ) {
		return;
	}
	$post_id = get_queried_object_id();
	$page    = deft_pages_get( $post_id );
	$user    = wp_get_current_user();
	wp_enqueue_media(); // The Media Library picker for swapping images.
	wp_enqueue_script( 'deft-live-edit', plugins_url( 'assets/live-edit.js', DEFT_CORE_FILE ), array(), DEFT_CORE_VERSION, true );
	wp_add_inline_script(
		'deft-live-edit',
		'window.deftLiveEdit = ' . wp_json_encode(
			array(
				'post'      => $post_id,
				'title'     => get_the_title( $post_id ),
				'base'      => $page['hash'],
				'draft'     => $page['draft'] ? array( 'by' => $page['draft']['by'], 'saved' => $page['draft']['saved'] ) : null,
				'rest'      => esc_url_raw( rest_url( 'deft/v1/pages/' . $post_id ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'css'       => plugins_url( 'assets/live-edit.css', DEFT_CORE_FILE ) . '?ver=' . DEFT_CORE_VERSION,
				'fonts'     => array(
					'sans'  => plugins_url( 'assets/fonts/Geist.woff2', DEFT_CORE_FILE ),
					'label' => plugins_url( 'assets/fonts/Archivo-SemiExpanded.woff2', DEFT_CORE_FILE ),
				),
				'admin'     => admin_url( 'admin.php?page=deft-pages&post=' . $post_id ),
				'user'      => $user->display_name,
				'ask'       => deft_central_configured(),
				'autostart' => isset( $_GET['deft_edit'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			)
		) . ';',
		'before'
	);
}

// The admin bar's own "Edit Page" would open the Deft pages screen; add a direct way into Live Edit next to it.
add_action( 'admin_bar_menu', 'deft_live_admin_bar', 81 );
function deft_live_admin_bar( WP_Admin_Bar $bar ) {
	if ( ! deft_live_available() ) {
		return;
	}
	$bar->add_node(
		array(
			'id'    => 'deft-live-edit',
			'title' => '<span class="ab-icon dashicons dashicons-edit-large" style="top:2px"></span>' . esc_html__( 'Live Edit', 'deft-core' ),
			'href'  => '#deft-live-edit',
			'meta'  => array( 'title' => __( 'Edit this page in place (E)', 'deft-core' ) ),
		)
	);
}

add_action( 'rest_api_init', 'deft_live_routes' );
function deft_live_routes() {
	register_rest_route(
		'deft/v1',
		'/pages/(?P<id>\d+)/edits',
		array(
			'methods'             => 'POST',
			'callback'            => 'deft_live_rest_edits',
			'permission_callback' => 'deft_live_rest_permission',
			'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
		)
	);
	register_rest_route(
		'deft/v1',
		'/pages/(?P<id>\d+)/suggest',
		array(
			'methods'             => 'POST',
			'callback'            => 'deft_live_rest_suggest',
			'permission_callback' => 'deft_live_rest_staff',
			'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
		)
	);
}

/** Signed-in Deft staff with Live Edit on. Central never calls this one. */
function deft_live_rest_staff() {
	if ( ! is_user_logged_in() || ! deft_pages_is_staff() ) {
		return new WP_Error( 'deft_not_staff', __( 'Only Deft staff can use Live Edit on this site.', 'deft-core' ), array( 'status' => 403 ) );
	}
	if ( ! deft_live_enabled() ) {
		return new WP_Error( 'deft_live_off', __( 'Live Edit is switched off on this site (Settings > Deft > Deft pages).', 'deft-core' ), array( 'status' => 403 ) );
	}
	return true;
}

/** Staff signed in with Live Edit on, or Central (signed) once Studio publishing is on: Claude's "make it shorter" uses this too. */
function deft_live_rest_permission( WP_REST_Request $request ) {
	if ( is_user_logged_in() && ! deft_live_enabled() ) {
		return new WP_Error( 'deft_live_off', __( 'Live Edit is switched off on this site (Settings > Deft > Deft pages).', 'deft-core' ), array( 'status' => 403 ) );
	}
	return deft_pages_rest_permission( $request );
}

/**
 * Applies a set of edits to the live page and publishes it as a new version. Body: base (the hash the editor loaded),
 * edits (see deft_html_apply_edits), note (optional, for the history). The same edits go into a waiting Studio draft
 * where its elements match, so the draft doesn't undo them when it goes live later.
 */
function deft_live_rest_edits( WP_REST_Request $request ) {
	$started = microtime( true );
	$post    = deft_pages_rest_page( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$edits = $request['edits'];
	if ( ! is_array( $edits ) || ! $edits || count( $edits ) > 200 ) {
		return new WP_Error( 'deft_edit_none', __( 'Send between 1 and 200 edits.', 'deft-core' ), array( 'status' => 400 ) );
	}
	$page = deft_pages_get( $post->ID );
	if ( (string) $request['base'] !== $page['hash'] ) {
		return new WP_Error( 'deft_page_conflict', __( 'This page changed since you opened it. Reload to get the new version; your edits are kept and put back for you.', 'deft-core' ), array( 'status' => 409, 'hash' => $page['hash'] ) );
	}
	$applied = deft_html_apply_edits( $page['html'], array_values( $edits ) );
	if ( is_wp_error( $applied ) ) {
		return $applied;
	}
	if ( ! $applied['changes'] ) {
		return rest_ensure_response( array( 'unchanged' => true, 'hash' => $page['hash'] ) );
	}

	$draft_updated = false;
	if ( $page['draft'] ) {
		$draft_html = (string) $page['draft']['html'];
		$present    = deft_html_index( $draft_html );
		$matching   = array_values( array_filter( $edits, function ( $edit ) use ( $present ) {
			return isset( $edit['id'] ) && isset( $present[ (string) $edit['id'] ] );
		} ) );
		$in_draft = $matching ? deft_html_apply_edits( $draft_html, $matching ) : null;
		if ( $in_draft && ! is_wp_error( $in_draft ) && $in_draft['html'] !== $draft_html ) {
			$draft          = $page['draft'];
			$draft['html']  = $in_draft['html'];
			$draft['hash']  = deft_pages_hash( $draft['html'], (string) $draft['head'] );
			update_post_meta( $post->ID, '_deft_page_draft', wp_slash( $draft ) );
			$draft_updated = true;
		}
	}

	$args   = deft_pages_rest_args( $request );
	$result = deft_pages_publish(
		$post->ID,
		array(
			'html'       => $applied['html'],
			'base'       => $page['hash'],
			'source'     => 'live-edit',
			'actor'      => $args['actor'],
			'note'       => $args['note'] ?: deft_live_summary( $applied['changes'] ),
			'changes'    => $applied['changes'],
			'keep_draft' => true,
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response(
		$result + array(
			'changes'       => $applied['changes'],
			'draft_updated' => $draft_updated,
			'ms'            => (int) round( ( microtime( true ) - $started ) * 1000 ),
		)
	);
}

/**
 * "Ask Claude for a version" of one block of words. Body: tag, text (plain), ask. Signed to Central as
 * deft.copy_suggest.v1, which writes it from what's on file for this client. Nothing changes here until the staff
 * member picks Use this and publishes.
 */
function deft_live_rest_suggest( WP_REST_Request $request ) {
	$post = deft_pages_rest_page( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( ! deft_central_configured() ) {
		return new WP_Error( 'deft_central_off', __( 'Connect this site to Deft Central first (Settings > Deft).', 'deft-core' ), array( 'status' => 409 ) );
	}
	$text = trim( sanitize_textarea_field( (string) $request['text'] ) );
	$ask  = trim( sanitize_text_field( (string) $request['ask'] ) );
	if ( '' === $text || '' === $ask ) {
		return new WP_Error( 'deft_suggest_empty', __( 'Pick some words and say what the new version should do.', 'deft-core' ), array( 'status' => 400 ) );
	}
	$user  = wp_get_current_user();
	$reply = deft_central_post(
		array(
			'schema'  => 'deft.copy_suggest.v1',
			'page'    => array( 'id' => $post->ID, 'title' => get_the_title( $post ), 'url' => get_permalink( $post ) ),
			'element' => array( 'tag' => sanitize_key( (string) $request['tag'] ), 'text' => mb_substr( $text, 0, 4000 ) ),
			'ask'     => mb_substr( $ask, 0, 500 ),
			'actor'   => $user->display_name,
		),
		60
	);
	if ( ! $reply['ok'] ) {
		$message = $reply['body']['message'] ?? __( 'Claude could not answer just now. Your words are as they were.', 'deft-core' );
		return new WP_Error( 'deft_suggest_failed', $message, array( 'status' => 502, 'code' => $reply['body']['code'] ?? '' ) );
	}
	return rest_ensure_response(
		array(
			'text' => sanitize_textarea_field( (string) ( $reply['body']['text'] ?? '' ) ),
			'note' => sanitize_text_field( (string) ( $reply['body']['note'] ?? '' ) ),
		)
	);
}

/** "Headline and 2 more" style summary for the history. */
function deft_live_summary( array $changes ) {
	$first = $changes[0];
	$label = 'image' === $first['kind'] ? __( 'Image', 'deft-core' ) : ( 'link' === $first['kind'] ? __( 'Link', 'deft-core' ) : '"' . wp_trim_words( $first['after'], 6, '…' ) . '"' );
	$more  = count( $changes ) - 1;
	/* translators: 1: first change, 2: number of other changes */
	return $more ? sprintf( _n( '%1$s and %2$d more change', '%1$s and %2$d more changes', $more, 'deft-core' ), $label, $more ) : $label;
}
