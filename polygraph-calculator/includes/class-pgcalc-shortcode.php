<?php
/**
 * Шорткод [polygraph_calculator].
 *
 * @package polygraph-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс шорткода калькулятора.
 */
class PGCalc_Shortcode {

	/**
	 * Singleton.
	 *
	 * @var PGCalc_Shortcode|null
	 */
	private static $instance = null;

	/**
	 * Получение singleton-экземпляра.
	 *
	 * @return PGCalc_Shortcode
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Регистрация шорткода.
	 */
	public function init() {
		add_shortcode( 'polygraph_calculator', array( $this, 'render' ) );
	}

	/**
	 * Отрисовка калькулятора.
	 *
	 * Атрибуты:
	 *  - title: заголовок блока.
	 *  - base_price / per_issue_price / max_issues / currency: переопределение настроек.
	 *
	 * @param array|string $atts Атрибуты шорткода.
	 * @return string HTML.
	 */
	public function render( $atts ) {
		$atts     = shortcode_atts(
			array(
				'title'           => __( 'Калькулятор стоимости полиграфной проверки', 'polygraph-calculator' ),
				'base_price'      => '',
				'per_issue_price' => '',
				'max_issues'      => '',
				'currency'        => '',
			),
			$atts,
			'polygraph_calculator'
		);
		$settings = PGCalc_Settings::get_settings();

		// Переопределение параметров атрибутами шорткода.
		if ( '' !== $atts['base_price'] ) {
			$settings[ 'ai' === $settings['calc_mode'] ? 'ai_price' : 'base_price' ] = absint( $atts['base_price'] );
		}
		if ( '' !== $atts['per_issue_price'] ) {
			$settings['per_issue_price'] = absint( $atts['per_issue_price'] );
		}
		if ( '' !== $atts['max_issues'] ) {
			$settings['max_issues'] = min( 100, max( 1, absint( $atts['max_issues'] ) ) );
		}
		if ( '' !== $atts['currency'] ) {
			$settings['currency'] = strtoupper( sanitize_text_field( $atts['currency'] ) );
		}

		wp_enqueue_style( 'pgcalc-style' );
		wp_enqueue_script( 'pgcalc-script' );

		ob_start();
		if ( 'ai' === $settings['calc_mode'] ) {
			include PGCALC_PLUGIN_DIR . 'templates/calculator-ai.php';
		} else {
			include PGCALC_PLUGIN_DIR . 'templates/calculator.php';
		}
		return ob_get_clean();
	}
}
