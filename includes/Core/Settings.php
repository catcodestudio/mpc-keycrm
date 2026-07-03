<?php
/**
 * Addon settings repository. The KeyCRM API key is encrypted at rest
 * with the Multi PRRO Connector Crypto helper (same key/option as MPC).
 *
 * @package MpcKeycrm
 */

namespace CatCode\MpcKeycrm\Core;

use CatCode\MultiPrroConnector\Core\Crypto;

defined( 'ABSPATH' ) || exit;

class Settings {

	private const SECRET_KEYS = array( 'api_key' );

	/** @var array|null */
	private static $cache = null;

	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$raw = get_option( 'mpc_keycrm_settings', Installer::default_settings() );
		if ( ! is_array( $raw ) ) {
			$raw = Installer::default_settings();
		}
		$raw = wp_parse_args( $raw, Installer::default_settings() );

		foreach ( self::SECRET_KEYS as $secret ) {
			if ( isset( $raw[ $secret ] ) && '' !== $raw[ $secret ] && class_exists( Crypto::class ) ) {
				$raw[ $secret ] = Crypto::decrypt( (string) $raw[ $secret ] );
			}
		}
		self::$cache = $raw;
		return $raw;
	}

	public static function get( string $key, $default = null ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	public static function save( array $values ): void {
		foreach ( self::SECRET_KEYS as $secret ) {
			if ( isset( $values[ $secret ] ) && '' !== $values[ $secret ] && class_exists( Crypto::class ) ) {
				$values[ $secret ] = Crypto::encrypt( (string) $values[ $secret ] );
			}
		}
		update_option( 'mpc_keycrm_settings', $values, true );
		self::$cache = null;
	}
}
