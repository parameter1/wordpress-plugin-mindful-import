<?php
/**
 * Plugin Name: Mindful Import
 * Plugin URI: https://github.com/parameter1/wordpress-plugin-mindful-import
 * Description: Exposes the data a Mindful CMS import needs (post meta on every post type, a change log, users, term meta, allowlisted options and redirect rules) behind a key sent on the X-Mindful-Import-Key header. Read-only.
 * Version: 0.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Parameter1
 * Author URI: https://parameter1.com
 * License: MIT
 * Text Domain: mindful-import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MINDFUL_IMPORT_VERSION', '0.2.0' );
define( 'MINDFUL_IMPORT_HOOK_API', 1 );
define( 'MINDFUL_IMPORT_FILE', __FILE__ );
define( 'MINDFUL_IMPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'MINDFUL_IMPORT_URL', plugin_dir_url( __FILE__ ) );
define( 'MINDFUL_IMPORT_REST_NAMESPACE', 'mindful-import/v1' );
define( 'MINDFUL_IMPORT_HEADER', 'X-Mindful-Import-Key' );
define( 'MINDFUL_IMPORT_OPTION', 'mindful_import' );
define( 'MINDFUL_IMPORT_ROLE', 'mindful_import' );
define( 'MINDFUL_IMPORT_USER_LOGIN', 'mindful-import' );

// Where import registration sends the site's REST URL and key. wp-config.php may define it first; a non-https
// URL is refused unless WP_ENVIRONMENT_TYPE is local or development.
if ( ! defined( 'MINDFUL_IMPORT_REGISTRATION_URL' ) ) {
	define( 'MINDFUL_IMPORT_REGISTRATION_URL', 'https://apis.mindfulcms.com/import/wordpress/register' );
}

require_once MINDFUL_IMPORT_DIR . 'includes/hooks.php';
require_once MINDFUL_IMPORT_DIR . 'includes/class-settings.php';
require_once MINDFUL_IMPORT_DIR . 'includes/class-auth.php';
require_once MINDFUL_IMPORT_DIR . 'includes/class-registration.php';
require_once MINDFUL_IMPORT_DIR . 'includes/class-post-types.php';
require_once MINDFUL_IMPORT_DIR . 'includes/class-fields.php';
require_once MINDFUL_IMPORT_DIR . 'includes/class-changelog.php';
require_once MINDFUL_IMPORT_DIR . 'includes/class-redirects.php';
require_once MINDFUL_IMPORT_DIR . 'includes/class-rest.php';
require_once MINDFUL_IMPORT_DIR . 'includes/class-updater.php';
require_once MINDFUL_IMPORT_DIR . 'includes/class-plugin.php';

/**
 * Site-side code uses this to detect the plugin and its hook API version.
 *
 * @return array{version:string,hook_api:int}
 */
function mindful_import() {
	return array(
		'version'  => MINDFUL_IMPORT_VERSION,
		'hook_api' => MINDFUL_IMPORT_HOOK_API,
	);
}

register_activation_hook( __FILE__, array( 'Mindful_Import\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Mindful_Import\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Mindful_Import\\Plugin', 'init' ) );
