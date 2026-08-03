(function ($) {
	'use strict';

	function uid() {
		return 'f' + Math.random().toString(36).slice(2, 8);
	}

	function parseJSON(sel, fallback) {
		try {
			var v = $(sel).val();
			if (!v) return fallback;
			var d = JSON.parse(v);
			return Array.isArray(d) ? d : fallback;
		} catch (e) {
			return fallback;
		}
	}

	function fieldTypes() {
		try {
			return JSON.parse($('#sc-field-types').text() || '{}');
		} catch (e) {
			return {};
		}
	}

	function priceTypes() {
		try {
			return JSON.parse($('#sc-price-types').text() || '{}');
		} catch (e) {
			return {};
		}
	}

	/* ---- Views ---- */
	function renderViews() {
		var views = parseJSON('#sc_customizer_views_json', []);
		var $list = $('#sc-views-list').empty();
		views.forEach(function (v, i) {
			var thumb = v.image_id
				? '<span class="sc-thumb" data-id="' + v.image_id + '">#' + v.image_id + '</span>'
				: '<span class="sc-thumb sc-thumb-empty">—</span>';
			var $row = $(
				'<div class="sc-row sc-view-row" data-index="' +
					i +
					'">' +
					'<input type="text" class="sc-view-id" placeholder="id" value="' +
					esc(v.id || '') +
					'" style="width:80px" />' +
					'<input type="text" class="sc-view-label" placeholder="Label" value="' +
					esc(v.label || '') +
					'" style="width:120px" />' +
					'<input type="hidden" class="sc-view-image-id" value="' +
					(v.image_id || '') +
					'" />' +
					thumb +
					' <button type="button" class="button sc-pick-image">Image</button>' +
					' <button type="button" class="button sc-remove-view">×</button>' +
					'</div>'
			);
			$list.append($row);
		});
	}

	function syncViews() {
		var views = [];
		$('#sc-views-list .sc-view-row').each(function () {
			var $r = $(this);
			views.push({
				id: $r.find('.sc-view-id').val() || uid(),
				label: $r.find('.sc-view-label').val() || '',
				image_id: parseInt($r.find('.sc-view-image-id').val(), 10) || 0,
			});
		});
		$('#sc_customizer_views_json').val(JSON.stringify(views));
	}

	/* ---- Areas ---- */
	function renderAreas() {
		var areas = parseJSON('#sc_customizer_areas_json', []);
		var views = parseJSON('#sc_customizer_views_json', []);
		var $list = $('#sc-areas-list').empty();
		areas.forEach(function (a, i) {
			var opts = views
				.map(function (v) {
					var sel = v.id === a.view_id ? ' selected' : '';
					return '<option value="' + esc(v.id) + '"' + sel + '>' + esc(v.label || v.id) + '</option>';
				})
				.join('');
			var $row = $(
				'<div class="sc-row sc-area-row">' +
					'<input type="text" class="sc-area-id" placeholder="id" value="' +
					esc(a.id || '') +
					'" style="width:70px" />' +
					'<select class="sc-area-view">' +
					opts +
					'</select>' +
					'<input type="text" class="sc-area-label" placeholder="Label" value="' +
					esc(a.label || '') +
					'" style="width:100px" />' +
					' x<input type="number" class="sc-area-x" value="' +
					(a.x != null ? a.x : 30) +
					'" style="width:55px" step="0.1" />' +
					' y<input type="number" class="sc-area-y" value="' +
					(a.y != null ? a.y : 25) +
					'" style="width:55px" step="0.1" />' +
					' w<input type="number" class="sc-area-w" value="' +
					(a.w != null ? a.w : 40) +
					'" style="width:55px" step="0.1" />' +
					' h<input type="number" class="sc-area-h" value="' +
					(a.h != null ? a.h : 35) +
					'" style="width:55px" step="0.1" />' +
					' <button type="button" class="button sc-remove-area">×</button>' +
					'</div>'
			);
			$list.append($row);
		});
	}

	function syncAreas() {
		var areas = [];
		$('#sc-areas-list .sc-area-row').each(function () {
			var $r = $(this);
			areas.push({
				id: $r.find('.sc-area-id').val() || uid(),
				view_id: $r.find('.sc-area-view').val() || '',
				label: $r.find('.sc-area-label').val() || '',
				x: parseFloat($r.find('.sc-area-x').val()) || 0,
				y: parseFloat($r.find('.sc-area-y').val()) || 0,
				w: parseFloat($r.find('.sc-area-w').val()) || 20,
				h: parseFloat($r.find('.sc-area-h').val()) || 20,
			});
		});
		$('#sc_customizer_areas_json').val(JSON.stringify(areas));
	}

	/* ---- Fields ---- */
	function renderFields() {
		var fields = parseJSON('#sc_options_fields_json', []);
		var types = fieldTypes();
		var prices = priceTypes();
		var $list = $('#sc-fields-list').empty();
		fields.forEach(function (f) {
			var typeOpts = Object.keys(types)
				.map(function (k) {
					return (
						'<option value="' +
						k +
						'"' +
						(f.type === k ? ' selected' : '') +
						'>' +
						esc(types[k]) +
						'</option>'
					);
				})
				.join('');
			var priceOpts = Object.keys(prices)
				.map(function (k) {
					return (
						'<option value="' +
						k +
						'"' +
						(f.price_type === k ? ' selected' : '') +
						'>' +
						esc(prices[k]) +
						'</option>'
					);
				})
				.join('');
			var choicesStr = '';
			if (Array.isArray(f.choices)) {
				choicesStr = f.choices
					.map(function (c) {
						return typeof c === 'object' ? c.label || c.value || '' : c;
					})
					.join(', ');
			}
			var showIfField = (f.show_if && f.show_if.field) || f.show_if_field || '';
			var showIfValue = (f.show_if && f.show_if.value != null) ? f.show_if.value : (f.show_if_value || '');
			var $row = $(
				'<div class="sc-row sc-field-row" style="margin-bottom:8px;padding:8px;border:1px solid #ddd;background:#fff;">' +
					'<input type="hidden" class="sc-field-id" value="' +
					esc(f.id || uid()) +
					'" />' +
					'<label>Label <input type="text" class="sc-field-label" value="' +
					esc(f.label || '') +
					'" /></label> ' +
					'<label>Type <select class="sc-field-type">' +
					typeOpts +
					'</select></label> ' +
					'<label><input type="checkbox" class="sc-field-required"' +
					(f.required ? ' checked' : '') +
					'/> Required</label><br/>' +
					'<label>Price <select class="sc-field-price-type">' +
					priceOpts +
					'</select></label> ' +
					'<input type="number" class="sc-field-price" value="' +
					(f.price != null ? f.price : 0) +
					'" step="0.01" style="width:80px" /> ' +
					'<label class="sc-choices-wrap">Choices <input type="text" class="sc-field-choices" placeholder="A, B, C" value="' +
					esc(choicesStr) +
					'" style="width:200px" /></label><br/>' +
					'<label>Show if field <input type="text" class="sc-field-show-if-field" placeholder="other_field_id" value="' +
					esc(showIfField) +
					'" style="width:120px" /></label> ' +
					'<label>equals <input type="text" class="sc-field-show-if-value" placeholder="value" value="' +
					esc(showIfValue) +
					'" style="width:100px" /></label> ' +
					'<button type="button" class="button sc-remove-field">Remove</button>' +
					'</div>'
			);
			$list.append($row);
		});
	}

	function syncFields() {
		var fields = [];
		$('#sc-fields-list .sc-field-row').each(function () {
			var $r = $(this);
			var type = $r.find('.sc-field-type').val();
			var choicesRaw = $r.find('.sc-field-choices').val() || '';
			var choices = [];
			if (choicesRaw && (type === 'select' || type === 'radio' || type === 'checkbox')) {
				choices = choicesRaw.split(',').map(function (s) {
					s = s.trim();
					return { value: s, label: s };
				});
			}
			var showIfField = ($r.find('.sc-field-show-if-field').val() || '').trim();
			var showIfValue = $r.find('.sc-field-show-if-value').val() || '';
			var row = {
				id: $r.find('.sc-field-id').val() || uid(),
				type: type,
				label: $r.find('.sc-field-label').val() || '',
				required: $r.find('.sc-field-required').is(':checked'),
				price_type: $r.find('.sc-field-price-type').val() || 'none',
				price: parseFloat($r.find('.sc-field-price').val()) || 0,
				choices: choices,
			};
			if (showIfField) {
				row.show_if = { field: showIfField, value: showIfValue };
			}
			fields.push(row);
		});
		$('#sc_options_fields_json').val(JSON.stringify(fields));
	}

	function esc(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	function openMedia($row) {
		var frame = wp.media({
			title: scAdmin.i18n.selectImg || 'Select image',
			button: { text: scAdmin.i18n.selectImg || 'Select' },
			multiple: false,
		});
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			$row.find('.sc-view-image-id').val(att.id);
			$row.find('.sc-thumb').removeClass('sc-thumb-empty').text('#' + att.id).attr('data-id', att.id);
			syncViews();
			renderAreas();
		});
		frame.open();
	}

	$(function () {
		if (!$('#sc_product_data').length) {
			return;
		}

		renderViews();
		renderAreas();
		renderFields();

		$('#sc-add-view').on('click', function () {
			syncViews();
			var views = parseJSON('#sc_customizer_views_json', []);
			views.push({ id: 'view_' + uid(), label: 'View', image_id: 0 });
			$('#sc_customizer_views_json').val(JSON.stringify(views));
			renderViews();
			renderAreas();
		});

		$('#sc-views-list')
			.on('click', '.sc-remove-view', function () {
				$(this).closest('.sc-view-row').remove();
				syncViews();
				renderAreas();
			})
			.on('click', '.sc-pick-image', function () {
				openMedia($(this).closest('.sc-view-row'));
			})
			.on('change', 'input', syncViews);

		$('#sc-add-area').on('click', function () {
			syncViews();
			syncAreas();
			var views = parseJSON('#sc_customizer_views_json', []);
			var areas = parseJSON('#sc_customizer_areas_json', []);
			areas.push({
				id: 'area_' + uid(),
				view_id: views[0] ? views[0].id : '',
				label: 'Print area',
				x: 30,
				y: 25,
				w: 40,
				h: 35,
			});
			$('#sc_customizer_areas_json').val(JSON.stringify(areas));
			renderAreas();
		});

		$('#sc-areas-list')
			.on('click', '.sc-remove-area', function () {
				$(this).closest('.sc-area-row').remove();
				syncAreas();
			})
			.on('change', 'input,select', syncAreas);

		$('#sc-add-field').on('click', function () {
			syncFields();
			var fields = parseJSON('#sc_options_fields_json', []);
			fields.push({
				id: uid(),
				type: 'text',
				label: 'New field',
				required: false,
				price_type: 'none',
				price: 0,
				choices: [],
				show_if: null,
			});
			$('#sc_options_fields_json').val(JSON.stringify(fields));
			renderFields();
		});

		$('#sc-fields-list')
			.on('click', '.sc-remove-field', function () {
				$(this).closest('.sc-field-row').remove();
				syncFields();
			})
			.on('change', 'input,select', syncFields);

		// Before product save, force sync.
		$('#post').on('submit', function () {
			syncViews();
			syncAreas();
			syncFields();
		});
	});
})(jQuery);
