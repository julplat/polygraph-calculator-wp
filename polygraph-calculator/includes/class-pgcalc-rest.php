<?php
/**
 * REST API: приём заявок с калькулятора.
 *
 * @package polygraph-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс REST-эндпоинтов.
 */
class PGCalc_Rest {

	const REST_NAMESPACE = 'polygraph-calculator/v1';

	/**
	 * Singleton.
	 *
	 * @var PGCalc_Rest|null
	 */
	private static $instance = null;

	/**
	 * Получение singleton-экземпляра.
	 *
	 * @return PGCalc_Rest
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Регистрация маршрутов.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Регистрация эндпоинтов.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/quote',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_quote' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/requests',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_request' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'name'    => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'maxLength'         => 100,
					),
					'phone'   => array(
						'sanitize_callback' => 'sanitize_text_field',
						'maxLength'         => 30,
					),
					'email'   => array(
						'sanitize_callback' => 'sanitize_email',
					),
					'issues'  => array(
						'sanitize_callback' => 'absint',
					),
					'message' => array(
						'sanitize_callback' => 'sanitize_textarea_field',
						'maxLength'         => 2000,
					),
				),
			)
		);
	}

	/**
	 * GET /quote — расчёт стоимости на сервере (для валидации и переиспользования).
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_quote( WP_REST_Request $request ) {
		$settings = PGCalc_Settings::get_settings();

		$issues  = min( $settings['max_issues'], max( 1, absint( $request->get_param( 'issues' ) ) ) );
		$persons = min( 50, max( 1, absint( $request->get_param( 'persons' ) ) ) );
		$pre_post = rest_sanitize_boolean( $request->get_param( 'pre_post' ) );

		if ( 'ai' === $settings['calc_mode'] ) {
			// Режим «только ИИ»: цена за тему × количество тем.
			$total = (int) round( $settings['ai_price'] * $issues );

			return rest_ensure_response(
				array(
					'total'    => $total,
					'currency' => $settings['currency'],
				)
			);
		}

		$total = $settings['base_price']
			+ ( $issues - 1 ) * $settings['per_issue_price'];

		if ( $persons > 1 ) {
			$total *= $persons;
		}
		if ( $pre_post ) {
			$total *= $settings['pre_post_multiplier'];
		}
		$total = (int) round( $total );

		return rest_ensure_response(
			array(
				'total'    => $total,
				'currency' => $settings['currency'],
				'details'  => array(
					'issues'   => $issues,
					'persons'  => $persons,
					'pre_post' => $pre_post,
				),
			)
		);
	}

	/**
	 * POST /requests — сохранение заявки и уведомление по e-mail.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_request( WP_REST_Request $request ) {
		$settings = PGCalc_Settings::get_settings();

		if ( empty( $settings['enable_rest'] ) ) {
			return new WP_Error(
				'pgcalc_disabled',
				__( 'Приём заявок через API отключён.', 'polygraph-calculator' ),
				array( 'status' => 403 )
			);
		}

		$name  = sanitize_text_field( $request->get_param( 'name' ) );
		$phone = sanitize_text_field( $request->get_param( 'phone' ) );
		$email = sanitize_email( $request->get_param( 'email' ) );

		if ( '' === $name ) {
			return new WP_Error( 'pgcalc_no_name', __( 'Укажите имя.', 'polygraph-calculator' ), array( 'status' => 400 ) );
		}
		if ( '' === $phone && ! is_email( $email ) ) {
			return new WP_Error( 'pgcalc_no_contact', __( 'Укажите телефон или e-mail.', 'polygraph-calculator' ), array( 'status' => 400 ) );
		}

		// Простейшая защита от спама: honeypot-поле «website» должно быть пустым.
		if ( '' !== sanitize_text_field( $request->get_param( 'website' ) ) ) {
			return new WP_Error( 'pgcalc_spam', __( 'Ошибка отправки.', 'polygraph-calculator' ), array( 'status' => 400 ) );
		}

		$quote = $this->get_quote( $request );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		$quote_data = $quote->get_data();

		$request_id = wp_insert_post(
			array(
				'post_type'   => 'pgcalc_request',
				'post_status' => 'private',
				'post_title'  => sprintf(
					/* translators: 1: name, 2: total price. */
					__( 'Заявка: %1$s — %2$s', 'polygraph-calculator' ),
					$name,
					$quote_data['total'] . ' ' . $quote_data['currency']
				),
				'post_content' => sanitize_textarea_field( $request->get_param( 'message' ) ),
			)
		);

		if ( is_wp_error( $request_id ) ) {
			return $request_id;
		}

		update_post_meta( $request_id, '_pgcalc_name', $name );
		update_post_meta( $request_id, '_pgcalc_phone', $phone );
		update_post_meta( $request_id, '_pgcalc_email', $email );
		update_post_meta( $request_id, '_pgcalc_total', $quote_data['total'] );
		update_post_meta( $request_id, '_pgcalc_currency', $quote_data['currency'] );

		// Уведомление администратору.
		if ( ! empty( $settings['notify_email'] ) && is_email( $settings['notify_email'] ) ) {
			$subject = sprintf(
				/* translators: %s: site name. */
				__( '[%s] Новая заявка с калькулятора полиграфа', 'polygraph-calculator' ),
				wp_bloginfo( 'name' )
			);
			$body    = sprintf(
				/* translators: 1: name, 2: phone, 3: email, 4: total, 5: currency, 6: message. */
				__( "Имя: %1\$s\nТелефон: %2\$s\nE-mail: %3\$s\nСумма: %4\$s %5\$s\nСообщение: %6\$s", 'polygraph-calculator' ),
				$name,
				$phone,
				$email,
				$quote_data['total'],
				$quote_data['currency'],
				sanitize_textarea_field( $request->get_param( 'message' ) )
			);
			wp_mail( $settings['notify_email'], $subject, $body );
		}

		return rest_ensure_response(
			array(
				'success'   => true,
				'requestId' => $request_id,
				'total'     => $quote_data['total'],
				'message'   => __( 'Заявка отправлена.', 'polygraph-calculator' ),
			)
		);
	}
}

/**
 * CPT для хранения заявок в админке.
 */
add_action( 'init', 'pgcalc_register_request_cpt' );
/**
 * Регистрация типа записи pgcalc_request.
 */
function pgcalc_register_request_cpt() {
	register_post_type(
		'pgcalc_request',
		array(
			'label'        => __( 'Заявки калькулятора', 'polygraph-calculator' ),
			'labels'       => array(
				'name'          => __( 'Заявки калькулятора', 'polygraph-calculator' ),
				'singular_name' => __( 'Заявка', 'polygraph-calculator' ),
			),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => true,
			'menu_icon'    => 'dashicons-calculator',
			'supports'     => array( 'title', 'editor' ),
			'capabilities' => array( 'create_posts' => 'do_not_allow' ),
			'map_meta_cap' => true,
		)
	);
}
