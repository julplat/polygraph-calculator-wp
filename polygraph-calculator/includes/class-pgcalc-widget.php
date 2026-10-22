<?php
/**
 * Виджет «Калькулятор полиграфа» для сайдбара.
 *
 * @package polygraph-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс виджета.
 */
class PGCalc_Widget extends WP_Widget {

	/**
	 * Конструктор.
	 */
	public function __construct() {
		parent::__construct(
			'pgcalc_widget',
			__( 'Polygraph: Калькулятор', 'polygraph-calculator' ),
			array( 'description' => __( 'Виджет калькулятора стоимости полиграфных услуг.', 'polygraph-calculator' ) )
		);
	}

	/**
	 * Вывод виджета на фронтенде.
	 *
	 * @param array $args Аргументы сайдбара.
	 * @param array $instance Настройки экземпляра виджета.
	 */
	public function widget( $args, $instance ) {
		echo wp_kses_post( $args['before_widget'] );

		if ( ! empty( $instance['title'] ) ) {
			echo wp_kses_post(
				str_replace(
					'%1$s',
					esc_html( $instance['title'] ),
					$args['before_title'] . '%1$s' . $args['after_title']
				)
			);
		}

		$shortcode = PGCalc_Shortcode::get_instance();
		echo do_shortcode( '[polygraph_calculator]' );

		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Форма настроек в админке.
	 *
	 * @param array $instance Текущие значения.
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : __( 'Калькулятор полиграфа', 'polygraph-calculator' );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Заголовок:', 'polygraph-calculator' ); ?>
			</label>
			<input class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	/**
	 * Сохранение настроек виджета.
	 *
	 * @param array $new_instance Новые значения.
	 * @param array $old_instance Старые значения.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		$instance          = array();
		$instance['title'] = sanitize_text_field( $new_instance['title'] ?? '' );
		return $instance;
	}
}
