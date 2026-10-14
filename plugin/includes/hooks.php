<?php
/**
 * Public hook reference for companion plugins. Documentation only: every hook below is
 * emitted by the class named in its doc block. Hook API version: MINDFUL_IMPORT_HOOK_API.
 *
 * Detect the core plugin from a companion with:
 *
 *     if ( function_exists( 'mindful_import' ) && mindful_import()['hook_api'] >= 1 ) { ... }
 *
 * @package Mindful_Import
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filter: mindful_import_post_fields
 *
 * Adjusts the `post_meta_fields` REST field for one post (any REST-visible post type,
 * including attachments). Runs only on key-authenticated requests. Emitted by Fields.
 *
 * @param array    $fields Flattened meta (see Fields::flatten_meta()).
 * @param \WP_Post $post   The post.
 * @return array
 *
 * Example:
 *
 *     add_filter( 'mindful_import_post_fields', function ( $fields, $post ) {
 *         $fields['acf_hero'] = get_field( 'hero', $post->ID );
 *         unset( $fields['_edit_lock'] );
 *         return $fields;
 *     }, 10, 2 );
 */

/**
 * Filter: mindful_import_term_fields
 *
 * Adjusts the `term_meta_fields` REST field for one term. Key-authenticated requests only.
 * Emitted by Fields.
 *
 * @param array    $fields Flattened term meta.
 * @param \WP_Term $term   The term.
 * @return array
 *
 * Example:
 *
 *     add_filter( 'mindful_import_term_fields', function ( $fields, $term ) {
 *         $fields['color'] = get_term_meta( $term->term_id, 'color', true );
 *         return $fields;
 *     }, 10, 2 );
 */

/**
 * Filter: mindful_import_user_fields
 *
 * Adjusts the extra fields exposed for one user on the users resource. Key-authenticated
 * requests only. Emitted by the users resource.
 *
 * @param array    $fields Fields for the user.
 * @param \WP_User $user   The user.
 * @return array
 *
 * Example:
 *
 *     add_filter( 'mindful_import_user_fields', function ( $fields, $user ) {
 *         $fields['twitter'] = get_user_meta( $user->ID, 'twitter', true );
 *         return $fields;
 *     }, 10, 2 );
 */

/**
 * Filter: mindful_import_options_allowlist
 *
 * Adjusts which wp_options rows the options resource returns. Emitted by the options route.
 *
 * @param array $names    Exact option names (from settings).
 * @param array $prefixes Option-name prefixes (from settings).
 * @return array Either array( $names, $prefixes ) to adjust both, or a flat list of names
 *               (which replaces $names only and keeps the configured prefixes).
 *
 * Example:
 *
 *     add_filter( 'mindful_import_options_allowlist', function ( $names, $prefixes ) {
 *         $names[]    = 'my_theme_settings';
 *         $prefixes[] = 'my_theme_';
 *         return array( $names, $prefixes );
 *     }, 10, 2 );
 */

/**
 * Filter: mindful_import_change_entry
 *
 * Adjusts or suppresses one change-log entry before it is written. Return null to skip it.
 * Emitted by Changelog::record().
 *
 * @param array|null $entry The entry (object_type, object_id, action, extra...).
 * @return array|null
 *
 * Example:
 *
 *     add_filter( 'mindful_import_change_entry', function ( $entry ) {
 *         if ( $entry && 'revision' === ( $entry['extra']['post_type'] ?? '' ) ) {
 *             return null;
 *         }
 *         return $entry;
 *     } );
 */

/**
 * Filter: mindful_import_authenticate
 *
 * Lets a companion authenticate a REST request when the X-Mindful-Import-Key check fails.
 * Return a user id to treat the request as an import request, or null to leave it
 * unauthenticated. Emitted by Auth.
 *
 * READ THIS BEFORE HOOKING IT. Whatever this filter returns becomes the current user for the
 * request, so a callback that returns an id on the wrong condition authenticates the world.
 * Verify a secret, and return $user_id (not 0) when yours is absent. The request is then subject
 * to the same limits as a key request: GET/HEAD/OPTIONS only and the allowed-routes list. The
 * returned user's own role decides what it can read; the import user's request-time
 * capabilities are not granted to it.
 *
 * The filter only runs on requests core serves through the REST API (never on admin screens,
 * admin-ajax, cron or XML-RPC). It is usually called before a WP_REST_Request exists (during
 * `init` and `determine_current_user`), in which case $request is null and the callback must
 * read its header from $_SERVER. Post types this plugin forces into REST, and the meta fields,
 * are decided at that early point, so a callback that only works with the request object will
 * not see them.
 *
 * @param int|null              $user_id Null when not authenticated.
 * @param \WP_REST_Request|null $request The request, when available.
 * @return int|null
 *
 * Example:
 *
 *     add_filter( 'mindful_import_authenticate', function ( $user_id, $request ) {
 *         $token = isset( $_SERVER['HTTP_X_MY_TOKEN'] ) ? wp_unslash( $_SERVER['HTTP_X_MY_TOKEN'] ) : null;
 *         return ( $token && my_verify( $token ) ) ? my_service_user_id() : $user_id;
 *     }, 10, 2 );
 */

/**
 * Filter: mindful_import_allowed_routes
 *
 * Adjusts the REST route prefixes an import request may reach. The default list is this
 * plugin's namespace, every REST-visible post type and taxonomy, and core's users, comments,
 * types, taxonomies, statuses and search; a prefix matches itself and everything below it.
 * Routes outside the list answer 403 (`mindful_import_route_denied`) even on GET. Routes added
 * with REST::add_route() are under the plugin namespace and need no entry here. Emitted by Auth.
 *
 * @param string[] $routes Route prefixes, e.g. "/wp/v2/posts".
 * @return string[]
 *
 * Example:
 *
 *     add_filter( 'mindful_import_allowed_routes', function ( $routes ) {
 *         $routes[] = '/wc/v3/products';
 *         return $routes;
 *     } );
 */

/**
 * Filter: mindful_import_discovery
 *
 * Adjusts the discovery document (GET mindful-import/v1/discovery). Emitted by the
 * discovery route.
 *
 * @param array $discovery Discovery payload.
 * @return array
 *
 * Example:
 *
 *     add_filter( 'mindful_import_discovery', function ( $discovery ) {
 *         $discovery['resources'][] = 'my-companion/v1/things';
 *         return $discovery;
 *     } );
 */

/**
 * Action: mindful_import_register_resource
 *
 * Fires during `rest_api_init` (priority 20) so companions can register extra routes
 * protected by the same key. Use REST::add_route() to inherit the permission callback.
 *
 * @param string $namespace The REST namespace (mindful-import/v1).
 *
 * Example:
 *
 *     add_action( 'mindful_import_register_resource', function ( $namespace ) {
 *         \Mindful_Import\REST::add_route( '/my-things', array(
 *             'callback' => 'my_things_callback',
 *         ) );
 *     } );
 */
