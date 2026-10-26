<?php
/**
 * The Deft page template: the stored HTML and nothing else from the page itself. "Deft page" is a bare canvas (like
 * Elementor Canvas, which our pages used before); "with the theme header and footer" wraps it in the theme's own.
 * wp_head and wp_footer still run, so SEO, analytics, Deft Core's tracking and Live Edit keep working.
 */

defined( 'ABSPATH' ) || exit;

$deft_post_id = get_queried_object_id();
$deft_current = deft_pages_current( $deft_post_id );
$deft_framed  = 'deft-theme' === ( deft_pages_is( $deft_post_id ) ? get_page_template_slug( $deft_post_id ) : (string) get_post_meta( $deft_post_id, '_deft_page_template_next', true ) );

if ( $deft_framed && ! wp_is_block_theme() ) {
	get_header();
	echo $deft_current['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- staff-written page markup, stored as-is on purpose.
	get_footer();
	return;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
wp_body_open();
if ( $deft_framed ) {
	block_template_part( 'header' );
}
echo $deft_current['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- staff-written page markup, stored as-is on purpose.
if ( $deft_framed ) {
	block_template_part( 'footer' );
}
wp_footer();
?>
</body>
</html>
