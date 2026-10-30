<?php
/**
 * Email, webhook and admin notice alerts.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Tells people about red (and, if asked, amber) runs: by email, a webhook
 * (generic JSON or Slack) and an admin notice. Rate-limited per URL and
 * verdict, so the same broken page does not send an alert every run.
 */
class Alerts implements Service {

	public const LOG_OPTION    = 'axle_smoke_test_alert_log';
	public const NOTICE_OPTION = 'axle_smoke_test_notice';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up alerts.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks alerts to finished runs.
	 */
	public function register(): void {
		add_action( 'axle_smoke_test_run_finished', array( $this, 'on_finished' ), 10, 2 );
	}

	/**
	 * The run_finished callback.
	 *
	 * @param array   $run     The run.
	 * @param array[] $results Its results.
	 */
	public function on_finished( $run, $results = array() ): void {
		$this->finished( (array) $run, (array) $results );
	}

	/**
	 * Sends alerts for a run, when its verdict calls for one.
	 *
	 * @param array   $run     The run.
	 * @param array[] $results Its results.
	 * @return string[] Channels used.
	 */
	public function finished( $run, $results = array() ): array {
		$run     = (array) $run;
		$verdict = (string) ( $run['verdict'] ?? '' );

		if ( Verdict::GREEN === $verdict && (int) get_option( self::NOTICE_OPTION ) ) {
			delete_option( self::NOTICE_OPTION );
		}

		$level = (string) $this->settings->get( 'alert_on' );
		if ( 'never' === $level || ! in_array( $verdict, array( Verdict::RED, Verdict::AMBER ), true ) || ( Verdict::AMBER === $verdict && 'amber' !== $level ) ) {
			return array();
		}

		$pairs = array();
		foreach ( (array) $results as $result ) {
			$result_verdict = (string) ( $result['verdict'] ?? '' );
			if ( Verdict::RED === $result_verdict || ( Verdict::AMBER === $result_verdict && 'amber' === $level ) ) {
				$pairs[] = $result['url_key'] . '|' . $result_verdict;
			}
		}
		$pairs = array_values( array_unique( $pairs ) );
		if ( ! $pairs ) {
			return array();
		}

		// The notice always shows the latest failed run; only messages are rate-limited.
		$notice = (bool) $this->settings->get( 'notice' );
		if ( $notice ) {
			update_option( self::NOTICE_OPTION, (int) $run['id'], false );
		}
		if ( ! $this->claim( $pairs ) ) {
			return array();
		}

		$message = $this->message( $run, (array) $results );
		$sent    = array();

		if ( $notice ) {
			$this->sent( 'notice', $run, array(), true );
			$sent[] = 'notice';
		}

		if ( $this->settings->get( 'email' ) ) {
			$recipients = $this->recipients( $run );
			if ( $recipients ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- At most one email per broken URL and verdict per hour.
				$ok = wp_mail( $recipients, $message['subject'], $message['body'] );
				$this->sent( 'email', $run, $recipients, (bool) $ok );
				$sent[] = 'email';
			}
		}

		$webhook = $this->settings->secret( 'webhook_url' );
		if ( '' !== $webhook ) {
			$ok = $this->webhook( $webhook, $run, $message );
			$this->sent( 'webhook', $run, array( (string) wp_parse_url( $webhook, PHP_URL_HOST ) ), $ok );
			$sent[] = 'webhook';
		}

		return $sent;
	}

	/**
	 * Records the URL and verdict pairs about to be alerted, unless they all
	 * were within the rate limit.
	 *
	 * @param string[] $pairs url_key|verdict.
	 * @return bool Whether at least one pair is new.
	 */
	public function claim( array $pairs ): bool {
		/**
		 * Filters how long the same URL and verdict stay quiet after an alert, in seconds.
		 *
		 * @param int $seconds Default one hour. 0 alerts every time.
		 */
		$window = Settings::filtered_int( 'alert_rate_limit', HOUR_IN_SECONDS, 0, WEEK_IN_SECONDS );
		$now    = time();
		$log    = get_option( self::LOG_OPTION, array() );
		$log    = is_array( $log ) ? $log : array();
		$fresh  = false;

		foreach ( $pairs as $pair ) {
			if ( ! isset( $log[ $pair ] ) || $now - (int) $log[ $pair ] >= $window ) {
				$fresh        = true;
				$log[ $pair ] = $now;
			}
		}
		if ( ! $fresh ) {
			return false;
		}

		foreach ( $log as $pair => $time ) {
			if ( $now - (int) $time > max( DAY_IN_SECONDS, 2 * $window ) ) {
				unset( $log[ $pair ] );
			}
		}
		update_option( self::LOG_OPTION, $log, false );

		return true;
	}

	/**
	 * Who gets the email.
	 *
	 * @param array $run The run.
	 * @return string[]
	 */
	public function recipients( array $run ): array {
		$list = (string) $this->settings->get( 'emails' );
		$list = '' !== $list ? array_map( 'trim', explode( ',', $list ) ) : array( (string) get_option( 'admin_email' ) );

		/**
		 * Filters who gets the alert email.
		 *
		 * @param string[] $recipients Default: the addresses in the settings, or the site admin email.
		 * @param array    $run        The run.
		 */
		$filtered = (array) apply_filters( 'axle_smoke_test_alert_recipients', $list, $run );

		return array_values( array_filter( array_map( 'sanitize_email', array_map( 'strval', $filtered ) ), 'is_email' ) );
	}

