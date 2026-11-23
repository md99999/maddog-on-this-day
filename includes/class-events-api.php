<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches, caches, and selects "on this day" events and births from the
 * Wikipedia "On this day" REST API, optionally favouring faith-related entries.
 */
class OnThisDay_Events_API {

	const API_URL = 'https://en.wikipedia.org/api/rest_v1/feed/onthisday/%s/%02d/%02d';

	/** Selection modes: a mix with about a third faith entries, faith entries first, or faith entries only. */
	const MODES = array( 'mix', 'faith', 'only' );

	/** Seconds to wait before retrying Wikipedia after a failed download (15 minutes). */
	const RETRY_AFTER = 900;

	/** Part of every cache key; bumped when cached data is validated differently, so old entries are not reused. */
	const CACHE_VERSION = 'v2';

	/** Largest response read from Wikipedia; a day's births feed is the biggest, at around 1-2 MB. */
	const MAX_RESPONSE_BYTES = 10485760;

	/** Most characters kept from an entry's text, and from its linked articles' descriptions. */
	const MAX_TEXT_LENGTH = 1000;

	/** Limits on the faith keyword list, so a huge list cannot push the matching regex past PCRE's limits. */
	const MAX_KEYWORDS       = 100;
	const MAX_KEYWORD_LENGTH = 50;

	const DEFAULT_FAITH_KEYWORDS = 'church, pope, priest, theologian, bishop, archbishop, cardinal, pastor, lutheran, protestant, catholic, missionary, nun, abbot, evangelist';

	/**
	 * Get a randomly-selected, year-ascending list of events for today.
	 *
	 * @param int    $count Number of events to return.
	 * @param string $mode  One of self::MODES.
	 * @return array[]|null List of ['year' => int, 'text' => string, 'url' => string], or null if the feed could not be loaded.
	 */
	public static function get_events( $count, $mode = 'mix' ) {
		return self::get_selection( 'events', $count, $mode );
	}

	/**
	 * Get a randomly-selected, year-ascending list of people born today.
	 *
	 * @param int    $count Number of people to return.
	 * @param string $mode  One of self::MODES.
	 * @return array[]|null Same shape as get_events().
	 */
	public static function get_births( $count, $mode = 'mix' ) {
		return self::get_selection( 'births', $count, $mode );
	}

	/** The faith keywords from Settings, or the defaults when none are saved. */
	public static function faith_keywords() {
		$keywords = self::parse_keywords( get_option( 'onthisday_faith_keywords', '' ) );
		return $keywords ? $keywords : self::parse_keywords( self::DEFAULT_FAITH_KEYWORDS );
	}

