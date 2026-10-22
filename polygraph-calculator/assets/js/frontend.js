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
		var saveBtn = app.querySelector('[data-save]');
		var excelBtn = app.querySelector('[data-excel]');
		var saveMsg = app.querySelector('[data-save-msg]');

		var state = { params: {}, history: [], lastResult: null, lastParams: null };

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

		function renderResult(res, params) {
			state.lastResult = res;
			state.lastParams = params;
			saveBtn.disabled = false;
			excelBtn.disabled = false;

			var typeName = params.type === 'flyer' ? cfg.i18n.flyer : cfg.i18n.booklet;
			var sizeStr = params.sizeName || (params.size.width + '×' + params.size.height + ' мм');
			var html = '';
			html += '<div class="pgc-res-card pgc-res-total">';
			html += '<h4>💰 ' + esc(cfg.i18n.forClient) + '</h4>';
			html += '<p class="pgc-res-summary">' + esc(typeName) + ' ' + esc(sizeStr) + ', ' + esc(params.quantity) + ' шт, ' + esc(matName(params.materialId)) + ', печать ' + esc(params.printing) + (params.creasing ? ', биговка ' + esc(params.creasingCount || 2) : '') + '</p>';
			html += '<p class="pgc-res-price">' + fmt(res.total) + '</p>';
			html += '</div>';

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
		}

		function row(label, val) {
			return '<div class="pgc-res-row"><span>' + label + '</span><em>' + val + '</em></div>';
		}

		function renderError(err) {
			state.lastResult = null;
			saveBtn.disabled = true;
			excelBtn.disabled = true;
			var cls = err.needsManager ? 'pgc-res-warn' : 'pgc-res-err';
			resultCol.innerHTML = '<div class="pgc-res-card ' + cls + '"><p>' + esc(err.message) + '</p>' +
				(err.needsManager ? '<p class="pgc-note">' + esc(cfg.i18n.managerNote) + '</p>' : '') + '</div>';
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
							addBubble('assistant', '✅ Расчёт готов — смотрите справа.');
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

		// Сохранение в историю (диалог имени/телефона — прямо под результатом).
		saveBtn.addEventListener('click', function () {
			if (!state.lastParams) return;
			if (resultCol.querySelector('.pgc-save-form')) return;
			var form = document.createElement('div');
			form.className = 'pgc-save-form';
			form.innerHTML = '<input class="pgc-in" data-name placeholder="' + esc(cfg.i18n.namePh) + '"/>' +
				'<input class="pgc-in" data-phone placeholder="' + esc(cfg.i18n.phonePh) + '"/>' +
				'<button class="pgc-btn pgc-btn-primary" data-ok>OK</button>';
			resultCol.appendChild(form);
			form.querySelector('[data-ok]').addEventListener('click', function () {
				var name = form.querySelector('[data-name]').value.trim();
				var phone = form.querySelector('[data-phone]').value.trim();
				fetch(cfg.restUrl + 'save', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
					body: JSON.stringify({ params: state.lastParams, name: name, phone: phone, notify: true })
				})
					.then(function (r) { return r.json(); })
					.then(function (d) {
						saveMsg.textContent = d && d.success ? cfg.i18n.saved : cfg.i18n.errSend;
						form.remove();
					})
					.catch(function () { saveMsg.textContent = cfg.i18n.errSend; });
			});
		});

		// Экспорт текущего расчёта в CSV (Excel).
		excelBtn.addEventListener('click', function () {
			if (!state.lastResult || !state.lastParams) return;
			var p = state.lastParams, r = state.lastResult;
			var rows = [
				['Параметр', 'Значение'],
				['Тип', p.type === 'flyer' ? cfg.i18n.flyer : cfg.i18n.booklet],
				['Размер', p.sizeName || (p.size.width + '×' + p.size.height)],
				['Количество', p.quantity + ' шт'],
				['Материал', matName(p.materialId)],
				['Печать', p.printing],
				['Биговка', p.creasing ? 'Да (' + (p.creasingCount || 2) + ')' : 'Нет'],
				['', ''],
				['--- ДЕТАЛИЗАЦИЯ ---', ''],
				['Раскладка (k)', r.k + ' шт/лист'],
				['Листов (N)', r.N],
				['Материал (N+2)', r.Nplus2 + ' листов'],
				['Стоимость материала', r.materialCost],
				['Стоимость печати', r.printCost],
				['Стоимость резки', r.cutCost],
				['Стоимость биговки', r.creasingCost],
				['', ''],
				['ИТОГО', r.total]
			];
			var csv = '\uFEFF' + rows.map(function (row) {
				return row.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(';');
			}).join('\r\n');
			var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = 'raschet.csv';
			a.click();
		});
	}
})();
