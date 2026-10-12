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
		register_post_type(
			'news',
			array(
				'label'        => 'News',
				'public'       => true,
				'show_in_rest' => false,
				'has_archive'  => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'author' ),
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