	/**
	 * Split a comma- or line-separated keyword list into unique lowercase words, at most
	 * MAX_KEYWORDS of them and each at most MAX_KEYWORD_LENGTH characters.
	 *
	 * @param string $raw Keyword list.
	 * @return string[]
	 */
	public static function parse_keywords( $raw ) {
		$words = array();
		$parts = preg_split( '/[,\r\n]+/', is_scalar( $raw ) ? (string) $raw : '' );
		foreach ( is_array( $parts ) ? $parts : array() as $word ) {
			$word = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $word ), 'UTF-8' ) : strtolower( trim( $word ) );
			if ( '' === $word || ( function_exists( 'mb_strlen' ) ? mb_strlen( $word, 'UTF-8' ) : strlen( $word ) ) > self::MAX_KEYWORD_LENGTH ) {
				continue;
			}
			$words[ $word ] = true;
			if ( count( $words ) >= self::MAX_KEYWORDS ) {
				break;
			}
		}
		return array_keys( $words );
	}

	/**
	 * Pick $count items from today's pool for a feed, sorted by year ascending.
	 *
	 * The selection is cached per feed, mode, day, count and keyword list, so every
	 * visitor sees the same list until the cache rolls over at midnight.
	 *
	 * @param string $type  Feed name: 'events' or 'births'.
	 * @param int    $count Number of items to return.
	 * @param string $mode  One of self::MODES.
	 * @return array[]|null Null if the feed could not be loaded; an empty array if nothing qualified.
	 */
	private static function get_selection( $type, $count, $mode ) {
		$count    = max( 1, min( 100, (int) $count ) );
		$mode     = in_array( $mode, self::MODES, true ) ? $mode : 'mix';
		$keywords = self::faith_keywords();

		$now   = current_time( 'timestamp' );
		$month = (int) date( 'n', $now );
		$day   = (int) date( 'j', $now );

		$cache_key = 'onthisday_' . self::CACHE_VERSION . '_' . $type . '_' . $mode . '_' . $month . '_' . $day . '_' . $count . '_' . substr( md5( implode( ',', $keywords ) ), 0, 8 );
		$cached    = get_transient( $cache_key );

		// Anything but a list here means a corrupted or foreign cache entry: rebuild rather than render it.
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$pool = self::get_pool( $type, $month, $day, $now );

		if ( empty( $pool ) ) {
			return null;
		}

		$pattern = self::faith_pattern( $keywords );
		$faith   = array();
		$other   = array();
		foreach ( $pool as $item ) {
			// preg_match() returns false on a regex error; that counts as no match rather than as faith.
			if ( $pattern && 1 === preg_match( $pattern, $item['text'] . ' ' . $item['about'] ) ) {
				$faith[] = $item;
			} else {
				$other[] = $item;
			}
		}
		shuffle( $faith );

		if ( 'only' === $mode ) {
			$selected = array_slice( $faith, 0, $count );
		} elseif ( 'faith' === $mode ) {
			$selected = array_slice( $faith, 0, $count );
			$selected = array_merge( $selected, self::pick( $other, $count - count( $selected ) ) );
		} else {
			$quota    = (int) round( $count / 3 );
			$selected = array_slice( $faith, 0, $quota );
			$leftover = array_merge( array_slice( $faith, $quota ), $other );
			$selected = array_merge( $selected, self::pick( $leftover, $count - count( $selected ) ) );
		}

		$selected = array_map(
			function ( $item ) {
				return array(
					'year' => $item['year'],
					'text' => $item['text'],
					'url'  => $item['url'],
				);
			},
			$selected
		);

		usort(
			$selected,
			function ( $a, $b ) {
				return $a['year'] <=> $b['year'];
			}
		);

		// Cache until local midnight so the list stays stable for the rest of the day.
		$seconds_until_midnight = strtotime( 'tomorrow', $now ) - $now;
		set_transient( $cache_key, $selected, max( $seconds_until_midnight, HOUR_IN_SECONDS ) );

		return $selected;
	}

	/**
	 * The day's full pool for a feed, downloaded once a day and shared by every count and mode,
	 * so many differently configured shortcodes cannot multiply requests to Wikipedia. A failed
	 * download is remembered for RETRY_AFTER seconds, so an outage does not slow every page view.
	 *
	 * @return array[] The pool, or an empty array if it is unavailable.
	 */
	private static function get_pool( $type, $month, $day, $now ) {
		$key    = 'onthisday_pool_' . self::CACHE_VERSION . '_' . $type . '_' . $month . '_' . $day;
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$pool = self::fetch_pool( $type, $month, $day );
		if ( empty( $pool ) ) {
			set_transient( $key, 'unavailable', self::RETRY_AFTER );
			return array();
		}

		set_transient( $key, $pool, max( strtotime( 'tomorrow', $now ) - $now, HOUR_IN_SECONDS ) );
		return $pool;
	}

	/**
	 * Up to $n random items from a list.
	 *
	 * @param array $items Items to choose from.
	 * @param int   $n     How many to take.
	 * @return array
	 */
	private static function pick( $items, $n ) {
		if ( $n <= 0 || ! $items ) {
			return array();
		}
		shuffle( $items );
		return array_slice( $items, 0, $n );
	}

	/**
	 * A case-insensitive, whole-word regex matching any keyword, or '' if there are none.
	 *
	 * @param string[] $keywords Keywords.
	 * @return string
	 */
	private static function faith_pattern( $keywords ) {
		if ( ! $keywords ) {
			return '';
		}
		$quoted = array_map(
			function ( $word ) {
				return preg_quote( $word, '/' );
			},
			$keywords
		);
		return '/\b(?:' . implode( '|', $quoted ) . ')\b/iu';
	}

	/**
	 * Fetch the full pool of items for a feed on a given month/day from Wikipedia.
	 *
	 * @param string $type  Feed name: 'events' or 'births'; also the key of the list in the response.
	 * @param int    $month 1-12.
	 * @param int    $day   1-31.
	 * @return array[] List of ['year' => int, 'text' => string, 'url' => string, 'about' => string],
	 *                 where 'about' holds the linked articles' short descriptions, used for faith matching.
	 */
	private static function fetch_pool( $type, $month, $day ) {
		if ( ! in_array( $type, array( 'events', 'births' ), true ) ) {
			return array();
		}

		$body = OnThisDay_Http::get_json( sprintf( self::API_URL, $type, $month, $day ), 10, self::MAX_RESPONSE_BYTES );
		if ( null === $body ) {
			return array();
		}

		$items = isset( $body[ $type ] ) && is_array( $body[ $type ] ) ? $body[ $type ] : array();

		$pool = array();

		foreach ( $items as $item ) {
			// Check types as well as presence: malformed data must not reach esc_url() or strip_tags() as an array.
			if ( ! is_array( $item ) || ! isset( $item['year'], $item['text'] ) || ! is_scalar( $item['year'] ) || ! is_string( $item['text'] ) ) {
				continue;
			}

			$text = OnThisDay_Http::clean_text( $item['text'], self::MAX_TEXT_LENGTH );
			if ( '' === $text ) {
				continue;
			}

			$page_url     = '';
			$descriptions = array();
			if ( ! empty( $item['pages'] ) && is_array( $item['pages'] ) ) {
				if ( ! empty( $item['pages'][0]['content_urls']['desktop']['page'] ) ) {
					// Only https links to Wikipedia; anything else is shown as plain text.
					$page_url = OnThisDay_Http::wiki_url( $item['pages'][0]['content_urls']['desktop']['page'] );
				}
				foreach ( array_slice( $item['pages'], 0, 20 ) as $page ) {
					if ( is_array( $page ) && ! empty( $page['description'] ) && is_string( $page['description'] ) ) {
						$descriptions[] = $page['description'];
					}
				}
			}

			$pool[] = array(
				'year'  => (int) $item['year'],
				'text'  => $text,
				'url'   => $page_url,
				'about' => OnThisDay_Http::clean_text( implode( ' | ', $descriptions ), self::MAX_TEXT_LENGTH ),
			);
		}

		return $pool;
	}
}
