<?php
/**
 * Approximate visitor location for the tracking module.
 *
 * The visitor's IP address is looked up in a local IP database (DB-IP "IP to City Lite", CC BY 4.0) and then thrown
 * away. Only country, state, city and, for the US, the nearest ZIP code and its county are kept. Nothing is sent to an
 * outside service. The database (about 60 MB per IP version) is downloaded to uploads/deft-core/geo/ when tracking is
 * switched on and refreshed monthly. Any MaxMind-format city database dropped there as geo.mmdb (e.g. GeoLite2-City
 * or DB-IP's own monthly file) is used instead.
 */

defined( 'ABSPATH' ) || exit;

/** Where the IP databases live. */
function deft_geo_dir() {
	$uploads = wp_upload_dir( null, false );
	return trailingslashit( $uploads['basedir'] ) . 'deft-core/geo/';
}

/** The database file for this IP, or '' if none is installed yet. */
function deft_geo_file( $ip ) {
	$dir = deft_geo_dir();
	if ( is_readable( $dir . 'geo.mmdb' ) ) {
		return $dir . 'geo.mmdb';
	}
	$file = $dir . ( false === strpos( $ip, ':' ) ? 'city-ipv4.mmdb' : 'city-ipv6.mmdb' );
	return is_readable( $file ) ? $file : '';
}

/**
 * Approximate location for an IP address: country, region, city, and for the US zip and county (nearest ZIP to the
 * city's position, so treat it as approximate). Empty array if unknown or private.
 */
function deft_geo_lookup( $ip ) {
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return array();
	}
	$file = deft_geo_file( $ip );
	if ( ! $file ) {
		return array();
	}
	try {
		$reader = new Deft_MMDB_Reader( $file );
		$record = $reader->get( $ip );
		$reader->close();
	} catch ( Exception $e ) {
		return array();
	}
	if ( ! is_array( $record ) ) {
		return array();
	}

	// Two layouts: ip-location-db (flat keys) and MaxMind/GeoIP2 (nested, used by GeoLite2 and DB-IP's own files).
	if ( isset( $record['country_code'] ) ) {
		$geo = array(
			'country' => (string) $record['country_code'],
			'region'  => (string) ( $record['state1'] ?? '' ),
			'city'    => (string) ( $record['city'] ?? '' ),
			'lat'     => $record['latitude'] ?? null,
			'lon'     => $record['longitude'] ?? null,
		);
	} else {
		$geo = array(
			'country' => (string) ( $record['country']['iso_code'] ?? '' ),
			'region'  => (string) ( $record['subdivisions'][0]['names']['en'] ?? '' ),
			'city'    => (string) ( $record['city']['names']['en'] ?? '' ),
			'lat'     => $record['location']['latitude'] ?? null,
			'lon'     => $record['location']['longitude'] ?? null,
		);
	}
	$out = array(
		'country' => substr( $geo['country'], 0, 2 ),
		'region'  => mb_substr( $geo['region'], 0, 64 ),
		'city'    => mb_substr( $geo['city'], 0, 80 ),
		'county'  => '',
		'zip'     => '',
	);
	if ( 'US' === $out['country'] && is_numeric( $geo['lat'] ) && is_numeric( $geo['lon'] ) ) {
		$near = deft_geo_nearest_zip( (float) $geo['lat'], (float) $geo['lon'] );
		if ( $near ) {
			$out['zip']    = $near['zip'];
			$out['county'] = $near['county'] . ', ' . $near['state'];
		}
	}
	return $out;
}

/** Nearest US ZIP code (and its county) to a point, within about 70 miles. */
function deft_geo_nearest_zip( $lat, $lon ) {
	static $buckets = null;
	if ( null === $buckets ) {
		$buckets = include DEFT_CORE_DIR . 'data/us-zips.php';
	}
	$best      = null;
	$best_dist = 1.0; // In squared degrees: about 1 degree, roughly 70 miles.
	$cos       = cos( deg2rad( $lat ) );
	$base_lat  = (int) floor( $lat );
	$base_lon  = (int) floor( $lon );
	for ( $dlat = -1; $dlat <= 1; $dlat++ ) {
		for ( $dlon = -1; $dlon <= 1; $dlon++ ) {
			$key = ( $base_lat + $dlat ) . ',' . ( $base_lon + $dlon );
			if ( empty( $buckets[ $key ] ) ) {
				continue;
			}
			foreach ( explode( ';', $buckets[ $key ] ) as $row ) {
				$f    = explode( '|', $row );
				$dist = ( (float) $f[1] - $lat ) ** 2 + ( ( (float) $f[2] - $lon ) * $cos ) ** 2;
				if ( $dist < $best_dist ) {
					$best_dist = $dist;
					$best      = array( 'zip' => $f[0], 'county' => $f[3], 'state' => $f[4], 'city' => $f[5] );
				}
			}
		}
	}
	return $best;
}

