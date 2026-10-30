<?php
/**
 * Tests for deft_forms_extras() (a site's own form questions). Runs without WordPress: php tests/forms-extras-test.php
 */
define( 'ABSPATH', __DIR__ );
function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) { return $value; }
function __( $text ) { return $text; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( $s ) ); }
function sanitize_email( $s ) { return $s; }
function esc_url_raw( $s ) { return $s; }
require dirname( __DIR__ ) . '/modules/forms.php';

$fails = 0;
function check( $label, $ok ) {
	global $fails;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	$fails += $ok ? 0 : 1;
}

$form = deft_forms_definitions()['contact'];
check( 'contact form takes extras', ! empty( $form['extras'] ) );
check( 'preview form does not', empty( deft_forms_definitions()['preview']['extras'] ) );

list( $values, $labels ) = deft_forms_extras(
	array(
		'name'          => 'Ana',
		'phone'         => '5615550100',
		'project_type'  => 'Frameless shower',
		'budget'        => '<b>$3k</b>',
		'rooms'         => array( 'Kitchen', 'Bath', array( 'x' ) ),
		'website_url'   => 'http://spam',
		'_elapsed'      => 5000,
		'_labels'       => array( 'budget' => 'Budget range', 'project_type' => array( 'no' ) ),
		'Bad-Name'      => 'x',
		'empty'         => '   ',
		'note'          => str_repeat( 'a', 2500 ),
	),
	$form
);
check( 'own fields are not extras', ! isset( $values['name'], $values['phone'] ) );
check( 'honeypot and _ fields are dropped', ! isset( $values['website_url'] ) && ! isset( $values['_elapsed'] ) && ! isset( $values['_labels'] ) );
check( 'bad names and empty answers are dropped', ! isset( $values['Bad-Name'] ) && ! isset( $values['empty'] ) );
check( 'answer is cleaned', '$3k' === $values['budget'] );
check( 'list becomes a, b', 'Kitchen, Bath' === $values['rooms'] );
check( 'answers capped at 2000', 2000 === mb_strlen( $values['note'] ) );
check( 'page label used', 'Budget range' === $labels['budget'] );
check( 'name becomes label when none given', 'Project type' === $labels['project_type'] );
check( 'labels match values', array_keys( $values ) === array_keys( $labels ) );

$many = array();
for ( $i = 0; $i < 30; $i++ ) {
	$many[ 'q' . $i ] = 'yes';
}
list( $values ) = deft_forms_extras( $many, $form );
check( 'at most 20 extras', 20 === count( $values ) );

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit( $fails ? 1 : 0 );
