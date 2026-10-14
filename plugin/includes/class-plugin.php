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

		if ( did_action( 'plugins_loaded' ) ) {
			self::maybe_upgrade();
		} else {
			add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ), 20 );
		}
	}

	/**
	 * Runs once per plugin version, since auto-updates never fire the activation hook: fills in
	 * new defaults and resets the role's capabilities.
	 */
	public static function maybe_upgrade() {
		if ( MINDFUL_IMPORT_VERSION === (string) Settings::get( 'installed_version', '' ) ) {
			return;
		}
		Settings::ensure_defaults();
		Auth::ensure_role_and_user();
		Settings::update( array( 'installed_version' => MINDFUL_IMPORT_VERSION ) );
	}

	/**
	 * Activation: defaults, role + user, change-log table, retention cron.
	 */
	public static function activate() {
		Settings::ensure_defaults();
		Auth::ensure_role_and_user();
		Changelog::install_table();
		Changelog::schedule_retention();
		Settings::update( array( 'installed_version' => MINDFUL_IMPORT_VERSION ) );
	}

	/**
	 * Deactivation: stop the retention cron. Data is kept until uninstall.
	 */
	public static function deactivate() {
		Changelog::unschedule_retention();
	}
}
