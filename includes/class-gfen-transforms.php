<?php
defined( 'ABSPATH' ) || exit;

/**
 * Transformation library and example-based detection engine.
 *
 * The detector tests every atomic transformation, then every chain of two
 * transformations, against all the provided BEFORE/AFTER pairs. Only chains
 * that reproduce ALL examples exactly are suggested.
 */
class GFEN_Transforms {

	/**
	 * Available transformations: id => label.
	 *
	 * @return array<string,string>
	 */
	public static function all() {
		return array(
			'trim'            => __( 'Remove leading/trailing spaces', 'entry-normalizer-for-gravity-forms' ),
			'collapse_spaces' => __( 'Collapse multiple spaces into one', 'entry-normalizer-for-gravity-forms' ),
			'remove_spaces'   => __( 'Remove all spaces', 'entry-normalizer-for-gravity-forms' ),
			'uppercase'       => __( 'ALL UPPERCASE', 'entry-normalizer-for-gravity-forms' ),
			'lowercase'       => __( 'all lowercase', 'entry-normalizer-for-gravity-forms' ),
			'sentence_case'   => __( 'Capitalize the first letter only, rest lowercase', 'entry-normalizer-for-gravity-forms' ),
			'title_case'      => __( 'Capitalize Each Word (Jean-Pierre Dupont)', 'entry-normalizer-for-gravity-forms' ),
			'remove_accents'  => __( 'Remove accents', 'entry-normalizer-for-gravity-forms' ),
			'alpha_only'      => __( 'Keep letters only', 'entry-normalizer-for-gravity-forms' ),
			'alnum_only'      => __( 'Keep letters and digits only', 'entry-normalizer-for-gravity-forms' ),
			'digits_only'     => __( 'Keep digits only', 'entry-normalizer-for-gravity-forms' ),
			'remove_digits'   => __( 'Remove digits', 'entry-normalizer-for-gravity-forms' ),
			'remove_dquotes'  => __( 'Remove double quotes', 'entry-normalizer-for-gravity-forms' ),
			'strip_html'      => __( 'Strip HTML tags and code', 'entry-normalizer-for-gravity-forms' ),
			'remove_links'    => __( 'Remove links (URLs)', 'entry-normalizer-for-gravity-forms' ),
			'phone_fr'        => __( 'French phone number to international format (+33612345678)', 'entry-normalizer-for-gravity-forms' ),
		);
	}

	/**
	 * Short human descriptions per transformation (id => description).
	 *
	 * Used to build the secondary line of the detection option cards.
	 *
	 * @return array<string,string>
	 */
	public static function descriptions() {
		return array(
			'trim'            => __( 'Removes spaces at the start and end.', 'entry-normalizer-for-gravity-forms' ),
			'collapse_spaces' => __( 'Reduces runs of spaces to a single space.', 'entry-normalizer-for-gravity-forms' ),
			'remove_spaces'   => __( 'Removes every space.', 'entry-normalizer-for-gravity-forms' ),
			'uppercase'       => __( 'Converts the whole value to capital letters.', 'entry-normalizer-for-gravity-forms' ),
			'lowercase'       => __( 'Converts the whole value to lowercase.', 'entry-normalizer-for-gravity-forms' ),
			'sentence_case'   => __( 'Capitalizes the first letter, lowercases the rest.', 'entry-normalizer-for-gravity-forms' ),
			'title_case'      => __( 'Uppercases the first letter of every word.', 'entry-normalizer-for-gravity-forms' ),
			'remove_accents'  => __( 'Replaces accented letters with plain ones.', 'entry-normalizer-for-gravity-forms' ),
			'alpha_only'      => __( 'Keeps only letters (removes digits, spaces and punctuation).', 'entry-normalizer-for-gravity-forms' ),
			'alnum_only'      => __( 'Keeps only letters and digits.', 'entry-normalizer-for-gravity-forms' ),
			'digits_only'     => __( 'Keeps only digits.', 'entry-normalizer-for-gravity-forms' ),
			'remove_digits'   => __( 'Removes every digit.', 'entry-normalizer-for-gravity-forms' ),
			'remove_dquotes'  => __( 'Removes straight, curly and « » double quotes (keeps apostrophes).', 'entry-normalizer-for-gravity-forms' ),
			'strip_html'      => __( 'Removes HTML tags, PHP code and script/style contents.', 'entry-normalizer-for-gravity-forms' ),
			'remove_links'    => __( 'Removes URLs starting with http(s):// or www.', 'entry-normalizer-for-gravity-forms' ),
			'phone_fr'        => __( 'Formats French phone numbers as +33XXXXXXXXX.', 'entry-normalizer-for-gravity-forms' ),
		);
	}

	/**
	 * @param string $id
	 * @return bool
	 */
	public static function is_valid( $id ) {
		$all = self::all();
		return isset( $all[ $id ] );
	}

