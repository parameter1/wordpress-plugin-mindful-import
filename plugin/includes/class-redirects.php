<?php
/**
 * Redirect rules from the common redirect plugins, normalized.
 *
 * @package Mindful_Import
 */

namespace Mindful_Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads redirects from Redirection, Safe Redirect Manager and Yoast SEO Premium.
 * Each provider is read straight from its storage, so rules are found even when
 * the owning plugin is deactivated.
 */
class Redirects {

	const PROVIDER_REDIRECTION = 'redirection';
	const PROVIDER_SRM         = 'safe-redirect-manager';
	const PROVIDER_YOAST       = 'yoast-premium';

	/**
	 * No hooks of its own; routes are registered by REST::register().
	 */
	public static function init() {
	}

	/**
	 * Every redirect from every detected provider.
	 *
	 * @return array{providers: string[], redirects: array[], count: int}
	 */
	public static function all() {
		$providers = array();
		$redirects = array();

		$sources = array(
			self::PROVIDER_REDIRECTION => array( __CLASS__, 'from_redirection' ),
			self::PROVIDER_SRM         => array( __CLASS__, 'from_safe_redirect_manager' ),
			self::PROVIDER_YOAST       => array( __CLASS__, 'from_yoast_premium' ),
		);
		foreach ( $sources as $name => $reader ) {
			$rows = call_user_func( $reader );
			if ( null === $rows ) {
				continue;
			}
			$providers[] = $name;
			$redirects   = array_merge( $redirects, $rows );
		}

		return array(
			'providers' => $providers,
			'redirects' => $redirects,
			'count'     => count( $redirects ),
		);
	}

	/**
	 * Builds one normalized row.
	 *
	 * @param string      $source     Source path/pattern.
	 * @param string      $target     Target URL ('' for 410-style rules).
	 * @param int         $code       HTTP status.
	 * @param bool        $enabled    Whether the rule is active.
	 * @param bool        $regex      Whether source is a regular expression.
	 * @param string      $provider   Provider id.
	 * @param string|null $match_type Provider-specific match type, when any.
	 * @return array
	 */
	private static function row( $source, $target, $code, $enabled, $regex, $provider, $match_type = null ) {
		return array(
			'source'     => (string) $source,
			'target'     => (string) $target,
			'code'       => (int) $code,
			'enabled'    => (bool) $enabled,
			'regex'      => (bool) $regex,
			'provider'   => $provider,
			'match_type' => $match_type,
		);
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return $found === $table;
	}

