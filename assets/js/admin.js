/* Order Shield admin */
(function ($) {
	'use strict';

	var A = window.OShieldAdmin || {};
	var cache = {}; // key => {compact, details}

	function post(action, data) {
		return $.post(A.ajax, $.extend({ action: 'oshield_' + action, nonce: A.nonce }, data || {}));
	}

	function keyFor($el) {
		return ($el.data('order') || 0) + '|' + ($el.data('phone') || '');
	}

	/* ---------- Modal ---------- */
	function modal(html) {
		var $m = $('#oshield-modal');
		if (!$m.length) {
			$m = $('<div id="oshield-modal" class="oshield-modal" hidden><div class="oshield-modal-box"><button type="button" class="oshield-modal-close" aria-label="Close"><span class="dashicons dashicons-no-alt"></span></button><div class="oshield-modal-body"></div></div></div>');
			$('body').append($m);
		}
		$m.find('.oshield-modal-body').html(html);
		$m.prop('hidden', false);
		$('body').addClass('oshield-modal-open');
		return $m;
	}
	function closeModal() {
		$('.oshield-modal').prop('hidden', true);
		$('body').removeClass('oshield-modal-open');
		$('.oshield-replay-target').empty();
	}
	$(document).on('click', '.oshield-modal-close', closeModal);
	$(document).on('click', '.oshield-modal', function (e) { if (e.target === this) { closeModal(); } });
	$(document).on('keydown', function (e) { if (e.key === 'Escape') { closeModal(); } });

	/* ---------- Fraud reports ---------- */
	var queue = [], running = 0, MAX = 3;

	function loadReport($box, fresh) {
		var k = keyFor($box);
		if (!fresh && cache[k]) {
			$box.html(cache[k].compact);
			return $.Deferred().resolve(cache[k]).promise();
		}
		$box.html('<span class="spinner is-active oshield-spinner"></span>');
		return post('report', { order_id: $box.data('order'), phone: $box.data('phone'), fresh: fresh ? 1 : 0 })
			.done(function (r) {
				if (r && r.success) {
					cache[k] = r.data;
					$box.html(r.data.compact);
				} else {
					$box.html('<span class="oshield-bad">' + A.i18n.error + '</span>');
				}
			})
			.fail(function () {
				$box.html('<button type="button" class="button button-small oshield-load">' + A.i18n.error + '</button>');
			});
	}

	function pump() {
		while (running < MAX && queue.length) {
			running++;
			loadReport(queue.shift()).always(function () { running--; pump(); });
		}
	}

	$(function () {
		$('.oshield-report').each(function () {
			var $b = $(this);
			if (A.autoload || $b.data('autoload')) { queue.push($b); }
		});
		pump();
	});

	$(document).on('click', '.oshield-load', function (e) {
		e.preventDefault();
		e.stopPropagation();
		loadReport($(this).closest('.oshield-report'));
	});

	$(document).on('click', '.oshield-details', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var $a = $(this), k = keyFor($a);
		if (cache[k]) {
			modal(cache[k].details);
			return;
		}
		modal('<p><span class="spinner is-active oshield-spinner"></span> ' + A.i18n.loading + '</p>');
		post('report', { order_id: $a.data('order'), phone: $a.data('phone') }).done(function (r) {
			if (r && r.success) { cache[k] = r.data; modal(r.data.details); }
		});
	});

	$(document).on('click', '.oshield-refresh-report', function (e) {
		e.preventDefault();
		var $a = $(this), k = keyFor($a);
		var $body = $a.closest('.oshield-modal-body, .oshield-search-result .inside');
		$a.text(A.i18n.loading);
		post('report', { order_id: $a.data('order'), phone: $a.data('phone'), fresh: 1 }).done(function (r) {
			if (!r || !r.success) { return; }
			cache[k] = r.data;
			if ($body.length) { $body.html(r.data.details); }
			$('.oshield-report').filter(function () { return keyFor($(this)) === k; }).html(r.data.compact);
		});
	});

	/* ---------- Block / unblock ---------- */
	$(document).on('click', '.oshield-block-toggle', function (e) {
		e.preventDefault();
		var $b = $(this);
		var block = String($b.data('blocked')) !== '1';
		if (block && !window.confirm(A.i18n.confirmBlock.replace('%s', $b.data('type')))) { return; }
		$b.prop('disabled', true);
		post('block_toggle', { type: $b.data('type'), value: $b.data('value'), hwid: $b.data('hwid') || '', ip: $b.data('ip') || '', block: block ? 1 : 0 })
			.done(function (r) {
				if (r && r.success) {
					$b.data('blocked', block ? '1' : '0').toggleClass('oshield-is-blocked', block)
						.text(block ? $b.data('label-unblock') : $b.data('label-block'));
					cache = {}; // ratios show "Blocked" pill; force reload next time
				} else {
					window.alert((r && r.data && r.data.message) || A.i18n.error);
				}
			})
			.always(function () { $b.prop('disabled', false); });
	});

	/* ---------- Courier ---------- */
	$(document).on('click', '.oshield-send-courier', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var $b = $(this), $cell = $b.closest('.oshield-courier');
		if (!window.confirm(A.i18n.confirmSend.replace('%s', $b.data('label')))) { return; }
		var note = $b.closest('.oshield-metabox').find('.oshield-courier-note').val() || '';
		$cell.find('button').prop('disabled', true);
		$b.text(A.i18n.loading);
		post('send_courier', { order_id: $cell.data('order'), courier: $b.data('courier'), note: note })
			.done(function (r) {
				if (r && r.success) {
					$cell.replaceWith(r.data.html);
				} else {
					window.alert((r && r.data && r.data.message) || A.i18n.error);
					$b.text($b.data('label'));
					$cell.find('button').prop('disabled', false);
				}
			})
			.fail(function () { window.alert(A.i18n.error); $cell.find('button').prop('disabled', false); $b.text($b.data('label')); });
	});

	$(document).on('click', '.oshield-refresh-courier', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var $a = $(this), $cell = $a.closest('.oshield-courier');
		$a.addClass('oshield-spin');
		post('refresh_courier', { order_id: $cell.data('order') })
			.done(function (r) {
				if (r && r.success) { $cell.replaceWith(r.data.html); }
				else { window.alert((r && r.data && r.data.message) || A.i18n.error); }
			})
			.always(function () { $a.removeClass('oshield-spin'); });
	});

	/* ---------- Session replay ---------- */
	$(document).on('click', '.oshield-replay', function (e) {
		e.preventDefault();
		var $m = $('#oshield-replay-modal'), $t = $m.find('.oshield-replay-target');
		$t.html('<p><span class="spinner is-active oshield-spinner"></span> ' + A.i18n.loading + '</p>');
		$m.prop('hidden', false);
		$('body').addClass('oshield-modal-open');
		post('replay', $(this).data('sid') ? { sid: $(this).data('sid') } : { id: $(this).data('id') }).done(function (r) {
			var events = r && r.success ? r.data.events : [];
			$t.empty();
			if (!events || events.length < 2) { $t.html('<p>' + A.i18n.noReplay + '</p>'); return; }
			var P = window.rrwebPlayer && (window.rrwebPlayer.Player || window.rrwebPlayer.default || window.rrwebPlayer);
			var width = Math.min(1000, $t.width() || 900);
			try {
				new P({ target: $t[0], props: { events: events, width: width, height: Math.round(width * 0.56), autoPlay: true, showController: true, skipInactive: true } });
			} catch (err) {
				$t.html('<p>' + A.i18n.error + '</p>');
				if (window.console) { console.error(err); }
			}
		});
	});

	/* ---------- Live visitors ---------- */
	var $liveRows = $('#oshield-live-rows');
	if ($liveRows.length) {
		var liveView = 'online', liveSearch = '', liveTimer = null, busy = false;
		var refresh = function () {
			if (busy || document.visibilityState === 'hidden') { return; }
			// Do not redraw while the replay or pages popup is open.
			if ($('.oshield-modal').filter(function () { return !this.hidden; }).length) { return; }
			busy = true;
			post('live', { view: liveView, s: liveSearch }).done(function (r) {
				if (!r || !r.success) { return; }
				$.each(r.data.stats, function (k, v) { $('[data-stat="' + k + '"]').text(v); });
				$('#oshield-live-pages').html(r.data.pages);
				$liveRows.html(r.data.rows);
				$('.oshield-live-time').text(r.data.time);
			}).always(function () { busy = false; });
		};
		$('.oshield-live-time').text(new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
		liveTimer = setInterval(refresh, 10000);
		document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { refresh(); } });
		$(document).on('click', '.oshield-live-views a', function (e) {
			e.preventDefault();
			$('.oshield-live-views a').removeClass('current');
			liveView = $(this).addClass('current').data('view');
			refresh();
		});
		var searchTimer = null;
		$(document).on('input', '#oshield-live-search', function () {
			clearTimeout(searchTimer);
			var v = $(this).val();
			searchTimer = setTimeout(function () { liveSearch = v; refresh(); }, 400);
		});
	}

	/* ---------- Watch a visitor live ---------- */
	var live = null;
	function stopLive() {
		if (!live) { return; }
		clearTimeout(live.timer);
		live = null;
		$('#oshield-live-modal .oshield-live-target').empty();
	}
	$(document).on('click', '#oshield-live-modal .oshield-modal-close, #oshield-live-modal', function (e) {
		if (e.target === this || $(e.target).closest('.oshield-modal-close').length) { stopLive(); }
	});
	$(document).on('keydown', function (e) { if (e.key === 'Escape') { stopLive(); } });

	$(document).on('click', '.oshield-watch-live', function (e) {
		e.preventDefault();
		stopLive();
		var sid = $(this).data('sid');
		var $m = $('#oshield-live-modal'), $t = $m.find('.oshield-live-target');
		$t.html('<p><span class="spinner is-active oshield-spinner"></span> ' + A.i18n.liveWaiting + '</p>');
		$m.find('.oshield-live-state').html('');
		$m.prop('hidden', false);
		$('body').addClass('oshield-modal-open');
		live = { sid: sid, after: 0, player: null, replayer: null, timer: null, pending: [] };
		var me = live;

		var mount = function (events) {
			var P = window.rrwebPlayer && (window.rrwebPlayer.Player || window.rrwebPlayer.default || window.rrwebPlayer);
			var width = Math.min(1000, $t.width() || 900);
			$t.empty();
			me.player = new P({ target: $t[0], props: { events: events, width: width, height: Math.round(width * 0.56), autoPlay: false, showController: false, liveMode: true, mouseTail: false } });
			me.replayer = me.player.getReplayer();
			// Start "now": everything already recorded is applied at once, new events play as they arrive.
			me.replayer.startLive(events[events.length - 1].timestamp - 500);
		};

		var poll = function () {
			if (live !== me) { return; }
			post('live_events', { sid: sid, after: me.after }).done(function (r) {
				if (live !== me || !r || !r.success) { return; }
				var d = r.data;
				me.after = d.last;
				$m.find('.oshield-live-page').text(d.page || '');
				$m.find('.oshield-live-state').html(d.online
					? '<span class="oshield-pill oshield-pill-risky"><span class="oshield-live-dot"></span> ' + A.i18n.liveNow + '</span>'
					: '<span class="oshield-pill">' + A.i18n.liveOffline + '</span>');
				if (d.events && d.events.length) {
					if (!me.replayer) {
						me.pending = me.pending.concat(d.events);
						// The player needs a full page snapshot (event type 2) to draw anything.
						var hasSnapshot = me.pending.some(function (ev) { return ev.type === 2; });
						if (hasSnapshot && me.pending.length > 1) {
							try { mount(me.pending); } catch (err) { if (window.console) { console.error(err); } }
						}
					} else {
						d.events.forEach(function (ev) { me.replayer.addEvent(ev); });
					}
				}
			}).always(function () {
				if (live === me) { me.timer = setTimeout(poll, 1500); }
			});
		};
		poll();
	});

	$(document).on('click', '.oshield-journey', function (e) {
		e.preventDefault();
		modal('<p><span class="spinner is-active oshield-spinner"></span> ' + A.i18n.loading + '</p>');
		post('journey', { sid: $(this).data('sid') }).done(function (r) {
			if (r && r.success) { modal(r.data.html); }
		});
	});

	$(document).on('click', '.oshield-block-link', function (e) {
		e.preventDefault();
		var $a = $(this);
		if (!window.confirm(A.i18n.confirmBlock.replace('%s', $a.data('type')))) { return; }
		post('block_toggle', { type: $a.data('type'), value: $a.data('value'), hwid: $a.data('hwid') || '', ip: $a.data('ip') || '', block: 1 }).done(function (r) {
			if (r && r.success) { $a.replaceWith('<span class="oshield-bad">' + A.i18n.blocked + '</span>'); }
			else { window.alert((r && r.data && r.data.message) || A.i18n.error); }
		});
	});

	/* ---------- Settings ---------- */
	function fieldInput($field) {
		return $field.find('.oshield-field-control').find('input:not([type=hidden]):not([type=checkbox]), select, textarea').first();
	}

	// Show/hide fields that depend on a select: "key=value" or "key!=value".
	function applyShowIf() {
		$('[data-show-if]').each(function () {
			var rule = String($(this).data('show-if'));
			var neg = rule.indexOf('!=') > -1;
			var parts = rule.split(neg ? '!=' : '=');
			var val = $('[data-oshield-key="' + parts[0] + '"]').val();
			$(this).toggle(neg ? val !== parts[1] : val === parts[1]);
		});
	}
	$(document).on('change', 'select[data-oshield-key]', applyShowIf);

	// Master switch: hide the section's options while it is off.
	$(document).on('change', '.oshield-master input', function () {
		$(this).closest('.oshield-section-card').toggleClass('is-off', !this.checked);
	});

	// Live warnings for risky values.
	function checkWarn($field) {
		var op = String($field.data('warn-op') || '');
		if (!op) { return; }
		var raw = String(fieldInput($field).val() || '').trim();
		var limit = parseFloat($field.data('warn-value'));
		var n = parseFloat(raw);
		var bad = false;
		if (op === 'empty') { bad = raw === ''; }
		else if (raw !== '' && !isNaN(n)) {
			bad = (op === '>' && n > limit) || (op === '<' && n < limit) || (op === '==' && n === limit);
		}
		$field.toggleClass('has-warning', bad).find('.oshield-warn').prop('hidden', !bad);
	}

	// "Recommended" chip shows a tick when the value already matches.
	function checkRec($field) {
		var $rec = $field.find('.oshield-rec');
		if (!$rec.length) { return; }
		var v = String(fieldInput($field).val() || '').trim();
		$rec.toggleClass('is-match', v === String($rec.data('value')));
	}

	function checkField($field) { checkWarn($field); checkRec($field); }

	$(document).on('input change', '.oshield-field .oshield-input', function () {
		checkField($(this).closest('.oshield-field'));
	});

	$(document).on('click', '.oshield-rec', function (e) {
		e.preventDefault();
		var $t = $('#' + $(this).data('target'));
		$t.val(String($(this).data('value'))).trigger('change');
		$t.closest('.oshield-field').addClass('is-changed');
	});

	// Unsaved changes indicator.
	var dirty = false;
	$(document).on('input change', 'form.oshield-settings :input', function () {
		if (!dirty) { dirty = true; $('.oshield-unsaved').prop('hidden', false); }
	});
	$(document).on('submit', 'form.oshield-settings', function () { dirty = false; });
	window.addEventListener('beforeunload', function (e) {
		if (dirty) { e.preventDefault(); e.returnValue = A.i18n.leavePage || ''; return e.returnValue; }
	});

	// Protection level presets.
	$(document).on('click', '.oshield-preset', function (e) {
		if ($(this).hasClass('is-active')) { e.preventDefault(); return; }
		var msg = (A.i18n.confirmPreset || '%s').replace('%s', $(this).data('label'));
		if (!window.confirm(msg)) { e.preventDefault(); return; }
		dirty = false;
	});

	// Copy buttons (webhook URL etc.).
	$(document).on('click', '.oshield-copy-btn', function () {
		var $b = $(this), input = $b.siblings('input')[0];
		if (!input) { return; }
		input.select();
		var done = function () {
			var old = $b.data('old') || $b.text();
			$b.data('old', old).text(A.i18n.copied || 'Copied');
			setTimeout(function () { $b.text(old); }, 1500);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(input.value).then(done);
		} else {
			try { document.execCommand('copy'); done(); } catch (err) { /* ignore */ }
		}
	});

	$(function () {
		applyShowIf();
		$('.oshield-field').each(function () { checkField($(this)); });
	});

	$(document).on('click', '.oshield-gen-key', function () {
		var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', out = '';
		var arr = new Uint32Array(32);
		window.crypto.getRandomValues(arr);
		for (var i = 0; i < arr.length; i++) { out += chars[arr[i] % chars.length]; }
		$('#' + $(this).data('target')).val(out).trigger('change');
	});
	$(document).on('click', '.oshield-clear-key', function () {
		$('#' + $(this).data('target')).val('').trigger('change');
	});
	$(document).on('focus click', '.oshield-copy', function () { this.select(); });

})(jQuery);