	/**
	 * Apply a single atomic transformation.
	 *
	 * @param string $id
	 * @param mixed  $value
	 * @return mixed
	 */
	public static function apply( $id, $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		switch ( $id ) {
			case 'trim':
				return trim( $value );
			case 'collapse_spaces':
				return preg_replace( '/\s+/u', ' ', $value );
			case 'remove_spaces':
				return preg_replace( '/\s+/u', '', $value );
			case 'uppercase':
				return mb_strtoupper( $value, 'UTF-8' );
			case 'lowercase':
				return mb_strtolower( $value, 'UTF-8' );
			case 'sentence_case':
				$lower = mb_strtolower( $value, 'UTF-8' );
				return mb_convert_case( mb_substr( $lower, 0, 1, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' ) . mb_substr( $lower, 1, null, 'UTF-8' );
			case 'title_case':
				// MB_CASE_TITLE re-capitalizes after every separator (space, hyphen, apostrophe).
				return mb_convert_case( mb_strtolower( $value, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
			case 'remove_accents':
				return remove_accents( $value );
			case 'alpha_only':
				return preg_replace( '/[^\p{L}]+/u', '', $value );
			case 'alnum_only':
				return preg_replace( '/[^\p{L}\p{N}]+/u', '', $value );
			case 'digits_only':
				return preg_replace( '/\D+/u', '', $value );
			case 'remove_digits':
				return preg_replace( '/\p{N}+/u', '', $value );
			case 'remove_dquotes':
				// Straight ("), curly (“ ” „ ‟) and French guillemet (« ») double quotes.
				// Apostrophes / single quotes are intentionally left untouched (e.g. O'Brien).
				return preg_replace( '/["\x{201C}\x{201D}\x{201E}\x{201F}\x{00AB}\x{00BB}]+/u', '', $value );
			case 'strip_html':
				// wp_strip_all_tags() strips HTML and PHP tags and also removes
				// the contents of <script>/<style> blocks.
				return wp_strip_all_tags( $value );
			case 'remove_links':
				// Bare URLs only; actual <a> tags are handled by strip_html.
				// Trailing sentence punctuation (period, comma, closing bracket…)
				// is not considered part of the URL.
				return preg_replace( '~(?:https?://|www\.)\S*[^\s.,;:!?)\]}"\'»]~iu', '', $value );
			case 'phone_fr':
				return self::phone_fr( $value );
		}
		return $value;
	}

	/**
	 * Normalize a French phone number to the compact +33XXXXXXXXX format.
	 * Unrecognized values (foreign or invalid numbers) are left unchanged.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function phone_fr( $value ) {
		// Strip spaces (including non-breaking ones), dots, hyphens, slashes and parentheses.
		$s = preg_replace( '/[\s\x{00A0}\x{202F}.\-\/()]+/u', '', $value );
		if ( null === $s ) {
			return $value;
		}
		// 0033... => +33...
		$s = preg_replace( '/^00/', '+', $s );
		// Accepts: 0612345678, +33612345678, 33612345678, +33(0)612345678, 612345678.
		if ( preg_match( '/^(?:\+33|33)?0?([1-9]\d{8})$/', $s, $m ) ) {
			return '+33' . $m[1];
		}
		return $value;
	}

	/**
	 * Is the value already a normalized French phone number?
	 *
	 * @param string $value
	 * @return bool
	 */
	public static function is_normalized_phone_fr( $value ) {
		return (bool) preg_match( '/^\+33[1-9]\d{8}$/', $value );
	}

	/**
	 * Apply a chain of transformations.
	 *
	 * @param string[] $chain
	 * @param mixed    $value
	 * @return mixed
	 */
	public static function apply_chain( $chain, $value ) {
		foreach ( (array) $chain as $id ) {
			$value = self::apply( $id, $value );
		}
		return $value;
	}

	/**
	 * Detect candidate transformations from BEFORE/AFTER pairs.
	 *
	 * @param array $pairs Array of ['before' => string, 'after' => string].
	 * @return array Array of candidate chains (each chain = array of ids), simplest first.
	 */
	public static function detect( $pairs ) {
		$ids        = array_keys( self::all() );
		$candidates = array();
		foreach ( $ids as $a ) {
			$candidates[] = array( $a );
		}
		foreach ( $ids as $a ) {
			foreach ( $ids as $b ) {
				if ( $a !== $b ) {
					$candidates[] = array( $a, $b );
				}
			}
		}

		$matches = array();
		foreach ( $candidates as $chain ) {
			$ok = true;
			foreach ( $pairs as $pair ) {
				if ( self::apply_chain( $chain, $pair['before'] ) !== $pair['after'] ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$matches[] = $chain;
			}
		}

		// Drop 2-chains when one of their single sub-chains already matches (redundant).
		$singles = array();
		foreach ( $matches as $m ) {
			if ( 1 === count( $m ) ) {
				$singles[ $m[0] ] = true;
			}
		}
		// Also drop unordered duplicates ("A then B" is equivalent to "B then A" on these examples).
		$filtered = array();
		$seen     = array();
		foreach ( $matches as $m ) {
			if ( 2 === count( $m ) && ( isset( $singles[ $m[0] ] ) || isset( $singles[ $m[1] ] ) ) ) {
				continue;
			}
			$sorted = $m;
			sort( $sorted );
			$key = implode( '|', $sorted );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$filtered[]   = $m;
		}

		usort( $filtered, function ( $x, $y ) {
			return count( $x ) - count( $y );
		} );

		return array_slice( $filtered, 0, 8 );
	}

	/**
	 * Human-readable label for a chain.
	 *
	 * @param string[] $chain
	 * @return string
	 */
	public static function chain_label( $chain ) {
		$all   = self::all();
		$parts = array();
		foreach ( (array) $chain as $id ) {
			$parts[] = isset( $all[ $id ] ) ? $all[ $id ] : $id;
		}
		/* translators: separator between chained transformation labels, e.g. "Remove accents, then ALL UPPERCASE". */
		return implode( __( ', then ', 'entry-normalizer-for-gravity-forms' ), $parts );
	}
}
