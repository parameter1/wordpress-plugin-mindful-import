<?php
/**
 * Self-update from GitHub releases via the bundled plugin-update-checker.
 *
 * @package Mindful_Import
 */

namespace Mindful_Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub release updater.
 */
class Updater {

	const REPO_URL = 'https://github.com/parameter1/wordpress-plugin-mindful-import/';

	/**
	 * 'active' | 'vendor-missing' | 'error'.
	 *
	 * @var string
	 */
	private static $status = 'vendor-missing';

	/**
	 * Boots the update checker when the vendored library is present.
	 */
	public static function init() {
		$file = MINDFUL_IMPORT_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! file_exists( $file ) ) {
			self::$status = 'vendor-missing';
			return;
		}

		try {
			require_once $file;
			$factory = 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
			if ( ! class_exists( $factory ) ) {
				self::$status = 'vendor-missing';
				return;
			}
			$checker = call_user_func( array( $factory, 'buildUpdateChecker' ), self::REPO_URL, MINDFUL_IMPORT_FILE, 'mindful-import' );
			if ( $checker && method_exists( $checker, 'getVcsApi' ) ) {
				$api = $checker->getVcsApi();
				if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
					$api->enableReleaseAssets();
				}
			}
			self::$status = 'active';
		} catch ( \Throwable $e ) {
			self::$status = 'vendor-missing';
		}
	}

	/**
	 * Updater status for the settings page.
	 *
	 * @return string 'active' | 'vendor-missing'
	 */
	public static function status() {
		return self::$status;
	}
}
