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

			// Провайдер ИИ (для реального расчёта стоимости нейросетью).
			'ai_provider'        => 'none',   // none | openai | anthropic | deepseek | custom.
			'ai_api_key'         => '',
			'ai_model'           => 'gpt-4o-mini',
			'ai_endpoint'        => '',       // Свой URL API (режим custom).
			'ai_pricing_rules'   => '',       // Прайс/правила для ИИ (текст промпта).
		);
	}

	/**
	 * Провайдеры ИИ: метка => [endpoint по умолчанию, модель по умолчанию].
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'openai'    => array(
				'label'      => 'OpenAI',
				'endpoint'   => 'https://api.openai.com/v1/chat/completions',
				'default_model' => 'gpt-4o-mini',
			),
			'anthropic' => array(
				'label'      => 'Anthropic (Claude)',
				'endpoint'   => 'https://api.anthropic.com/v1/messages',
				'default_model' => 'claude-3-5-haiku-latest',
			),
			'deepseek'  => array(
				'label'      => 'DeepSeek',
				'endpoint'   => 'https://api.deepseek.com/chat/completions',
				'default_model' => 'deepseek-chat',
			),
			'custom'    => array(
				'label'      => __( 'Свой endpoint (OpenAI-совместимый)', 'polygraph-calculator' ),
				'endpoint'   => '',
				'default_model' => '',
			),
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

		// Секция «ИИ: провайдер и база знаний».
		add_settings_section(
			'pgcalc_ai',
			__( 'Интеллектуальный расчёт (ИИ)', 'polygraph-calculator' ),
			array( $this, 'render_ai_section_intro' ),
			'pgcalc-settings'
		);

		$ai_fields = array(
			'ai_provider'      => __( 'Провайдер ИИ', 'polygraph-calculator' ),
			'ai_api_key'       => __( 'API-ключ провайдера', 'polygraph-calculator' ),
			'ai_model'         => __( 'Модель', 'polygraph-calculator' ),
			'ai_endpoint'      => __( 'Свой endpoint (для режима «Свой»)', 'polygraph-calculator' ),
			'ai_pricing_rules' => __( 'База знаний: прайс и правила расчёта', 'polygraph-calculator' ),
		);

		foreach ( $ai_fields as $key => $label ) {
			add_settings_field(
				'pgcalc_' . $key,
				$label,
				array( $this, 'render_field' ),
				'pgcalc-settings',
				'pgcalc_ai',
				array(
					'key'   => $key,
					'label' => $label,
				)
			);
		}
	}

	/**
	 * Вступление к секции ИИ.
	 */
	public function render_ai_section_intro() {
		echo '<p class="description">' . esc_html__(
			'Если провайдер выбран и ключ заполнен, итоговая стоимость в режиме «Только ИИ» рассчитывается нейросетью на основе базы знаний ниже. Если нет — используется фиксированная цена за тему.',
			'polygraph-calculator'
		) . '</p>';
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

		if ( 'ai_provider' === $key ) {
			echo '<select name="' . esc_attr( $name ) . '">';
			printf( '<option value="none" %s>%s</option>', selected( $value, 'none', false ), esc_html__( 'Без ИИ (фиксированная цена)', 'polygraph-calculator' ) );
			foreach ( self::providers() as $pid => $p ) {
				printf(
					'<option value="%s" %s data-endpoint="%s" data-model="%s">%s</option>',
					esc_attr( $pid ),
					selected( $value, $pid, false ),
					esc_attr( $p['endpoint'] ),
					esc_attr( $p['default_model'] ),
					esc_html( $p['label'] )
				);
			}
			echo '</select>';
			return;
		}

		if ( 'ai_pricing_rules' === $key ) {
			printf(
				'<textarea name="%s" rows="8" class="large-text code" placeholder="%s">%s</textarea>',
				esc_attr( $name ),
				esc_attr__( "Пример:\nТема по безопасности — 9000 руб.\nТема по финансам — 12000 руб.\nСрочность в выходные +30%.", 'polygraph-calculator' ),
				esc_textarea( $value )
			);
			return;
		}

		if ( 'ai_api_key' === $key ) {
			printf(
				'<input type="password" class="regular-text" name="%s" value="%s" autocomplete="off" />',
				esc_attr( $name ),
				esc_attr( $value )
			);
			echo '<p class="description">' . esc_html__( 'Ключ хранится в базе только для администраторов и никогда не передаётся в браузер посетителя.', 'polygraph-calculator' ) . '</p>';
			return;
		}

		if ( 'ai_endpoint' === $key || 'ai_model' === $key ) {
			printf(
				'<input type="text" class="large-text code" name="%s" value="%s" />',
				esc_attr( $name ),
				esc_attr( $value )
			);
			return;
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

		$providers               = self::providers();
		$out['ai_provider']      = in_array( $input['ai_provider'] ?? 'none', array_merge( array( 'none' ), array_keys( $providers ) ), true )
			? $input['ai_provider']
			: 'none';
		$out['ai_api_key']       = sanitize_text_field( $input['ai_api_key'] ?? '' );
		$out['ai_model']         = sanitize_text_field( $input['ai_model'] ?? $defaults['ai_model'] );
		$out['ai_endpoint']      = esc_url_raw( $input['ai_endpoint'] ?? '' );
		$out['ai_pricing_rules'] = wp_kses_post( $input['ai_pricing_rules'] ?? '' );

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

			<script>
			// Автоподстановка endpoint и модели при выборе провайдера ИИ.
			(function () {
				var sel = document.querySelector( 'select[name="<?php echo esc_js( self::OPTION_KEY ); ?>[ai_provider]"]' );
				if ( ! sel ) return;
				var ep = document.querySelector( 'input[name="<?php echo esc_js( self::OPTION_KEY ); ?>[ai_endpoint]"]' );
				var md = document.querySelector( 'input[name="<?php echo esc_js( self::OPTION_KEY ); ?>[ai_model]"]' );
				sel.addEventListener( 'change', function () {
					var opt = sel.options[ sel.selectedIndex ];
					if ( ep ) ep.value = opt.getAttribute( 'data-endpoint' ) || '';
					if ( md && opt.getAttribute( 'data-model' ) ) md.value = opt.getAttribute( 'data-model' );
				} );
			})();
			</script>
		</div>
		<?php
	}
}
