<?php
/**
 * The WordPress side of deft-move.sh. Run through WP-CLI only:
 *
 *   wp eval-file deft-move.php check   MANIFEST
 *   wp eval-file deft-move.php apply   MANIFEST UNDO_FILE
 *   wp eval-file deft-move.php undo    UNDO_FILE
 *   wp eval-file deft-move.php urls    MANIFEST      (each page's real address and form count, for the checks)
 *   wp eval-file deft-move.php leftovers MANIFEST
 *   wp eval-file deft-move.php export  OUT_DIR       (writes each old form plugin's stored submissions to CSV)
 *   wp eval-file deft-move.php purge   OUT_DIR       (drops those tables, only if their CSV is complete)
 *
 * Pages go live through Deft Core's own deft_pages_publish(), so each one gets a revision, the caches are cleared and
 * Central hears about it, exactly as when Studio or the Move off Elementor board publishes. An Elementor page keeps its
 * Elementor data and is marked the same way the board marks it, so "Back to Elementor" still works.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$mode = $args[0] ?? '';
$out  = function ( $line ) {
	WP_CLI::line( $line );
};
$stop = function ( $why ) {
	WP_CLI::line( 'STOP ' . $why );
	exit( 3 );
};

$read_manifest = function ( $file ) use ( $stop ) {
	$m = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $m ) || empty( $m['pages'] ) || ! is_array( $m['pages'] ) ) {
		$stop( 'manifest unreadable: ' . $file );
	}
	$home = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( preg_replace( '/^www\./', '', (string) $home ) !== preg_replace( '/^www\./', '', (string) $m['domain'] ) ) {
		$stop( 'this bundle is for ' . $m['domain'] . ' but this WordPress is ' . $home );
	}
	return $m;
};

/** The WordPress page a rebuild page lands on, or 0 when it would be new. */
$find_page = function ( array $page ) {
	if ( ! empty( $page['front'] ) ) {
		return 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
	}
	$p = get_page_by_path( $page['path'], OBJECT, 'page' );
	return $p && 'trash' !== $p->post_status ? (int) $p->ID : 0;
};

$is_elementor = function ( $id ) {
	return 'builder' === get_post_meta( $id, '_elementor_edit_mode', true );
};

$tables = function () {
	global $wpdb;
	$found = array();
	$like  = array( 'e_submissions%', 'frmt_form_entry%', 'wpforms_entries%', 'wpforms_entry_%', 'wpforms_payment%', 'cf7_vdata%', 'db7_forms%' );
	foreach ( $like as $pattern ) {
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . str_replace( '%', '', $pattern ) . '%' ) ) as $t ) {
			$found[ $t ] = true;
		}
	}
	return array_keys( $found );
};

