<?php
/**
 * REST routes: discovery, options, settings, users (+ changes and redirects via their classes).
 *
 * @package Mindful_Import
 */

namespace Mindful_Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the mindful-import/v1 namespace.
 */
class REST {

	/**
	 * Option names never returned via a prefix match (exact allowlist entries bypass this).
	 */
	const OPTION_DENY = '/(key|secret|token|password|passwd|salt|smtp|auth|private|license)/i';

	/**
	 * User meta keys never returned. One pattern per concern:
	 *  1. credential-looking names, matched as whole tokens (split on _ and -) so keyword, passions
	 *     and secretary pass; "key" is denied only as the last token (api_key), so key_skills passes;
	 *  2. core account keys behind any table prefix (wp_capabilities, wp_2_user_level, session_tokens);
	 *  3. admin UI preferences and screen options.
	 */
	const USER_META_DENY = array(
		'/(^|[_-])(pass|password|passwd|tokens?|secrets?|apikey|accesstoken|authtoken|secretkey|privatekey)([_-]|$)|(^|[_-])keys?$/i',
		'/(^|_)(session_tokens|capabilities|user_level|user-settings|user-settings-time|persisted_preferences|dashboard_quick_press_last_post_id|media_library_mode)$/i',
		'/^(dismissed_|closedpostboxes_|metaboxhidden_|meta-box-order_|screen_layout_|manage.+columnshidden$|.+_per_page$|nav_menu_recently_edited$|community-events-location$|show_admin_bar_front$|show_welcome_panel$|admin_color$|use_ssl$|rich_editing$|syntax_highlighting$|comment_shortcuts$|locale$|default_password_nag$)/i',
	);

