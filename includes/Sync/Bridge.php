<?php
/**
 * KeyCRM → PRRO bridge.
 *
 * Fiscalizer із Multi PRRO Connector жорстко зав'язаний на WC_Order
 * (wc_get_order, order meta, order notes), тому аддон НЕ дублює адаптери,
 * а формує ту саму нормалізовану структуру чека (як Fiscalizer::build_receipt)
 * із даних KeyCRM і викликає адаптери напряму через Registry, записуючи
 * чеки/спроби у спільні таблиці MPC через Logger — чеки зʼявляються
 * на спільній сторінці «Каса → Чеки».
 *
 * @package MpcKeycrm
 */

namespace CatCode\MpcKeycrm\Sync;

use CatCode\MpcKeycrm\Core\Settings;
use CatCode\MultiPrroConnector\Adapters\Registry;
use CatCode\MultiPrroConnector\Core\Logger;
use CatCode\MultiPrroConnector\Core\Phone;
use CatCode\MultiPrroConnector\Core\Settings as MpcSettings;

defined( 'ABSPATH' ) || exit;

class Bridge {

	/**
	 * Побудувати нормалізований чек (та сама структура, що у
	 * Fiscalizer::build_receipt) із замовлення KeyCRM.
	 *
	 * @param array $order Замовлення з GET /order (include=buyer,products,payments,shipping).
	 *
	 * @return array|null null якщо чек порожній / нульовий.
	 */
	public static function build_receipt( array $order ): ?array {
		$order_id    = (int) ( isset( $order['id'] ) ? $order['id'] : 0 );
		$grand_total = round( (float) ( isset( $order['grand_total'] ) ? $order['grand_total'] : 0 ), 2 );
		if ( $order_id <= 0 || $grand_total <= 0.009 ) {
			return null;
		}

		$products = isset( $order['products'] ) && is_array( $order['products'] ) ? $order['products'] : array();
		$items    = self::build_items( $products, $grand_total, $order_id );
		if ( ! $items ) {
			return null;
		}

		// Total = сума позицій — гарантує sum(items) == total (вимога адаптерів).
		$total = 0.0;
		foreach ( $items as $it ) {
			$total += (float) $it['price'] * (float) $it['qty'];
		}
		$total = round( $total, 2 );

		$buyer = isset( $order['buyer'] ) && is_array( $order['buyer'] ) ? $order['buyer'] : array();
		$phone = (string) ( isset( $buyer['phone'] ) ? $buyer['phone'] : '' );

		$payment_type = strtoupper( (string) Settings::get( 'payment_type', 'CARD' ) );
		if ( ! in_array( $payment_type, array( 'CASH', 'CARD' ), true ) ) {
			$payment_type = 'CARD';
		}

		return array(
			'order_id'     => $order_id,
			'order_number' => (string) $order_id,
			'items'        => $items,
			'total'        => $total,
			'currency'     => 'UAH',
			'payment_type' => $payment_type,
			'header'       => sprintf( 'Замовлення KeyCRM #%d', $order_id ),
			'footer'       => '',
			'customer'     => array(
				'name'  => trim( (string) ( isset( $buyer['full_name'] ) ? $buyer['full_name'] : '' ) ),
				'email' => (string) ( isset( $buyer['email'] ) ? $buyer['email'] : '' ),
				'phone' => class_exists( Phone::class ) ? Phone::normalize( $phone ) : $phone,
			),
		);
	}

