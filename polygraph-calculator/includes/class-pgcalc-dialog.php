<?php
/**
 * Диалог сбора параметров — порт utils/chatLogic.ts (rule-based парсер).
 *
 * @package polygraph-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PGCALC_DIALOG {

	public static function initial_question() {
		return __( 'Опишите заказ: тип (листовка/буклет), размер, количество, материал, печать (4+0/4+4). Для буклета — нужна ли биговка и сколько сгибов.', 'polygraph-calculator' );
	}

	public static function initial_params() {
		return array(
			'type'          => null,
			'size'          => null,
			'sizeName'      => null,
			'quantity'      => null,
			'materialId'    => null,
			'printing'      => null,
			'creasing'      => false,
			'creasingCount' => 0,
		);
	}

	/**
	 * Обработка сообщения пользователя.
	 *
	 * @param array  $params текущие собранные параметры
	 * @param string $input  текст пользователя
	 * @return array {step: collecting|complete|need_manager, params, question}
	 */
	public static function process( $params, $input ) {
		$text = mb_strtolower( trim( $input ) );

		// Передача менеджеру для нестандартных изделий
		foreach ( array( 'визитк', 'календар', 'каталог', 'блокнот', 'стикер' ) as $kw ) {
			if ( false !== mb_strpos( $text, $kw ) ) {
				return array(
					'step'     => 'need_manager',
					'params'   => $params,
					'question' => __( 'Это изделие не листовка и не буклет. Точный расчёт лучше доверить менеджеру.', 'polygraph-calculator' ),
				);
			}
		}

		$params = self::parse_message( $text, $params );

		$missing = array();
		if ( ! $params['type'] ) { $missing[] = __( 'тип (листовка/буклет)', 'polygraph-calculator' ); }
		if ( ! $params['size'] ) { $missing[] = __( 'размер', 'polygraph-calculator' ); }
		if ( null === $params['quantity'] ) { $missing[] = __( 'количество', 'polygraph-calculator' ); }
		if ( ! $params['materialId'] ) { $missing[] = __( 'материал', 'polygraph-calculator' ); }
		if ( ! $params['printing'] ) { $missing[] = __( 'печать (4+0/4+4)', 'polygraph-calculator' ); }

		// Буклет: уточнить биговку
		if ( 'booklet' === $params['type'] && $params['printing'] && empty( $missing ) ) {
			$creaseWords = array( 'биговк', 'сгиб', 'фальц' );
			$hasCreaseWord = false;
			foreach ( $creaseWords as $w ) {
				if ( false !== mb_strpos( $text, $w ) ) { $hasCreaseWord = true; break; }
			}
			if ( ! $hasCreaseWord ) {
				return array(
					'step'     => 'collecting',
					'params'   => $params,
					'question' => __( 'Для буклета: нужна ли биговка? Если да, сколько сгибов (обычно 2 для евробуклета)?', 'polygraph-calculator' ),
				);
			}
			if ( $params['creasing'] && 0 === $params['creasingCount'] ) {
				return array(
					'step'     => 'collecting',
					'params'   => $params,
					'question' => __( 'Сколько биговок (сгибов) нужно? Обычно 2 для евробуклета.', 'polygraph-calculator' ),
				);
			}
		}

		if ( ! empty( $missing ) ) {
			return array(
				'step'     => 'collecting',
				'params'   => $params,
				'question' => sprintf( __( 'Укажите ещё: %s.', 'polygraph-calculator' ), implode( ', ', $missing ) ),
			);
		}

		return array(
			'step'     => 'complete',
			'params'   => $params,
			'question' => '__CALCULATE__',
		);
	}

	private static function parse_message( $lower, $params ) {
		// Тип
		if ( false !== mb_strpos( $lower, 'листовк' ) || false !== mb_strpos( $lower, 'флаер' ) || false !== strpos( $lower, 'flyer' ) ) {
			$params['type'] = 'flyer';
		} elseif ( false !== mb_strpos( $lower, 'буклет' ) || false !== strpos( $lower, 'booklet' ) ) {
			$params['type'] = 'booklet';
		}

		// Размер: стандартные
		$sizes       = PGCALC_DB::get_sizes();
		$sizeFound   = false;
		$normalized  = self::normalize_for_size( $lower );
		foreach ( $sizes as $s ) {
			$name = $s->name; // например "А6"
			$letter = mb_substr( $name, 0, 1 );
			$digit  = mb_substr( $name, 1, 1 );
			$lc     = mb_strtolower( $letter );
			$charClass = '[аa]'; // по умолчанию
			if ( 'а' === $lc ) { $charClass = '[аa]'; }
			elseif ( 'е' === $lc ) { $charClass = '[еe]'; }
			else { $charClass = '[' . preg_quote( $lc, '/' ) . ']'; }

			$pattern = $charClass . preg_quote( $digit, '/' );
			$w = (int) $s->w;
			$h = (int) $s->h;

			$dimRe = '/(\D|^)' . $w . '\s*[xх×]\s*' . $h . '(\D|$)|(\D|^)' . $h . '\s*[xх×]\s*' . $w . '(\D|$)/u';
			$nameRe = '/(\D|^)' . $pattern . '(\D|$)/u';
			$fmtRe  = '/(размер|формат)\s*[:#]?\s*' . $pattern . '/u';

			if ( preg_match( $nameRe, $normalized ) || preg_match( $fmtRe, $normalized ) || preg_match( $dimRe, $lower ) ) {
				$params['size']     = array( 'width' => $w, 'height' => $h );
				$params['sizeName'] = $name;
				$sizeFound          = true;
				break;
			}
		}

		// Нестандартный размер "55x20"
		if ( ! $sizeFound && preg_match( '/(\d+)\s*(?:[xх×*]|на)\s*(\d+)/u', $lower, $m ) ) {
			$w = (int) $m[1];
			$h = (int) $m[2];
			if ( $w > 0 && $h > 0 && $w <= 600 && $h <= 600 ) {
				$params['size'] = array( 'width' => $w, 'height' => $h );
				$params['sizeName'] = null;
				foreach ( $sizes as $s ) {
					if ( ( (int) $s->w === $w && (int) $s->h === $h ) || ( (int) $s->w === $h && (int) $s->h === $w ) ) {
						$params['sizeName'] = $s->name;
						break;
					}
				}
				if ( ! $params['sizeName'] ) {
					$params['sizeName'] = $w . '×' . $h;
				}
			}
		}

		// Количество
		$qty = self::detect_quantity( $lower );
		if ( null !== $qty ) {
			$params['quantity'] = $qty;
		}

		// Материал. Как в оригинале: ищем по «нормализованному» тексту
		// (латиница → кириллица), иначе латинская «a» внутри слов вроде
		// «флаеры a5» склеивается с цифрой («a5») и ложно детектируется вес 200.
		$mat = self::detect_material( self::normalize_for_size( $lower ) );
		if ( $mat ) {
			$params['materialId'] = $mat;
		}

		// Печать
		$oneSide = array( '4+0', 'односторон', 'одна сторон', 'одной сторон', 'с одной' );
		$twoSide = array( '4+4', 'двусторон', 'две сторон', 'двух сторон', 'с двух', 'с обеих' );
		foreach ( $oneSide as $kw ) {
			if ( false !== mb_strpos( $lower, $kw ) ) { $params['printing'] = '4+0'; break; }
		}
		foreach ( $twoSide as $kw ) {
			if ( false !== mb_strpos( $lower, $kw ) ) { $params['printing'] = '4+4'; break; }
		}

		// Биговка для буклета
		if ( 'booklet' === $params['type'] ) {
			$creaseWords = array( 'биговк', 'сгиб', 'фальц' );
			$has = false;
			foreach ( $creaseWords as $w ) {
				if ( false !== mb_strpos( $lower, $w ) ) { $has = true; break; }
			}
			if ( $has ) {
				$neg = array( 'без', 'не нужн', 'не надо' );
				$isNeg = false;
				foreach ( $neg as $n ) {
					if ( false !== mb_strpos( $lower, $n ) ) { $isNeg = true; break; }
				}
				if ( $isNeg ) {
					$params['creasing']      = false;
					$params['creasingCount'] = 0;
				} else {
					$params['creasing'] = true;
					if ( preg_match( '/(\d+)\s*(?:биговк|сгиб|фальц)/u', $lower, $m ) ) {
						$params['creasingCount'] = (int) $m[1];
					} else {
						$params['creasingCount'] = 2;
					}
				}
			}
		}

		return $params;
	}

	private static function detect_quantity( $lower ) {
		if ( preg_match( '/(\d+)\s*(?:шт|штук|экз|экземпляр)/u', $lower, $m ) ) { return (int) $m[1]; }
		if ( preg_match( '/тираж\s*[:#]?\s*(\d+)/u', $lower, $m ) ) { return (int) $m[1]; }
		if ( preg_match( '/(\d+)\s*(?:колич|кол-во)/u', $lower, $m ) ) { return (int) $m[1]; }
		return null;
	}

	private static function detect_material( $lower ) {
		// Вес с единицей
		$weights = array(
			105 => 'paper-105', 120 => 'paper-120', 140 => 'paper-140',
			200 => 'paper-200', 250 => 'paper-250', 300 => 'paper-300', 290 => 'designer-290',
		);
		foreach ( $weights as $w => $id ) {
			if ( preg_match( '/\b' . $w . '\s*(?:г|грамм)/u', $lower ) ) { return $id; }
		}
		// Вес в контексте слова «бумага/картон»
		foreach ( $weights as $w => $id ) {
			if ( preg_match( '/(?:бумаг|картон|матов|гллянц)[^0-9]*\b' . $w . '\b/u', $lower ) ) { return $id; }
		}
		// Самоклейка
		if ( false !== mb_strpos( $lower, 'самоклейк' ) ) {
			return ( false !== mb_strpos( $lower, 'просечк' ) ) ? 'sticker-cut' : 'sticker-nocut';
		}
		// По полному имени материала из БЗ
		foreach ( PGCALC_DB::get_materials() as $m ) {
			if ( false !== mb_strpos( $lower, mb_strtolower( $m->name ) ) ) { return $m->id; }
		}
		// Номер "№4" / "материал 4". В оригинальном JS regex срабатывал только на
		// первой цифре текста (обычно это размер «а5»), поэтому номер материала
		// реально определялся только когда в тексте нет других цифр. Сохраняем
		// то же поведение: ищем ЦИФРУ, перед которой нет другой цифры/«№»,
		// иначе «250» ложно давало бы материал №2.
		$materials = PGCALC_DB::get_materials();
		if ( preg_match( '/(?<![0-9№])\s*(\d)(?![0-9])/u', $lower, $m ) ) {
			$idx = (int) $m[1] - 1;
			if ( $idx >= 0 && $idx < count( $materials ) ) {
				if ( false !== mb_strpos( $lower, 'материал' ) || false !== mb_strpos( $lower, 'бумаг' ) || false !== mb_strpos( $lower, 'картон' ) || false !== mb_strpos( $lower, '№' ) ) {
					return $materials[ $idx ]->id;
				}
			}
		}
		return null;
	}

	/** Латиница → кириллица для совпадения имён размеров. */
	private static function normalize_for_size( $text ) {
		$map = array(
			'a' => 'а', 'b' => 'в', 'c' => 'с', 'e' => 'е', 'o' => 'о', 'p' => 'р', 'x' => 'х',
			'A' => 'а', 'B' => 'в', 'C' => 'с', 'E' => 'е', 'O' => 'о', 'P' => 'р', 'X' => 'х',
		);
		return strtr( $text, $map );
	}
}
