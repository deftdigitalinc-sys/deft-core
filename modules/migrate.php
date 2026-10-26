<?php
/**
 * Moving pages off Elementor, one page at a time.
 *
 * Our client pages were built as custom HTML pasted into an Elementor HTML widget on the Elementor Canvas template.
 * For each page this module:
 * 1. reads the Elementor layout (_elementor_data) and checks it really is HTML widgets only;
 * 2. "Convert" lifts the widgets' HTML out, in order, into a Deft page draft. The live page is untouched, and the draft
 *    can be previewed and compared side by side with the live Elementor page;
 * 3. "Go live" switches the page to the Deft template and publishes the draft. Elementor's own data stays in place, so
 *    "Back to Elementor" restores the page exactly as it was;
 * 4. once every Elementor page on the site is live as a Deft page and nothing else uses Elementor (theme builder
 *    headers and footers, other post types), the board says Elementor can be uninstalled.
 *
 * Pages with other Elementor widgets (headings, images, forms…) are listed as needing attention: their markup depends on
 * Elementor's CSS, so they are rebuilt in Studio instead of converted.
 */

defined( 'ABSPATH' ) || exit;

/** Elementor container types whose own settings we can safely drop when only layout defaults are set. */
const DEFT_MIGRATE_CONTAINERS = array( 'section', 'column', 'container', 'inner-section' );

/** Container settings that change how the page looks, so dropping them shows up as a difference. */
const DEFT_MIGRATE_STYLE_KEYS = array( 'background_background', 'background_color', 'background_image', 'custom_css', 'padding', 'padding_tablet', 'padding_mobile', 'margin', 'border_border', 'box_shadow_box_shadow_type', 'shape_divider_top', 'shape_divider_bottom', 'min_height', 'css_classes', '_element_id' );

/** Whether a page is (or was) built with Elementor. */
function deft_migrate_is_elementor( $post_id ) {
	return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
}

/**
 * What converting a page would do: html (the widgets' HTML joined in order), widgets (count of HTML widgets), other
 * (other widget types and how many), warnings (Elementor styling that would be lost) and convertible.
 */
function deft_migrate_analyze( $post_id ) {
	$raw  = get_post_meta( $post_id, '_elementor_data', true );
	$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
	$out  = array(
		'html'        => '',
		'widgets'     => 0,
		'other'       => array(),
		'warnings'    => array(),
		'convertible' => false,
		'template'    => get_page_template_slug( $post_id ),
	);
	if ( ! is_array( $data ) ) {
		$out['warnings'][] = __( 'Elementor\'s layout for this page could not be read.', 'deft-core' );
		return $out;
	}
	$parts  = array();
	$styled = array();
	$walk   = function ( array $elements ) use ( &$walk, &$out, &$parts, &$styled ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$type     = (string) ( $element['elType'] ?? '' );
			$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
			if ( 'widget' === $type ) {
				$widget = (string) ( $element['widgetType'] ?? 'unknown' );
				if ( 'html' === $widget ) {
					$out['widgets']++;
					$parts[] = (string) ( $settings['html'] ?? '' );
				} else {
					$out['other'][ $widget ] = ( $out['other'][ $widget ] ?? 0 ) + 1;
				}
				foreach ( array( '_css_classes', '_element_id', 'custom_css', '_padding', '_margin', '_background_background' ) as $key ) {
					if ( ! empty( $settings[ $key ] ) && ! deft_migrate_empty_setting( $settings[ $key ] ) ) {
						$styled[ 'widget:' . $key ] = true;
					}
				}
			} elseif ( in_array( $type, DEFT_MIGRATE_CONTAINERS, true ) ) {
				foreach ( DEFT_MIGRATE_STYLE_KEYS as $key ) {
					if ( isset( $settings[ $key ] ) && ! deft_migrate_empty_setting( $settings[ $key ] ) ) {
						$styled[ $key ] = true;
					}
				}
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$walk( $element['elements'] );
			}
		}
	};
	$walk( $data );
	$out['html'] = trim( implode( "\n", $parts ) );
	if ( $styled ) {
		$out['warnings'][] = sprintf(
			/* translators: %s: setting names */
			__( 'Elementor styling on the sections or widgets will be dropped (%s). Compare before going live.', 'deft-core' ),
			implode( ', ', array_map( function ( $key ) {
				return str_replace( array( 'widget:', '_' ), array( '', ' ' ), trim( $key, '_' ) );
			}, array_keys( $styled ) ) )
		);
	}
	if ( preg_match( '/\[[a-z0-9_-]+[^\]]*\]/i', $out['html'] ) ) {
		$out['warnings'][] = __( 'The HTML has something that looks like a [shortcode]. Elementor\'s HTML widget never ran shortcodes either, so check it shows the same.', 'deft-core' );
	}
	if ( preg_match( '/class="[^"]*\belementor-/i', $out['html'] ) ) {
		$out['warnings'][] = __( 'The HTML uses elementor- classes, which lose their styling once Elementor is gone.', 'deft-core' );
	}
	$out['convertible'] = $out['widgets'] > 0 && ! $out['other'] && '' !== $out['html'];
	if ( ! $out['widgets'] ) {
		$out['warnings'][] = __( 'There is no HTML widget on this page, so there is nothing to lift out. Rebuild it in Studio.', 'deft-core' );
	}
	return $out;
}

