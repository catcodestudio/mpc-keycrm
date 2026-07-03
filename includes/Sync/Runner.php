<?php
/**
 * Опитування KeyCRM за розкладом (WP-Cron) + ручний запуск.
 *
 * Тягне замовлення, оновлені у вікні (last_run − 10 хв, now), пагінує,
 * відбирає оплачені (та/або з тригерним статусом), пропускає вже
 * фіскалізовані (unique key у wp_mpc_keycrm_orders) і пробиває чеки
 * через Bridge. Веде журнал останніх запусків.
 *
 * @package MpcKeycrm
 */

namespace CatCode\MpcKeycrm\Sync;

use CatCode\MpcKeycrm\Api\Client;
use CatCode\MpcKeycrm\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Runner {

	public const HOOK        = 'mpc_keycrm_poll';
	public const RUNS_OPTION = 'mpc_keycrm_runs';
	public const LAST_RUN    = 'mpc_keycrm_last_run';

	private const OVERLAP_SECONDS = 600; // 10 хв запас на вікно вибірки.
	private const MAX_PAGES       = 30;  // до 1500 замовлень за прохід.
	private const KEEP_RUNS       = 10;

	/**
	 * Реєстрація хуків. Викликається один раз із Plugin::boot() —
	 * конструктор без side-ефектів, щоб Runner можна було створювати
	 * для разового run() (кнопка «Запустити зараз»).
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( self::HOOK, array( $this, 'cron_run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	public function cron_schedules( array $schedules ): array {
		foreach ( array( 5, 15, 30, 60 ) as $min ) {
			$key = 'mpc_keycrm_' . $min . 'min';
			if ( ! isset( $schedules[ $key ] ) ) {
				$schedules[ $key ] = array(
					'interval' => $min * MINUTE_IN_SECONDS,
					'display'  => sprintf( 'MPC KeyCRM: кожні %d хв', $min ),
				);
			}
		}
		return $schedules;
	}

	public static function schedule_name(): string {
		$interval = (string) Settings::get( 'poll_interval', '15' );
		if ( ! in_array( $interval, array( '5', '15', '30', '60' ), true ) ) {
			$interval = '15';
		}
		return 'mpc_keycrm_' . $interval . 'min';
	}

	/**
	 * Тримає крон у актуальному стані: планує якщо не заплановано,
	 * перепланує якщо змінився інтервал.
	 */
	public function ensure_scheduled(): void {
		$wanted  = self::schedule_name();
		$current = wp_get_schedule( self::HOOK );
		if ( $current === $wanted ) {
			return;
		}
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $wanted, self::HOOK );
	}

	public function cron_run(): void {
		$this->run( false );
	}

	/**
	 * Один прохід синхронізації.
	 *
	 * @param bool $manual true — запуск кнопкою «Запустити зараз».
	 *
	 * @return array Звіт запуску.
	 */
	public function run( bool $manual = false ): array {
		$report = array(
			'time'       => current_time( 'mysql' ),
			'manual'     => $manual ? 1 : 0,
			'from'       => '',
			'to'         => '',
			'checked'    => 0,
			'matched'    => 0,
			'fiscalized' => 0,
			'failed'     => 0,
			'skipped'    => 0,
			'errors'     => array(),
		);

		$cfg     = Settings::all();
		$api_key = (string) $cfg['api_key'];
		if ( '' === $api_key ) {
			$report['errors'][] = 'Не заповнено API-ключ KeyCRM';
			$this->log_run( $report );
			return $report;
		}

		$to   = current_time( 'mysql' );
		$last = (string) get_option( self::LAST_RUN, '' );
		$from = '' !== $last
			? gmdate( 'Y-m-d H:i:s', strtotime( $last ) - self::OVERLAP_SECONDS )
			: gmdate( 'Y-m-d H:i:s', strtotime( $to ) - DAY_IN_SECONDS );

		$report['from'] = $from;
		$report['to']   = $to;

		$client      = new Client( $api_key );
		$all_paid    = 'yes' === (string) $cfg['fiscal_all_paid'];
		$trigger_ids = self::parse_ids( (string) $cfg['trigger_statuses'] );

		if ( ! $all_paid && ! $trigger_ids ) {
			$report['errors'][] = 'Не задано тригерні статуси і вимкнено «фіскалізувати всі оплачені»';
			$this->log_run( $report );
			return $report;
		}

		$page       = 1;
		$api_failed = false;
		do {
			$res = $client->orders_page( $page, $from, $to );
			if ( ! $res['ok'] ) {
				$report['errors'][] = 'KeyCRM API: ' . $res['error'];
				$api_failed         = true;
				break;
			}
			$json      = is_array( $res['json'] ) ? $res['json'] : array();
			$orders    = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();
			$last_page = (int) ( isset( $json['last_page'] ) ? $json['last_page'] : 1 );

			foreach ( $orders as $order ) {
				++$report['checked'];
				$this->process_order( (array) $order, $all_paid, $trigger_ids, $client, $cfg, $report );
			}

			++$page;
		} while ( $page <= $last_page && $page <= self::MAX_PAGES );

		// Вікно зсуваємо тільки якщо API відпрацював — інакше наступний прохід
		// перечитає той самий діапазон.
		if ( ! $api_failed ) {
			update_option( self::LAST_RUN, $to, false );
		}

		$this->log_run( $report );
		return $report;
	}

	private function process_order( array $order, bool $all_paid, array $trigger_ids, Client $client, array $cfg, array &$report ): void {
		$order_id = (int) ( isset( $order['id'] ) ? $order['id'] : 0 );
		if ( $order_id <= 0 ) {
			return;
		}

		$paid = 'paid' === (string) ( isset( $order['payment_status'] ) ? $order['payment_status'] : '' );
		if ( ! $paid ) {
			return;
		}
		if ( ! $all_paid && ! in_array( (int) ( isset( $order['status_id'] ) ? $order['status_id'] : 0 ), $trigger_ids, true ) ) {
			return;
		}

		++$report['matched'];

		$tracked = $this->tracked_row( $order_id );
		if ( $tracked && 'completed' === $tracked['status'] ) {
			++$report['skipped'];
			return;
		}

		$receipt = Bridge::build_receipt( $order );
		if ( null === $receipt ) {
			++$report['skipped'];
			$this->upsert_tracked( $order_id, $order, null, 'skipped', 'Порожній або нульовий чек' );
			return;
		}

		$this->upsert_tracked( $order_id, $order, null, 'pending', null );

		$result = Bridge::fiscalize( $receipt );

		if ( ! empty( $result['ok'] ) ) {
			$this->upsert_tracked( $order_id, $order, (int) $result['receipt_id'], 'completed', null );
			if ( ! empty( $result['already'] ) ) {
				++$report['skipped'];
			} else {
				++$report['fiscalized'];
				if ( 'yes' === (string) $cfg['write_comment'] ) {
					$this->write_comment( $client, $order_id, $result );
				}
			}
			return;
		}

		++$report['failed'];
		if ( count( $report['errors'] ) < 5 ) {
			$report['errors'][] = sprintf( '#%d: %s', $order_id, $result['error'] );
		}
		$this->upsert_tracked( $order_id, $order, $result['receipt_id'] > 0 ? (int) $result['receipt_id'] : null, 'failed', (string) $result['error'] );
	}

	/**
	 * Дописати лінк на фіскальний чек у коментар менеджера KeyCRM
	 * (append — існуючий текст не затирається).
	 */
	private function write_comment( Client $client, int $order_id, array $result ): void {
		$fiscal_id = (string) $result['fiscal_id'];
		$url       = '' !== (string) $result['pdf_url'] ? (string) $result['pdf_url'] : (string) $result['html_url'];
		$line      = '' !== $url
			? sprintf( 'Фіскальний чек: %s (№ %s)', $url, $fiscal_id )
			: sprintf( 'Фіскальний чек № %s', $fiscal_id );

		$existing = '';
		$res      = $client->get_order( $order_id );
		if ( $res['ok'] && is_array( $res['json'] ) ) {
			$existing = (string) ( isset( $res['json']['manager_comment'] ) ? $res['json']['manager_comment'] : '' );
		}
		if ( '' !== $fiscal_id && false !== strpos( $existing, $fiscal_id ) ) {
			return; // Лінк уже дописано раніше.
		}
		$comment = '' !== trim( $existing ) ? rtrim( $existing ) . "\n" . $line : $line;
		$client->set_manager_comment( $order_id, $comment );
	}

	private function tracked_row( int $order_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE keycrm_order_id = %d LIMIT 1',
				self::table(),
				$order_id
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	private function upsert_tracked( int $order_id, array $order, ?int $receipt_id, string $status, ?string $error ): void {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = array(
			'source_uuid' => (string) ( isset( $order['source_uuid'] ) ? $order['source_uuid'] : '' ),
			'grand_total' => round( (float) ( isset( $order['grand_total'] ) ? $order['grand_total'] : 0 ), 2 ),
			'status'      => $status,
			'error_text'  => $error,
			'updated_at'  => $now,
		);
		if ( null !== $receipt_id ) {
			$row['receipt_id'] = $receipt_id;
		}

		$existing = $this->tracked_row( $order_id );
		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$wpdb->update( self::table(), $row, array( 'keycrm_order_id' => $order_id ) );
			return;
		}

		$row['keycrm_order_id'] = $order_id;
		$row['created_at']      = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, unique key гарантує ідемпотентність.
		$wpdb->insert( self::table(), $row );
	}

	private function log_run( array $report ): void {
		$runs = get_option( self::RUNS_OPTION, array() );
		if ( ! is_array( $runs ) ) {
			$runs = array();
		}
		array_unshift( $runs, $report );
		$runs = array_slice( $runs, 0, self::KEEP_RUNS );
		update_option( self::RUNS_OPTION, $runs, false );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mpc_keycrm_orders';
	}

	public static function parse_ids( string $csv ): array {
		$out = array();
		foreach ( explode( ',', $csv ) as $part ) {
			$id = (int) trim( $part );
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
