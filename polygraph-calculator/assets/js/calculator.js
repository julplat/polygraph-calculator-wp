/**
 * Polygraph Calculator — клиентская логика.
 * Зависимости: глобальный PGCALC_CONFIG (wp_localize_script).
 */
( function () {
	'use strict';

	var cfg = window.PGCALC_CONFIG || {};

	var CURRENCY_SYMBOLS = { RUB: '\u20BD', USD: '$', EUR: '\u20AC', KZT: '\u20B8', UAH: '\u20B4' };

	function formatNumber( n ) {
		return String( n ).replace( /\B(?=(\d{3})+(?!\d))/g, ' ' );
	}

	function symbolFor( code ) {
		return CURRENCY_SYMBOLS[ code ] || code;
	}

	/**
	 * Инициализация одного блока калькулятора.
	 *
	 * @param {HTMLElement} root Контейнер .pgcalc
	 */
	function initCalculator( root ) {
		if ( root.dataset.pgcalcReady === '1' ) {
			return;
		}
		root.dataset.pgcalcReady = '1';

		var basePrice = parseFloat( root.dataset.basePrice ) || cfg.basePrice || 0;
		var perIssue = parseFloat( root.dataset.perIssuePrice ) || cfg.perIssuePrice || 0;
		var multiplier = parseFloat( root.dataset.prePostMultiplier ) || cfg.prePostMultiplier || 1;
		var currency = root.dataset.currency || cfg.currency || 'RUB';

		var personsInput = root.querySelector( '.pgcalc__persons' );
		var issuesInput = root.querySelector( '.pgcalc__issues' );
		var issuesValue = root.querySelector( '.pgcalc__issues-value' );
		var prepostInput = root.querySelector( '.pgcalc__prepost' );
		var totalEl = root.querySelector( '.pgcalc__total' );
		var form = root.querySelector( '.pgcalc__form' );
		var notice = root.querySelector( '.pgcalc__notice' );
		var submitBtn = root.querySelector( '.pgcalc__submit' );

		function recalc() {
			var persons = Math.max( 1, parseInt( personsInput.value, 10 ) || 1 );
			var issues = Math.max( 1, parseInt( issuesInput.value, 10 ) || 1 );
			var usePrepost = prepostInput && prepostInput.checked;

			if ( issuesValue ) {
				issuesValue.textContent = issues;
			}

			var total = basePrice + ( issues - 1 ) * perIssue;
			total *= persons;
			if ( usePrepost ) {
				total *= multiplier;
			}
			total = Math.round( total );

			totalEl.textContent = formatNumber( total ) + ' ' + symbolFor( currency );
		}

		[ personsInput, issuesInput, prepostInput ].forEach( function ( el ) {
			if ( el ) {
				el.addEventListener( 'input', recalc );
				el.addEventListener( 'change', recalc );
			}
		} );

		recalc();

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			sendRequest( form, notice, submitBtn );
		} );
	}

	/**
	 * Отправка заявки через REST API.
	 */
	function sendRequest( form, notice, btn ) {
		var i18n = cfg.i18n || {};
		var data = {};
		new FormData( form ).forEach( function ( v, k ) {
			data[ k ] = v;
		} );

		// Клиентская валидация.
		if ( ! ( data.name || '' ).trim() ) {
			showNotice( notice, i18n.errorName || 'Укажите имя', true );
			return;
		}
		if ( ! ( data.phone || '' ).trim() && ! ( data.email || '' ).trim() ) {
			showNotice( notice, i18n.errorContact || 'Укажите телефон или e-mail', true );
			return;
		}

		var originalLabel = btn.textContent;
		btn.disabled = true;
		btn.textContent = '...';

		fetch( cfg.restUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: JSON.stringify( {
				name: data.name,
				phone: data.phone || '',
				email: data.email || '',
				message: data.message || '',
				website: data.website || '',
				persons: parseInt( data.persons, 10 ) || 1,
				issues: parseInt( data.issues, 10 ) || 1,
				pre_post: !! data.pre_post
			} )
		} )
			.then( function ( res ) {
				return res.json().then( function ( json ) {
					return { ok: res.ok, json: json };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok && result.json.success ) {
					showNotice( notice, i18n.successMessage || 'Заявка отправлена!', false );
					form.reset();
					form.dispatchEvent( new Event( 'input' ) );
				} else {
					showNotice(
						notice,
						result.json.message || i18n.errorMessage || 'Ошибка отправки',
						true
					);
				}
			} )
			.catch( function () {
				showNotice( notice, i18n.errorMessage || 'Ошибка отправки', true );
			} )
			.finally( function () {
				btn.disabled = false;
				btn.textContent = originalLabel;
			} );
	}

	function showNotice( el, text, isError ) {
		el.textContent = text;
		el.classList.toggle( 'pgcalc__notice--error', !! isError );
		el.classList.toggle( 'pgcalc__notice--success', ! isError );
	}

	// Инициализация всех калькуляторов на странице (в т.ч. добавленных позже).
	function initAll() {
		document.querySelectorAll( '.pgcalc' ).forEach( initCalculator );
	}

	if ( document.readyState !== 'loading' ) {
		initAll();
	} else {
		document.addEventListener( 'DOMContentLoaded', initAll );
	}
} )();
