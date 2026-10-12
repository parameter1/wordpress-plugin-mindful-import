<?php
/**
 * Plugin Name: Mindful Import: Example Companion
 * Description: Reference for per-site extensions of Mindful Import. Copy, rename, keep what you need.
 * Version: 1.0.0
 * Requires Plugins: mindful-import
 * Requires PHP: 7.4
 * Author: Parameter1
 * License: MIT
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'plugins_loaded',
	function () {
		// Core plugin missing, or a hook API this file was not written against: do nothing.
		if ( ! function_exists( 'mindful_import' ) ) {
			return;
		}
		if ( 1 !== mindful_import()['hook_api'] ) {
			return;
		}

		// Extra field on every post's post_meta_fields (key-authenticated requests only).
		add_filter(
			'mindful_import_post_fields',
			function ( $fields, $post ) {
				$words                          = str_word_count( wp_strip_all_tags( (string) $post->post_content ) );
				$fields['example_reading_time'] = max( 1, (int) ceil( $words / 200 ) );
				return $fields;
			},
			10,
			2
		);

		// Expose every option starting with "example_". Returning [names, prefixes] changes both lists;
		// returning a flat array of names changes only the names.
		add_filter(
			'mindful_import_options_allowlist',
			function ( $names, $prefixes ) {
				$prefixes[] = 'example_';
				return array( $names, $prefixes );
			},
			10,
			2
		);

		// Never log changes to an internal post type. Returning null skips the entry.
		add_filter(
			'mindful_import_change_entry',
			function ( $entry ) {
				if ( is_array( $entry ) && 'secret_internal' === ( $entry['post_type'] ?? null ) ) {
					return null;
				}
				return $entry;
			}
		);

		// Extra key-protected route: GET /wp-json/mindful-import/v1/example/ping.
		// add_route() injects the key permission_callback and defaults methods to GET.
		add_action(
			'mindful_import_register_resource',
			function () {
				\Mindful_Import\REST::add_route( 'example/ping', array( 'callback' => fn() => array( 'pong' => true ) ) );
			}
		);

		// Advertise this companion in the discovery document.
		add_filter(
			'mindful_import_discovery',
			function ( $discovery ) {
				$discovery['example']['companion'] = true;
				return $discovery;
			}
		);
	}
);
