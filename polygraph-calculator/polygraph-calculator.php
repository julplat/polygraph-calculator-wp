<?php
/**
 * Plugin Name:       Polygraph Calculator
 * Plugin URI:        https://example.com/polygraph-calculator
 * Description:       Калькулятор стоимости услуг полиграфа для WordPress. Шорткод [polygraph_calculator], виджет и REST API.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Polygraph Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       polygraph-calculator
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Прямой доступ запрещён.
}

define( 'PGCALC_VERSION', '1.2.0' );
define( 'PGCALC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PGCALC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PGCALC_PLUGIN_DIR . 'includes/class-pgcalc-settings.php';
require_once PGCALC_PLUGIN_DIR . 'includes/class-pgcalc-ai.php';
require_once PGCALC_PLUGIN_DIR . 'includes/class-pgcalc-shortcode.php';
require_once PGCALC_PLUGIN_DIR . 'includes/class-pgcalc-widget.php';
require_once PGCALC_PLUGIN_DIR . 'includes/class-pgcalc-rest.php';

/**
 * Основная класс-обёртка плагина.
 */
final class Polygraph_Calculator_Plugin {

	/**
	 * Единственный экземпляр плагина.
	 *
	 * @var Polygraph_Calculator_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Получение singleton-экземпляра.
	 *
	 * @return Polygraph_Calculator_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Инициализация хуков.
	 */
	private function init() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );

		PGCalc_Settings::get_instance()->init();
		PGCalc_Shortcode::get_instance()->init();
		PGCalc_Rest::get_instance()->init();
	}

	/**
	 * Загрузка переводов.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'polygraph-calculator',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	/**
	 * Подключение ресурсов на фронтенде (загружаются только при наличии калькулятора).
	 */
	public function enqueue_assets() {
		// Ресурсы подключаются внутри шорткода, здесь регистрируем их заранее.
		wp_register_style(
			'pgcalc-style',
			PGCALC_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			PGCALC_VERSION
		);
		wp_register_script(
			'pgcalc-script',
			PGCALC_PLUGIN_URL . 'assets/js/calculator.js',
			array(),
			PGCALC_VERSION,
			true
		);

		$settings = PGCalc_Settings::get_settings();
		wp_localize_script(
			'pgcalc-script',
			'PGCALC_CONFIG',
			array(
				'currency'        => $settings['currency'],
				'basePrice'       => (int) $settings['base_price'],
				'perIssuePrice'   => (int) $settings['per_issue_price'],
				'maxIssues'       => (int) $settings['max_issues'],
				'prePostMultiplier' => (float) $settings['pre_post_multiplier'],
				'i18n'            => array(
					'total'          => __( 'Итого', 'polygraph-calculator' ),
					'sendRequest'    => __( 'Отправить заявку', 'polygraph-calculator' ),
					'errorName'      => __( 'Укажите ваше имя', 'polygraph-calculator' ),
					'errorContact'   => __( 'Укажите телефон или e-mail', 'polygraph-calculator' ),
					'successMessage' => __( 'Заявка отправлена! Мы свяжемся с вами.', 'polygraph-calculator' ),
					'errorMessage'   => __( 'Не удалось отправить заявку. Попробуйте позже.', 'polygraph-calculator' ),
				),
				'restUrl'         => esc_url_raw( rest_url( 'polygraph-calculator/v1/requests' ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Ресурсы административной страницы.
	 *
	 * @param string $hook Текущая страница админки.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_pgcalc-settings' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'pgcalc-admin', PGCALC_PLUGIN_URL . 'assets/css/admin.css', array(), PGCALC_VERSION );
	}

	/**
	 * Регистрация виджета.
	 */
	public function register_widget() {
		register_widget( 'PGCalc_Widget' );
	}

	/**
	 * Активация плагина: значения настроек по умолчанию.
	 */
	public static function activate() {
		PGCalc_Settings::maybe_set_defaults();
		flush_rewrite_rules();
	}

	/**
	 * Деактивация плагина.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}

register_activation_hook( __FILE__, array( 'Polygraph_Calculator_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Polygraph_Calculator_Plugin', 'deactivate' ) );

Polygraph_Calculator_Plugin::instance();
