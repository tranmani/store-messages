<?php
/**
 * Shipping carriers and their tracking-page URLs.
 *
 * Each carrier has a URL pattern with {number} where the tracking number goes.
 * Pasting a full tracking link works too: the carrier is recognised from the
 * link and the number read from it.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Carriers {

	const OPTION = 'stms_carriers';

	/** Built-in list; the store can edit, add and remove carriers on the Carriers tab. */
	public static function defaults() {
		return [
			'swiss-post' => [ 'name' => 'Swiss Post', 'url' => 'https://www.post.ch/swisspost-tracking?formattedParcelCodes={number}' ],
			'la-poste'   => [ 'name' => 'La Poste / Colissimo', 'url' => 'https://www.laposte.fr/outils/suivre-vos-envois?code={number}' ],
			'dhl'        => [ 'name' => 'DHL', 'url' => 'https://www.dhl.com/global-en/home/tracking/tracking-express.html?submit=1&tracking-id={number}' ],
			'dhl-de'     => [ 'name' => 'DHL Paket (Germany)', 'url' => 'https://www.dhl.de/de/privatkunden/pakete-empfangen/verfolgen.html?piececode={number}' ],
			'dpd'        => [ 'name' => 'DPD', 'url' => 'https://tracking.dpd.de/status/en_US/parcel/{number}' ],
			'gls'        => [ 'name' => 'GLS', 'url' => 'https://gls-group.com/track/{number}' ],
			'ups'        => [ 'name' => 'UPS', 'url' => 'https://www.ups.com/track?tracknum={number}' ],
			'fedex'      => [ 'name' => 'FedEx', 'url' => 'https://www.fedex.com/fedextrack/?trknbr={number}' ],
			'postnl'     => [ 'name' => 'PostNL', 'url' => 'https://jouw.postnl.nl/track-and-trace/{number}' ],
			'poste-it'   => [ 'name' => 'Poste Italiane', 'url' => 'https://www.poste.it/cerca/index.html#/risultati-spedizioni/{number}' ],
			'correos'    => [ 'name' => 'Correos', 'url' => 'https://www.correos.es/es/es/herramientas/localizador/envios/detalle?tracking-number={number}' ],
			'inpost'     => [ 'name' => 'InPost', 'url' => 'https://inpost.pl/sledzenie-przesylek?number={number}' ],
			'royal-mail' => [ 'name' => 'Royal Mail', 'url' => 'https://www.royalmail.com/track-your-item#/tracking-results/{number}' ],
			'usps'       => [ 'name' => 'USPS', 'url' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels={number}' ],
			'parcelsapp' => [ 'name' => 'Parcels (any carrier)', 'url' => 'https://parcelsapp.com/en/tracking/{number}' ],
		];
	}

	public static function all() {
		$saved = get_option( self::OPTION, null );
		return is_array( $saved ) ? $saved : self::defaults();
	}

	public static function save( array $carriers ) {
		update_option( self::OPTION, $carriers, false );
	}

	public static function get( $id ) {
		return self::all()[ $id ] ?? null;
	}

	public static function url_for( $id, $number ) {
		$carrier = self::get( $id );
		if ( ! $carrier || '' === $number ) {
			return '';
		}
		return str_replace( '{number}', rawurlencode( $number ), $carrier['url'] );
	}

	/**
	 * Turn what the user typed (a number, or a full link) into
	 * [carrier id, carrier name, number, url]. Unknown links are kept as they are.
	 */
	public static function resolve( $input, $carrier_id = '' ) {
		$input = trim( (string) $input );
		if ( '' === $input ) {
			return [ 'carrier' => '', 'name' => '', 'number' => '', 'url' => '' ];
		}
		if ( preg_match( '#^https?://#i', $input ) ) {
			$url   = esc_url_raw( $input );
			$found = self::match_url( $url );
			return [
				'carrier' => $found['carrier'] ?? $carrier_id,
				'name'    => $found ? self::all()[ $found['carrier'] ]['name'] : ( self::get( $carrier_id )['name'] ?? (string) wp_parse_url( $url, PHP_URL_HOST ) ),
				'number'  => $found['number'] ?? '',
				'url'     => $url,
			];
		}
		$number = preg_replace( '/\s+/', '', $input );
		return [
			'carrier' => $carrier_id,
			'name'    => self::get( $carrier_id )['name'] ?? '',
			'number'  => $number,
			'url'     => self::url_for( $carrier_id, $number ),
		];
	}

	/** Find which carrier a tracking link belongs to, and the number inside it. */
	public static function match_url( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		foreach ( self::all() as $id => $carrier ) {
			$pattern = (string) $carrier['url'];
			if ( strtolower( (string) wp_parse_url( str_replace( '{number}', 'X', $pattern ), PHP_URL_HOST ) ) !== $host ) {
				continue;
			}
			// The pattern as a regex: everything literal except {number}. Query order and
			// extra parameters vary, so also look for the number's own query parameter.
			$regex = '#^' . str_replace( '\{number\}', '([^&\#/?]+)', preg_quote( preg_replace( '#^https?://#', '', $pattern ), '#' ) ) . '#i';
			if ( preg_match( $regex, preg_replace( '#^https?://#', '', $url ), $m ) ) {
				return [ 'carrier' => $id, 'number' => rawurldecode( $m[1] ) ];
			}
			parse_str( (string) wp_parse_url( $pattern, PHP_URL_QUERY ), $pq );
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $uq );
			foreach ( $pq as $key => $value ) {
				if ( '{number}' === $value && ! empty( $uq[ $key ] ) ) {
					return [ 'carrier' => $id, 'number' => (string) $uq[ $key ] ];
				}
			}
			// Same host, different path: still this carrier, number unknown.
			$fallback = $fallback ?? [ 'carrier' => $id, 'number' => self::number_from_path( $url ) ];
		}
		return $fallback ?? null;
	}

	/** Last path or query value that looks like a parcel number. */
	private static function number_from_path( $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$candidates = array_merge( array_values( array_filter( $q, 'is_string' ) ), array_reverse( explode( '/', (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		foreach ( $candidates as $c ) {
			if ( preg_match( '/^[A-Z0-9]{8,40}$/i', $c ) && preg_match( '/\d{4}/', $c ) ) {
				return $c;
			}
		}
		return '';
	}

	/** First tracking-looking link in a block of text (used for "Note to customer"). */
	public static function find_in_text( $text ) {
		if ( ! preg_match_all( '#https?://[^\s<>"\']+#i', (string) $text, $m ) ) {
			return null;
		}
		foreach ( $m[0] as $url ) {
			$url   = rtrim( $url, '.,;:!?)' );
			$found = self::match_url( $url );
			if ( $found ) {
				return [ 'carrier' => $found['carrier'], 'name' => self::all()[ $found['carrier'] ]['name'], 'number' => $found['number'], 'url' => $url ];
			}
			if ( preg_match( '#track|suivi|sendung|sledz|parcel#i', $url ) ) {
				return [ 'carrier' => '', 'name' => (string) wp_parse_url( $url, PHP_URL_HOST ), 'number' => self::number_from_path( $url ), 'url' => $url ];
			}
		}
		return null;
	}
}
