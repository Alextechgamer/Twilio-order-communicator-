(function ($) {
	'use strict';

	// Translatable UI strings come from scCustomizer.i18n (see class-sc-plugin.php); the English
	// fallback keeps behavior unchanged if a key is missing.
	var I18N = (typeof scCustomizer !== 'undefined' && scCustomizer.i18n) || {};
	function scT(key, fallback) {
		return I18N && I18N[key] ? I18N[key] : fallback;
	}

	function parseData($el, key, fallback) {
		try {
			var raw = $el.attr('data-' + key);
			return raw ? JSON.parse(raw) : fallback;
		} catch (e) {
			return fallback;
		}
	}

	function uid(prefix) {
		return (prefix || 'l') + Math.random().toString(36).slice(2, 8);
	}

	function journeyLog(event, detail, productId) {
		if (typeof scJourney === 'undefined' || !scJourney || !scJourney.enabled || !scJourney.ajax) {
			return;
		}
		try {
			$.post(scJourney.ajax, {
				action: 'sc_journey_log',
				nonce: scJourney.nonce,
				event: event,
				detail: detail || '',
				product_id: productId || 0,
			});
		} catch (e) {
			/* ignore */
		}
	}

	/**
	 * Layer model (0.7.0):
	 * { id, type: 'image'|'text'|'clipart', label, content?, fontSize?, fill?, fontFamily?,
	 *   image?, fileName?, clipart_id?, srcUrl?,
	 *   placementByView: { [viewId]: { area_id, x, y, scale, rotation } } }
	 */
	function initCustomizer($root) {
		var canvas = $root.find('canvas').get(0) || document.getElementById('sc-canvas');
		if (!canvas || !canvas.getContext) {
			return;
		}
		var ctx = canvas.getContext('2d');
		var views = parseData($root, 'views', []);
		var areas = parseData($root, 'areas', []);
		var validation = parseData($root, 'validation', {});
		var productId = parseInt($root.attr('data-product-id'), 10) || 0;
		var currentView = views[0] ? views[0].id : null;
		var baseImage = null;
		var layout = null;
		var activeArea = null;
		var layers = [];
		var activeLayerId = null;
		var mode = null;
		var resizeCorner = null;
		var lastX = 0;
		var lastY = 0;
		var rotateStartAngle = 0;
		var rotateStartRot = 0;
		var HANDLE = 10;
		var ROTATE_OFFSET = 28;
		var guestToken =
			typeof scDesigns !== 'undefined' && scDesigns && scDesigns.token ? scDesigns.token : '';

		function defaultPlacement(viewId) {
			return {
				view_id: viewId,
				area_id: null,
				x: 50,
				y: 50,
				scale: 1,
				rotation: 0,
			};
		}

		function getActiveLayer() {
			if (!activeLayerId) return null;
			for (var i = 0; i < layers.length; i++) {
				if (layers[i].id === activeLayerId) return layers[i];
			}
			return null;
		}

		function getPlacement(layer) {
			layer = layer || getActiveLayer();
			if (!layer || !currentView) return defaultPlacement(currentView);
			if (!layer.placementByView[currentView]) {
				layer.placementByView[currentView] = defaultPlacement(currentView);
			}
			return layer.placementByView[currentView];
		}

		function areasForView(viewId) {
			return areas.filter(function (a) {
				return a.view_id === viewId;
			});
		}

		function areaPixelRect(area) {
			if (!layout || !area) return null;
			return {
				x: layout.ox + (area.x / 100) * layout.w,
				y: layout.oy + (area.y / 100) * layout.h,
				w: (area.w / 100) * layout.w,
				h: (area.h / 100) * layout.h,
			};
		}

		function primaryArea(viewId) {
			var list = areasForView(viewId);
			return list.length ? list[0] : null;
		}

		function isText(layer) {
			return layer && layer.type === 'text';
		}

		function hasDrawable(layer) {
			return layer && (isText(layer) || layer.image);
		}

		function measureText(layer, p) {
			var content = layer.content || 'Text';
			var size = (layer.fontSize || 28) * (p.scale || 1);
			ctx.save();
			ctx.font = size + 'px ' + (layer.fontFamily || 'Arial, Helvetica, sans-serif');
			var w = ctx.measureText(content).width;
			ctx.restore();
			return { w: Math.max(12, w), h: Math.max(12, size * 1.25) };
		}

		function artSize(layer, p, areaRect) {
			if (!layer || !areaRect) return { w: 0, h: 0 };
			if (isText(layer)) {
				return measureText(layer, p);
			}
			if (!layer.image) return { w: 0, h: 0 };
			var maxW = areaRect.w * 0.5 * (p.scale || 1);
			var ratio = layer.image.height / layer.image.width;
			return { w: maxW, h: maxW * ratio };
		}

		function artCenter(p, areaRect) {
			return {
				cx: areaRect.x + (p.x / 100) * areaRect.w,
				cy: areaRect.y + (p.y / 100) * areaRect.h,
			};
		}

		function constrainPlacement(layer, p, areaRect) {
			if (!areaRect || !hasDrawable(layer)) return p;
			var size = artSize(layer, p, areaRect);
			var minX = (size.w / 2 / areaRect.w) * 100;
			var maxX = 100 - minX;
			var minY = (size.h / 2 / areaRect.h) * 100;
			var maxY = 100 - minY;
			if (minX > maxX) p.x = 50;
			else p.x = Math.max(minX, Math.min(maxX, p.x));
			if (minY > maxY) p.y = 50;
			else p.y = Math.max(minY, Math.min(maxY, p.y));
			p.scale = Math.max(0.2, Math.min(2.5, p.scale || 1));
			p.rotation = ((p.rotation || 0) % 360 + 360) % 360;
			return p;
		}

		function handlePoints(layer, p, areaRect) {
			var size = artSize(layer, p, areaRect);
			var c = artCenter(p, areaRect);
			var hw = size.w / 2;
			var hh = size.h / 2;
			return {
				nw: { x: c.cx - hw, y: c.cy - hh },
				ne: { x: c.cx + hw, y: c.cy - hh },
				sw: { x: c.cx - hw, y: c.cy + hh },
				se: { x: c.cx + hw, y: c.cy + hh },
				rotate: { x: c.cx, y: c.cy - hh - ROTATE_OFFSET },
			};
		}

		function hitHandle(mx, my, layer, p, areaRect) {
			var pts = handlePoints(layer, p, areaRect);
			var names = ['nw', 'ne', 'sw', 'se', 'rotate'];
			for (var i = 0; i < names.length; i++) {
				var n = names[i];
				var pt = pts[n];
				var pad = n === 'rotate' ? HANDLE + 2 : HANDLE;
				if (Math.abs(mx - pt.x) <= pad && Math.abs(my - pt.y) <= pad) return n;
			}
			return null;
		}

		function hitArt(mx, my, layer, p, areaRect) {
			var size = artSize(layer, p, areaRect);
			var c = artCenter(p, areaRect);
			return (
				mx >= c.cx - size.w / 2 &&
				mx <= c.cx + size.w / 2 &&
				my >= c.cy - size.h / 2 &&
				my <= c.cy + size.h / 2
			);
		}

		function serializeLayers() {
			return layers.map(function (L, idx) {
				var placements = {};
				Object.keys(L.placementByView || {}).forEach(function (vid) {
					var pl = L.placementByView[vid];
					placements[vid] = {
						view_id: vid,
						area_id: pl.area_id || null,
						x: pl.x,
						y: pl.y,
						scale: pl.scale,
						rotation: pl.rotation || 0,
					};
				});
				var row = {
					id: L.id,
					type: L.type || 'image',
					name: L.label || 'Layer ' + (idx + 1),
					label: L.label || 'Layer ' + (idx + 1),
					fileName: L.fileName || '',
					order: idx,
					placements: placements,
					placementByView: placements,
				};
				if (L.type === 'text') {
					row.content = L.content || '';
					row.fontSize = L.fontSize || 28;
					row.fill = L.fill || '#111111';
					row.fontFamily = L.fontFamily || 'Arial, Helvetica, sans-serif';
					row.strokeColor = L.strokeColor || '';
					row.strokeWidth = L.strokeWidth || 0;
				}
				if (L.clipart_id) row.clipart_id = L.clipart_id;
				// Full rehydrate: prefer explicit src, then srcUrl, then object URL if still live.
				var src = L.src || L.srcUrl || '';
				if (!src && L.image && L.image.src) src = L.image.src;
				if (src) {
					row.src = src;
					row.srcUrl = src;
				}
				if (L.attachment_id) row.attachment_id = L.attachment_id;
				return row;
			});
		}

		function syncHidden() {
			var active = getActiveLayer();
			var p = getPlacement(active);
			var payload = {
				current_view: currentView,
				active_layer: activeLayerId,
				view_id: currentView,
				area_id: p.area_id,
				x: p.x,
				y: p.y,
				scale: p.scale,
				rotation: p.rotation || 0,
				placements: active
					? (function () {
							var out = {};
							Object.keys(active.placementByView || {}).forEach(function (vid) {
								out[vid] = active.placementByView[vid];
							});
							return out;
					  })()
					: {},
				layers: serializeLayers(),
			};
			$root.find('#sc_placement, input[name="sc_placement"]').val(JSON.stringify(payload));
			$root.find('#sc_layers_json, input[name="sc_layers_json"]').val(JSON.stringify(serializeLayers()));
		}

		function truncate(s, n) {
			s = String(s || '');
			return s.length > n ? s.slice(0, n - 1) + '…' : s;
		}

		function renderLayerList() {
			var $list = $root.find('#sc-layers-list');
			if (!$list.length) return;
			$list.empty();
			layers.forEach(function (L, idx) {
				var active = L.id === activeLayerId ? ' active' : '';
				var label =
					L.type === 'text'
						? 'T: ' + truncate(L.content || 'Text', 24)
						: L.label || 'Layer ' + (idx + 1);
				var $row = $(
					'<div class="sc-layer-row' +
						active +
						'" data-id="' +
						L.id +
						'">' +
						'<button type="button" class="button sc-layer-select">' +
						esc(label) +
						'</button> ' +
						'<button type="button" class="button sc-layer-up" title="' + scT('layerUp', 'Up') + '">↑</button>' +
						'<button type="button" class="button sc-layer-down" title="' + scT('layerDown', 'Down') + '">↓</button>' +
						'<button type="button" class="button sc-layer-remove" title="' + scT('layerRemove', 'Remove') + '">×</button>' +
						'</div>'
				);
				$list.append($row);
			});
			syncTextEditor();
		}

		function esc(s) {
			return String(s)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		}

		function syncTextEditor() {
			var $ed = $root.find('#sc-text-editor');
			var L = getActiveLayer();
			if (!L || L.type !== 'text') {
				$ed.hide();
				return;
			}
			$ed.show();
			$root.find('#sc-text-content').val(L.content || '');
			$root.find('#sc-text-size').val(L.fontSize || 28);
			$root.find('#sc-text-fill').val(L.fill || '#111111');
			$root.find('#sc-text-font').val(L.fontFamily || 'Arial, Helvetica, sans-serif');
			$root.find('#sc-text-stroke-color').val(L.strokeColor || '#000000');
			$root.find('#sc-text-stroke-width').val(L.strokeWidth != null ? L.strokeWidth : 0);
		}

		function drawLayerArt(layer, selected) {
			if (!hasDrawable(layer) || !activeArea) return;
			var p = getPlacement(layer);
			p = constrainPlacement(layer, p, activeArea);
			layer.placementByView[currentView] = p;
			var size = artSize(layer, p, activeArea);
			var c = artCenter(p, activeArea);
			ctx.save();
			ctx.translate(c.cx, c.cy);
			ctx.rotate(((p.rotation || 0) * Math.PI) / 180);
			if (isText(layer)) {
				var fs = (layer.fontSize || 28) * (p.scale || 1);
				ctx.font = fs + 'px ' + (layer.fontFamily || 'Arial, Helvetica, sans-serif');
				ctx.textAlign = 'center';
				ctx.textBaseline = 'middle';
				var txt = layer.content || 'Text';
				var sw = parseFloat(layer.strokeWidth) || 0;
				if (sw > 0) {
					ctx.lineWidth = sw * 2;
					ctx.strokeStyle = layer.strokeColor || '#000000';
					ctx.lineJoin = 'round';
					ctx.strokeText(txt, 0, 0);
				}
				ctx.fillStyle = layer.fill || '#111111';
				ctx.fillText(txt, 0, 0);
			} else {
				ctx.drawImage(layer.image, -size.w / 2, -size.h / 2, size.w, size.h);
			}
			ctx.restore();

			if (selected) {
				ctx.save();
				ctx.strokeStyle = 'rgba(255,140,0,0.9)';
				ctx.setLineDash([]);
				ctx.lineWidth = 1;
				ctx.strokeRect(c.cx - size.w / 2, c.cy - size.h / 2, size.w, size.h);
				var pts = handlePoints(layer, p, activeArea);
				['nw', 'ne', 'sw', 'se'].forEach(function (n) {
					var pt = pts[n];
					ctx.fillStyle = '#fff';
					ctx.strokeStyle = '#ff8c00';
					ctx.fillRect(pt.x - HANDLE / 2, pt.y - HANDLE / 2, HANDLE, HANDLE);
					ctx.strokeRect(pt.x - HANDLE / 2, pt.y - HANDLE / 2, HANDLE, HANDLE);
				});
				ctx.beginPath();
				ctx.strokeStyle = '#2271b1';
				ctx.moveTo(c.cx, c.cy - size.h / 2);
				ctx.lineTo(pts.rotate.x, pts.rotate.y);
				ctx.stroke();
				ctx.beginPath();
				ctx.fillStyle = '#2271b1';
				ctx.strokeStyle = '#fff';
				ctx.lineWidth = 2;
				ctx.arc(pts.rotate.x, pts.rotate.y, HANDLE / 2 + 1, 0, Math.PI * 2);
				ctx.fill();
				ctx.stroke();
				ctx.restore();
			}
		}

		function draw() {
			ctx.clearRect(0, 0, canvas.width, canvas.height);
			layout = null;
			activeArea = null;

			if (baseImage) {
				var scale = Math.min(canvas.width / baseImage.width, canvas.height / baseImage.height);
				var w = baseImage.width * scale;
				var h = baseImage.height * scale;
				var ox = (canvas.width - w) / 2;
				var oy = (canvas.height - h) / 2;
				layout = { ox: ox, oy: oy, w: w, h: h };
				ctx.drawImage(baseImage, ox, oy, w, h);

				areasForView(currentView).forEach(function (area, idx) {
					var r = areaPixelRect(area);
					if (!r) return;
					ctx.save();
					ctx.strokeStyle = idx === 0 ? 'rgba(0,120,255,0.95)' : 'rgba(0,120,255,0.45)';
					ctx.lineWidth = idx === 0 ? 2 : 1;
					ctx.setLineDash([6, 4]);
					ctx.strokeRect(r.x, r.y, r.w, r.h);
					ctx.restore();
					if (idx === 0) {
						var marginPct = parseFloat(validation.safe_margin_pct);
						if (isNaN(marginPct) || marginPct < 0) marginPct = 5;
						var mx = (marginPct / 100) * r.w;
						var my = (marginPct / 100) * r.h;
						if (mx * 2 < r.w && my * 2 < r.h) {
							ctx.save();
							ctx.strokeStyle = 'rgba(0,180,80,0.9)';
							ctx.lineWidth = 1.5;
							ctx.setLineDash([4, 3]);
							ctx.strokeRect(r.x + mx, r.y + my, r.w - mx * 2, r.h - my * 2);
							ctx.restore();
						}
						activeArea = r;
						layers.forEach(function (L) {
							var pl = getPlacement(L);
							if (!pl.area_id) pl.area_id = area.id;
						});
					}
				});
			}

			layers.forEach(function (L) {
				drawLayerArt(L, L.id === activeLayerId);
			});

			syncHidden();
			renderLayerList();
		}

		function loadView(viewId) {
			currentView = viewId;
			var view = views.find(function (v) {
				return v.id === viewId;
			});
			$root.find('.sc-view-btn').removeClass('active');
			$root.find('.sc-view-btn[data-view-id="' + viewId + '"]').addClass('active');
			journeyLog('view_switch', String(viewId), productId);

			if (!view || !view.url) {
				baseImage = null;
				ctx.clearRect(0, 0, canvas.width, canvas.height);
				ctx.fillStyle = '#f0f0f0';
				ctx.fillRect(0, 0, canvas.width, canvas.height);
				ctx.fillStyle = '#666';
				ctx.fillText(scT('noBaseImage', 'No base image for this view'), 20, 40);
				syncHidden();
				return;
			}
			var img = new Image();
			img.crossOrigin = 'anonymous';
			img.onload = function () {
				baseImage = img;
				draw();
			};
			img.src = view.url;
		}

		function seedPlacements(layer) {
			views.forEach(function (v) {
				if (!layer.placementByView[v.id]) {
					var pl = defaultPlacement(v.id);
					var a = primaryArea(v.id);
					if (a) pl.area_id = a.id;
					layer.placementByView[v.id] = pl;
				}
			});
		}

		function addLayerFromFile(file, makeActive) {
			if (!file) return;
			var url = URL.createObjectURL(file);
			var img = new Image();
			img.onload = function () {
				var layer = {
					id: uid('l'),
					type: 'image',
					label: file.name ? file.name.replace(/\.[^.]+$/, '') : 'Layer ' + (layers.length + 1),
					fileName: file.name || '',
					image: img,
					src: url,
					srcUrl: url,
					placementByView: {},
				};
				seedPlacements(layer);
				layers.push(layer);
				if (makeActive !== false) activeLayerId = layer.id;
				journeyLog('layer_add', layer.label, productId);
				draw();
			};
			img.src = url;
		}

		function addLayerFromUrl(url, opts) {
			opts = opts || {};
			var img = new Image();
			img.crossOrigin = 'anonymous';
			img.onload = function () {
				var layer = {
					id: uid('c'),
					type: opts.type || 'clipart',
					label: opts.label || 'Clip-art',
					fileName: '',
					image: img,
					srcUrl: url,
					clipart_id: opts.clipart_id || 0,
					placementByView: {},
				};
				seedPlacements(layer);
				layers.push(layer);
				activeLayerId = layer.id;
				journeyLog('library_add', layer.label, productId);
				draw();
			};
			img.onerror = function () {
				alert(scT('libImgError', 'Could not load library image.'));
			};
			img.src = url;
		}

		function addTextLayer() {
			var layer = {
				id: uid('t'),
				type: 'text',
				label: 'Text',
				content: scT('defaultText', 'Your text'),
				fontSize: 28,
				fill: '#111111',
				fontFamily: 'Arial, Helvetica, sans-serif',
				strokeColor: '',
				strokeWidth: 0,
				placementByView: {},
			};
			seedPlacements(layer);
			layers.push(layer);
			activeLayerId = layer.id;
			journeyLog('text_add', layer.content, productId);
			draw();
		}

		function rotateActive(delta) {
			var layer = getActiveLayer();
			if (!layer || !activeArea) return;
			var p = getPlacement(layer);
			p.rotation = (p.rotation || 0) + delta;
			layer.placementByView[currentView] = constrainPlacement(layer, p, activeArea);
			journeyLog('rotate', String(p.rotation), productId);
			draw();
		}

		// ---- Events ----
		$root.on('click', '.sc-view-btn', function () {
			loadView($(this).data('view-id'));
		});

		$root.find('#sc-upload, input[name="sc_artwork"]').on('change', function (e) {
			var file = e.target.files && e.target.files[0];
			if (!file) return;
			if (!layers.length) {
				addLayerFromFile(file, true);
			} else {
				var url = URL.createObjectURL(file);
				var img = new Image();
				img.onload = function () {
					var layer = getActiveLayer();
					if (!layer || layer.type === 'text') {
						addLayerFromFile(file, true);
						return;
					}
					layer.image = img;
					layer.type = 'image';
					layer.fileName = file.name || layer.fileName;
					layer.label = file.name ? file.name.replace(/\.[^.]+$/, '') : layer.label;
					seedPlacements(layer);
					journeyLog('artwork_upload', layer.fileName, productId);
					draw();
				};
				img.src = url;
			}
			journeyLog('artwork_upload', file.name || '', productId);
		});

		$root.find('#sc-add-layer').on('click', function () {
			var input = document.createElement('input');
			input.type = 'file';
			input.accept = 'image/png,image/jpeg,image/svg+xml,image/webp';
			input.onchange = function () {
				var f = input.files && input.files[0];
				if (f) addLayerFromFile(f, true);
			};
			input.click();
		});

		$root.find('#sc-add-text').on('click', function () {
			addTextLayer();
		});

		$root.find('#sc-toggle-library').on('click', function () {
			var $panel = $root.find('#sc-library-panel');
			if ($panel.is(':visible')) {
				$panel.hide();
				return;
			}
			$panel.show();
			loadLibrary();
		});

		function loadLibrary() {
			var $box = $root.find('#sc-library-thumbs').empty().text('Loading…');
			if (typeof scLibrary === 'undefined' || !scLibrary || !scLibrary.ajax) {
				$box.text(scT('libUnavailable', 'Library unavailable.'));
				return;
			}
			$.get(scLibrary.ajax, {
				action: 'sc_library_items',
				nonce: scLibrary.nonce,
				product_id: productId,
			}).done(function (res) {
				$box.empty();
				if (!res || !res.success || !res.data.items || !res.data.items.length) {
					$box.text(scT('noClipart', 'No clip-art available.'));
					return;
				}
				res.data.items.forEach(function (item) {
					var $a = $(
						'<button type="button" class="sc-lib-item" title="' +
							esc(item.title) +
							'" style="border:1px solid #ccc;padding:2px;background:#fff;cursor:pointer;">' +
							'<img src="' +
							esc(item.thumb || item.url) +
							'" alt="" style="width:64px;height:64px;object-fit:contain;display:block;" />' +
							'</button>'
					);
					$a.on('click', function () {
						addLayerFromUrl(item.url, {
							type: 'clipart',
							label: item.title || 'Clip-art',
							clipart_id: item.id,
						});
					});
					$box.append($a);
				});
			});
		}

		// Text editor bindings
		$root.on('input change', '#sc-text-content, #sc-text-size, #sc-text-fill, #sc-text-font, #sc-text-stroke-color, #sc-text-stroke-width', function () {
			var L = getActiveLayer();
			if (!L || L.type !== 'text') return;
			L.content = $root.find('#sc-text-content').val() || '';
			L.fontSize = parseFloat($root.find('#sc-text-size').val()) || 28;
			L.fill = $root.find('#sc-text-fill').val() || '#111111';
			L.fontFamily = $root.find('#sc-text-font').val() || 'Arial, Helvetica, sans-serif';
			L.strokeColor = $root.find('#sc-text-stroke-color').val() || '';
			L.strokeWidth = parseFloat($root.find('#sc-text-stroke-width').val()) || 0;
			L.label = truncate(L.content, 24) || 'Text';
			draw();
		});

		$root.find('#sc-rotate-left').on('click', function () {
			rotateActive(-15);
		});
		$root.find('#sc-rotate-right').on('click', function () {
			rotateActive(15);
		});

		$root.find('#sc-reset').on('click', function () {
			layers = [];
			activeLayerId = null;
			$root.find('#sc-upload, input[name="sc_artwork"]').val('');
			journeyLog('reset', '', productId);
			draw();
		});

		$root.on('click', '.sc-layer-select', function () {
			activeLayerId = $(this).closest('.sc-layer-row').data('id');
			journeyLog('layer_select', String(activeLayerId), productId);
			draw();
		});
		$root.on('click', '.sc-layer-remove', function () {
			var id = $(this).closest('.sc-layer-row').data('id');
			layers = layers.filter(function (L) {
				return L.id !== id;
			});
			if (activeLayerId === id) {
				activeLayerId = layers.length ? layers[layers.length - 1].id : null;
			}
			journeyLog('layer_remove', String(id), productId);
			draw();
		});
		$root.on('click', '.sc-layer-up', function () {
			var id = $(this).closest('.sc-layer-row').data('id');
			var i = layers.findIndex(function (L) {
				return L.id === id;
			});
			if (i > 0) {
				var tmp = layers[i - 1];
				layers[i - 1] = layers[i];
				layers[i] = tmp;
				draw();
			}
		});
		$root.on('click', '.sc-layer-down', function () {
			var id = $(this).closest('.sc-layer-row').data('id');
			var i = layers.findIndex(function (L) {
				return L.id === id;
			});
			if (i >= 0 && i < layers.length - 1) {
				var tmp = layers[i + 1];
				layers[i + 1] = layers[i];
				layers[i] = tmp;
				draw();
			}
		});

		function canvasPos(e) {
			var rect = canvas.getBoundingClientRect();
			var scaleX = canvas.width / rect.width;
			var scaleY = canvas.height / rect.height;
			var oe = e.originalEvent || e;
			var clientX = e.clientX != null ? e.clientX : oe.touches[0].clientX;
			var clientY = e.clientY != null ? e.clientY : oe.touches[0].clientY;
			return {
				x: (clientX - rect.left) * scaleX,
				y: (clientY - rect.top) * scaleY,
			};
		}

		function angleAt(mx, my, cx, cy) {
			return (Math.atan2(my - cy, mx - cx) * 180) / Math.PI;
		}

		$(canvas).on('mousedown touchstart', function (e) {
			if (!activeArea || !layers.length) return;
			e.preventDefault();
			var pos = canvasPos(e);
			var hitLayer = null;
			for (var i = layers.length - 1; i >= 0; i--) {
				var L = layers[i];
				if (!hasDrawable(L)) continue;
				var p = getPlacement(L);
				var h = hitHandle(pos.x, pos.y, L, p, activeArea);
				if (h || hitArt(pos.x, pos.y, L, p, activeArea)) {
					hitLayer = L;
					if (L.id !== activeLayerId) activeLayerId = L.id;
					if (h === 'rotate') {
						mode = 'rotate';
						resizeCorner = null;
						var c = artCenter(p, activeArea);
						rotateStartAngle = angleAt(pos.x, pos.y, c.cx, c.cy);
						rotateStartRot = p.rotation || 0;
					} else if (h) {
						mode = 'resize';
						resizeCorner = h;
					} else {
						mode = 'drag';
						resizeCorner = null;
					}
					break;
				}
			}
			if (!hitLayer) mode = null;
			lastX = pos.x;
			lastY = pos.y;
			draw();
		});

		$(window).on('mouseup touchend', function () {
			mode = null;
			resizeCorner = null;
		});

		$(canvas).on('mousemove touchmove', function (e) {
			if (!mode || !activeArea) return;
			var layer = getActiveLayer();
			if (!layer || !hasDrawable(layer)) return;
			e.preventDefault();
			var pos = canvasPos(e);
			var p = getPlacement(layer);
			var dx = pos.x - lastX;
			var dy = pos.y - lastY;
			lastX = pos.x;
			lastY = pos.y;

			if (mode === 'drag') {
				p.x += (dx / activeArea.w) * 100;
				p.y += (dy / activeArea.h) * 100;
			} else if (mode === 'resize') {
				var delta = (dx + dy) * 0.005;
				if (resizeCorner === 'nw') delta = (-dx - dy) * 0.005;
				else if (resizeCorner === 'ne') delta = (dx - dy) * 0.005;
				else if (resizeCorner === 'sw') delta = (-dx + dy) * 0.005;
				else delta = (dx + dy) * 0.005;
				p.scale = (p.scale || 1) + delta;
			} else if (mode === 'rotate') {
				var c = artCenter(p, activeArea);
				var ang = angleAt(pos.x, pos.y, c.cx, c.cy);
				p.rotation = rotateStartRot + (ang - rotateStartAngle);
			}

			layer.placementByView[currentView] = constrainPlacement(layer, p, activeArea);
			draw();
		});

		$(canvas).on('wheel', function (e) {
			var layer = getActiveLayer();
			if (!layer || !hasDrawable(layer) || !activeArea) return;
			e.preventDefault();
			var p = getPlacement(layer);
			var delta = e.originalEvent.deltaY > 0 ? -0.08 : 0.08;
			p.scale = (p.scale || 1) + delta;
			layer.placementByView[currentView] = constrainPlacement(layer, p, activeArea);
			draw();
		});

		// ---- Saved designs ----
		function designPayload() {
			return {
				current_view: currentView,
				active_layer: activeLayerId,
				layers: serializeLayers(),
				placement: JSON.parse($root.find('#sc_placement').val() || '{}'),
			};
		}

		function showGuestHint(msg) {
			var $h = $('#sc-guest-design-hint');
			if ($h.length) {
				$h.show().text(msg);
			}
		}

		$(document).on('click', '#sc-save-design', function () {
			if (typeof scDesigns === 'undefined' || !scDesigns || !scDesigns.ajax) return;
			var title = scT('defaultDesignName', 'My design');
			if (scDesigns.loggedIn) {
				var t = window.prompt(scT('promptName', 'Name this design'), scT('defaultDesignName', 'My design'));
				if (t === null) return;
				title = t;
			}
			$.post(scDesigns.ajax, {
				action: 'sc_save_design',
				nonce: scDesigns.nonce,
				product_id: productId,
				title: title,
				payload: JSON.stringify(designPayload()),
			}).done(function (res) {
				if (res && res.success) {
					journeyLog('design_save', title, productId);
					if (res.data && res.data.mode === 'guest') {
						guestToken = res.data.token || guestToken;
						showGuestHint(
							(res.data.message || scT('designSaved', 'Design saved.')) +
								(guestToken ? ' Token: ' + guestToken.slice(0, 8) + '…' : '')
						);
						alert(res.data.message || scT('designSavedDevice', 'Design saved on this device.'));
					} else {
						alert(scT('designSaved', 'Design saved.'));
					}
				} else {
					alert((res && res.data && res.data.message) || scT('saveFailed', 'Could not save design.'));
				}
			});
		});

		$(document).on('click', '#sc-load-designs', function () {
			if (typeof scDesigns === 'undefined' || !scDesigns || !scDesigns.ajax) return;
			var $box = $('#sc-designs-list').empty().text('Loading…');
			$.post(scDesigns.ajax, {
				action: 'sc_list_designs',
				nonce: scDesigns.nonce,
				product_id: productId,
			}).done(function (res) {
				$box.empty();
				if (!res || !res.success || !res.data.items || !res.data.items.length) {
					$box.text(scT('noSavedDesigns', 'No saved designs.'));
					return;
				}
				res.data.items.forEach(function (item) {
					var $b = $('<button type="button" class="button" style="margin:2px;">').text(item.title);
					$b.on('click', function () {
						$.post(scDesigns.ajax, {
							action: 'sc_load_design',
							nonce: scDesigns.nonce,
							id: item.id,
						}).done(function (r2) {
							if (!r2 || !r2.success || !r2.data.payload) return;
							applyDesignPayload(r2.data.payload);
							journeyLog('design_load', String(item.id), productId);
						});
					});
					$box.append($b).append(' ');
				});
			});
		});

		function loadGuestDesign(token) {
			if (typeof scDesigns === 'undefined' || !scDesigns || !scDesigns.ajax) return;
			$.post(scDesigns.ajax, {
				action: 'sc_load_design',
				nonce: scDesigns.nonce,
				token: token || guestToken || '',
				product_id: productId,
			}).done(function (res) {
				if (!res || !res.success || !res.data.payload) {
					alert((res && res.data && res.data.message) || scT('noSavedDesign', 'No saved design found.'));
					return;
				}
				if (res.data.token) guestToken = res.data.token;
				applyDesignPayload(res.data.payload);
				showGuestHint(scT('designReloaded', 'Design reloaded.'));
				journeyLog('design_load_guest', guestToken.slice(0, 8), productId);
			});
		}

		$(document).on('click', '#sc-reload-guest-design', function () {
			loadGuestDesign(guestToken);
		});

		$(document).on('click', '#sc-email-guest-design', function () {
			if (typeof scDesigns === 'undefined' || !scDesigns || !scDesigns.ajax) return;
			if (!guestToken) {
				alert(scT('saveFirst', 'Save a design first.'));
				return;
			}
			var email = window.prompt(scT('promptEmail', 'Email address for your design link'), '');
			if (!email) return;
			$.post(scDesigns.ajax, {
				action: 'sc_email_design_link',
				nonce: scDesigns.nonce,
				email: email,
				product_id: productId,
				token: guestToken,
			}).done(function (res) {
				if (res && res.success) {
					alert(res.data.message || scT('linkEmailed', 'Link emailed.'));
				} else {
					alert((res && res.data && res.data.message) || scT('emailFailed', 'Could not send email.'));
				}
			});
		});

		/**
		 * Full rehydrate from saved design JSON (guest token + CPT).
		 * Clears current layers then rebuilds from payload.
		 */
		function applyDesignPayload(payload) {
			if (!payload || typeof payload !== 'object') return;

			var layerList = payload.layers;
			if (!Array.isArray(layerList) && payload.placement && Array.isArray(payload.placement.layers)) {
				layerList = payload.placement.layers;
			}
			if (!Array.isArray(layerList) || !layerList.length) {
				if (payload.placement && layers[0]) {
					var pl0 = payload.placement;
					if (pl0.placements) {
						Object.keys(pl0.placements).forEach(function (vid) {
							layers[0].placementByView[vid] = $.extend(defaultPlacement(vid), pl0.placements[vid]);
						});
					}
					draw();
				}
				return;
			}

			// Clear canvas layers for clean hydrate.
			layers = [];
			activeLayerId = null;
			var pending = 0;
			var next = [];
			var skipped = 0;

			function finish() {
				// Preserve saved order.
				next.sort(function (a, b) {
					return (a._order || 0) - (b._order || 0);
				});
				layers = next.map(function (L) {
					delete L._order;
					return L;
				});
				if (layers.length) {
					activeLayerId = payload.active_layer || layers[layers.length - 1].id;
					// Ensure active_layer exists.
					var found = false;
					layers.forEach(function (L) {
						if (L.id === activeLayerId) found = true;
					});
					if (!found) activeLayerId = layers[layers.length - 1].id;
				}
				if (skipped) {
					showGuestHint('Restored design (' + skipped + ' layer(s) could not load).');
				}
				if (payload.current_view) loadView(payload.current_view);
				else draw();
			}

			function applyPlacements(L, saved) {
				var places = saved.placements || saved.placementByView || {};
				Object.keys(places).forEach(function (vid) {
					L.placementByView[vid] = $.extend(defaultPlacement(vid), places[vid]);
				});
				seedPlacements(L);
			}

			function loadImageLayer(sv, order) {
				var src = sv.src || sv.srcUrl || '';
				pending++;
				var img = new Image();
				img.crossOrigin = 'anonymous';
				img.onload = function () {
					var L = {
						id: sv.id || uid('img'),
						type: sv.type || 'image',
						label: sv.name || sv.label || 'Layer',
						fileName: sv.fileName || '',
						image: img,
						src: src || img.src,
						srcUrl: src || img.src,
						clipart_id: sv.clipart_id || 0,
						attachment_id: sv.attachment_id || 0,
						placementByView: {},
						_order: order,
					};
					applyPlacements(L, sv);
					next.push(L);
					pending--;
					if (pending === 0) finish();
				};
				img.onerror = function () {
					skipped++;
					pending--;
					if (pending === 0) finish();
				};
				if (src) {
					img.src = src;
				} else if (sv.clipart_id && typeof scLibrary !== 'undefined' && scLibrary.ajax) {
					$.get(scLibrary.ajax, {
						action: 'sc_library_items',
						nonce: scLibrary.nonce,
						product_id: productId,
					})
						.done(function (res) {
							var found = null;
							if (res && res.success && res.data.items) {
								res.data.items.forEach(function (it) {
									if (it.id === sv.clipart_id) found = it;
								});
							}
							if (found) {
								img.src = found.url;
							} else {
								skipped++;
								pending--;
								if (pending === 0) finish();
							}
						})
						.fail(function () {
							skipped++;
							pending--;
							if (pending === 0) finish();
						});
				} else {
					skipped++;
					pending--;
					if (pending === 0) finish();
				}
			}

			layerList.forEach(function (saved, order) {
				if ((saved.type || 'image') === 'text') {
					var tl = {
						id: saved.id || uid('t'),
						type: 'text',
						label: saved.name || saved.label || truncate(saved.content, 24) || 'Text',
						content: saved.content || 'Text',
						fontSize: saved.fontSize || 28,
						fill: saved.fill || '#111111',
						fontFamily: saved.fontFamily || 'Arial, Helvetica, sans-serif',
						strokeColor: saved.strokeColor || '',
						strokeWidth: saved.strokeWidth || 0,
						placementByView: {},
						_order: order,
					};
					applyPlacements(tl, saved);
					next.push(tl);
				} else {
					// image | clipart
					loadImageLayer(saved, order);
				}
			});
			if (pending === 0) finish();
		}

		$root.data('scCustomizerApi', {
			getPayload: designPayload,
			applyPayload: applyDesignPayload,
			draw: draw,
		});

		journeyLog('customizer_init', 'views=' + views.length, productId);

		if (currentView) loadView(currentView);
		else draw();

		// Auto-load guest design from ?sc_design=
		if (guestToken) {
			setTimeout(function () {
				loadGuestDesign(guestToken);
			}, 400);
		}
	}

	$(function () {
		$('.sc-customizer').each(function () {
			initCustomizer($(this));
		});
	});
})(jQuery);
