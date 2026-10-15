<?php
/**
 * Deterministic media prefix replacements before WXR serialization.
 *
 * @package ZeroPressStatic
 */

namespace ZeroPress\StaticPublisher;

/** Applies validated rules to each original value once. */
final class Media_Rewriter {
	/**
	 * Map.
	 *
	 * @var array
	 */
	private array $map;
	/**
	 * Replacements.
	 *
	 * @var int
	 */
	public int $replacements = 0;

	/**
	 * Initialize this service with validated input.
	 *
	 * @param array $rules Source and destination URL prefix pairs.
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public function __construct( array $rules ) {
		$this->map = array();
		foreach ( self::normalize( $rules ) as $pair ) {
			$this->map[ $pair[0] ] = $pair[1];
		}
	}

	/**
	 * Validate and canonically order replacement pairs.
	 *
	 * @param mixed $rules Source and destination URL prefix pairs.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public static function normalize( mixed $rules ): array {
		if ( ! is_array( $rules ) || ! array_is_list( $rules ) || count( $rules ) > 50 ) {
			throw new Failure( 'INVALID_MEDIA_RULES' );
		}
		$map = array();
		foreach ( $rules as $pair ) {
			if ( ! is_array( $pair ) || ! array_is_list( $pair ) || count( $pair ) !== 2 || ! is_string( $pair[0] ) || ! is_string( $pair[1] ) ) {
				throw new Failure( 'INVALID_MEDIA_RULES' );
			}
			$from = self::prefix( $pair[0] );
			$to   = self::prefix( $pair[1] );
			if ( isset( $map[ $from ] ) && $map[ $from ] !== $to ) {
				throw new Failure( 'MEDIA_RULE_CONFLICT' );
			}
			$map[ $from ] = $to;
		}
		ksort( $map, SORT_STRING );
		$result = array();
		foreach ( $map as $from => $to ) {
			$result[] = array( $from, $to );
		}
		return $result;
	}

	/**
	 * Validate a slash-terminated URL prefix.
	 *
	 * @param string $value Original input value.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private static function prefix( string $value ): string {
		$value = trim( $value );
		Settings::url( $value );
		return rtrim( $value, '/' ) . '/';
	}

	/**
	 * Replace the longest prefix of one media URL.
	 *
	 * @param string $value Original input value.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public function url( string $value ): string {
		$matches = array_filter( array_keys( $this->map ), static fn( $prefix ) => str_starts_with( $value, $prefix ) );
		usort( $matches, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
		if ( ! $matches ) {
			return $value;
		}
		$prefix = $matches[0];
		$result = $this->map[ $prefix ] . substr( $value, strlen( $prefix ) );
		if ( $result !== $value ) {
			++$this->replacements;
		}
		return $result;
	}

	/**
	 * Rewrite media HTML and static block attributes without rendering blocks.
	 *
	 * @param string $value Original input value.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	public function content( string $value ): string {
		if ( ! $this->map ) {
			return $value;
		}
		// Process HTML and JSON attributes independently; never run the map twice.
		$blocks = parse_blocks( $value );
		return serialize_blocks( $this->blocks( $blocks ) );
	}

	/**
	 * Visit saved block fragments without applying content filters.
	 *
	 * @param array $blocks Parsed WordPress blocks.
	 * @return array
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function blocks( array $blocks ): array {
		foreach ( $blocks as &$block ) {
			if ( in_array( $block['blockName'], array( 'core/image', 'core/audio', 'core/video', 'core/file', 'core/cover', 'core/media-text', 'core/gallery' ), true ) ) {
				$block['attrs'] = $this->attributes( $block['attrs'] );
			}
			$block['innerBlocks']  = $this->blocks( $block['innerBlocks'] );
			$block['innerContent'] = array_map( fn( $part ) => is_string( $part ) ? $this->html( $part ) : $part, $block['innerContent'] );
		}
		return $blocks;
	}

	/**
	 * Change media attributes without rewriting ordinary navigation or HTML text.
	 *
	 * @param string $html Original saved media markup.
	 * @return string
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function html( string $html ): string {
		$processor  = new \WP_HTML_Tag_Processor( $html );
		$attributes = array(
			'IMG'    => array( 'src', 'srcset' ),
			'SOURCE' => array( 'src', 'srcset' ),
			'VIDEO'  => array( 'src', 'poster' ),
			'AUDIO'  => array( 'src' ),
			'TRACK'  => array( 'src' ),
			'OBJECT' => array( 'data' ),
			'EMBED'  => array( 'src' ),
		);
		while ( $processor->next_tag() ) {
			// Linked original images/documents are media too; ordinary article links stay intact.
			if ( 'A' === $processor->get_tag() ) {
				$href = $processor->get_attribute( 'href' );
				if ( is_string( $href ) && preg_match( '/\.(?:avif|bmp|gif|heic|jpe?g|png|svg|webp|ico|mp3|m4a|ogg|wav|mp4|mov|webm|pdf|docx?|xlsx?|pptx?|odt|ods|odp|zip)\z/i', (string) wp_parse_url( $href, PHP_URL_PATH ) ) ) {
					$processor->set_attribute( 'href', $this->url( $href ) );
				}
			}
			foreach ( $attributes[ $processor->get_tag() ] ?? array() as $name ) {
				$value = $processor->get_attribute( $name );
				if ( is_string( $value ) ) {
					$processor->set_attribute( $name, 'srcset' === $name ? $this->srcset( $value ) : $this->url( $value ) );
				}
			}
		}
		return $processor->get_updated_html();
	}

	/**
	 * Rewrite each srcset URL while preserving its suffix and descriptors.
	 *
	 * URL tokens end at ASCII whitespace; commas within a URL are preserved.
	 * Descriptors end at a comma outside parentheses, as in the HTML algorithm.
	 *
	 * @param string $value Original srcset attribute.
	 * @return string
	 */
	private function srcset( string $value ): string {
		$length = strlen( $value );
		$offset = 0;
		$copied = 0;
		$result = '';
		$space  = "\x09\x0a\x0c\x0d\x20";
		while ( $offset < $length ) {
			$offset += strspn( $value, $space . ',', $offset );
			if ( $offset >= $length ) {
				break;
			}
			$start   = $offset;
			$offset += strcspn( $value, $space, $offset );
			$token   = substr( $value, $start, $offset - $start );
			$url     = rtrim( $token, ',' );
			$result .= substr( $value, $copied, $start - $copied ) . $this->url( $url );
			$copied  = $start + strlen( $url );
			if ( strlen( $url ) !== strlen( $token ) ) {
				continue;
			}
			$parentheses = 0;
			while ( $offset < $length ) {
				$character = $value[ $offset++ ];
				if ( '(' === $character ) {
					++$parentheses;
				} elseif ( ')' === $character && $parentheses > 0 ) {
					--$parentheses;
				} elseif ( ',' === $character && 0 === $parentheses ) {
					break;
				}
			}
		}
		return $result . substr( $value, $copied );
	}

	/**
	 * Rewrite nested media block attribute values.
	 *
	 * @param mixed $value Original input value.
	 * @return mixed
	 * @throws Failure If validation or the operation cannot be completed.
	 */
	private function attributes( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => &$entry ) {
				if ( is_array( $entry ) ) {
					$entry = $this->attributes( $entry );
				} elseif ( is_string( $entry ) && in_array( $key, array( 'url', 'src', 'poster' ), true ) ) {
					$entry = $this->url( $entry );
				}
			}
		}
		return $value;
	}
}
