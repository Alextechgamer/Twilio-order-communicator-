(function($){
'use strict';

function i18n(key, fallback){
	return (tocData.i18n && tocData.i18n[key]) ? tocData.i18n[key] : (fallback || key);
}

function $chat(){ return $('.toc-chat').first(); }

function refresh($w){
	$.post(tocData.ajax_url,{action:'toc_get_history',nonce:tocData.nonce,order_id:$w.data('order-id')})
	.done(function(r){ if(r.success){ $w.find('.toc-history').html(r.data.html); scroll($w); } });
}
function scroll($w){ var $h=$w.find('.toc-history'); if($h[0]) $h.scrollTop($h[0].scrollHeight); }

function errText(data){
	if(data == null) return i18n('unknown', 'Unknown');
	if(typeof data === 'string') return data;
	if(typeof data === 'object'){
		if(data.message) return data.message;
		if(data.code) return data.code;
	}
	return String(data);
}

function send(type, force){
	var $w=$chat(), msg=$('#toc-message').val().trim(), phone=$w.data('phone');
	if(!msg){ alert(i18n('enterMessage', 'Enter a message')); return; }
	if(!phone){ alert(i18n('noPhone', 'No phone number on this order')); return; }

	if(type === 'sms' && !force){
		var consented = String($w.data('consent')) === '1';
		var optedOut = String($w.data('opted-out')) === '1';
		if(optedOut){
			if(!confirm(i18n('optOutConfirm', 'This phone has opted out (STOP). Send SMS anyway?'))) return;
			force = true;
		} else if(!consented){
			if(!confirm(i18n('noConsentConfirm', 'Customer has not opted in to SMS. Send anyway?'))) return;
			force = true;
		}
	}

	$w.addClass('loading');
	$.post(tocData.ajax_url,{
		action: type==='sms'?'toc_send_sms':'toc_send_call',
		nonce:tocData.nonce, order_id:$w.data('order-id'), phone:phone, message:msg,
		force: force ? 1 : 0
	}).done(function(r){
		$w.removeClass('loading');
		if(r.success){ $('#toc-message').val(''); refresh($w); }
		else {
			var d = r.data;
			if(type==='sms' && d && typeof d === 'object' && d.code === 'needs_force'){
				if(confirm((d.message || i18n('needsForce', 'SMS blocked by consent')) + '\n\n' + i18n('sendAnyway', 'Send anyway?'))){
					send('sms', true);
				}
				return;
			}
			alert(i18n('errorPrefix', 'Error:') + ' ' + errText(d));
		}
	}).fail(function(){ $w.removeClass('loading'); alert(i18n('requestFailed', 'Request failed')); });
}

function resolve(id, orderId, $btn){
	$btn.prop('disabled',true).text('…');
	var data={action:'toc_mark_resolved',nonce:tocData.nonce};
	if(orderId) data.order_id=orderId; else data.id=id;
	$.post(tocData.ajax_url,data).done(function(r){
		if(r.success){
			if(orderId){
				$('.toc-history .toc-bubble').addClass('resolved');
				$('.toc-resolve-order').replaceWith('<span class="toc-badge">'+i18n('conversationDone', 'Conversation resolved')+'</span>');
			} else {
				$btn.closest('tr').addClass('toc-resolved');
				$btn.replaceWith('<span class="toc-badge">'+i18n('resolved', 'Resolved')+'</span>');
				$('.toc-resolve-one[data-id="'+id+'"]').closest('.toc-bubble').addClass('resolved')
					.find('.toc-resolve-one').replaceWith('<span class="tag">'+i18n('resolved', 'Resolved').toLowerCase()+'</span>');
			}
		} else {
			alert(i18n('couldNotResolve', 'Could not mark resolved'));
			$btn.prop('disabled',false).text(i18n('markResolved', 'Mark Resolved'));
		}
	});
}

/* ---------- Bulk sequential sender ---------- */
var bulkState = {
	running: false,
	stop: false,
	ids: [],
	index: 0,
	ok: 0,
	fail: 0,
	skip: 0
};

function updateSelectedCount(){
	var n = $('input[name="order_ids[]"]:checked').length;
	var $el = $('#toc-bulk-selected-count');
	if($el.length) $el.text(n + ' selected');
}

function bulkLog(html, cls){
	var $log = $('#toc-bulk-log').show();
	$log.append('<div class="toc-bulk-line '+(cls||'')+'">'+html+'</div>');
	$log.scrollTop($log[0].scrollHeight);
}

function setBulkUi(running){
	bulkState.running = running;
	$('#toc-run-bulk').prop('disabled', running).text(running ? i18n('sending', 'Sending…') : i18n('sendSelected', 'Send to Selected'));
	$('#toc-stop-bulk').toggle(running);
	$('#toc-bulk-form').toggleClass('toc-bulk-running', running);
	$('input[name="order_ids[]"], #toc-check-all, #toc-bulk-message, input[name="mode"], #toc-bulk-delay')
		.prop('disabled', running);
}

function finishBulk(){
	setBulkUi(false);
	var $st = $('#toc-bulk-status');
	$st.html(
		'<strong style="color:green">'+bulkState.ok+' succeeded</strong>, ' +
		'<strong style="color:#b32d2e">'+bulkState.fail+' failed</strong>, ' +
		'<strong style="color:#646970">'+bulkState.skip+' skipped</strong>'
	);
	bulkLog('Done. '+bulkState.ok+' ok · '+bulkState.fail+' failed · '+bulkState.skip+' skipped.', 'toc-bulk-done');
}

function processNextBulk(msg, mode, delaySec){
	if(bulkState.stop){
		bulkLog('Stopped by user.', 'toc-bulk-warn');
		finishBulk();
		return;
	}
	if(bulkState.index >= bulkState.ids.length){
		finishBulk();
		return;
	}

	var id = bulkState.ids[bulkState.index];
	var n = bulkState.index + 1;
	var total = bulkState.ids.length;
	var $st = $('#toc-bulk-status');
	var $row = $('tr[data-order-id="'+id+'"]');

	$st.text('Processing '+n+' of '+total+' (order #'+id+')…');
	$row.addClass('toc-bulk-active');

	$.post(tocData.ajax_url, {
		action: 'toc_bulk_reminder',
		nonce: tocData.nonce,
		order_id: id,
		message: msg,
		mode: mode,
		delay: delaySec
	}).done(function(r){
		$row.removeClass('toc-bulk-active');
		if(!r || !r.success){
			bulkState.fail++;
			$row.addClass('toc-bulk-fail');
			bulkLog('#'+id+': error — '+(r && r.data ? r.data : 'unknown'), 'toc-bulk-fail');
		} else {
			var d = r.data || {};
			if(d.skipped){
				bulkState.skip++;
				$row.addClass('toc-bulk-skip');
				bulkLog('#'+id+': skipped — '+(d.detail||''), 'toc-bulk-skip');
			} else if(d.ok){
				bulkState.ok++;
				$row.addClass('toc-bulk-ok');
				$row.find('input[name="order_ids[]"]').prop('checked', false);
				bulkLog('#'+id+': OK — '+(d.detail||''), 'toc-bulk-ok');
			} else {
				if(mode === 'sms' && d.detail && String(d.detail).indexOf('no consent') !== -1){
					bulkState.skip++;
					$row.addClass('toc-bulk-skip');
					bulkLog('#'+id+': skipped — '+(d.detail||'no consent'), 'toc-bulk-skip');
				} else {
					bulkState.fail++;
					$row.addClass('toc-bulk-fail');
					bulkLog('#'+id+': failed — '+(d.detail||''), 'toc-bulk-fail');
				}
			}
		}
	}).fail(function(){
		$row.removeClass('toc-bulk-active').addClass('toc-bulk-fail');
		bulkState.fail++;
		bulkLog('#'+id+': request failed', 'toc-bulk-fail');
	}).always(function(){
		bulkState.index++;
		updateSelectedCount();
		if(bulkState.index >= bulkState.ids.length || bulkState.stop){
			if(bulkState.stop) bulkLog('Stopped by user.', 'toc-bulk-warn');
			finishBulk();
			return;
		}
		var waitMs = Math.max(1, delaySec) * 1000;
		$st.text('Waiting '+delaySec+'s before next order… ('+bulkState.index+'/'+total+' done)');
		window._tocBulkTimer = setTimeout(function(){
			processNextBulk(msg, mode, delaySec);
		}, waitMs);
	});
}

$(function(){
	var $w=$chat();
	if($w.length){
		scroll($w);
		$('.toc-tpl').on('click',function(){ $('#toc-message').val($('#toc-tpl-'+$(this).data('tpl')).text()).focus(); });
		$('#toc-sms').on('click',function(){ send('sms', false); });
		$('#toc-call').on('click',function(){ if(confirm(i18n('placeCallConfirm', 'Place a voice call with the current message?'))) send('call', false); });
		$('#toc-message').on('keydown',function(e){ if(e.ctrlKey&&e.key==='Enter') send('sms', false); });
		$(document).on('click','.toc-resolve-order',function(){ resolve(null,$w.data('order-id'),$(this)); });
		$(document).on('click','.toc-resolve-one',function(){ resolve($(this).data('id'),null,$(this)); });
	}

	$(document).on('click','.toc-resolve',function(){ resolve($(this).data('id'),null,$(this)); });

	$('#toc-check-all').on('change',function(){
		$('input[name="order_ids[]"]').prop('checked', this.checked);
		updateSelectedCount();
	});
	$(document).on('change', 'input[name="order_ids[]"]', updateSelectedCount);
	updateSelectedCount();

	$('#toc-stop-bulk').on('click', function(){
		bulkState.stop = true;
		$(this).prop('disabled', true).text(i18n('stopping', 'Stopping…'));
		if(window._tocBulkTimer){
			clearTimeout(window._tocBulkTimer);
			window._tocBulkTimer = null;
			bulkLog('Stopped by user.', 'toc-bulk-warn');
			finishBulk();
		}
	});

	$('#toc-run-bulk').on('click', function(){
		if(bulkState.running) return;

		var ids = [];
		$('input[name="order_ids[]"]:checked').each(function(){ ids.push($(this).val()); });
		if(!ids.length){ alert(i18n('selectOrders', 'Select at least one order')); return; }

		var msg = $('#toc-bulk-message').val().trim();
		if(!msg){ alert(i18n('enterMessage', 'Enter a message')); return; }

		var mode = $('input[name="mode"]:checked').val() || 'call';
		var delaySec = parseInt($('#toc-bulk-delay').val(), 10);
		if(isNaN(delaySec) || delaySec < 1) delaySec = 8;
		if(delaySec > 120) delaySec = 120;

		var modeLabel = mode === 'both' ? i18n('modeBoth', 'call + SMS (SMS only with consent)') : (mode === 'sms' ? i18n('modeSms', 'SMS (consent required)') : i18n('modeCall', 'voice call'));
		var estMin = Math.ceil((ids.length * delaySec) / 60);
		var bulkMsg = i18n('bulkSendConfirm', 'Send {mode} to {count} order(s)?\n\nDelay: {delay}s between each (~{est} min total).')
			.replace('{mode}', modeLabel).replace('{count}', ids.length)
			.replace('{delay}', delaySec).replace('{est}', estMin);
		if(!confirm(bulkMsg)) return;

		if(mode === 'sms' || mode === 'both'){
			var noConsent = 0;
			ids.forEach(function(id){
				var $row = $('tr[data-order-id="'+id+'"]');
				if($row.data('consent') == 0 || $row.data('consent') === '0') noConsent++;
			});
			if(mode === 'sms' && noConsent === ids.length){
				alert(i18n('noConsentAll', 'None of the selected orders have SMS consent. SMS will be skipped for all.'));
				return;
			}
			if(noConsent > 0 && mode === 'sms'){
				if(!confirm(i18n('noConsentSome', '{skipped} of {total} selected order(s) have no SMS consent and will be skipped. Continue?')
					.replace('{skipped}', noConsent).replace('{total}', ids.length))) return;
			}
		}

		bulkState = { running:true, stop:false, ids:ids, index:0, ok:0, fail:0, skip:0 };
		$('#toc-bulk-log').empty().show();
		$('#toc-stop-bulk').prop('disabled', false).text(i18n('stop', 'Stop'));
		$('.toc-bulk-table tr').removeClass('toc-bulk-ok toc-bulk-fail toc-bulk-skip toc-bulk-active');
		setBulkUi(true);
		bulkLog('Starting bulk send: '+ids.length+' order(s), mode='+mode+', delay='+delaySec+'s');
		processNextBulk(msg, mode, delaySec);
	});

	$('#toc-test-btn').on('click',function(){
		var $btn=$(this).prop('disabled',true).text(i18n('testing', 'Testing…'));
		var $r=$('#toc-test-result').text('');
		$.post(tocData.ajax_url,{action:'toc_test_connection',nonce:tocData.nonce})
		.done(function(res){
			$btn.prop('disabled',false).text(i18n('testBtn', 'Run Connection Test'));
			if(res.success) $r.html('<span style="color:green">✓ '+res.data+'</span>');
			else $r.html('<span style="color:#b32d2e">✗ '+(res.data||'Failed')+'</span>');
		}).fail(function(){ $btn.prop('disabled',false).text(i18n('testBtn', 'Run Connection Test')); $r.text(i18n('requestFailed', 'Request failed')); });
	});

	/* ---------- Onboarding wizard ---------- */
	function showWizardStep(step){
		var $wiz = $('.toc-wizard');
		if(!$wiz.length) return;
		$wiz.attr('data-step', step);
		$wiz.find('.toc-wizard-panel').attr('hidden', true);
		$wiz.find('.toc-wizard-panel[data-panel="'+step+'"]').removeAttr('hidden');
		$wiz.find('.toc-wizard-steps li').removeClass('is-active is-done').each(function(){
			var s = parseInt($(this).data('step'), 10);
			if(s === step) $(this).addClass('is-active');
			else if(s < step) $(this).addClass('is-done');
		});
	}

	function wizardPayload(step){
		return {
			action: 'toc_onboarding_save',
			nonce: tocData.nonce,
			step: step,
			toc_account_sid: $('#toc-wiz-sid').val() || '',
			toc_auth_token: $('#toc-wiz-token').val() || '',
			toc_from_number: $('#toc-wiz-from').val() || '',
			toc_checkout_consent_enabled: $('#toc-wiz-checkout-consent').is(':checked') ? 1 : 0,
			toc_require_sms_consent: $('#toc-wiz-require-consent').is(':checked') ? 1 : 0,
			toc_auto_ready_enabled: $('#toc-wiz-auto-ready').is(':checked') ? 1 : 0,
			toc_auto_ready_voice: $('#toc-wiz-auto-ready-voice').is(':checked') ? 1 : 0,
			toc_auto_ready_sms: $('#toc-wiz-auto-ready-sms').is(':checked') ? 1 : 0,
			toc_auto_shipped_enabled: $('#toc-wiz-auto-shipped').is(':checked') ? 1 : 0,
			toc_auto_shipped_voice: $('#toc-wiz-auto-shipped-voice').is(':checked') ? 1 : 0,
			toc_auto_shipped_sms: $('#toc-wiz-auto-shipped-sms').is(':checked') ? 1 : 0,
			toc_quiet_hours_enabled: $('#toc-wiz-quiet').is(':checked') ? 1 : 0,
			toc_quiet_hours_start: $('#toc-wiz-quiet-start').val() || '21:00',
			toc_quiet_hours_end: $('#toc-wiz-quiet-end').val() || '08:00'
		};
	}

	$(document).on('click', '.toc-wiz-next', function(){
		var next = parseInt($(this).data('next'), 10);
		var $btn = $(this).prop('disabled', true);
		$.post(tocData.ajax_url, wizardPayload(next))
		.done(function(r){
			$btn.prop('disabled', false);
			if(r && r.success){
				$('#toc-wiz-token').val('');
				showWizardStep(next);
			} else {
				alert(i18n('errorPrefix', 'Error:') + ' ' + errText(r && r.data));
			}
		}).fail(function(){ $btn.prop('disabled', false); alert(i18n('requestFailed', 'Request failed')); });
	});

	$(document).on('click', '.toc-wiz-back', function(){
		showWizardStep(parseInt($(this).data('back'), 10));
	});

	$('#toc-wiz-test').on('click', function(){
		var $btn = $(this).prop('disabled', true).text(i18n('testing', 'Testing…'));
		var $r = $('#toc-wiz-test-result').text('');
		// Save credentials first, then test.
		$.post(tocData.ajax_url, wizardPayload(2)).always(function(){
			$.post(tocData.ajax_url, {action:'toc_test_connection', nonce:tocData.nonce})
			.done(function(res){
				$btn.prop('disabled', false).text(i18n('testBtn', 'Run Connection Test'));
				if(res.success) $r.html('<span style="color:green">✓ '+res.data+'</span>');
				else $r.html('<span style="color:#b32d2e">✗ '+(res.data||'Failed')+'</span>');
			}).fail(function(){
				$btn.prop('disabled', false).text(i18n('testBtn', 'Run Connection Test'));
				$r.text(i18n('requestFailed', 'Request failed'));
			});
		});
	});

	$('#toc-wiz-copy-webhook').on('click', function(){
		var text = $('#toc-wiz-webhook').text();
		if(navigator.clipboard && navigator.clipboard.writeText){
			navigator.clipboard.writeText(text);
		} else {
			var $tmp = $('<textarea>').val(text).appendTo('body').select();
			document.execCommand('copy');
			$tmp.remove();
		}
		$(this).text('Copied');
		var $b = $(this);
		setTimeout(function(){ $b.text('Copy'); }, 1500);
	});

	$('#toc-wiz-finish').on('click', function(){
		var $btn = $(this).prop('disabled', true);
		$.post(tocData.ajax_url, {action:'toc_onboarding_complete', nonce:tocData.nonce})
		.done(function(r){
			if(r && r.success && r.data && r.data.redirect){
				window.location.href = r.data.redirect;
			} else {
				$btn.prop('disabled', false);
			}
		}).fail(function(){ $btn.prop('disabled', false); alert(i18n('requestFailed', 'Request failed')); });
	});

	$(document).on('click', '.toc-dismiss-onboarding', function(e){
		e.preventDefault();
		var $notice = $(this).closest('.notice');
		$.post(tocData.ajax_url, {action:'toc_onboarding_dismiss', nonce:tocData.nonce})
		.done(function(){ $notice.fadeOut(); });
	});

	/* ---------- License tab ---------- */
	function applyLicenseState(d){
		if(!d) return;
		$('#toc-license-status-label').text(d.status_label || d.status || '')
			.attr('class', 'toc-license-status toc-license-status--'+(d.status||'inactive'));
		$('#toc-license-updates-note').text(' — ' + (d.allows_updates ? i18n('updatesOn', 'premium updates enabled') : i18n('updatesOff', 'premium updates paused')));
		$('#toc-lic-site').text(d.site_url || '—');
		if(d.activations != null && d.max_sites != null){
			$('#toc-lic-acts').text(d.activations + ' / ' + d.max_sites);
		} else {
			$('#toc-lic-acts').text('—');
		}
		$('#toc-lic-exp').text(d.expires_at ? d.expires_at : i18n('lifetime', 'Lifetime / none set'));
		$('#toc-lic-email').text(d.customer_email || '—');
		$('#toc-lic-check').text(d.last_check || '—');
		if(d.instance_id) $('#toc-lic-instance').text(d.instance_id);
		if(d.masked_key){
			$('#toc-license-key').attr('placeholder', d.masked_key).val('');
		}
		if(d.message){
			$('#toc-license-msg').html('<span style="color:green">'+d.message+'</span>');
		}
	}

	function licenseError(r){
		var msg = (r && r.data && (r.data.message || r.data.error)) ? (r.data.message || r.data.error) : errText(r && r.data);
		if(r && r.data && r.data.status_label){
			applyLicenseState(r.data);
		}
		$('#toc-license-msg').html('<span style="color:#b32d2e">'+msg+'</span>');
	}

	$('#toc-license-activate').on('click', function(){
		var $btn = $(this).prop('disabled', true).text(i18n('activating', 'Activating…'));
		$('#toc-license-msg').text('');
		$.post(tocData.ajax_url, {
			action: 'toc_license_activate',
			nonce: tocData.nonce,
			license_key: $('#toc-license-key').val() || ''
		}).done(function(r){
			$btn.prop('disabled', false).text(i18n('activate', 'Activate'));
			if(r && r.success) applyLicenseState(r.data);
			else licenseError(r);
		}).fail(function(){
			$btn.prop('disabled', false).text(i18n('activate', 'Activate'));
			$('#toc-license-msg').text(i18n('requestFailed', 'Request failed'));
		});
	});

	$('#toc-license-deactivate').on('click', function(){
		var $btn = $(this).prop('disabled', true).text(i18n('deactivating', 'Deactivating…'));
		$('#toc-license-msg').text('');
		$.post(tocData.ajax_url, {
			action: 'toc_license_deactivate',
			nonce: tocData.nonce,
			clear_key: $('#toc-license-clear-key').is(':checked') ? 1 : 0
		}).done(function(r){
			$btn.prop('disabled', false).text(i18n('deactivate', 'Deactivate'));
			if(r && r.success) applyLicenseState(r.data);
			else licenseError(r);
		}).fail(function(){
			$btn.prop('disabled', false).text(i18n('deactivate', 'Deactivate'));
			$('#toc-license-msg').text(i18n('requestFailed', 'Request failed'));
		});
	});

	$('#toc-license-refresh').on('click', function(){
		var $btn = $(this).prop('disabled', true).text(i18n('checking', 'Checking…'));
		$('#toc-license-msg').text('');
		$.post(tocData.ajax_url, {
			action: 'toc_license_refresh',
			nonce: tocData.nonce
		}).done(function(r){
			$btn.prop('disabled', false).text(i18n('recheck', 'Re-check'));
			if(r && r.success) applyLicenseState(r.data);
			else licenseError(r);
		}).fail(function(){
			$btn.prop('disabled', false).text(i18n('recheck', 'Re-check'));
			$('#toc-license-msg').text(i18n('requestFailed', 'Request failed'));
		});
	});

	$('#toc-license-save-server').on('click', function(){
		var $btn = $(this).prop('disabled', true).text(i18n('saving', 'Saving…'));
		$('#toc-license-msg').text('');
		$.post(tocData.ajax_url, {
			action: 'toc_license_save_server',
			nonce: tocData.nonce,
			server_url: $('#toc-license-server').val() || ''
		}).done(function(r){
			$btn.prop('disabled', false).text(i18n('saveServer', 'Save server URL'));
			if(r && r.success){
				$('#toc-license-msg').html('<span style="color:green">'+(r.data.message||'')+'</span>');
			} else {
				licenseError(r);
			}
		}).fail(function(){
			$btn.prop('disabled', false).text(i18n('saveServer', 'Save server URL'));
			$('#toc-license-msg').text(i18n('requestFailed', 'Request failed'));
		});
	});
});
})(jQuery);