/** Center of a US ZIP code as array( lat, lon ), for the visitor map. */
function deft_geo_zip_point( $zip ) {
	static $index = null;
	if ( null === $index ) {
		$index = array();
		foreach ( include DEFT_CORE_DIR . 'data/us-zips.php' as $bucket ) {
			foreach ( explode( ';', $bucket ) as $row ) {
				$f              = explode( '|', $row, 4 );
				$index[ $f[0] ] = array( (float) $f[1], (float) $f[2] );
			}
		}
	}
	return $index[ $zip ] ?? null;
}

/**
 * A US city's county and center, from the ZIP table: the most common county among the city's ZIP codes and the
 * average of their centers. $state is the two-letter code. Used for Google Analytics history, which reports cities.
 */
function deft_geo_city_point( $city, $state ) {
	static $index = null;
	if ( null === $index ) {
		$index = array();
		foreach ( include DEFT_CORE_DIR . 'data/us-zips.php' as $bucket ) {
			foreach ( explode( ';', $bucket ) as $row ) {
				$f = explode( '|', $row );
				if ( count( $f ) < 6 ) {
					continue;
				}
				$key = strtolower( $f[5] ) . '|' . $f[4];
				if ( ! isset( $index[ $key ] ) ) {
					$index[ $key ] = array( 'lat' => 0, 'lon' => 0, 'n' => 0, 'counties' => array() );
				}
				$index[ $key ]['lat'] += (float) $f[1];
				$index[ $key ]['lon'] += (float) $f[2];
				++$index[ $key ]['n'];
				$index[ $key ]['counties'][ $f[3] ] = ( $index[ $key ]['counties'][ $f[3] ] ?? 0 ) + 1;
			}
		}
	}
	$hit = $index[ strtolower( trim( $city ) ) . '|' . strtoupper( $state ) ] ?? null;
	if ( ! $hit ) {
		return null;
	}
	arsort( $hit['counties'] );
	return array(
		'county' => key( $hit['counties'] ) . ', ' . strtoupper( $state ),
		'lat'    => round( $hit['lat'] / $hit['n'], 4 ),
		'lon'    => round( $hit['lon'] / $hit['n'], 4 ),
	);
}

/** Two-letter code for a US state name ("Florida" => "FL"), or '' if unknown. */
function deft_geo_state_code( $name ) {
	$states = array(
		'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR', 'california' => 'CA', 'colorado' => 'CO',
		'connecticut' => 'CT', 'delaware' => 'DE', 'district of columbia' => 'DC', 'florida' => 'FL', 'georgia' => 'GA',
		'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS',
		'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD', 'massachusetts' => 'MA',
		'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT',
		'nebraska' => 'NE', 'nevada' => 'NV', 'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM',
		'new york' => 'NY', 'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK',
		'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD',
		'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA',
		'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY', 'puerto rico' => 'PR',
	);
	return $states[ strtolower( trim( (string) $name ) ) ] ?? '';
}

/**
 * Centers of all US ZIP codes within $deg degrees of a point, as [lat, lon] pairs (2 decimals). The dashboard map draws
 * them as a faint dot field, which shows the shape of the land without shipping a map.
 */
function deft_geo_zips_around( $lat, $lon, $deg = 4, $max = 5000 ) {
	$out = array();
	foreach ( include DEFT_CORE_DIR . 'data/us-zips.php' as $key => $bucket ) {
		list( $blat, $blon ) = array_map( 'floatval', explode( ',', $key ) );
		if ( abs( $blat - $lat ) > $deg + 1 || abs( $blon - $lon ) > $deg + 1 ) {
			continue;
		}
		foreach ( explode( ';', $bucket ) as $row ) {
			$f = explode( '|', $row, 4 );
			if ( abs( $f[1] - $lat ) <= $deg && abs( $f[2] - $lon ) <= $deg ) {
				$out[] = array( round( (float) $f[1], 2 ), round( (float) $f[2], 2 ) );
				if ( count( $out ) >= $max ) {
					return $out;
				}
			}
		}
	}
	return $out;
}