	/**
	 * Пробити чек продажу через ланцюжок провайдерів MPC (основний → резервний),
	 * записуючи чек і спроби у спільні таблиці MPC.
	 *
	 * @return array ['ok'=>bool,'already'=>bool,'receipt_id'=>int,'fiscal_id'=>string,'pdf_url'=>string,'html_url'=>string,'error'=>string]
	 */
	public static function fiscalize( array $receipt ): array {
		$order_id = (int) $receipt['order_id'];
		$type     = 'sale';
		$chain    = self::provider_chain();

		if ( ! $chain ) {
			return self::failure( 0, 'У Multi PRRO Connector не вибрано основний ПРРО-сервіс (Каса → Налаштування).' );
		}

		$last_error = '';
		$receipt_id = 0;

		foreach ( $chain as $slug ) {
			$provider = Registry::get( $slug );
			if ( ! $provider ) {
				continue;
			}

			// Власний хеш з маркером джерела — щоб KeyCRM-замовлення ніколи не
			// колізувало з WooCommerce-замовленням з тим самим числовим ID.
			$hash = sha1( 'keycrm|' . $order_id . '|' . $type . '|' . number_format( (float) $receipt['total'], 2, '.', '' ) . '|' . $slug );

			$existing = Logger::find_receipt_by_hash( $hash );
			if ( $existing && 'completed' === $existing['status'] ) {
				return array(
					'ok'         => true,
					'already'    => true,
					'receipt_id' => (int) $existing['id'],
					'fiscal_id'  => (string) $existing['fiscal_id'],
					'pdf_url'    => (string) $existing['pdf_url'],
					'html_url'   => (string) $existing['html_url'],
					'error'      => '',
				);
			}

			$receipt_id = Logger::insert_receipt(
				array(
					'order_id'     => $order_id,
					'provider'     => $slug,
					'receipt_type' => $type,
					'total_amount' => $receipt['total'],
					'status'       => 'pending',
					'payload_hash' => $hash,
				)
			);

			$result = self::try_send( $provider, $receipt );
			Logger::log_attempt(
				$order_id,
				$slug,
				1,
				(int) ( isset( $result['http'] ) ? $result['http'] : 0 ),
				mb_substr( (string) ( isset( $result['raw'] ) ? $result['raw'] : '' ), 0, 1800 ),
				! empty( $result['ok'] )
			);

			if ( ! empty( $result['ok'] ) ) {
				Logger::update_receipt(
					$receipt_id,
					array(
						'fiscal_id'  => $result['fiscal_id'],
						'tax_number' => $result['tax_number'],
						'pdf_url'    => $result['pdf_url'],
						'html_url'   => $result['html_url'],
						'status'     => 'completed',
					)
				);
				return array(
					'ok'         => true,
					'already'    => false,
					'receipt_id' => $receipt_id,
					'fiscal_id'  => (string) $result['fiscal_id'],
					'pdf_url'    => (string) $result['pdf_url'],
					'html_url'   => (string) $result['html_url'],
					'error'      => '',
				);
			}

			$last_error = (string) ( isset( $result['error'] ) ? $result['error'] : 'unknown' );
			Logger::update_receipt(
				$receipt_id,
				array(
					'status'     => 'failed',
					'error_text' => $last_error,
				)
			);
		}

		return self::failure( $receipt_id, '' !== $last_error ? $last_error : 'Жоден ПРРО-сервіс недоступний' );
	}

	/**
	 * Send + автовідкриття зміни при чеку (та сама логіка, що у Fiscalizer::try_send).
	 *
	 * @param object $provider ProviderInterface instance.
	 */
	private static function try_send( $provider, array $receipt ): array {
		$result = $provider->send_sale( $receipt );

		if ( empty( $result['ok'] ) && self::looks_like_closed_shift( $result ) && method_exists( $provider, 'open_shift' ) ) {
			$cfg = MpcSettings::all();
			if ( 'yes' === ( isset( $cfg['auto_open_on_sale'] ) ? $cfg['auto_open_on_sale'] : 'yes' ) ) {
				$open = $provider->open_shift();
				if ( ! empty( $open['ok'] ) ) {
					$result = $provider->send_sale( $receipt );
				}
			}
		}

		return $result;
	}

