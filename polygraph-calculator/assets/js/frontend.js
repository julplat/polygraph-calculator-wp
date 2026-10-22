/**
 * Polygraph Calculator — фронтенд-виджет (шорткод [polygraph_calculator]).
 * Диалог + панель результата. Вся логика расчёта на сервере (REST).
 */
(function () {
	'use strict';

	var cfg = window.PGCALC_FRONT;
	if (!cfg) return;

	document.querySelectorAll('.pgc-app').forEach(initApp);

	function initApp(app) {
		var chatEl = app.querySelector('[data-chat]');
		var inputEl = app.querySelector('[data-input]');
		var sendBtn = app.querySelector('[data-send]');
		var resultCol = app.querySelector('[data-result-col]');

		var state = { params: {}, history: [], lastResult: null, lastParams: null, leadSent: false };

		addBubble('assistant', cfg.i18n.initial);

		function esc(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}

		function addBubble(role, text) {
			var d = document.createElement('div');
			d.className = 'pgc-bubble pgc-bubble-' + role;
			d.textContent = text;
			chatEl.appendChild(d);
			chatEl.scrollTop = chatEl.scrollHeight;
			return d;
		}

		function fmt(v) {
			var n = Number(v || 0).toFixed(2).replace('.', ',');
			n = n.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
			return n + ' ₽';
		}

		function matName(id) {
			var m = (cfg.materials || []).filter(function (x) { return x.id === id; })[0];
			return m ? m.name : id;
		}

		function isMobile() {
			return window.matchMedia && window.matchMedia('(max-width: 780px)').matches;
		}

		function buildTotalCard(res, params) {
			var typeName = params.type === 'flyer' ? cfg.i18n.flyer : cfg.i18n.booklet;
			var sizeStr = params.sizeName || (params.size.width + '×' + params.size.height + ' мм');
			var html = '';
			html += '<div class="pgc-res-card pgc-res-total">';
			html += '<h4>💰Итого</h4>';
			html += '<p class="pgc-res-summary">' + esc(typeName) + ' ' + esc(sizeStr) + ', ' + esc(params.quantity) + ' шт, ' + esc(matName(params.materialId)) + ', печать ' + esc(params.printing) + (params.creasing ? ', биговка ' + esc(params.creasingCount || 2) : '') + '</p>';
			html += '<p class="pgc-res-price">' + fmt(res.total) + '</p>';
			html += '<p class="pgc-res-disclaimer">' + esc(cfg.i18n.disclaimer) + '</p>';
			html += '</div>';
			return html;
		}

		function renderResult(res, params) {
			state.lastResult = res;
			state.lastParams = params;

			var html = buildTotalCard(res, params);

			if (cfg.showDetail) {
				html += '<div class="pgc-res-card pgc-res-detail">';
				html += '<h4>📋 Детализация</h4>';
				html += row('Раскладка (k)', res.k + ' шт/лист');
				html += row('Листов тиража (N)', res.N);
				html += row('Материал (N+2)', res.Nplus2 + ' листов');
				html += row('📄 Материал', fmt(res.materialCost) + ' <small>(' + res.Nplus2 + ' × ' + fmt(res.materialPricePerSheet) + ', диапазон ' + esc(res.materialRange) + ')</small>');
				html += row('🖨️ Печать ' + esc(params.printing), fmt(res.printCost) + ' <small>(' + fmt(res.printSetup) + ' приладка + ' + res.N + ' × ' + fmt(res.printTariff) + (params.printing === '4+4' ? ' × 2 стороны' : '') + ')</small>');
				html += row('✂️ Резка', fmt(res.cutCost) + ' <small>(' + fmt(res.cutSetup) + ' приладка + ' + res.Q + ' × ' + fmt(res.cutTariff) + ')</small>');
				if (params.type === 'booklet' && params.creasing) {
					html += row('📐 Биговка (' + esc(params.creasingCount || 2) + ')', fmt(res.creasingCost) + ' <small>(' + res.Q + ' × ' + fmt(res.creasingTariff) + ' × ' + esc(params.creasingCount || 2) + ')</small>');
				}
				html += '<div class="pgc-res-row pgc-res-itog"><span>ИТОГО:</span><strong>' + fmt(res.total) + '</strong></div>';
				html += '</div>';
			}
			resultCol.innerHTML = html;

			// Мобильная версия: панель справа недоступна — карточка «💰Итого»
			// и форма контактов появляются прямо внутри диалога.
			if (isMobile()) {
				var card = document.createElement('div');
				card.className = 'pgc-inchat-result';
				card.innerHTML = buildTotalCard(res, params);
				chatEl.appendChild(card);
				chatEl.scrollTop = chatEl.scrollHeight;
				if (!state.leadSent) { renderLeadForm(true); }
			} else if (!state.leadSent) {
				renderLeadForm(false);
			}
		}

		function leadConsentHtml() {
			return '<p class="pgc-privacy pgc-form-privacy">' + esc(cfg.i18n.privacyText) + ' ' +
				'<a href="' + esc(cfg.i18n.privacyUrl) + '" target="_blank" rel="noopener noreferrer">' +
				esc(cfg.i18n.privacyLink) + '</a></p>';
		}

		function renderLeadForm(inChat) {
			var form = document.createElement('div');
			form.className = 'pgc-save-form';
			form.innerHTML =
				'<p class="pgc-form-title">Оставьте контакты — менеджер свяжется с вами и подтвердит стоимость</p>' +
				'<input class="pgc-in" data-name placeholder="' + esc(cfg.i18n.namePh) + '" />' +
				'<input class="pgc-in" data-phone type="tel" placeholder="' + esc(cfg.i18n.phonePh) + '" />' +
				'<button class="pgc-btn pgc-btn-primary" data-ok>' + esc(cfg.i18n.send || 'Отправить') + '</button>' +
				'<span class="pgc-form-msg" data-form-msg></span>' +
				leadConsentHtml();
			if (inChat) { chatEl.appendChild(form); } else { resultCol.appendChild(form); }
			chatEl.scrollTop = chatEl.scrollHeight;
			form.querySelector('[data-ok]').addEventListener('click', function () {
				var name = form.querySelector('[data-name]').value.trim();
				var phone = form.querySelector('[data-phone]').value.trim();
				var msg = form.querySelector('[data-form-msg]');
				if (!name || !phone) { msg.textContent = cfg.i18n.required; return; }
				msg.textContent = '';
				fetch(cfg.restUrl + 'save', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
					body: JSON.stringify({ params: state.lastParams, name: name, phone: phone, notify: true })
				})
					.then(function (r) { return r.json(); })
					.then(function (d) {
						if (d && d.success) {
							state.leadSent = true;
							form.innerHTML = '<p class="pgc-form-thanks">✅ ' + esc(cfg.i18n.saved) + '</p>';
							addBubble('assistant', cfg.i18n.saved);
						} else {
							msg.textContent = cfg.i18n.errSend;
						}
					})
					.catch(function () { msg.textContent = cfg.i18n.errSend; });
			});
		}

		function row(label, val) {
			return '<div class="pgc-res-row"><span>' + label + '</span><em>' + val + '</em></div>';
		}

		function renderError(err) {
			state.lastResult = null;
			var cls = err.needsManager ? 'pgc-res-warn' : 'pgc-res-err';
			resultCol.innerHTML = '<div class="pgc-res-card ' + cls + '"><p>' + esc(err.message) + '</p>' +
				(err.needsManager ? '<p class="pgc-note">' + esc(cfg.i18n.managerNote) + '</p>' : '') + '</div>';
			if (err.needsManager && !state.leadSent) { renderLeadForm(); }
		}

		function send() {
			var text = inputEl.value.trim();
			if (!text) return;
			inputEl.value = '';
			addBubble('user', text);
			state.history.push({ role: 'user', content: text });

			var thinking = addBubble('assistant', cfg.i18n.thinking || '…');
			fetch(cfg.restUrl + 'dialog', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify({ message: text, context: { params: state.params, history: state.history.slice(-20) } })
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					thinking.remove();
					if (data && data.code) { // WP_Error
						addBubble('assistant', data.message || cfg.i18n.errSend);
						return;
					}
					if (data.step === 'complete') {
						state.params = data.params;
						state.history.push({ role: 'assistant', content: '[параметры собраны]' });
						if (data.error) {
							addBubble('assistant', data.error.message);
							renderError(data.error);
						} else {
							addBubble('assistant', isMobile() ? '✅ Расчёт готов' : '✅ Расчёт готов →');
							renderResult(data.result, data.params);
						}
					} else if (data.step === 'need_manager') {
						addBubble('assistant', data.reply);
						renderError({ message: data.reply, needsManager: true });
					} else {
						state.params = data.params || state.params;
						state.history.push({ role: 'assistant', content: data.reply });
						addBubble('assistant', data.reply);
					}
				})
				.catch(function () {
					thinking.remove();
					addBubble('assistant', cfg.i18n.errSend);
				});
		}

		sendBtn.addEventListener('click', send);
		inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') send(); });
	}
})();