switch ( $mode ) {
	case 'check':
		$m = $read_manifest( $args[1] ?? '' );
		if ( ! function_exists( 'deft_pages_publish' ) ) {
			$stop( 'Deft Core with Deft pages is not active' );
		}
		$out( 'deft-core ' . ( defined( 'DEFT_CORE_VERSION' ) ? DEFT_CORE_VERSION : '?' ) . ( defined( 'DEFT_CORE_VERSION' ) && version_compare( DEFT_CORE_VERSION, '0.9.6', '<' ) ? '  (needs 0.9.6 so extra questions are kept)' : '' ) );
		if ( function_exists( 'deft_migrate_cleaning' ) && ( $why = deft_migrate_cleaning() ) ) {
			$out( 'CLEANING ' . $why );
		}
		$s = get_option( 'deft_core', array() );
		$out( 'leads go to: ' . ( ! empty( $s['recipients'] ) ? $s['recipients'] : get_option( 'admin_email' ) . ' (site admin email, nothing set)' ) );
		$out( 'front page: ' . ( 'page' === get_option( 'show_on_front' ) ? '#' . get_option( 'page_on_front' ) : 'latest posts (a Home page will be made and set as the front page)' ) );
		$seen = array();
		foreach ( $m['pages'] as $page ) {
			$id = $find_page( $page );
			if ( $id ) {
				$seen[ $id ] = true;
				$out( sprintf( 'page  %-28s -> #%d %s (%s%s, %s)', $page['path'] ?: '/', $id, get_permalink( $id ), get_page_template_slug( $id ) ?: 'default', $is_elementor( $id ) ? ', Elementor' : '', get_post_status( $id ) ) );
			} else {
				$out( sprintf( 'NEW   %-28s -> a new page "%s"', $page['path'] ?: '/', $page['title'] ) );
			}
		}
		// A Mailchimp for WP signup form: the list address deft_move.py needs (--mailchimp) so the rebuilt form keeps
		// adding people to the list. Read from the plugin's own settings; the API key is used here and never printed.
		$mc = get_option( 'mc4wp', array() );
		if ( ! empty( $mc['api_key'] ) && preg_match( '/-([a-z]+\d+)$/', $mc['api_key'], $dc ) ) {
			$lists = array();
			foreach ( get_posts( array( 'post_type' => 'mc4wp-form', 'numberposts' => 20, 'post_status' => 'any' ) ) as $f ) {
				$set = get_post_meta( $f->ID, '_mc4wp_settings', true );
				foreach ( (array) ( $set['lists'] ?? array() ) as $l ) {
					$lists[ $l ] = $f->post_title;
				}
			}
			foreach ( $lists as $list_id => $form_title ) {
				$r = wp_remote_get( 'https://' . $dc[1] . '.api.mailchimp.com/3.0/lists/' . rawurlencode( $list_id ) . '?fields=name,subscribe_url_long', array(
					'timeout' => 15,
					'headers' => array( 'Authorization' => 'Basic ' . base64_encode( 'deft:' . $mc['api_key'] ) ),
				) );
				$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
				if ( ! empty( $body['subscribe_url_long'] ) ) {
					$url = preg_replace( '#^http:#', 'https:', str_replace( '/subscribe?', '/subscribe/post?', $body['subscribe_url_long'] ) );
					$out( sprintf( 'MAILCHIMP list "%s" (form "%s"): --mailchimp "%s"', $body['name'] ?? $list_id, $form_title, $url ) );
				} else {
					$out( sprintf( 'MAILCHIMP list %s: could not read it (%s)', $list_id, wp_remote_retrieve_response_code( $r ) ?: 'no answer' ) );
				}
			}
		}
		$others = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 200, 'fields' => 'ids' ) );
		foreach ( $others as $id ) {
			if ( empty( $seen[ $id ] ) ) {
				$out( sprintf( 'KEEP  %-28s    #%d not in the rebuild%s', wp_make_link_relative( get_permalink( $id ) ), $id, $is_elementor( $id ) ? ' (Elementor: stops looking right once Elementor is removed)' : '' ) );
			}
		}
		break;

	case 'apply':
		$m    = $read_manifest( $args[1] ?? '' );
		$undo = $args[2] ?? '';
		if ( '' === $undo ) {
			$stop( 'no undo file named' );
		}
		if ( ! function_exists( 'deft_pages_publish' ) ) {
			$stop( 'Deft Core with Deft pages is not active' );
		}
		if ( function_exists( 'deft_migrate_cleaning' ) && ( $why = deft_migrate_cleaning() ) ) {
			$stop( 'site is marked as hacked or being cleaned: ' . $why );
		}
		$record = array( 'domain' => $m['domain'], 'when' => gmdate( 'c' ), 'pages' => array(), 'options' => array(
			'show_on_front' => get_option( 'show_on_front' ),
			'page_on_front' => (int) get_option( 'page_on_front' ),
		) );
		$save = function () use ( &$record, $undo ) {
			file_put_contents( $undo, wp_json_encode( $record ) );
		};
		$save();
		// Parents first, so /services/mirrors/ can find /services/.
		usort( $m['pages'], function ( $a, $b ) {
			return substr_count( $a['path'], '/' ) - substr_count( $b['path'], '/' );
		} );
		// Pass 1: find or make every page, so the links between them can point at their real addresses.
		$ids = array();
		foreach ( $m['pages'] as $i => $page ) {
			$id = $find_page( $page );
			if ( ! $id ) {
				$parent = 0;
				if ( false !== strpos( $page['path'], '/' ) ) {
					$pp     = get_page_by_path( dirname( $page['path'] ), OBJECT, 'page' );
					$parent = $pp ? (int) $pp->ID : 0;
				}
				$id = deft_pages_create( array(
					'title'  => $page['title'],
					'slug'   => basename( $page['path'] ?: 'home' ),
					'parent' => $parent,
					'html'   => $page['html'],
					'head'   => $page['head'],
					'source' => 'migration',
					'actor'  => 'Deft move',
				) );
				if ( is_wp_error( $id ) ) {
					$out( 'FAIL  ' . ( $page['path'] ?: '/' ) . ' ' . $id->get_error_message() );
					continue;
				}
				$record['pages'][] = array( 'id' => $id, 'created' => true, 'path' => $page['path'] );
				$save();
			} else {
				$record['pages'][] = array(
					'id'      => $id,
					'created' => false,
					'path'    => $page['path'],
					'before'  => array(
						'template'  => get_page_template_slug( $id ),
						'edit_mode' => (string) get_post_meta( $id, '_elementor_edit_mode', true ),
						'deft'      => function_exists( 'deft_pages_is' ) && deft_pages_is( $id ),
						'hash'      => deft_pages_get( $id )['hash'],
					),
				);
				$save();
				if ( ! get_post_meta( $id, '_deft_migrated_from', true ) && ! deft_pages_is( $id ) ) {
					update_post_meta( $id, '_deft_migrated_from', wp_slash( array(
						'template'  => get_page_template_slug( $id ),
						'edit_mode' => (string) get_post_meta( $id, '_elementor_edit_mode', true ),
						'moved'     => gmdate( 'c' ),
						'by'        => 'Deft move',
					) ) );
				}
				delete_post_meta( $id, '_elementor_edit_mode' );
			}
			$ids[ $i ] = $id;
		}
		// The rebuild links pages as /about/. A site on plain or /index.php/ permalinks has other addresses, so each
		// link is swapped for the page's own permalink (a new page is still a draft, so ask as if it were published).
		$links = array();
		foreach ( $ids as $i => $id ) {
			$post              = clone get_post( $id );
			$post->post_status = 'publish';
			$links[ trim( $m['pages'][ $i ]['path'], '/' ) ] = wp_make_link_relative( get_permalink( $post ) );
		}
		$relink = function ( $html ) use ( $links ) {
			return preg_replace_callback( '~(\shref=)(["\'])(/[^"\'#?]*)([#?][^"\']*)?\2~i', function ( $mm ) use ( $links ) {
				$key = trim( $mm[3], '/' );
				if ( '' === $key || ! isset( $links[ $key ] ) ) {
					return $mm[0];
				}
				return $mm[1] . $mm[2] . $links[ $key ] . ( $mm[4] ?? '' ) . $mm[2];
			}, $html );
		};
		// Pass 2: put every page live.
		foreach ( $ids as $i => $id ) {
			$page = $m['pages'][ $i ];
			$note = 'Moved to the rebuilt page (deft-move, ' . $m['source'] . ')';
			$res  = deft_pages_publish( $id, array(
				'html'    => $relink( $page['html'] ),
				'head'    => $page['head'],
				'source'  => 'migration',
				'actor'   => 'Deft move',
				'note'    => $note,
				'go_live' => true,
			) );
			if ( is_wp_error( $res ) ) {
				$out( 'FAIL  ' . ( $page['path'] ?: '/' ) . ' ' . $res->get_error_message() );
				continue;
			}
			if ( ! empty( $page['front'] ) && 'page' !== get_option( 'show_on_front' ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $id );
			}
			$out( 'LIVE  ' . $res['url'] . ' revision ' . $res['revision'] );
		}
		break;

	case 'urls':
		$m = $read_manifest( $args[1] ?? '' );
		foreach ( $m['pages'] as $page ) {
			$id = $find_page( $page );
			$out( ( $id ? get_permalink( $id ) : home_url( '/' . ( '' === $page['path'] ? '' : $page['path'] . '/' ) ) ) . '|' . (int) $page['forms'] );
		}
		break;

	case 'undo':
		$record = json_decode( (string) file_get_contents( $args[1] ?? '' ), true );
		if ( ! is_array( $record ) || ! isset( $record['pages'] ) ) {
			$stop( 'undo file unreadable' );
		}
		foreach ( array_reverse( $record['pages'] ) as $p ) {
			$id = (int) $p['id'];
			if ( ! empty( $p['created'] ) ) {
				wp_trash_post( $id );
				$out( 'TRASHED #' . $id . ' (made by the move)' );
				continue;
			}
			if ( ! empty( $p['before']['deft'] ) ) {
				// Was already a Deft page: put the version it had back live.
				foreach ( deft_pages_revisions( $id, 50 ) as $rev ) {
					if ( $rev['hash'] === $p['before']['hash'] ) {
						deft_pages_restore( $id, $rev['id'], array( 'source' => 'restore', 'actor' => 'Deft move', 'note' => 'deft-move undo' ) );
						break;
					}
				}
				$out( 'RESTORED #' . $id . ' to its earlier Deft version' );
				continue;
			}
			delete_post_meta( $id, '_deft_migrated_from' );
			update_post_meta( $id, '_wp_page_template', $p['before']['template'] ?: 'default' );
			if ( '' !== $p['before']['edit_mode'] ) {
				update_post_meta( $id, '_elementor_edit_mode', $p['before']['edit_mode'] );
			}
			deft_core_flush_caches( $id );
			$out( 'BACK  #' . $id . ' ' . get_permalink( $id ) . ' (as it was before the move)' );
		}
		update_option( 'show_on_front', $record['options']['show_on_front'] );
		update_option( 'page_on_front', (int) $record['options']['page_on_front'] );
		deft_core_flush_caches();
		break;

	case 'leftovers':
		// Anything that still needs Elementor or a form plugin once the rebuilt pages are live.
		$m     = $read_manifest( $args[1] ?? '' );
		$moved = array();
		foreach ( $m['pages'] as $page ) {
			$moved[ $find_page( $page ) ] = true;
		}
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT p.ID, p.post_type, p.post_name FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_edit_mode' AND m.meta_value = 'builder' WHERE p.post_status = 'publish' AND p.post_type NOT IN ('elementor_library','revision')" ); // phpcs:ignore
		foreach ( $rows as $r ) {
			if ( empty( $moved[ $r->ID ] ) ) {
				$out( sprintf( 'ELEMENTOR %s #%d %s', $r->post_type, $r->ID, $r->post_name ) );
			}
		}
		$sc = $wpdb->get_results( "SELECT ID, post_type, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','deft_lead') AND (post_content LIKE '%[wpforms%' OR post_content LIKE '%[forminator_form%' OR post_content LIKE '%[contact-form-7%' OR post_content LIKE '%wp:wpforms%' OR post_content LIKE '%[mc4wp_form%')" ); // phpcs:ignore
		foreach ( $sc as $r ) {
			if ( empty( $moved[ $r->ID ] ) ) {
				$out( sprintf( 'FORM %s #%d %s', $r->post_type, $r->ID, $r->post_name ) );
			}
		}
		foreach ( (array) get_option( 'sidebars_widgets', array() ) as $area => $widgets ) {
			if ( 'wp_inactive_widgets' !== $area && is_array( $widgets ) ) {
				foreach ( $widgets as $w ) {
					if ( preg_match( '/wpforms|forminator|elementor|mc4wp|wpcf7/', $w ) ) {
						$out( 'WIDGET ' . $area . ' ' . $w );
					}
				}
			}
		}
		break;

	case 'export':
		$dir = rtrim( $args[1] ?? '', '/' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			$stop( 'no export folder' );
		}
		global $wpdb;
		foreach ( $tables() as $t ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" ); // phpcs:ignore
			$fh    = fopen( "$dir/$t.csv", 'w' );
			$n     = 0;
			for ( $off = 0; $off < $count; $off += 1000 ) {
				$rows = $wpdb->get_results( "SELECT * FROM `$t` LIMIT 1000 OFFSET $off", ARRAY_A ); // phpcs:ignore
				foreach ( $rows as $i => $row ) {
					if ( 0 === $n && 0 === $i ) {
						fputcsv( $fh, array_keys( $row ) );
					}
					fputcsv( $fh, $row );
					$n++;
				}
			}
			fclose( $fh );
			chmod( "$dir/$t.csv", 0600 );
			file_put_contents( "$dir/$t.count", (string) $n );
			$out( sprintf( 'EXPORTED %s %d rows', $t, $n ) );
		}
		// Forminator keeps entries in its own tables; Contact Form 7's Flamingo keeps them as posts.
		$fl = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('flamingo_inbound','flamingo_contact')" ); // phpcs:ignore
		if ( $fl ) {
			$out( 'NOTE Flamingo holds ' . $fl . ' stored messages as posts; export them from wp-admin before deleting Flamingo.' );
		}
		break;

	case 'purge':
		$dir = rtrim( $args[1] ?? '', '/' );
		global $wpdb;
		foreach ( $tables() as $t ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" ); // phpcs:ignore
			$saved = is_file( "$dir/$t.count" ) ? (int) file_get_contents( "$dir/$t.count" ) : -1;
			if ( $saved !== $count ) {
				$out( sprintf( 'KEPT %s: %d rows now, export has %d. Export again first.', $t, $count, $saved ) );
				continue;
			}
			$wpdb->query( "DROP TABLE `$t`" ); // phpcs:ignore
			$out( sprintf( 'DROPPED %s (%d rows, exported)', $t, $count ) );
		}
		break;

	default:
		$stop( 'unknown mode ' . $mode );
}
