<?php
/**
 * Unit-тест движка расчёта и диалога вне WordPress (моки функций и БД).
 * Запуск: php tests/run-tests.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['pgcalc_options'] = array(
	'pgcalc_setup_print' => 700,
	'pgcalc_setup_cut'   => 200,
);

function get_option( $name, $default = false ) {
	return isset( $GLOBALS['pgcalc_options'][ $name ] ) ? $GLOBALS['pgcalc_options'][ $name ] : $default;
}
function __( $text, $domain = 'default' ) { return $text; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) ); }
function sanitize_title_with_dashes( $v ) { return strtolower( trim( preg_replace( '/[^a-z0-9_\-\x80-\xff]+/iu', '-', (string) $v ), '-' ) ); }
function mb_stripos_h( $h, $n ) { return mb_stripos( $h, $n ); }

/* Мок-класс БД с теми же данными, что и seed(). */
class PGCALC_DB {
	private static function m( $id, $name, $desc, $sw, $sh, $p ) {
		$o = new stdClass();
		$o->id = $id; $o->name = $name; $o->description = $desc;
		$o->sheet_w = $sw; $o->sheet_h = $sh;
		$o->p0 = $p[0]; $o->p40 = $p[1]; $o->p90 = $p[2]; $o->p150 = $p[3]; $o->p4001 = $p[4]; $o->p6501 = $p[5];
		return $o;
	}
	public static function get_materials() {
		return array(
			self::m( 'designer-290', 'Дизайнерский картон 290 г', 'HPG VIVID TOUCH', 450, 320, array( 21428, 17142, 15428, 14571, 13285, 12685 ) ),
			self::m( 'paper-105', 'Бумага 105 г матовая', 'SNOW EAGLE SILK', 450, 320, array( 538, 430, 387, 366, 333, 318 ) ),
			self::m( 'paper-120', 'Бумага 120 г матовая', 'SNOW EAGLE SILK', 450, 320, array( 658, 526, 473, 447, 408, 389 ) ),
			self::m( 'paper-140', 'Бумага 140 г матовая', 'SNOW EAGLE SILK', 450, 320, array( 768, 615, 553, 522, 476, 455 ) ),
			self::m( 'paper-200', 'Бумага 200 г матовая', 'SNOW EAGLE SILK', 450, 320, array( 1098, 878, 790, 746, 680, 650 ) ),
			self::m( 'paper-250', 'Бумага 250 г матовая', 'C2S MATT ART PAPER', 450, 320, array( 1270, 1016, 914, 864, 787, 752 ) ),
			self::m( 'paper-300', 'Бумага 300 г матовая', 'SNOW EAGLE SILK', 310, 470, array( 1645, 1316, 1184, 1119, 1020, 974 ) ),
			self::m( 'sticker-cut', 'Самоклейка с просечкой', 'ADESTOR GLOSS', 430, 305, array( 3448, 2758, 2482, 2344, 2137, 2041 ) ),
			self::m( 'sticker-nocut', 'Самоклейка без просечки', 'NOVITA GLOSSY', 430, 305, array( 2563, 2050, 1845, 1743, 1589, 1517 ) ),
		);
	}
	public static function get_sizes() {
		$rows = array(
			array( 'А6', 105, 148, 9, 8, 8 ),
			array( 'А5', 148, 210, 4, 4, 4 ),
			array( 'А4', 210, 297, 2, 2, 2 ),
			array( 'А3', 297, 420, 1, 1, 1 ),
			array( 'Евро', 100, 210, 6, 6, 6 ),
		);
		$out = array();
		foreach ( $rows as $r ) {
			$o = new stdClass();
			$o->id = count( $out ) + 1;
			$o->name = $r[0]; $o->w = $r[1]; $o->h = $r[2];
			$o->k450x320 = $r[3]; $o->k310x470 = $r[4]; $o->k430x305 = $r[5];
			$out[] = $o;
		}
		return $out;
	}
	public static function get_tariffs( $kind ) {
		if ( 'print' === $kind ) {
			$rows = array( array( 1, 5, 80 ), array( 6, 10, 60 ), array( 11, 24, 50 ), array( 25, 49, 45 ), array( 50, 99, 40 ), array( 100, 150, 35 ), array( 151, 199, 30 ), array( 200, 299, 27 ), array( 300, 400, 25 ), array( 401, 499, 23 ), array( 500, 999, 21.5 ), array( 1000, 1499, 20.74 ), array( 1500, 100000000, 18 ) );
		} elseif ( 'cut' === $kind ) {
			$rows = array( array( 1, 50, 1 ), array( 51, 100, .8 ), array( 101, 1000, .6 ), array( 1001, 2000, .5 ), array( 2001, 5000, .2 ), array( 5001, 100000000, .15 ) );
		} else {
			$rows = array( array( 1, 4000, 1.83 ), array( 4001, 100000000, 1 ) );
		}
		$out = array();
		foreach ( $rows as $r ) {
			$o = new stdClass();
			$o->min_qty = $r[0]; $o->max_qty = $r[1]; $o->price = $r[2];
			$out[] = $o;
		}
		return $out;
	}
}

