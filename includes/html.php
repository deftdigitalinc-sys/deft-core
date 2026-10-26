<?php
/**
 * Editing stored page HTML without rewriting it.
 *
 * Deft pages keep their markup exactly as Studio (or the Elementor HTML widget) wrote it. A DOM parser would re-serialise
 * the whole page (entities, whitespace, inline scripts), so instead this file scans the markup once into a light tree of
 * byte offsets and splices only the parts that change:
 *
 * - deft_html_mark() gives every editable element a data-deft-edit="t12" (text), "i3" (image) or "l4" (a link whose
 *   text is edited in pieces) marker, inserted into the start tag and nothing else. Studio may write its own markers; existing ones are kept.
 * - deft_html_apply_edits() replaces the inside of marked text elements, or the src/alt/href of marked tags.
 * - deft_html_outline() lists the editable elements and their text, for revision summaries and agents.
 *
 * Text elements are only editable when their content is plain text with inline tags (deft_html_inline_tags()). Anything
 * richer (an icon, a nested block) stays out of reach of Live Edit and is changed in Studio instead.
 */

defined( 'ABSPATH' ) || exit;

const DEFT_HTML_VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );
const DEFT_HTML_RAW  = array( 'script', 'style', 'textarea', 'title', 'svg', 'math', 'noscript', 'iframe', 'template' );
const DEFT_HTML_BLOCK = array( 'address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul' );
const DEFT_HTML_TEXT = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'blockquote', 'figcaption', 'label', 'dt', 'dd', 'td', 'th', 'caption', 'summary', 'a', 'button', 'span', 'div', 'small', 'strong', 'em', 'b', 'q', 'cite', 'address', 'legend' );

/** Inline tags (and their attributes) allowed inside an editable text element, in wp_kses form. */
function deft_html_inline_tags() {
	$common = array( 'class' => true, 'style' => true, 'title' => true, 'lang' => true, 'dir' => true );
	return apply_filters(
		'deft_html_inline_tags',
		array(
			'a'      => $common + array( 'href' => true, 'target' => true, 'rel' => true, 'aria-label' => true ),
			'strong' => $common,
			'b'      => $common,
			'em'     => $common,
			'i'      => $common,
			'u'      => $common,
			's'      => $common,
			'span'   => $common,
			'small'  => $common,
			'sup'    => $common,
			'sub'    => $common,
			'mark'   => $common,
			'code'   => $common,
			'abbr'   => $common,
			'br'     => array( 'class' => true ),
		)
	);
}

/**
 * Scans markup into nodes: tag, start (offset of "<"), open_end (just after the start tag's ">"), close_start, close_end,
 * parent (index) and children (indexes). Raw-text elements (script, style, svg…) are kept as one node whose content is
 * never looked into. Unclosed elements end where their parent ends.
 */
