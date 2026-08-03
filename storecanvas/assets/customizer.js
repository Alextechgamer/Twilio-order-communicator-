(function ($) {
	'use strict';

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
	 * Layer model:
	 * { id, label, image: Image|null, fileName, placementByView: { [viewId]: { area_id, x, y, scale, rotation } } }
	 * sc_placement: active view + active layer placement (back-compat)
	 * sc_layers_json: serializable layer placements (no Image blobs)
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
		var mode = null; // 'drag' | 'resize' | 'rotate'
		var resizeCorner = null;
		var lastX = 0;
		var lastY = 0;
		var rotateStartAngle = 0;
		var rotateStartRot = 0;
		var HANDLE = 10;
		var ROTATE_OFFSET = 28;

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
			if (!activeLayerId) {
				return null;
			}
			for (var i = 0; i < layers.length; i++) {
				if (layers[i].id === activeLayerId) {
					return layers[i];
				}
			}
			return null;
		}

		function getPlacement(layer) {
			layer = layer || getActiveLayer();
			if (!layer || !currentView) {
				return defaultPlacement(currentView);
			}
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
			if (!layout || !area) {
				return null;
			}
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

		function artSize(layer, p, areaRect) {
			if (!layer || !layer.image || !areaRect) {
				return { w: 0, h: 0 };
			}
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
			if (!areaRect || !layer || !layer.image) {
				return p;
			}
			var size = artSize(layer, p, areaRect);
			var minX = (size.w / 2 / areaRect.w) * 100;
			var maxX = 100 - minX;
			var minY = (size.h / 2 / areaRect.h) * 100;
			var maxY = 100 - minY;
			if (minX > maxX) {
				p.x = 50;
			} else {
				p.x = Math.max(minX, Math.min(maxX, p.x));
			}
			if (minY > maxY) {
				p.y = 50;
			} else {
				p.y = Math.max(minY, Math.min(maxY, p.y));
			}
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
				if (Math.abs(mx - pt.x) <= pad && Math.abs(my - pt.y) <= pad) {
					return n;
				}
			}
			return null;
		}

		function hitArt(mx, my, layer, p, areaRect) {
			var size = artSize(layer, p, areaRect);
			var c = artCenter(p, areaRect);
			// Approximate without inverse-rotation for hit test (good enough for UX).
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
				return {
					id: L.id,
					label: L.label || 'Layer ' + (idx + 1),
					fileName: L.fileName || '',
					order: idx,
					placements: placements,
				};
			});
		}

		function syncHidden() {
			var active = getActiveLayer();
			var p = getPlacement(active);
			var payload = {
				current_view: currentView,
				active_layer: activeLayerId,
				// Back-compat single placement for cart (active view / active layer).
				view_id: currentView,
				area_id: p.area_id,
				x: p.x,
				y: p.y,
				scale: p.scale,
				rotation: p.rotation || 0,
				// Multi-view map for active layer only (0.3/0.4 consumers).
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

		function renderLayerList() {
			var $list = $root.find('#sc-layers-list');
			if (!$list.length) {
				return;
			}
			$list.empty();
			layers.forEach(function (L, idx) {
				var active = L.id === activeLayerId ? ' active' : '';
				var $row = $(
					'<div class="sc-layer-row' +
						active +
						'" data-id="' +
						L.id +
						'">' +
						'<button type="button" class="button sc-layer-select">' +
						esc(L.label || 'Layer ' + (idx + 1)) +
						'</button> ' +
						'<button type="button" class="button sc-layer-up" title="Up">↑</button>' +
						'<button type="button" class="button sc-layer-down" title="Down">↓</button>' +
						'<button type="button" class="button sc-layer-remove" title="Remove">×</button>' +
						'</div>'
				);
				$list.append($row);
			});
		}

		function esc(s) {
			return String(s)
				.replace(/&/g, '&')
				.replace(/</g, '<')
				.replace(/>/g, '>')
				.replace(/"/g, '"');
		}

		function drawLayerArt(layer, selected) {
			if (!layer.image || !activeArea) {
				return;
			}
			var p = getPlacement(layer);
			p = constrainPlacement(layer, p, activeArea);
			layer.placementByView[currentView] = p;
			var size = artSize(layer, p, activeArea);
			var c = artCenter(p, activeArea);
			ctx.save();
			ctx.translate(c.cx, c.cy);
			ctx.rotate(((p.rotation || 0) * Math.PI) / 180);
			ctx.drawImage(layer.image, -size.w / 2, -size.h / 2, size.w, size.h);
			ctx.restore();

			if (selected) {
				// Selection box (axis-aligned around center; simple UX)
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
				// Blue rotate handle + stem
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
					if (!r) {
						return;
					}
					// Print area (blue)
					ctx.save();
					ctx.strokeStyle = idx === 0 ? 'rgba(0,120,255,0.95)' : 'rgba(0,120,255,0.45)';
					ctx.lineWidth = idx === 0 ? 2 : 1;
					ctx.setLineDash([6, 4]);
					ctx.strokeRect(r.x, r.y, r.w, r.h);
					ctx.restore();

					// Safe margin green guide
					if (idx === 0) {
						var marginPct = parseFloat(validation.safe_margin_pct);
						if (isNaN(marginPct) || marginPct < 0) {
							marginPct = 5;
						}
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
							if (!pl.area_id) {
								pl.area_id = area.id;
							}
						});
					}
				});
			}

			// Draw layers bottom → top
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
				ctx.fillText('No base image for this view', 20, 40);
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
					if (a) {
						pl.area_id = a.id;
					}
					layer.placementByView[v.id] = pl;
				}
			});
		}

		function addLayerFromFile(file, makeActive) {
			if (!file) {
				return;
			}
			var url = URL.createObjectURL(file);
			var img = new Image();
			img.onload = function () {
				var layer = {
					id: uid('l'),
					label: file.name ? file.name.replace(/\.[^.]+$/, '') : 'Layer ' + (layers.length + 1),
					fileName: file.name || '',
					image: img,
					placementByView: {},
				};
				seedPlacements(layer);
				layers.push(layer);
				if (makeActive !== false) {
					activeLayerId = layer.id;
				}
				journeyLog('layer_add', layer.label, productId);
				draw();
			};
			img.src = url;
		}

		function rotateActive(delta) {
			var layer = getActiveLayer();
			if (!layer || !activeArea) {
				return;
			}
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
			if (!file) {
				return;
			}
			// First upload: replace empty stack or add first layer; keep file in sc_artwork for cart.
			if (!layers.length) {
				addLayerFromFile(file, true);
			} else {
				// Replace active layer image, or add if none active.
				var url = URL.createObjectURL(file);
				var img = new Image();
				img.onload = function () {
					var layer = getActiveLayer();
					if (!layer) {
						addLayerFromFile(file, true);
						return;
					}
					layer.image = img;
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
			// Trigger file pick for a new layer (does not replace sc_artwork primary).
			var input = document.createElement('input');
			input.type = 'file';
			input.accept = 'image/png,image/jpeg,image/svg+xml,image/webp';
			input.onchange = function () {
				var f = input.files && input.files[0];
				if (f) {
					addLayerFromFile(f, true);
				}
			};
			input.click();
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
				journeyLog('layer_reorder', 'up:' + id, productId);
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
				journeyLog('layer_reorder', 'down:' + id, productId);
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
			if (!activeArea || !layers.length) {
				return;
			}
			e.preventDefault();
			var pos = canvasPos(e);

			// Hit-test top → bottom for selection
			var hitLayer = null;
			for (var i = layers.length - 1; i >= 0; i--) {
				var L = layers[i];
				if (!L.image) {
					continue;
				}
				var p = getPlacement(L);
				var h = hitHandle(pos.x, pos.y, L, p, activeArea);
				if (h || hitArt(pos.x, pos.y, L, p, activeArea)) {
					hitLayer = L;
					if (L.id !== activeLayerId) {
						activeLayerId = L.id;
					}
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
			if (!hitLayer) {
				mode = null;
			}
			lastX = pos.x;
			lastY = pos.y;
			draw();
		});

		$(window).on('mouseup touchend', function () {
			if (mode) {
				journeyLog(mode === 'rotate' ? 'rotate' : mode, 'end', productId);
			}
			mode = null;
			resizeCorner = null;
		});

		$(canvas).on('mousemove touchmove', function (e) {
			if (!mode || !activeArea) {
				return;
			}
			var layer = getActiveLayer();
			if (!layer || !layer.image) {
				return;
			}
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
				if (resizeCorner === 'nw' || resizeCorner === 'sw') {
					delta = (-dx + dy) * 0.005;
				}
				if (resizeCorner === 'nw' || resizeCorner === 'ne') {
					// keep corner-ish feel
					delta = (Math.abs(dx) > Math.abs(dy) ? dx : -dy) * 0.005;
					if (resizeCorner === 'nw') {
						delta = (-dx - dy) * 0.005;
					} else if (resizeCorner === 'ne') {
						delta = (dx - dy) * 0.005;
					} else if (resizeCorner === 'sw') {
						delta = (-dx + dy) * 0.005;
					} else {
						delta = (dx + dy) * 0.005;
					}
				}
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
			if (!layer || !layer.image || !activeArea) {
				return;
			}
			e.preventDefault();
			var p = getPlacement(layer);
			var delta = e.originalEvent.deltaY > 0 ? -0.08 : 0.08;
			p.scale = (p.scale || 1) + delta;
			layer.placementByView[currentView] = constrainPlacement(layer, p, activeArea);
			draw();
		});

		// ---- Saved designs (optional) ----
		function designPayload() {
			return {
				current_view: currentView,
				active_layer: activeLayerId,
				layers: serializeLayers(),
				placement: JSON.parse($root.find('#sc_placement').val() || '{}'),
			};
		}

		$(document).on('click', '#sc-save-design', function () {
			if (typeof scDesigns === 'undefined' || !scDesigns || !scDesigns.ajax) {
				return;
			}
			var title = window.prompt('Name this design', 'My design');
			if (title === null) {
				return;
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
					alert('Design saved.');
				} else {
					alert((res && res.data && res.data.message) || 'Could not save design.');
				}
			});
		});

		$(document).on('click', '#sc-load-designs', function () {
			if (typeof scDesigns === 'undefined' || !scDesigns || !scDesigns.ajax) {
				return;
			}
			var $box = $('#sc-designs-list').empty().text('Loading…');
			$.post(scDesigns.ajax, {
				action: 'sc_list_designs',
				nonce: scDesigns.nonce,
				product_id: productId,
			}).done(function (res) {
				$box.empty();
				if (!res || !res.success || !res.data.items || !res.data.items.length) {
					$box.text('No saved designs.');
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
							if (!r2 || !r2.success || !r2.data.payload) {
								return;
							}
							applyDesignPayload(r2.data.payload);
							journeyLog('design_load', String(item.id), productId);
						});
					});
					$box.append($b).append(' ');
				});
			});
		});

		function applyDesignPayload(payload) {
			if (!payload || typeof payload !== 'object') {
				return;
			}
			// Restore layer transforms; images must be re-uploaded by customer (no server art blobs).
			if (Array.isArray(payload.layers) && payload.layers.length) {
				// Map saved placements onto existing layers by order when images present.
				payload.layers.forEach(function (saved, idx) {
					if (layers[idx]) {
						var L = layers[idx];
						var places = saved.placements || {};
						Object.keys(places).forEach(function (vid) {
							L.placementByView[vid] = $.extend(defaultPlacement(vid), places[vid]);
						});
						if (saved.label) {
							L.label = saved.label;
						}
					}
				});
			} else if (payload.placement && layers[0]) {
				var pl = payload.placement;
				if (pl.placements) {
					Object.keys(pl.placements).forEach(function (vid) {
						layers[0].placementByView[vid] = $.extend(defaultPlacement(vid), pl.placements[vid]);
					});
				} else if (pl.view_id) {
					layers[0].placementByView[pl.view_id] = $.extend(defaultPlacement(pl.view_id), pl);
				}
			}
			if (payload.current_view) {
				loadView(payload.current_view);
			} else {
				draw();
			}
		}

		// Expose for designs / external hooks
		$root.data('scCustomizerApi', {
			getPayload: designPayload,
			applyPayload: applyDesignPayload,
			draw: draw,
		});

		journeyLog('customizer_init', 'views=' + views.length, productId);

		if (currentView) {
			loadView(currentView);
		} else {
			draw();
		}
	}

	$(function () {
		$('.sc-customizer').each(function () {
			initCustomizer($(this));
		});
	});
})(jQuery);
