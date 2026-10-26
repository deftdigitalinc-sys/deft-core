<?php
/**
 * Moving pages off Elementor, one page at a time.
 *
 * Our client pages were built as custom HTML pasted into an Elementor HTML widget on the Elementor Canvas template.
 * For each page this module:
 * 1. reads the Elementor layout (_elementor_data) and checks it really is HTML widgets only;
 * 2. "Convert" lifts the widgets' HTML out, in order, into a Deft page draft. The live page is untouched, and the draft
 *    can be previewed and compared side by side with the live Elementor page;
 * 3. "Check": the board loads the live page and the draft at phone, tablet and desktop widths and compares what renders
 *    (height, words, images, links, where the headings and images sit). A draft that matches is "Checked"; one with
 *    differences stays "Converted" with the differences listed, and can still go live ("Go live anyway");
 * 4. "Go live" switches the page to the Deft template and publishes the draft. Elementor's own data stays in place, so
 *    "Back to Elementor" restores the page exactly as it was;
 * 5. once every Elementor page on the site is live as a Deft page and nothing else uses Elementor (theme builder
 *    headers and footers, other post types), Elementor can be removed: with a backup confirmed and Deft staff's OK,
 *    Elementor is deactivated, every Deft page is loaded as a visitor would, and only if they all pass is it
 *    uninstalled. If any page fails, Elementor goes straight back on.
 *
 * A site that has been hacked is locked ("Cleaning first") until it is clean, so nothing infected is copied into a Deft
 * page: when Deft staff tick "This site is being cleaned", or the site watch finds spam posts or PHP files in uploads.
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

/** Elementor's plugin files, the free one first. */
const DEFT_MIGRATE_ELEMENTOR_PLUGINS = array( 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' );

/**
 * Where a page stands: elementor, converted (draft waiting), checked (draft matches the live page), live (Deft page), or
 * attention (can't convert).
 */
function deft_migrate_status( $post_id, array $analysis = null ) {
	if ( deft_pages_is( $post_id ) ) {
		return get_post_meta( $post_id, '_deft_migrated_from', true ) ? 'live' : 'deft';
	}
	$draft = get_post_meta( $post_id, '_deft_page_draft', true );
	if ( $draft ) {
		$check = deft_migrate_check_result( $post_id );
		return $check && $check['ok'] ? 'checked' : 'converted';
	}
	$analysis = $analysis ?? deft_migrate_analyze( $post_id );
	return $analysis['convertible'] ? 'elementor' : 'attention';
}

/** The Deft template a page moves to: a bare canvas when it used Elementor Canvas, else with the theme's header and footer. */
function deft_migrate_target_template( $post_id ) {
	return in_array( get_page_template_slug( $post_id ), array( 'elementor_canvas', '' ), true ) ? 'deft-canvas' : 'deft-theme';
}

/**
 * Why the site is locked for the move off Elementor, or '' when it isn't: Deft staff ticked "This site is being
 * cleaned", or the site watch finds spam posts or PHP files in uploads (checked at most once an hour).
 */
function deft_migrate_cleaning() {
	if ( deft_core_setting( 'cleaning' ) ) {
		return __( 'Deft staff marked this site as being cleaned (Settings > Deft > Site watch).', 'deft-core' );
	}
	$signs = get_transient( 'deft_migrate_hack_signs' );
	if ( ! is_array( $signs ) ) {
		$spam    = deft_watch_spam_posts();
		$uploads = deft_watch_uploads_php();
		$signs   = array( 'spam' => $spam['count'], 'uploads' => $uploads['count'] );
		set_transient( 'deft_migrate_hack_signs', $signs, HOUR_IN_SECONDS );
	}
	$why = array();
	if ( $signs['spam'] ) {
		/* translators: %d: count */
		$why[] = sprintf( _n( '%d spam post is published', '%d spam posts are published', $signs['spam'], 'deft-core' ), $signs['spam'] );
	}
	if ( $signs['uploads'] ) {
		/* translators: %d: count */
		$why[] = sprintf( _n( '%d PHP file is in uploads', '%d PHP files are in uploads', $signs['uploads'], 'deft-core' ), $signs['uploads'] );
	}
	return $why ? sprintf( /* translators: %s: what was found */ __( 'The site watch found signs of a hack: %s.', 'deft-core' ), implode( ', ', $why ) ) : '';
}

function deft_migrate_locked_error() {
	$why = deft_migrate_cleaning();
	return '' === $why ? null : new WP_Error( 'deft_cleaning_first', __( 'This site is being cleaned first, so nothing infected is copied into a Deft page.', 'deft-core' ) . ' ' . $why, array( 'status' => 423 ) );
}

/** The last check of a page's draft, if it was made against the draft that is waiting now. */
function deft_migrate_check_result( $post_id ) {
	$check = get_post_meta( $post_id, '_deft_migrate_check', true );
	$draft = get_post_meta( $post_id, '_deft_page_draft', true );
	if ( ! is_array( $check ) || ! is_array( $draft ) || ( $check['hash'] ?? '' ) !== ( $draft['hash'] ?? '' ) ) {
		return null;
	}
	return $check;
}

/**
 * Saves what the board found comparing the live page and the draft. $sizes maps phone/tablet/desktop to the
 * differences found at that width (plain sentences); a draft matches when none were found at any size.
 */
function deft_migrate_save_check( $post_id, array $sizes ) {
	$draft = get_post_meta( $post_id, '_deft_page_draft', true );
	if ( ! is_array( $draft ) ) {
		return new WP_Error( 'deft_migrate_no_draft', __( 'Convert the page first.', 'deft-core' ), array( 'status' => 409 ) );
	}
	$clean = array();
	foreach ( array( 'phone', 'tablet', 'desktop' ) as $size ) {
		$issues          = isset( $sizes[ $size ] ) && is_array( $sizes[ $size ] ) ? $sizes[ $size ] : array();
		$clean[ $size ] = array_values( array_filter( array_map( function ( $issue ) {
			return mb_substr( sanitize_text_field( (string) $issue ), 0, 240 );
		}, array_slice( $issues, 0, 12 ) ) ) );
	}
	$check = array(
		'hash'  => (string) $draft['hash'],
		'ok'    => ! array_filter( $clean ),
		'sizes' => $clean,
		'at'    => gmdate( 'c' ),
		'by'    => deft_pages_actor(),
	);
	update_post_meta( $post_id, '_deft_migrate_check', wp_slash( $check ) );
	return $check;
}

function deft_migrate_convert( $post_id ) {
	$locked = deft_migrate_locked_error();
	if ( $locked ) {
		return $locked;
	}
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
	$locked = deft_migrate_locked_error();
	if ( $locked ) {
		return $locked;
	}
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
	$checked = deft_migrate_check_result( $post_id );
	$note    = $checked && $checked['ok'] ? __( 'Moved off Elementor', 'deft-core' ) : __( 'Moved off Elementor without a passing check', 'deft-core' );
	$result  = deft_pages_publish( $post_id, array( 'source' => 'migration', 'note' => $note ) );
	if ( is_wp_error( $result ) ) {
		deft_migrate_back( $post_id );
		return $result;
	}
	delete_post_meta( $post_id, '_deft_migrate_check' );
	return $result;
}

/** Back to Elementor: the template and builder mode it had, with the Deft markup kept for another try. */
function deft_migrate_back( $post_id ) {
	$before = get_post_meta( $post_id, '_deft_migrated_from', true );
	if ( ! is_array( $before ) ) {
		if ( get_post_meta( $post_id, '_deft_page_draft', true ) && ! deft_pages_is( $post_id ) ) {
			deft_pages_discard_draft( $post_id );
			delete_post_meta( $post_id, '_deft_page_template_next' );
			delete_post_meta( $post_id, '_deft_migrate_check' );
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
	$count = array( 'elementor' => 0, 'attention' => 0, 'converted' => 0, 'checked' => 0, 'live' => 0 );
	foreach ( $ids as $id ) {
		$id       = (int) $id;
		$analysis = deft_pages_is( $id ) ? null : deft_migrate_analyze( $id );
		$status   = deft_migrate_status( $id, $analysis );
		if ( 'deft' === $status ) {
			continue;
		}
		$count[ $status ]++;
		$from    = get_post_meta( $id, '_deft_migrated_from', true );
		$check   = in_array( $status, array( 'converted', 'checked' ), true ) ? deft_migrate_check_result( $id ) : null;
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
			'preview'  => in_array( $status, array( 'converted', 'checked' ), true ) ? deft_pages_preview_url( $id ) : null,
			'check'    => $check ? array_diff_key( $check, array( 'hash' => 1 ) ) : null,
			'target'   => deft_pages_is( $id ) ? get_page_template_slug( $id ) : ( get_post_meta( $id, '_deft_page_template_next', true ) ?: deft_migrate_target_template( $id ) ),
			'moved'    => is_array( $from ) ? $from['moved'] : null,
		);
	}
	$blockers  = deft_migrate_blockers();
	$left      = $count['elementor'] + $count['attention'] + $count['converted'] + $count['checked'];
	$installed = deft_migrate_elementor_installed();
	$cleaning  = deft_migrate_cleaning();
	return array(
		'pages'     => $pages,
		'count'     => $count,
		'blockers'  => $blockers,
		'cleaning'  => '' === $cleaning ? null : $cleaning,
		'elementor' => array(
			'active'    => defined( 'ELEMENTOR_VERSION' ),
			'version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'pro'       => defined( 'ELEMENTOR_PRO_VERSION' ),
			'installed' => array_values( $installed ),
			'removable' => 0 === $left && ! $blockers && $count['live'] > 0 && $installed && '' === $cleaning,
			'removal'   => deft_migrate_removal(),
			'can'       => current_user_can( 'activate_plugins' ) && current_user_can( 'delete_plugins' ),
		),
	);
}

/* ---------- Removing Elementor ---------- */

/** Elementor plugins installed on the site (file => name), active or not. */
function deft_migrate_elementor_installed() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$all = get_plugins();
	$out = array();
	foreach ( DEFT_MIGRATE_ELEMENTOR_PLUGINS as $file ) {
		if ( isset( $all[ $file ] ) ) {
			$out[ $file ] = $all[ $file ]['Name'];
		}
	}
	return $out;
}

/** Where a removal stands: step (deactivated, checked, failed, removed), which plugins were on, the page check. */
function deft_migrate_removal() {
	$state = get_option( 'deft_migrate_removal' );
	return is_array( $state ) ? $state : null;
}

/** Every page a visitor might open once Elementor is gone: the Deft pages, the home page and the blog page. */
function deft_migrate_check_targets() {
	$ids     = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 200, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_wp_page_template', 'value' => array_keys( deft_pages_templates() ), 'compare' => 'IN' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	$targets = array();
	foreach ( $ids as $id ) {
		$targets[ get_permalink( $id ) ] = array( 'title' => get_the_title( $id ), 'deft' => true );
	}
	$home = home_url( '/' );
	if ( ! isset( $targets[ $home ] ) ) {
		$targets[ $home ] = array( 'title' => __( 'Home page', 'deft-core' ), 'deft' => false );
	}
	$posts = (int) get_option( 'page_for_posts' );
	if ( $posts && ! isset( $targets[ get_permalink( $posts ) ] ) ) {
		$targets[ get_permalink( $posts ) ] = array( 'title' => get_the_title( $posts ), 'deft' => false );
	}
	return $targets;
}

/**
 * Loads each page as a visitor would (no cookies, cache skipped) and says whether it still works: it answers 200, has
 * no PHP error, and Deft pages still carry their deft-page tag.
 */
function deft_migrate_check_pages() {
	$results = array();
	foreach ( deft_migrate_check_targets() as $url => $target ) {
		$response = wp_remote_get( add_query_arg( 'deft_check', wp_generate_password( 6, false ), $url ), array( 'timeout' => 20, 'redirection' => 3, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$body     = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
		$problem  = '';
		if ( is_wp_error( $response ) ) {
			$problem = $response->get_error_message();
		} elseif ( 200 !== $code ) {
			/* translators: %d: HTTP status */
			$problem = sprintf( __( 'Answered %d', 'deft-core' ), $code );
		} elseif ( preg_match( '/There has been a critical error|Fatal error:|Parse error:/i', $body ) ) {
			$problem = __( 'Shows a PHP error', 'deft-core' );
		} elseif ( $target['deft'] && false === strpos( $body, 'name="deft-page"' ) ) {
			$problem = __( 'Doesn\'t show the Deft page', 'deft-core' );
		} elseif ( preg_match( '/\[elementor-template[^\]]*\]/i', $body ) ) {
			$problem = __( 'Shows an Elementor shortcode as text', 'deft-core' );
		}
		$results[] = array( 'url' => $url, 'title' => $target['title'], 'code' => $code, 'ok' => '' === $problem, 'problem' => $problem );
	}
	return $results;
}

/**
 * One step of removing Elementor. The board runs them in order: deactivate (needs the backup and the OK ticked),
 * check (every page, and puts Elementor back on if one fails), uninstall (only right after a passing check), and
 * restore (puts Elementor back on, any time before it is uninstalled).
 */
function deft_migrate_remove_step( $step, array $args = array() ) {
	if ( ! current_user_can( 'activate_plugins' ) || ! current_user_can( 'delete_plugins' ) ) {
		return new WP_Error( 'deft_migrate_cant', __( 'Only an administrator who can manage plugins can remove Elementor.', 'deft-core' ), array( 'status' => 403 ) );
	}
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$state     = deft_migrate_removal() ?: array();
	$installed = deft_migrate_elementor_installed();
	switch ( $step ) {
		case 'deactivate':
			$board = deft_migrate_board();
			if ( ! $board['elementor']['removable'] ) {
				return new WP_Error( 'deft_migrate_not_ready', __( 'Elementor can be removed once every page is live as a Deft page and nothing else uses it.', 'deft-core' ), array( 'status' => 409 ) );
			}
			if ( empty( $args['backup'] ) || empty( $args['ok'] ) ) {
				return new WP_Error( 'deft_migrate_need_ok', __( 'Take a backup first, then tick both boxes.', 'deft-core' ), array( 'status' => 400 ) );
			}
			$active = array_values( array_filter( array_keys( $installed ), 'is_plugin_active' ) );
			deactivate_plugins( array_reverse( $active ) );
			$state = array( 'step' => 'deactivated', 'was_active' => $active, 'plugins' => $installed, 'by' => deft_pages_actor(), 'started' => gmdate( 'c' ), 'backup' => true );
			break;
		case 'check':
			if ( 'deactivated' !== ( $state['step'] ?? '' ) ) {
				return new WP_Error( 'deft_migrate_order', __( 'Deactivate Elementor first.', 'deft-core' ), array( 'status' => 409 ) );
			}
			$pages          = deft_migrate_check_pages();
			$failed         = array_values( array_filter( $pages, function ( $p ) {
				return ! $p['ok'];
			} ) );
			$state['pages'] = $pages;
			$state['checked'] = gmdate( 'c' );
			if ( $failed ) {
				activate_plugins( $state['was_active'] ?? array() );
				$state['step'] = 'failed';
			} else {
				$state['step'] = 'checked';
			}
			break;
		case 'uninstall':
			if ( 'checked' !== ( $state['step'] ?? '' ) || strtotime( $state['checked'] ?? '' ) < time() - 15 * MINUTE_IN_SECONDS ) {
				return new WP_Error( 'deft_migrate_order', __( 'Run the page check again first.', 'deft-core' ), array( 'status' => 409 ) );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			// Without direct file access WordPress would ask for FTP details mid-request, which a REST call can't show.
			if ( 'direct' !== get_filesystem_method( array(), WP_PLUGIN_DIR ) ) {
				return new WP_Error( 'deft_migrate_uninstall', __( 'WordPress needs FTP details to delete plugins on this server. Elementor stays deactivated; delete it under Plugins.', 'deft-core' ), array( 'status' => 500 ) );
			}
			$files   = array_values( array_filter( array_keys( $installed ), function ( $file ) {
				return ! is_plugin_active( $file );
			} ) );
			$deleted = $files ? delete_plugins( $files ) : true;
			if ( is_wp_error( $deleted ) || ! $deleted ) {
				return new WP_Error( 'deft_migrate_uninstall', __( 'WordPress couldn\'t delete the Elementor files. Elementor stays deactivated; delete it under Plugins.', 'deft-core' ) . ( is_wp_error( $deleted ) ? ' ' . $deleted->get_error_message() : '' ), array( 'status' => 500 ) );
			}
			$state['step']    = 'removed';
			$state['removed'] = gmdate( 'c' );
			break;
		case 'restore':
			if ( ! in_array( $state['step'] ?? '', array( 'deactivated', 'checked', 'failed' ), true ) ) {
				return new WP_Error( 'deft_migrate_order', __( 'Elementor isn\'t waiting to be removed.', 'deft-core' ), array( 'status' => 409 ) );
			}
			activate_plugins( $state['was_active'] ?? array() );
			$state = null;
			break;
		default:
			return new WP_Error( 'deft_migrate_step', __( 'Unknown step.', 'deft-core' ), array( 'status' => 400 ) );
	}
	if ( null === $state ) {
		delete_option( 'deft_migrate_removal' );
	} else {
		update_option( 'deft_migrate_removal', $state, false );
	}
	deft_core_flush_caches();
	return $state;
}

/* ---------- REST ---------- */

add_action( 'rest_api_init', 'deft_migrate_routes' );
function deft_migrate_routes() {
	$id = array( 'id' => array( 'sanitize_callback' => 'absint' ) );
	register_rest_route( 'deft/v1', '/migration', array( 'methods' => 'GET', 'callback' => 'deft_migrate_rest_board', 'permission_callback' => 'deft_pages_rest_permission' ) );
	foreach ( array( 'convert', 'live', 'back', 'check' ) as $action ) {
		register_rest_route( 'deft/v1', '/migration/(?P<id>\d+)/' . $action, array( 'methods' => 'POST', 'callback' => 'deft_migrate_rest_' . $action, 'permission_callback' => 'deft_pages_rest_permission', 'args' => $id ) );
	}
	// Removing Elementor is only ever done by a person signed in, never by a signed request.
	register_rest_route( 'deft/v1', '/migration/remove', array( 'methods' => 'POST', 'callback' => 'deft_migrate_rest_remove', 'permission_callback' => function () {
		return is_user_logged_in() && deft_pages_is_staff() ? true : new WP_Error( 'deft_not_staff', __( 'Only Deft staff, signed in, can remove Elementor.', 'deft-core' ), array( 'status' => 403 ) );
	} ) );
}

function deft_migrate_rest_board( WP_REST_Request $request ) {
	if ( $request->get_param( 'fresh' ) ) {
		delete_transient( 'deft_migrate_hack_signs' );
	}
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

function deft_migrate_rest_check( WP_REST_Request $request ) {
	$post = deft_migrate_rest_page( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$sizes  = $request->get_param( 'sizes' );
	$result = deft_migrate_save_check( $post->ID, is_array( $sizes ) ? $sizes : array() );
	return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => $result['ok'], 'board' => deft_migrate_board() ) );
}

function deft_migrate_rest_remove( WP_REST_Request $request ) {
	$result = deft_migrate_remove_step( sanitize_key( (string) $request->get_param( 'step' ) ), array(
		'backup' => (bool) $request->get_param( 'backup' ),
		'ok'     => (bool) $request->get_param( 'ok' ),
	) );
	return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'removal' => $result, 'board' => deft_migrate_board() ) );
}
