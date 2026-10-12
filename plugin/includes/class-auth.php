<?php
/**
 * Key authentication, the import role and the import user.
 *
 * @package Mindful_Import
 */

namespace Mindful_Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auth.
 */
class Auth {

	/**
	 * Resolved user id for this request (0 = not an import request). Null = not resolved yet.
	 *
	 * @var int|null
	 */
	private static $user_id = null;

	/**
	 * Re-entrancy guard for resolve().
	 *
	 * @var bool
	 */
	private static $resolving = false;

	/**
	 * Last WP_REST_Request resolve() was run against (avoids re-running per call).
	 *
	 * @var \WP_REST_Request|null
	 */
	private static $checked_request = null;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_filter( 'determine_current_user', array( __CLASS__, 'determine_current_user' ), 20 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_authentication_errors' ), 90 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_pre_dispatch' ), 10, 3 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_interactive_login' ), 10, 1 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'block_application_passwords' ), 10, 2 );
	}

	/**
	 * Whether the current request carried a valid key (or a companion authenticated it).
	 *
	 * @param \WP_REST_Request|null $request Optional request to read the header from.
	 * @return bool
	 */
	public static function is_import_request( $request = null ) {
		if ( null === self::$user_id ) {
			self::resolve( null );
		}
		if ( 0 === self::$user_id && $request instanceof \WP_REST_Request && self::$checked_request !== $request ) {
			self::$checked_request = $request;
			self::resolve( $request );
			if ( self::$user_id > 0 && get_current_user_id() !== self::$user_id ) {
				// Authenticated late (header only visible on the request object): switch user now.
				wp_set_current_user( self::$user_id );
			}
		}
		return self::$user_id > 0;
	}

	/**
	 * REST permission callback for every plugin route.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function permission_callback( $request ) {
		if ( self::is_import_request( $request ) ) {
			return true;
		}
		return new \WP_Error(
			'mindful_import_unauthorized',
			__( 'A valid Mindful Import key is required.', 'mindful-import' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Resolves the import user for this request from the header or the companion filter.
	 *
	 * @param \WP_REST_Request|null $request Request, when available.
	 */
	private static function resolve( $request ) {
		if ( self::$resolving ) {
			return;
		}
		self::$resolving = true;

		$user_id = 0;
		// Only REST requests are ever authenticated by this plugin.
		$is_rest = null !== $request || self::is_rest_uri();
		$plain   = $is_rest ? self::header_value( $request ) : null;

		if ( null !== $plain && self::verify_key( $plain ) ) {
			$user_id = self::import_user_id();
		}

		if ( ! $user_id && $is_rest ) {
			/**
			 * Lets a companion plugin authenticate the request when the key path fails.
			 *
			 * @param int|null              $user_id Null when not authenticated.
			 * @param \WP_REST_Request|null $request Request, when available.
			 */
			$filtered = apply_filters( 'mindful_import_authenticate', null, $request );
			if ( is_numeric( $filtered ) && (int) $filtered > 0 && get_userdata( (int) $filtered ) ) {
				$user_id = (int) $filtered;
			}
		}

		self::$user_id   = $user_id;
		self::$resolving = false;

		if ( $user_id ) {
			self::record_last_seen();
		}
	}

	/**
	 * Reads the key header.
	 *
	 * @param \WP_REST_Request|null $request Request.
	 * @return string|null
	 */
	private static function header_value( $request ) {
		$value = null;
		if ( $request instanceof \WP_REST_Request ) {
			$value = $request->get_header( MINDFUL_IMPORT_HEADER );
		}
		if ( ( null === $value || '' === $value ) && isset( $_SERVER['HTTP_X_MINDFUL_IMPORT_KEY'] ) ) {
			$value = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MINDFUL_IMPORT_KEY'] ) );
		}
		if ( null === $value ) {
			return null;
		}
		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}

	/**
	 * Whether the URI targets the REST API (the key is honoured on REST requests only).
	 *
	 * @return bool
	 */
	private static function is_rest_uri() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing check only.
		if ( isset( $_GET['rest_route'] ) ) {
			return true;
		}
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$uri    = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		$home   = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$base   = rtrim( $home, '/' ) . $prefix;
		return 0 === strpos( $uri, $base );
	}

	/**
	 * `determine_current_user`: become the import user on a key-authenticated request.
	 *
	 * @param int|false $user_id Current user id.
	 * @return int|false
	 */
	public static function determine_current_user( $user_id ) {
		if ( self::$resolving ) {
			return $user_id;
		}
		if ( self::is_import_request() ) {
			return self::$user_id;
		}
		return $user_id;
	}

	/**
	 * `rest_authentication_errors`: report success so the cookie-nonce check does not 401.
	 *
	 * @param \WP_Error|null|true $result Prior result.
	 * @return \WP_Error|null|true
	 */
	public static function rest_authentication_errors( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( self::is_import_request() ) {
			return true;
		}
		return $result;
	}

	/**
	 * `rest_pre_dispatch`: import requests are read-only.
	 *
	 * @param mixed            $result  Prior result.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function rest_pre_dispatch( $result, $server, $request ) {
		if ( ! ( $request instanceof \WP_REST_Request ) || ! self::is_import_request() ) {
			return $result;
		}
		$method = strtoupper( $request->get_method() );
		if ( ! in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return new \WP_Error(
				'mindful_import_read_only',
				__( 'Mindful Import access is read-only.', 'mindful-import' ),
				array( 'status' => 403 )
			);
		}
		return $result;
	}

	/**
	 * Generates a new key, stores its salted hash and returns the plaintext once.
	 *
	 * @return string
	 */
	public static function generate_key() {
		$plain = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$salt  = bin2hex( random_bytes( 16 ) );
		Settings::update(
			array(
				'key_hash'       => hash( 'sha256', $salt . $plain ),
				'key_salt'       => $salt,
				'key_created_at' => time(),
			)
		);
		return $plain;
	}

	/**
	 * Constant-time key check.
	 *
	 * @param string $plain Plaintext key.
	 * @return bool
	 */
	public static function verify_key( $plain ) {
		$hash = (string) Settings::get( 'key_hash', '' );
		$salt = (string) Settings::get( 'key_salt', '' );
		if ( '' === $hash || '' === $salt || '' === (string) $plain ) {
			return false;
		}
		return hash_equals( $hash, hash( 'sha256', $salt . (string) $plain ) );
	}

	/**
	 * Stores last_seen at most once per 60 seconds.
	 */
	private static function record_last_seen() {
		$last = Settings::get( 'last_seen', array() );
		$now  = time();
		if ( is_array( $last ) && ! empty( $last['at'] ) && ( $now - (int) $last['at'] ) < 60 ) {
			return;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $ip && false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '';
		}
		Settings::update(
			array(
				'last_seen' => array(
					'at' => $now,
					'ip' => $ip,
				),
			)
		);
	}

	/**
	 * Capabilities for the import role (read + edit-level reads; no publish/delete/manage_options).
	 *
	 * @return array
	 */
	private static function role_caps() {
		$caps = array(
			'read',
			'read_private_posts',
			'read_private_pages',
			'edit_posts',
			'edit_others_posts',
			'edit_private_posts',
			'edit_published_posts',
			'edit_pages',
			'edit_others_pages',
			'edit_private_pages',
			'edit_published_pages',
			'list_users',
			'upload_files',
			'manage_categories',
		);
		return array_fill_keys( $caps, true );
	}

	/**
	 * Creates (or repairs) the role and the import user.
	 *
	 * @return int Import user id (0 on failure).
	 */
	public static function ensure_role_and_user() {
		$caps = self::role_caps();
		$role = get_role( MINDFUL_IMPORT_ROLE );
		if ( ! $role ) {
			add_role( MINDFUL_IMPORT_ROLE, 'Mindful Import', $caps );
		} else {
			foreach ( $caps as $cap => $grant ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap, true );
				}
			}
		}

		$user_id = self::import_user_id();
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user && ! in_array( MINDFUL_IMPORT_ROLE, (array) $user->roles, true ) ) {
				$user->set_role( MINDFUL_IMPORT_ROLE );
			}
			return $user_id;
		}

		$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$user_id = wp_insert_user(
			array(
				'user_login'   => MINDFUL_IMPORT_USER_LOGIN,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'user_email'   => 'mindful-import@' . ( '' !== $host ? $host : 'localhost' ),
				'display_name' => 'Mindful Import',
				'nickname'     => 'Mindful Import',
				'role'         => MINDFUL_IMPORT_ROLE,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return 0;
		}
		Settings::update( array( 'import_user_id' => (int) $user_id ) );
		return (int) $user_id;
	}

	/**
	 * The import user id, re-resolved by login if the stored id is gone.
	 *
	 * @return int
	 */
	public static function import_user_id() {
		$stored = (int) Settings::get( 'import_user_id', 0 );
		if ( $stored ) {
			$user = get_userdata( $stored );
			if ( $user && MINDFUL_IMPORT_USER_LOGIN === $user->user_login ) {
				return $stored;
			}
		}
		$user = get_user_by( 'login', MINDFUL_IMPORT_USER_LOGIN );
		if ( ! $user ) {
			return 0;
		}
		if ( (int) $user->ID !== $stored ) {
			Settings::update( array( 'import_user_id' => (int) $user->ID ) );
		}
		return (int) $user->ID;
	}

	/**
	 * `wp_authenticate_user`: the import user never logs in interactively.
	 *
	 * @param \WP_User|\WP_Error $user User.
	 * @return \WP_User|\WP_Error
	 */
	public static function block_interactive_login( $user ) {
		if ( $user instanceof \WP_User && MINDFUL_IMPORT_USER_LOGIN === $user->user_login ) {
			return new \WP_Error( 'mindful_import_no_login', __( 'This account is used by Mindful Import only and cannot log in.', 'mindful-import' ) );
		}
		return $user;
	}

	/**
	 * Application passwords are disabled for the import user.
	 *
	 * @param bool     $available Whether available.
	 * @param \WP_User $user      User.
	 * @return bool
	 */
	public static function block_application_passwords( $available, $user ) {
		if ( $user instanceof \WP_User && MINDFUL_IMPORT_USER_LOGIN === $user->user_login ) {
			return false;
		}
		return $available;
	}
}