	/**
	 * Hooks rest_api_init.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ), 5 );
		add_action(
			'rest_api_init',
			function () {
				/**
				 * Lets companion plugins register routes with REST::add_route().
				 *
				 * @param string $namespace mindful-import/v1
				 */
				do_action( 'mindful_import_register_resource', MINDFUL_IMPORT_REST_NAMESPACE );
			},
			20
		);
	}

	/**
	 * Registers a route under mindful-import/v1. Defaults: methods GET, Auth permission check.
	 *
	 * @param string $path Route path, relative to the namespace.
	 * @param array  $args One route array, or a list of route arrays (one per method).
	 * @return bool
	 */
	public static function add_route( $path, array $args ) {
		$is_list = ! empty( $args ) && array_keys( $args ) === range( 0, count( $args ) - 1 );
		if ( $is_list ) {
			foreach ( $args as $i => $route ) {
				$args[ $i ] = self::with_defaults( (array) $route );
			}
		} else {
			$args = self::with_defaults( $args );
		}
		return register_rest_route( MINDFUL_IMPORT_REST_NAMESPACE, '/' . ltrim( $path, '/' ), $args );
	}

	/**
	 * Fills methods and permission_callback when absent.
	 *
	 * @param array $route Route args.
	 * @return array
	 */
	private static function with_defaults( array $route ) {
		if ( ! isset( $route['methods'] ) ) {
			$route['methods'] = 'GET';
		}
		if ( ! isset( $route['permission_callback'] ) ) {
			$route['permission_callback'] = array( 'Mindful_Import\\Auth', 'permission_callback' );
		}
		return $route;
	}

	/**
	 * Registers the core routes.
	 */
	public static function register() {
		self::add_route( 'discovery', array( 'callback' => array( __CLASS__, 'discovery' ) ) );
		self::add_route(
			'options',
			array(
				'callback' => array( __CLASS__, 'options' ),
				'args'     => array(
					'names' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		self::add_route( 'settings', array( 'callback' => array( __CLASS__, 'settings' ) ) );
		self::add_route(
			'users',
			array(
				'callback' => array( __CLASS__, 'users' ),
				'args'     => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 100,
						'minimum'           => 1,
						'maximum'           => 500,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		Changelog::register_routes();
		Redirects::register_routes();
	}

	/**
	 * GET discovery: what the importer needs to plan a run.
	 *
	 * @return \WP_REST_Response
	 */
	public static function discovery() {
		global $wp_version;

		$post_types = array();
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			// Only advertise what an importer can fetch: public types and anything already in REST.
			if ( ! $type->public && ! $type->show_in_rest && 'attachment' !== $type->name ) {
				continue;
			}
			$counts   = wp_count_posts( $type->name );
			$statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
			if ( 'attachment' === $type->name ) {
				$statuses[] = 'inherit';
			}
			$by_status = array();
			foreach ( $statuses as $status ) {
				$by_status[ $status ] = isset( $counts->$status ) ? (int) $counts->$status : 0;
			}
			$by_status['total'] = array_sum( $by_status );

			$post_types[] = array(
				'name'         => $type->name,
				'label'        => $type->label,
				'public'       => (bool) $type->public,
				'show_in_rest' => (bool) $type->show_in_rest,
				'rest_base'    => $type->rest_base ? $type->rest_base : $type->name,
				'hierarchical' => (bool) $type->hierarchical,
				'taxonomies'   => array_values( get_object_taxonomies( $type->name ) ),
				'count'        => $by_status,
			);
		}

		$taxonomies = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $tax ) {
			if ( ! $tax->public && ! $tax->show_in_rest ) {
				continue;
			}
			$count        = wp_count_terms(
				array(
					'taxonomy'   => $tax->name,
					'hide_empty' => false,
				)
			);
			$taxonomies[] = array(
				'name'         => $tax->name,
				'label'        => $tax->label,
				'public'       => (bool) $tax->public,
				'show_in_rest' => (bool) $tax->show_in_rest,
				'rest_base'    => $tax->rest_base ? $tax->rest_base : $tax->name,
				'hierarchical' => (bool) $tax->hierarchical,
				'object_type'  => array_values( (array) $tax->object_type ),
				'count'        => is_wp_error( $count ) ? null : (int) $count,
			);
		}

		$theme   = wp_get_theme();
		$parent  = $theme->parent();
		$uploads = wp_upload_dir( null, false );

		$data = array(
			'plugin'       => array(
				'version'  => MINDFUL_IMPORT_VERSION,
				'hook_api' => MINDFUL_IMPORT_HOOK_API,
			),
			'wordpress'    => array(
				'version'   => $wp_version,
				'multisite' => is_multisite(),
				'locale'    => get_locale(),
			),
			'site'         => array(
				'url'                 => site_url(),
				'home'                => home_url(),
				'name'                => get_bloginfo( 'name' ),
				'timezone'            => wp_timezone_string(),
				'gmt_offset'          => (float) get_option( 'gmt_offset' ),
				'permalink_structure' => (string) get_option( 'permalink_structure' ),
				'uploads'             => $uploads['baseurl'],
				'rest_url'            => rest_url(),
			),
			'post_types'   => $post_types,
			'taxonomies'   => $taxonomies,
			'plugins'      => self::active_plugins(),
			'theme'        => array(
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'stylesheet' => $theme->get_stylesheet(),
				'parent'     => $parent ? array(
					'name'    => $parent->get( 'Name' ),
					'version' => $parent->get( 'Version' ),
				) : null,
			),
			'settings'     => array(
				'post_types'         => Settings::get( 'post_types', array() ),
				'taxonomies'         => Settings::get( 'taxonomies', array() ),
				'enabled_post_types' => array_values( (array) Post_Types::enabled_post_types() ),
				'enabled_taxonomies' => array_values( (array) Post_Types::enabled_taxonomies() ),
				'retention_days'     => (int) Settings::get( 'retention_days', 90 ),
				'render_enabled'     => (bool) Settings::get( 'render_enabled', true ),
			),
			'changes'      => array( 'head_id' => Changelog::head_id() ),
			'generated_at' => gmdate( 'c' ),
		);

		/**
		 * Filters the discovery payload.
		 *
		 * @param array $data
		 */
		return rest_ensure_response( apply_filters( 'mindful_import_discovery', $data ) );
	}

	/**
	 * Active plugins (site + network-active), each {file, name, version}.
	 *
	 * @return array[]
	 */
	private static function active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( array_unique( $active ) as $file ) {
			if ( ! isset( $all[ $file ] ) ) {
				continue;
			}
			$out[] = array(
				'file'    => $file,
				'name'    => $all[ $file ]['Name'],
				'version' => $all[ $file ]['Version'],
			);
		}
		return $out;
	}

	/**
	 * GET options: allowlisted site options.
	 *
	 * The `mindful_import_options_allowlist` filter receives ($names, $prefixes) and must
	 * return array( $names, $prefixes ); returning a flat list of names replaces only $names.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function options( \WP_REST_Request $request ) {
		global $wpdb;

		$names    = array_values( array_filter( array_map( 'strval', (array) Settings::get( 'options_allowlist', array() ) ) ) );
		$prefixes = array_values( array_filter( array_map( 'strval', (array) Settings::get( 'options_prefixes', array() ) ) ) );
		$filtered = apply_filters( 'mindful_import_options_allowlist', $names, $prefixes );
		if ( is_array( $filtered ) && isset( $filtered[0], $filtered[1] ) && is_array( $filtered[0] ) && is_array( $filtered[1] ) ) {
			$names    = $filtered[0];
			$prefixes = $filtered[1];
		} elseif ( is_array( $filtered ) ) {
			$names = $filtered;
		}
		$names    = array_values( array_filter( array_map( 'strval', $names ) ) );
		$prefixes = array_values( array_filter( array_map( 'strval', $prefixes ) ) );

		// Candidate names: autoloaded options, exact names, and any stored option matching a prefix.
		$candidates = array_keys( wp_load_alloptions() );
		$candidates = array_merge( $candidates, $names );
		foreach ( $prefixes as $prefix ) {
			$like       = $wpdb->esc_like( $prefix ) . '%';
			$candidates = array_merge(
				$candidates,
				(array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) )
			);
		}
		$candidates = array_unique( $candidates );

		$restrict = $request->get_param( 'names' );
		if ( ! empty( $restrict ) ) {
			$restrict   = array_filter( array_map( 'trim', explode( ',', (string) $restrict ) ) );
			$candidates = array_intersect( $candidates, $restrict );
		}

		$exact   = array_flip( $names );
		$options = array();
		$denied  = 0;
		foreach ( $candidates as $name ) {
			$name = (string) $name;
			if ( ! isset( $exact[ $name ] ) ) {
				if ( ! self::matches_prefix( $name, $prefixes ) ) {
					continue;
				}
				if ( preg_match( self::OPTION_DENY, $name ) ) {
					++$denied;
					continue;
				}
			}
			$value = get_option( $name, null );
			if ( null === $value && isset( $exact[ $name ] ) && false === self::option_exists( $name ) ) {
				continue;
			}
			$options[] = array(
				'key'   => $name,
				'value' => $value,
			);
		}

		usort(
			$options,
			function ( $a, $b ) {
				return strcmp( $a['key'], $b['key'] );
			}
		);

		return rest_ensure_response(
			array(
				'options'      => $options,
				'denied_count' => $denied,
			)
		);
	}

	/**
	 * Whether a name starts with any prefix.
	 *
	 * @param string   $name     Option name.
	 * @param string[] $prefixes Prefixes.
	 * @return bool
	 */
	private static function matches_prefix( $name, array $prefixes ) {
		foreach ( $prefixes as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an option row exists (distinguishes a stored null from a missing option).
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private static function option_exists( $name ) {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/**
	 * GET settings: the non-secret subset of the plugin's settings.
	 *
	 * @return \WP_REST_Response
	 */
	public static function settings() {
		return rest_ensure_response(
			array(
				'post_types'        => Settings::get( 'post_types', array() ),
				'taxonomies'        => Settings::get( 'taxonomies', array() ),
				'options_allowlist' => array_values( (array) Settings::get( 'options_allowlist', array() ) ),
				'options_prefixes'  => array_values( (array) Settings::get( 'options_prefixes', array() ) ),
				'retention_days'    => (int) Settings::get( 'retention_days', 90 ),
				'render_enabled'    => (bool) Settings::get( 'render_enabled', true ),
				'key_created_at'    => Settings::get( 'key_created_at' ),
				'last_seen'         => Settings::get( 'last_seen' ),
				'import_user_id'    => (int) Auth::import_user_id(),
				'version'           => MINDFUL_IMPORT_VERSION,
				'hook_api'          => MINDFUL_IMPORT_HOOK_API,
			)
		);
	}

	/**
	 * GET users: every user (authors or not), paginated, with allowlisted meta.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function users( \WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 500, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$query = new \WP_User_Query(
			array(
				'number'      => $per_page,
				'offset'      => ( $page - 1 ) * $per_page,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'fields'      => 'all',
				'count_total' => true,
			)
		);
		$total = (int) $query->get_total();

		$users = array();
		foreach ( (array) $query->get_results() as $user ) {
			$users[] = self::user_fields( $user );
		}

		$response = rest_ensure_response( $users );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );
		return $response;
	}

	/**
	 * One user's API shape.
	 *
	 * @param \WP_User $user User.
	 * @return array
	 */
	private static function user_fields( \WP_User $user ) {
		$meta = array();
		foreach ( (array) get_user_meta( $user->ID ) as $key => $values ) {
			if ( ! self::is_denied_user_meta( (string) $key ) ) {
				$meta[ $key ] = $values;
			}
		}

		$fields = array(
			'id'           => (int) $user->ID,
			'login'        => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'nicename'     => $user->user_nicename,
			'url'          => $user->user_url,
			'registered'   => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $user->user_registered . ' UTC' ) ),
			'roles'        => array_values( (array) $user->roles ),
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'nickname'     => $user->nickname,
			'description'  => $user->description,
			'avatar_url'   => get_avatar_url( $user->ID ),
			'meta'         => Fields::flatten_meta( $meta ),
		);

		/**
		 * Filters one user's fields.
		 *
		 * @param array    $fields
		 * @param \WP_User $user
		 */
		return apply_filters( 'mindful_import_user_fields', $fields, $user );
	}

	/**
	 * Whether a user meta key matches USER_META_DENY.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function is_denied_user_meta( $key ) {
		foreach ( self::USER_META_DENY as $pattern ) {
			if ( preg_match( $pattern, $key ) ) {
				return true;
			}
		}
		return false;
	}
}
