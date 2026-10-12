<?php
/**
 * Change log: an append-only table of content changes the importer polls.
 *
 * @package Mindful_Import
 */

namespace Mindful_Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records create/update/publish/unpublish/trash/untrash/delete events for posts,
 * attachments, terms and users, and serves them over REST.
 */
class Changelog {

	const DB_VERSION = 1;
	const CRON_HOOK  = 'mindful_import_retention';
	const MIN_DAYS   = 7;

	/**
	 * Per-request set of "type:id" keys already recorded (debounce).
	 *
	 * @var array<string,bool>
	 */
	private static $seen = array();

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mindful_import_changes';
	}

	/**
	 * Registers hooks.
	 */
	public static function init() {
		if ( did_action( 'plugins_loaded' ) ) {
			self::maybe_upgrade();
		} else {
			add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ), 20 );
		}
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_retention' ) );

		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 10, 3 );
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_before_delete_post' ), 10, 1 );
		add_action( 'deleted_post', array( __CLASS__, 'on_deleted_post' ), 10, 2 );

		add_action( 'add_attachment', array( __CLASS__, 'on_add_attachment' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'on_edit_attachment' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );

		add_action( 'added_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );

		add_action( 'created_term', array( __CLASS__, 'on_created_term' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'on_edited_term' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_delete_term' ), 10, 3 );

		add_action( 'user_register', array( __CLASS__, 'on_user_register' ) );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ) );
		add_action( 'deleted_user', array( __CLASS__, 'on_deleted_user' ) );
	}

	/**
	 * Creates or upgrades the table (idempotent).
	 */
	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type varchar(20) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  post_type varchar(40) NULL,
  taxonomy varchar(40) NULL,
  action varchar(20) NOT NULL,
  old_status varchar(20) NULL,
  new_status varchar(20) NULL,
  changed_at datetime NOT NULL,
  user_id bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  KEY object (object_type,object_id),
  KEY changed_at (changed_at)
) {$collate};";

		dbDelta( $sql );
		Settings::update( array( 'db_version' => self::DB_VERSION ) );
	}

	/**
	 * Installs the table when the stored schema version is older than DB_VERSION.
	 */
	public static function maybe_upgrade() {
		if ( (int) Settings::get( 'db_version', 0 ) < self::DB_VERSION ) {
			self::install_table();
		}
	}

	/**
	 * Records one change.
	 *
	 * @param string $object_type post|attachment|term|user.
	 * @param int    $object_id   Object id.
	 * @param string $action      create|update|publish|unpublish|trash|untrash|delete.
	 * @param array  $extra       Optional post_type, taxonomy, old_status, new_status.
	 * @return bool Whether a row was written.
	 */
	public static function record( $object_type, $object_id, $action, $extra = array() ) {
		global $wpdb;

		$user_id = get_current_user_id();
		$import  = (int) Auth::import_user_id();
		if ( $import > 0 && $user_id === $import ) {
			return false;
		}

		$entry = array(
			'object_type' => (string) $object_type,
			'object_id'   => (int) $object_id,
			'post_type'   => isset( $extra['post_type'] ) ? (string) $extra['post_type'] : null,
			'taxonomy'    => isset( $extra['taxonomy'] ) ? (string) $extra['taxonomy'] : null,
			'action'      => (string) $action,
			'old_status'  => isset( $extra['old_status'] ) ? (string) $extra['old_status'] : null,
			'new_status'  => isset( $extra['new_status'] ) ? (string) $extra['new_status'] : null,
			'changed_at'  => current_time( 'mysql', true ),
			'user_id'     => $user_id > 0 ? $user_id : null,
		);

		/**
		 * Filters a change-log entry before insert. Return null to skip it.
		 *
		 * @param array|null $entry
		 */
		$entry = apply_filters( 'mindful_import_change_entry', $entry );
		if ( ! is_array( $entry ) ) {
			return false;
		}

		self::$seen[ $entry['object_type'] . ':' . $entry['object_id'] ] = true;
		return false !== $wpdb->insert( self::table(), $entry );
	}

	/**
	 * Whether a post should be logged at all.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function trackable_post( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( 'revision' === $post->post_type || 'auto-draft' === $post->post_status ) {
			return false;
		}
		if ( wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return false;
		}
		if ( 'attachment' === $post->post_type ) {
			return true;
		}
		$type = get_post_type_object( $post->post_type );
		return $type && $type->public;
	}

	/**
	 * Records a post-level change after the trackability checks.
	 *
	 * @param \WP_Post $post   Post.
	 * @param string   $action Action.
	 * @param array    $extra  Extra columns.
	 */
	private static function record_post( $post, $action, $extra = array() ) {
		if ( ! self::trackable_post( $post ) ) {
			return;
		}
		$type  = 'attachment' === $post->post_type ? 'attachment' : 'post';
		$extra = array_merge( array( 'post_type' => $post->post_type ), $extra );
		self::record( $type, (int) $post->ID, $action, $extra );
	}

	/**
	 * Whether this request already recorded the object.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return bool
	 */
	private static function already( $type, $id ) {
		return isset( self::$seen[ $type . ':' . (int) $id ] );
	}

	/**
	 * Hook: transition_post_status.
	 *
	 * @param string   $new  New status.
	 * @param string   $old  Old status.
	 * @param \WP_Post $post Post.
	 */
	public static function on_transition_post_status( $new, $old, $post ) {
		if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type || 'auto-draft' === $new ) {
			return;
		}
		if ( 'new' === $old || 'auto-draft' === $old ) {
			$action = 'create';
		} elseif ( 'trash' === $new ) {
			$action = 'trash';
		} elseif ( 'trash' === $old ) {
			$action = 'untrash';
		} elseif ( 'publish' === $new && 'publish' !== $old ) {
			$action = 'publish';
		} elseif ( 'publish' === $old && 'publish' !== $new ) {
			$action = 'unpublish';
		} else {
			$action = 'update';
		}
		self::record_post(
			$post,
			$action,
			array(
				'old_status' => $old,
				'new_status' => $new,
			)
		);
	}

	/**
	 * Hook: save_post (catches saves that did not go through a status transition).
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type || self::already( 'post', $post_id ) ) {
			return;
		}
		self::record_post(
			$post,
			'update',
			array(
				'old_status' => $post->post_status,
				'new_status' => $post->post_status,
			)
		);
	}

	/**
	 * Hook: before_delete_post — suppress meta-deletion noise ahead of the delete row.
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_before_delete_post( $post_id ) {
		self::$seen[ 'post:' . (int) $post_id ] = true;
	}

	/**
	 * Hook: deleted_post.
	 *
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    Post (WP >= 5.5).
	 */
	public static function on_deleted_post( $post_id, $post = null ) {
		if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) {
			return;
		}
		self::record_post( $post, 'delete', array( 'old_status' => $post->post_status ) );
	}

	/**
	 * Hooks: add_attachment / edit_attachment / delete_attachment (the last fires before meta removal).
	 *
	 * @param int $post_id Attachment id.
	 */
	public static function on_add_attachment( $post_id ) {
		self::record_post( get_post( $post_id ), 'create' );
	}

	/** @see on_add_attachment() */
	public static function on_edit_attachment( $post_id ) {
		if ( ! self::already( 'attachment', $post_id ) ) {
			self::record_post( get_post( $post_id ), 'update' );
		}
	}

	/** @see on_add_attachment() */
	public static function on_delete_attachment( $post_id ) {
		self::record_post( get_post( $post_id ), 'delete' );
	}

	/**
	 * Hook: added/updated/deleted_post_meta — one 'update' per post per request.
	 *
	 * @param int|int[] $meta_id  Meta id(s).
	 * @param int       $post_id  Post id.
	 * @param string    $meta_key Meta key.
	 */
	public static function on_post_meta( $meta_id, $post_id, $meta_key ) {
		if ( in_array( $meta_key, array( '_edit_lock', '_edit_last', '_encloseme', '_pingme' ), true ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$type = 'attachment' === $post->post_type ? 'attachment' : 'post';
		if ( self::already( $type, $post->ID ) ) {
			return;
		}
		self::$seen[ $type . ':' . (int) $post->ID ] = true;
		self::record_post(
			$post,
			'update',
			array(
				'old_status' => $post->post_status,
				'new_status' => $post->post_status,
			)
		);
	}

	/**
	 * Hooks: created_term / edited_term / delete_term (public taxonomies only).
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function on_created_term( $term_id, $tt_id, $taxonomy ) {
		self::record_term( $term_id, $taxonomy, 'create' );
	}

	/** @see on_created_term() */
	public static function on_edited_term( $term_id, $tt_id, $taxonomy ) {
		self::record_term( $term_id, $taxonomy, 'update' );
	}

	/** @see on_created_term() */
	public static function on_delete_term( $term_id, $tt_id, $taxonomy ) {
		self::record_term( $term_id, $taxonomy, 'delete' );
	}

	/**
	 * Records a term change when its taxonomy is public.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @param string $action   Action.
	 */
	private static function record_term( $term_id, $taxonomy, $action ) {
		$tax = get_taxonomy( $taxonomy );
		if ( $tax && $tax->public ) {
			self::record( 'term', (int) $term_id, $action, array( 'taxonomy' => $taxonomy ) );
		}
	}

	/**
	 * Hooks: user_register / profile_update / deleted_user.
	 *
	 * @param int $user_id User id.
	 */
	public static function on_user_register( $user_id ) {
		self::record( 'user', (int) $user_id, 'create' );
	}

	/** @see on_user_register() */
	public static function on_profile_update( $user_id ) {
		if ( ! self::already( 'user', $user_id ) ) {
			self::record( 'user', (int) $user_id, 'update' );
		}
	}

	/** @see on_user_register() */
	public static function on_deleted_user( $user_id ) {
		self::record( 'user', (int) $user_id, 'delete' );
	}

	/**
	 * Highest change id, or 0.
	 *
	 * @return int
	 */
	public static function head_id() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Schedules the daily retention job.
	 */
	public static function schedule_retention() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Removes the retention job.
	 */
	public static function unschedule_retention() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Deletes rows older than retention_days (minimum 7).
	 *
	 * @return int Rows deleted.
	 */
	public static function run_retention() {
		global $wpdb;
		$days   = max( self::MIN_DAYS, (int) Settings::get( 'retention_days', 90 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$table  = self::table();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE changed_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Casts a DB row to its API shape.
	 *
	 * @param object|array $row Row.
	 * @return array
	 */
	public static function format_row( $row ) {
		$row = (array) $row;
		return array(
			'id'          => (int) $row['id'],
			'object_type' => $row['object_type'],
			'object_id'   => (int) $row['object_id'],
			'post_type'   => $row['post_type'],
			'taxonomy'    => $row['taxonomy'],
			'action'      => $row['action'],
			'old_status'  => $row['old_status'],
			'new_status'  => $row['new_status'],
			'changed_at'  => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $row['changed_at'] . ' UTC' ) ),
			'user_id'     => null === $row['user_id'] ? null : (int) $row['user_id'],
		);
	}

	/**
	 * Registers `changes` and `changes/latest`.
	 */
	public static function register_routes() {
		REST::add_route(
			'changes',
			array(
				'callback' => array( __CLASS__, 'rest_changes' ),
				'args'     => array(
					'after'       => array(
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'limit'       => array(
						'type'              => 'integer',
						'default'           => 100,
						'minimum'           => 1,
						'maximum'           => 500,
						'sanitize_callback' => 'absint',
					),
					'object_type' => array(
						'type' => 'string',
						'enum' => array( 'post', 'attachment', 'term', 'user' ),
					),
					'since'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		REST::add_route( 'changes/latest', array( 'callback' => array( __CLASS__, 'rest_latest' ) ) );
	}

	/**
	 * GET changes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_changes( \WP_REST_Request $request ) {
		global $wpdb;
		$table = self::table();
		$after = (int) $request->get_param( 'after' );
		$limit = min( 500, max( 1, (int) $request->get_param( 'limit' ) ) );

		$where  = array( 'id > %d' );
		$values = array( $after );

		$object_type = $request->get_param( 'object_type' );
		if ( ! empty( $object_type ) ) {
			$where[]  = 'object_type = %s';
			$values[] = (string) $object_type;
		}

		$since = $request->get_param( 'since' );
		if ( ! empty( $since ) ) {
			$ts = strtotime( (string) $since );
			if ( false === $ts ) {
				return new \WP_Error(
					'mindful_import_invalid_since',
					__( 'The since parameter must be an ISO 8601 date-time.', 'mindful-import' ),
					array( 'status' => 400 )
				);
			}
			$where[]  = 'changed_at >= %s';
			$values[] = gmdate( 'Y-m-d H:i:s', $ts );
		}

		$values[] = $limit + 1;
		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT %d';
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows     = is_array( $rows ) ? $rows : array();
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			$rows = array_slice( $rows, 0, $limit );
		}
		$changes = array_map( array( __CLASS__, 'format_row' ), $rows );
		$last    = end( $changes );

		return rest_ensure_response(
			array(
				'changes'    => $changes,
				'head_id'    => self::head_id(),
				'has_more'   => $has_more,
				'next_after' => $last ? (int) $last['id'] : $after,
			)
		);
	}

	/**
	 * GET changes/latest.
	 *
	 * @return \WP_REST_Response
	 */
	public static function rest_latest() {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row   = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1" );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable

		return rest_ensure_response(
			array(
				'head_id' => $row ? (int) $row->id : 0,
				'latest'  => $row ? self::format_row( $row ) : null,
				'count'   => $count,
			)
		);
	}
}
