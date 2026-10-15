<?php
/**
 * Plugin Name: Mindful Import test fixtures (dev only)
 * Description: Registers a CPT and taxonomy that are NOT in REST (the plugin must force them in), a shortcode
 *              to prove rendering, a non-public CPT that must stay out of REST, and a mock Mindful
 *              registration receiver. Never ship this.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	function () {
		// Own capability_type (edit_news_items etc.), so the plugin's per-request capability grant is exercised.
		register_post_type(
			'news',
			array(
				'label'           => 'News',
				'public'          => true,
				'show_in_rest'    => false,
				'has_archive'     => true,
				'capability_type' => array( 'news_item', 'news_items' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'author' ),
			)
		);

		register_taxonomy(
			'news_topic',
			array( 'news' ),
			array(
				'label'        => 'News Topics',
				'public'       => true,
				'hierarchical' => true,
				'show_in_rest' => false,
			)
		);

		register_post_type(
			'secret_internal',
			array(
				'label'        => 'Secret Internal',
				'public'       => false,
				'show_in_rest' => false,
				'supports'     => array( 'title', 'editor' ),
			)
		);

		add_shortcode(
			'mi_test',
			function () {
				return '<strong>rendered-ok</strong>';
			}
		);
	}
);

// Reports who an admin-ajax request runs as, so the smoke test can prove the key is ignored there.
function mi_test_whoami() {
	wp_send_json(
		array(
			'user'       => get_current_user_id(),
			'edit_posts' => current_user_can( 'edit_posts' ),
		)
	);
}
add_action( 'wp_ajax_nopriv_mi_whoami', 'mi_test_whoami' );
add_action( 'wp_ajax_mi_whoami', 'mi_test_whoami' );

// Mock Mindful registration endpoint (MINDFUL_IMPORT_REGISTRATION_URL points here in docker-compose.yml).
// Keeps the last 10 registrations in the mi_dev_registrations option; set mi_dev_registration_fail to make it 500.
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'mi-dev/v1',
			'/registrations',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					if ( get_option( 'mi_dev_registration_fail' ) ) {
						return new WP_REST_Response(
							array(
								'code'    => 'mi_dev_forced_failure',
								'message' => 'Registration receiver is set to fail (mi_dev_registration_fail).',
							),
							500
						);
					}
					$params    = (array) $request->get_json_params();
					$entries   = (array) get_option( 'mi_dev_registrations', array() );
					$entries[] = array(
						'site'        => isset( $params['site'] ) ? (string) $params['site'] : '',
						'key'         => isset( $params['key'] ) ? (string) $params['key'] : '',
						'user_agent'  => (string) $request->get_header( 'user-agent' ),
						'received_at' => time(),
					);
					update_option( 'mi_dev_registrations', array_slice( $entries, -10 ), false );
					return new WP_REST_Response( array( 'ok' => true ), 201 );
				},
			)
		);
	}
);

// Last registration the mock receiver accepted (for `wp eval`), or null.
function mi_dev_last_registration() {
	$entries = (array) get_option( 'mi_dev_registrations', array() );
	return empty( $entries ) ? null : end( $entries );
}
