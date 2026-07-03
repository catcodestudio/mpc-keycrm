<?php
/**
 * Uninstall handler. Drops the addon tracking table and removes options.
 * Чеки у спільних таблицях MPC (wp_mpc_receipts) не чіпаємо — це аудиторські
 * дані основного плагіна.
 *
 * @package MpcKeycrm
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- plugin uninstall.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mpc_keycrm_orders' );

delete_option( 'mpc_keycrm_settings' );
delete_option( 'mpc_keycrm_version' );
delete_option( 'mpc_keycrm_last_run' );
delete_option( 'mpc_keycrm_runs' );
delete_transient( 'mpc_keycrm_statuses' );
delete_transient( 'mpc_keycrm_last_error' );
