<?php
/**
 * Uninstall: removes the option, change-log table, import user, role and cron.
 *
 * @package Mindful_Import
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$mindful_import_options = get_option( 'mindful_import' );

delete_option( 'mindful_import' );
delete_option( 'mindful_import_registration_lock' );

$mindful_import_table = $wpdb->prefix . 'mindful_import_changes';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name is built from $wpdb->prefix only.
$wpdb->query( "DROP TABLE IF EXISTS `{$mindful_import_table}`" );

wp_clear_scheduled_hook( 'mindful_import_retention' );
wp_clear_scheduled_hook( 'mindful_import_register' );

require_once ABSPATH . 'wp-admin/includes/user.php';

$mindful_import_user_ids = array();
if ( is_array( $mindful_import_options ) && ! empty( $mindful_import_options['import_user_id'] ) ) {
	$mindful_import_user_ids[] = (int) $mindful_import_options['import_user_id'];
}
$mindful_import_user = get_user_by( 'login', 'mindful-import' );
if ( $mindful_import_user ) {
	$mindful_import_user_ids[] = (int) $mindful_import_user->ID;
}
foreach ( array_unique( $mindful_import_user_ids ) as $mindful_import_user_id ) {
	$mindful_import_candidate = get_userdata( $mindful_import_user_id );
	// Only delete the account this plugin created (login or role match).
	if ( $mindful_import_candidate && ( 'mindful-import' === $mindful_import_candidate->user_login || in_array( 'mindful_import', (array) $mindful_import_candidate->roles, true ) ) ) {
		wp_delete_user( $mindful_import_user_id, null );
	}
}

remove_role( 'mindful_import' );
