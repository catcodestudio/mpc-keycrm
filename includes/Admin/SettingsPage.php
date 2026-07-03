<?php
/**
 * Сторінка налаштувань аддона (підменю у меню «Каса» від MPC).
 *
 * @package MpcKeycrm
 */

namespace CatCode\MpcKeycrm\Admin;

use CatCode\MpcKeycrm\Api\Client;
use CatCode\MpcKeycrm\Core\Settings;
use CatCode\MpcKeycrm\Sync\Runner;
use CatCode\MultiPrroConnector\Adapters\Registry;
use CatCode\MultiPrroConnector\Core\Settings as MpcSettings;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	private const PAGE_SLUG          = 'mpc-keycrm';
	private const STATUSES_TRANSIENT = 'mpc_keycrm_statuses';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_mpc_keycrm_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_mpc_keycrm_load_statuses', array( $this, 'handle_load_statuses' ) );
		add_action( 'admin_post_mpc_keycrm_run_now', array( $this, 'handle_run_now' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'mpc-settings',
			'KeyCRM',
			'KeyCRM',
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$cfg = Settings::all();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flags.
		$notice = isset( $_GET['mpck_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['mpck_notice'] ) ) : '';

		echo '<div class="wrap"><h1>KeyCRM → ПРРО</h1>';
		echo '<p>Фіскалізація замовлень з KeyCRM (Rozetka, Prom, Instagram, сайти) через ПРРО-сервіси, підключені у Multi PRRO Connector.</p>';

		$this->render_notice( $notice );
		$this->render_provider_status();
		$this->render_settings_form( $cfg );
		$this->render_statuses_table();
		$this->render_runs_log();

		echo '</div>';
	}

	private function render_notice( string $notice ): void {
		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success"><p>Налаштування збережено.</p></div>';
		} elseif ( 'statuses' === $notice ) {
			echo '<div class="notice notice-success"><p>Довідник статусів завантажено (кеш на 1 годину).</p></div>';
		} elseif ( 'statuses_error' === $notice ) {
			$err = get_transient( 'mpc_keycrm_last_error' );
			echo '<div class="notice notice-error"><p>Не вдалося завантажити статуси' . ( $err ? ': ' . esc_html( (string) $err ) : '.' ) . '</p></div>';
		} elseif ( 'ran' === $notice ) {
			$runs = get_option( Runner::RUNS_OPTION, array() );
			$last = is_array( $runs ) && $runs ? $runs[0] : null;
			if ( $last ) {
				echo '<div class="notice notice-info"><p>Запуск виконано: перевірено ' . (int) $last['checked'] . ', під тригер ' . (int) $last['matched'] . ', фіскалізовано ' . (int) $last['fiscalized'] . ', помилок ' . (int) $last['failed'] . ', пропущено ' . (int) $last['skipped'] . '.</p></div>';
			} else {
				echo '<div class="notice notice-info"><p>Запуск виконано.</p></div>';
			}
		}
	}

	/**
	 * Показує успадкований з MPC ланцюжок провайдерів (тут не редагується).
	 */
	private function render_provider_status(): void {
		$mpc      = MpcSettings::all();
		$labels   = Registry::labels();
		$primary  = (string) ( isset( $mpc['primary_provider'] ) ? $mpc['primary_provider'] : '' );
		$fallback = (string) ( isset( $mpc['fallback_provider'] ) ? $mpc['fallback_provider'] : '' );

		echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid ' . ( '' !== $primary ? '#1a7f37' : '#a00' ) . ';padding:10px 14px;margin:12px 0;max-width:760px">';
		if ( '' !== $primary ) {
			echo '<strong>ПРРО-сервіс (з налаштувань MPC):</strong> ' . esc_html( isset( $labels[ $primary ] ) ? $labels[ $primary ] : $primary );
			if ( '' !== $fallback && $fallback !== $primary ) {
				echo ' → резервний: ' . esc_html( isset( $labels[ $fallback ] ) ? $labels[ $fallback ] : $fallback );
			}
		} else {
			echo '<strong>ПРРО-сервіс не вибрано.</strong> Спочатку налаштуйте основний сервіс у <a href="' . esc_url( admin_url( 'admin.php?page=mpc-settings' ) ) . '">Каса → Налаштування</a>.';
		}
		echo '</div>';
	}

	private function render_settings_form( array $cfg ): void {
		$next = wp_next_scheduled( Runner::HOOK );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:760px">';
		wp_nonce_field( 'mpc_keycrm_save' );
		echo '<input type="hidden" name="action" value="mpc_keycrm_save"/>';

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="mpck_api_key">API-ключ KeyCRM</label></th><td>';
		$has_key = '' !== (string) $cfg['api_key'];
		echo '<input type="password" id="mpck_api_key" name="api_key" value="" class="regular-text" autocomplete="new-password" placeholder="' . ( $has_key ? '••••••••••••••• (збережено)' : '' ) . '"/>';
		echo '<p class="description">Кабінет KeyCRM → Налаштування → API. Зберігається у зашифрованому вигляді. Порожнє поле не затирає збережений ключ.</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="mpck_interval">Інтервал опитування</label></th><td>';
		echo '<select id="mpck_interval" name="poll_interval">';
		foreach ( array( '5', '15', '30', '60' ) as $min ) {
			echo '<option value="' . esc_attr( $min ) . '" ' . selected( (string) $cfg['poll_interval'], $min, false ) . '>кожні ' . esc_html( $min ) . ' хв</option>';
		}
		echo '</select>';
		if ( $next ) {
			echo '<p class="description">Наступний запуск за розкладом: ' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y-m-d H:i:s' ) ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">Тригер фіскалізації</th><td>';
		echo '<label><input type="checkbox" name="fiscal_all_paid" value="yes" ' . checked( 'yes', (string) $cfg['fiscal_all_paid'], false ) . '/> Фіскалізувати всі оплачені замовлення (payment_status = paid)</label>';
		echo '<p style="margin:10px 0 4px"><label for="mpck_statuses">…або тільки оплачені у вибраних статусах KeyCRM (ID через кому):</label></p>';
		echo '<input type="text" id="mpck_statuses" name="trigger_statuses" value="' . esc_attr( (string) $cfg['trigger_statuses'] ) . '" class="regular-text" placeholder="напр. 3,5,12"/>';
		echo '<p class="description">Список статусів працює коли чекбокс вимкнено. ID дивіться у довіднику нижче («Завантажити статуси»).</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="mpck_paytype">Форма оплати у чеку</label></th><td>';
		echo '<select id="mpck_paytype" name="payment_type">';
		echo '<option value="CARD" ' . selected( 'CARD', (string) $cfg['payment_type'], false ) . '>Безготівкова (картка)</option>';
		echo '<option value="CASH" ' . selected( 'CASH', (string) $cfg['payment_type'], false ) . '>Готівка</option>';
		echo '</select>';
		echo '<p class="description">KeyCRM не передає форму оплати у стандартизованому вигляді, тому вона задається тут для всіх чеків.</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">Зворотний звʼязок у CRM</th><td>';
		echo '<label><input type="checkbox" name="write_comment" value="yes" ' . checked( 'yes', (string) $cfg['write_comment'], false ) . '/> Дописувати посилання на фіскальний чек у коментар менеджера замовлення KeyCRM</label>';
		echo '<p class="description">Дописується у кінець коментаря, існуючий текст не затирається.</p>';
		echo '</td></tr>';

		echo '</table>';

		echo '<p style="display:flex;gap:8px;align-items:center">';
		submit_button( 'Зберегти', 'primary', 'submit', false );
		echo '</p>';
		echo '</form>';

		// Окремі кнопки-дії (свої admin-post форми).
		echo '<p style="display:flex;gap:8px;align-items:center;max-width:760px">';
		echo '<a href="' . esc_url( wp_nonce_url( add_query_arg( 'action', 'mpc_keycrm_load_statuses', admin_url( 'admin-post.php' ) ), 'mpc_keycrm_load_statuses' ) ) . '" class="button">Завантажити статуси</a>';
		echo '<a href="' . esc_url( wp_nonce_url( add_query_arg( 'action', 'mpc_keycrm_run_now', admin_url( 'admin-post.php' ) ), 'mpc_keycrm_run_now' ) ) . '" class="button button-secondary">Запустити зараз</a>';
		echo '<small style="color:#666">«Запустити зараз» тягне замовлення за останнє вікно і фіскалізує ті, що підпадають під тригер.</small>';
		echo '</p>';
	}

	private function render_statuses_table(): void {
		$statuses = get_transient( self::STATUSES_TRANSIENT );
		if ( ! is_array( $statuses ) || ! $statuses ) {
			return;
		}
		echo '<h2>Довідник статусів KeyCRM</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>ID</th><th>Назва</th><th>Alias</th><th>Група</th><th>Закриває замовлення</th></tr></thead><tbody>';
		foreach ( $statuses as $s ) {
			$s = (array) $s;
			echo '<tr>';
			echo '<td><code>' . (int) ( isset( $s['id'] ) ? $s['id'] : 0 ) . '</code></td>';
			echo '<td>' . esc_html( (string) ( isset( $s['name'] ) ? $s['name'] : '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( isset( $s['alias'] ) ? $s['alias'] : '' ) ) . '</td>';
			echo '<td>' . (int) ( isset( $s['group_id'] ) ? $s['group_id'] : 0 ) . '</td>';
			echo '<td>' . ( ! empty( $s['is_closing_order'] ) ? 'так' : '—' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_runs_log(): void {
		$runs = get_option( Runner::RUNS_OPTION, array() );
		if ( ! is_array( $runs ) || ! $runs ) {
			return;
		}
		echo '<h2>Останні запуски</h2>';
		echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>Час</th><th>Тип</th><th>Вікно</th><th>Перевірено</th><th>Під тригер</th><th>Фіскалізовано</th><th>Помилок</th><th>Пропущено</th><th>Деталі помилок</th></tr></thead><tbody>';
		foreach ( $runs as $run ) {
			$run    = (array) $run;
			$errors = isset( $run['errors'] ) && is_array( $run['errors'] ) ? $run['errors'] : array();
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( isset( $run['time'] ) ? $run['time'] : '' ) ) . '</td>';
			echo '<td>' . ( ! empty( $run['manual'] ) ? 'вручну' : 'крон' ) . '</td>';
			echo '<td><small>' . esc_html( (string) ( isset( $run['from'] ) ? $run['from'] : '' ) ) . ' →<br>' . esc_html( (string) ( isset( $run['to'] ) ? $run['to'] : '' ) ) . '</small></td>';
			echo '<td>' . (int) ( isset( $run['checked'] ) ? $run['checked'] : 0 ) . '</td>';
			echo '<td>' . (int) ( isset( $run['matched'] ) ? $run['matched'] : 0 ) . '</td>';
			echo '<td style="color:#1a7f37;font-weight:600">' . (int) ( isset( $run['fiscalized'] ) ? $run['fiscalized'] : 0 ) . '</td>';
			echo '<td style="color:' . ( ! empty( $run['failed'] ) ? '#a00' : '#7e8993' ) . '">' . (int) ( isset( $run['failed'] ) ? $run['failed'] : 0 ) . '</td>';
			echo '<td>' . (int) ( isset( $run['skipped'] ) ? $run['skipped'] : 0 ) . '</td>';
			echo '<td><small style="color:#a00">' . esc_html( implode( ' | ', array_map( 'strval', $errors ) ) ) . '</small></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Пробиті чеки — на спільній сторінці <a href="' . esc_url( admin_url( 'admin.php?page=mpc-receipts' ) ) . '">Каса → Чеки</a> (у колонці «Замовлення» — ID замовлення KeyCRM).</p>';
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Немає прав' );
		}
		check_admin_referer( 'mpc_keycrm_save' );

		$cfg = Settings::all();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$api_key = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : '';
		if ( '' !== $api_key ) {
			$cfg['api_key'] = sanitize_text_field( $api_key );
		}

		$interval = isset( $_POST['poll_interval'] ) ? sanitize_key( wp_unslash( $_POST['poll_interval'] ) ) : '15';
		if ( ! in_array( $interval, array( '5', '15', '30', '60' ), true ) ) {
			$interval = '15';
		}
		$cfg['poll_interval'] = $interval;

		$cfg['fiscal_all_paid'] = isset( $_POST['fiscal_all_paid'] ) ? 'yes' : 'no';
		$cfg['write_comment']   = isset( $_POST['write_comment'] ) ? 'yes' : 'no';

		$statuses_csv            = isset( $_POST['trigger_statuses'] ) ? sanitize_text_field( wp_unslash( $_POST['trigger_statuses'] ) ) : '';
		$cfg['trigger_statuses'] = implode( ',', Runner::parse_ids( $statuses_csv ) );

		$paytype             = isset( $_POST['payment_type'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['payment_type'] ) ) ) : 'CARD';
		$cfg['payment_type'] = in_array( $paytype, array( 'CASH', 'CARD' ), true ) ? $paytype : 'CARD';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		Settings::save( $cfg );

		// Перепланувати крон під новий інтервал.
		wp_clear_scheduled_hook( Runner::HOOK );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, Runner::schedule_name(), Runner::HOOK );

		$this->redirect_back( 'saved' );
	}

	public function handle_load_statuses(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Немає прав' );
		}
		check_admin_referer( 'mpc_keycrm_load_statuses' );

		$api_key = (string) Settings::get( 'api_key', '' );
		$client  = new Client( $api_key );
		$res     = $client->statuses();

		if ( ! $res['ok'] || ! is_array( $res['json'] ) ) {
			set_transient( 'mpc_keycrm_last_error', (string) $res['error'], 5 * MINUTE_IN_SECONDS );
			$this->redirect_back( 'statuses_error' );
		}

		set_transient( self::STATUSES_TRANSIENT, $res['json'], HOUR_IN_SECONDS );
		$this->redirect_back( 'statuses' );
	}

	public function handle_run_now(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Немає прав' );
		}
		check_admin_referer( 'mpc_keycrm_run_now' );

		$runner = new Runner();
		$runner->run( true );

		$this->redirect_back( 'ran' );
	}

	private function redirect_back( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE_SLUG,
					'mpck_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
