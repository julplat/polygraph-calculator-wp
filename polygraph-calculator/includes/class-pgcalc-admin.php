<?php
/**
 * Админка плагина: единое меню «Калькулятор Polygraph» со всеми страницами,
 * настройками и диалогами внутри (не в «Настройках → Общие»).
 *
 * Страницы:
 *  - Калькулятор (главная): тестовый диалог + проверка расчёта/автотестов
 *  - База знаний: материалы, размеры, тарифы, приладки
 *  - Настройки ИИ: режим диалога (парсер/ИИ), API URL, ключ, модель
 *  - История: сохранённые расчёты
 *
 * @package polygraph-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PGCALC_ADMIN {

	const MENU_SLUG = 'pgcalc';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'load-toplevel_page_pgcalc', array( __CLASS__, 'handle_actions' ) );
		add_action( 'load-polygraph-calculator_page_pgcalc-kb', array( __CLASS__, 'handle_actions' ) );
		add_action( 'load-polygraph-calculator_page_pgcalc-ai', array( __CLASS__, 'handle_actions' ) );
		add_action( 'load-polygraph-calculator_page_pgcalc-history', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_post_pgcalc_reset_db', array( __CLASS__, 'action_reset_db' ) );
	}

	/**
	 * Ключ текущей админ-страницы плагина (для redirect после сохранения).
	 */
	private static function current_page_slug() {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && ! empty( $screen->id ) ) {
				// "polygraph-calculator_page_pgcalc-kb" -> "pgcalc-kb", "toplevel_page_pgcalc" -> "pgcalc".
				$id = $screen->id;
				if ( false !== strpos( $id, '_page_' ) ) {
					return substr( $id, strrpos( $id, '_page_' ) + 6 );
				}
				if ( 0 === strpos( $id, 'toplevel_page_' ) ) {
					return substr( $id, strlen( 'toplevel_page_' ) );
				}
				return $id;
			}
		}
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::MENU_SLUG; // phpcs:ignore
	}

	public static function register_menu() {
		$cap = 'manage_options';

		add_menu_page(
			__( 'Калькулятор Polygraph', 'polygraph-calculator' ),
			__( 'Калькулятор Polygraph', 'polygraph-calculator' ),
			$cap,
			self::MENU_SLUG,
			array( __CLASS__, 'page_calculator' ),
			'dashicons-calculator',
			26
		);

		add_submenu_page( self::MENU_SLUG, __( 'Калькулятор (тест)', 'polygraph-calculator' ), __( 'Калькулятор (тест)', 'polygraph-calculator' ), $cap, self::MENU_SLUG, array( __CLASS__, 'page_calculator' ) );
		add_submenu_page( self::MENU_SLUG, __( 'База знаний', 'polygraph-calculator' ), __( 'База знаний', 'polygraph-calculator' ), $cap, 'pgcalc-kb', array( __CLASS__, 'page_kb' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Настройки ИИ', 'polygraph-calculator' ), __( 'Настройки ИИ', 'polygraph-calculator' ), $cap, 'pgcalc-ai', array( __CLASS__, 'page_ai' ) );
		add_submenu_page( self::MENU_SLUG, __( 'История', 'polygraph-calculator' ), __( 'История', 'polygraph-calculator' ), $cap, 'pgcalc-history', array( __CLASS__, 'page_history' ) );
	}

	/* ---------- Обработчики POST ---------- */

	public static function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Все POST-формы плагина отправляются на саму страницу (admin.php?page=...),
		// обрабатываются на хуке load-* и завершаются redirect-ом (паттерн PRG):
		// без редиректа браузер может повторно отправить устаревший nonce («залипшая»
		// вкладка, автозаполнение формы) — сохранение провалится молча.
		$redirect = false;

		// Сохранение приладок.
		if ( isset( $_POST['pgcalc_setup_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['pgcalc_setup_nonce'] ), 'pgcalc_setup' ) ) {
			update_option( 'pgcalc_setup_print', max( 0, (float) ( isset( $_POST['setup_print'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_print'] ) ) : 700 ) ) );
			update_option( 'pgcalc_setup_cut', max( 0, (float) ( isset( $_POST['setup_cut'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_cut'] ) ) : 200 ) ) );
			$redirect = 'pgcalc_kb';
		}

		// Материал: сохранить/удалить.
		if ( isset( $_POST['pgcalc_kb_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['pgcalc_kb_nonce'] ), 'pgcalc_kb' ) ) {
			$act = isset( $_POST['pgcalc_act'] ) ? sanitize_key( $_POST['pgcalc_act'] ) : '';
			if ( 'save_material' === $act ) {
				$row = array(
					'id'          => isset( $_POST['material_id'] ) ? sanitize_text_field( wp_unslash( $_POST['material_id'] ) ) : '',
					'name'        => isset( $_POST['material_name'] ) ? sanitize_text_field( wp_unslash( $_POST['material_name'] ) ) : '',
					'description' => isset( $_POST['material_desc'] ) ? sanitize_text_field( wp_unslash( $_POST['material_desc'] ) ) : '',
					'sheet_w'     => isset( $_POST['sheet_w'] ) ? absint( $_POST['sheet_w'] ) : 0,
					'sheet_h'     => isset( $_POST['sheet_h'] ) ? absint( $_POST['sheet_h'] ) : 0,
					'p0'          => isset( $_POST['p0'] ) ? sanitize_text_field( wp_unslash( $_POST['p0'] ) ) : 0,
					'p40'         => isset( $_POST['p40'] ) ? sanitize_text_field( wp_unslash( $_POST['p40'] ) ) : 0,
					'p90'         => isset( $_POST['p90'] ) ? sanitize_text_field( wp_unslash( $_POST['p90'] ) ) : 0,
					'p150'        => isset( $_POST['p150'] ) ? sanitize_text_field( wp_unslash( $_POST['p150'] ) ) : 0,
					'p4001'       => isset( $_POST['p4001'] ) ? sanitize_text_field( wp_unslash( $_POST['p4001'] ) ) : 0,
					'p6501'       => isset( $_POST['p6501'] ) ? sanitize_text_field( wp_unslash( $_POST['p6501'] ) ) : 0,
					'sort_order'  => isset( $_POST['sort_order'] ) ? absint( $_POST['sort_order'] ) : 99,
				);
				PGCALC_DB::save_material( $row, $row['id'] ? rawurldecode( $row['id'] ) : null );
			} elseif ( 'delete_material' === $act && isset( $_POST['del_id'] ) ) {
				PGCALC_DB::delete_material( sanitize_text_field( wp_unslash( $_POST['del_id'] ) ) );
			} elseif ( 'save_size' === $act ) {
				PGCALC_DB::save_size(
					array(
						'name'      => isset( $_POST['size_name'] ) ? sanitize_text_field( wp_unslash( $_POST['size_name'] ) ) : '',
						'w'         => isset( $_POST['size_w'] ) ? absint( $_POST['size_w'] ) : 0,
						'h'         => isset( $_POST['size_h'] ) ? absint( $_POST['size_h'] ) : 0,
						'k450x320'  => isset( $_POST['k450x320'] ) ? absint( $_POST['k450x320'] ) : 0,
						'k310x470'  => isset( $_POST['k310x470'] ) ? absint( $_POST['k310x470'] ) : 0,
						'k430x305'  => isset( $_POST['k430x305'] ) ? absint( $_POST['k430x305'] ) : 0,
					),
					isset( $_POST['size_id'] ) && $_POST['size_id'] ? absint( $_POST['size_id'] ) : null
				);
			} elseif ( 'delete_size' === $act && isset( $_POST['del_id'] ) ) {
				PGCALC_DB::delete_size( absint( $_POST['del_id'] ) );
			} elseif ( 'save_tariff' === $act ) {
				$kind = isset( $_POST['tariff_kind'] ) ? sanitize_key( $_POST['tariff_kind'] ) : 'print';
				PGCALC_DB::save_tariff(
					$kind,
					array(
						'min_qty'    => isset( $_POST['min_qty'] ) ? absint( $_POST['min_qty'] ) : 1,
						'max_qty'    => isset( $_POST['max_qty'] ) ? absint( $_POST['max_qty'] ) : 0,
						'price'      => isset( $_POST['tprice'] ) ? sanitize_text_field( wp_unslash( $_POST['tprice'] ) ) : 0,
						'sort_order' => isset( $_POST['sort_order'] ) ? absint( $_POST['sort_order'] ) : 99,
					),
					isset( $_POST['tariff_id'] ) && $_POST['tariff_id'] ? absint( $_POST['tariff_id'] ) : null
				);
			} elseif ( 'delete_tariff' === $act && isset( $_POST['del_id'] ) ) {
				PGCALC_DB::delete_tariff( absint( $_POST['del_id'] ) );
			}
			$redirect = 'pgcalc-kb';
		}

		// Настройки ИИ.
		if ( isset( $_POST['pgcalc_ai_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['pgcalc_ai_nonce'] ), 'pgcalc_ai' ) ) {
			$mode = ( isset( $_POST['dialog_mode'] ) && 'ai' === $_POST['dialog_mode'] ) ? 'ai' : 'parser';
			update_option( 'pgcalc_dialog_mode', $mode );
			update_option( 'pgcalc_ai_api_url', esc_url_raw( isset( $_POST['ai_api_url'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_api_url'] ) ) : '' ) );
			$key = isset( $_POST['ai_api_key'] ) ? trim( wp_unslash( $_POST['ai_api_key'] ) ) : '';
			if ( '' !== $key ) { // Пустое поле = не менять ключ.
				update_option( 'pgcalc_ai_api_key', sanitize_text_field( $key ) );
			}
			update_option( 'pgcalc_ai_model', sanitize_text_field( isset( $_POST['ai_model'] ) ? wp_unslash( $_POST['ai_model'] ) : '' ) );
			update_option( 'pgcalc_notify_email', sanitize_email( isset( $_POST['notify_email'] ) ? wp_unslash( $_POST['notify_email'] ) : '' ) );
			update_option( 'pgcalc_show_breakdown', empty( $_POST['show_breakdown'] ) ? 0 : 1 );
			$redirect = 'pgcalc-ai';
		}

		// История: удаление/очистка.
		if ( isset( $_POST['pgcalc_hist_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['pgcalc_hist_nonce'] ), 'pgcalc_history' ) ) {
			if ( isset( $_POST['del_ids'] ) ) {
				PGCALC_DB::delete_history( array_map( 'absint', (array) $_POST['del_ids'] ) );
			}
			if ( isset( $_POST['clear_all'] ) ) {
				PGCALC_DB::clear_history();
			}
			$redirect = 'pgcalc-history';
		}

		// POST обработан (или пришёл со старым nonce) — редирект с сообщением (PRG),
		// чтобы форма не отправлялась повторно и страница всегда показывала свежий nonce.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			$page = $redirect ? $redirect : self::current_page_slug();
			$msg  = $redirect ? 'saved' : 'expired';
			wp_safe_redirect( admin_url( 'admin.php?page=' . rawurlencode( $page ) . '&pgcalc_msg=' . $msg ) );
			exit;
		}
	}

	public static function action_reset_db() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'pgcalc_reset_db' );
		PGCALC_DB::reset_to_defaults();
		wp_safe_redirect( admin_url( 'admin.php?page=pgcalc-kb&reset=1' ) );
		exit;
	}

	private static function nonce_field( $action ) {
		wp_nonce_field( $action, $action . '_nonce', false );
	}

	/* ---------- Общая разметка ---------- */

	private static function header( $title ) {
		echo '<div class="wrap pgcalc-wrap">'
			. '<div class="pgcalc-hero"><h1>' . esc_html( $title ) . '</h1>'
			. '<p>Polygraph Calculator — настройки, база знаний и тест диалога в одном месте.</p></div>';
		if ( isset( $_GET['pgcalc_msg'] ) ) {
			if ( 'saved' === $_GET['pgcalc_msg'] ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Изменения сохранены.', 'polygraph-calculator' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Форма устарела (страница была открыта давно). Обновите страницу и сохраните ещё раз.', 'polygraph-calculator' ) . '</p></div>';
			}
		}
	}

	/* ---------- Страница: калькулятор (тест диалога) ---------- */

	public static function page_calculator() {
		self::enqueue();
		$mode = get_option( 'pgcalc_dialog_mode', 'parser' );
		self::header( __( 'Калькулятор Polygraph — тест диалога', 'polygraph-calculator' ) );
		?>
		<p class="description"><?php echo 'ai' === $mode ? esc_html__( 'Активен режим ИИ: параметры заказа извлекает нейросеть.', 'polygraph-calculator' ) : esc_html__( 'Активен режим парсера: быстрый разбор текста без ИИ.', 'polygraph-calculator' ); ?></p>

		<div id="pgcalc-test" class="pgcalc-card">
			<div class="pgcalc-chat" id="pgcalc-chat"></div>
			<div class="pgcalc-row">
				<input type="text" id="pgcalc-input" class="regular-text" style="width:70%" placeholder="<?php esc_attr_e( 'Например: листовки А6, 100 шт, бумага 120 г, печать 4+0', 'polygraph-calculator' ); ?>" />
				<button class="button button-primary" id="pgcalc-send"><?php esc_html_e( 'Отправить', 'polygraph-calculator' ); ?></button>
				<button class="button" id="pgcalc-reset"><?php esc_html_e( 'Начать заново', 'polygraph-calculator' ); ?></button>
			</div>
			<div id="pgcalc-result"></div>
		</div>

		<h2><?php esc_html_e( 'Автотесты расчётного движка', 'polygraph-calculator' ); ?></h2>
		<table class="widefat striped" style="max-width:700px">
			<thead><tr><th><?php esc_html_e( 'Проверка', 'polygraph-calculator' ); ?></th><th><?php esc_html_e( 'Ожидалось', 'polygraph-calculator' ); ?></th><th><?php esc_html_e( 'Получено', 'polygraph-calculator' ); ?></th><th><?php esc_html_e( 'Статус', 'polygraph-calculator' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( PGCALC_ENGINE::verify_examples() as $t ) : ?>
				<tr>
					<td><?php echo esc_html( $t['name'] ); ?></td>
					<td><?php echo esc_html( PGCALC_ENGINE::format_price( $t['expected'] ) ); ?></td>
					<td><?php echo null === $t['actual'] ? '—' : esc_html( PGCALC_ENGINE::format_price( $t['actual'] ) ); ?></td>
					<td><?php echo $t['passed'] ? '<span style="color:#0a7d30;font-weight:600">✔ OK</span>' : '<span style="color:#b32d2e;font-weight:600">✘ FAIL</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		echo '</div>';
	}

	/* ---------- Страница: база знаний ---------- */

	public static function page_kb() {
		self::enqueue();
		self::header( __( 'База знаний', 'polygraph-calculator' ) );
		if ( isset( $_GET['reset'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'База знаний сброшена к значениям по умолчанию.', 'polygraph-calculator' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php esc_attr_e( 'Сбросить все таблицы БЗ к заводским значениям? Ваши правки будут потеряны.', 'polygraph-calculator' ); ?>');" style="margin-bottom:15px">
			<?php self::nonce_field( 'pgcalc_reset_db' ); ?>
			<input type="hidden" name="action" value="pgcalc_reset_db" />
			<button class="button"><?php esc_html_e( '↺ Сбросить базу знаний к стандартной', 'polygraph-calculator' ); ?></button>
		</form>

		<h2 class="title"><?php esc_html_e( 'Материалы (цена за лист, ₽)', 'polygraph-calculator' ); ?></h2>
		<table class="widefat striped pgcalc-table">
			<thead>
				<tr>
					<th>ID</th><th><?php esc_html_e( 'Название', 'polygraph-calculator' ); ?></th><th><?php esc_html_e( 'Лист, мм', 'polygraph-calculator' ); ?></th>
					<th>0–39</th><th>40–89</th><th>90–149</th><th>150–4000</th><th>4001–6500</th><th>6501+</th><th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( PGCALC_DB::get_materials() as $m ) : ?>
				<tr>
					<td><code><?php echo esc_html( $m->id ); ?></code></td>
					<td><?php echo esc_html( $m->name ); ?><br/><small class="description"><?php echo esc_html( $m->description ); ?></small></td>
					<td><?php echo (int) $m->sheet_w . '×' . (int) $m->sheet_h; ?></td>
					<td><?php echo esc_html( number_format( $m->p0 / 100, 2, '.', ' ' ) ); ?></td>
					<td><?php echo esc_html( number_format( $m->p40 / 100, 2, '.', ' ' ) ); ?></td>
					<td><?php echo esc_html( number_format( $m->p90 / 100, 2, '.', ' ' ) ); ?></td>
					<td><?php echo esc_html( number_format( $m->p150 / 100, 2, '.', ' ' ) ); ?></td>
					<td><?php echo esc_html( number_format( $m->p4001 / 100, 2, '.', ' ' ) ); ?></td>
					<td><?php echo esc_html( number_format( $m->p6501 / 100, 2, '.', ' ' ) ); ?></td>
					<td>
						<form method="post" style="display:inline" onsubmit="return confirm('<?php esc_attr_e( 'Удалить материал?', 'polygraph-calculator' ); ?>');">
							<?php self::nonce_field( 'pgcalc_kb' ); ?>
							<input type="hidden" name="pgcalc_act" value="delete_material" />
							<input type="hidden" name="del_id" value="<?php echo esc_attr( $m->id ); ?>" />
							<button class="button-link-delete"><?php esc_html_e( 'Удалить', 'polygraph-calculator' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Добавить / изменить материал', 'polygraph-calculator' ); ?></h3>
		<form method="post" class="pgcalc-form">
			<?php self::nonce_field( 'pgcalc_kb' ); ?>
			<input type="hidden" name="pgcalc_act" value="save_material" />
			<table><tr>
				<td><label>ID</label><br/><input name="material_id" class="regular-text" placeholder="paper-130"/></td>
				<td><label><?php esc_html_e( 'Название', 'polygraph-calculator' ); ?></label><br/><input name="material_name" class="regular-text"/></td>
				<td><label><?php esc_html_e( 'Описание', 'polygraph-calculator' ); ?></label><br/><input name="material_desc" class="regular-text"/></td>
			</tr><tr>
				<td><label><?php esc_html_e( 'Лист W, мм', 'polygraph-calculator' ); ?></label><br/><input name="sheet_w" size="5"/></td>
				<td><label><?php esc_html_e( 'Лист H, мм', 'polygraph-calculator' ); ?></label><br/><input name="sheet_h" size="5"/></td>
				<td><label><?php esc_html_e( 'Порядок', 'polygraph-calculator' ); ?></label><br/><input name="sort_order" size="5"/></td>
			</tr><tr>
				<td><label>0–39</label><br/><input name="p0" size="8"/></td>
				<td><label>40–89</label><br/><input name="p40" size="8"/></td>
				<td><label>90–149</label><br/><input name="p90" size="8"/></td>
				<td><label>150–4000</label><br/><input name="p150" size="8"/></td>
				<td><label>4001–6500</label><br/><input name="p4001" size="8"/></td>
				<td><label>6501+</label><br/><input name="p6501" size="8"/></td>
			</tr></table>
			<p><button class="button button-primary"><?php esc_html_e( 'Сохранить материал', 'polygraph-calculator' ); ?></button></p>
		</form>

		<h2 class="title"><?php esc_html_e( 'Стандартные размеры и раскладка k (шт/лист)', 'polygraph-calculator' ); ?></h2>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th>ID</th><th><?php esc_html_e( 'Имя', 'polygraph-calculator' ); ?></th><th>W</th><th>H</th><th>k 450×320</th><th>k 310×470</th><th>k 430×305</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( PGCALC_DB::get_sizes() as $s ) : ?>
				<tr>
					<td><?php echo (int) $s->id; ?></td>
					<td><?php echo esc_html( $s->name ); ?></td>
					<td><?php echo (int) $s->w; ?></td>
					<td><?php echo (int) $s->h; ?></td>
					<td><?php echo (int) $s->k450x320; ?></td>
					<td><?php echo (int) $s->k310x470; ?></td>
					<td><?php echo (int) $s->k430x305; ?></td>
					<td>
						<form method="post" style="display:inline" onsubmit="return confirm('<?php esc_attr_e( 'Удалить размер?', 'polygraph-calculator' ); ?>');">
							<?php self::nonce_field( 'pgcalc_kb' ); ?>
							<input type="hidden" name="pgcalc_act" value="delete_size" />
							<input type="hidden" name="del_id" value="<?php echo (int) $s->id; ?>" />
							<button class="button-link-delete"><?php esc_html_e( 'Удалить', 'polygraph-calculator' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<h3><?php esc_html_e( 'Добавить / изменить размер', 'polygraph-calculator' ); ?></h3>
		<form method="post" class="pgcalc-form">
			<?php self::nonce_field( 'pgcalc_kb' ); ?>
			<input type="hidden" name="pgcalc_act" value="save_size" />
			<input type="hidden" name="size_id" value="" />
			<label><?php esc_html_e( 'Имя', 'polygraph-calculator' ); ?> <input name="size_name" size="6"/></label>
			<label>W <input name="size_w" size="4"/></label>
			<label>H <input name="size_h" size="4"/></label>
			<label>k450×320 <input name="k450x320" size="3"/></label>
			<label>k310×470 <input name="k310x470" size="3"/></label>
			<label>k430×305 <input name="k430x305" size="3"/></label>
			<button class="button button-primary"><?php esc_html_e( 'Сохранить', 'polygraph-calculator' ); ?></button>
		</form>

		<h2 class="title"><?php esc_html_e( 'Тарифы', 'polygraph-calculator' ); ?></h2>
		<?php
		$kind_labels = array(
			'print'  => __( 'Печать (₽/лист за прогон)', 'polygraph-calculator' ),
			'cut'    => __( 'Резка (₽/изделие)', 'polygraph-calculator' ),
			'crease' => __( 'Биговка (₽/сгиб)', 'polygraph-calculator' ),
		);
		foreach ( $kind_labels as $kind => $label ) :
			$rows = PGCALC_DB::get_tariffs( $kind );
			?>
			<h3><?php echo esc_html( $label ); ?></h3>
			<table class="widefat striped" style="max-width:700px">
				<thead><tr><th>ID</th><th><?php esc_html_e( 'от', 'polygraph-calculator' ); ?></th><th><?php esc_html_e( 'до', 'polygraph-calculator' ); ?></th><th><?php esc_html_e( 'Цена, ₽', 'polygraph-calculator' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $t ) : ?>
					<tr>
						<td><?php echo (int) $t->id; ?></td>
						<td><?php echo (int) $t->min_qty; ?></td>
						<td><?php echo (int) $t->max_qty >= 100000000 ? '∞' : (int) $t->max_qty; ?></td>
						<td><?php echo esc_html( number_format( (float) $t->price, 2, '.', ' ' ) ); ?></td>
						<td>
							<form method="post" style="display:inline" onsubmit="return confirm('<?php esc_attr_e( 'Удалить строку тарифа?', 'polygraph-calculator' ); ?>');">
								<?php self::nonce_field( 'pgcalc_kb' ); ?>
								<input type="hidden" name="pgcalc_act" value="delete_tariff" />
								<input type="hidden" name="del_id" value="<?php echo (int) $t->id; ?>" />
								<button class="button-link-delete"><?php esc_html_e( 'Удалить', 'polygraph-calculator' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" class="pgcalc-form">
				<?php self::nonce_field( 'pgcalc_kb' ); ?>
				<input type="hidden" name="pgcalc_act" value="save_tariff" />
				<input type="hidden" name="tariff_kind" value="<?php echo esc_attr( $kind ); ?>" />
				<input type="hidden" name="tariff_id" value="" />
				<label><?php esc_html_e( 'от', 'polygraph-calculator' ); ?> <input name="min_qty" size="5"/></label>
				<label><?php esc_html_e( 'до (пусто = ∞)', 'polygraph-calculator' ); ?> <input name="max_qty" size="7"/></label>
				<label><?php esc_html_e( 'Цена ₽', 'polygraph-calculator' ); ?> <input name="tprice" size="7"/></label>
				<label><?php esc_html_e( 'порядок', 'polygraph-calculator' ); ?> <input name="sort_order" size="4"/></label>
				<button class="button"><?php esc_html_e( 'Добавить/сохранить', 'polygraph-calculator' ); ?></button>
			</form>
		<?php endforeach; ?>

		<h2 class="title"><?php esc_html_e( 'Приладки (подготовительные работы), ₽', 'polygraph-calculator' ); ?></h2>
		<form method="post" class="pgcalc-form">
			<?php self::nonce_field( 'pgcalc_setup' ); ?>
			<label><?php esc_html_e( 'Приладка печати', 'polygraph-calculator' ); ?> <input name="setup_print" size="8" value="<?php echo esc_attr( get_option( 'pgcalc_setup_print', 700 ) ); ?>"/></label>
			<label><?php esc_html_e( 'Приладка резки', 'polygraph-calculator' ); ?> <input name="setup_cut" size="8" value="<?php echo esc_attr( get_option( 'pgcalc_setup_cut', 200 ) ); ?>"/></label>
			<button class="button button-primary"><?php esc_html_e( 'Сохранить', 'polygraph-calculator' ); ?></button>
		</form>
		<?php
		echo '</div>';
	}

	/* ---------- Страница: настройки ИИ ---------- */

	public static function page_ai() {
		self::enqueue();
		self::header( __( 'Настройки ИИ', 'polygraph-calculator' ) );
		$mode   = get_option( 'pgcalc_dialog_mode', 'parser' );
		$keyset = '' !== trim( (string) get_option( 'pgcalc_ai_api_key', '' ) );
		?>
		<form method="post" class="pgcalc-card" style="max-width:760px">
			<?php self::nonce_field( 'pgcalc_ai' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Режим диалога', 'polygraph-calculator' ); ?></th>
					<td>
						<label><input type="radio" name="dialog_mode" value="parser" <?php checked( $mode, 'parser' ); ?> /> 🔧 <?php esc_html_e( 'Парсер — быстрый, бесплатный', 'polygraph-calculator' ); ?></label><br/>
						<label><input type="radio" name="dialog_mode" value="ai" <?php checked( $mode, 'ai' ); ?> /> 🤖 <?php esc_html_e( 'ИИ — гибкий разбор через API', 'polygraph-calculator' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ai_api_url"><?php esc_html_e( 'API URL', 'polygraph-calculator' ); ?></label></th>
					<td><input type="text" id="ai_api_url" name="ai_api_url" class="regular-text code" value="<?php echo esc_attr( get_option( 'pgcalc_ai_api_url', 'https://api.promptra.ru/v1/chat/completions' ) ); ?>"/>
					<p class="description"><?php esc_html_e( 'OpenAI-совместимый endpoint. Можно указать базовый URL — /v1/chat/completions добавится автоматически.', 'polygraph-calculator' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="ai_api_key"><?php esc_html_e( 'API ключ', 'polygraph-calculator' ); ?></label></th>
					<td><input type="password" id="ai_api_key" name="ai_api_key" class="regular-text code" value="" autocomplete="off" placeholder="<?php echo $keyset ? esc_attr__( '•••• ключ сохранён •••• (введите, чтобы заменить)', 'polygraph-calculator' ) : esc_attr__( 'Введите API ключ', 'polygraph-calculator' ); ?>"/>
					<p class="description"><?php echo $keyset ? esc_html__( 'Ключ сохранён. Поле пустое — ключ не изменится.', 'polygraph-calculator' ) : esc_html__( 'Ключ хранится только на сервере, клиенту не отдаётся.', 'polygraph-calculator' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="ai_model"><?php esc_html_e( 'Модель', 'polygraph-calculator' ); ?></label></th>
					<td><input type="text" id="ai_model" name="ai_model" class="regular-text code" value="<?php echo esc_attr( get_option( 'pgcalc_ai_model', 'deepseek/deepseek-v4-pro' ) ); ?>"/></td>
				</tr>
				<tr>
					<th scope="row"><label for="notify_email"><?php esc_html_e( 'E-mail для заявок с сайта', 'polygraph-calculator' ); ?></label></th>
					<td><input type="email" id="notify_email" name="notify_email" class="regular-text" value="<?php echo esc_attr( get_option( 'pgcalc_notify_email', get_option( 'admin_email' ) ) ); ?>"/></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Что видит клиент', 'polygraph-calculator' ); ?></th>
					<td>
						<label><input type="checkbox" name="show_breakdown" value="1" <?php checked( (bool) get_option( 'pgcalc_show_breakdown', false ) ); ?> /> <?php esc_html_e( 'Показывать детализацию (обычно выключено — только итоговая сумма)', 'polygraph-calculator' ); ?></label>
					</td>
				</tr>
			</table>
			<p><button class="button button-primary"><?php esc_html_e( 'Сохранить настройки', 'polygraph-calculator' ); ?></button></p>
		</form>

		<h2><?php esc_html_e( 'Проверка подключения к ИИ', 'polygraph-calculator' ); ?></h2>
		<p><button class="button" id="pgcalc-ai-ping"><?php esc_html_e( '🔌 Проверить API', 'polygraph-calculator' ); ?></button> <span id="pgcalc-ai-ping-result" class="description"></span></p>
		<?php
		echo '</div>';
	}

	/* ---------- Страница: история ---------- */

	public static function page_history() {
		self::enqueue();
		self::header( __( 'История расчётов', 'polygraph-calculator' ) );
		$rows = PGCALC_DB::get_history( 200 );
		?>
		<form method="post">
			<?php self::nonce_field( 'pgcalc_history' ); ?>
			<p>
				<button class="button" name="clear_all" value="1" onclick="return confirm('<?php esc_attr_e( 'Очистить всю историю?', 'polygraph-calculator' ); ?>');"><?php esc_html_e( 'Очистить историю', 'polygraph-calculator' ); ?></button>
			</p>
			<table class="widefat striped">
				<thead><tr>
					<th style="width:30px"></th><th><?php esc_html_e( 'Дата', 'polygraph-calculator' ); ?></th>
					<th><?php esc_html_e( 'Клиент', 'polygraph-calculator' ); ?></th>
					<th><?php esc_html_e( 'Заказ', 'polygraph-calculator' ); ?></th>
					<th><?php esc_html_e( 'Итого', 'polygraph-calculator' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'История пуста. Сохраняйте расчёты из окна калькулятора (кнопка «Сохранить») или с сайта.', 'polygraph-calculator' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) :
					$p = json_decode( $r->params, true );
					$res = json_decode( $r->result, true );
					$summary = '';
					if ( is_array( $p ) ) {
						$type = 'flyer' === ( $p['type'] ?? '' ) ? 'Листовка' : 'Буклет';
						$summary = $type . ' ' . ( $p['sizeName'] ?? '' ) . ', ' . ( $p['quantity'] ?? '' ) . ' шт, ' . PGCALC_ENGINE::material_display_name( $p['materialId'] ?? '' ) . ', печать ' . ( $p['printing'] ?? '' );
					}
					?>
					<tr>
						<td><input type="checkbox" name="del_ids[]" value="<?php echo (int) $r->id; ?>"/></td>
						<td><?php echo esc_html( $r->created_at ); ?></td>
						<td><?php echo esc_html( trim( ( $r->client_name ? $r->client_name : '' ) . ' ' . $r->client_phone ) ); ?></td>
						<td><?php echo esc_html( $summary ); ?></td>
						<td><strong><?php echo isset( $res['total'] ) ? esc_html( PGCALC_ENGINE::format_price( $res['total'] ) ) : '—'; ?></strong></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button class="button"><?php esc_html_e( 'Удалить выбранные', 'polygraph-calculator' ); ?></button></p>
		</form>
		<?php
		echo '</div>';
	}

	/* ---------- Ресурсы ---------- */

	public static function enqueue( $hook = '' ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $hook && false === strpos( (string) $hook, 'pgcalc' ) ) {
			return;
		}
		wp_enqueue_style( 'pgcalc-admin', PGCALC_PLUGIN_URL . 'assets/css/admin.css', array(), PGCALC_VERSION );
		wp_enqueue_script( 'pgcalc-admin', PGCALC_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), PGCALC_VERSION, true );
		wp_localize_script(
			'pgcalc-admin',
			'PGCALC_ADMIN',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'polygraph-calculator/v1/' ) ),
				'ajaxUrl'  => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'adminNonce' => wp_create_nonce( 'pgcalc_admin_rest' ),
				'ajaxNonce'  => wp_create_nonce( 'pgcalc_admin_ajax' ),
				'i18n'     => array(
					'initial'  => PGCALC_DIALOG::initial_question(),
					'sending'  => __( '…', 'polygraph-calculator' ),
					'error'    => __( 'Ошибка соединения', 'polygraph-calculator' ),
					'pingOk'   => __( '✅ API доступен', 'polygraph-calculator' ),
					'pingFail' => __( '❌ Ошибка: ', 'polygraph-calculator' ),
					'pingSend' => __( 'Проверяю…', 'polygraph-calculator' ),
				),
			)
		);
	}
}
