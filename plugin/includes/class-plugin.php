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
	}

	/**
	 * Activation: defaults, role + user, change-log table, retention cron.
	 */
	public static function activate() {
		Settings::ensure_defaults();
		Auth::ensure_role_and_user();
		Changelog::install_table();
		Changelog::schedule_retention();
	}

	/**
	 * Deactivation: stop the retention cron. Data is kept until uninstall.
	 */
	public static function deactivate() {
		Changelog::unschedule_retention();
	}
}