require_once __DIR__ . '/../polygraph-calculator/includes/class-pgcalc-engine.php';
require_once __DIR__ . '/../polygraph-calculator/includes/class-pgcalc-dialog.php';

$fail = 0;
$pass = 0;

function check( $name, $cond, $detail = '' ) {
	global $fail, $pass;
	if ( $cond ) {
		$pass++;
		echo "PASS  {$name}\n";
	} else {
		$fail++;
		echo "FAIL  {$name} {$detail}\n";
	}
}

/* --- Тесты движка (ожидаемые значения из verifyExamples оригинала) --- */
$r = PGCALC_ENGINE::calculate( array( 'type' => 'flyer', 'size' => array( 'width' => 105, 'height' => 148 ), 'quantity' => 100, 'materialId' => 'paper-120', 'printing' => '4+0', 'creasing' => false, 'creasingCount' => 0 ) );
check( 'Пример 1: листовка А6 100шт 120г 4+0 = 1672.12', $r['result'] && abs( $r['result']['totalRaw'] - 1672.12 ) < 0.01, json_encode( $r ) );
check( 'Пример 1: k=9, N=12', $r['result'] && 9 === $r['result']['k'] && 12 === $r['result']['N'] );

$r = PGCALC_ENGINE::calculate( array( 'type' => 'flyer', 'size' => array( 'width' => 148, 'height' => 210 ), 'quantity' => 1000, 'materialId' => 'paper-105', 'printing' => '4+4', 'creasing' => false, 'creasingCount' => 0 ) );
check( 'Пример 2: листовка А5 1000шт 105г 4+4 = 13172.32', $r['result'] && abs( $r['result']['totalRaw'] - 13172.32 ) < 0.01, json_encode( $r ) );

$r = PGCALC_ENGINE::calculate( array( 'type' => 'booklet', 'size' => array( 'width' => 210, 'height' => 297 ), 'quantity' => 500, 'materialId' => 'paper-120', 'printing' => '4+4', 'creasing' => true, 'creasingCount' => 1 ) );
check( 'Пример 3: буклет А4 500шт 120г 4+4 биговка×1 = 13991.44', $r['result'] && abs( $r['result']['totalRaw'] - 13991.44 ) < 0.01, json_encode( $r ) );

$r = PGCALC_ENGINE::calculate( array( 'type' => 'flyer', 'size' => array( 'width' => 100, 'height' => 210 ), 'quantity' => 100, 'materialId' => 'paper-120', 'printing' => '4+0', 'creasing' => false, 'creasingCount' => 0 ) );
check( 'Евроформат k=6', $r['result'] && 6 === $r['result']['k'] );

