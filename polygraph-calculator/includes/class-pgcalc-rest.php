<?php
/**
 * REST API плагина: диалог, расчёт, сохранение, история, экспорт.
 * Все вычисления — на сервере (PHP), клиент получает только цифры.
 *
 * @package polygraph-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PGCALC_REST {

	const NS = 'polygraph-calculator/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( self::NS, '/dialog', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_dialog' ),
			'permission_callback' => array( __CLASS__, 'perm_any' ),
		) );

		register_rest_route( self::NS, '/calculate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_calculate' ),
			'permission_callback' => array( __CLASS__, 'perm_any' ),
		) );

		register_rest_route( self::NS, '/save', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_save' ),
			'permission_callback' => array( __CLASS__, 'perm_any' ),
		) );

		register_rest_route( self::NS, '/history', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_history' ),
			'permission_callback' => array( __CLASS__, 'perm_admin' ),
		) );

		register_rest_route( self::NS, '/export-csv', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_export_csv' ),
			'permission_callback' => array( __CLASS__, 'perm_admin' ),
		) );

		register_rest_route( self::NS, '/ai-ping', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_ai_ping' ),
			'permission_callback' => array( __CLASS__, 'perm_admin' ),
		) );
	}

	public static function perm_any() {
		return true; // Диалог и расчёт доступны гостям (публичный калькулятор).
	}

	public static function perm_admin() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		// Запасной путь, если REST-нонc не прошёл из-за куки-проблем хостинга:
		// принимаем запрос с корректным отдельным nonce-полем в теле/параметрах.
		$nonce = '';
		foreach ( array( 'pgc_admin_nonce', 'nonce' ) as $key ) {
			$val = $GLOBALS['_REQUEST'][ $key ] ?? null;
			if ( null === $val && isset( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$val = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
			if ( is_string( $val ) && '' !== $val ) {
				$nonce = $val;
				break;
			}
		}
		if ( '' !== $nonce && wp_verify_nonce( $nonce, 'pgcalc_admin_rest' ) && current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error(
			'pgcalc_forbidden',
			__( 'Недостаточно прав. Если вы видите эту ошибку будучи администратором — обновите страницу админки (истё cookie-сессия) и повторите действие.', 'polygraph-calculator' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * POST /dialog  { message, context:{params:[], history:[]} }
	 * Возвращает { step, reply, params, result? }.
	 */
	public static function rest_dialog( WP_REST_Request $req ) {
		$message = sanitize_textarea_field( (string) $req->get_param( 'message' ) );
		if ( '' === trim( $message ) ) {
			return new WP_Error( 'pgcalc_empty', __( 'Пустое сообщение.', 'polygraph-calculator' ), array( 'status' => 400 ) );
		}

		$ctx     = (array) $req->get_param( 'context' );
		$params  = isset( $ctx['params'] ) && is_array( $ctx['params'] ) ? wp_parse_args( $ctx['params'], PGCALC_DIALOG::initial_params() ) : PGCALC_DIALOG::initial_params();
		$history = isset( $ctx['history'] ) && is_array( $ctx['history'] ) ? array_slice( $ctx['history'], -20 ) : array();

		// Ограничиваем размер истории для ИИ-режима.
		$sanitized_history = array();
		foreach ( $history as $h ) {
			if ( isset( $h['role'], $h['content'] ) && in_array( $h['role'], array( 'user', 'assistant' ), true ) ) {
				$sanitized_history[] = array( 'role' => $h['role'], 'content' => substr( sanitize_textarea_field( $h['content'] ), 0, 4000 ) );
			}
		}

		if ( PGCALC_AI_CHAT::is_enabled() ) {
			$reply = self::dialog_via_ai( $sanitized_history, $message );
			if ( is_wp_error( $reply ) ) {
				return $reply;
			}
			$parsed = PGCALC_AI_CHAT::parse_params( $reply['text'] );
			if ( $parsed ) {
				$res = PGCALC_ENGINE::calculate( $parsed );
				return rest_ensure_response( array(
					'step'    => 'complete',
					'reply'   => '',
					'params'  => $parsed,
					'result'  => $res['result'],
					'error'   => $res['error'],
				) );
			}
			return rest_ensure_response( array(
				'step'   => 'collecting',
				'reply'  => $reply['text'],
				'params' => $params,
			) );
		}

		// Режим парсера.
		$state = PGCALC_DIALOG::process( $params, $message );

		if ( 'complete' === $state['step'] ) {
			$res = PGCALC_ENGINE::calculate( $state['params'] );
			return rest_ensure_response( array(
				'step'   => 'complete',
				'reply'  => '',
				'params' => $state['params'],
				'result' => $res['result'],
				'error'  => $res['error'],
			) );
		}

		return rest_ensure_response( array(
			'step'   => $state['step'],
			'reply'  => $state['question'],
			'params' => $state['params'],
		) );
	}

	private static function dialog_via_ai( $history, $message ) {
		$messages = array_merge( $history, array( array( 'role' => 'user', 'content' => $message ) ) );
		$text     = PGCALC_AI_CHAT::chat( $messages );
		if ( is_wp_error( $text ) ) {
			return new WP_Error( 'pgcalc_ai', sprintf(
				/* translators: %s error */
				__( '⚠️ Ошибка ИИ: %s. Проверьте настройки ИИ в плагине или переключитесь на режим парсера.', 'polygraph-calculator' ),
				$text->get_error_message()
			), array( 'status' => 502 ) );
		}
		return array( 'text' => $text );
	}

	/**
	 * POST /calculate {params} — прямой расчёт по параметрам (форма/виджет).
	 */
	public static function rest_calculate( WP_REST_Request $req ) {
		$params = self::sanitize_params( (array) $req->get_param( 'params' ) );
		$res    = PGCALC_ENGINE::calculate( $params );
		return rest_ensure_response( array(
			'result' => $res['result'],
			'error'  => $res['error'],
			'params' => $params,
		) );
	}

	public static function sanitize_params( $p ) {
		$size = isset( $p['size'] ) && is_array( $p['size'] ) ? $p['size'] : array();
		return array(
			'type'          => isset( $p['type'] ) && in_array( $p['type'], array( 'flyer', 'booklet' ), true ) ? $p['type'] : null,
			'size'          => array(
				'width'  => isset( $size['width'] ) ? absint( $size['width'] ) : ( isset( $p['width'] ) ? absint( $p['width'] ) : 0 ),
				'height' => isset( $size['height'] ) ? absint( $size['height'] ) : ( isset( $p['height'] ) ? absint( $p['height'] ) : 0 ),
			),
			'sizeName'      => isset( $p['sizeName'] ) ? sanitize_text_field( $p['sizeName'] ) : null,
			'quantity'      => isset( $p['quantity'] ) ? absint( $p['quantity'] ) : 0,
			'materialId'    => isset( $p['materialId'] ) ? sanitize_title_with_dashes( $p['materialId'] ) : null,
			'printing'      => isset( $p['printing'] ) && in_array( $p['printing'], array( '4+0', '4+4' ), true ) ? $p['printing'] : null,
			'creasing'      => ! empty( $p['creasing'] ),
			'creasingCount' => isset( $p['creasingCount'] ) ? absint( $p['creasingCount'] ) : 0,
		);
	}

	/**
	 * POST /save {params, name, phone, notify} — сохранить расчёт в историю (+письмо).
	 */
	public static function rest_save( WP_REST_Request $req ) {
		$params = self::sanitize_params( (array) $req->get_param( 'params' ) );
		$res    = PGCALC_ENGINE::calculate( $params );
		if ( ! $res['result'] ) {
			return new WP_Error( 'pgcalc_no_calc', $res['error']['message'], array( 'status' => 400 ) );
		}

		$name  = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$phone = sanitize_text_field( (string) $req->get_param( 'phone' ) );

		$id = PGCALC_DB::add_history( $params, $res['result'], $name, $phone );

		// Уведомление менеджеру (для заявок с сайта).
		if ( $req->get_param( 'notify' ) ) {
			$email = get_option( 'pgcalc_notify_email', get_option( 'admin_email' ) );
			if ( is_email( $email ) ) {
				$typeName = 'flyer' === $params['type'] ? __( 'Листовка', 'polygraph-calculator' ) : __( 'Буклет', 'polygraph-calculator' );
				$body = sprintf(
					/* translators: 1..6 */
					__( "Клиент: %1\$s %2\$s\nЗаказ: %3\$s %4\$s, %5\$d шт, печать %6\$s\nИтого: %7\$s", 'polygraph-calculator' ),
					$name, $phone, $typeName, $params['sizeName'] ?: ( $params['size']['width'] . '×' . $params['size']['height'] ),
					$params['quantity'], $params['printing'],
					PGCALC_ENGINE::format_price( $res['result']['total'] )
				);
				 /* translators: %s site name */
				wp_mail( $email, sprintf( __( '[%s] Новый расчёт заказа', 'polygraph-calculator' ), get_bloginfo( 'name' ) ), $body );
			}
		}

		return rest_ensure_response( array( 'success' => true, 'id' => $id ) );
	}

	public static function rest_history( WP_REST_Request $req ) {
		$rows = PGCALC_DB::get_history( 200 );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'          => (int) $r->id,
				'date'        => $r->created_at,
				'clientName'  => $r->client_name,
				'clientPhone' => $r->client_phone,
				'params'      => json_decode( $r->params, true ),
				'result'      => json_decode( $r->result, true ),
			);
		}
		return rest_ensure_response( $out );
	}

	/** CSV-экспорт истории (для страницы истории и кнопки Excel). */
	public static function rest_export_csv( WP_REST_Request $req ) {
		$rows = PGCALC_DB::get_history( 500 );
		$lines = array( array( 'Дата', 'Клиент', 'Телефон', 'Тип', 'Размер', 'Кол-во', 'Материал', 'Печать', 'Биговка', 'k', 'N листов', 'Материал ₽', 'Печать ₽', 'Резка ₽', 'Биговка ₽', 'Итого ₽' ) );
		foreach ( $rows as $r ) {
			$p   = json_decode( $r->params, true );
			$res = json_decode( $r->result, true );
			if ( ! is_array( $p ) || ! is_array( $res ) ) {
				continue;
			}
			$lines[] = array(
				$r->created_at, $r->client_name, $r->client_phone,
				'flyer' === $p['type'] ? 'Листовка' : 'Буклет',
				$p['sizeName'] ? $p['sizeName'] : $p['size']['width'] . 'x' . $p['size']['height'],
				$p['quantity'], PGCALC_ENGINE::material_display_name( $p['materialId'] ), $p['printing'],
				! empty( $p['creasing'] ) ? ( (int) $p['creasingCount'] ?: 2 ) : '-',
				$res['k'], $res['N'],
				$res['materialCost'], $res['printCost'], $res['cutCost'], $res['creasingCost'], $res['total'],
			);
		}
		$fp = fopen( 'php://temp', 'r+' );
		fwrite( $fp, "\xEF\xBB\xBF" ); // BOM для Excel.
		foreach ( $lines as $l ) {
			fputcsv( $fp, $l, ';' );
		}
		rewind( $fp );
		$csv = stream_get_contents( $fp );
		fclose( $fp );

		$response = new WP_REST_Response( $csv, 200 );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="pgcalc-history.csv"' );
		return $response;
	}

	/** POST /ai-ping — проверка подключения к ИИ из настроек. */
	public static function rest_ai_ping() {
		if ( ! PGCALC_AI_CHAT::is_enabled() ) {
			// Даже в режиме парсера можно проверить ключ/URL, если они заполнены.
			if ( '' === trim( (string) get_option( 'pgcalc_ai_api_key', '' ) ) ) {
				return new WP_Error( 'pgcalc_ai_off', __( 'Не задан API ключ (Настройки ИИ).', 'polygraph-calculator' ), array( 'status' => 400 ) );
			}
		}
		$text = PGCALC_AI_CHAT::chat( array( array( 'role' => 'user', 'content' => 'Скажи одно слово: ок' ) ) );
		if ( is_wp_error( $text ) ) {
			return new WP_Error( 'pgcalc_ai_fail', $text->get_error_message(), array( 'status' => 502 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'answer' => mb_substr( $text, 0, 100 ) ) );
	}
}
