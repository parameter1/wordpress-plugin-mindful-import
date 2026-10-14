<?php
/**
 * Plugin Name: Mindful Import test fixtures (dev only)
 * Description: Registers a CPT and taxonomy that are NOT in REST (the plugin must force them in), a shortcode
 *              to prove rendering, and a non-public CPT that must stay out of REST. Never ship this.
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
