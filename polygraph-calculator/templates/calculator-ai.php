<?php
/**
 * Шаблон калькулятора — режим «Только ИИ».
 *
 * Клиент видит единственное поле (количество тем) и только итоговую сумму,
 * без какой-либо расшифровки расчёта. Все параметры формулы скрыты.
 *
 * Ожидаемые переменные: $atts (array), $settings (array).
 *
 * @package polygraph-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pgcalc_uid = wp_unique_id( 'pgcalc-' );
?>
<div class="pgcalc pgcalc--ai" id="<?php echo esc_attr( $pgcalc_uid ); ?>"
	data-mode="ai"
	data-base-price="<?php echo esc_attr( $settings['ai_price'] ); ?>"
	data-per-issue-price="<?php echo esc_attr( $settings['ai_price'] ); ?>"
	data-max-issues="<?php echo esc_attr( $settings['max_issues'] ); ?>"
	data-pre-post-multiplier="1"
	data-currency="<?php echo esc_attr( $settings['currency'] ); ?>">

	<h3 class="pgcalc__title"><?php echo esc_html( $atts['title'] ); ?></h3>

	<form class="pgcalc__form" novalidate>
		<!-- Количество тем -->
		<div class="pgcalc__field">
			<label for="<?php echo esc_attr( $pgcalc_uid ); ?>-issues">
				<?php esc_html_e( 'Количество тем для проверки:', 'polygraph-calculator' ); ?>
			</label>
			<input type="number"
				id="<?php echo esc_attr( $pgcalc_uid ); ?>-issues"
				name="issues"
				class="pgcalc__issues"
				min="1" max="<?php echo esc_attr( $settings['max_issues'] ); ?>" value="1" step="1" />
		</div>

		<!-- Только итоговая сумма, без расшифровки -->
		<div class="pgcalc__result">
			<span class="pgcalc__result-label"><?php esc_html_e( 'Стоимость:', 'polygraph-calculator' ); ?></span>
			<strong class="pgcalc__total">
				<?php echo esc_html( number_format_i18n( $settings['ai_price'] ) ); ?>
				<?php echo esc_html( $settings['currency'] ); ?>
			</strong>
		</div>

		<hr class="pgcalc__divider" />

		<!-- Контактные данные -->
		<div class="pgcalc__field">
			<label for="<?php echo esc_attr( $pgcalc_uid ); ?>-name"><?php esc_html_e( 'Ваше имя', 'polygraph-calculator' ); ?> *</label>
			<input type="text" id="<?php echo esc_attr( $pgcalc_uid ); ?>-name" name="name" class="pgcalc__name" required autocomplete="name" />
		</div>

		<div class="pgcalc__field">
			<label for="<?php echo esc_attr( $pgcalc_uid ); ?>-phone"><?php esc_html_e( 'Телефон', 'polygraph-calculator' ); ?></label>
			<input type="tel" id="<?php echo esc_attr( $pgcalc_uid ); ?>-phone" name="phone" class="pgcalc__phone" autocomplete="tel" />
		</div>

		<div class="pgcalc__field">
			<label for="<?php echo esc_attr( $pgcalc_uid ); ?>-email"><?php esc_html_e( 'E-mail', 'polygraph-calculator' ); ?></label>
			<input type="email" id="<?php echo esc_attr( $pgcalc_uid ); ?>-email" name="email" class="pgcalc__email" autocomplete="email" />
		</div>

		<div class="pgcalc__field">
			<label for="<?php echo esc_attr( $pgcalc_uid ); ?>-message"><?php esc_html_e( 'Комментарий', 'polygraph-calculator' ); ?></label>
			<textarea id="<?php echo esc_attr( $pgcalc_uid ); ?>-message" name="message" rows="3" class="pgcalc__message"></textarea>
		</div>

		<!-- Honeypot от ботов (скрыт, не заполнять) -->
		<div class="pgcalc__hp" aria-hidden="true">
			<input type="text" name="website" tabindex="-1" autocomplete="off" />
		</div>

		<button type="submit" class="pgcalc__submit">
			<?php esc_html_e( 'Отправить заявку', 'polygraph-calculator' ); ?>
		</button>

		<p class="pgcalc__notice" role="status" aria-live="polite"></p>
	</form>
</div>
