<?php
/**
 * Настройки плагина (админка → Polygraph Calculator).
 *
 * @package polygraph-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс настроек плагина.
 */
class PGCalc_Settings {

	const OPTION_KEY = 'pgcalc_settings';

	/**
	 * Singleton.
	 *
	 * @var PGCalc_Settings|null
	 */
	private static $instance = null;

	/**
	 * Значения по умолчанию.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Режим «только ИИ»: цена задаётся вручную, калькулятор показывает только итог.
			'calc_mode'          => 'ai',   // ai | formula
			'ai_price'           => 7000,   // Фиксированная цена за одну тему (режим ИИ).
			'base_price'         => 5000,   // Базовая стоимость проверки (1 чел., 1 тема) — режим формулы.
			'per_issue_price'    => 1500,   // Стоимость доп. темы — режим формулы.
			'max_issues'         => 20,     // Максимум тем в калькуляторе.
			'pre_post_multiplier' => 1.5,    // Множитель для пред-/постдиагностики (режим формулы).
			'currency'           => 'RUB',  // Валюта: RUB, USD, EUR, KZT, UAH.
			'notify_email'       => get_option( 'admin_email' ),
			'enable_rest'        => true,
		);
	}

	/**
	 * Получение singleton-экземпляра.
	 *
	 * @return PGCalc_Settings
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Инициализация: страница настроек в админке.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_options_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Текущие настройки с подменой значений по умолчанию.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Установка значений по умолчанию при активации.
	 */
	public static function maybe_set_defaults() {
		if ( false === get_option( self::OPTION_KEY ) ) {
			add_option( self::OPTION_KEY, self::defaults() );
		}
	}

	/**
	 * Меню настроек.
	 */
	public function add_options_page() {
		add_options_page(
			__( 'Polygraph Calculator', 'polygraph-calculator' ),
			__( 'Polygraph Calculator', 'polygraph-calculator' ),
			'manage_options',
			'pgcalc-settings',
			array( $this, 'render_options_page' )
		);
	}

	/**
	 * Регистрация настроек API WordPress.
	 */
	public function register_settings() {
		register_setting(
			'pgcalc_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'pgcalc_main',
			__( 'Параметры калькулятора', 'polygraph-calculator' ),
			'__return_false',
			'pgcalc-settings'
		);

		// Режим работы калькулятора (радио: только ИИ / формула).
		add_settings_field(
			'pgcalc_calc_mode',
			__( 'Режим калькулятора', 'polygraph-calculator' ),
			array( $this, 'render_mode_field' ),
			'pgcalc-settings',
			'pgcalc_main'
		);

		$fields = array(
			'ai_price'            => __( 'Цена за 1 тему (режим «только ИИ»)', 'polygraph-calculator' ),
			'base_price'          => __( 'Базовая стоимость проверки (формула)', 'polygraph-calculator' ),
			'per_issue_price'     => __( 'Стоимость дополнительной темы (формула)', 'polygraph-calculator' ),
			'max_issues'          => __( 'Максимальное количество тем', 'polygraph-calculator' ),
			'pre_post_multiplier' => __( 'Множитель пред-/постдиагностики (формула)', 'polygraph-calculator' ),
			'currency'            => __( 'Валюта', 'polygraph-calculator' ),
			'notify_email'        => __( 'E-mail для заявок', 'polygraph-calculator' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'pgcalc_' . $key,
				$label,
				array( $this, 'render_field' ),
				'pgcalc-settings',
				'pgcalc_main',
				array(
					'key'   => $key,
					'label' => $label,
				)
			);
		}
	}

	/**
	 * Отрисовка переключателя режима калькулятора.
	 */
	public function render_mode_field() {
		$settings = self::get_settings();
		$name     = self::OPTION_KEY . '[calc_mode]';
		$value    = $settings['calc_mode'];
		?>
		<label style="display:block">
			<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="ai" <?php checked( $value, 'ai' ); ?> />
			<?php esc_html_e( 'Только ИИ — клиент видит единственное поле «тема» и только итоговую сумму без расшифровки (рекомендуется)', 'polygraph-calculator' ); ?>
		</label>
		<label style="display:block">
			<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="formula" <?php checked( $value, 'formula' ); ?> />
			<?php esc_html_e( 'Формула — подробный расчёт (кол-во проверяемых, темы, диагностика)', 'polygraph-calculator' ); ?>
		</label>
		<?php
	}

	/**
	 * Отрисовка поля настройки.
	 *
	 * @param array $args Аргументы поля.
	 */
	public function render_field( $args ) {
		$settings = self::get_settings();
		$key      = $args['key'];
		$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		$name     = self::OPTION_KEY . '[' . $key . ']';

		if ( 'currency' === $key ) {
			echo '<select name="' . esc_attr( $name ) . '">';
			foreach ( array( 'RUB', 'USD', 'EUR', 'KZT', 'UAH' ) as $cur ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $cur ),
					selected( $value, $cur, false ),
					esc_html( $cur )
				);
			}
			echo '</select>';
			return;
		}

		if ( 'calc_mode' === $key ) {
			return; // Отрисовывается отдельно (render_mode_field).
		}

		$type = ( 'notify_email' === $key ) ? 'email' : 'number';
		$step = ( 'pre_post_multiplier' === $key ) ? '0.01' : '1';
		printf(
			'<input type="%s" step="%s" min="0" class="regular-text" name="%s" value="%s" />',
			esc_attr( $type ),
			esc_attr( $step ),
			esc_attr( $name ),
			esc_attr( $value )
		);
	}

	/**
	 * Санитизация настроек.
	 *
	 * @param array $input Введённые значения.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$defaults = self::defaults();
		$out      = array();

		$out['calc_mode']          = in_array( $input['calc_mode'] ?? '', array( 'ai', 'formula' ), true )
			? $input['calc_mode']
			: $defaults['calc_mode'];
		$out['ai_price']           = max( 0, absint( $input['ai_price'] ?? $defaults['ai_price'] ) );
		$out['base_price']         = max( 0, absint( $input['base_price'] ?? $defaults['base_price'] ) );
		$out['per_issue_price']    = max( 0, absint( $input['per_issue_price'] ?? $defaults['per_issue_price'] ) );
		$out['max_issues']         = min( 100, max( 1, absint( $input['max_issues'] ?? $defaults['max_issues'] ) ) );
		$out['pre_post_multiplier'] = max( 0.1, (float) ( $input['pre_post_multiplier'] ?? $defaults['pre_post_multiplier'] ) );
		$out['currency']           = in_array( $input['currency'] ?? '', array( 'RUB', 'USD', 'EUR', 'KZT', 'UAH' ), true )
			? $input['currency']
			: $defaults['currency'];
		$out['notify_email']       = sanitize_email( $input['notify_email'] ?? $defaults['notify_email'] );
		$out['enable_rest']        = ! empty( $input['enable_rest'] ) ? true : $defaults['enable_rest'];

		return $out;
	}

	/**
	 * Страница настроек.
	 */
	public function render_options_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap pgcalc-wrap">
			<h1><?php esc_html_e( 'Polygraph Calculator — настройки', 'polygraph-calculator' ); ?></h1>
			<p>
				<?php esc_html_e( 'Шорткод для вставки калькулятора:', 'polygraph-calculator' ); ?>
				<code>[polygraph_calculator]</code>
			</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'pgcalc_settings_group' );
				do_settings_sections( 'pgcalc-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
