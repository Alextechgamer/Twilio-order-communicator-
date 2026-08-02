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

	function initCustomizer($root) {
		var canvas = document.getElementById('sc-canvas');
		if (!canvas || !canvas.getContext) {
			return;
		}
		var ctx = canvas.getContext('2d');
		var views = parseData($root, 'views', []);
		var areas = parseData($root, 'areas', []);
		var currentView = views[0] ? views[0].id : null;
		var baseImage = null;
		var artImage = null;
		var placement = { view_id: currentView, x: 50, y: 50, scale: 1, rotation: 0, area_id: null };
		var dragging = false;
		var lastX = 0;
		var lastY = 0;

		function areasForView(viewId) {
			return areas.filter(function (a) {
				return a.view_id === viewId;
			});
		}

		function loadView(viewId) {
			currentView = viewId;
			placement.view_id = viewId;
			var view = views.find(function (v) {
				return v.id === viewId;
			});
			if (!view || !view.url) {
				ctx.clearRect(0, 0, canvas.width, canvas.height);
				ctx.fillStyle = '#f0f0f0';
				ctx.fillRect(0, 0, canvas.width, canvas.height);
				ctx.fillStyle = '#666';
				ctx.fillText('No base image for this view', 20, 40);
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

		function draw() {
			ctx.clearRect(0, 0, canvas.width, canvas.height);
			if (baseImage) {
				var scale = Math.min(canvas.width / baseImage.width, canvas.height / baseImage.height);
				var w = baseImage.width * scale;
				var h = baseImage.height * scale;
				var ox = (canvas.width - w) / 2;
				var oy = (canvas.height - h) / 2;
				ctx.drawImage(baseImage, ox, oy, w, h);

				// Print area outlines (percent of base drawn rect).
				areasForView(currentView).forEach(function (area) {
					var ax = ox + (area.x / 100) * w;
					var ay = oy + (area.y / 100) * h;
					var aw = (area.w / 100) * w;
					var ah = (area.h / 100) * h;
					ctx.save();
					ctx.strokeStyle = 'rgba(0,120,255,0.8)';
					ctx.setLineDash([6, 4]);
					ctx.strokeRect(ax, ay, aw, ah);
					ctx.restore();
					if (!placement.area_id) {
						placement.area_id = area.id;
					}
				});
			}

			if (artImage) {
				var size = 120 * (placement.scale || 1);
				ctx.save();
				ctx.translate((placement.x / 100) * canvas.width, (placement.y / 100) * canvas.height);
				ctx.rotate(((placement.rotation || 0) * Math.PI) / 180);
				ctx.drawImage(artImage, -size / 2, -size / 2, size, size);
				ctx.restore();
			}

			$('#sc_placement').val(JSON.stringify(placement));
		}

		$root.on('click', '.sc-view-btn', function () {
			loadView($(this).data('view-id'));
		});

		$('#sc-upload').on('change', function (e) {
			var file = e.target.files && e.target.files[0];
			if (!file) {
				return;
			}
			var url = URL.createObjectURL(file);
			var img = new Image();
			img.onload = function () {
				artImage = img;
				draw();
			};
			img.src = url;
		});

		$('#sc-reset').on('click', function () {
			artImage = null;
			placement.scale = 1;
			placement.rotation = 0;
			placement.x = 50;
			placement.y = 50;
			$('#sc-upload').val('');
			draw();
		});

		$(canvas).on('mousedown', function (e) {
			if (!artImage) {
				return;
			}
			dragging = true;
			lastX = e.offsetX;
			lastY = e.offsetY;
		});
		$(window).on('mouseup', function () {
			dragging = false;
		});
		$(canvas).on('mousemove', function (e) {
			if (!dragging || !artImage) {
				return;
			}
			var dx = e.offsetX - lastX;
			var dy = e.offsetY - lastY;
			lastX = e.offsetX;
			lastY = e.offsetY;
			placement.x = Math.max(0, Math.min(100, placement.x + (dx / canvas.width) * 100));
			placement.y = Math.max(0, Math.min(100, placement.y + (dy / canvas.height) * 100));
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
