<?php
/**
 * Link previews (Oct 26): the Open Graph and Twitter card tags that make a shared link show a title, a line of text and
 * a picture on Facebook, WhatsApp, iMessage, LinkedIn, Slack and X. On by default; replaces the Open Graph plugin.
 *
 * An SEO plugin (Yoast, Rank Math, All in One SEO, SEOPress, The SEO Framework, Slim SEO, Squirrly) already writes these
 * tags, so Deft Core leaves them alone while one is active. The Open Graph plugin and Jetpack are switched off for the
 * tags instead, so a page never has two sets. A Deft page whose own head already has og: tags keeps those.
 *
 * Picture, in order: the featured image, the first Media Library image in the content, the share image in
 * Settings > Deft, then the site icon. The deft_share_tags filter can change any tag.
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Settings ---------- */

add_filter( 'deft_core_defaults', 'deft_share_defaults' );
function deft_share_defaults( $defaults ) {
	return $defaults + array(
		'share_enabled' => 1,
		'share_image'   => '',
	);
}

add_filter( 'deft_core_sanitize', 'deft_share_sanitize', 10, 2 );
function deft_share_sanitize( $out, $input ) {
	$out['share_enabled'] = empty( $input['share_enabled'] ) ? 0 : 1;
	$out['share_image']   = esc_url_raw( trim( (string) ( $input['share_image'] ?? '' ) ), array( 'https', 'http' ) );
	return $out;
}

add_action( 'deft_core_settings_sections', 'deft_share_settings_fields', 4 );
function deft_share_settings_fields( $s ) {
	$other = deft_share_other_plugin();
	?>
	<h2 id="deft-share"><?php esc_html_e( 'Link previews', 'deft-core' ); ?></h2>
	<p><?php esc_html_e( 'The title, short description and picture that show when someone shares a page on Facebook, WhatsApp, iMessage, LinkedIn or X.', 'deft-core' ); ?></p>
	<?php if ( $other ) : ?>
		<p><strong><?php echo esc_html( sprintf( /* translators: %s: plugin name */ __( '%s writes these on this site, so Deft Core leaves them alone.', 'deft-core' ), $other ) ); ?></strong></p>
	<?php elseif ( $s['share_enabled'] && function_exists( 'opengraph_meta_tags' ) ) : ?>
		<p><strong><?php esc_html_e( 'Deft Core writes these now instead of the Open Graph plugin, so you can deactivate that plugin.', 'deft-core' ); ?></strong></p>
	<?php endif; ?>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><?php esc_html_e( 'Link previews', 'deft-core' ); ?></th>
			<td><label><input type="checkbox" name="deft_core[share_enabled]" value="1" <?php checked( $s['share_enabled'] ); ?>> <?php esc_html_e( 'On', 'deft-core' ); ?></label></td></tr>
		<tr><th scope="row"><label for="deft-share-image"><?php esc_html_e( 'Share image', 'deft-core' ); ?></label></th>
			<td><input id="deft-share-image" type="url" class="large-text" name="deft_core[share_image]" value="<?php echo esc_attr( $s['share_image'] ); ?>" placeholder="https://">
			<p class="description"><?php esc_html_e( 'Shown for pages without a featured image or a photo of their own. Best at 1200 × 630. Copy the address from Media > Library. Empty uses the site icon.', 'deft-core' ); ?></p></td></tr>
	</table>
	<?php
}

/* ---------- Who writes the tags ---------- */

/** The name of an active SEO plugin that writes its own link preview tags, or ''. */
function deft_share_other_plugin() {
	$plugins = array(
		'WPSEO_VERSION'              => 'Yoast SEO',
		'RANK_MATH_VERSION'          => 'Rank Math',
		'AIOSEO_VERSION'             => 'All in One SEO',
		'AIOSEOP_VERSION'            => 'All in One SEO',
		'SEOPRESS_VERSION'           => 'SEOPress',
		'THE_SEO_FRAMEWORK_VERSION'  => 'The SEO Framework',
		'SLIM_SEO_VER'               => 'Slim SEO',
		'SQ_VERSION'                 => 'Squirrly SEO',
	);
	foreach ( $plugins as $constant => $name ) {
		if ( defined( $constant ) ) {
			return (string) apply_filters( 'deft_share_other_plugin', $name );
		}
	}
	return (string) apply_filters( 'deft_share_other_plugin', '' );
}

function deft_share_active() {
	return (bool) deft_core_setting( 'share_enabled' ) && '' === deft_share_other_plugin();
}

// The Open Graph plugin adds its tags on wp_head and its defaults on wp; take both off once the query is known.
add_action( 'wp', 'deft_share_take_over', 20 );
function deft_share_take_over() {
	if ( is_admin() || ! deft_share_active() ) {
		return;
	}
	remove_action( 'wp_head', 'opengraph_meta_tags' );
	remove_filter( 'language_attributes', 'opengraph_add_prefix' );
	add_filter( 'jetpack_enable_open_graph', '__return_false', 99 );
	add_filter( 'jetpack_enable_opengraph', '__return_false', 99 );
}

/* ---------- The tags ---------- */

