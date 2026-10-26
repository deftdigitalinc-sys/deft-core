<?php
/**
 * Link previews (Open Graph and X cards).
 *
 * When someone shares a page on Facebook, WhatsApp, iMessage, LinkedIn or X, the preview shows its title, a short
 * description and a picture. This adds those tags to every page so sites don't need a separate Open Graph plugin.
 * It stays quiet while an SEO plugin that writes its own (Yoast, Rank Math, The SEO Framework, All in One SEO,
 * SEOPress) or the Open Graph plugin is active, and on a Deft page whose own head already has them.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'deft_core_defaults', 'deft_social_defaults' );
function deft_social_defaults( $defaults ) {
	return $defaults + array(
		'social'       => 1,
		'social_image' => '',
	);
}

add_filter( 'deft_core_sanitize', 'deft_social_sanitize', 10, 2 );
function deft_social_sanitize( $out, $input ) {
	$out['social']       = empty( $input['social'] ) ? 0 : 1;
	$out['social_image'] = isset( $input['social_image'] ) ? esc_url_raw( trim( (string) $input['social_image'] ) ) : '';
	return $out;
}

/** The plugin already writing link-preview tags, by name, or '' when none is. */
function deft_social_other_plugin() {
	$others = array(
		'Yoast SEO'           => defined( 'WPSEO_VERSION' ),
		'Rank Math'           => class_exists( 'RankMath' ),
		'The SEO Framework'   => function_exists( 'the_seo_framework' ),
		'All in One SEO'      => defined( 'AIOSEO_VERSION' ),
		'SEOPress'            => defined( 'SEOPRESS_VERSION' ),
		'Open Graph (plugin)' => function_exists( 'opengraph_metadata' ),
	);
	foreach ( $others as $name => $active ) {
		if ( $active ) {
			return $name;
		}
	}
	return '';
}

add_action( 'deft_core_settings_sections', 'deft_social_settings_fields', 9 );
function deft_social_settings_fields( $s ) {
	$other = deft_social_other_plugin();
	?>
	<h2 id="deft-social"><?php esc_html_e( 'Link previews', 'deft-core' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><?php esc_html_e( 'Shared links', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[social]" value="1" <?php checked( $s['social'] ); ?>> <?php esc_html_e( 'Show a title, description and picture when a page is shared on Facebook, WhatsApp, iMessage, LinkedIn or X', 'deft-core' ); ?></label>
			<?php if ( $other ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: plugin name */ __( '%s is active and already adds these, so Deft Core stays quiet. Deactivate it and Deft Core takes over.', 'deft-core' ), $other ) ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'The picture is the page\'s featured image, else the first picture on the page, else the image below, else the site icon.', 'deft-core' ); ?></p>
			<?php endif; ?></td></tr>
		<tr><th scope="row"><label for="deft-social-image"><?php esc_html_e( 'Fallback picture', 'deft-core' ); ?></label></th>
			<td><input id="deft-social-image" class="regular-text" type="url" name="deft_core[social_image]" value="<?php echo esc_attr( $s['social_image'] ); ?>" placeholder="https://">
			<p class="description"><?php esc_html_e( 'Address of an image from the Media Library, ideally 1200 x 630. Used for pages without a picture of their own.', 'deft-core' ); ?></p></td></tr>
	</table>
	<?php
}

// Jetpack adds its own tags unless told another plugin does.
add_filter( 'jetpack_enable_open_graph', 'deft_social_jetpack', 20 );
function deft_social_jetpack( $enabled ) {
	return deft_social_on() ? false : $enabled;
}

function deft_social_on() {
	return deft_core_setting( 'social' ) && ! deft_social_other_plugin() && apply_filters( 'deft_social_enabled', true );
}

add_action( 'wp_head', 'deft_social_head', 5 );
function deft_social_head() {
	if ( ! deft_social_on() || is_feed() || is_404() ) {
		return;
	}
	$post_id = is_singular() ? get_queried_object_id() : 0;
	if ( $post_id && deft_pages_showing() && false !== stripos( deft_pages_current( $post_id )['head'], 'og:title' ) ) {
		return; // The Deft page's own head wins.
	}
	$tags = deft_social_tags( $post_id );
	foreach ( $tags as $tag ) {
		list( $attr, $key, $value ) = $tag;
		if ( '' !== (string) $value ) {
			echo '<meta ' . $attr . '="' . esc_attr( $key ) . '" content="' . esc_attr( $value ) . '">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attr is one of two literals.
		}
	}
}

/** The tags for the page being viewed, as [ attribute, key, value ] rows. */
function deft_social_tags( $post_id ) {
	$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$front = is_front_page();
	if ( $post_id && ! $front ) {
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES );
		$url   = wp_get_canonical_url( $post_id );
	} else {
		$title = $front ? $site : wp_strip_all_tags( wp_get_document_title() );
		$url   = $front ? home_url( '/' ) : '';
	}
	$desc  = deft_social_description( $post_id );
	$image = deft_social_image( $post_id );
	$type  = $post_id && 'post' === get_post_type( $post_id ) ? 'article' : 'website';

	$tags = array(
		array( 'property', 'og:type', $type ),
		array( 'property', 'og:site_name', $site ),
		array( 'property', 'og:title', $title ),
		array( 'property', 'og:description', $desc ),
		array( 'property', 'og:url', $url ),
		array( 'property', 'og:locale', str_replace( '-', '_', get_bloginfo( 'language' ) ) ),
	);
	if ( $image ) {
		$tags[] = array( 'property', 'og:image', $image['url'] );
		$tags[] = array( 'property', 'og:image:width', $image['width'] );
		$tags[] = array( 'property', 'og:image:height', $image['height'] );
		$tags[] = array( 'property', 'og:image:alt', $image['alt'] );
	}
	if ( 'article' === $type ) {
		$tags[] = array( 'property', 'article:published_time', get_post_time( 'c', true, $post_id ) );
		$tags[] = array( 'property', 'article:modified_time', get_post_modified_time( 'c', true, $post_id ) );
	}
	$tags[] = array( 'name', 'twitter:card', $image ? 'summary_large_image' : 'summary' );
	$tags[] = array( 'name', 'twitter:title', $title );
	$tags[] = array( 'name', 'twitter:description', $desc );
	if ( $image ) {
		$tags[] = array( 'name', 'twitter:image', $image['url'] );
	}
	return apply_filters( 'deft_social_tags', $tags, $post_id );
}