	private static function looks_like_closed_shift( array $result ): bool {
		$err  = mb_strtolower( (string) ( isset( $result['error'] ) ? $result['error'] : '' ) );
		$http = (int) ( isset( $result['http'] ) ? $result['http'] : 0 );
		if ( 401 === $http ) {
			return true;
		}
		foreach ( array( 'unauthorized', 'змін', 'shift', 'не відкрит', 'закрит' ) as $needle ) {
			if ( false !== mb_strpos( $err, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ланцюжок провайдерів успадковується з налаштувань MPC (основний → резервний).
	 */
	private static function provider_chain(): array {
		$cfg      = MpcSettings::all();
		$primary  = (string) ( isset( $cfg['primary_provider'] ) ? $cfg['primary_provider'] : '' );
		$fallback = (string) ( isset( $cfg['fallback_provider'] ) ? $cfg['fallback_provider'] : '' );
		$out      = array();
		if ( '' !== $primary ) {
			$out[] = $primary;
		}
		if ( '' !== $fallback && $fallback !== $primary ) {
			$out[] = $fallback;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Позиції чека з products KeyCRM. Ціна — з урахуванням знижок
	 * (price*qty − total_discount). Різниця з grand_total: додатна → рядок
	 * «Доставка», відʼємна (знижка на замовлення) → пропорційний перерахунок.
	 * Копійчаний залишок від округлення юніт-ціни вирішується розбиттям рядка
	 * (qty−1 за базовою ціною + 1 шт зі скоригованою) — щоб Σ price*qty
	 * точно збігалася із сумою чека (вимога Вчасно: Σ rows.cost == sum).
	 */
	private static function build_items( array $products, float $grand_total, int $order_id ): array {
		$lines = array();
		foreach ( $products as $p ) {
			$qty = round( (float) ( isset( $p['quantity'] ) ? $p['quantity'] : 0 ), 3 );
			if ( $qty <= 0 ) {
				continue;
			}
			$price      = (float) ( isset( $p['price'] ) ? $p['price'] : 0 );
			$discount   = (float) ( isset( $p['total_discount'] ) ? $p['total_discount'] : 0 );
			$line_total = round( $price * $qty - $discount, 2 );
			if ( $line_total <= 0.0001 ) {
				continue;
			}
			$lines[] = array(
				'sku'        => (string) ( isset( $p['sku'] ) ? $p['sku'] : '' ),
				'name'       => '' !== (string) ( isset( $p['name'] ) ? $p['name'] : '' ) ? (string) $p['name'] : 'Товар',
				'qty'        => $qty,
				'line_total' => $line_total,
			);
		}

		if ( ! $lines ) {
			// Немає позицій у CRM — пробиваємо одним рядком на повну суму.
			$lines[] = array(
				'sku'        => '',
				'name'       => sprintf( 'Замовлення KeyCRM #%d', $order_id ),
				'qty'        => 1.0,
				'line_total' => $grand_total,
			);
		}

		$sum  = 0.0;
		foreach ( $lines as $l ) {
			$sum += $l['line_total'];
		}
		$sum  = round( $sum, 2 );
		$diff = round( $grand_total - $sum, 2 );

		if ( $diff > 0.009 ) {
			// Доставка / послуги, не розписані у products.
			$lines[] = array(
				'sku'        => 'SHIP',
				'name'       => 'Доставка',
				'qty'        => 1.0,
				'line_total' => $diff,
			);
		} elseif ( $diff < -0.009 && $sum > 0 ) {
			// Знижка на все замовлення — розкидаємо пропорційно, залишок на останній рядок.
			$factor = $grand_total / $sum;
			$acc    = 0.0;
			$last   = count( $lines ) - 1;
			foreach ( $lines as $i => $l ) {
				if ( $i < $last ) {
					$lt                        = round( $l['line_total'] * $factor, 2 );
					$lines[ $i ]['line_total'] = $lt;
					$acc                      += $lt;
				}
			}
			$lines[ $last ]['line_total'] = max( 0.0, round( $grand_total - $acc, 2 ) );
		}

		// line_total → юніт-ціна; копійчаний залишок закриваємо розбиттям рядка.
		$items = array();
		foreach ( $lines as $l ) {
			$qty  = (float) $l['qty'];
			$unit = round( $l['line_total'] / $qty, 2 );
			$residual = round( $l['line_total'] - $unit * $qty, 2 );

			if ( abs( $residual ) >= 0.01 && $qty > 1 && floor( $qty ) === $qty ) {
				$items[] = array(
					'sku'   => $l['sku'],
					'name'  => $l['name'],
					'qty'   => $qty - 1,
					'price' => $unit,
				);
				$items[] = array(
					'sku'   => $l['sku'],
					'name'  => $l['name'],
					'qty'   => 1.0,
					'price' => round( $unit + $residual, 2 ),
				);
			} else {
				$items[] = array(
					'sku'   => $l['sku'],
					'name'  => $l['name'],
					'qty'   => $qty,
					'price' => $unit,
				);
			}
		}

		return $items;
	}

	private static function failure( int $receipt_id, string $error ): array {
		return array(
			'ok'         => false,
			'already'    => false,
			'receipt_id' => $receipt_id,
			'fiscal_id'  => '',
			'pdf_url'    => '',
			'html_url'   => '',
			'error'      => $error,
		);
	}
}
