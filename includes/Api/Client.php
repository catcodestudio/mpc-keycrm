<?php
/**
 * KeyCRM Open API client (https://openapi.keycrm.app/v1, Bearer auth).
 *
 * Built-in guards:
 *   • soft rate limit — не більше ~55 запитів за ковзну хвилину (ліміт KeyCRM 60/хв);
 *   • 429 → повтор з backoff (Retry-After або експоненційна пауза), до 4 спроб.
 *
 * @package MpcKeycrm
 */

namespace CatCode\MpcKeycrm\Api;

defined( 'ABSPATH' ) || exit;

class Client {

	private const BASE_URL       = 'https://openapi.keycrm.app/v1';
	private const RATE_LIMIT     = 55; // запас від офіційних 60 req/min.
	private const RATE_WINDOW    = 60; // секунд.
	private const MAX_429_RETRY  = 3;

	/** @var string */
	private $api_key;

	/** @var float[] Unix-мітки виконаних запитів (ковзне вікно). */
	private $stamps = array();

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Сторінка списку замовлень, оновлених у вікні [$from, $to].
	 *
	 * @param int    $page Номер сторінки (1..N).
	 * @param string $from 'Y-m-d H:i:s'.
	 * @param string $to   'Y-m-d H:i:s'.
	 */
	public function orders_page( int $page, string $from, string $to ): array {
		return $this->request(
			'GET',
			'/order',
			array(
				'limit'                   => 50,
				'page'                    => $page,
				'include'                 => 'buyer,products,payments,shipping',
				'filter[updated_between]' => $from . ',' . $to,
				'sort'                    => '-updated_at',
			)
		);
	}

	/**
	 * Довідник статусів замовлень. Повертає плаский масив
	 * рядків {id,name,alias,group_id,is_closing_order}.
	 */
	public function statuses(): array {
		$out  = array();
		$page = 1;
		do {
			$res = $this->request(
				'GET',
				'/order/status',
				array(
					'limit' => 50,
					'page'  => $page,
				)
			);
			if ( ! $res['ok'] ) {
				return $res;
			}
			$json = is_array( $res['json'] ) ? $res['json'] : array();
			// Може прийти як пагінований обʼєкт {data:[...]}, так і плаский масив.
			$rows      = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : ( isset( $json[0] ) ? $json : array() );
			$out       = array_merge( $out, $rows );
			$last_page = (int) ( isset( $json['last_page'] ) ? $json['last_page'] : 1 );
			++$page;
		} while ( $page <= $last_page && $page <= 10 );

		return array(
			'ok'   => true,
			'http' => 200,
			'json' => $out,
			'raw'  => '',
			'error' => '',
		);
	}

	public function get_order( int $order_id ): array {
		return $this->request( 'GET', '/order/' . $order_id );
	}

	/**
	 * Оновити коментар менеджера у замовленні (PUT /order/{id}).
	 */
	public function set_manager_comment( int $order_id, string $comment ): array {
		return $this->request(
			'PUT',
			'/order/' . $order_id,
			array(),
			array( 'manager_comment' => $comment )
		);
	}

	/**
	 * @param string     $method HTTP method.
	 * @param string     $path   API path, starts with '/'.
	 * @param array      $query  Query args.
	 * @param array|null $body   JSON body.
	 *
	 * @return array ['ok'=>bool,'http'=>int,'json'=>array|null,'raw'=>string,'error'=>string]
	 */
	public function request( string $method, string $path, array $query = array(), $body = null ): array {
		if ( '' === $this->api_key ) {
			return array(
				'ok'    => false,
				'http'  => 0,
				'json'  => null,
				'raw'   => '',
				'error' => 'Не заповнено API-ключ KeyCRM',
			);
		}

		$url = self::BASE_URL . $path;
		if ( $query ) {
			$url .= '?' . http_build_query( $query );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$last = array(
			'ok'    => false,
			'http'  => 0,
			'json'  => null,
			'raw'   => '',
			'error' => 'unknown',
		);

		for ( $attempt = 0; $attempt <= self::MAX_429_RETRY; $attempt++ ) {
			$this->throttle();
			$resp           = wp_remote_request( $url, $args );
			$this->stamps[] = microtime( true );

			if ( is_wp_error( $resp ) ) {
				return array(
					'ok'    => false,
					'http'  => 0,
					'json'  => null,
					'raw'   => '',
					'error' => $resp->get_error_message(),
				);
			}

			$http = (int) wp_remote_retrieve_response_code( $resp );
			$raw  = (string) wp_remote_retrieve_body( $resp );
			$json = json_decode( $raw, true );

			if ( 429 === $http ) {
				$retry_after = (int) wp_remote_retrieve_header( $resp, 'retry-after' );
				$pause       = $retry_after > 0 ? min( $retry_after, 30 ) : min( 2 * ( 2 ** $attempt ), 20 );
				$last        = array(
					'ok'    => false,
					'http'  => 429,
					'json'  => is_array( $json ) ? $json : null,
					'raw'   => $raw,
					'error' => 'KeyCRM 429 Too Many Requests',
				);
				if ( $attempt < self::MAX_429_RETRY ) {
					sleep( $pause );
					continue;
				}
				return $last;
			}

			$ok = $http >= 200 && $http < 300;
			return array(
				'ok'    => $ok,
				'http'  => $http,
				'json'  => is_array( $json ) ? $json : null,
				'raw'   => $raw,
				'error' => $ok ? '' : $this->extract_error( $http, $json, $raw ),
			);
		}

		return $last;
	}

	/**
	 * Ковзне вікно: якщо за останні 60 с уже RATE_LIMIT запитів — чекаємо
	 * поки найстаріший вийде з вікна.
	 */
	private function throttle(): void {
		$now          = microtime( true );
		$this->stamps = array_values(
			array_filter(
				$this->stamps,
				static function ( $t ) use ( $now ) {
					return ( $now - $t ) < self::RATE_WINDOW;
				}
			)
		);
		if ( count( $this->stamps ) >= self::RATE_LIMIT ) {
			$oldest = $this->stamps[0];
			$wait   = (int) ceil( self::RATE_WINDOW - ( $now - $oldest ) ) + 1;
			if ( $wait > 0 ) {
				sleep( min( $wait, self::RATE_WINDOW ) );
			}
		}
	}

	private function extract_error( int $http, $json, string $raw ): string {
		if ( is_array( $json ) ) {
			$msg = '';
			if ( isset( $json['message'] ) ) {
				$msg = (string) $json['message'];
			} elseif ( isset( $json['error'] ) ) {
				$msg = is_string( $json['error'] ) ? $json['error'] : wp_json_encode( $json['error'] );
			}
			if ( '' !== $msg ) {
				return 'HTTP ' . $http . ': ' . $msg;
			}
		}
		$raw = trim( $raw );
		if ( '' !== $raw && strlen( $raw ) < 250 ) {
			return 'HTTP ' . $http . ': ' . $raw;
		}
		return 'HTTP ' . $http;
	}
}
