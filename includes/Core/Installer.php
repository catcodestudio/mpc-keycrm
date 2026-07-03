<?php
/**
 * Installer. Creates the KeyCRM orders tracking table and default options.
 *
 * @package MpcKeycrm
 */

namespace CatCode\MpcKeycrm\Core;

defined( 'ABSPATH' ) || exit;

class Installer {

	public static function activate(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$orders = "CREATE TABLE {$prefix}mpc_keycrm_orders (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			keycrm_order_id BIGINT UNSIGNED NOT NULL,
			source_uuid VARCHAR(64) DEFAULT NULL,
			receipt_id BIGINT UNSIGNED DEFAULT NULL,
			grand_total DECIMAL(13,2) NOT NULL DEFAULT 0,
			status VARCHAR(24) NOT NULL DEFAULT 'pending',
			error_text TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY keycrm_order_id (keycrm_order_id),
			KEY status (status)
		) {$charset};";

		dbDelta( $orders );

		if ( false === get_option( 'mpc_keycrm_settings' ) ) {
			add_option( 'mpc_keycrm_settings', self::default_settings(), '', true );
		}
		update_option( 'mpc_keycrm_version', MPCK_VERSION, false );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'mpc_keycrm_poll' );
	}

	public static function default_settings(): array {
		return array(
			'api_key'          => '',
			'poll_interval'    => '15',
			'fiscal_all_paid'  => 'yes',
			'trigger_statuses' => '',
			'write_comment'    => 'no',
			'payment_type'     => 'CARD',
		);
	}
}
