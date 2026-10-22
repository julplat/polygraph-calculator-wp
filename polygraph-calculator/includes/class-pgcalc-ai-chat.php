<?php
/**
 * ИИ-помощник диалога: чат через OpenAI-совместимый API (promptra/deepseek/openai/custom).
 * Системный промпт строится из базы знаний (материалы, размеры, тарифы) — как в aiChat.ts.
 * Ключ хранится только в БД WordPress, в браузер не попадает.
 *
 * @package polygraph-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PGCALC_AI_CHAT {

	public static function system_prompt() {
		$materials = PGCALC_DB::get_materials();
		$sizes     = PGCALC_DB::get_sizes();

		$matList = array();
		foreach ( $materials as $m ) {
			$matList[] = "   - {$m->id} ({$m->name})";
		}
		$sizeList = array();
		foreach ( $sizes as $s ) {
			$sizeList[] = "{$s->name} ({$s->w}×{$s->h} мм)";
		}

		return "Ты — помощник менеджера полиграфии. Твоя задача — собирать параметры заказа на печать листовок и буклетов.\n\n"
			. "ПРАВИЛА РАБОТЫ:\n"
			. "1. Собирай параметры по одному вопросу за раз, пока не получишь все необходимые данные\n"
			. "2. Если параметр уже назван — не переспрашивай\n"
			. "3. Когда все параметры собраны — верни JSON с параметрами для расчёта\n\n"
			. "НЕОБХОДИМЫЕ ПАРАМЕТРЫ:\n"
			. "1. Тип: листовка (flyer) или буклет (booklet)\n"
			. "2. Размер: ширина × высота в мм (для буклета — размер листа в развёрнутом виде). Стандартные: " . implode( ', ', $sizeList ) . "\n"
			. "3. Количество изделий\n"
			. "4. Материал из списка:\n" . implode( "\n", $matList ) . "\n"
			. "5. Печать: 4+0 (одна сторона) или 4+4 (две стороны)\n"
			. "6. Для буклета: нужна ли биговка и сколько сгибов (обычно 2 для евробуклета)\n\n"
			. "ФОРМАТ ОТВЕТА:\n"
			. "Когда все параметры собраны, верни ТОЛЬКО JSON без текста:\n"
			. "{\n  \"type\": \"flyer\" | \"booklet\",\n  \"size\": {\"width\": число, \"height\": число},\n  \"sizeName\": \"А6\" | \"А5\" | ... | null,\n  \"quantity\": число,\n  \"materialId\": \"...\",\n  \"printing\": \"4+0\" | \"4+4\",\n  \"creasing\": true | false,\n  \"creasingCount\": число (0 если листовка)\n}\n\n"
			. "Если чего-то не хватает — задай один уточняющий вопрос.\n\n"
			. "ВАЖНО:\n"
			. "- Не называй клиенту марки бумаги — только обозначение (например, \"бумага 120 г матовая\")\n"
			. "- Если изделие не листовка/буклет — скажи, что расчёт лучше доверить менеджеру\n"
			. "- Стоимость НЕ называй — её рассчитывает система. Отвечай кратко и по делу.";
	}

	/**
	 * Настроен ли ИИ-режим.
	 */
	public static function is_enabled() {
		return 'ai' === get_option( 'pgcalc_dialog_mode', 'parser' )
			&& '' !== trim( (string) get_option( 'pgcalc_ai_api_key', '' ) );
	}

	private static function normalize_url( $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			$url = 'https://api.promptra.ru/v1';
		}
		if ( false !== strpos( $url, '/chat/completions' ) ) {
			return $url;
		}
		$url = rtrim( $url, '/' );
		if ( substr( $url, -3 ) === '/v1' ) {
			return $url . '/chat/completions';
		}
		return $url . '/v1/chat/completions';
	}

	/**
	 * Отправить историю сообщений ИИ. Возвращает string|WP_Error.
	 *
	 * @param array $history массив {role,content} (без system).
	 */
	public static function chat( $history ) {
		$endpoint = self::normalize_url( get_option( 'pgcalc_ai_api_url', 'https://api.promptra.ru/v1/chat/completions' ) );
		$model    = get_option( 'pgcalc_ai_model', 'deepseek/deepseek-v4-pro' );
		$key      = get_option( 'pgcalc_ai_api_key', '' );

		$messages = array_merge(
			array( array( 'role' => 'system', 'content' => self::system_prompt() ) ),
			$history
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'authorization' => 'Bearer ' . $key,
					'content-type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'messages'    => $messages,
						'temperature' => 0.7,
						'max_tokens'  => 1000,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pgcalc_ai_http', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'pgcalc_ai_error', $msg );
		}

		$text = isset( $json['choices'][0]['message']['content'] ) ? $json['choices'][0]['message']['content'] : '';
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'pgcalc_ai_empty', __( 'Пустой ответ от ИИ.', 'polygraph-calculator' ) );
		}
		return $text;
	}

	/**
	 * Извлечь JSON параметров из ответа модели.
	 *
	 * @return array|null
	 */
	public static function parse_params( $text ) {
		if ( ! preg_match( '/\{[\s\S]*\}/', $text, $m ) ) {
			return null;
		}
		$data = json_decode( $m[0], true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( empty( $data['type'] ) || empty( $data['size'] ) || empty( $data['quantity'] ) || empty( $data['materialId'] ) || empty( $data['printing'] ) ) {
			return null;
		}
		if ( ! is_array( $data['size'] ) || ! isset( $data['size']['width'], $data['size']['height'] ) ) {
			return null;
		}
		return array(
			'type'          => in_array( $data['type'], array( 'flyer', 'booklet' ), true ) ? $data['type'] : 'flyer',
			'size'          => array(
				'width'  => (int) $data['size']['width'],
				'height' => (int) $data['size']['height'],
			),
			'sizeName'      => isset( $data['sizeName'] ) ? sanitize_text_field( $data['sizeName'] ) : null,
			'quantity'      => (int) $data['quantity'],
			'materialId'    => sanitize_title_with_dashes( $data['materialId'] ),
			'printing'      => in_array( $data['printing'], array( '4+0', '4+4' ), true ) ? $data['printing'] : '4+0',
			'creasing'      => ! empty( $data['creasing'] ),
			'creasingCount' => isset( $data['creasingCount'] ) ? (int) $data['creasingCount'] : 0,
		);
	}
}
