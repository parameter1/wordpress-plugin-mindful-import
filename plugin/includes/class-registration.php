<?php
/**
 * Import registration: generates the import key and sends it, with the site's hostname, to Mindful.
 *
 * @package Mindful_Import
 */

namespace Mindful_Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registration.
 *
 * The plaintext key exists only in memory during register(): it is never stored, logged, displayed
 * or put in an error message. A failed attempt restores the previous key, so a key Mindful already
 * holds keeps working until a new one has been accepted.
 */
class Registration {

	const CRON_HOOK    = 'mindful_import_register';
	const LOCK_OPTION  = 'mindful_import_registration_lock';
	const LOCK_TTL     = 120;
	const MAX_ATTEMPTS = 5;

	/**
	 * Seconds to wait before the next automatic attempt, indexed by failed attempts so far (1-based).
	 * No retry is scheduled once MAX_ATTEMPTS attempts have failed.
	 */
	const BACKOFF = array(
		1 => 300,
		2 => 3600,
		3 => 21600,
		4 => 86400,
	);

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled' ) );
	}

	/**
	 * Cron callback: registers unless the site is already registered.
	 */
	public static function run_scheduled() {
		if ( 'registered' === (string) Settings::get( 'registration_status', '' ) ) {
			return;
		}
		self::register();
	}

	/**
	 * Whether a registration URL may receive the key: it must have a host and use https, except
	 * that http is also accepted when the environment type is local or development.
	 *
	 * @param string      $url              Registration URL.
	 * @param string|null $environment_type Environment type; null reads wp_get_environment_type().
	 * @return bool
	 */
	public static function is_url_allowed( $url, $environment_type = null ) {
		if ( null === $environment_type ) {
			$environment_type = wp_get_environment_type();
		}
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'https' === $scheme ) {
			return true;
		}
		return 'http' === $scheme && in_array( (string) $environment_type, array( 'local', 'development' ), true );
	}

	/**
	 * Generates a new import key and registers it, with the site's hostname, with Mindful.
	 *
	 * The new key is live before the request is sent, so Mindful can verify it against the
	 * discovery route while the request is open. On failure the previous key (or no key) is restored
	 * and a retry is scheduled.
	 *
	 * @return true|\WP_Error
	 */
	public static function register() {
		$url = (string) MINDFUL_IMPORT_REGISTRATION_URL;
		if ( ! self::is_url_allowed( $url ) ) {
			$error = new \WP_Error(
				'mindful_import_registration_url',
				__( 'The registration URL must use https (http is accepted only when WP_ENVIRONMENT_TYPE is local or development).', 'mindful-import' )
			);
			Settings::update(
				array(
					'registration_status'          => 'failed',
					'registration_error'           => $error->get_error_message(),
					'registration_last_attempt_at' => time(),
				)
			);
			return $error;
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $host ) {
			$error = new \WP_Error( 'mindful_import_registration_host', __( 'The site address has no hostname.', 'mindful-import' ) );
			Settings::update(
				array(
					'registration_status'          => 'failed',
					'registration_error'           => $error->get_error_message(),
					'registration_last_attempt_at' => time(),
				)
			);
			return $error;
		}

		if ( ! self::acquire_lock() ) {
			return new \WP_Error( 'mindful_import_registration_busy', __( 'A registration is already in progress. Try again in a minute.', 'mindful-import' ) );
		}

		try {
			$snapshot = array(
				'key_hash'       => (string) Settings::get( 'key_hash', '' ),
				'key_salt'       => (string) Settings::get( 'key_salt', '' ),
				'key_created_at' => (int) Settings::get( 'key_created_at', 0 ),
			);

			$plain = Auth::generate_key();
			$body  = wp_json_encode(
				array(
					'site' => $host,
					'key'  => $plain,
				)
			);

			$response = wp_remote_post(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 0,
					'sslverify'   => true,
					'blocking'    => true,
					'user-agent'  => 'Mindful-Import/' . MINDFUL_IMPORT_VERSION . '; ' . home_url(),
					'headers'     => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					'body'        => $body,
				)
			);
			$body = null;
			unset( $body );

			$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				$plain = null;
				unset( $plain );
				Settings::update(
					array(
						'registration_status'          => 'registered',
						'registered_at'                => time(),
						'registration_error'           => '',
						'registration_attempts'        => 0,
						'registration_last_attempt_at' => time(),
					)
				);
				self::unschedule();
				return true;
			}

			$message = self::error_message( $response, $code, $plain );
			$plain   = null;
			unset( $plain );

			Settings::update( $snapshot );
			Settings::update(
				array(
					'registration_status'          => 'failed',
					'registration_error'           => $message,
					'registration_attempts'        => (int) Settings::get( 'registration_attempts', 0 ) + 1,
					'registration_last_attempt_at' => time(),
				)
			);
			self::schedule_retry();
			return new \WP_Error( 'mindful_import_registration_failed', $message );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * A short, sanitised description of a failed registration response. The key is redacted in
	 * case the endpoint echoed it back.
	 *
	 * @param array|\WP_Error $response Response from wp_remote_post().
	 * @param int             $code     HTTP status (0 when the request itself failed).
	 * @param string          $plain    Plaintext key, redacted from the message.
	 * @return string
	 */
	private static function error_message( $response, $code, $plain ) {
		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
		} else {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'HTTP %d', 'mindful-import' ), $code );
			$detail  = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ) ) );
			if ( '' !== $detail ) {
				$message .= ': ' . self::truncate( $detail, 160 );
			}
		}
		if ( '' !== (string) $plain ) {
			$message = str_replace( (string) $plain, '[redacted]', $message );
		}
		$message = self::truncate( sanitize_text_field( $message ), 200 );
		return '' !== $message ? $message : __( 'Unknown error', 'mindful-import' );
	}

	/**
	 * Cuts a string to at most $length characters, adding an ellipsis when it was longer.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private static function truncate( $text, $length ) {
		$text = (string) $text;
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) > $length ? mb_substr( $text, 0, $length ) . '…' : $text;
		}
		return strlen( $text ) > $length ? substr( $text, 0, $length ) . '…' : $text;
	}

	/**
	 * Schedules the next automatic attempt, backing off by the number of failed attempts. Stops
	 * once MAX_ATTEMPTS attempts have failed; the settings page can still register by hand.
	 */
	public static function schedule_retry() {
		self::unschedule();
		$attempts = (int) Settings::get( 'registration_attempts', 0 );
		if ( $attempts < 1 || $attempts >= self::MAX_ATTEMPTS ) {
			return;
		}
		$delay = isset( self::BACKOFF[ $attempts ] ) ? self::BACKOFF[ $attempts ] : DAY_IN_SECONDS;
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
	}

	/**
	 * Schedules an attempt on the next WP-Cron run when none is pending, so the upgrade path never
	 * makes a blocking HTTP call during an arbitrary page load.
	 */
	public static function schedule_now() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		if ( '' === (string) Settings::get( 'registration_status', '' ) ) {
			Settings::update( array( 'registration_status' => 'pending' ) );
		}
		wp_schedule_single_event( time(), self::CRON_HOOK );
	}

	/**
	 * Removes any pending automatic attempt.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Takes the registration lock, so two attempts (cron and the settings page, say) cannot
	 * interleave their key swaps. A lock older than LOCK_TTL is treated as abandoned.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}
		$since = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $since > 0 && time() - $since < self::LOCK_TTL ) {
			return false;
		}
		delete_option( self::LOCK_OPTION );
		return (bool) add_option( self::LOCK_OPTION, time(), '', 'no' );
	}

	/**
	 * Releases the registration lock.
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}
}