function deft_html_scan( $html ) {
	$nodes = array();
	$stack = array();
	$len   = strlen( $html );
	$pos   = 0;
	while ( $pos < $len ) {
		$lt = strpos( $html, '<', $pos );
		if ( false === $lt ) {
			break;
		}
		if ( 0 === substr_compare( $html, '<!--', $lt, 4 ) ) {
			$end = strpos( $html, '-->', $lt + 4 );
			$pos = false === $end ? $len : $end + 3;
			continue;
		}
		if ( ! preg_match( '/\G<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/A', $html, $m, 0, $lt ) ) {
			$pos = $lt + 1; // A stray "<", a doctype or a processing instruction: not a tag we care about.
			continue;
		}
		$tag      = strtolower( $m[2] );
		$tag_end  = $lt + strlen( $m[0] );
		$is_close = '/' === $m[1];
		if ( $is_close ) {
			// Close the nearest open element with this name; ignore a close tag that matches nothing.
			for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
				if ( $nodes[ $stack[ $i ] ]['tag'] === $tag ) {
					$match = $stack[ $i ];
					while ( count( $stack ) > $i ) {
						// Elements left open inside this one (a missing </p>) end where it ends.
						$open                          = array_pop( $stack );
						$nodes[ $open ]['close_start'] = $lt;
						$nodes[ $open ]['close_end']   = $open === $match ? $tag_end : $lt;
					}
					break;
				}
			}
			$pos = $tag_end;
			continue;
		}
		deft_html_implied_close( $nodes, $stack, $tag, $lt );
		$index           = count( $nodes );
		$parent          = $stack ? end( $stack ) : -1;
		$nodes[ $index ] = array(
			'tag'         => $tag,
			'start'       => $lt,
			'open_end'    => $tag_end,
			'attrs'       => $m[3],
			'close_start' => null,
			'close_end'   => null,
			'parent'      => $parent,
			'children'    => array(),
		);
		if ( $parent >= 0 ) {
			$nodes[ $parent ]['children'][] = $index;
		}
		$self_closing = '/' === substr( rtrim( $m[3] ), -1 );
		if ( in_array( $tag, DEFT_HTML_VOID, true ) || $self_closing ) {
			$nodes[ $index ]['close_start'] = $tag_end;
			$nodes[ $index ]['close_end']   = $tag_end;
			$pos                            = $tag_end;
			continue;
		}
		if ( in_array( $tag, DEFT_HTML_RAW, true ) ) {
			// Skip to the matching close tag without looking inside (svg can nest, the rest can't).
			$depth  = 1;
			$cursor = $tag_end;
			while ( $depth > 0 && preg_match( '/<(\/?)' . preg_quote( $tag, '/' ) . '\b[^>]*>/i', $html, $raw, PREG_OFFSET_CAPTURE, $cursor ) ) {
				$depth += '/' === $raw[1][0] ? -1 : ( 'svg' === $tag || 'math' === $tag || 'template' === $tag ? 1 : 0 );
				$cursor = $raw[0][1] + strlen( $raw[0][0] );
				if ( 0 === $depth ) {
					$nodes[ $index ]['close_start'] = $raw[0][1];
					$nodes[ $index ]['close_end']   = $cursor;
				}
			}
			if ( null === $nodes[ $index ]['close_start'] ) {
				$nodes[ $index ]['close_start'] = $len;
				$nodes[ $index ]['close_end']   = $len;
			}
			$pos = $nodes[ $index ]['close_end'];
			continue;
		}
		$stack[] = $index;
		$pos     = $tag_end;
	}
	foreach ( $stack as $open ) {
		$parent                        = $nodes[ $open ]['parent'];
		$nodes[ $open ]['close_start'] = $parent >= 0 && null !== $nodes[ $parent ]['close_start'] ? $nodes[ $parent ]['close_start'] : $len;
		$nodes[ $open ]['close_end']   = $nodes[ $open ]['close_start'];
	}
	return $nodes;
}

/**
 * HTML's optional end tags: a new <li> ends an open <li> in the same list, a block element ends an open <p>, and so on.
 * Handwritten pages lean on these, so without them "<li>One<li>Two" would nest.
 */
function deft_html_implied_close( array &$nodes, array &$stack, $tag, $at ) {
	$rules = array(
		'li' => array( array( 'li' ), array( 'ul', 'ol', 'menu' ) ),
		'dt' => array( array( 'dt', 'dd' ), array( 'dl' ) ),
		'dd' => array( array( 'dt', 'dd' ), array( 'dl' ) ),
		'tr' => array( array( 'tr', 'td', 'th' ), array( 'table', 'thead', 'tbody', 'tfoot' ) ),
		'td' => array( array( 'td', 'th' ), array( 'tr', 'table' ) ),
		'th' => array( array( 'td', 'th' ), array( 'tr', 'table' ) ),
		'option' => array( array( 'option' ), array( 'select', 'datalist', 'optgroup' ) ),
	);
	if ( in_array( $tag, DEFT_HTML_BLOCK, true ) ) {
		$rules[ $tag ] = array( array( 'p' ), array( 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'li', 'td', 'th', 'blockquote', 'figure', 'form', 'button', 'a' ) );
	}
	if ( ! isset( $rules[ $tag ] ) ) {
		return;
	}
	list( $closes, $scope ) = $rules[ $tag ];
	for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
		$open = $nodes[ $stack[ $i ] ]['tag'];
		if ( in_array( $open, $scope, true ) ) {
			return;
		}
		if ( in_array( $open, $closes, true ) ) {
			while ( count( $stack ) > $i ) {
				$node                          = array_pop( $stack );
				$nodes[ $node ]['close_start'] = $at;
				$nodes[ $node ]['close_end']   = $at;
			}
			return;
		}
	}
}

