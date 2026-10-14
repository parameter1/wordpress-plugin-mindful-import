<?php
/**
 * REST fields: post/term meta and (optionally) rendered content for import requests.
 *
 * @package Mindful_Import
 */

namespace Mindful_Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fields.
 */
class Fields {

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_fields' ), 10 );
	}

	/**
	 * Registers the fields on every REST-visible post type and taxonomy, on import requests only.
	 * Anonymous responses are exactly what core produces: no extra keys, not even null ones.
	 */
	public static function register_fields() {
		if ( ! Auth::is_import_request() ) {
			return;
		}

		$meta_schema = array(
			'description' => __( 'All meta for the object (Mindful Import key required).', 'mindful-import' ),
			'type'        => array( 'object', 'null' ),
			'context'     => array( 'view', 'edit' ),
			'readonly'    => true,
		);

		$post_types = get_post_types( array( 'show_in_rest' => true ), 'names' );
		foreach ( $post_types as $post_type ) {
			register_rest_field(
				$post_type,
				'post_meta_fields',
				array(
					'get_callback' => array( __CLASS__, 'get_post_meta_fields' ),
					'schema'       => $meta_schema,
				)
			);
		}

		foreach ( get_taxonomies( array( 'show_in_rest' => true ), 'names' ) as $taxonomy ) {
			register_rest_field(
				$taxonomy,
				'term_meta_fields',
				array(
					'get_callback' => array( __CLASS__, 'get_term_meta_fields' ),
					'schema'       => $meta_schema,
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag; auth checked in the callback.
		$wants_render = isset( $_GET['mindful_render'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['mindful_render'] ) );
		if ( $wants_render && Settings::get( 'render_enabled' ) ) {
			foreach ( $post_types as $post_type ) {
				if ( 'attachment' === $post_type ) {
					continue;
				}
				register_rest_field(
					$post_type,
					'mindful_rendered_content',
					array(
						'get_callback' => array( __CLASS__, 'get_rendered_content' ),
						'schema'       => array(
							'description' => __( 'post_content run through the_content (Mindful Import key required).', 'mindful-import' ),
							'type'        => array( 'string', 'null' ),
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
					)
				);
			}
		}
	}

	/**
	 * Object id from a REST prepared object.
	 *
	 * @param array|object $object Prepared object.
	 * @return int
	 */
	private static function object_id( $object ) {
		if ( is_array( $object ) && isset( $object['id'] ) ) {
			return (int) $object['id'];
		}
		if ( is_object( $object ) && isset( $object->ID ) ) {
			return (int) $object->ID;
		}
		if ( is_object( $object ) && isset( $object->term_id ) ) {
			return (int) $object->term_id;
		}
		return 0;
	}

	/**
	 * get_callback for post_meta_fields.
	 *
	 * @param array            $object     Prepared post.
	 * @param string           $field_name Field.
	 * @param \WP_REST_Request $request    Request.
	 * @return array|null
	 */
	public static function get_post_meta_fields( $object, $field_name = '', $request = null ) {
		if ( ! Auth::is_import_request( $request ) ) {
			return null;
		}
		$post = get_post( self::object_id( $object ) );
		if ( ! $post ) {
			return null;
		}
		$meta   = get_post_meta( $post->ID );
		$fields = self::flatten_meta( is_array( $meta ) ? $meta : array() );
		return apply_filters( 'mindful_import_post_fields', $fields, $post );
	}

	/**
	 * get_callback for term_meta_fields.
	 *
	 * @param array            $object     Prepared term.
	 * @param string           $field_name Field.
	 * @param \WP_REST_Request $request    Request.
	 * @return array|null
	 */
	public static function get_term_meta_fields( $object, $field_name = '', $request = null ) {
		if ( ! Auth::is_import_request( $request ) ) {
			return null;
		}
		$term = get_term( self::object_id( $object ) );
		if ( ! ( $term instanceof \WP_Term ) ) {
			return null;
		}
		$meta   = get_term_meta( $term->term_id );
		$fields = self::flatten_meta( is_array( $meta ) ? $meta : array() );
		return apply_filters( 'mindful_import_term_fields', $fields, $term );
	}

	/**
	 * get_callback for mindful_rendered_content.
	 *
	 * @param array            $object     Prepared post.
	 * @param string           $field_name Field.
	 * @param \WP_REST_Request $request    Request.
	 * @return string|null
	 */
	public static function get_rendered_content( $object, $field_name = '', $request = null ) {
		if ( ! Settings::get( 'render_enabled' ) || ! Auth::is_import_request( $request ) ) {
			return null;
		}
		$target = get_post( self::object_id( $object ) );
		if ( ! $target ) {
			return null;
		}

		global $post;
		$previous = $post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		$post = $target;
		setup_postdata( $target );

		$rendered = apply_filters( 'the_content', $target->post_content );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
		$post = $previous;
		if ( $previous instanceof \WP_Post ) {
			setup_postdata( $previous );
		} else {
			wp_reset_postdata();
		}

		return is_string( $rendered ) ? $rendered : null;
	}

	/**
	 * Flattens get_*_meta() output into the base-imports shape: `.` in keys becomes `__`,
	 * single-element arrays become their element, multi-value arrays are kept. Values are
	 * left exactly as stored (serialized strings stay serialized).
	 *
	 * @param array $meta Meta as returned by get_post_meta( $id ).
	 * @return array
	 */
	public static function flatten_meta( array $meta ) {
		$out = array();
		foreach ( $meta as $key => $value ) {
			$key = str_replace( '.', '__', (string) $key );
			if ( is_array( $value ) && 1 === count( $value ) ) {
				$value = reset( $value );
			}
			$out[ $key ] = $value;
		}
		return $out;
	}
}
