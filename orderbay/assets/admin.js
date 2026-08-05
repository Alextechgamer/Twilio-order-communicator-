(function ($) {
	'use strict';
	// Prompt for bulk note / price / stock when those actions are chosen.
	$(document).on('click', '#doaction, #doaction2', function () {
		var $sel = $(this).siblings('select').length
			? $(this).siblings('select')
			: $(this).closest('.bulkactions').find('select');
		var action = $sel.val();
		if (!action) return true;

		if (action === 'ob_add_note') {
			var note = window.prompt('Orderbay private note for selected orders:', '');
			if (note === null) return false;
			$('<input type="hidden" name="ob_bulk_note" />').val(note).appendTo($sel.closest('form'));
		}
		if (action === 'ob_price_fixed') {
			var price = window.prompt('Set regular price to:', '');
			if (price === null || price === '') return false;
			$('<input type="hidden" name="ob_fixed_price" />').val(price).appendTo($sel.closest('form'));
		}
		if (action === 'ob_set_stock') {
			var qty = window.prompt('Set stock quantity to:', '0');
			if (qty === null) return false;
			$('<input type="hidden" name="ob_stock_qty" />').val(qty).appendTo($sel.closest('form'));
		}
		if (action === 'ob_assign_cat') {
			var cat = window.prompt('Product category term ID:', '');
			if (cat === null || cat === '') return false;
			$('<input type="hidden" name="ob_cat_id" />').val(cat).appendTo($sel.closest('form'));
		}
		return true;
	});
})(jQuery);
