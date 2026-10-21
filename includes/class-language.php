<?php
/**
 * The visitor's language, and sending an email in a subscriber's language.
 *
 * Works on its own (WordPress locale) and uses Overlay Multilingual when it is
 * active: its language codes, its locale per language and its dictionary for
 * the finished email.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Language {

	/** Language of the current request, as a short code ("en", "fr"). */
	public static function current() {
		$lang = function_exists( 'ovml_lang' ) ? (string) ovml_lang() : substr( determine_locale(), 0, 2 );
		return (string) apply_filters( 'stms_current_language', sanitize_key( $lang ) );
	}

	/** Languages the store offers: [code => code]. */
	public static function all() {
		$codes = function_exists( 'ovml_languages' ) ? array_keys( (array) ovml_languages() ) : [ substr( get_locale(), 0, 2 ) ];
		return array_combine( $codes, $codes );
	}

	/**
	 * Language the customer ordered in, from Overlay Multilingual or WPML order data
	 * ("" = the store's default). Other plugins: filter stms_order_language.
	 */
	public static function for_order( $order ) {
		$lang = '';
		if ( $order instanceof \WC_Order ) {
			$lang = (string) ( $order->get_meta( '_ovml_lang' ) ?: $order->get_meta( 'wpml_language' ) );
		}
		$lang = (string) apply_filters( 'stms_order_language', sanitize_key( $lang ), $order );
		return isset( self::all()[ $lang ] ) ? $lang : '';
	}

	/** A template's one-line texts through the dictionary (built-in texts are already translated by run()). */
	public static function translate_template( array $tpl, $lang ) {
		foreach ( [ 'subject', 'heading', 'preheader' ] as $key ) {
			$tpl[ $key ] = self::translate_text( (string) ( $tpl[ $key ] ?? '' ), $lang );
		}
		return $tpl;
	}

	public static function is_default( $lang ) {
		return '' === (string) $lang || ( function_exists( 'ovml_is_default' ) ? ovml_is_default( $lang ) : substr( get_locale(), 0, 2 ) === $lang );
	}

	/** Run $fn with $lang as the language for translations; returns what $fn returns. */
	public static function run( $lang, callable $fn ) {
		if ( self::is_default( $lang ) || ! function_exists( 'ovml_locale' ) || ! isset( self::all()[ $lang ] ) ) {
			return $fn();
		}
		$previous              = $GLOBALS['ovml_tlang'] ?? null;
		$GLOBALS['ovml_tlang'] = $lang;
		$switched              = switch_to_locale( ovml_locale( $lang ) );
		try {
			return $fn();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
			if ( null === $previous ) {
				unset( $GLOBALS['ovml_tlang'] );
			} else {
				$GLOBALS['ovml_tlang'] = $previous;
			}
		}
	}

	/** Translate a finished email's text with Overlay Multilingual's dictionary. */
	public static function translate_html( $html, $lang ) {
		if ( ! self::is_default( $lang ) && class_exists( '\OverlayML\Dictionary' ) ) {
			$html = \OverlayML\Dictionary::translate_html( $html, $lang );
		}
		return $html;
	}

	public static function translate_text( $text, $lang ) {
		return ! self::is_default( $lang ) && function_exists( 'ovml_t' ) ? ovml_t( $text, $lang ) : $text;
	}
}
