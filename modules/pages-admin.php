<?php
/**
 * The Deft pages screen (wp-admin > Deft pages), for Deft staff only: every Deft page with its draft, HTML and
 * history, a form for new pages, and the Move off Elementor board. The screen is one small script
 * (assets/pages-admin.js) on top of the deft/v1 REST routes in pages.php and migrate.php.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'deft_pages_admin_menu' );
function deft_pages_admin_menu() {
	if ( ! deft_pages_is_staff() ) {
		return;
	}
	$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><circle cx="14" cy="19" r="7.5" fill="none" stroke="#a7aaad" stroke-width="3.6"/><rect x="20" y="4" width="3.6" height="23" rx="1.2" fill="#a7aaad"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	add_menu_page( __( 'Deft pages', 'deft-core' ), __( 'Deft pages', 'deft-core' ), 'manage_options', 'deft-pages', 'deft_pages_admin_screen', $icon, 21 );
	add_submenu_page( 'deft-pages', __( 'Deft pages', 'deft-core' ), __( 'All Deft pages', 'deft-core' ), 'manage_options', 'deft-pages', 'deft_pages_admin_screen' );
	add_submenu_page( 'deft-pages', __( 'Move off Elementor', 'deft-core' ), __( 'Move off Elementor', 'deft-core' ), 'manage_options', 'deft-pages&view=migrate', '__return_null' );
}

// The submenu link above carries &view=migrate; WordPress needs the page slug alone to highlight it.
add_filter( 'submenu_file', 'deft_pages_admin_submenu', 10, 2 );
function deft_pages_admin_submenu( $submenu_file, $parent_file ) {
	if ( 'deft-pages' === $parent_file && isset( $_GET['view'] ) && 'migrate' === $_GET['view'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'deft-pages&view=migrate';
	}
	return $submenu_file;
}

function deft_pages_admin_screen() {
	if ( ! deft_pages_is_staff() ) {
		wp_die( esc_html__( 'Only Deft staff can open this screen.', 'deft-core' ) );
	}
	echo '<div class="wrap deft-pages-wrap"><h1 class="screen-reader-text">' . esc_html__( 'Deft pages', 'deft-core' ) . '</h1><div id="deft-pages-app" aria-live="polite"><p class="deft-loading">' . esc_html__( 'Loading Deft pages…', 'deft-core' ) . '</p></div></div>';
}

add_action( 'admin_enqueue_scripts', 'deft_pages_admin_enqueue' );
function deft_pages_admin_enqueue( $hook ) {
	if ( 'toplevel_page_deft-pages' !== $hook || ! deft_pages_is_staff() ) {
		return;
	}
	$editor = wp_enqueue_code_editor( array( 'type' => 'text/html', 'codemirror' => array( 'lineWrapping' => true, 'lint' => false, 'gutters' => array() ) ) );
	wp_enqueue_style( 'deft-pages-admin', plugins_url( 'assets/pages-admin.css', DEFT_CORE_FILE ), array(), DEFT_CORE_VERSION );
	wp_enqueue_script( 'deft-pages-admin', plugins_url( 'assets/pages-admin.js', DEFT_CORE_FILE ), array(), DEFT_CORE_VERSION, true );
	wp_add_inline_script(
		'deft-pages-admin',
		'window.deftPages = ' . wp_json_encode(
			array(
				'site'      => get_bloginfo( 'name' ),
				'home'      => home_url( '/' ),
				'root'      => esc_url_raw( rest_url( 'deft/v1' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'screen'    => admin_url( 'admin.php?page=deft-pages' ),
				'settings'  => admin_url( 'options-general.php?page=deft-core#deft-pages' ),
				'liveEdit'  => deft_live_enabled(),
				'studio'    => (bool) deft_core_setting( 'pages_api' ),
				'templates' => deft_pages_templates(),
				'editor'    => $editor ?: null,
				'user'      => wp_get_current_user()->display_name,
			)
		) . ';',
		'before'
	);
}

// The compare view shows pages in frames; the admin bar would only get in the way there.
add_filter( 'show_admin_bar', 'deft_pages_admin_frame_bar' );
function deft_pages_admin_frame_bar( $show ) {
	return isset( $_GET['deft_frame'] ) ? false : $show; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

// "Edit" on a Deft page opens the Deft pages screen instead of the block editor, which would rewrite the markup.
add_filter( 'get_edit_post_link', 'deft_pages_admin_edit_link', 10, 2 );
function deft_pages_admin_edit_link( $link, $post_id ) {
	if ( deft_pages_is( $post_id ) && deft_pages_is_staff() ) {
		return admin_url( 'admin.php?page=deft-pages&post=' . (int) $post_id );
	}
	return $link;
}

add_action( 'load-post.php', 'deft_pages_admin_redirect_editor' );
function deft_pages_admin_redirect_editor() {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'edit' === $action && $post_id && deft_pages_is( $post_id ) && ! isset( $_GET['deft_classic'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( deft_pages_is_staff() ? admin_url( 'admin.php?page=deft-pages&post=' . $post_id ) : admin_url( 'edit.php?post_type=page&deft_locked=1' ) );
		exit;
	}
}

add_action( 'admin_notices', 'deft_pages_admin_locked_notice' );
function deft_pages_admin_locked_notice() {
	if ( isset( $_GET['deft_locked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'That page is looked after by Deft Digital. Send us the change you need and we\'ll make it.', 'deft-core' ) . '</p></div>';
	}
}

// Deft pages say so in the Pages list.
add_filter( 'display_post_states', 'deft_pages_admin_states', 10, 2 );
function deft_pages_admin_states( $states, $post ) {
	if ( deft_pages_is( $post ) ) {
		$states['deft'] = get_post_meta( $post->ID, '_deft_page_draft', true ) ? __( 'Deft page · draft waiting', 'deft-core' ) : __( 'Deft page', 'deft-core' );
	}
	return $states;
}

// Quick Edit could switch the template away from Deft by accident; Deft pages keep theirs unless staff change it here.
add_filter( 'page_row_actions', 'deft_pages_admin_row_actions', 10, 2 );
function deft_pages_admin_row_actions( $actions, $post ) {
	if ( ! deft_pages_is( $post ) ) {
		return $actions;
	}
	unset( $actions['inline hide-if-no-js'] );
	if ( deft_pages_is_staff() && deft_live_enabled() && 'publish' === $post->post_status ) {
		$actions['deft_live'] = '<a href="' . esc_url( add_query_arg( 'deft_edit', '1', get_permalink( $post ) ) ) . '">' . esc_html__( 'Live Edit', 'deft-core' ) . '</a>';
	}
	return $actions;
}
