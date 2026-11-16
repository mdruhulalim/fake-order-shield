/* Fake Order Shield checkout: phone check, incomplete order capture & block popup. */
(function ($) {
	'use strict';

	var C = window.OShieldCheckout || {};
	var i18n = C.i18n || {};

	/* ---------- helpers ---------- */
	function uuid() {
		if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = Math.random() * 16 | 0;
			return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
		});
	}
	function getCookie(name) {
		var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : '';
	}
	function setCookie(name, value, days) {
		var exp = days ? '; expires=' + new Date(Date.now() + days * 864e5).toUTCString() : '';
		document.cookie = name + '=' + encodeURIComponent(value) + exp + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
	}
	function bnToEn(s) {
		return String(s || '').replace(/[০-৯]/g, function (d) { return String('০১২৩৪৫৬৭৮৯'.indexOf(d)); });
	}
	function normalizePhone(raw) {
		var d = bnToEn(raw).replace(/\D/g, '');
		if (d.indexOf('0088') === 0) { d = d.slice(4); }
		else if (d.indexOf('88') === 0 && d.length === 13) { d = d.slice(2); }
		else if (d.length === 10 && d.charAt(0) === '1') { d = '0' + d; }
		return /^01[3-9]\d{8}$/.test(d) ? d : '';
	}
	function esc(s) { return $('<div>').text(String(s || '')).html(); }

	if (!getCookie('oshield_did')) { setCookie('oshield_did', uuid(), 365); }
	var token = getCookie('oshield_itoken');
	if (!token) { token = uuid(); setCookie('oshield_itoken', token); }

	var $phone = function () { return $('#billing_phone'); };
	var $form = function () { return $('form.checkout, form.woocommerce-checkout').first(); };

	/* ---------- phone validation (inline) ---------- */
	if (C.validatePhone) {
		$(document).on('blur change', '#billing_phone', function () {
			var $f = $(this), n = normalizePhone($f.val());
			var $row = $f.closest('.form-row');
			$row.find('.oshield-phone-error').remove();
			if ($f.val() === '') { return; }
			if (n) {
				$f.val(n);
				$row.removeClass('woocommerce-invalid woocommerce-invalid-phone').addClass('woocommerce-validated');
			} else {
				$row.addClass('woocommerce-invalid woocommerce-invalid-phone').removeClass('woocommerce-validated');
				$f.after('<span class="oshield-phone-error" role="alert">' + esc(C.phoneMessage) + '</span>');
			}
		});
	}

	/* ---------- incomplete order capture ---------- */
	var lastSent = '', timer = null;

	function collect() {
		var fields = {};
		$form().find('input, select, textarea').each(function () {
			var n = this.name || '';
			if (!/^(billing_|shipping_|order_comments$)/.test(n) || /password|card|cvv|cvc/i.test(n)) { return; }
			if ((this.type === 'checkbox' || this.type === 'radio') && !this.checked) { return; }
			fields[n] = $(this).val();
		});
		return fields;
	}

	function capture(useBeacon) {
		if (!C.incomplete) { return; }
		var fields = collect();
		if (!normalizePhone(fields.billing_phone)) { return; }
		var sig = JSON.stringify(fields);
		if (sig === lastSent) { return; }
		lastSent = sig;
		var data = { action: 'oshield_capture', nonce: C.nonce, token: token, page: location.href };
		if (useBeacon && navigator.sendBeacon) {
			var fd = new FormData();
			$.each(data, function (k, v) { fd.append(k, v); });
			$.each(fields, function (k, v) { fd.append('fields[' + k + ']', v); });
			navigator.sendBeacon(C.ajax, fd);
			return;
		}
		data.fields = fields;
		$.post(C.ajax, data);
	}

	if (C.incomplete) {
		$(document).on('input change', 'form.checkout :input, form.woocommerce-checkout :input', function () {
			clearTimeout(timer);
			timer = setTimeout(capture, 1500);
		});
		$(document.body).on('updated_checkout', function () { clearTimeout(timer); timer = setTimeout(capture, 1500); });
		document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { capture(true); } });
		window.addEventListener('pagehide', function () { capture(true); });
	}

	/* Session replay is handled site-wide by tracker.js. */

	/* ---------- popups ---------- */
	function popup(inner) {
		$('.oshield-popup').remove();
		var $p = $('<div class="oshield-popup" role="dialog" aria-modal="true"><div class="oshield-popup-box"><button type="button" class="oshield-popup-close" aria-label="' + esc(i18n.close) + '">&times;</button>' + inner + '</div></div>');
		$('body').append($p);
		$p.on('click', function (e) { if (e.target === this) { $p.remove(); } });
		$p.find('.oshield-popup-close').on('click', function () { $p.remove(); });
		return $p;
	}

	function blockPopup(html) {
		var call = C.support ? '<a class="button oshield-popup-call" href="tel:' + esc(C.support) + '">' + esc(i18n.call) + ' ' + esc(C.support) + '</a>' : '';
		popup('<div class="oshield-popup-icon oshield-popup-icon-block">!</div><h3>' + esc(i18n.blocked) + '</h3><div class="oshield-popup-text">' + html + '</div>' + call);
	}

	$(document.body).on('checkout_error', function () {
		var $notices = $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .wc-block-components-notice-banner').filter(':visible');
		var $flag = $notices.find('.oshield-flag').first();
		if (!$flag.length) { return; }
		var type = $flag.data('oshield');
		var $li = $flag.closest('li, .woocommerce-error, .wc-block-components-notice-banner');
		if (type === 'block') {
			var $copy = $li.clone();
			$copy.find('.oshield-flag, .oshield-call, svg, [aria-hidden="true"]').remove();
			blockPopup(esc($.trim($copy.text())));
		}
	});
})(jQuery);
