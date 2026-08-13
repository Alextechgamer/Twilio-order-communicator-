(function ($) {
	'use strict';
	function post(action, data, $msg) {
		data = data || {};
		data.action = action;
		data.nonce = (window.orlAdmin && orlAdmin.nonce) || '';
		return $.post((window.orlAdmin && orlAdmin.ajax) || ajaxurl, data).fail(function () {
			if ($msg) $msg.text((orlAdmin.i18n && orlAdmin.i18n.failed) || 'Failed');
		});
	}

	$(document).on('click', '#orl-test-connection', function () {
		var $btn = $(this);
		var $msg = $('#orl-test-msg');
		$btn.prop('disabled', true);
		$msg.text(orlAdmin.i18n.testing);
		post('orl_test_connection', {}).done(function (res) {
			if (res && res.success) {
				$msg.text(res.data.friendly_name || 'OK');
			} else {
				$msg.text((res && res.data && (res.data.error || res.data.message)) || 'Failed');
			}
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$(document).on('click', '.orl-use-tpl', function () {
		var $box = $(this).closest('.orl-order-box');
		// Template is already the textarea default; re-read from current value if user cleared it.
		var $ta = $box.find('.orl-sms-body');
		if (!$ta.data('tpl')) {
			$ta.data('tpl', $ta.val());
		}
		$ta.val($ta.data('tpl'));
	});

	$(document).on('click', '.orl-send-sms', function () {
		var $btn = $(this);
		var $box = $btn.closest('.orl-order-box');
		var $msg = $box.find('.orl-sms-msg');
		var body = $box.find('.orl-sms-body').val();
		var orderId = $box.data('order');
		function send(force) {
			$btn.prop('disabled', true);
			$msg.text(orlAdmin.i18n.sending);
			post('orl_send_sms', { order_id: orderId, body: body, force: force ? 1 : 0 }).done(function (res) {
				if (res && res.success) {
					$msg.text(res.data.message || 'Sent');
					return;
				}
				var code = res && res.data && res.data.code;
				var err = (res && res.data && res.data.message) || 'Failed';
				if (code === 'needs_force' && window.confirm(orlAdmin.i18n.force)) {
					send(true);
					return;
				}
				$msg.text(err);
			}).always(function () {
				$btn.prop('disabled', false);
			});
		}
		send(false);
	});
})(jQuery);
