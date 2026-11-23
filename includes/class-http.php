<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbound HTTP and validation of what comes back. Every remote call the plugin makes goes
 * through here, so the request limits and the checks on the response are in one place.
 */
class OnThisDay_Http {

	/** Redirects followed. Each hop is re-checked by wp_safe_remote_get() (no private or loopback addresses). */
	const MAX_REDIRECTS = 2;

	/** Hosts a link taken from a feed may point at. Anything else is shown as plain text. */
	const LINK_HOST_SUFFIX = '.wikipedia.org';

	/**
	 * GET a URL and decode its JSON body.
	 *
	 * Uses wp_safe_remote_get(), so a redirect from upstream (or from anyone able to tamper with a
	 * plain-HTTP response) cannot point the server at an internal address. The body is capped, so a
	 * runaway or hostile response cannot exhaust memory: a truncated body fails to decode and is
	 * treated as a failed request.
	 *
	 * @param string $url       Absolute URL built from a class constant.
	 * @param int    $timeout   Seconds.
	 * @param int    $max_bytes Largest body read.
	 * @param bool   $identify  Send the site URL in the User-Agent (Wikimedia's policy asks for contact
	 *                          details). Pass false for unencrypted requests, so it is not sent in clear text.
	 * @return array|null Decoded JSON object, or null on any failure.
	 */
	public static function get_json( $url, $timeout, $max_bytes, $identify = true ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => (int) $timeout,
				'redirection'         => self::MAX_REDIRECTS,
				'limit_response_size' => (int) $max_bytes,
				'headers'             => array(
					'User-Agent' => 'WordPress/OnThisDayPlugin/' . ONTHISDAY_VERSION . ( $identify ? ' (' . esc_url_raw( home_url() ) . ')' : '' ),
					'Accept'     => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log( 'request failed', $url, $response->get_error_code() );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			self::log( 'unexpected status', $url, (string) $code );
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			self::log( 'invalid JSON', $url, json_last_error_msg() );
			return null;
		}
		return $data;
	}

	/**
	 * A link from a feed, kept only if it is an https URL on Wikipedia; '' otherwise.
	 *
	 * esc_url() already strips javascript: and similar, but it accepts any http(s) host. Feeds only
	 * ever link to Wikipedia articles, so a link anywhere else means tampered data and is dropped.
	 *
	 * @param mixed $url Candidate URL.
	 * @return string
	 */
	public static function wiki_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 2048 ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] )
			|| 'https' !== strtolower( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			return '';
		}
		$host   = strtolower( $parts['host'] );
		$suffix = self::LINK_HOST_SUFFIX;
		if ( 'wikipedia.org' !== $host && substr( $host, -strlen( $suffix ) ) !== $suffix ) {
			return '';
		}
		return esc_url_raw( $url, array( 'https' ) );
	}

	/**
	 * Plain text from a feed: tags stripped, whitespace collapsed, length capped.
	 *
	 * @param mixed $value Candidate string.
	 * @param int   $max   Most characters kept.
	 * @return string '' for anything that is not a string.
	 */
	public static function clean_text( $value, $max ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$text = preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) );
		if ( ! is_string( $text ) ) {
			return ''; // Not valid UTF-8: drop it rather than display mangled bytes.
		}
		$text = trim( $text );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text, 'UTF-8' ) > $max ? rtrim( mb_substr( $text, 0, $max, 'UTF-8' ) ) . '…' : $text;
		}
		return strlen( $text ) > $max ? rtrim( substr( $text, 0, $max ) ) . '...' : $text;
	}

	/**
	 * Record a failed remote call in the PHP error log, only when the site has debug logging on.
	 * Logs the host and a short reason, never the response body or the site's own details.
	 *
	 * @param string $what   Short reason.
	 * @param string $url    Requested URL.
	 * @param string $detail Status code, error code or JSON error.
	 */
	public static function log( $what, $url, $detail = '' ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'On This Day: %s from %s (%s)',
				$what,
				is_string( $host ) ? $host : 'unknown host',
				preg_replace( '/[^\w .:-]/', '', substr( (string) $detail, 0, 100 ) )
			)
		);
	}
}