/** An attribute's value from a scanned node, or null. */
function deft_html_attr( array $node, $name ) {
	if ( preg_match( '/(?:^|\s)' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $node['attrs'], $m ) ) {
		return html_entity_decode( $m[1] . ( $m[2] ?? '' ) . ( $m[3] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
	return preg_match( '/(?:^|\s)' . preg_quote( $name, '/' ) . '(?=\s|\/|$)/i', $node['attrs'] ) ? '' : null;
}

function deft_html_inner( $html, array $node ) {
	return substr( $html, $node['open_end'], $node['close_start'] - $node['open_end'] );
}

/** Whether markup is plain text with inline tags only (and some actual text). */
function deft_html_is_simple( $inner ) {
	if ( '' === trim( wp_strip_all_tags( $inner ) ) ) {
		return false;
	}
	$allowed = deft_html_inline_tags();
	if ( preg_match( '/<!--|<!\[CDATA\[/', $inner ) ) {
		return false;
	}
	preg_match_all( '/<\/?([a-zA-Z][a-zA-Z0-9:-]*)/', $inner, $tags );
	foreach ( $tags[1] as $tag ) {
		if ( ! isset( $allowed[ strtolower( $tag ) ] ) ) {
			return false;
		}
	}
	return true;
}

/** Plain text of some markup, with whitespace collapsed. */
function deft_html_text( $markup ) {
	$text = html_entity_decode( wp_strip_all_tags( str_ireplace( array( '<br>', '<br/>', '<br />' ), ' ', $markup ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return trim( preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Whether an element reads as one piece of text. A row of links or a link made of two labelled spans has no text of
 * its own, so its children are edited one by one instead.
 */
function deft_html_is_text_unit( $html, array $nodes, array $node ) {
	if ( count( $node['children'] ) < 2 ) {
		return true;
	}
	$own    = '';
	$cursor = $node['open_end'];
	foreach ( $node['children'] as $child ) {
		$own   .= substr( $html, $cursor, $nodes[ $child ]['start'] - $cursor );
		$cursor = max( $cursor, $nodes[ $child ]['close_end'] );
	}
	$own .= substr( $html, $cursor, $node['close_start'] - $cursor );
	return '' !== deft_html_text( $own );
}

/**
 * Which nodes are editable: text elements with simple content and no editable ancestor, and images with a src.
 * Returns kind ("text", "image") by node index.
 */
function deft_html_editable( $html, array $nodes ) {
	$kinds  = array();
	$inside = array(); // Node indexes that sit inside an editable text element.
	foreach ( $nodes as $i => $node ) {
		if ( $node['parent'] >= 0 && ( isset( $inside[ $node['parent'] ] ) || isset( $kinds[ $node['parent'] ] ) && 'text' === $kinds[ $node['parent'] ] ) ) {
			$inside[ $i ] = true;
			continue;
		}
		if ( 'img' === $node['tag'] ) {
			if ( null !== deft_html_attr( $node, 'src' ) || null !== deft_html_attr( $node, 'data-deft-edit' ) ) {
				$kinds[ $i ] = 'image';
			}
			continue;
		}
		if ( null !== deft_html_attr( $node, 'data-deft-lock' ) ) {
			continue; // Studio can mark parts of a page as not for Live Edit.
		}
		if ( in_array( $node['tag'], DEFT_HTML_TEXT, true ) && deft_html_is_simple( deft_html_inner( $html, $node ) ) && deft_html_is_text_unit( $html, $nodes, $node ) ) {
			$kinds[ $i ] = 'text';
		} elseif ( 'a' === $node['tag'] && null !== deft_html_attr( $node, 'href' ) ) {
			$kinds[ $i ] = 'link'; // A link built from richer parts: its address can change, its text pieces are edited inside it.
		}
	}
	return $kinds;
}

/**
 * Adds a data-deft-edit marker to every editable element that has none. Existing markers (from Studio or an earlier
 * save) are kept, so ids stay stable across edits. Returns the markup unchanged when nothing needed a marker.
 */
function deft_html_mark( $html ) {
	$nodes = deft_html_scan( $html );
	$kinds = deft_html_editable( $html, $nodes );
	$max   = 0;
	$seen  = array();
	foreach ( $nodes as $node ) {
		$id = deft_html_attr( $node, 'data-deft-edit' );
		if ( null !== $id && preg_match( '/^[til](\d+)$/', $id, $m ) ) {
			$max = max( $max, (int) $m[1] );
		}
	}
	$inserts = array();
	foreach ( $kinds as $i => $kind ) {
		$id = deft_html_attr( $nodes[ $i ], 'data-deft-edit' );
		if ( null !== $id && '' !== $id && ! isset( $seen[ $id ] ) ) {
			$seen[ $id ] = true;
			continue;
		}
		$id          = array( 'image' => 'i', 'link' => 'l', 'text' => 't' )[ $kind ] . ( ++$max );
		$seen[ $id ] = true;
		$node        = $nodes[ $i ];
		if ( null !== deft_html_attr( $node, 'data-deft-edit' ) ) {
			// A duplicate or empty marker (copied markup): give this one a fresh id.
			$open = substr( $html, $node['start'], $node['open_end'] - $node['start'] );
			$open = preg_replace( '/\sdata-deft-edit\s*(=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/i', '', $open, 1 );
			$inserts[] = array( $node['start'], $node['open_end'] - $node['start'], substr( $open, 0, deft_html_insert_at( $open ) ) . ' data-deft-edit="' . $id . '"' . substr( $open, deft_html_insert_at( $open ) ) );
			continue;
		}
		$open      = substr( $html, $node['start'], $node['open_end'] - $node['start'] );
		$at        = $node['start'] + deft_html_insert_at( $open );
		$inserts[] = array( $at, 0, ' data-deft-edit="' . $id . '"' );
	}
	return deft_html_splice( $html, $inserts );
}

/** Where a new attribute goes inside a start tag: before "/>" or ">". */
function deft_html_insert_at( $open ) {
	$end = strlen( $open ) - 1;
	if ( $end > 0 && '/' === $open[ $end - 1 ] ) {
		$end--;
		while ( $end > 0 && ctype_space( $open[ $end - 1 ] ) ) {
			$end--;
		}
	}
	return $end;
}

/** Applies [offset, length, replacement] changes, last first so earlier offsets stay right. */
function deft_html_splice( $html, array $changes ) {
	usort(
		$changes,
		function ( $a, $b ) {
			return $b[0] <=> $a[0];
		}
	);
	foreach ( $changes as $change ) {
		$html = substr_replace( $html, $change[2], $change[0], $change[1] );
	}
	return $html;
}

/** Marked nodes by id. */
function deft_html_index( $html, array $nodes = null ) {
	$nodes = null === $nodes ? deft_html_scan( $html ) : $nodes;
	$index = array();
	foreach ( $nodes as $i => $node ) {
		$id = deft_html_attr( $node, 'data-deft-edit' );
		if ( null !== $id && '' !== $id && ! isset( $index[ $id ] ) ) {
			$index[ $id ] = $i;
		}
	}
	return $index;
}

/**
 * The editable elements in page order: id, kind, tag, text (or src and alt for images, href for links).
 */
function deft_html_outline( $html ) {
	$nodes = deft_html_scan( $html );
	$kinds = deft_html_editable( $html, $nodes );
	$out   = array();
	foreach ( $kinds as $i => $kind ) {
		$node = $nodes[ $i ];
		$id   = deft_html_attr( $node, 'data-deft-edit' );
		if ( null === $id ) {
			continue;
		}
		$item = array( 'id' => $id, 'kind' => $kind, 'tag' => $node['tag'] );
		if ( 'image' === $kind ) {
			$item['src'] = (string) deft_html_attr( $node, 'src' );
			$item['alt'] = (string) deft_html_attr( $node, 'alt' );
		} elseif ( 'link' === $kind ) {
			$item['href'] = (string) deft_html_attr( $node, 'href' );
		} else {
			$item['text'] = deft_html_text( deft_html_inner( $html, $node ) );
			if ( 'a' === $node['tag'] ) {
				$item['href'] = (string) deft_html_attr( $node, 'href' );
			}
		}
		$out[] = $item;
	}
	return $out;
}

/** A URL Live Edit may write into src or href: http(s), site-relative, #anchor, mailto:, tel: or sms:. */
function deft_html_clean_url( $url, $for = 'href' ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	if ( 'href' === $for && ( '#' === $url[0] ) ) {
		return preg_replace( '/[^A-Za-z0-9_:.#-]/', '', $url );
	}
	$protocols = 'href' === $for ? array( 'http', 'https', 'mailto', 'tel', 'sms' ) : array( 'http', 'https' );
	return esc_url_raw( $url, $protocols );
}

/**
 * Applies Live Edit changes to marked elements. Each edit is one of:
 *   { id, html }               new inside of a text element (inline tags only, cleaned with wp_kses)
 *   { id, href }               new link target for a marked <a>
 *   { id, src, alt?, width?, height? }  new image (srcset and sizes are dropped; <picture> sources too)
 *   { id, alt }                new alt text only
 * Returns [ 'html' => new markup, 'changes' => list of { id, kind, before, after } ] or a WP_Error naming the first
 * edit that could not be applied (nothing is changed then).
 */
function deft_html_apply_edits( $html, array $edits ) {
	$nodes   = deft_html_scan( $html );
	$index   = deft_html_index( $html, $nodes );
	$kinds   = deft_html_editable( $html, $nodes );
	$splices = array();
	$changes = array();
	$touched = array();
	foreach ( $edits as $edit ) {
		$id = isset( $edit['id'] ) ? (string) $edit['id'] : '';
		if ( ! isset( $index[ $id ] ) ) {
			return new WP_Error( 'deft_edit_missing', sprintf( 'Element %s is not on this page any more. Reload and try again.', $id ), array( 'status' => 409, 'id' => $id ) );
		}
		if ( isset( $touched[ $id ] ) ) {
			return new WP_Error( 'deft_edit_twice', sprintf( 'Element %s was sent twice.', $id ), array( 'status' => 400, 'id' => $id ) );
		}
		$touched[ $id ] = true;
		$i              = $index[ $id ];
		$node           = $nodes[ $i ];
		$open           = substr( $html, $node['start'], $node['open_end'] - $node['start'] );

		if ( array_key_exists( 'html', $edit ) ) {
			if ( ! isset( $kinds[ $i ] ) || 'text' !== $kinds[ $i ] ) {
				return new WP_Error( 'deft_edit_complex', sprintf( 'Element %s holds more than text, so it can only be changed in Studio.', $id ), array( 'status' => 422, 'id' => $id ) );
			}
			$before = deft_html_inner( $html, $node );
			$after  = deft_html_clean_inline( (string) $edit['html'] );
			if ( $after !== $before ) {
				$splices[] = array( $node['open_end'], $node['close_start'] - $node['open_end'], $after );
				$changes[] = array( 'id' => $id, 'kind' => 'text', 'tag' => $node['tag'], 'before' => deft_html_text( $before ), 'after' => deft_html_text( $after ) );
			}
		}

		$attrs = array();
		if ( array_key_exists( 'href', $edit ) ) {
			if ( 'a' !== $node['tag'] ) {
				return new WP_Error( 'deft_edit_not_link', sprintf( 'Element %s is not a link.', $id ), array( 'status' => 422, 'id' => $id ) );
			}
			$href = deft_html_clean_url( $edit['href'], 'href' );
			if ( '' === $href ) {
				return new WP_Error( 'deft_edit_bad_url', 'That link address is not allowed. Use https://, a page path, #section, mailto:, tel: or sms:.', array( 'status' => 422, 'id' => $id ) );
			}
			$attrs['href'] = $href;
		}
		if ( array_key_exists( 'src', $edit ) || array_key_exists( 'alt', $edit ) ) {
			if ( 'img' !== $node['tag'] ) {
				return new WP_Error( 'deft_edit_not_image', sprintf( 'Element %s is not an image.', $id ), array( 'status' => 422, 'id' => $id ) );
			}
			if ( array_key_exists( 'src', $edit ) ) {
				$src = deft_html_clean_url( $edit['src'], 'src' );
				if ( '' === $src ) {
					return new WP_Error( 'deft_edit_bad_url', 'That image address is not allowed.', array( 'status' => 422, 'id' => $id ) );
				}
				$attrs['src']    = $src;
				$attrs['srcset'] = false;
				$attrs['sizes']  = false;
				foreach ( array( 'width', 'height' ) as $dim ) {
					if ( isset( $edit[ $dim ] ) && absint( $edit[ $dim ] ) ) {
						$attrs[ $dim ] = (string) absint( $edit[ $dim ] );
					}
				}
				// Inside <picture>, the <source> elements would keep showing the old image.
				$parent = $node['parent'];
				if ( $parent >= 0 && 'picture' === $nodes[ $parent ]['tag'] ) {
					foreach ( $nodes[ $parent ]['children'] as $child ) {
						if ( 'source' === $nodes[ $child ]['tag'] ) {
							$splices[] = array( $nodes[ $child ]['start'], $nodes[ $child ]['close_end'] - $nodes[ $child ]['start'], '' );
						}
					}
				}
			}
			if ( array_key_exists( 'alt', $edit ) ) {
				$attrs['alt'] = sanitize_text_field( (string) $edit['alt'] );
			}
		}
		if ( $attrs ) {
			$before_value = 'a' === $node['tag'] ? (string) deft_html_attr( $node, 'href' ) : (string) deft_html_attr( $node, 'src' );
			$new_open     = deft_html_set_attrs( $open, $attrs );
			if ( $new_open !== $open ) {
				$splices[] = array( $node['start'], $node['open_end'] - $node['start'], $new_open );
				$after_value = $attrs['href'] ?? ( $attrs['src'] ?? $before_value );
				$changes[]   = array(
					'id'     => $id,
					'kind'   => 'img' === $node['tag'] ? 'image' : 'link',
					'tag'    => $node['tag'],
					'before' => $before_value,
					'after'  => $after_value,
					'alt'    => $attrs['alt'] ?? null,
				);
			}
		}
	}
	return array( 'html' => deft_html_splice( $html, $splices ), 'changes' => $changes );
}

/** Sets (string) or removes (false) attributes on one start tag, leaving the rest of it as written. */
function deft_html_set_attrs( $open, array $attrs ) {
	$p = new WP_HTML_Tag_Processor( $open );
	if ( ! $p->next_tag() ) {
		return $open;
	}
	foreach ( $attrs as $name => $value ) {
		if ( false === $value ) {
			$p->remove_attribute( $name );
		} else {
			$p->set_attribute( $name, $value );
		}
	}
	return $p->get_updated_html();
}

/** Cleans the inside of an edited text element: inline tags only, no markers, no line-break soup from contenteditable. */
function deft_html_clean_inline( $markup ) {
	$markup = str_replace( array( "\r\n", "\r" ), "\n", $markup );
	$markup = preg_replace( '/<(script|style|template)\b[^>]*>.*?<\/\1\s*>/is', '', $markup );
	$markup = preg_replace( '/<div[^>]*>(.*?)<\/div>/is', '<br>$1', $markup ); // Browsers wrap new lines in <div> while typing.
	$markup = preg_replace( '/&nbsp;/', ' ', $markup );
	$markup = wp_kses( $markup, deft_html_inline_tags() );
	$markup = preg_replace( '/(<br\s*\/?>\s*)+$/i', '', $markup );
	return $markup;
}
