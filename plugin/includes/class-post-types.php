<?php
/**
 * Puts every public post type and taxonomy into the REST API, on key-authenticated requests only.
 *
 * Anonymous visitors and the admin (including the block-editor choice, which core derives from
 * show_in_rest) see every type exactly as the site registered it.
 *
 * @package Mindful_Import
 */

namespace Mindful_Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post_Types.
 */
class Post_Types {

	/**
	 * Core types already in REST; their args are left untouched.
	 *
	 * @var string[]
	 */
	private static $core_types = array( 'post', 'page', 'attachment' );

	/**
	 * Private WP_Query var carrying the `min_id` cursor from the REST query filter to posts_where.
	 */
	const MIN_ID_VAR = 'mindful_import_min_id';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_filter( 'register_post_type_args', array( __CLASS__, 'post_type_args' ), 20, 2 );
		add_filter( 'register_taxonomy_args', array( __CLASS__, 'taxonomy_args' ), 20, 2 );
		// Before create_initial_rest_routes (priority 99) builds each controller's collection params.
		add_action( 'rest_api_init', array( __CLASS__, 'register_min_id' ), 5 );
		add_filter( 'posts_where', array( __CLASS__, 'min_id_where' ), 10, 2 );
	}

	/**
	 * Adds the `min_id` collection parameter (ID >= min_id) to every REST-visible post type,
	 * attachments included, on key-authenticated requests only. Anonymous requests never see the
	 * parameter, so core ignores it there like any unknown query arg and the response is exactly
	 * what core returns.
	 *
	 * An id cursor lets the importer page with `orderby=id&order=asc&min_id=<last id + 1>` instead
	 * of `page=N`, so a post trashed on an earlier page mid-run cannot shift a later one out of the
	 * window.
	 */
	public static function register_min_id() {
		if ( ! Auth::is_import_request() ) {
			return;
		}
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $post_type ) {
			add_filter( "rest_{$post_type}_collection_params", array( __CLASS__, 'min_id_collection_param' ) );
			add_filter( "rest_{$post_type}_query", array( __CLASS__, 'min_id_query' ), 10, 2 );
		}
	}

	/**
	 * `rest_{$post_type}_collection_params`: declares `min_id`. Validated like core's `page`, so a
	 * non-integer or negative value answers 400 (rest_invalid_param) before the query runs.
	 *
	 * @param array $params Collection params.
	 * @return array
	 */
	public static function min_id_collection_param( $params ) {
		$params['min_id'] = array(
			'description'       => __( 'Limit results to ids greater than or equal to this one (Mindful Import key required).', 'mindful-import' ),
			'type'              => 'integer',
			'minimum'           => 0,
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'absint',
		);
		return $params;
	}

	/**
	 * `rest_{$post_type}_query`: copies `min_id` into a private query var for min_id_where().
	 *
	 * @param array            $args    WP_Query args.
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function min_id_query( $args, $request ) {
		if ( ! ( $request instanceof \WP_REST_Request ) || ! Auth::is_import_request( $request ) ) {
			return $args;
		}
		$min_id = absint( $request->get_param( 'min_id' ) );
		if ( $min_id > 0 ) {
			$args[ self::MIN_ID_VAR ] = $min_id;
		}
		return $args;
	}

	/**
	 * `posts_where`: applies `ID >= min_id` to queries carrying the private var, and to no other.
	 *
	 * @param string    $where WHERE clause.
	 * @param \WP_Query $query Query.
	 * @return string
	 */
	public static function min_id_where( $where, $query ) {
		if ( ! ( $query instanceof \WP_Query ) ) {
			return $where;
		}
		$min_id = absint( $query->get( self::MIN_ID_VAR ) );
		if ( $min_id > 0 ) {
			global $wpdb;
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID >= %d", $min_id );
		}
		return $where;
	}

	/**
	 * Whether a name is enabled in a settings map (missing = enabled).
	 *
	 * @param string $setting 'post_types' | 'taxonomies'.
	 * @param string $name    Type name.
	 * @return bool
	 */
	private static function is_enabled( $setting, $name ) {
		$flags = Settings::get( $setting, array() );
		if ( ! is_array( $flags ) || ! array_key_exists( $name, $flags ) ) {
			return true;
		}
		return false !== (bool) $flags[ $name ];
	}

	/**
	 * Applies show_in_rest/rest_base to enabled public args.
	 *
	 * @param array  $args Registration args.
	 * @param string $name Type/taxonomy name.
	 * @return array
	 */
	private static function apply_rest( array $args, $name ) {
		$args['show_in_rest'] = true;
		if ( empty( $args['rest_base'] ) ) {
			$args['rest_base'] = $name;
		}
		return $args;
	}

	/**
	 * `register_post_type_args`.
	 *
	 * @param array  $args      Args.
	 * @param string $post_type Post type.
	 * @return array
	 */
	public static function post_type_args( $args, $post_type ) {
		if ( ! is_array( $args ) || ! empty( $args['_builtin'] ) || in_array( $post_type, self::$core_types, true ) ) {
			return $args;
		}
		if ( empty( $args['public'] ) || ! self::is_enabled( 'post_types', $post_type ) ) {
			return $args;
		}
		if ( ! Auth::is_import_request() ) {
			return $args; // No key on this request: leave the type as the site registered it.
		}
		return self::apply_rest( $args, $post_type );
	}

	/**
	 * `register_taxonomy_args`.
	 *
	 * @param array  $args     Args.
	 * @param string $taxonomy Taxonomy.
	 * @return array
	 */
	public static function taxonomy_args( $args, $taxonomy ) {
		if ( ! is_array( $args ) || ! empty( $args['_builtin'] ) ) {
			return $args;
		}
		// Taxonomies default to public when the arg is omitted.
		$public = array_key_exists( 'public', $args ) ? (bool) $args['public'] : true;
		if ( ! $public || ! self::is_enabled( 'taxonomies', $taxonomy ) ) {
			return $args;
		}
		if ( ! empty( $args['show_in_rest'] ) ) {
			return $args; // Already in REST (core category/post_tag, or plugin-registered): leave it.
		}
		if ( ! Auth::is_import_request() ) {
			return $args; // No key on this request: leave the taxonomy as the site registered it.
		}
		return self::apply_rest( $args, $taxonomy );
	}

	/**
	 * Public and enabled post types.
	 *
	 * @return string[]
	 */
	public static function enabled_post_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $name ) {
			if ( self::is_enabled( 'post_types', $name ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Public and enabled taxonomies.
	 *
	 * @return string[]
	 */
	public static function enabled_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $name ) {
			if ( self::is_enabled( 'taxonomies', $name ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}
}
