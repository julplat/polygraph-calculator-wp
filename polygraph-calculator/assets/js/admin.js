/**
 * Админка плагина: тест диалога + проверка API.
 */
/* global PGCALC_ADMIN */
(function () {
	'use strict';

	if (typeof jQuery !== 'undefined') {
		jQuery(function ($) { init($); });
	} else {
		document.addEventListener('DOMContentLoaded', function () { init(null); });
	}

	function init($) {
		var chat = document.getElementById('pgcalc-chat');
		if (!chat) return;

		var input = document.getElementById('pgcalc-input');
		var sendBtn = document.getElementById('pgcalc-send');
		var resetBtn = document.getElementById('pgcalc-reset');
		var resultEl = document.getElementById('pgcalc-result');
		var state = { params: {}, history: [] };

		function bubble(role, text) {
			var d = document.createElement('div');
			d.className = 'pgcalc-bubble pgcalc-bubble-' + role;
			d.textContent = text;
			chat.appendChild(d);
			chat.scrollTop = chat.scrollHeight;
			return d;
		}

		bubble('assistant', PGCALC_ADMIN.i18n.initial);

		function fmt(v) { return Number(v || 0).toFixed(2).replace('.', ',') + ' ₽'; }

		function showResult(res, err, params) {
			if (err) {
				resultEl.innerHTML = '<div class="pgcalc-notice' + (err.needsManager ? ' warn' : ' err') + '">' + escapeHtml(err.message) + '</div>';
				return;
			}
			var h = '<h3>📊 Результат: <span style="color:#0a7d30">' + fmt(res.total) + '</span></h3>';
			h += '<table class="widefat striped" style="max-width:640px"><tbody>';
			h += tr('Раскладка (k)', res.k + ' шт/лист');
			h += tr('Листов тиража N', res.N);
			h += tr('Материал (N+2)', res.Nplus2 + ' листов × ' + fmt(res.materialPricePerSheet) + ' (' + res.materialRange + ')');
			h += tr('Материал', fmt(res.materialCost));
			h += tr('Печать (' + params.printing + ')', fmt(res.printCost) + ' = ' + fmt(res.printSetup) + ' + ' + res.N + '×' + fmt(res.printTariff) + (params.printing === '4+4' ? '×2' : ''));
			h += tr('Резка', fmt(res.cutCost) + ' = ' + fmt(res.cutSetup) + ' + ' + res.Q + '×' + fmt(res.cutTariff));
			if (params.type === 'booklet' && params.creasing) {
				h += tr('Биговка ×' + (params.creasingCount || 2), fmt(res.creasingCost));
			}
			h += tr('<b>ИТОГО</b>', '<b>' + fmt(res.total) + '</b>');
			h += '</tbody></table>';
			resultEl.innerHTML = h;
		}

		function tr(a, b) { return '<tr><td>' + a + '</td><td>' + b + '</td></tr>'; }
		function escapeHtml(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

		function send() {
			var text = input.value.trim();
			if (!text) return;
			input.value = '';
			bubble('user', text);
			state.history.push({ role: 'user', content: text });
			var t = bubble('assistant', PGCALC_ADMIN.i18n.sending);

			fetch(PGCALC_ADMIN.restUrl + 'dialog', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PGCALC_ADMIN.nonce },
				body: JSON.stringify({ message: text, context: { params: state.params, history: state.history.slice(-20) } })
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					t.remove();
					if (data && data.code) { bubble('assistant', data.message || PGCALC_ADMIN.i18n.error); return; }
					if (data.step === 'complete') {
						state.params = data.params;
						bubble('assistant', '✅ Параметры собраны, расчёт выполнен.');
						showResult(data.result, data.error, data.params);
					} else {
						state.params = data.params || state.params;
						state.history.push({ role: 'assistant', content: data.reply });
						bubble('assistant', data.reply);
						if (data.step === 'need_manager') {
							showResult(null, { message: data.reply, needsManager: true }, {});
						}
					}
				})
				.catch(function () { t.remove(); bubble('assistant', PGCALC_ADMIN.i18n.error); });
		}

		sendBtn.addEventListener('click', send);
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') send(); });
		resetBtn.addEventListener('click', function () {
			state = { params: {}, history: [] };
			chat.innerHTML = '';
			resultEl.innerHTML = '';
			bubble('assistant', PGCALC_ADMIN.i18n.initial);
		});

		// Проверка AI API.
		var ping = document.getElementById('pgcalc-ai-ping');
		if (ping) {
			ping.addEventListener('click', function () {
				var out = document.getElementById('pgcalc-ai-ping-result');
				out.textContent = PGCALC_ADMIN.i18n.pingSend;
				fetch(PGCALC_ADMIN.restUrl + 'ai-ping?pgc_admin_nonce=' + encodeURIComponent(PGCALC_ADMIN.adminNonce), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PGCALC_ADMIN.nonce }
				})
					.then(function (r) { return r.json(); })
					.then(function (d) {
						out.textContent = (d && d.success) ? (PGCALC_ADMIN.i18n.pingOk + ' — ' + d.answer) : (PGCALC_ADMIN.i18n.pingFail + ((d && d.message) || '?'));
					})
					.catch(function () { out.textContent = PGCALC_ADMIN.i18n.error; });
			});
		}
	}
})();