$r = PGCALC_ENGINE::calculate( array( 'type' => 'flyer', 'size' => array( 'width' => 55, 'height' => 20 ), 'quantity' => 100, 'materialId' => 'paper-120', 'printing' => '4+0', 'creasing' => false, 'creasingCount' => 0 ) );
// Оригинальный calculateLayout из knowledgeBase.ts берёт max из двух ориентаций:
// floor(450/55)*floor(320/20)=8*16=128 vs floor(450/20)*floor(320/55)=22*5=110 => 128.
check( 'Нестандарт 55×20: k=max(orientations)=128 (как в оригинале)', $r['result'] && 128 === $r['result']['k'], json_encode( $r['result'] ) );

$r = PGCALC_ENGINE::calculate( array( 'type' => 'flyer', 'size' => array( 'width' => 500, 'height' => 400 ), 'quantity' => 100, 'materialId' => 'paper-120', 'printing' => '4+0', 'creasing' => false, 'creasingCount' => 0 ) );
check( 'Больше листа → needsManager', $r['error'] && $r['error']['needsManager'] );

$tests = PGCALC_ENGINE::verify_examples();
check( 'verify_examples: все 3 примера проходят', 3 === count( array_filter( $tests, function ( $t ) { return $t['passed']; } ) ) );

check( 'material_display_name paper-120', 'бумага 120 г матовая' === PGCALC_ENGINE::material_display_name( 'paper-120' ), PGCALC_ENGINE::material_display_name( 'paper-120' ) );
check( 'format_price 1672.12', '1 672.12 ₽' === PGCALC_ENGINE::format_price( 1672.12 ), PGCALC_ENGINE::format_price( 1672.12 ) );

/* --- Тесты парсера диалога --- */
$p = PGCALC_DIALOG::process( PGCALC_DIALOG::initial_params(), 'Листовки А6, 100 шт, бумага 120 г, печать 4+0' );
check( 'Парсер: полный ввод → complete', 'complete' === $p['step'], json_encode( $p, JSON_UNESCAPED_UNICODE ) );
check( 'Парсер: параметры верны', 'flyer' === $p['params']['type'] && 105 === $p['params']['size']['width'] && 100 === $p['params']['quantity'] && 'paper-120' === $p['params']['materialId'] && '4+0' === $p['params']['printing'] );

$p = PGCALC_DIALOG::process( PGCALC_DIALOG::initial_params(), 'нужны визитки 1000 шт' );
check( 'Парсер: визитки → need_manager', 'need_manager' === $p['step'] );

$p = PGCALC_DIALOG::process( PGCALC_DIALOG::initial_params(), 'евробуклеты 100х210, 500 шт, бумага 200 г, 4+4, биговка 2' );
check( 'Парсер: евробуклет → complete', 'complete' === $p['step'] && 'booklet' === $p['params']['type'] && 2 === $p['params']['creasingCount'], json_encode( $p['params'], JSON_UNESCAPED_UNICODE ) );

$p = PGCALC_DIALOG::process( PGCALC_DIALOG::initial_params(), 'буклеты а4 100 шт бумага 140 г печать 4+0' );
check( 'Парсер: буклет без слова биговка → вопрос про биговку', 'collecting' === $p['step'] && false !== mb_strpos( $p['question'], 'биговк' ), $p['question'] );

$p = PGCALC_DIALOG::process( $p['params'], 'без биговки' );
check( 'Парсер: «без биговки» → complete, creasing=false', 'complete' === $p['step'] && false === $p['params']['creasing'], json_encode( $p, JSON_UNESCAPED_UNICODE ) );

$p = PGCALC_DIALOG::process( PGCALC_DIALOG::initial_params(), 'флаеры a5 250 экз №2 4+4' );
// №2 = второй материал в прайсе оригинала = paper-120 (порядок как в knowledgeBase.ts).
check( 'Парсер: флаеры/a5/экз/№2/4+4', 'complete' === $p['step'] && 'paper-120' === $p['params']['materialId'] && 250 === $p['params']['quantity'], json_encode( $p['params'], JSON_UNESCAPED_UNICODE ) );

echo "\n==== ИТОГО: PASS={$pass} FAIL={$fail} ====\n";
exit( $fail > 0 ? 1 : 0 );
