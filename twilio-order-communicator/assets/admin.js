(function($){
'use strict';

function $chat(){ return $('.toc-chat').first(); }

function refresh($w){
	$.post(tocData.ajax_url,{action:'toc_get_history',nonce:tocData.nonce,order_id:$w.data('order-id')})
	.done(function(r){ if(r.success){ $w.find('.toc-history').html(r.data.html); scroll($w); } });
}
function scroll($w){ var $h=$w.find('.toc-history'); if($h[0]) $h.scrollTop($h[0].scrollHeight); }

function errText(data){
	if(data == null) return 'Unknown';
	if(typeof data === 'string') return data;
	if(typeof data === 'object'){
		if(data.message) return data.message;
		if(data.code) return data.code;
	}
	return String(data);
}

function send(type, force){
	var $w=$chat(), msg=$('#toc-message').val().trim(), phone=$w.data('phone');
	if(!msg){ alert('Enter a message'); return; }
	if(!phone){ alert('No phone number on this order'); return; }

	// Consent / STOP warning before force SMS
	if(type === 'sms' && !force){
		var consented = String($w.data('consent')) === '1';
		var optedOut = String($w.data('opted-out')) === '1';
		if(optedOut){
			if(!confirm('This phone has opted out (STOP). Send SMS anyway?')) return;
			force = true;
		} else if(!consented){
			if(!confirm('Customer has not opted in to SMS. Send anyway?')) return;
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
				if(confirm((d.message || 'SMS blocked by consent') + '\n\nSend anyway?')){
					send('sms', true);
				}
				return;
			}
			alert('Error: '+errText(d));
		}
	}).fail(function(){ $w.removeClass('loading'); alert('Request failed'); });
}

function resolve(id, orderId, $btn){
	$btn.prop('disabled',true).text('…');
	var data={action:'toc_mark_resolved',nonce:tocData.nonce};
	if(orderId) data.order_id=orderId; else data.id=id;
	$.post(tocData.ajax_url,data).done(function(r){
		if(r.success){
			if(orderId){
				$('.toc-history .toc-bubble').addClass('resolved');
				$('.toc-resolve-order').replaceWith('<span class="toc-badge">Conversation resolved</span>');
			} else {
				$btn.closest('tr').addClass('toc-resolved');
				$btn.replaceWith('<span class="toc-badge">Resolved</span>');
				$('.toc-resolve-one[data-id="'+id+'"]').closest('.toc-bubble').addClass('resolved')
					.find('.toc-resolve-one').replaceWith('<span class="tag">resolved</span>');
			}
		} else { alert('Could not mark resolved'); $btn.prop('disabled',false).text('Mark Resolved'); }
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
	$('#toc-run-bulk').prop('disabled', running).text(running ? 'Sending…' : 'Send to Selected');
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
		$('#toc-call').on('click',function(){ if(confirm('Place a voice call with the current message?')) send('call', false); });
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
		$(this).prop('disabled', true).text('Stopping…');
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
		if(!ids.length){ alert('Select at least one order'); return; }

		var msg = $('#toc-bulk-message').val().trim();
		if(!msg){ alert('Enter a message'); return; }

		var mode = $('input[name="mode"]:checked').val() || 'call';
		var delaySec = parseInt($('#toc-bulk-delay').val(), 10);
		if(isNaN(delaySec) || delaySec < 1) delaySec = 8;
		if(delaySec > 120) delaySec = 120;

		var modeLabel = mode === 'both' ? 'call + SMS (SMS only with consent)' : (mode === 'sms' ? 'SMS (consent required)' : 'voice call');
		var estMin = Math.ceil((ids.length * delaySec) / 60);
		if(!confirm('Send '+modeLabel+' to '+ids.length+' order(s)?\n\nDelay: '+delaySec+'s between each (~'+estMin+' min total).')) return;

		if(mode === 'sms' || mode === 'both'){
			var noConsent = 0;
			ids.forEach(function(id){
				var $row = $('tr[data-order-id="'+id+'"]');
				if($row.data('consent') == 0 || $row.data('consent') === '0') noConsent++;
			});
			if(mode === 'sms' && noConsent === ids.length){
				alert('None of the selected orders have SMS consent. SMS will be skipped for all.');
				return;
			}
			if(noConsent > 0 && mode === 'sms'){
				if(!confirm(noConsent+' of '+ids.length+' selected order(s) have no SMS consent and will be skipped. Continue?')) return;
			}
		}

		bulkState = { running:true, stop:false, ids:ids, index:0, ok:0, fail:0, skip:0 };
		$('#toc-bulk-log').empty().show();
		$('#toc-stop-bulk').prop('disabled', false).text('Stop');
		$('.toc-bulk-table tr').removeClass('toc-bulk-ok toc-bulk-fail toc-bulk-skip toc-bulk-active');
		setBulkUi(true);
		bulkLog('Starting bulk send: '+ids.length+' order(s), mode='+mode+', delay='+delaySec+'s');
		processNextBulk(msg, mode, delaySec);
	});

	$('#toc-test-btn').on('click',function(){
		var $btn=$(this).prop('disabled',true).text('Testing…');
		var $r=$('#toc-test-result').text('');
		$.post(tocData.ajax_url,{action:'toc_test_connection',nonce:tocData.nonce})
		.done(function(res){
			$btn.prop('disabled',false).text('Run Connection Test');
			if(res.success) $r.html('<span style="color:green">✓ '+res.data+'</span>');
			else $r.html('<span style="color:#b32d2e">✗ '+(res.data||'Failed')+'</span>');
		}).fail(function(){ $btn.prop('disabled',false).text('Run Connection Test'); $r.text('Request failed'); });
	});
});
})(jQuery);
