<?php
/**
 * Writes data/manifest.json: a sha1 for each file Deft Core ships, so the nightly file check (modules/security.php)
 * can tell if one was changed on a site. Run before every build: php bin/manifest.php
 * Leaves out the manifest itself and data/site.json, redirects.json and 404.html (our own site's, written by the build).
 */
$root  = dirname( __DIR__ );
$files = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	$rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
	if ( preg_match( '#^(\.git|dist)/|^data/(manifest|site|redirects)\.json$|^data/404\.html$|^\.#', $rel ) ) {
		continue;
	}
	$files[ $rel ] = sha1_file( $file->getPathname() );
}
ksort( $files );
preg_match( "/define\( 'DEFT_CORE_VERSION', '([^']+)'/", file_get_contents( $root . '/deft-core.php' ), $m );
file_put_contents( $root . '/data/manifest.json', json_encode( array( 'version' => $m[1], 'files' => $files ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
echo count( $files ), " files, version {$m[1]}\n";