/** Elementor stores unset controls as empty strings or arrays of empty values. */
function deft_migrate_empty_setting( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $key => $part ) {
			if ( in_array( $key, array( 'unit', 'isLinked', 'sizes' ), true ) ) {
				continue;
			}
			if ( ! deft_migrate_empty_setting( $part ) ) {
				return false;
			}
		}
		return true;
	}
	return '' === trim( (string) $value ) || '0' === (string) $value;
}

/** Where a page stands: elementor, converted (draft waiting), live (Deft page), or attention (can't convert). */
function deft_migrate_status( $post_id, array $analysis = null ) {
	if ( deft_pages_is( $post_id ) ) {
		return get_post_meta( $post_id, '_deft_migrated_from', true ) ? 'live' : 'deft';
	}
	if ( get_post_meta( $post_id, '_deft_page_draft', true ) ) {
		return 'converted';
	}
	$analysis = $analysis ?? deft_migrate_analyze( $post_id );
	return $analysis['convertible'] ? 'elementor' : 'attention';
}

/** The Deft template a page moves to: a bare canvas when it used Elementor Canvas, else with the theme's header and footer. */
function deft_migrate_target_template( $post_id ) {
	return in_array( get_page_template_slug( $post_id ), array( 'elementor_canvas', '' ), true ) ? 'deft-canvas' : 'deft-theme';
}

function deft_migrate_convert( $post_id ) {
	if ( deft_pages_is( $post_id ) ) {
		return new WP_Error( 'deft_migrate_done', __( 'This page is already a Deft page.', 'deft-core' ), array( 'status' => 409 ) );
	}
	$analysis = deft_migrate_analyze( $post_id );
	if ( ! $analysis['convertible'] ) {
		return new WP_Error( 'deft_migrate_not_convertible', __( 'This page uses Elementor widgets other than HTML, so it needs rebuilding in Studio.', 'deft-core' ), array( 'status' => 422, 'other' => $analysis['other'] ) );
	}
	update_post_meta( $post_id, '_deft_page_template_next', deft_migrate_target_template( $post_id ) );
	$saved = deft_pages_save_draft( $post_id, $analysis['html'], '', array( 'source' => 'migration', 'note' => __( 'Lifted out of Elementor', 'deft-core' ) ) );
	return is_wp_error( $saved ) ? $saved : $analysis;
}

