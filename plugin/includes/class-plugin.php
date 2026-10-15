<?php
/**
 * Plugin orchestration: wires every class's hooks and handles (de)activation.
 *
 * @package Mindful_Import
 */

namespace Mindful_Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin lifecycle.
 */
class Plugin {

	/**
	 * Whether init() already ran.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Registers every class's hooks. Runs on `plugins_loaded`.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		load_plugin_textdomain( 'mindful-import', false, dirname( plugin_basename( MINDFUL_IMPORT_FILE ) ) . '/languages' );

		Settings::init();
		Auth::init();
		Post_Types::init();
		Fields::init();
		Changelog::init();
		Redirects::init();
		REST::init();
		Updater::init();
		Registration::init();

		if ( did_action( 'plugins_loaded' ) ) {
			self::maybe_upgrade();
		} else {
			add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ), 20 );
		}
	}

	/**
	 * Runs once per plugin version, since auto-updates and mu-plugin installs never fire the
	 * activation hook: fills in new defaults, resets the role's capabilities, makes sure the
	 * retention cron exists, drops change-log rows about the service account and, when the site is
	 * not registered yet, queues a registration attempt on WP-Cron (never inline: this can run on any
	 * front-end request). A failed attempt restores the existing key, so a key Mindful already holds
	 * keeps working.
	 */
	public static function maybe_upgrade() {
		if ( MINDFUL_IMPORT_VERSION === (string) Settings::get( 'installed_version', '' ) ) {
			return;
		}
		Settings::ensure_defaults();
		Auth::ensure_role_and_user();
		Changelog::schedule_retention();
		Changelog::forget_import_user();
		Settings::delete( 'last_seen' ); // Pre-release versions recorded the last key use here.
		Settings::update( array( 'installed_version' => MINDFUL_IMPORT_VERSION ) );
		if ( 'registered' !== (string) Settings::get( 'registration_status', '' ) ) {
			Registration::schedule_now();
		}
	}

	/**
	 * Activation: defaults, role + user, change-log table, retention cron, then import registration
	 * (inline: activation runs in an admin or CLI request, so a blocking call is fine). The outcome
	 * is recorded in the settings; a failure schedules retries.
	 */
	public static function activate() {
		Settings::ensure_defaults();
		Auth::ensure_role_and_user();
		Changelog::install_table();
		Changelog::schedule_retention();
		Settings::update( array( 'installed_version' => MINDFUL_IMPORT_VERSION ) );
		Registration::register();
	}

	/**
	 * Deactivation: stop the retention cron and any pending registration retry. Data is kept until
	 * uninstall.
	 */
	public static function deactivate() {
		Changelog::unschedule_retention();
		Registration::unschedule();
	}
}
