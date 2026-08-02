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

	/**
	 * placementByView: { [viewId]: { area_id, x, y, scale, rotation } }
	 * x,y are 0–100 relative to the active print area (not full canvas).
	 * scale is relative to area width (1 = 50% of area width).
	 */
	function initCustomizer($root) {
		var canvas = $root.find('canvas').get(0) || document.getElementById('sc-canvas');
		if (!canvas || !canvas.getContext) {
			return;
		}
		var ctx = canvas.getContext('2d');
		var views = parseData($root, 'views', []);
		var areas = parseData($root, 'areas', []);
		var currentView = views[0] ? views[0].id : null;
		var baseImage = null;
		var artImage = null;
		var layout = null; // { ox, oy, w, h } base image draw rect on canvas
		var activeArea = null; // pixel rect of print area on canvas
		var placementByView = {};
		var mode = null; // 'drag' | 'resize'
		var resizeCorner = null; // 'nw'|'ne'|'sw'|'se'
		var lastX = 0;
		var lastY = 0;
		var HANDLE = 10;

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

		function getPlacement() {
			if (!currentView) {
				return defaultPlacement(null);
			}
			if (!placementByView[currentView]) {
				placementByView[currentView] = defaultPlacement(currentView);
			}
			return placementByView[currentView];
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

		function artSize(p, areaRect) {
			if (!artImage || !areaRect) {
				return { w: 0, h: 0 };
			}
			var maxW = areaRect.w * 0.5 * (p.scale || 1);
			var ratio = artImage.height / artImage.width;
			return { w: maxW, h: maxW * ratio };
		}

		function artCenter(p, areaRect) {
			return {
				cx: areaRect.x + (p.x / 100) * areaRect.w,
				cy: areaRect.y + (p.y / 100) * areaRect.h,
			};
		}

		function constrainPlacement(p, areaRect) {
			if (!areaRect || !artImage) {
				return p;
			}
			var size = artSize(p, areaRect);
			// Keep center inside area so art stays mostly within bounds.
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
			// Scale limits: not larger than the area.
			var maxScale = Math.min(areaRect.w / (artImage.width * 0.01), 4);
			// Practical clamp: scale 0.2 – 2.5 relative units.
			p.scale = Math.max(0.2, Math.min(2.5, p.scale || 1));
			return p;
		}

		function handlePoints(p, areaRect) {
			var size = artSize(p, areaRect);
			var c = artCenter(p, areaRect);
			var hw = size.w / 2;
			var hh = size.h / 2;
			return {
				nw: { x: c.cx - hw, y: c.cy - hh },
				ne: { x: c.cx + hw, y: c.cy - hh },
				sw: { x: c.cx - hw, y: c.cy + hh },
				se: { x: c.cx + hw, y: c.cy + hh },
			};
		}

		function hitHandle(mx, my, p, areaRect) {
			var pts = handlePoints(p, areaRect);
			var names = ['nw', 'ne', 'sw', 'se'];
			for (var i = 0; i < names.length; i++) {
				var n = names[i];
				var pt = pts[n];
				if (Math.abs(mx - pt.x) <= HANDLE && Math.abs(my - pt.y) <= HANDLE) {
					return n;
				}
			}
			return null;
		}

		function hitArt(mx, my, p, areaRect) {
			var size = artSize(p, areaRect);
			var c = artCenter(p, areaRect);
			return (
				mx >= c.cx - size.w / 2 &&
				mx <= c.cx + size.w / 2 &&
				my >= c.cy - size.h / 2 &&
				my <= c.cy + size.h / 2
			);
		}

		function syncHidden() {
			var payload = {
				current_view: currentView,
				placements: placementByView,
				// Back-compat single placement for cart (active view).
				view_id: currentView,
				area_id: getPlacement().area_id,
				x: getPlacement().x,
				y: getPlacement().y,
				scale: getPlacement().scale,
				rotation: getPlacement().rotation,
			};
			$root.find('#sc_placement, input[name="sc_placement"]').val(JSON.stringify(payload));
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
					ctx.save();
					ctx.strokeStyle = idx === 0 ? 'rgba(0,120,255,0.95)' : 'rgba(0,120,255,0.45)';
					ctx.lineWidth = idx === 0 ? 2 : 1;
					ctx.setLineDash([6, 4]);
					ctx.strokeRect(r.x, r.y, r.w, r.h);
					ctx.restore();
					if (idx === 0) {
						activeArea = r;
						var p0 = getPlacement();
						if (!p0.area_id) {
							p0.area_id = area.id;
						}
					}
				});
			}

			var p = getPlacement();
			if (artImage && activeArea) {
				p = constrainPlacement(p, activeArea);
				placementByView[currentView] = p;
				var size = artSize(p, activeArea);
				var c = artCenter(p, activeArea);
				ctx.save();
				ctx.translate(c.cx, c.cy);
				ctx.rotate(((p.rotation || 0) * Math.PI) / 180);
				ctx.drawImage(artImage, -size.w / 2, -size.h / 2, size.w, size.h);
				ctx.restore();

				// Selection box + handles
				ctx.save();
				ctx.strokeStyle = 'rgba(255,140,0,0.9)';
				ctx.setLineDash([]);
				ctx.lineWidth = 1;
				ctx.strokeRect(c.cx - size.w / 2, c.cy - size.h / 2, size.w, size.h);
				var pts = handlePoints(p, activeArea);
				['nw', 'ne', 'sw', 'se'].forEach(function (n) {
					var pt = pts[n];
					ctx.fillStyle = '#fff';
					ctx.strokeStyle = '#ff8c00';
					ctx.fillRect(pt.x - HANDLE / 2, pt.y - HANDLE / 2, HANDLE, HANDLE);
					ctx.strokeRect(pt.x - HANDLE / 2, pt.y - HANDLE / 2, HANDLE, HANDLE);
				});
				ctx.restore();
			}

			syncHidden();
		}

		function loadView(viewId) {
			currentView = viewId;
			var view = views.find(function (v) {
				return v.id === viewId;
			});
			$root.find('.sc-view-btn').removeClass('active');
			$root.find('.sc-view-btn[data-view-id="' + viewId + '"]').addClass('active');

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

		$root.on('click', '.sc-view-btn', function () {
			loadView($(this).data('view-id'));
		});

		$root.find('#sc-upload, input[type=file]').on('change', function (e) {
			var file = e.target.files && e.target.files[0];
			if (!file) {
				return;
			}
			var url = URL.createObjectURL(file);
			var img = new Image();
			img.onload = function () {
				artImage = img;
				// Seed placement on every view that has an area.
				views.forEach(function (v) {
					if (!placementByView[v.id]) {
						var pl = defaultPlacement(v.id);
						var a = primaryArea(v.id);
						if (a) {
							pl.area_id = a.id;
						}
						placementByView[v.id] = pl;
					}
				});
				draw();
			};
			img.src = url;
		});

		$root.find('#sc-reset').on('click', function () {
			artImage = null;
			placementByView = {};
			$root.find('#sc-upload, input[type=file]').val('');
			draw();
		});

		function canvasPos(e) {
			var rect = canvas.getBoundingClientRect();
			var scaleX = canvas.width / rect.width;
			var scaleY = canvas.height / rect.height;
			var clientX = e.clientX != null ? e.clientX : e.originalEvent.touches[0].clientX;
			var clientY = e.clientY != null ? e.clientY : e.originalEvent.touches[0].clientY;
			return {
				x: (clientX - rect.left) * scaleX,
				y: (clientY - rect.top) * scaleY,
			};
		}

		$(canvas).on('mousedown touchstart', function (e) {
			if (!artImage || !activeArea) {
				return;
			}
			e.preventDefault();
			var pos = canvasPos(e);
			var p = getPlacement();
			var h = hitHandle(pos.x, pos.y, p, activeArea);
			if (h) {
				mode = 'resize';
				resizeCorner = h;
			} else if (hitArt(pos.x, pos.y, p, activeArea)) {
				mode = 'drag';
				resizeCorner = null;
			} else {
				mode = null;
			}
			lastX = pos.x;
			lastY = pos.y;
		});

		$(window).on('mouseup touchend', function () {
			mode = null;
			resizeCorner = null;
		});

		$(canvas).on('mousemove touchmove', function (e) {
			if (!mode || !artImage || !activeArea) {
				return;
			}
			e.preventDefault();
			var pos = canvasPos(e);
			var p = getPlacement();
			var dx = pos.x - lastX;
			var dy = pos.y - lastY;
			lastX = pos.x;
			lastY = pos.y;

			if (mode === 'drag') {
				p.x += (dx / activeArea.w) * 100;
				p.y += (dy / activeArea.h) * 100;
			} else if (mode === 'resize') {
				// Corner drag changes scale.
				var delta = (dx + dy) * 0.005;
				if (resizeCorner === 'nw' || resizeCorner === 'sw') {
					delta = (-dx + dy) * 0.005;
				}
				if (resizeCorner === 'nw' || resizeCorner === 'ne') {
					delta = (dx - dy) * 0.005;
				}
				p.scale = (p.scale || 1) + delta;
			}

			placementByView[currentView] = constrainPlacement(p, activeArea);
			draw();
		});

		// Wheel to scale when hovering art
		$(canvas).on('wheel', function (e) {
			if (!artImage || !activeArea) {
				return;
			}
			e.preventDefault();
			var p = getPlacement();
			var delta = e.originalEvent.deltaY > 0 ? -0.08 : 0.08;
			p.scale = (p.scale || 1) + delta;
			placementByView[currentView] = constrainPlacement(p, activeArea);
			draw();
		});

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
