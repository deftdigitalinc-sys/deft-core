<?php
/**
 * Removing the plugin deletes its settings. Leads and visitor statistics are kept unless "Delete leads when the plugin is removed" was ticked
 * under Settings > Deft, so an accidental uninstall never loses enquiries.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$deft_settings = get_option( 'deft_core', array() );
if ( ! empty( $deft_settings['delete_on_uninstall'] ) ) {
	$deft_leads = get_posts(
		array(
			'post_type'   => 'deft_lead',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	foreach ( $deft_leads as $deft_lead ) {
		wp_delete_post( $deft_lead, true );
	}
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}deft_hits, {$wpdb->prefix}deft_reports, {$wpdb->prefix}deft_ga, {$wpdb->prefix}deft_page_revisions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	delete_option( 'deft_ga_bridge' );
	delete_option( 'deft_ga_db' );
	delete_option( 'deft_pages_db' );
}

// Agent access: tokens and the log always go, so a removed plugin leaves no way in. Backups of changed files stay
// in uploads/deft-core/agent-backups-* until "Delete leads" was ticked.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}deft_agent_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option( 'deft_agent_tokens' );
delete_option( 'deft_agent_db' );
delete_option( 'deft_watch_mail' );
foreach ( array( 'deft_sec_scan', 'deft_sec_baseline', 'deft_sec_baseline_at', 'deft_sec_applied', 'deft_sec_apply_result', 'deft_sec_lockouts', 'deft_sec_root_error', 'deft_sec_force_reset' ) as $deft_option ) {
	delete_option( $deft_option );
}
// Two-factor secrets go with the plugin, so nobody is left asked for a code no form can take.
delete_metadata( 'user', 0, 'deft_2fa_secret', '', true );
delete_metadata( 'user', 0, 'deft_2fa_on', '', true );
delete_metadata( 'user', 0, 'deft_2fa_last', '', true );
delete_metadata( 'user', 0, 'deft_must_reset', '', true );
delete_option( 'deft_migrate_removal' );
delete_transient( 'deft_migrate_hack_signs' );
$deft_backup_key = get_option( 'deft_agent_backup_key' );
if ( $deft_backup_key && ! empty( $deft_settings['delete_on_uninstall'] ) ) {
	$deft_uploads    = wp_upload_dir( null, false );
	$deft_backup_dir = trailingslashit( $deft_uploads['basedir'] ) . 'deft-core/agent-backups-' . $deft_backup_key . '/';
	foreach ( array_merge( (array) glob( $deft_backup_dir . '*' ), array( $deft_backup_dir . '.htaccess' ) ) as $deft_file ) {
		if ( is_file( $deft_file ) ) {
			wp_delete_file( $deft_file );
		}
	}
	@rmdir( $deft_backup_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	delete_option( 'deft_agent_backup_key' );
}
delete_option( 'deft_core' );
wp_clear_scheduled_hook( 'deft_forms_cleanup' );
wp_clear_scheduled_hook( 'deft_central_sweep' );
wp_clear_scheduled_hook( 'deft_track_daily' );
delete_option( 'deft_track_salt' );
delete_option( 'deft_track_db' );
delete_option( 'deft_geo_updated' );

// The location database (about 120 MB, downloadable again any time) always goes.
$deft_uploads = wp_upload_dir( null, false );
$deft_geo_dir = trailingslashit( $deft_uploads['basedir'] ) . 'deft-core/geo/';
foreach ( array_merge( (array) glob( $deft_geo_dir . '*' ), array( $deft_geo_dir . '.htaccess' ) ) as $deft_file ) {
	if ( is_file( $deft_file ) ) {
		wp_delete_file( $deft_file );
	}
}
@rmdir( $deft_geo_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
@rmdir( dirname( $deft_geo_dir ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
