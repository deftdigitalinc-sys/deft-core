<?php
/**
 * Sends a request signed like Deft Central does, for testing: php tests/signed.php METHOD URL ROUTE SECRET [body-file]
 * Prints the status line and the JSON reply.
 */
list( , $method, $url, $route, $secret ) = $argv;
$body = isset( $argv[5] ) ? file_get_contents( $argv[5] ) : '';
$ts   = (string) time();
$sig  = 'v1=' . hash_hmac( 'sha256', $ts . '.' . strtoupper( $method ) . '.' . $route . '.' . $body, $secret );
$ch   = curl_init( $url );
curl_setopt_array( $ch, array(
	CURLOPT_CUSTOMREQUEST  => $method,
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json', 'X-Deft-Timestamp: ' . $ts, 'X-Deft-Signature: ' . $sig, 'X-Deft-Actor: Daniel' ),
	CURLOPT_POSTFIELDS     => '' === $body ? null : $body,
	CURLOPT_PROXY          => '',
) );
$out = curl_exec( $ch );
echo curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ), "\n", $out, "\n";
