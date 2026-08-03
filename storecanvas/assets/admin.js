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

	function viewImageUrls() {
		try {
			return JSON.parse($('#sc-view-image-urls').text() || '{}');
		} catch (e) {
			return {};
		}
	}

	function setViewImageUrl(id, url) {
		var map = viewImageUrls();
		map[String(id)] = url;
		$('#sc-view-image-urls').text(JSON.stringify(map));
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
			if (att.url) {
				setViewImageUrl(att.id, att.url);
			}
			syncViews();
			renderAreas();
			refreshVisualEditor();
		});
		frame.open();
	}

	/* ---- Visual print-area editor (0.6.0) ---- */
	var visual = {
		img: null,
		layout: null,
		mode: null,
		handle: null,
		lastX: 0,
		lastY: 0,
		HANDLE: 8,
	};

	function fillVisualViewSelect() {
		var views = parseJSON('#sc_customizer_views_json', []);
		var $sel = $('#sc-visual-view').empty();
		views.forEach(function (v) {
			$sel.append(
				$('<option/>')
					.val(v.id)
					.text((v.label || v.id) + (v.image_id ? '' : ' (no image)'))
					.prop('disabled', !v.image_id)
			);
		});
		fillVisualAreaSelect();
	}

	function fillVisualAreaSelect() {
		var viewId = $('#sc-visual-view').val();
		var areas = parseJSON('#sc_customizer_areas_json', []);
		var $sel = $('#sc-visual-area').empty();
		var list = areas.filter(function (a) {
			return a.view_id === viewId;
		});
		if (!list.length) {
			$sel.append($('<option/>').val('').text('— none —'));
		} else {
			list.forEach(function (a, i) {
				$sel.append(
					$('<option/>')
						.val(a.id)
						.text((a.label || a.id) + (i === 0 ? ' (primary)' : ''))
				);
			});
		}
		loadVisualImage();
	}

	function getSelectedArea() {
		var areaId = $('#sc-visual-area').val();
		var areas = parseJSON('#sc_customizer_areas_json', []);
		for (var i = 0; i < areas.length; i++) {
			if (areas[i].id === areaId) {
				return { area: areas[i], index: i };
			}
		}
		return null;
	}

	function updateAreaInJson(patch) {
		var sel = getSelectedArea();
		if (!sel) return;
		var areas = parseJSON('#sc_customizer_areas_json', []);
		areas[sel.index] = Object.assign({}, areas[sel.index], patch);
		$('#sc_customizer_areas_json').val(JSON.stringify(areas));
		// Sync numeric row if present
		$('#sc-areas-list .sc-area-row').each(function () {
			var $r = $(this);
			if ($r.find('.sc-area-id').val() === sel.area.id) {
				if (patch.x != null) $r.find('.sc-area-x').val(round1(patch.x));
				if (patch.y != null) $r.find('.sc-area-y').val(round1(patch.y));
				if (patch.w != null) $r.find('.sc-area-w').val(round1(patch.w));
				if (patch.h != null) $r.find('.sc-area-h').val(round1(patch.h));
			}
		});
	}

	function round1(n) {
		return Math.round(n * 10) / 10;
	}

	function loadVisualImage() {
		var canvas = document.getElementById('sc-area-canvas');
		if (!canvas) return;
		var ctx = canvas.getContext('2d');
		var views = parseJSON('#sc_customizer_views_json', []);
		var viewId = $('#sc-visual-view').val();
		var view = views.find(function (v) {
			return v.id === viewId;
		});
		visual.img = null;
		visual.layout = null;
		ctx.clearRect(0, 0, canvas.width, canvas.height);
		ctx.fillStyle = '#e8e8e8';
		ctx.fillRect(0, 0, canvas.width, canvas.height);
		if (!view || !view.image_id) {
			ctx.fillStyle = '#666';
			ctx.fillText('Select a view with an image', 16, 28);
			return;
		}
		var urls = viewImageUrls();
		var url = urls[String(view.image_id)];
		if (!url) {
			ctx.fillStyle = '#666';
			ctx.fillText('Image URL unavailable (re-select image)', 16, 28);
			drawVisualArea();
			return;
		}
		var img = new Image();
		img.crossOrigin = 'anonymous';
		img.onload = function () {
			visual.img = img;
			drawVisualArea();
		};
		img.onerror = function () {
			ctx.fillStyle = '#666';
			ctx.fillText('Could not load view image', 16, 28);
		};
		img.src = url;
	}

	function areaPixelRect(area, layout) {
		return {
			x: layout.ox + (area.x / 100) * layout.w,
			y: layout.oy + (area.y / 100) * layout.h,
			w: (area.w / 100) * layout.w,
			h: (area.h / 100) * layout.h,
		};
	}

	function drawVisualArea() {
		var canvas = document.getElementById('sc-area-canvas');
		if (!canvas) return;
		var ctx = canvas.getContext('2d');
		ctx.clearRect(0, 0, canvas.width, canvas.height);
		ctx.fillStyle = '#f0f0f1';
		ctx.fillRect(0, 0, canvas.width, canvas.height);

		if (visual.img) {
			var scale = Math.min(canvas.width / visual.img.width, canvas.height / visual.img.height);
			var w = visual.img.width * scale;
			var h = visual.img.height * scale;
			var ox = (canvas.width - w) / 2;
			var oy = (canvas.height - h) / 2;
			visual.layout = { ox: ox, oy: oy, w: w, h: h };
			ctx.drawImage(visual.img, ox, oy, w, h);
		} else {
			visual.layout = { ox: 20, oy: 20, w: canvas.width - 40, h: canvas.height - 40 };
			ctx.strokeStyle = '#ccc';
			ctx.strokeRect(visual.layout.ox, visual.layout.oy, visual.layout.w, visual.layout.h);
		}

		var sel = getSelectedArea();
		if (!sel || !visual.layout) return;
		var r = areaPixelRect(sel.area, visual.layout);
		ctx.save();
		ctx.fillStyle = 'rgba(34,113,177,0.15)';
		ctx.strokeStyle = 'rgba(34,113,177,0.95)';
		ctx.lineWidth = 2;
		ctx.setLineDash([6, 3]);
		ctx.fillRect(r.x, r.y, r.w, r.h);
		ctx.strokeRect(r.x, r.y, r.w, r.h);
		ctx.setLineDash([]);
		var handles = {
			nw: { x: r.x, y: r.y },
			ne: { x: r.x + r.w, y: r.y },
			sw: { x: r.x, y: r.y + r.h },
			se: { x: r.x + r.w, y: r.y + r.h },
		};
		Object.keys(handles).forEach(function (k) {
			var pt = handles[k];
			ctx.fillStyle = '#fff';
			ctx.strokeStyle = '#2271b1';
			ctx.fillRect(pt.x - visual.HANDLE / 2, pt.y - visual.HANDLE / 2, visual.HANDLE, visual.HANDLE);
			ctx.strokeRect(pt.x - visual.HANDLE / 2, pt.y - visual.HANDLE / 2, visual.HANDLE, visual.HANDLE);
		});
		ctx.restore();
	}

	function canvasPos(e, canvas) {
		var rect = canvas.getBoundingClientRect();
		var scaleX = canvas.width / rect.width;
		var scaleY = canvas.height / rect.height;
		return {
			x: (e.clientX - rect.left) * scaleX,
			y: (e.clientY - rect.top) * scaleY,
		};
	}

	function hitVisual(mx, my) {
		var sel = getSelectedArea();
		if (!sel || !visual.layout) return null;
		var r = areaPixelRect(sel.area, visual.layout);
		var hs = {
			nw: { x: r.x, y: r.y },
			ne: { x: r.x + r.w, y: r.y },
			sw: { x: r.x, y: r.y + r.h },
			se: { x: r.x + r.w, y: r.y + r.h },
		};
		var names = ['nw', 'ne', 'sw', 'se'];
		for (var i = 0; i < names.length; i++) {
			var pt = hs[names[i]];
			if (Math.abs(mx - pt.x) <= visual.HANDLE && Math.abs(my - pt.y) <= visual.HANDLE) {
				return names[i];
			}
		}
		if (mx >= r.x && mx <= r.x + r.w && my >= r.y && my <= r.y + r.h) {
			return 'move';
		}
		return null;
	}

	function bindVisualCanvas() {
		var canvas = document.getElementById('sc-area-canvas');
		if (!canvas || canvas._scBound) return;
		canvas._scBound = true;
		$(canvas).on('mousedown', function (e) {
			var pos = canvasPos(e, canvas);
			var h = hitVisual(pos.x, pos.y);
			if (!h) return;
			e.preventDefault();
			visual.mode = h === 'move' ? 'move' : 'resize';
			visual.handle = h;
			visual.lastX = pos.x;
			visual.lastY = pos.y;
		});
		$(window).on('mouseup.scVisual', function () {
			visual.mode = null;
			visual.handle = null;
		});
		$(canvas).on('mousemove', function (e) {
			if (!visual.mode || !visual.layout) return;
			var sel = getSelectedArea();
			if (!sel) return;
			var pos = canvasPos(e, canvas);
			var dx = pos.x - visual.lastX;
			var dy = pos.y - visual.lastY;
			visual.lastX = pos.x;
			visual.lastY = pos.y;
			var a = Object.assign({}, sel.area);
			var lx = visual.layout.w / 100;
			var ly = visual.layout.h / 100;
			var dpx = dx / lx;
			var dpy = dy / ly;
			if (visual.mode === 'move') {
				a.x = Math.max(0, Math.min(100 - a.w, a.x + dpx));
				a.y = Math.max(0, Math.min(100 - a.h, a.y + dpy));
			} else {
				var h = visual.handle;
				if (h === 'se') {
					a.w = Math.max(2, Math.min(100 - a.x, a.w + dpx));
					a.h = Math.max(2, Math.min(100 - a.y, a.h + dpy));
				} else if (h === 'ne') {
					var nh = Math.max(2, a.h - dpy);
					var ny = a.y + (a.h - nh);
					a.w = Math.max(2, Math.min(100 - a.x, a.w + dpx));
					a.y = Math.max(0, ny);
					a.h = Math.min(100 - a.y, nh);
				} else if (h === 'sw') {
					var nw = Math.max(2, a.w - dpx);
					var nx = a.x + (a.w - nw);
					a.h = Math.max(2, Math.min(100 - a.y, a.h + dpy));
					a.x = Math.max(0, nx);
					a.w = Math.min(100 - a.x, nw);
				} else if (h === 'nw') {
					var nw2 = Math.max(2, a.w - dpx);
					var nh2 = Math.max(2, a.h - dpy);
					var nx2 = a.x + (a.w - nw2);
					var ny2 = a.y + (a.h - nh2);
					a.x = Math.max(0, nx2);
					a.y = Math.max(0, ny2);
					a.w = Math.min(100 - a.x, nw2);
					a.h = Math.min(100 - a.y, nh2);
				}
			}
			updateAreaInJson({ x: round1(a.x), y: round1(a.y), w: round1(a.w), h: round1(a.h) });
			// re-read and draw
			drawVisualArea();
		});
	}

	function refreshVisualEditor() {
		if (!$('#sc-area-canvas').length) return;
		fillVisualViewSelect();
		bindVisualCanvas();
	}

	$(function () {
		if (!$('#sc_product_data').length) {
			return;
		}

		renderViews();
		renderAreas();
		renderFields();
		refreshVisualEditor();

		$('#sc-add-view').on('click', function () {
			syncViews();
			var views = parseJSON('#sc_customizer_views_json', []);
			views.push({ id: 'view_' + uid(), label: 'View', image_id: 0 });
			$('#sc_customizer_views_json').val(JSON.stringify(views));
			renderViews();
			renderAreas();
			refreshVisualEditor();
		});

		$('#sc-views-list')
			.on('click', '.sc-remove-view', function () {
				$(this).closest('.sc-view-row').remove();
				syncViews();
				renderAreas();
				refreshVisualEditor();
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
			refreshVisualEditor();
		});

		$('#sc-areas-list')
			.on('click', '.sc-remove-area', function () {
				$(this).closest('.sc-area-row').remove();
				syncAreas();
				refreshVisualEditor();
			})
			.on('change', 'input,select', function () {
				syncAreas();
				drawVisualArea();
			});

		$('#sc-visual-view').on('change', fillVisualAreaSelect);
		$('#sc-visual-area').on('change', drawVisualArea);

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
