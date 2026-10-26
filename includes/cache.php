<?php
/**
 * Clearing caches after a page changes, shared by Deft pages, Live Edit and agent access.
 *
 * Hostinger sites run LiteSpeed, which tags each cached page with its post, so a single page can be purged on its own.
 * Other caching plugins are cleared whole. Hostinger's CDN sits outside WordPress: after a page change Deft Core tells
 * Deft Central (deft_core_page_changed), and Central clears the CDN through the Hostinger API.
 */

defined( 'ABSPATH' ) || exit;

/** Caching plugins found on this site, by name. */
function deft_core_cache_plugins() {
	$found = array();
	$known = array(
		'LiteSpeed Cache'  => defined( 'LSCWP_V' ),
		'WP Rocket'        => function_exists( 'rocket_clean_domain' ),
		'W3 Total Cache'   => function_exists( 'w3tc_flush_all' ),
		'WP Super Cache'   => function_exists( 'wp_cache_clear_cache' ),
		'SiteGround'       => function_exists( 'sg_cachepress_purge_cache' ),
		'Autoptimize'      => class_exists( 'autoptimizeCache' ),
		'WP Fastest Cache' => class_exists( 'WpFastestCache' ),
		'Hostinger'        => class_exists( '\Hostinger\Cache\Purge' ) || defined( 'HOSTINGER_VERSION' ),
		'Object cache'     => wp_using_ext_object_cache(),
	);
	foreach ( $known as $name => $present ) {
		if ( $present ) {
			$found[] = $name;
		}
	}
	return $found;
}

function deft_core_clear_elementor() {
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
		return true;
	}
	return false;
}

/**
 * Clears caches. With a post ID, LiteSpeed and WP Rocket purge just that page (and the home page when it is the front
 * page); everything else is cleared whole. Returns the names of what was cleared.
 */
function deft_core_flush_caches( $post_id = 0 ) {
	$post_id = absint( $post_id );
	$done    = array();
	if ( $post_id ) {
		clean_post_cache( $post_id );
		$done[] = 'WordPress post cache';
	} else {
		wp_cache_flush();
		$done[] = 'WordPress object cache';
	}
	if ( deft_core_clear_elementor() ) {
		$done[] = 'Elementor CSS';
	}
	if ( defined( 'LSCWP_V' ) ) {
		if ( $post_id ) {
			do_action( 'litespeed_purge_post', $post_id );
			if ( (int) get_option( 'page_on_front' ) === $post_id ) {
				do_action( 'litespeed_purge_url', home_url( '/' ) );
			}
			$done[] = 'LiteSpeed Cache (this page)';
		} else {
			do_action( 'litespeed_purge_all' );
			$done[] = 'LiteSpeed Cache';
		}
	}
	if ( function_exists( 'rocket_clean_domain' ) ) {
		if ( $post_id && function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		} else {
			rocket_clean_domain();
		}
		$done[] = 'WP Rocket';
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
		$done[] = 'W3 Total Cache';
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
		$done[] = 'WP Super Cache';
	}
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
		$done[] = 'SiteGround';
	}
	if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
		autoptimizeCache::clearall();
		$done[] = 'Autoptimize';
	}
	if ( class_exists( 'WpFastestCache' ) && isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
		$GLOBALS['wp_fastest_cache']->deleteCache( true );
		$done[] = 'WP Fastest Cache';
	}
	do_action( 'deft_agent_flush_caches' ); // Kept from 0.6: hosts with their own cache can hook in here.
	do_action( 'deft_core_flush_caches', $post_id );
	return $done;
}
