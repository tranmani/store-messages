<?php
/**
 * Updates from GitHub releases.
 *
 * The plugin is not on WordPress.org, so it plugs into WordPress's own update
 * system instead: the latest GitHub release is checked (cached for six hours),
 * and a newer version appears on Dashboard → Updates and the Plugins screen with
 * the usual "Update now" button and a "View details" changelog. The release's
 * attached store-messages.zip is installed; when a release has no asset,
 * GitHub's source zip is used and its folder renamed to the plugin's slug.
 *
 * Point a fork at its own repository with the `stms_update_repository` filter,
 * or switch checks off under WooCommerce → Messages → Settings.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Updater {

	const REPOSITORY = 'tranmani/store-messages';
	const CACHE      = 'stms_update_release';
	const SLUG       = 'store-messages';

	public static function init() {
		if ( empty( stms_settings()['updates'] ) ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'inject' ] );
		add_filter( 'plugins_api', [ __CLASS__, 'details' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ __CLASS__, 'fix_folder' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'after_update' ], 10, 2 );
	}

	public static function repository() {
		return (string) apply_filters( 'stms_update_repository', self::REPOSITORY );
	}

	public static function basename() {
		return plugin_basename( STMS_FILE );
	}

	/**
	 * Latest release as [version, package, url, notes, published], or null.
	 * Cached; failures are cached briefly so a GitHub outage does not slow admin.
	 */
	public static function latest( $force = false ) {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}
		$response = wp_remote_get( 'https://api.github.com/repos/' . self::repository() . '/releases/latest', [
			'timeout' => 10,
			'headers' => [ 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'store-messages/' . STMS_VERSION ],
		] );
		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $release['tag_name'] ) ) {
			set_site_transient( self::CACHE, [ 'version' => '' ], HOUR_IN_SECONDS );
			return null;
		}
		$package = $release['zipball_url'] ?? '';
		foreach ( (array) ( $release['assets'] ?? [] ) as $asset ) {
			if ( self::SLUG . '.zip' === ( $asset['name'] ?? '' ) ) {
				$package = $asset['browser_download_url'];
			}
		}
		$data = [
			'version'   => ltrim( (string) $release['tag_name'], 'vV' ),
			'package'   => $package,
			'url'       => (string) ( $release['html_url'] ?? 'https://github.com/' . self::repository() ),
			'notes'     => (string) ( $release['body'] ?? '' ),
			'published' => (string) ( $release['published_at'] ?? '' ),
		];
		set_site_transient( self::CACHE, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/** Cached latest release without contacting GitHub (null if never checked or the check failed). */
	public static function cached() {
		$cached = get_site_transient( self::CACHE );
		return is_array( $cached ) && ! empty( $cached['version'] ) ? $cached : null;
	}

	public static function has_update() {
		$latest = self::latest();
		return $latest && version_compare( $latest['version'], STMS_VERSION, '>' ) ? $latest : null;
	}

	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$item = (object) [
			'id'          => 'github.com/' . self::repository(),
			'slug'        => self::SLUG,
			'plugin'      => self::basename(),
			'new_version' => STMS_VERSION,
			'url'         => 'https://github.com/' . self::repository(),
			'package'     => '',
		];
		$update = self::has_update();
		if ( $update ) {
			$item->new_version                       = $update['version'];
			$item->package                           = $update['package'];
			$transient->response[ self::basename() ] = $item;
		} else {
			$transient->no_update[ self::basename() ] = $item; // enables the auto-update toggle
		}
		return $transient;
	}

	/** "View details" popup on the Plugins and Updates screens. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$latest = self::latest();
		return (object) [
			'name'          => 'Store Messages',
			'slug'          => self::SLUG,
			'version'       => $latest['version'] ?? STMS_VERSION,
			'author'        => '<a href="https://github.com/' . esc_attr( self::repository() ) . '">Store Messages contributors</a>',
			'homepage'      => 'https://github.com/' . self::repository(),
			'requires'      => '6.4',
			'requires_php'  => '8.1',
			'last_updated'  => $latest['published'] ?? '',
			'download_link' => $latest['package'] ?? '',
			'sections'      => [
				'description' => esc_html__( 'Beautiful, customisable emails from your WooCommerce store to your customers: tracking links, pickup notices, refunds and personal messages.', 'store-messages' ),
				'changelog'   => $latest ? wp_kses_post( wpautop( esc_html( $latest['notes'] ) ) ) : '',
			],
		];
	}

	/** GitHub source zips unpack to owner-repo-sha/; WordPress needs store-messages/. */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		global $wp_filesystem;
		if ( ( $hook_extra['plugin'] ?? '' ) !== self::basename() || basename( $source ) === self::SLUG ) {
			return $source;
		}
		$target = trailingslashit( $remote_source ) . self::SLUG . '/';
		return $wp_filesystem && $wp_filesystem->move( $source, $target, true ) ? $target : $source;
	}

	/** Forget the cached release after updating, so the next check starts fresh. */
	public static function after_update( $upgrader, $options ) {
		if ( 'plugin' === ( $options['type'] ?? '' ) && in_array( self::basename(), (array) ( $options['plugins'] ?? [] ), true ) ) {
			delete_site_transient( self::CACHE );
		}
	}

	/** One-click update URL (WordPress's own updater, nonce included). */
	public static function update_url() {
		return wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( self::basename() ) ), 'upgrade-plugin_' . self::basename() );
	}
}