/* ---------- Database download ---------- */

/** Status line for Settings: whether a database is installed and how old it is. */
function deft_geo_status() {
	$dir = deft_geo_dir();
	foreach ( array( 'geo.mmdb', 'city-ipv4.mmdb' ) as $name ) {
		if ( is_readable( $dir . $name ) ) {
			return array( 'installed' => true, 'updated' => filemtime( $dir . $name ), 'file' => $name );
		}
	}
	return array( 'installed' => false, 'updated' => 0, 'file' => '' );
}

/**
 * Downloads the latest DB-IP City Lite database (ip-location-db's packaging on the npm registry, which ships separate
 * IPv4 and IPv6 files) and swaps it in. Returns true or a WP_Error.
 */
function deft_geo_update() {
	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 900 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the download takes a minute or two.
	}
	$meta = wp_remote_get( 'https://registry.npmjs.org/@ip-location-db/dbip-city-mmdb/latest', array( 'timeout' => 20 ) );
	if ( is_wp_error( $meta ) ) {
		return $meta;
	}
	$info    = json_decode( wp_remote_retrieve_body( $meta ), true );
	$tarball = $info['dist']['tarball'] ?? '';
	if ( 0 !== strpos( $tarball, 'https://registry.npmjs.org/' ) ) {
		return new WP_Error( 'deft_geo_meta', __( 'Could not find the location database download.', 'deft-core' ) );
	}
	$tmp = download_url( $tarball, 600 );
	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}
	$dir = deft_geo_dir();
	if ( ! wp_mkdir_p( $dir ) ) {
		wp_delete_file( $tmp );
		return new WP_Error( 'deft_geo_dir', __( 'Could not create the uploads/deft-core/geo folder.', 'deft-core' ) );
	}
	if ( ! file_exists( $dir . 'index.php' ) ) {
		file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	// The plugin reads the files directly; nobody needs to download them from the web (they are about 120 MB, so
	// repeated downloads would eat the host's bandwidth). Apache and LiteSpeed honour this; Nginx ignores it.
	if ( ! file_exists( $dir . '.htaccess' ) ) {
		file_put_contents( $dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	$wanted = array(
		'package/dbip-city-ipv4.mmdb' => 'city-ipv4.mmdb',
		'package/dbip-city-ipv6.mmdb' => 'city-ipv6.mmdb',
	);
	$result = deft_geo_extract_tgz( $tmp, $wanted, $dir );
	wp_delete_file( $tmp );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	update_option( 'deft_geo_updated', time(), false );
	return true;
}

/**
 * Streams the wanted files out of a .tgz without loading it into memory. Each file is written next to its target and
 * checked before it replaces the old one, so a failed download never leaves the site without a database.
 */
function deft_geo_extract_tgz( $archive, $wanted, $dir ) {
	$gz = gzopen( $archive, 'rb' );
	if ( ! $gz ) {
		return new WP_Error( 'deft_geo_open', __( 'Could not open the downloaded database.', 'deft-core' ) );
	}
	$found = 0;
	while ( ! gzeof( $gz ) ) {
		$header = gzread( $gz, 512 );
		if ( strlen( $header ) < 512 || '' === trim( $header, "\0" ) ) {
			break;
		}
		$name   = rtrim( substr( $header, 0, 100 ), "\0" );
		$prefix = rtrim( substr( $header, 345, 155 ), "\0" );
		$name   = $prefix ? $prefix . '/' . $name : $name;
		$size   = octdec( trim( substr( $header, 124, 12 ), "\0 " ) );
		$padded = (int) ( ceil( $size / 512 ) * 512 );
		if ( isset( $wanted[ $name ] ) ) {
			$target = $dir . $wanted[ $name ];
			$out    = fopen( $target . '.part', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$left   = $size;
			while ( $left > 0 && ! gzeof( $gz ) ) {
				$chunk = gzread( $gz, (int) min( 1048576, $left ) );
				fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				$left -= strlen( $chunk );
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( $padded > $size ) {
				gzread( $gz, $padded - $size );
			}
			try {
				$check = new Deft_MMDB_Reader( $target . '.part' );
				$check->close();
			} catch ( Exception $e ) {
				wp_delete_file( $target . '.part' );
				gzclose( $gz );
				return new WP_Error( 'deft_geo_bad', __( 'The downloaded location database is damaged. Try again later.', 'deft-core' ) );
			}
			rename( $target . '.part', $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			++$found;
		} else {
			// Skip this entry's data.
			$skip = $padded;
			while ( $skip > 0 && ! gzeof( $gz ) ) {
				$skip -= strlen( gzread( $gz, (int) min( 1048576, $skip ) ) );
			}
		}
	}
	gzclose( $gz );
	return $found ? true : new WP_Error( 'deft_geo_missing', __( 'The download did not contain a location database.', 'deft-core' ) );
}

/**
 * Minimal reader for MaxMind DB (.mmdb) files: the format used by GeoLite2 and DB-IP.
 * Reads straight from disk, so a 60 MB database costs a few kilobytes of memory per lookup.
 * Spec: https://maxmind.github.io/MaxMind-DB/
 */
class Deft_MMDB_Reader {
	private $fh;
	private $node_count;
	private $record_size;
	private $node_bytes;
	private $ip_version;
	private $tree_size;
	private $data_start;
	private $ipv4_start = null;

	public function __construct( $file ) {
		$this->fh = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $this->fh ) {
			throw new Exception( 'Cannot open database' );
		}
		$size = filesize( $file );
		$tail = min( $size, 131072 );
		fseek( $this->fh, $size - $tail );
		$buffer = fread( $this->fh, $tail ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$marker = strrpos( $buffer, "\xAB\xCD\xEFMaxMind.com" );
		if ( false === $marker ) {
			throw new Exception( 'Not a MaxMind DB file' );
		}
		$meta_start = $size - $tail + $marker + 14;
		list( $meta ) = $this->decode( $meta_start, $meta_start );
		if ( ! isset( $meta['node_count'], $meta['record_size'], $meta['ip_version'] ) ) {
			throw new Exception( 'Bad metadata' );
		}
		$this->node_count  = (int) $meta['node_count'];
		$this->record_size = (int) $meta['record_size'];
		$this->ip_version  = (int) $meta['ip_version'];
		if ( ! in_array( $this->record_size, array( 24, 28, 32 ), true ) ) {
			throw new Exception( 'Unsupported record size' );
		}
		$this->node_bytes = $this->record_size / 4;
		$this->tree_size  = $this->node_bytes * $this->node_count;
		$this->data_start = $this->tree_size + 16;
	}

	public function close() {
		if ( $this->fh ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->fh = null;
		}
	}

	/** The record for an IP address, or null. */
	public function get( $ip ) {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return null;
		}
		$bits = strlen( $packed ) * 8;
		if ( 128 === $bits && 4 === $this->ip_version ) {
			return null;
		}
		$node = ( 32 === $bits && 6 === $this->ip_version ) ? $this->ipv4_start() : 0;
		for ( $i = 0; $i < $bits && $node < $this->node_count; $i++ ) {
			$bit  = ( ord( $packed[ $i >> 3 ] ) >> ( 7 - ( $i & 7 ) ) ) & 1;
			$node = $this->read_node( $node, $bit );
		}
		if ( $node <= $this->node_count ) {
			return null; // node_count itself means "no data".
		}
		$offset = $this->tree_size + ( $node - $this->node_count );
		list( $value ) = $this->decode( $offset, $this->data_start );
		return $value;
	}

	private function ipv4_start() {
		if ( null === $this->ipv4_start ) {
			$node = 0;
			for ( $i = 0; $i < 96 && $node < $this->node_count; $i++ ) {
				$node = $this->read_node( $node, 0 );
			}
			$this->ipv4_start = $node;
		}
		return $this->ipv4_start;
	}

	private function read_node( $node, $index ) {
		$b = $this->read( $node * $this->node_bytes, $this->node_bytes );
		switch ( $this->record_size ) {
			case 24:
				$part = 0 === $index ? substr( $b, 0, 3 ) : substr( $b, 3, 3 );
				return unpack( 'N', "\0" . $part )[1];
			case 28:
				if ( 0 === $index ) {
					return ( ( ord( $b[3] ) & 0xF0 ) << 20 ) | unpack( 'N', "\0" . substr( $b, 0, 3 ) )[1];
				}
				return ( ( ord( $b[3] ) & 0x0F ) << 24 ) | unpack( 'N', "\0" . substr( $b, 4, 3 ) )[1];
			default:
				return unpack( 'N', substr( $b, 0 === $index ? 0 : 4, 4 ) )[1];
		}
	}

	private function read( $offset, $length ) {
		if ( 0 === $length ) {
			return '';
		}
		fseek( $this->fh, $offset );
		$bytes = fread( $this->fh, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( strlen( $bytes ) !== $length ) {
			throw new Exception( 'Unexpected end of database' );
		}
		return $bytes;
	}

	/** Decodes one value at $offset. Pointers are relative to $base. Returns array( value, next offset ). */
	private function decode( $offset, $base ) {
		$ctrl = ord( $this->read( $offset++, 1 ) );
		$type = $ctrl >> 5;
		if ( 1 === $type ) { // Pointer.
			$ss  = ( $ctrl >> 3 ) & 3;
			$vvv = $ctrl & 7;
			$b   = $this->read( $offset, $ss + 1 );
			switch ( $ss ) {
				case 0:
					$ptr = ( $vvv << 8 ) | ord( $b[0] );
					break;
				case 1:
					$ptr = ( ( $vvv << 16 ) | unpack( 'n', $b )[1] ) + 2048;
					break;
				case 2:
					$ptr = ( ( $vvv << 24 ) | unpack( 'N', "\0" . $b )[1] ) + 526336;
					break;
				default:
					$ptr = unpack( 'N', $b )[1];
			}
			list( $value ) = $this->decode( $base + $ptr, $base );
			return array( $value, $offset + $ss + 1 );
		}
		if ( 0 === $type ) { // Extended type.
			$type = 7 + ord( $this->read( $offset++, 1 ) );
		}
		$size = $ctrl & 0x1F;
		if ( $size >= 29 ) {
			$extra = $size - 28;
			$b     = $this->read( $offset, $extra );
			$offset += $extra;
			if ( 29 === $size ) {
				$size = 29 + ord( $b );
			} elseif ( 30 === $size ) {
				$size = 285 + unpack( 'n', $b )[1];
			} else {
				$size = 65821 + unpack( 'N', "\0" . $b )[1];
			}
		}
		switch ( $type ) {
			case 2: // UTF-8 string.
				return array( $this->read( $offset, $size ), $offset + $size );
			case 3: // Double.
				return array( unpack( 'E', $this->read( $offset, 8 ) )[1], $offset + 8 );
			case 4: // Bytes.
				return array( $this->read( $offset, $size ), $offset + $size );
			case 5: // uint16.
			case 6: // uint32.
			case 9: // uint64.
			case 10: // uint128.
				$value = 0;
				foreach ( str_split( $this->read( $offset, $size ) ) as $byte ) {
					$value = $value * 256 + ord( $byte );
				}
				return array( $size ? $value : 0, $offset + $size );
			case 7: // Map.
				$map = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $key, $offset )   = $this->decode( $offset, $base );
					list( $value, $offset ) = $this->decode( $offset, $base );
					$map[ $key ] = $value;
				}
				return array( $map, $offset );
			case 8: // int32.
				$value = $size ? unpack( 'N', str_pad( $this->read( $offset, $size ), 4, "\0", STR_PAD_LEFT ) )[1] : 0;
				if ( 4 === $size && $value >= 2147483648 ) {
					$value -= 4294967296;
				}
				return array( $value, $offset + $size );
			case 11: // Array.
				$list = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $list[], $offset ) = $this->decode( $offset, $base );
				}
				return array( $list, $offset );
			case 14: // Boolean: the value is the size.
				return array( (bool) $size, $offset );
			case 15: // Float.
				return array( unpack( 'G', $this->read( $offset, 4 ) )[1], $offset + 4 );
			default:
				throw new Exception( 'Unsupported data type ' . $type );
		}
	}
}