add_action( 'wp_head', 'deft_share_head', 5 );
function deft_share_head() {
	if ( ! deft_share_active() || is_404() || is_search() || is_feed() ) {
		return;
	}
	// A Deft page built with its own og: tags keeps them (deft_pages_head prints the page's head).
	if ( function_exists( 'deft_pages_showing' ) && deft_pages_showing() ) {
		$current = deft_pages_current( get_queried_object_id() );
		if ( false !== stripos( (string) $current['head'], 'property="og:' ) ) {
			return;
		}
	}
	$tags = apply_filters( 'deft_share_tags', deft_share_tags() );
	echo "<!-- Deft Core link previews -->\n";
	foreach ( $tags as $tag ) {
		list( $attr, $name, $value ) = $tag;
		if ( '' === (string) $value ) {
			continue;
		}
		$is_url = in_array( $name, array( 'og:url', 'og:image', 'twitter:image' ), true );
		printf( '<meta %s="%s" content="%s">' . "\n", esc_attr( $attr ), esc_attr( $name ), $is_url ? esc_url( $value ) : esc_attr( $value ) );
	}
}

/** The tags for the page being viewed, as array( attribute, name, content ) rows. */
function deft_share_tags() {
	$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$about = wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES );
	$post  = is_singular() ? get_queried_object() : null;
	$front = is_front_page();

	if ( $front ) {
		$title = $site;
		$desc  = $about ? $about : ( $post ? deft_share_text( $post ) : '' );
		$url   = home_url( '/' );
	} elseif ( $post ) {
		$title = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );
		$desc  = deft_share_text( $post );
		$url   = wp_get_canonical_url( $post ) ?: get_permalink( $post );
	} else {
		$title = wp_specialchars_decode( wp_get_document_title(), ENT_QUOTES );
		$desc  = wp_strip_all_tags( get_the_archive_description() );
		$desc  = $desc ? $desc : $about;
		global $wp;
		$url   = home_url( user_trailingslashit( isset( $wp->request ) ? $wp->request : '' ) );
	}
	$image   = deft_share_image( $post );
	$article = $post && 'post' === $post->post_type;

	$tags = array(
		array( 'property', 'og:type', $article ? 'article' : 'website' ),
		array( 'property', 'og:site_name', $site ),
		array( 'property', 'og:title', $title ),
		array( 'property', 'og:description', $desc ),
		array( 'property', 'og:url', $url ),
		array( 'property', 'og:locale', str_replace( '-', '_', get_locale() ) ),
	);
	if ( $image ) {
		$tags[] = array( 'property', 'og:image', $image['url'] );
		$tags[] = array( 'property', 'og:image:width', $image['width'] ? (string) $image['width'] : '' );
		$tags[] = array( 'property', 'og:image:height', $image['height'] ? (string) $image['height'] : '' );
		$tags[] = array( 'property', 'og:image:alt', $image['alt'] );
	}
	if ( $article ) {
		$tags[] = array( 'property', 'article:published_time', get_post_time( 'c', true, $post ) );
		$tags[] = array( 'property', 'article:modified_time', get_post_modified_time( 'c', true, $post ) );
	}
	// The big picture card unless the only picture is small (a site icon).
	$large  = $image && ( ! $image['width'] || $image['width'] >= 300 );
	$tags[] = array( 'name', 'twitter:card', $large ? 'summary_large_image' : 'summary' );
	$tags[] = array( 'name', 'twitter:title', $title );
	$tags[] = array( 'name', 'twitter:description', $desc );
	$tags[] = array( 'name', 'twitter:image', $image ? $image['url'] : '' );
	return $tags;
}

/** A short description of a post: its excerpt, else the start of its text. */
function deft_share_text( $post ) {
	if ( post_password_required( $post ) ) {
		return '';
	}
	if ( has_excerpt( $post ) ) {
		return wp_strip_all_tags( get_the_excerpt( $post ) );
	}
	$text = excerpt_remove_blocks( strip_shortcodes( (string) $post->post_content ) );
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
	return html_entity_decode( wp_trim_words( $text, 30, '…' ), ENT_QUOTES, 'UTF-8' );
}

/** The picture for a page as array( url, width, height, alt ), or null. */
function deft_share_image( $post ) {
	$id = 0;
	if ( $post && ! post_password_required( $post ) ) {
		$id = (int) get_post_thumbnail_id( $post );
		// Else the first Media Library image in the content that is big enough to be a photo, not an icon.
		if ( ! $id && preg_match_all( '/<img\b[^>]*\bwp-image-(\d+)/i', (string) $post->post_content, $m ) ) {
			foreach ( $m[1] as $candidate ) {
				$meta = wp_get_attachment_metadata( (int) $candidate );
				if ( ! empty( $meta['width'] ) && $meta['width'] >= 200 ) {
					$id = (int) $candidate;
					break;
				}
			}
		}
	}
	if ( $id ) {
		$src = wp_get_attachment_image_src( $id, 'large' );
		if ( $src ) {
			$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			return array( 'url' => $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2], 'alt' => $alt );
		}
	}
	$fallback = (string) deft_core_setting( 'share_image' );
	if ( $fallback ) {
		$fid = attachment_url_to_postid( $fallback );
		$src = $fid ? wp_get_attachment_image_src( $fid, 'full' ) : false;
		return array(
			'url'    => $fallback,
			'width'  => $src ? (int) $src[1] : 0,
			'height' => $src ? (int) $src[2] : 0,
			'alt'    => $fid ? (string) get_post_meta( $fid, '_wp_attachment_image_alt', true ) : '',
		);
	}
	$icon = get_site_icon_url( 512 );
	return $icon ? array( 'url' => $icon, 'width' => 512, 'height' => 512, 'alt' => '' ) : null;
}
