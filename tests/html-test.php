<?php
/**
 * Tests for includes/html.php. Run with WordPress loaded: php tests/html-test.php /path/to/wp-load.php
 */
require $argv[1];
require_once dirname( __DIR__ ) . '/includes/html.php';

$fails = 0;
function check( $label, $ok ) {
	global $fails;
	echo ( $ok ? "ok   " : "FAIL " ) . $label . "\n";
	$fails += $ok ? 0 : 1;
}

$page = <<<'HTML'
<style>.hero h1{color:red} p > a {x:"<p>"}</style>
<section class="hero">
  <span class="eyebrow">Palm Beach glass</span>
  <h1 class='t'>Frameless showers, <em>done right</em></h1>
  <p>Call <a href="tel:5615550100">(561) 555-0100</a> today &amp; save.</p>
  <a class="btn" href="/contact/">Get a quote</a>
  <button><svg viewBox="0 0 1 1"><text>x</text><path d="M0 0"/></svg> Menu</button>
  <picture><source srcset="a.webp" type="image/webp"><img src="a.jpg" srcset="a.jpg 1x, a2.jpg 2x" alt="Shower"></picture>
  <img src="/logo.png" alt="Logo"/>
  <!-- <p>hidden</p> -->
  <div class="card"><h3>Mirrors</h3><p>Cut to size.<br>Installed.</p></div>
  <ul><li>One<li>Two</ul>
  <script>document.write("<h2>no</h2>")</script>
  <p data-deft-lock>Locked text</p>
</section>
HTML;

$marked = deft_html_mark( $page );
check( 'mark only adds attributes', preg_replace( '/ data-deft-edit="[til]\d+"/', '', $marked ) === $page );
check( 'mark is stable (second pass changes nothing)', deft_html_mark( $marked ) === $marked );
$outline = deft_html_outline( $marked );
$by_text = array_column( $outline, 'text', 'id' );
$texts   = array_values( array_filter( array_column( $outline, 'text' ) ) );
print_r( array_map( function ( $o ) { return $o['id'] . ' ' . $o['tag'] . ' ' . ( $o['text'] ?? $o['src'] ); }, $outline ) );
check( 'eyebrow span editable', in_array( 'Palm Beach glass', $texts, true ) );
check( 'h1 with <em> editable as one', in_array( 'Frameless showers, done right', $texts, true ) );
check( 'p with link editable as one', in_array( 'Call (561) 555-0100 today & save.', $texts, true ) );
check( 'button with svg not editable', ! in_array( 'Menu', $texts, true ) && ! in_array( 'x Menu', $texts, true ) );
check( 'nothing from script, style or comment', ! array_filter( $texts, function ( $t ) { return in_array( $t, array( 'no', 'hidden', '<p>' ), true ); } ) );
check( 'locked paragraph left out', ! in_array( 'Locked text', $texts, true ) );
check( 'card heading and paragraph, not the card', in_array( 'Mirrors', $texts, true ) && in_array( 'Cut to size. Installed.', $texts, true ) );
check( 'unclosed li both editable', in_array( 'One', $texts, true ) && in_array( 'Two', $texts, true ) );
check( 'two images', 2 === count( array_filter( $outline, function ( $o ) { return 'image' === $o['kind']; } ) ) );

$ids = array_flip( array_filter( $by_text ) );
$img = array_values( array_filter( $outline, function ( $o ) { return 'a.jpg' === ( $o['src'] ?? '' ); } ) )[0]['id'];
$btn = array_values( array_filter( $outline, function ( $o ) { return 'a' === $o['tag']; } ) )[0]['id'];
$r   = deft_html_apply_edits( $marked, array(
	array( 'id' => $ids['Frameless showers, done right'], 'html' => 'Showers <em>made</em> simple<script>alert(1)</script><div>x</div>' ),
	array( 'id' => $img, 'src' => 'https://example.com/new.jpg', 'alt' => 'New <b>shower</b>' ),
	array( 'id' => $btn, 'href' => 'https://example.com/quote', 'html' => 'Free quote' ),
) );
check( 'edits applied', ! is_wp_error( $r ) );
$new = $r['html'];
check( 'script stripped from edit', false === strpos( $new, 'alert(1)' ) || false === strpos( $new, '<script>alert' ) );
check( 'div from typing became br', false !== strpos( $new, 'Showers <em>made</em> simple<br>x</h1>' ) );
check( 'image src changed, srcset and picture source gone', false !== strpos( $new, 'src="https://example.com/new.jpg"' ) && false === strpos( $new, 'a2.jpg' ) && false === strpos( $new, 'a.webp' ) );
check( 'alt cleaned', false !== strpos( $new, 'alt="New shower"' ) );
check( 'link href and text', false !== strpos( $new, 'href="https://example.com/quote"' ) && false !== strpos( $new, '>Free quote</a>' ) );
check( 'rest of page byte-identical', false !== strpos( $new, '<style>.hero h1{color:red} p > a {x:"<p>"}</style>' ) && false !== strpos( $new, '<script>document.write("<h2>no</h2>")</script>' ) );
check( 'changes listed', 4 <= count( $r['changes'] ) );
check( 'still stable after edits', deft_html_mark( $new ) === $new );

$err = deft_html_apply_edits( $marked, array( array( 'id' => 't999', 'html' => 'x' ) ) );
check( 'unknown id refused', is_wp_error( $err ) && 409 === $err->get_error_data()['status'] );
$err = deft_html_apply_edits( $marked, array( array( 'id' => $btn, 'href' => 'javascript:alert(1)' ) ) );
check( 'javascript: link refused', is_wp_error( $err ) );
$dup = deft_html_mark( '<p data-deft-edit="t1">A</p><p data-deft-edit="t1">B</p>' );
check( 'duplicate marker renumbered', 2 === count( array_unique( array_column( deft_html_outline( $dup ), 'id' ) ) ) );
check( 'self-closing img marker placement', false !== strpos( deft_html_mark( '<img src="x.png" />' ), '<img src="x.png" data-deft-edit="i1" />' ) );

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit( $fails ? 1 : 0 );
