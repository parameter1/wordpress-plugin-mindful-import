<?php
/**
 * Settings storage (single array option) and the Settings → Mindful Import page.
 *
 * @package Mindful_Import
 */

namespace Mindful_Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings.
 */
class Settings {

	const PAGE_SLUG      = 'mindful-import';
	const NONCE_SAVE     = 'mindful_import_save';
	const NONCE_REGISTER = 'mindful_import_register';
	const CAPABILITY     = 'manage_options';

	/**
	 * Outcome of a registration started from the settings page during this request.
	 *
	 * @var true|\WP_Error|null
	 */
	private static $registration_result = null;

	/**
	 * Whether settings were saved during this request.
	 *
	 * @var bool
	 */
	private static $saved = false;

	/**
	 * Registers admin hooks.
	 */
	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_action( 'admin_notices', array( __CLASS__, 'registration_notice' ) );
		}
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'key_hash'                     => '',
			'key_salt'                     => '',
			'key_created_at'               => 0,
			'registration_status'          => '',
			'registered_at'                => 0,
			'registration_error'           => '',
			'registration_attempts'        => 0,
			'registration_last_attempt_at' => 0,
			'post_types'                   => array(),
			'taxonomies'                   => array(),
			'options_allowlist'            => array(
				'blogname',
				'blogdescription',
				'siteurl',
				'home',
				'timezone_string',
				'gmt_offset',
				'permalink_structure',
				'date_format',
				'time_format',
				'page_on_front',
				'page_for_posts',
				'show_on_front',
				'posts_per_page',
				'upload_url_path',
				'upload_path',
				'start_of_week',
				'WPLANG',
				'stylesheet',
				'template',
			),
			'options_prefixes'             => array( 'wpseo_', 'aioseo_' ),
			'retention_days'               => 90,
			'render_enabled'               => true,
			'import_user_id'               => 0,
			'installed_version'            => '',
		);
	}

	/**
	 * Raw stored option.
	 *
	 * @return array
	 */
	private static function all() {
		$value = get_option( MINDFUL_IMPORT_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Reads one setting. Falls back to $default when given, else to defaults().
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		if ( null !== $default ) {
			return $default;
		}
		$defaults = self::defaults();
		return array_key_exists( $key, $defaults ) ? $defaults[ $key ] : null;
	}

	/**
	 * Merges $partial into the stored option.
	 *
	 * @param array $partial Keys to set.
	 * @return bool
	 */
	public static function update( array $partial ) {
		$all = array_merge( self::all(), $partial );
		return update_option( MINDFUL_IMPORT_OPTION, $all );
	}

	/**
	 * Removes one key from the stored option (no-op when absent).
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function delete( $key ) {
		$all = self::all();
		if ( ! array_key_exists( $key, $all ) ) {
			return false;
		}
		unset( $all[ $key ] );
		return update_option( MINDFUL_IMPORT_OPTION, $all );
	}

	/**
	 * Adds any missing default keys without touching existing values.
	 */
	public static function ensure_defaults() {
		$all     = self::all();
		$missing = array_diff_key( self::defaults(), $all );
		if ( ! empty( $missing ) || false === get_option( MINDFUL_IMPORT_OPTION, false ) ) {
			update_option( MINDFUL_IMPORT_OPTION, array_merge( self::defaults(), $all ) );
		}
	}

	/**
	 * Adds Settings → Mindful Import.
	 */
	public static function admin_menu() {
		$hook = add_options_page(
			__( 'Mindful Import', 'mindful-import' ),
			__( 'Mindful Import', 'mindful-import' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'handle_post' ) );
		}
	}

	/**
	 * Handles form posts before the page renders.
	 */
	public static function handle_post() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method || empty( $_POST['mindful_import_action'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['mindful_import_action'] ) );

		if ( 'register' === $action ) {
			check_admin_referer( self::NONCE_REGISTER );
			self::$registration_result = Registration::register();
			return;
		}

		if ( 'save' === $action ) {
			check_admin_referer( self::NONCE_SAVE );
			self::save_from_post();
			self::$saved = true;
		}
	}

	/**
	 * Sanitises and stores the settings form.
	 */
	private static function save_from_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_post().
		$checked_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
		$checked_taxes = isset( $_POST['taxonomies'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['taxonomies'] ) ) : array();

		$post_types = array();
		foreach ( self::public_post_types() as $name => $obj ) {
			$post_types[ $name ] = in_array( $name, $checked_types, true );
		}
		$taxonomies = array();
		foreach ( self::public_taxonomies() as $name => $obj ) {
			$taxonomies[ $name ] = in_array( $name, $checked_taxes, true );
		}

		$allowlist = isset( $_POST['options_allowlist'] ) ? self::lines( sanitize_textarea_field( wp_unslash( $_POST['options_allowlist'] ) ) ) : array();
		$prefixes  = isset( $_POST['options_prefixes'] ) ? self::lines( sanitize_textarea_field( wp_unslash( $_POST['options_prefixes'] ) ) ) : array();
		$retention = isset( $_POST['retention_days'] ) ? absint( wp_unslash( $_POST['retention_days'] ) ) : 90;
		$render    = ! empty( $_POST['render_enabled'] );
		// phpcs:enable

		self::update(
			array(
				'post_types'        => $post_types,
				'taxonomies'        => $taxonomies,
				'options_allowlist' => $allowlist,
				'options_prefixes'  => $prefixes,
				'retention_days'    => max( 1, min( 3650, $retention ) ),
				'render_enabled'    => $render,
			)
		);
	}

	/**
	 * Splits a textarea into unique option-name tokens.
	 *
	 * @param string $text Textarea value.
	 * @return string[]
	 */
	private static function lines( $text ) {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) $text ) as $line ) {
			$line = preg_replace( '/[^A-Za-z0-9_\-\.:]/', '', trim( $line ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Public post types.
	 *
	 * @return \WP_Post_Type[]
	 */
	private static function public_post_types() {
		return get_post_types( array( 'public' => true ), 'objects' );
	}

	/**
	 * Public taxonomies.
	 *
	 * @return \WP_Taxonomy[]
	 */
	private static function public_taxonomies() {
		return get_taxonomies( array( 'public' => true ), 'objects' );
	}

	/**
	 * One-line description of the registration status for the settings page.
	 *
	 * @param string $date_format Date and time format.
	 * @return string
	 */
	private static function registration_status_text( $date_format ) {
		$status = (string) self::get( 'registration_status', '' );
		if ( 'registered' === $status ) {
			$at = (int) self::get( 'registered_at', 0 );
			/* translators: %s: date of the last successful registration. */
			return sprintf( __( 'Registered with Mindful on %s.', 'mindful-import' ), $at ? wp_date( $date_format, $at ) : __( 'an unknown date', 'mindful-import' ) );
		}
		if ( 'failed' === $status ) {
			$next  = wp_next_scheduled( Registration::CRON_HOOK );
			$retry = $next
				/* translators: %s: date and time of the next automatic attempt. */
				? sprintf( __( 'next retry %s', 'mindful-import' ), wp_date( $date_format, $next ) )
				: __( 'no further automatic retries', 'mindful-import' );
			$attempts = (int) self::get( 'registration_attempts', 0 );
			return sprintf(
				/* translators: 1: error message, 2: number of failed attempts, 3: next retry time or "no further automatic retries". */
				_n( 'Registration failed: %1$s (%2$d attempt; %3$s).', 'Registration failed: %1$s (%2$d attempts; %3$s).', $attempts, 'mindful-import' ),
				(string) self::get( 'registration_error', '' ),
				$attempts,
				$retry
			);
		}
		if ( 'pending' === $status || wp_next_scheduled( Registration::CRON_HOOK ) ) {
			return __( 'Registration pending.', 'mindful-import' );
		}
		return __( 'Not registered yet.', 'mindful-import' );
	}

	/**
	 * `admin_notices`: warns administrators on the Plugins screen and on this page when the last
	 * registration attempt failed.
	 */
	public static function registration_notice() {
		if ( ! current_user_can( self::CAPABILITY ) || 'failed' !== (string) self::get( 'registration_status', '' ) ) {
			return;
		}
		if ( null !== self::$registration_result ) {
			return; // The page reports the attempt it just made.
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_' . self::PAGE_SLUG ), true ) ) {
			return;
		}
		$page_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		?>
		<div class="notice notice-error">
			<p>
				<?php
				/* translators: %s: error message. */
				echo esc_html( sprintf( __( 'Mindful Import could not register this site with Mindful: %s.', 'mindful-import' ), (string) self::get( 'registration_error', '' ) ) );
				?>
				<a href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'View registration status', 'mindful-import' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mindful-import' ) );
		}

		$type_flags   = (array) self::get( 'post_types', array() );
		$tax_flags    = (array) self::get( 'taxonomies', array() );
		$created_at   = (int) self::get( 'key_created_at', 0 );
		$has_key      = '' !== (string) self::get( 'key_hash', '' );
		$registered   = 'registered' === (string) self::get( 'registration_status', '' );
		$user_id      = Auth::import_user_id();
		$discovery    = rest_url( MINDFUL_IMPORT_REST_NAMESPACE . '/discovery' );
		$date_format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$page_url     = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mindful Import', 'mindful-import' ); ?></h1>

			<?php if ( true === self::$registration_result ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Registered with Mindful. Any previous key has stopped working.', 'mindful-import' ); ?></p></div>
			<?php elseif ( is_wp_error( self::$registration_result ) ) : ?>
				<div class="notice notice-error is-dismissible"><p>
					<?php
					/* translators: %s: error message. */
					echo esc_html( sprintf( __( 'Registration with Mindful failed: %s. The previous key, if any, still works.', 'mindful-import' ), self::$registration_result->get_error_message() ) );
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( self::$saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'mindful-import' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Import registration', 'mindful-import' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'mindful-import' ); ?></th>
					<td><p><?php echo esc_html( self::registration_status_text( $date_format ) ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Key', 'mindful-import' ); ?></th>
					<td>
						<?php if ( $has_key ) : ?>
							<p><?php
								/* translators: %s: date the key was created. */
								echo esc_html( sprintf( __( 'A key is set (created %s).', 'mindful-import' ), $created_at ? wp_date( $date_format, $created_at ) : __( 'unknown', 'mindful-import' ) ) );
							?></p>
						<?php else : ?>
							<p><?php esc_html_e( 'No key is set. The import endpoints refuse every request until registration succeeds.', 'mindful-import' ); ?></p>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( $page_url ); ?>">
							<?php wp_nonce_field( self::NONCE_REGISTER ); ?>
							<input type="hidden" name="mindful_import_action" value="register" />
							<?php submit_button( $registered ? __( 'Rotate key and re-register', 'mindful-import' ) : __( 'Register with Mindful', 'mindful-import' ), 'secondary', 'submit', false ); ?>
						</form>
						<p class="description"><?php esc_html_e( 'Registration generates a new import key and sends it to Mindful over HTTPS. The key is never shown here, and only a hash of it is kept on this site. The previous key stops working once registration succeeds.', 'mindful-import' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Import user', 'mindful-import' ); ?></th>
					<td>
						<?php if ( $user_id ) : ?>
							<?php
							/* translators: 1: user login, 2: user id. */
							echo esc_html( sprintf( __( '%1$s exists (ID %2$d).', 'mindful-import' ), MINDFUL_IMPORT_USER_LOGIN, $user_id ) );
							?>
						<?php else : ?>
							<?php esc_html_e( 'Missing. Deactivate and reactivate the plugin to recreate it.', 'mindful-import' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mindful-import-discovery"><?php esc_html_e( 'Discovery URL', 'mindful-import' ); ?></label></th>
					<td><input id="mindful-import-discovery" type="text" class="large-text code" readonly value="<?php echo esc_url( $discovery ); ?>" onclick="this.select();" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Version', 'mindful-import' ); ?></th>
					<td>
						<?php
						/* translators: 1: plugin version, 2: hook API version. */
						echo esc_html( sprintf( __( 'Plugin %1$s, hook API %2$d', 'mindful-import' ), MINDFUL_IMPORT_VERSION, MINDFUL_IMPORT_HOOK_API ) );
						?>
						<p class="description">
							<?php
							if ( 'active' === Updater::status() ) {
								esc_html_e( 'Update channel: active (GitHub releases).', 'mindful-import' );
							} else {
								esc_html_e( 'Update channel: inactive. It activates once the GitHub repository is public and the update library is bundled.', 'mindful-import' );
							}
							?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Settings', 'mindful-import' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $page_url ); ?>">
				<?php wp_nonce_field( self::NONCE_SAVE ); ?>
				<input type="hidden" name="mindful_import_action" value="save" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'mindful-import' ); ?></th>
						<td><fieldset>
							<?php foreach ( self::public_post_types() as $name => $obj ) : ?>
								<label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( ! isset( $type_flags[ $name ] ) || false !== (bool) $type_flags[ $name ] ); ?> />
								<?php echo esc_html( $obj->labels->name ); ?> <code><?php echo esc_html( $name ); ?></code></label><br />
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Checked types are added to the REST API for key-authenticated requests only. Unchecked types are never added.', 'mindful-import' ); ?></p>
						</fieldset></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Taxonomies', 'mindful-import' ); ?></th>
						<td><fieldset>
							<?php foreach ( self::public_taxonomies() as $name => $obj ) : ?>
								<label><input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( ! isset( $tax_flags[ $name ] ) || false !== (bool) $tax_flags[ $name ] ); ?> />
								<?php echo esc_html( $obj->labels->name ); ?> <code><?php echo esc_html( $name ); ?></code></label><br />
							<?php endforeach; ?>
						</fieldset></td>
					</tr>
					<tr>
						<th scope="row"><label for="mindful-import-allowlist"><?php esc_html_e( 'Options allowlist', 'mindful-import' ); ?></label></th>
						<td>
							<textarea id="mindful-import-allowlist" name="options_allowlist" rows="10" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) self::get( 'options_allowlist' ) ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One option name per line.', 'mindful-import' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mindful-import-prefixes"><?php esc_html_e( 'Option prefixes', 'mindful-import' ); ?></label></th>
						<td>
							<textarea id="mindful-import-prefixes" name="options_prefixes" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) self::get( 'options_prefixes' ) ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Options whose names start with any of these prefixes are also exposed. One per line.', 'mindful-import' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mindful-import-retention"><?php esc_html_e( 'Change-log retention (days)', 'mindful-import' ); ?></label></th>
						<td><input id="mindful-import-retention" type="number" min="1" max="3650" name="retention_days" value="<?php echo esc_attr( (string) (int) self::get( 'retention_days' ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rendered content', 'mindful-import' ); ?></th>
						<td><label><input type="checkbox" name="render_enabled" value="1" <?php checked( (bool) self::get( 'render_enabled' ) ); ?> />
						<?php esc_html_e( 'Allow key-authenticated requests to ask for rendered post content (?mindful_render=1).', 'mindful-import' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