/** Up to about 200 characters: the excerpt, else the Deft page's meta description, else the opening words, else the tagline. */
function deft_social_description( $post_id ) {
	$text = '';
	if ( $post_id ) {
		$post = get_post( $post_id );
		$text = $post && has_excerpt( $post ) ? $post->post_excerpt : '';
		if ( ! $text && deft_pages_is( $post_id ) ) {
			$page = deft_pages_get( $post_id );
			if ( preg_match( '/<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)/i', $page['head'], $m ) ) {
				$text = html_entity_decode( $m[1], ENT_QUOTES );
			} else {
				$text = deft_social_opening( $page['html'] );
			}
		}
		if ( ! $text && $post ) {
			$text = deft_social_opening( strip_shortcodes( $post->post_content ) );
		}
	}
	if ( ! $text ) {
		$text = get_bloginfo( 'description' );
	}
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
	return mb_strlen( $text ) > 200 ? rtrim( mb_substr( $text, 0, 197 ), " ,.;:-" ) . '...' : $text;
}

/** The first real paragraph of some markup, skipping headings, menus and buttons. */
function deft_social_opening( $html ) {
	$html = preg_replace( '#<(script|style|nav|header|footer|form|button|h[1-6])\b.*?</\1>#is', ' ', (string) $html );
	if ( preg_match_all( '#<p\b[^>]*>(.*?)</p>#is', $html, $m ) ) {
		foreach ( $m[1] as $p ) {
			$p = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $p ) ) );
			if ( mb_strlen( $p ) >= 40 ) {
				return html_entity_decode( $p, ENT_QUOTES );
			}
		}
	}
	return '';
}

/** [ url, width, height, alt ] or null. */
function deft_social_image( $post_id ) {
	if ( $post_id && has_post_thumbnail( $post_id ) ) {
		$image = deft_social_attachment( get_post_thumbnail_id( $post_id ) );
		if ( $image ) {
			return $image;
		}
	}
	if ( $post_id ) {
		$html = deft_pages_is( $post_id ) ? deft_pages_get( $post_id )['html'] : (string) get_post_field( 'post_content', $post_id );
		if ( preg_match( '#<img\b[^>]*>#i', $html, $m ) ) {
			if ( preg_match( '/wp-image-(\d+)/', $m[0], $id ) && ( $image = deft_social_attachment( (int) $id[1] ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
				return $image;
			}
			if ( preg_match( '/\ssrc=["\']([^"\']+)/i', $m[0], $src ) ) {
				$url = deft_social_absolute( html_entity_decode( $src[1], ENT_QUOTES ) );
				$id  = attachment_url_to_postid( $url );
				$alt = preg_match( '/\salt=["\']([^"\']*)/i', $m[0], $a ) ? html_entity_decode( $a[1], ENT_QUOTES ) : '';
				return ( $id ? deft_social_attachment( $id ) : null ) ?: array( 'url' => $url, 'width' => '', 'height' => '', 'alt' => $alt );
			}
		}
	}
	$fallback = deft_core_setting( 'social_image' );
	if ( $fallback ) {
		$id = attachment_url_to_postid( $fallback );
		return ( $id ? deft_social_attachment( $id ) : null ) ?: array( 'url' => $fallback, 'width' => '', 'height' => '', 'alt' => '' );
	}
	$icon = get_option( 'site_icon' );
	return $icon ? deft_social_attachment( (int) $icon, 'full' ) : null;
}

function deft_social_attachment( $id, $size = 'large' ) {
	$src = wp_get_attachment_image_src( $id, $size );
	if ( ! $src ) {
		return null;
	}
	return array(
		'url'    => $src[0],
		'width'  => $src[1] ? (string) $src[1] : '',
		'height' => $src[2] ? (string) $src[2] : '',
		'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
	);
}

function deft_social_absolute( $url ) {
	if ( 0 === strpos( $url, '//' ) ) {
		return set_url_scheme( $url );
	}
	if ( 0 === strpos( $url, '/' ) ) {
		return home_url( $url );
	}
	return $url;
}
