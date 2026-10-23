<?php
/**
 * Шорткод [polygraph_calculator] — калькулятор с диалогом на сайте.
 * Настройки и диалоги живут в админке плагина (меню «Калькулятор Polygraph»),
 * здесь только виджет для посетителей.
 *
 * @package polygraph-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PGCALC_SHORTCODE {

	public static function init() {
		add_shortcode( 'polygraph_calculator', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array( 'title' => __( 'Расчёт стоимости печати', 'polygraph-calculator' ) ), $atts, 'polygraph_calculator' );

		wp_enqueue_style( 'pgcalc-frontend', PGCALC_PLUGIN_URL . 'assets/css/frontend.css', array(), PGCALC_VERSION );
		wp_enqueue_script( 'pgcalc-frontend', PGCALC_PLUGIN_URL . 'assets/js/frontend.js', array(), PGCALC_VERSION, true );
		wp_localize_script(
			'pgcalc-frontend',
			'PGCALC_FRONT',
			array(
				'restUrl'  => esc_url_raw( rest_url( PGCALC_REST::NS . '/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'mode'     => get_option( 'pgcalc_dialog_mode', 'parser' ),
				'showDetail' => (bool) get_option( 'pgcalc_show_breakdown', false ),
				'materials' => self::materials_public(),
				'sizes'     => self::sizes_public(),
				'i18n'      => array(
					'initial'    => PGCALC_DIALOG::initial_question(),
					'thinking'   => __( '…', 'polygraph-calculator' ),
					'namePh'     => __( 'Ваше имя', 'polygraph-calculator' ),
					'phonePh'    => __( 'Телефон', 'polygraph-calculator' ),
					'saved'      => __( 'Спасибо! Менеджер свяжется с вами в ближайшее время.', 'polygraph-calculator' ),
					'errSend'    => __( 'Ошибка соединения. Попробуйте ещё раз.', 'polygraph-calculator' ),
					'total'      => __( 'ИТОГО', 'polygraph-calculator' ),
					'forClient'  => __( 'Итого', 'polygraph-calculator' ),
					'formTitle'  => __( 'Стоимость ориентировочная. Оставьте телефон — менеджер подтвердит точную цену.', 'polygraph-calculator' ),
					'callNote'   => __( 'Перезвоним или напишем в течение рабочего дня', 'polygraph-calculator' ),
					'privacyText' => __( 'Отправляя сообщение, вы соглашаетесь с', 'polygraph-calculator' ),
					'privacyLink' => __( 'политикой обработки персональных данных.', 'polygraph-calculator' ),
					'privacyUrl'  => 'https://www.aeroplane-media.ru/licenses-detail/',
					'required'    => __( 'Укажите имя и телефон.', 'polygraph-calculator' ),
					'flyer'      => __( 'Листовка', 'polygraph-calculator' ),
					'booklet'    => __( 'Буклет', 'polygraph-calculator' ),
					'startHint'  => __( 'Начните диалог для расчёта', 'polygraph-calculator' ),
					'managerNote' => __( 'По этому варианту точный расчёт лучше доверить менеджеру — он быстро подберёт решение под задачу.', 'polygraph-calculator' ),
					'send'       => __( 'Отправить', 'polygraph-calculator' ),
					'inputPh'    => __( 'Опишите заказ…', 'polygraph-calculator' ),
				),
			)
		);

		ob_start();
		?>
		<div class="pgc-app" data-title="<?php echo esc_attr( $atts['title'] ); ?>">
			<div class="pgc-header">
				<h3 class="pgc-title"><?php echo esc_html( $atts['title'] ); ?></h3>
			</div>
			<div class="pgc-body">
				<div class="pgc-chat-col">
					<div class="pgc-chat" data-chat></div>
					<div class="pgc-input-row">
						<input type="text" data-input class="pgc-input" placeholder="<?php esc_attr_e( 'Например: листовки А6, 100 шт, бумага 120 г, печать 4+0', 'polygraph-calculator' ); ?>" />
						<button class="pgc-btn pgc-btn-primary" data-send><?php esc_html_e( '➤', 'polygraph-calculator' ); ?></button>
					</div>
				</div>
				<div class="pgc-result-col" data-result-col>
					<div class="pgc-result-empty"><span>📊</span><p><?php esc_html_e( 'Начните диалог для расчёта', 'polygraph-calculator' ); ?></p></div>
				</div>
			</div>
			<p class="pgc-privacy"><?php esc_html_e( 'Отправляя сообщение, вы соглашаетесь с', 'polygraph-calculator' ); ?> <a href="https://www.aeroplane-media.ru/licenses-detail/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'политикой обработки персональных данных.', 'polygraph-calculator' ); ?></a></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Публичные данные материалов (без цен — цены не отдаём клиенту). */
	private static function materials_public() {
		$out = array();
		foreach ( PGCALC_DB::get_materials() as $m ) {
			$out[] = array(
				'id'   => $m->id,
				'name' => PGCALC_ENGINE::material_display_name( $m->id ),
			);
		}
		return $out;
	}

	private static function sizes_public() {
		$out = array();
		foreach ( PGCALC_DB::get_sizes() as $s ) {
			$out[] = array( 'name' => $s->name, 'w' => (int) $s->w, 'h' => (int) $s->h );
		}
		return $out;
	}
}
