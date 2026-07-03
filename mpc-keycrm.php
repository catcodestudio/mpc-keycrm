<?php
/**
 * Plugin Name: MPC KeyCRM Source
 * Plugin URI: https://catcode.com.ua/plugins/mpc-keycrm
 * Description: Аддон до Multi PRRO Connector: фіскалізація замовлень з KeyCRM (Rozetka, Prom, Instagram, власні сайти) через підключені ПРРО — Вчасно.Каса, Checkbox.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: multi-prro-connector
 * Author: CatCode
 * Author URI: https://catcode.com.ua
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mpc-keycrm
 * Domain Path: /languages
 *
 * @package MpcKeycrm
 */

defined( 'ABSPATH' ) || exit;

define( 'MPCK_VERSION', '0.1.0' );
define( 'MPCK_FILE', __FILE__ );
define( 'MPCK_DIR', plugin_dir_path( __FILE__ ) );
define( 'MPCK_URL', plugin_dir_url( __FILE__ ) );
define( 'MPCK_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'CatCode\\MpcKeycrm\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = MPCK_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( '\\CatCode\\MpcKeycrm\\Core\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\CatCode\\MpcKeycrm\\Core\\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\CatCode\\MultiPrroConnector\\Core\\Plugin' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'MPC KeyCRM Source requires the Multi PRRO Connector plugin to be installed and active.', 'mpc-keycrm' ) . '</p></div>';
				}
			);
			return;
		}
		load_plugin_textdomain( 'mpc-keycrm', false, dirname( MPCK_BASENAME ) . '/languages' );
		\CatCode\MpcKeycrm\Core\Plugin::instance()->boot();
	},
	20
);
