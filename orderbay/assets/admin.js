(function ($) {
	'use strict';
	$(document).on('click', '#doaction, #doaction2', function () {
		var $wrap = $(this).closest('.bulkactions');
		var $sel = $wrap.find('select').first();
		var action = $sel.val();
		if (!action) return true;
		var $form = $sel.closest('form');

		function inject(name, val) {
			$form.find('input[name="' + name + '"]').remove();
			$('<input type="hidden" />').attr('name', name).val(val).appendTo($form);
		}

		if (action === 'ob_add_note') {
			var note = window.prompt('Orderbay private note for selected orders:', '');
			if (note === null) return false;
			inject('ob_bulk_note', note);
		}
		if (action === 'ob_add_tag') {
			var tag = window.prompt('Tag to add to selected orders:', '');
			if (tag === null || !String(tag).trim()) return false;
			inject('ob_bulk_tag', String(tag).trim());
		}
		if (action === 'ob_price_pct') {
			var pct = window.prompt('Adjust regular price by percent (e.g. 10 or -5):', '10');
			if (pct === null || pct === '') return false;
			inject('ob_price_pct', pct);
		}
		if (action === 'ob_price_fixed') {
			var price = window.prompt('Set regular price to:', '');
			if (price === null || price === '') return false;
			inject('ob_fixed_price', price);
		}
		if (action === 'ob_set_stock') {
			var qty = window.prompt('Set stock quantity to:', '0');
			if (qty === null) return false;
			inject('ob_stock_qty', qty);
		}
		if (action === 'ob_assign_cat') {
			var cat = window.prompt('Product category term ID:', '');
			if (cat === null || cat === '') return false;
			inject('ob_cat_id', cat);
		}
		return true;
	});
})(jQuery);
