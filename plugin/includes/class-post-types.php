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
	 * Registers hooks.
	 */
	public static function init() {
		add_filter( 'register_post_type_args', array( __CLASS__, 'post_type_args' ), 20, 2 );
		add_filter( 'register_taxonomy_args', array( __CLASS__, 'taxonomy_args' ), 20, 2 );
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
