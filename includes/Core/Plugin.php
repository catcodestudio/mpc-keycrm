<?php
/**
 * Plugin singleton.
 *
 * @package MpcKeycrm
 */

namespace CatCode\MpcKeycrm\Core;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var bool */
	private $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'maybe_upgrade' ) );

		( new \CatCode\MpcKeycrm\Sync\Runner() )->register();

		if ( is_admin() ) {
			new \CatCode\MpcKeycrm\Admin\SettingsPage();
		}
	}

	public function maybe_upgrade(): void {
		$installed = get_option( 'mpc_keycrm_version' );
		if ( MPCK_VERSION === $installed ) {
			return;
		}
		Installer::activate();
		update_option( 'mpc_keycrm_version', MPCK_VERSION, false );
	}
}
