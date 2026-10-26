<?php
/**
 * Site basics (Oct 25, post-launch): old addresses 301 to their new pages, and missing pages show our own 404 design.
 *
 * Both only act on a request WordPress could not match (is_404), so a real page at the same address always wins.
 * The list and the page come from the website build (source/build-wp.py writes data/redirects.json and data/404.html
 * from site.config.json and pages/404.html); the deft_redirects filter can add to the list.
 */

defined( 'ABSPATH' ) || exit;

function deft_site_redirects() {
	static $map = null;
	if ( null === $map ) {
		$map  = array();
		$file = DEFT_CORE_DIR . 'data/redirects.json';
		if ( is_readable( $file ) ) {
			$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_array( $data ) ) {
				foreach ( $data as $from => $to ) {
					$map[ untrailingslashit( strtolower( $from ) ) ] = $to;
				}
			}
		}
		$map = apply_filters( 'deft_redirects', $map );
	}
	return $map;
}

// Early, before the theme picks a template.
add_action( 'template_redirect', 'deft_site_handle_404', 1 );
function deft_site_handle_404() {
	if ( ! is_404() ) {
		return;
	}
	$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$key  = untrailingslashit( strtolower( rawurldecode( $path ) ) );
	$map  = deft_site_redirects();
	if ( '' !== $key && isset( $map[ $key ] ) ) {
		$query = isset( $_SERVER['QUERY_STRING'] ) && '' !== $_SERVER['QUERY_STRING'] ? '?' . wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$to    = $map[ $key ];
		// Keep UTM tags from old links, ahead of any #anchor in the target.
		if ( $query ) {
			$hash = strpos( $to, '#' );
			$to   = false === $hash ? $to . $query : substr( $to, 0, $hash ) . $query . substr( $to, $hash );
		}
		wp_safe_redirect( home_url( $to ), 301, 'Deft Core' );
		exit;
	}

	$page = DEFT_CORE_DIR . 'data/404.html';
	if ( ! is_readable( $page ) ) {
		return; // No design shipped: the theme's own 404 shows.
	}
	status_header( 404 );
	nocache_headers();
	// Same shell as Elementor Canvas, which the other pages use: the theme's header and footer stay out.
	?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'elementor-template-canvas' ); ?>>
<?php
	wp_body_open();
	readfile( $page ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- our own built markup, shipped in the plugin.
	wp_footer();
	?>
</body>
</html>
<?php
	exit;
}