function deft_migrate_go_live( $post_id ) {
	$draft = get_post_meta( $post_id, '_deft_page_draft', true );
	if ( ! is_array( $draft ) ) {
		return new WP_Error( 'deft_migrate_no_draft', __( 'Convert the page first.', 'deft-core' ), array( 'status' => 409 ) );
	}
	$before = array(
		'template'  => get_page_template_slug( $post_id ),
		'edit_mode' => (string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
		'moved'     => gmdate( 'c' ),
		'by'        => deft_pages_actor(),
	);
	update_post_meta( $post_id, '_deft_migrated_from', wp_slash( $before ) );
	update_post_meta( $post_id, '_wp_page_template', get_post_meta( $post_id, '_deft_page_template_next', true ) ?: deft_migrate_target_template( $post_id ) );
	delete_post_meta( $post_id, '_deft_page_template_next' );
	// Elementor's data stays (for Back to Elementor), but Elementor no longer treats the page as its own.
	delete_post_meta( $post_id, '_elementor_edit_mode' );
	$result = deft_pages_publish( $post_id, array( 'source' => 'migration', 'note' => __( 'Moved off Elementor', 'deft-core' ) ) );
	if ( is_wp_error( $result ) ) {
		deft_migrate_back( $post_id );
	}
	return $result;
}

/** Back to Elementor: the template and builder mode it had, with the Deft markup kept for another try. */
function deft_migrate_back( $post_id ) {
	$before = get_post_meta( $post_id, '_deft_migrated_from', true );
	if ( ! is_array( $before ) ) {
		if ( get_post_meta( $post_id, '_deft_page_draft', true ) && ! deft_pages_is( $post_id ) ) {
			deft_pages_discard_draft( $post_id );
			delete_post_meta( $post_id, '_deft_page_template_next' );
			return true;
		}
		return new WP_Error( 'deft_migrate_not_moved', __( 'This page wasn\'t moved off Elementor here.', 'deft-core' ), array( 'status' => 409 ) );
	}
	update_post_meta( $post_id, '_wp_page_template', $before['template'] ?: 'default' );
	if ( '' !== $before['edit_mode'] ) {
		update_post_meta( $post_id, '_elementor_edit_mode', $before['edit_mode'] );
	}
	delete_post_meta( $post_id, '_deft_migrated_from' );
	deft_core_flush_caches( $post_id );
	return true;
}

/** What still uses Elementor besides the pages on the board. */
function deft_migrate_blockers() {
	global $wpdb;
	$blockers = array();
	$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT p.post_type, COUNT(*) AS n FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
		WHERE m.meta_key = '_elementor_edit_mode' AND m.meta_value = 'builder' AND p.post_status IN ('publish','private','future')
		AND p.post_type NOT IN ('page','revision') GROUP BY p.post_type",
		ARRAY_A
	);
	foreach ( (array) $rows as $row ) {
		if ( 'elementor_library' === $row['post_type'] ) {
			$blockers[] = sprintf( /* translators: %d: count */ _n( '%d Elementor template (a theme builder header, footer or popup may be in use)', '%d Elementor templates (theme builder headers, footers or popups may be in use)', (int) $row['n'], 'deft-core' ), (int) $row['n'] );
		} else {
			$object     = get_post_type_object( $row['post_type'] );
			$blockers[] = sprintf( /* translators: 1: count, 2: post type */ __( '%1$d %2$s built with Elementor', 'deft-core' ), (int) $row['n'], $object ? strtolower( $object->labels->name ) : $row['post_type'] );
		}
	}
	return $blockers;
}

