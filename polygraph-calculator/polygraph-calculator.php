<?php
/**
 * Plugin Name:       Polygraph Calculator (Калькулятор полиграфии)
 * Plugin URI:        https://example.com/polygraph-calculator
 * Description:       Калькулятор стоимости листовок и буклетов по диалогу с клиентом. Порт приложения «Polygraph»: чат-диалог (парсер или ИИ), точный расчёт по базе знаний, история, Excel-экспорт. Все настройки и диалоги — в отдельном меню админки «Калькулятор Polygraph». Шорткод [polygraph_calculator].
 * Version:           2.0.0
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

define( 'PGCALC_VERSION', '2.0.0' );
define( 'PGCALC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PGCALC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PGCALC_PLUGIN_DIR . 'includes/class-pgcalc-db.php';
require_once PGCALC_PLUGIN_DIR . 'includes/class-pgcalc-engine.php';
require_once PGCALC_PLUGIN_DIR . 'includes/class-pgcalc-dialog.php';
require_once PGCALC_PLUGIN_DIR . 'includes/class-pgcalc-ai-chat.php';
require_once PGCALC_PLUGIN_DIR . 'includes/class-pgcalc-rest.php';
require_once PGCALC_PLUGIN_DIR . 'includes/class-pgcalc-admin.php';
require_once PGCALC_PLUGIN_DIR . 'includes/class-pgcalc-shortcode.php';

/**
 * Инициализация плагина.
 */
final class Polygraph_Calculator_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_enqueue_scripts', array( 'PGCALC_ADMIN', 'enqueue' ) );

		PGCALC_DB::maybe_install();
		PGCALC_REST::init();
		PGCALC_ADMIN::init();
		PGCALC_SHORTCODE::init();
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'polygraph-calculator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Активация: создать таблицы и заполнить базу знаний.
	 */
	public static function activate() {
		PGCALC_DB::install();
		if ( false === get_option( 'pgcalc_dialog_mode', false ) ) {
			add_option( 'pgcalc_dialog_mode', 'parser' );
			add_option( 'pgcalc_setup_print', 700 );
			add_option( 'pgcalc_setup_cut', 200 );
			add_option( 'pgcalc_show_breakdown', 0 );
		}
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}
}

register_activation_hook( __FILE__, array( 'Polygraph_Calculator_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Polygraph_Calculator_Plugin', 'deactivate' ) );

Polygraph_Calculator_Plugin::instance();
