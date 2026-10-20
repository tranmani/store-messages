<?php
/**
 * Sign-ups from other plugins' forms: Brave Popup.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Integrations {

	public static function init() {
		add_action( 'bravepop_user_submitted_form', [ __CLASS__, 'brave_submitted' ], 10, 4 );
	}

	/* ---------------------------------------------------------- Brave Popup -- */

	public static function brave_active() {
		return defined( 'BRAVEPOP_PLUGIN_FILE' ) && post_type_exists( 'popup' );
	}

	/** Popup ids whose form submissions add a subscriber. */
	public static function brave_connected() {
		return array_filter( array_map( 'absint', explode( ',', (string) stms_settings()['signup']['brave'] ) ) );
	}

	/**
	 * Brave popups that have a form: [id => [title, status, newsletter]] where newsletter is the
	 * service the form itself still sends to inside Brave ('' when its newsletter action is off).
	 */
	public static function brave_popups() {
		$out = [];
		foreach ( get_posts( [ 'post_type' => 'popup', 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'numberposts' => 100 ] ) as $p ) {
			$data  = json_decode( (string) get_post_meta( $p->ID, 'popup_data', true ), true );
			$forms = self::brave_forms( $data );
			if ( ! $forms ) {
				continue;
			}
			$service = '';
			foreach ( $forms as $form ) {
				$n = $form['formData']['settings']['action']['newsletter'] ?? [];
				if ( ! empty( $n['enable'] ) && ! empty( $n['type'] ) ) {
					$service = (string) $n['type'];
				}
			}
			$out[ $p->ID ] = [
				'title'      => get_the_title( $p ) ?: '#' . $p->ID,
				'status'     => $p->post_status,
				'newsletter' => $service,
			];
		}
		return $out;
	}

	private static function brave_forms( $data ) {
		$forms = [];
		foreach ( (array) ( $data['steps'] ?? [] ) as $step ) {
			foreach ( [ 'desktop', 'mobile' ] as $device ) {
				foreach ( (array) ( $step[ $device ]['content'] ?? [] ) as $el ) {
					if ( 'form' === ( $el['type'] ?? '' ) ) {
						$forms[] = $el;
					}
				}
			}
		}
		return $forms;
	}

	/**
	 * Brave has validated the form and is about to run its own actions.
	 *
	 * @param int|string $popup_id Popup post id.
	 * @param array      $form     ['fields' => {field id: {type, validation, value, ...}}, 'actions' => ..., ...].
	 * @param string     $page_url Page the visitor was on.
	 */
	public static function brave_submitted( $popup_id, $form, $page_url = '', $user = [] ) {
		if ( ! in_array( absint( $popup_id ), self::brave_connected(), true ) ) {
			return;
		}
		$email = '';
		$name  = '';
		$first = '';
		$last  = '';
		foreach ( (array) ( $form['fields'] ?? [] ) as $field ) {
			$field = (array) $field;
			$value = $field['value'] ?? '';
			if ( ! is_string( $value ) || '' === trim( $value ) || 'input' !== ( $field['type'] ?? '' ) ) {
				continue;
			}
			$validation = (string) ( $field['validation'] ?? '' );
			$uid        = (string) ( $field['uid'] ?? '' );
			if ( '' === $email && ( 'email' === $validation || 'email' === $uid ) && is_email( trim( $value ) ) ) {
				$email = trim( $value );
			} elseif ( '' === $name && ( 'name' === $validation || in_array( $uid, [ 'name', 'firstname', 'first_name', 'fullname' ], true ) ) ) {
				$name = $value;
				if ( 'name' === $validation && ! empty( $field[ $uid ] ) ) { // Brave split "first,last" already
					$first = (string) $field[ $uid ];
					$last  = (string) ( $field[ $field['uidl'] ?? '' ] ?? '' );
				}
			} elseif ( '' === $last && in_array( $uid, [ 'lastname', 'last_name' ], true ) ) {
				$last = $value;
			}
		}
		if ( '' === $email ) {
			return;
		}
		if ( '' === $first ) {
			$parts = preg_split( '/\s+/', trim( $name ), 2 );
			$first = $parts[0] ?? '';
			$last  = '' !== $last ? $last : ( $parts[1] ?? '' );
		}
		Subscribers::subscribe( [
			'email'      => $email,
			'first_name' => $first,
			'last_name'  => $last,
			'source'     => 'brave',
			'lang'       => self::lang_from_url( $page_url ),
			'user_id'    => get_current_user_id(),
			/* translators: %s: popup title */
			'consent'    => sprintf( __( 'Brave Popup form: %s', 'store-messages' ), get_the_title( absint( $popup_id ) ) ),
		] );
	}

	/** Language of the page the visitor was on: a /xx/ prefix, else the default language (Brave posts through admin-ajax). */
	private static function lang_from_url( $url ) {
		$path  = trim( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), '/' );
		$first = strtolower( explode( '/', $path )[0] );
		if ( isset( Language::all()[ $first ] ) ) {
			return $first;
		}
		foreach ( Language::all() as $code ) {
			if ( Language::is_default( $code ) ) {
				return $code;
			}
		}
		return Language::current();
	}
}