	/**
	 * The alert text.
	 *
	 * @param array   $run     The run.
	 * @param array[] $results Its results.
	 * @return array{subject: string, body: string, lines: string[], url: string}
	 */
	public function message( array $run, array $results ): array {
		$verdict = (string) $run['verdict'];
		$change  = (string) ( $run['summary']['change'] ?? '' );
		$link    = admin_url( 'tools.php?page=axle-smoke-test&run=' . (int) $run['id'] );
		$label   = Verdict::RED === $verdict ? __( 'red', 'axle-smoke-test' ) : __( 'amber', 'axle-smoke-test' );

		$subject = '' !== $change
			/* translators: 1: site name, 2: verdict (red or amber), 3: what changed. */
			? sprintf( __( '[%1$s] Smoke test %2$s after %3$s', 'axle-smoke-test' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ), $label, $change )
			/* translators: 1: site name, 2: verdict (red or amber). */
			: sprintf( __( '[%1$s] Smoke test %2$s', 'axle-smoke-test' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ), $label );

		$lines = array();
		foreach ( $results as $result ) {
			if ( Verdict::GREEN === ( $result['verdict'] ?? '' ) ) {
				continue;
			}
			$reasons = array();
			foreach ( (array) ( $result['meta']['reasons'] ?? array() ) as $reason ) {
				$reasons[] = (string) $reason['message'] . ( ! empty( $reason['source'] ) && 'unknown' !== $reason['source'] ? ' [' . Attribution::label( (string) $reason['source'] ) . ']' : '' );
			}
			$lines[] = strtoupper( (string) $result['verdict'] ) . ' ' . (string) ( $result['meta']['label'] ?? $result['url_key'] ) . ' (' . $result['tier'] . '): ' . implode( '; ', array_slice( $reasons, 0, 5 ) );
		}

		$body = implode(
			"\n",
			array_merge(
				/* translators: %s: site URL. */
				array( sprintf( __( 'The smoke test on %s found problems.', 'axle-smoke-test' ), home_url( '/' ) ), '' ),
				$lines,
				/* translators: %s: link to the run. */
				array( '', sprintf( __( 'Details and rollback: %s', 'axle-smoke-test' ), $link ) )
			)
		);

		$message = array(
			'subject' => $subject,
			'body'    => $body,
			'lines'   => $lines,
			'url'     => $link,
		);

		/**
		 * Filters the alert text, used for the email and the webhook.
		 *
		 * @param array $message subject, body, lines and url.
		 * @param array $run     The run.
		 */
		$filtered = (array) apply_filters( 'axle_smoke_test_alert_message', $message, $run );

		return isset( $filtered['subject'], $filtered['body'] ) ? array_merge( $message, $filtered ) : $message;
	}

	/**
	 * Posts the alert to the webhook.
	 *
	 * @param string $url     Webhook URL.
	 * @param array  $run     The run.
	 * @param array  $message The alert text.
	 * @return bool
	 */
	private function webhook( string $url, array $run, array $message ): bool {
		$format  = (string) $this->settings->get( 'webhook_format' );
		$payload = 'slack' === $format
			? array( 'text' => '*' . $message['subject'] . "*\n" . implode( "\n", $message['lines'] ) . "\n<" . $message['url'] . '|' . __( 'Open the run', 'axle-smoke-test' ) . '>' )
			: array(
				'site'    => home_url( '/' ),
				'run'     => (int) $run['id'],
				'verdict' => (string) $run['verdict'],
				'trigger' => (string) $run['trigger_type'],
				'change'  => (string) ( $run['summary']['change'] ?? '' ),
				'counts'  => (array) ( $run['summary']['counts'] ?? array() ),
				'subject' => $message['subject'],
				'lines'   => $message['lines'],
				'url'     => $message['url'],
			);

		/**
		 * Filters the webhook body before it is sent as JSON.
		 *
		 * @param array  $payload Generic JSON or a Slack message.
		 * @param array  $run     The run.
		 * @param string $format  json or slack.
		 */
		$filtered = (array) apply_filters( 'axle_smoke_test_webhook_payload', $payload, $run, $format );

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout' => 5, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Only sent for broken pages, rate-limited.
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( $filtered ),
			)
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		return $code >= 200 && $code < 300;
	}

	/**
	 * Announces a sent alert.
	 *
	 * @param string   $channel    notice, email or webhook.
	 * @param array    $run        The run.
	 * @param string[] $recipients Email addresses or the webhook host.
	 * @param bool     $ok         Whether it went out.
	 */
	private function sent( string $channel, array $run, array $recipients, bool $ok ): void {
		/**
		 * Fires after an alert went out (or failed to).
		 *
		 * @param string   $channel    notice, email or webhook.
		 * @param array    $run        The run.
		 * @param string[] $recipients Email addresses, or the webhook host.
		 * @param bool     $ok         Whether it was sent.
		 */
		do_action( 'axle_smoke_test_alert_sent', $channel, $run, $recipients, $ok );
	}
}
