(function ($) {
	'use strict';
	var cfg = window.scLicense || window.obLicense;
	if (!cfg || !cfg.prefix) return;
	var p = cfg.prefix;

	function applyState(d) {
		if (!d) return;
		$('#' + p + '-license-status-label')
			.text(d.status_label || d.status || '')
			.attr('class', 'toc-license-status toc-license-status--' + (d.status || 'inactive'));
		$('#' + p + '-license-updates-note').text(
			' — ' + (d.allows_updates ? cfg.i18n.updatesOn : cfg.i18n.updatesOff)
		);
		$('#' + p + '-lic-site').text(d.site_url || '—');
		if (d.activations != null && d.max_sites != null) {
			$('#' + p + '-lic-acts').text(d.activations + ' / ' + d.max_sites);
		} else {
			$('#' + p + '-lic-acts').text('—');
		}
		$('#' + p + '-lic-exp').text(d.expires_at ? d.expires_at : cfg.i18n.lifetime);
		$('#' + p + '-lic-email').text(d.customer_email || '—');
		$('#' + p + '-lic-check').text(d.last_check || '—');
		if (d.instance_id) $('#' + p + '-lic-instance').text(d.instance_id);
		if (d.masked_key) $('#' + p + '-license-key').attr('placeholder', d.masked_key).val('');
		if (d.message) {
			$('#' + p + '-license-msg').html('<span style="color:green">' + d.message + '</span>');
		}
	}

	function fail(r) {
		var msg =
			(r && r.data && (r.data.message || r.data.error)) ||
			cfg.i18n.requestFailed;
		if (r && r.data && r.data.status_label) applyState(r.data);
		$('#' + p + '-license-msg').html('<span style="color:#b32d2e">' + msg + '</span>');
	}

	function post(action, extra, $btn, idle) {
		var payload = $.extend({ action: action, nonce: cfg.nonce }, extra || {});
		$.post(cfg.ajax, payload)
			.done(function (r) {
				$btn.prop('disabled', false).text(idle);
				if (r && r.success) applyState(r.data);
				else fail(r);
			})
			.fail(function () {
				$btn.prop('disabled', false).text(idle);
				$('#' + p + '-license-msg').text(cfg.i18n.requestFailed);
			});
	}

	$('#' + p + '-license-activate').on('click', function () {
		var $btn = $(this).prop('disabled', true).text(cfg.i18n.activating);
		$('#' + p + '-license-msg').text('');
		post(p + '_license_activate', { license_key: $('#' + p + '-license-key').val() || '' }, $btn, cfg.i18n.activate);
	});
	$('#' + p + '-license-deactivate').on('click', function () {
		var $btn = $(this).prop('disabled', true).text(cfg.i18n.deactivating);
		$('#' + p + '-license-msg').text('');
		post(
			p + '_license_deactivate',
			{ clear_key: $('#' + p + '-license-clear-key').is(':checked') ? 1 : 0 },
			$btn,
			cfg.i18n.deactivate
		);
	});
	$('#' + p + '-license-refresh').on('click', function () {
		var $btn = $(this).prop('disabled', true).text(cfg.i18n.checking);
		$('#' + p + '-license-msg').text('');
		post(p + '_license_refresh', {}, $btn, cfg.i18n.recheck);
	});
	$('#' + p + '-license-save-server').on('click', function () {
		var $btn = $(this).prop('disabled', true).text(cfg.i18n.saving);
		$('#' + p + '-license-msg').text('');
		post(p + '_license_save_server', { server_url: $('#' + p + '-license-server').val() || '' }, $btn, cfg.i18n.saveServer);
	});
})(jQuery);
