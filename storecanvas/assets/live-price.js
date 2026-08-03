(function () {
	'use strict';

	function cfg() {
		return typeof scLivePrice !== 'undefined' ? scLivePrice : null;
	}

	function fieldExtra(field, value, qty, base) {
		var type = field.price_type || 'none';
		if (type === 'none' || value === '' || value === null || value === undefined) {
			return 0;
		}
		if ((field.type || '') === 'checkbox' && !value) {
			return 0;
		}
		var amount = parseFloat(field.price) || 0;
		switch (type) {
			case 'flat':
				return amount;
			case 'percent':
				return base * (amount / 100);
			case 'qty':
				return amount * (qty || 1);
			case 'per_char':
				return amount * String(value).length;
			default:
				return 0;
		}
	}

	function readValues(root) {
		var values = {};
		if (!root) return values;
		root.querySelectorAll('[name^="sc_option["]').forEach(function (el) {
			var m = el.name.match(/^sc_option\[([^\]]+)\]/);
			if (!m) return;
			var id = m[1];
			if (el.type === 'checkbox') {
				values[id] = el.checked ? el.value || '1' : '';
			} else if (el.type === 'radio') {
				if (el.checked) values[id] = el.value;
			} else {
				values[id] = el.value;
			}
		});
		return values;
	}

	function qtyValue() {
		var q =
			document.querySelector('form.cart input.qty') ||
			document.querySelector('input.qty') ||
			document.querySelector('[name="quantity"]');
		var n = q ? parseFloat(q.value) : 1;
		return isNaN(n) || n < 1 ? 1 : n;
	}

	function formatMoney(amount) {
		var c = cfg();
		if (!c) return String(amount);
		// Prefer WooCommerce accounting if present.
		if (typeof accounting !== 'undefined' && accounting.formatMoney && c.currency) {
			try {
				return accounting.formatMoney(amount, c.currency);
			} catch (e) {
				/* fall through */
			}
		}
		var decimals = c.decimals != null ? c.decimals : 2;
		var symbol = c.symbol || '';
		var fixed = amount.toFixed(decimals);
		if (c.currencyPos === 'left') return symbol + fixed;
		if (c.currencyPos === 'left_space') return symbol + ' ' + fixed;
		if (c.currencyPos === 'right_space') return fixed + ' ' + symbol;
		return fixed + symbol;
	}

	function calc() {
		var c = cfg();
		if (!c) return null;
		var base = parseFloat(c.basePrice) || 0;
		var fields = c.fields || [];
		var root = document.querySelector('.sc-options');
		var values = readValues(root);
		var qty = qtyValue();
		var extra = 0;
		fields.forEach(function (f) {
			if (!f || !f.id) return;
			// Hidden by show_if: skip pricing when not visible
			var wrap = root
				? root.querySelector('.sc-option-field[data-field-id="' + f.id + '"]')
				: null;
			if (wrap && wrap.style.display === 'none') return;
			var val = values[f.id];
			if (val === undefined) val = '';
			extra += fieldExtra(f, val, qty, base);
		});
		// qty pricing is already * qty in fieldExtra; flat/percent/per_char are unit extras.
		// Match PHP: sc_price_extra is unit extra; line multiplies by qty in WC.
		// For display of "unit total": base + unit extras (flat/percent/per_char) + qty-type*1
		// Recompute unit-style extra matching PHP field_price (qty type returns amount once):
		var unitExtra = 0;
		fields.forEach(function (f) {
			if (!f || !f.id) return;
			var wrap = root
				? root.querySelector('.sc-option-field[data-field-id="' + f.id + '"]')
				: null;
			if (wrap && wrap.style.display === 'none') return;
			var val = values[f.id];
			if (val === undefined) val = '';
			var type = f.price_type || 'none';
			if (type === 'none' || val === '' || val === null) return;
			if ((f.type || '') === 'checkbox' && !val) return;
			var amount = parseFloat(f.price) || 0;
			if (type === 'flat') unitExtra += amount;
			else if (type === 'percent') unitExtra += base * (amount / 100);
			else if (type === 'qty') unitExtra += amount; // per unit
			else if (type === 'per_char') unitExtra += amount * String(val).length;
		});
		return {
			base: base,
			extra: unitExtra,
			unit: base + unitExtra,
			line: (base + unitExtra) * qty,
			qty: qty,
		};
	}

	function priceNodes() {
		var nodes = [];
		var selectors = [
			'.summary .price',
			'.product .price',
			'.wp-block-woocommerce-product-price .price',
			'.wc-block-components-product-price',
			'p.price',
		];
		selectors.forEach(function (sel) {
			document.querySelectorAll(sel).forEach(function (el) {
				if (nodes.indexOf(el) === -1) nodes.push(el);
			});
		});
		return nodes;
	}

	function ensureBreakdown() {
		var el = document.getElementById('sc-live-price');
		if (el) return el;
		var anchor =
			document.querySelector('.summary .price') ||
			document.querySelector('.product .price') ||
			document.querySelector('form.cart');
		el = document.createElement('p');
		el.id = 'sc-live-price';
		el.className = 'sc-live-price description';
		el.style.margin = '6px 0';
		if (anchor && anchor.parentNode) {
			anchor.parentNode.insertBefore(el, anchor.nextSibling);
		} else if (anchor) {
			anchor.appendChild(el);
		} else {
			document.body.appendChild(el);
		}
		return el;
	}

	function update() {
		var c = cfg();
		if (!c || !c.fields || !c.fields.length) return;
		var r = calc();
		if (!r) return;
		var html =
			'<span class="sc-live-base">' +
			(c.i18n.base || 'Base') +
			': ' +
			formatMoney(r.base) +
			'</span>';
		if (r.extra > 0.00001) {
			html +=
				' + <span class="sc-live-extra">' +
				(c.i18n.extras || 'options') +
				': ' +
				formatMoney(r.extra) +
				'</span>';
		}
		html +=
			' = <strong class="sc-live-total">' +
			formatMoney(r.unit) +
			'</strong>';
		if (r.qty > 1) {
			html +=
				' <span class="sc-live-line">(' +
				(c.i18n.line || 'line') +
				': ' +
				formatMoney(r.line) +
				')</span>';
		}
		var breakdown = ensureBreakdown();
		breakdown.innerHTML = html;

		// Update visible main price amounts to unit total (classic + block).
		priceNodes().forEach(function (node) {
			if (!node.getAttribute('data-sc-base-html')) {
				node.setAttribute('data-sc-base-html', node.innerHTML);
			}
			var amounts = node.querySelectorAll('.woocommerce-Price-amount bdi, .woocommerce-Price-amount, .wc-block-components-product-price__value');
			if (amounts.length) {
				amounts.forEach(function (a) {
					// Keep structure; replace text.
					var sym = a.querySelector('.woocommerce-Price-currencySymbol');
					var symbolHtml = sym ? sym.outerHTML : '';
					if (c.currencyPos === 'left' || c.currencyPos === 'left_space') {
						a.innerHTML =
							symbolHtml +
							(c.currencyPos === 'left_space' ? ' ' : '') +
							r.unit.toFixed(c.decimals != null ? c.decimals : 2);
					} else {
						a.innerHTML =
							r.unit.toFixed(c.decimals != null ? c.decimals : 2) +
							(c.currencyPos === 'right_space' ? ' ' : '') +
							symbolHtml;
					}
				});
			} else {
				node.innerHTML = formatMoney(r.unit);
			}
		});
	}

	function bind() {
		var c = cfg();
		if (!c || !c.fields || !c.fields.length) return;
		document.addEventListener('change', function (e) {
			if (!e.target) return;
			if (e.target.closest && (e.target.closest('.sc-options') || e.target.matches('input.qty') || e.target.name === 'quantity')) {
				update();
			}
		});
		document.addEventListener('input', function (e) {
			if (!e.target) return;
			if (e.target.closest && e.target.closest('.sc-options')) update();
			if (e.target.matches && (e.target.matches('input.qty') || e.target.name === 'quantity')) update();
		});
		update();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bind);
	} else {
		bind();
	}
})();
