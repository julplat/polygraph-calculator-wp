<?php
/**
 * Движок расчёта — точный порт utils/calculator.ts + knowledgeBase.ts.
 * Все деньги внутри считаются в копейках (int).
 *
 * @package polygraph-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PGCALC_ENGINE {

	/**
	 * Расчёт заказа.
	 *
	 * @param array $params type|size{width,height}|quantity|materialId|printing|creasing|creasingCount
	 * @return array {result: array|null, error: array|null}
	 */
	public static function calculate( $params ) {
		$type       = isset( $params['type'] ) ? $params['type'] : null;
		$size       = isset( $params['size'] ) ? $params['size'] : null;
		$qty        = isset( $params['quantity'] ) ? (int) $params['quantity'] : 0;
		$materialId = isset( $params['materialId'] ) ? $params['materialId'] : null;
		$printing   = isset( $params['printing'] ) ? $params['printing'] : null;

		if ( ! in_array( $type, array( 'flyer', 'booklet' ), true ) || ! $size || $qty <= 0 || ! $materialId || ! in_array( $printing, array( '4+0', '4+4' ), true ) ) {
			return array(
				'result' => null,
				'error'  => array(
					'message'     => __( 'Не все параметры заполнены', 'polygraph-calculator' ),
					'needsManager' => false,
				),
			);
		}

		$materials = PGCALC_DB::get_materials();
		$material  = null;
		foreach ( $materials as $m ) {
			if ( $m->id === $materialId ) {
				$material = $m;
				break;
			}
		}
		if ( ! $material ) {
			return array(
				'result' => null,
				'error'  => array(
					'message'      => __( 'Материал не найден в таблице. Передайте расчёт менеджеру.', 'polygraph-calculator' ),
					'needsManager' => true,
				),
			);
		}

		$itemW = (int) $size['width'];
		$itemH = (int) $size['height'];
		$sheetW = (int) $material->sheet_w;
		$sheetH = (int) $material->sheet_h;

		// Шаг 0: раскладка k
		$k = 0;
		$std = self::find_standard_size( $itemW, $itemH );
		if ( $std ) {
			$k = self::layout_for_standard( $std, $sheetW, $sheetH );
		}
		if ( $k <= 0 ) {
			$k = self::calculate_layout( $itemW, $itemH, $sheetW, $sheetH );
		}
		if ( $k <= 0 ) {
			return array(
				'result' => null,
				'error'  => array(
					'message'      => __( 'Изделие больше печатного листа или раскладка невозможна. Передайте расчёт менеджеру.', 'polygraph-calculator' ),
					'needsManager' => true,
				),
			);
		}

		$N      = (int) ceil( $qty / $k );
		$Nplus2 = $N + 2;

		// Шаг 1: материал (цена за лист, копейки)
		$matPriceKop = self::get_material_price_kopecks( $material, $Nplus2 );
		$materialCostKop = $Nplus2 * $matPriceKop;

		// Шаг 2: печать
		$printSetupKop = (int) round( (float) get_option( 'pgcalc_setup_print', 700 ) * 100 );
		if ( '4+0' === $printing ) {
			$printTariffKop = self::get_tariff_kopecks( 'print', $N );
			$printCostKop   = $printSetupKop + $N * $printTariffKop;
		} else {
			$printTariffKop = self::get_tariff_kopecks( 'print', 2 * $N );
			$printCostKop   = $printSetupKop + $N * $printTariffKop + $N * $printTariffKop;
		}

		// Шаг 3: резка
		$cutSetupKop  = (int) round( (float) get_option( 'pgcalc_setup_cut', 200 ) * 100 );
		$cutTariffKop = self::get_tariff_kopecks( 'cut', $qty );
		$cutCostKop   = $cutSetupKop + $qty * $cutTariffKop;

		// Шаг 4: биговка (только буклет)
		$creasingCostKop   = 0;
		$creasingTariffKop = 0;
		$creasing          = ! empty( $params['creasing'] );
		$creasingCount     = isset( $params['creasingCount'] ) ? (int) $params['creasingCount'] : 0;
		if ( 'booklet' === $type && $creasing ) {
			$creasingTariffKop = self::get_tariff_kopecks( 'crease', $qty );
			$numCreases        = $creasingCount > 0 ? $creasingCount : 2;
			$creasingCostKop   = $qty * $creasingTariffKop * $numCreases;
		}

		$totalKop = $materialCostKop + $printCostKop + $cutCostKop + $creasingCostKop;

		return array(
			'result' => array(
				'k'                 => $k,
				'N'                 => $N,
				'Nplus2'            => $Nplus2,
				'Q'                 => $qty,
				'materialCost'      => self::fmt( $materialCostKop ),
				'materialPricePerSheet' => self::fmt( $matPriceKop ),
				'materialRange'     => self::material_range( $Nplus2 ),
				'printCost'         => self::fmt( $printCostKop ),
				'printTariff'       => self::fmt( $printTariffKop ),
				'printSetup'        => self::fmt( $printSetupKop ),
				'cutCost'           => self::fmt( $cutCostKop ),
				'cutTariff'         => self::fmt( $cutTariffKop ),
				'cutSetup'          => self::fmt( $cutSetupKop ),
				'creasingCost'      => self::fmt( $creasingCostKop ),
				'creasingTariff'    => self::fmt( $creasingTariffKop ),
				'total'             => self::fmt( $totalKop ),
				'totalRaw'          => $totalKop / 100,
			),
			'error'  => null,
		);
	}

	private static function fmt( $kopecks ) {
		return round( $kopecks / 100, 2 );
	}

	private static function material_range( $n ) {
		if ( $n <= 39 ) { return '0–39'; }
		if ( $n <= 89 ) { return '40–89'; }
		if ( $n <= 149 ) { return '90–149'; }
		if ( $n <= 4000 ) { return '150–4000'; }
		if ( $n <= 6500 ) { return '4001–6500'; }
		return '6501+';
	}

	private static function get_material_price_kopecks( $material, $nPlus2 ) {
		if ( $nPlus2 <= 39 ) { return (int) $material->p0; }
		if ( $nPlus2 <= 89 ) { return (int) $material->p40; }
		if ( $nPlus2 <= 149 ) { return (int) $material->p90; }
		if ( $nPlus2 <= 4000 ) { return (int) $material->p150; }
		if ( $nPlus2 <= 6500 ) { return (int) $material->p4001; }
		return (int) $material->p6501;
	}

	/** Возвращает тариф в копейках по значению qty. */
	public static function get_tariff_kopecks( $kind, $qty ) {
		$rows = PGCALC_DB::get_tariffs( $kind );
		foreach ( $rows as $t ) {
			if ( $qty >= (int) $t->min_qty && $qty <= (int) $t->max_qty ) {
				return (int) round( (float) $t->price * 100 );
			}
		}
		if ( $rows ) {
			$last = $rows[ count( $rows ) - 1 ];
			return (int) round( (float) $last->price * 100 );
		}
		return 0;
	}

	private static function find_standard_size( $w, $h ) {
		foreach ( PGCALC_DB::get_sizes() as $s ) {
			if ( ( (int) $s->w === $w && (int) $s->h === $h ) || ( (int) $s->w === $h && (int) $s->h === $w ) ) {
				return $s;
			}
		}
		return null;
	}

	private static function layout_for_standard( $std, $sheetW, $sheetH ) {
		if ( 450 === $sheetW && 320 === $sheetH ) { return (int) $std->k450x320; }
		if ( 310 === $sheetW && 470 === $sheetH ) { return (int) $std->k310x470; }
		if ( 430 === $sheetW && 305 === $sheetH ) { return (int) $std->k430x305; }
		return 0;
	}

	private static function calculate_layout( $itemW, $itemH, $sheetW, $sheetH ) {
		if ( $itemW <= 0 || $itemH <= 0 ) { return 0; }
		$l1 = (int) floor( $sheetW / $itemW ) * (int) floor( $sheetH / $itemH );
		$l2 = (int) floor( $sheetW / $itemH ) * (int) floor( $sheetH / $itemW );
		return max( $l1, $l2 );
	}

	/** Отображаемое имя материала как в оригинале. */
	public static function material_display_name( $materialId ) {
		foreach ( PGCALC_DB::get_materials() as $m ) {
			if ( $m->id === $materialId ) {
				if ( preg_match( '/(\d+)\s*г\s*(.*)/u', $m->name, $match ) ) {
					return 'бумага ' . $match[1] . ' г ' . mb_strtolower( trim( $match[2] ) );
				}
				if ( false !== mb_stripos( $m->name, 'Дизайнерский' ) ) { return 'дизайнерский картон 290 г'; }
				if ( false !== mb_stripos( $m->name, 'Самоклейка с просечкой' ) ) { return 'самоклейка с просечкой'; }
				if ( false !== mb_stripos( $m->name, 'Самоклейка без просечки' ) ) { return 'самоклейка без просечки'; }
				return $m->name;
			}
		}
		return __( 'Неизвестный материал', 'polygraph-calculator' );
	}

	public static function format_price( $value ) {
		$s = number_format( (float) $value, 2, '.', ' ' );
		return rtrim( rtrim( $s, '0' ), '.' ) . ' ₽';
	}

	/** Автотесты из оригинала (verifyExamples). */
	public static function verify_examples() {
		$cases = array(
			array(
				'params'   => array( 'type' => 'flyer', 'size' => array( 'width' => 105, 'height' => 148 ), 'quantity' => 100, 'materialId' => 'paper-120', 'printing' => '4+0', 'creasing' => false, 'creasingCount' => 0 ),
				'expected' => 1672.12,
			),
			array(
				'params'   => array( 'type' => 'flyer', 'size' => array( 'width' => 148, 'height' => 210 ), 'quantity' => 1000, 'materialId' => 'paper-105', 'printing' => '4+4', 'creasing' => false, 'creasingCount' => 0 ),
				'expected' => 13172.32,
			),
			array(
				'params'   => array( 'type' => 'booklet', 'size' => array( 'width' => 210, 'height' => 297 ), 'quantity' => 500, 'materialId' => 'paper-120', 'printing' => '4+4', 'creasing' => true, 'creasingCount' => 1 ),
				'expected' => 13991.44,
			),
		);
		$out = array();
		foreach ( $cases as $i => $c ) {
			$res   = self::calculate( $c['params'] );
			$total = $res['result'] ? $res['result']['totalRaw'] : null;
			$out[] = array(
				'name'     => 'Пример ' . ( $i + 1 ),
				'expected' => $c['expected'],
				'actual'   => $total,
				'passed'   => ( null !== $total && abs( $total - $c['expected'] ) < 0.01 ),
			);
		}
		return $out;
	}
}
