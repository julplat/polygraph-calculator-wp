<?php
/**
 * Хранилище базы знаний и расчёт движка (порт калькулятора Polygraph).
 *
 * Таблицы:
 *  {prefix}pg_materials   — материалы (лист, ценовые диапазоны)
 *  {prefix}pg_sizes       — стандартные размеры и раскладки k
 *  {prefix}pg_tariffs     — тарифы печати/резки/биговки по диапазонам
 *  {prefix}pg_history     — история расчётов (диалогов)
 *
 * @package polygraph-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PGCALC_DB {

	const DB_VERSION = '1.0';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'pg_' . $name;
	}

	public static function maybe_install() {
		if ( get_option( 'pgcalc_db_version' ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$m = self::table( 'materials' );
		dbDelta( "CREATE TABLE {$m} (
			id VARCHAR(64) NOT NULL,
			name VARCHAR(191) NOT NULL DEFAULT '',
			description VARCHAR(191) NOT NULL DEFAULT '',
			sheet_w INT NOT NULL DEFAULT 0,
			sheet_h INT NOT NULL DEFAULT 0,
			p0 INT NOT NULL DEFAULT 0,
			p40 INT NOT NULL DEFAULT 0,
			p90 INT NOT NULL DEFAULT 0,
			p150 INT NOT NULL DEFAULT 0,
			p4001 INT NOT NULL DEFAULT 0,
			p6501 INT NOT NULL DEFAULT 0,
			sort_order INT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id)
		) {$charset};" );

		$s = self::table( 'sizes' );
		dbDelta( "CREATE TABLE {$s} (
			id BIGINT NOT NULL AUTO_INCREMENT,
			name VARCHAR(64) NOT NULL DEFAULT '',
			w INT NOT NULL DEFAULT 0,
			h INT NOT NULL DEFAULT 0,
			k450x320 INT NOT NULL DEFAULT 0,
			k310x470 INT NOT NULL DEFAULT 0,
			k430x305 INT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id)
		) {$charset};" );

		$t = self::table( 'tariffs' );
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT NOT NULL AUTO_INCREMENT,
			kind VARCHAR(16) NOT NULL,
			min_qty INT NOT NULL DEFAULT 0,
			max_qty INT NOT NULL DEFAULT 0,
			price DECIMAL(10,2) NOT NULL DEFAULT 0,
			sort_order INT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY kind (kind)
		) {$charset};" );

		$h = self::table( 'history' );
		dbDelta( "CREATE TABLE {$h} (
			id BIGINT NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			client_name VARCHAR(191) NOT NULL DEFAULT '',
			client_phone VARCHAR(64) NOT NULL DEFAULT '',
			params LONGTEXT NULL,
			result LONGTEXT NULL,
			PRIMARY KEY  (id)
		) {$charset};" );

		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m}" ) ) { // phpcs:ignore
			self::seed();
		}

		update_option( 'pgcalc_db_version', self::DB_VERSION );
	}

	/** Цены хранятся в копейках (инты) — точность до 0,01 ₽ без float-погрешностей. */
	private static function mat( $id, $name, $desc, $sw, $sh, $p ) {
		return array(
			'id'          => $id,
			'name'        => $name,
			'description' => $desc,
			'sheet_w'     => $sw,
			'sheet_h'     => $sh,
			'p0'          => $p[0],
			'p40'         => $p[1],
			'p90'         => $p[2],
			'p150'        => $p[3],
			'p4001'       => $p[4],
			'p6501'       => $p[5],
		);
	}

	public static function seed() {
		global $wpdb;

		$materials = array(
			self::mat( 'designer-290', 'Дизайнерский картон 290 г', 'HPG VIVID TOUCH, матовый с двух сторон', 450, 320, array( 21428, 17142, 15428, 14571, 13285, 12685 ) ),
			self::mat( 'paper-105', 'Бумага 105 г матовая', 'SNOW EAGLE SILK', 450, 320, array( 538, 430, 387, 366, 333, 318 ) ),
			self::mat( 'paper-120', 'Бумага 120 г матовая', 'SNOW EAGLE SILK', 450, 320, array( 658, 526, 473, 447, 408, 389 ) ),
			self::mat( 'paper-140', 'Бумага 140 г матовая', 'SNOW EAGLE SILK', 450, 320, array( 768, 615, 553, 522, 476, 455 ) ),
			self::mat( 'paper-200', 'Бумага 200 г матовая', 'SNOW EAGLE SILK', 450, 320, array( 1098, 878, 790, 746, 680, 650 ) ),
			self::mat( 'paper-250', 'Бумага 250 г матовая', 'C2S MATT ART PAPER', 450, 320, array( 1270, 1016, 914, 864, 787, 752 ) ),
			self::mat( 'paper-300', 'Бумага 300 г матовая', 'SNOW EAGLE SILK', 310, 470, array( 1645, 1316, 1184, 1119, 1020, 974 ) ),
			self::mat( 'sticker-cut', 'Самоклейка с просечкой', 'ADESTOR GLOSS / SUPERPERMANENT KRIS LINE', 430, 305, array( 3448, 2758, 2482, 2344, 2137, 2041 ) ),
			self::mat( 'sticker-nocut', 'Самоклейка без просечки', 'NOVITA GLOSSY / A300 / CCK80 SOLID', 430, 305, array( 2563, 2050, 1845, 1743, 1589, 1517 ) ),
		);

		$wpdb->query( 'DELETE FROM ' . self::table( 'materials' ) ); // phpcs:ignore
		$i = 0;
		foreach ( $materials as $m ) {
			$m['sort_order'] = $i++;
			$wpdb->insert( self::table( 'materials' ), $m ); // phpcs:ignore
		}

		$sizes = array(
			array( 'name' => 'А6', 'w' => 105, 'h' => 148, 'k450x320' => 9, 'k310x470' => 8, 'k430x305' => 8 ),
			array( 'name' => 'А5', 'w' => 148, 'h' => 210, 'k450x320' => 4, 'k310x470' => 4, 'k430x305' => 4 ),
			array( 'name' => 'А4', 'w' => 210, 'h' => 297, 'k450x320' => 2, 'k310x470' => 2, 'k430x305' => 2 ),
			array( 'name' => 'А3', 'w' => 297, 'h' => 420, 'k450x320' => 1, 'k310x470' => 1, 'k430x305' => 1 ),
			array( 'name' => 'Евро', 'w' => 100, 'h' => 210, 'k450x320' => 6, 'k310x470' => 6, 'k430x305' => 6 ),
		);
		$wpdb->query( 'DELETE FROM ' . self::table( 'sizes' ) ); // phpcs:ignore
		foreach ( $sizes as $s ) {
			$wpdb->insert( self::table( 'sizes' ), $s ); // phpcs:ignore
		}

		$tariffs = array_merge(
			self::trows( 'print', array(
				array( 1, 5, 8000 ), array( 6, 10, 6000 ), array( 11, 24, 5000 ), array( 25, 49, 4500 ),
				array( 50, 99, 4000 ), array( 100, 150, 3500 ), array( 151, 199, 3000 ), array( 200, 299, 2700 ),
				array( 300, 400, 2500 ), array( 401, 499, 2300 ), array( 500, 999, 2150 ), array( 1000, 1499, 2074 ),
				array( 1500, 100000000, 1800 ),
			) ),
			self::trows( 'cut', array(
				array( 1, 50, 100 ), array( 51, 100, 80 ), array( 101, 1000, 60 ),
				array( 1001, 2000, 50 ), array( 2001, 5000, 20 ), array( 5001, 100000000, 15 ),
			) ),
			self::trows( 'crease', array(
				array( 1, 4000, 183 ), array( 4001, 100000000, 100 ),
			) )
		);
		$wpdb->query( 'DELETE FROM ' . self::table( 'tariffs' ) ); // phpcs:ignore
		foreach ( $tariffs as $t ) {
			$wpdb->insert( self::table( 'tariffs' ), $t ); // phpcs:ignore
		}
	}

	private static function trows( $kind, $rows ) {
		$out = array();
		$i   = 0;
		foreach ( $rows as $r ) {
			$out[] = array(
				'kind'      => $kind,
				'min_qty'   => $r[0],
				'max_qty'   => $r[1],
				'price'     => $r[2] / 100,
				'sort_order' => $i++,
			);
		}
		return $out;
	}

	/* ---------- Чтение ---------- */

	public static function get_materials() {
		global $wpdb;
		$t = self::table( 'materials' );
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY sort_order ASC" ); // phpcs:ignore
	}

	public static function get_sizes() {
		global $wpdb;
		$t = self::table( 'sizes' );
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id ASC" ); // phpcs:ignore
	}

	public static function get_tariffs( $kind ) {
		global $wpdb;
		$t    = self::table( 'tariffs' );
		$kind = sanitize_key( $kind );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE kind = %s ORDER BY sort_order ASC", $kind ) ); // phpcs:ignore
	}

	/* ---------- Запись ---------- */

	public static function save_material( $row, $old_id = null ) {
		global $wpdb;
		$data = array(
			'id'          => sanitize_title_with_dashes( rawurldecode( $row['id'] ) ),
			'name'        => sanitize_text_field( $row['name'] ),
			'description' => sanitize_text_field( $row['description'] ),
			'sheet_w'     => max( 0, (int) $row['sheet_w'] ),
			'sheet_h'     => max( 0, (int) $row['sheet_h'] ),
			'p0'          => self::to_kopecks( $row['p0'] ),
			'p40'         => self::to_kopecks( $row['p40'] ),
			'p90'         => self::to_kopecks( $row['p90'] ),
			'p150'        => self::to_kopecks( $row['p150'] ),
			'p4001'       => self::to_kopecks( $row['p4001'] ),
			'p6501'       => self::to_kopecks( $row['p6501'] ),
			'sort_order'  => (int) $row['sort_order'],
		);
		if ( '' === $data['id'] ) {
			$data['id'] = 'mat-' . wp_generate_password( 6, false, false );
		}
		$t = self::table( 'materials' );
		if ( $old_id && $old_id !== $data['id'] ) {
			$wpdb->delete( $t, array( 'id' => $old_id ) ); // phpcs:ignore
		}
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE id = %s", $data['id'] ) ); // phpcs:ignore
		if ( $exists ) {
			$wpdb->update( $t, $data, array( 'id' => $data['id'] ) ); // phpcs:ignore
		} else {
			$wpdb->insert( $t, $data ); // phpcs:ignore
		}
		return $data['id'];
	}

	public static function delete_material( $id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'materials' ), array( 'id' => sanitize_title_with_dashes( $id ) ) ); // phpcs:ignore
	}

	public static function save_size( $row, $old_id = null ) {
		global $wpdb;
		$data = array(
			'name'      => sanitize_text_field( $row['name'] ),
			'w'         => max( 0, (int) $row['w'] ),
			'h'         => max( 0, (int) $row['h'] ),
			'k450x320'  => max( 0, (int) $row['k450x320'] ),
			'k310x470'  => max( 0, (int) $row['k310x470'] ),
			'k430x305'  => max( 0, (int) $row['k430x305'] ),
		);
		$t = self::table( 'sizes' );
		if ( $old_id ) {
			$wpdb->update( $t, $data, array( 'id' => (int) $old_id ) ); // phpcs:ignore
		} else {
			$wpdb->insert( $t, $data ); // phpcs:ignore
		}
	}

	public static function delete_size( $id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'sizes' ), array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	public static function save_tariff( $kind, $row, $old_id = null ) {
		global $wpdb;
		$data = array(
			'kind'      => sanitize_key( $kind ),
			'min_qty'   => max( 0, (int) $row['min_qty'] ),
			'max_qty'   => (int) $row['max_qty'] > 0 ? (int) $row['max_qty'] : 100000000,
			'price'     => self::to_kopecks( $row['price'] ) / 100,
			'sort_order' => (int) $row['sort_order'],
		);
		$t = self::table( 'tariffs' );
		if ( $old_id ) {
			$wpdb->update( $t, $data, array( 'id' => (int) $old_id ) ); // phpcs:ignore
		} else {
			$wpdb->insert( $t, $data ); // phpcs:ignore
		}
	}

	public static function delete_tariff( $id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'tariffs' ), array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	public static function reset_to_defaults() {
		self::seed();
	}

	private static function to_kopecks( $v ) {
		$v = str_replace( array( ' ', ',', '₽', 'руб' ), array( '', '.', '', '' ), trim( (string) $v ) );
		return (int) round( (float) $v * 100 );
	}

	public static function add_history( $params, $result, $client_name = '', $client_phone = '' ) {
		global $wpdb;
		$wpdb->insert( self::table( 'history' ), array( // phpcs:ignore
			'created_at'   => current_time( 'mysql' ),
			'client_name'  => sanitize_text_field( $client_name ),
			'client_phone' => sanitize_text_field( $client_phone ),
			'params'       => wp_json_encode( $params, JSON_UNESCAPED_UNICODE ),
			'result'       => wp_json_encode( $result, JSON_UNESCAPED_UNICODE ),
		) );
		return $wpdb->insert_id;
	}

	public static function get_history( $limit = 100 ) {
		global $wpdb;
		$t     = self::table( 'history' );
		$limit = max( 1, min( 500, (int) $limit ) );
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id DESC LIMIT {$limit}" ); // phpcs:ignore
	}

	public static function delete_history( $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( ! $ids ) {
			return;
		}
		$t = self::table( 'history' );
		$wpdb->query( "DELETE FROM {$t} WHERE id IN (" . implode( ',', $ids ) . ")" ); // phpcs:ignore
	}

	public static function clear_history() {
		global $wpdb;
		$t = self::table( 'history' );
		$wpdb->query( "TRUNCATE TABLE {$t}" ); // phpcs:ignore
	}
}
