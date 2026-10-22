<?php
/**
 * Интеграция с ИИ-провайдером: расчёт стоимости по базе знаний.
 *
 * API-ключ хранится только в настройках плагина (wp_options) и никогда
 * не передаётся в браузер посетителя. Запрос к провайдеру выполняется
 * на сервере (wp_remote_post) с кэшированием результата.
 *
 * @package polygraph-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс AI-расчётчика.
 */
class PGCalc_AI {

	/**
	 * Singleton.
	 *
	 * @var PGCalc_AI|null
	 */
	private static $instance = null;

	/**
	 * Получение singleton-экземпляра.
	 *
	 * @return PGCalc_AI
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Настроен ли ИИ (провайдер + ключ).
	 *
	 * @param array $settings Настройки плагина.
	 * @return bool
	 */
	public static function is_enabled( $settings ) {
		return ! empty( $settings['ai_provider'] )
			&& 'none' !== $settings['ai_provider']
			&& ! empty( $settings['ai_api_key'] );
	}

	/**
	 * Оценка стоимости через ИИ. Возвращает int|WP_ERROR цену либо ошибку.
	 *
	 * @param int   $issues    Количество тем.
	 * @param array $settings  Настройки плагина.
	 * @return int|WP_Error
	 */
	public function estimate( $issues, $settings ) {
		$issues = max( 1, absint( $issues ) );

		if ( ! self::is_enabled( $settings ) ) {
			return new WP_Error( 'pgcalc_ai_off', 'AI provider not configured.' );
		}

		// Кэш: одинаковые запросы в течение часа не бьём в API повторно.
		$cache_key = 'pgcalc_ai_' . md5( $settings['ai_provider'] . '|' . $settings['ai_model'] . '|' . $settings['ai_pricing_rules'] . '|' . $issues );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && absint( $cached ) > 0 ) {
			return absint( $cached );
		}

		$system = "Ты — калькулятор стоимости услуг полиграфа. Рассчитай итоговую стоимость проверки по базе знаний (прайсу). Верни ТОЛЬКО одно целое число — итоговую сумму в валюте прайса. Без текста и пояснений.\n\nБАЗА ЗНАНИЙ (ПРАЙС И ПРАВИЛА):\n"
			 . ( ! empty( $settings['ai_pricing_rules'] ) ? wp_strip_all_tags( $settings['ai_pricing_rules'] ) : 'Цена за тему: ' . $settings['ai_price'] . ' ' . $settings['currency'] . '.')
			 . "\n\nМаксимум тем: {$settings['max_issues']}.";

		$user = "Количество тем для проверки: {$issues}. Итоговая стоимость?";

		$result = $this->chat( $settings, $system, $user );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Извлекаем первое число из ответа.
		$digits = preg_replace( '/[^\d]/', '', $result );
		$price  = $digits ? (int) $digits : 0;

		// Защита от абсурдных ответов модели.
		$max_reasonable = max( 1, (int) $settings['ai_price'] ) * $issues * 10;
		if ( $price <= 0 || ( $max_reasonable > 0 && $price > $max_reasonable ) ) {
			return new WP_Error( 'pgcalc_ai_bad_answer', 'AI returned an unreasonable price.', array( 'raw' => $result ) );
		}

		set_transient( $cache_key, $price, HOUR_IN_SECONDS );

		return $price;
	}

	/**
	 * Низкоуровневый вызов chat completion у выбранного провайдера.
	 *
	 * @param array  $settings Настройки.
	 * @param string $system   Системный промпт.
	 * @param string $user     Пользовательский промпт.
	 * @return string|WP_Error Текст ответа модели.
	 */
	private function chat( $settings, $system, $user ) {
		$providers = PGCalc_Settings::providers();
		$pid       = $settings['ai_provider'];
		if ( ! isset( $providers[ $pid ] ) ) {
			return new WP_Error( 'pgcalc_ai_provider', 'Unknown AI provider.' );
		}

		$endpoint = ! empty( $settings['ai_endpoint'] ) ? $settings['ai_endpoint'] : $providers[ $pid ]['endpoint'];
		$model    = ! empty( $settings['ai_model'] ) ? $settings['ai_model'] : $providers[ $pid ]['default_model'];

		if ( empty( $endpoint ) ) {
			return new WP_Error( 'pgcalc_ai_endpoint', 'AI endpoint is not set.' );
		}

		if ( 'anthropic' === $pid ) {
			$body = array(
				'model'      => $model,
				'max_tokens' => 64,
				'system'     => $system,
				'messages'   => array( array( 'role' => 'user', 'content' => $user ) ),
			);
			$headers = array(
				'x-api-key'         => $settings['ai_api_key'],
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			);
		} else {
			// OpenAI-совместимые API: openai, deepseek, custom.
			$body = array(
				'model'       => $model,
				'max_tokens'  => 64,
				'temperature' => 0,
				'messages'    => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user', 'content' => $user ),
				),
			);
			$headers = array(
				'authorization' => 'Bearer ' . $settings['ai_api_key'],
				'content-type'  => 'application/json',
			);
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pgcalc_ai_http', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'pgcalc_ai_http_error', $msg );
		}

		if ( 'anthropic' === $pid ) {
			$text = isset( $json['content'][0]['text'] ) ? $json['content'][0]['text'] : '';
		} else {
			$text = isset( $json['choices'][0]['message']['content'] ) ? $json['choices'][0]['message']['content'] : '';
		}

		if ( '' === trim( $text ) ) {
			return new WP_Error( 'pgcalc_ai_empty', 'Empty AI response.' );
		}

		return $text;
	}
}