/** The whole board: every Elementor page (or page that was one), its status and what converting it would do. */
function deft_migrate_board() {
	global $wpdb;
	$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
		WHERE p.post_type = 'page' AND p.post_status IN ('publish','private','draft','pending','future')
		AND ( ( m.meta_key = '_elementor_edit_mode' AND m.meta_value = 'builder' ) OR m.meta_key = '_deft_migrated_from' )
		ORDER BY p.menu_order, p.post_title"
	);
	$pages = array();
	$count = array( 'elementor' => 0, 'attention' => 0, 'converted' => 0, 'live' => 0 );
	foreach ( $ids as $id ) {
		$id       = (int) $id;
		$analysis = deft_pages_is( $id ) ? null : deft_migrate_analyze( $id );
		$status   = deft_migrate_status( $id, $analysis );
		if ( 'deft' === $status ) {
			continue;
		}
		$count[ $status ]++;
		$from    = get_post_meta( $id, '_deft_migrated_from', true );
		$pages[] = array(
			'id'       => $id,
			'title'    => get_the_title( $id ),
			'url'      => get_permalink( $id ),
			'status'   => $status,
			'front'    => (int) get_option( 'page_on_front' ) === $id,
			'wpstatus' => get_post_status( $id ),
			'widgets'  => $analysis ? $analysis['widgets'] : null,
			'other'    => $analysis ? $analysis['other'] : array(),
			'warnings' => $analysis ? $analysis['warnings'] : array(),
			'bytes'    => $analysis ? strlen( $analysis['html'] ) : strlen( (string) get_post_meta( $id, '_deft_page_html', true ) ),
			'preview'  => 'converted' === $status ? deft_pages_preview_url( $id ) : null,
			'target'   => deft_pages_is( $id ) ? get_page_template_slug( $id ) : ( get_post_meta( $id, '_deft_page_template_next', true ) ?: deft_migrate_target_template( $id ) ),
			'moved'    => is_array( $from ) ? $from['moved'] : null,
		);
	}
	$blockers = deft_migrate_blockers();
	$left     = $count['elementor'] + $count['attention'] + $count['converted'];
	return array(
		'pages'     => $pages,
		'count'     => $count,
		'blockers'  => $blockers,
		'elementor' => array(
			'active'    => defined( 'ELEMENTOR_VERSION' ),
			'version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'pro'       => defined( 'ELEMENTOR_PRO_VERSION' ),
			'removable' => 0 === $left && ! $blockers && $count['live'] > 0,
		),
	);
}

/* ---------- REST ---------- */

add_action( 'rest_api_init', 'deft_migrate_routes' );
function deft_migrate_routes() {
	$id = array( 'id' => array( 'sanitize_callback' => 'absint' ) );
	register_rest_route( 'deft/v1', '/migration', array( 'methods' => 'GET', 'callback' => 'deft_migrate_rest_board', 'permission_callback' => 'deft_pages_rest_permission' ) );
	foreach ( array( 'convert', 'live', 'back' ) as $action ) {
		register_rest_route( 'deft/v1', '/migration/(?P<id>\d+)/' . $action, array( 'methods' => 'POST', 'callback' => 'deft_migrate_rest_' . $action, 'permission_callback' => 'deft_pages_rest_permission', 'args' => $id ) );
	}
}

function deft_migrate_rest_board() {
	return rest_ensure_response( deft_migrate_board() );
}

function deft_migrate_rest_page( WP_REST_Request $request ) {
	$post = get_post( (int) $request['id'] );
	if ( ! $post || 'page' !== $post->post_type ) {
		return new WP_Error( 'deft_page_missing', __( 'No such page.', 'deft-core' ), array( 'status' => 404 ) );
	}
	return $post;
}

function deft_migrate_rest_convert( WP_REST_Request $request ) {
	$post = deft_migrate_rest_page( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$result = deft_migrate_convert( $post->ID );
	return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true, 'preview' => deft_pages_preview_url( $post->ID ), 'warnings' => $result['warnings'], 'board' => deft_migrate_board() ) );
}

function deft_migrate_rest_live( WP_REST_Request $request ) {
	$post = deft_migrate_rest_page( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$result = deft_migrate_go_live( $post->ID );
	return is_wp_error( $result ) ? $result : rest_ensure_response( $result + array( 'board' => deft_migrate_board() ) );
}

function deft_migrate_rest_back( WP_REST_Request $request ) {
	$post = deft_migrate_rest_page( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$result = deft_migrate_back( $post->ID );
	return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true, 'board' => deft_migrate_board() ) );
}