	/**
	 * Redirection plugin (`redirection_items` / `redirection_groups`). Only action_type 'url'.
	 *
	 * @return array[]|null Null when the plugin's tables are absent.
	 */
	public static function from_redirection() {
		global $wpdb;
		$items  = $wpdb->prefix . 'redirection_items';
		$groups = $wpdb->prefix . 'redirection_groups';
		if ( ! self::table_exists( $items ) ) {
			return null;
		}

		if ( self::table_exists( $groups ) ) {
			$sql = "SELECT i.url, i.action_data, i.action_code, i.status, i.regex, i.match_type, g.status AS group_status
				FROM {$items} i LEFT JOIN {$groups} g ON g.id = i.group_id
				WHERE i.action_type = %s ORDER BY i.id ASC";
		} else {
			$sql = "SELECT url, action_data, action_code, status, regex, match_type, NULL AS group_status
				FROM {$items} WHERE action_type = %s ORDER BY id ASC";
		}
		$results = $wpdb->get_results( $wpdb->prepare( $sql, 'url' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = array();
		foreach ( (array) $results as $r ) {
			$enabled = 'enabled' === $r->status && ( null === $r->group_status || 'enabled' === $r->group_status );
			$rows[]  = self::row(
				$r->url,
				self::redirection_target( $r->action_data ),
				(int) $r->action_code,
				$enabled,
				! empty( $r->regex ),
				self::PROVIDER_REDIRECTION,
				$r->match_type
			);
		}
		return $rows;
	}

	/**
	 * Resolves Redirection's action_data: a plain URL, or JSON/serialized data
	 * with a `url` (or `url_from` / `logged_in` for conditional matches) key.
	 *
	 * @param string|null $data Raw action_data.
	 * @return string
	 */
	private static function redirection_target( $data ) {
		$data = (string) $data;
		if ( '' === $data ) {
			return '';
		}
		$decoded = null;
		$first   = substr( ltrim( $data ), 0, 1 );
		if ( '{' === $first || '[' === $first ) {
			$decoded = json_decode( $data, true );
		} elseif ( is_serialized( $data ) ) {
			$decoded = maybe_unserialize( $data );
		}
		if ( ! is_array( $decoded ) ) {
			return $data;
		}
		foreach ( array( 'url', 'url_from', 'logged_in', 'agent_from' ) as $key ) {
			if ( ! empty( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
				return $decoded[ $key ];
			}
		}
		return '';
	}

	/**
	 * Safe Redirect Manager (`redirect_rule` posts).
	 *
	 * @return array[]|null Null when the post type is neither registered nor present.
	 */
	public static function from_safe_redirect_manager() {
		global $wpdb;
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_status FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'inherit') ORDER BY menu_order ASC, ID ASC",
				'redirect_rule'
			)
		);
		if ( empty( $posts ) && ! post_type_exists( 'redirect_rule' ) ) {
			return null;
		}

		$ids = wp_list_pluck( (array) $posts, 'ID' );
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}

		$rows = array();
		foreach ( (array) $posts as $p ) {
			$code   = (int) get_post_meta( $p->ID, '_redirect_rule_status_code', true );
			$rows[] = self::row(
				get_post_meta( $p->ID, '_redirect_rule_from', true ),
				get_post_meta( $p->ID, '_redirect_rule_to', true ),
				$code > 0 ? $code : 302,
				'publish' === $p->post_status,
				(bool) get_post_meta( $p->ID, '_redirect_rule_from_regex', true ),
				self::PROVIDER_SRM
			);
		}
		return $rows;
	}

	/**
	 * Yoast SEO Premium (`wpseo-premium-redirects-base` option).
	 *
	 * @return array[]|null Null when the option does not exist.
	 */
	public static function from_yoast_premium() {
		$base = get_option( 'wpseo-premium-redirects-base', null );
		if ( ! is_array( $base ) ) {
			return null;
		}
		$rows = array();
		foreach ( $base as $r ) {
			if ( ! is_array( $r ) || ! isset( $r['origin'] ) ) {
				continue;
			}
			$rows[] = self::row(
				$r['origin'],
				isset( $r['url'] ) ? $r['url'] : '',
				isset( $r['type'] ) ? (int) $r['type'] : 301,
				true,
				isset( $r['format'] ) && 'regex' === $r['format'],
				self::PROVIDER_YOAST,
				isset( $r['format'] ) ? (string) $r['format'] : null
			);
		}
		return $rows;
	}

	/**
	 * Registers `redirects`.
	 */
	public static function register_routes() {
		REST::add_route(
			'redirects',
			array(
				'callback' => array( __CLASS__, 'rest_redirects' ),
				'args'     => array(
					'provider' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * GET redirects.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_redirects( \WP_REST_Request $request ) {
		$data     = self::all();
		$provider = $request->get_param( 'provider' );
		if ( ! empty( $provider ) ) {
			$data['providers'] = array_values( array_intersect( $data['providers'], array( $provider ) ) );
			$data['redirects'] = array_values(
				array_filter(
					$data['redirects'],
					function ( $r ) use ( $provider ) {
						return $r['provider'] === $provider;
					}
				)
			);
			$data['count']     = count( $data['redirects'] );
		}
		return rest_ensure_response( $data );
	}
}
