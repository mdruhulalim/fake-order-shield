/* Order Shield tracker: live visitors (page views + heartbeat) and session recording. No jQuery needed. */
(function () {
	'use strict';

	var T = window.OShieldTracker || {};
	if (!T.ajax) { return; }

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

	// Device id (long lived) and visit id (until the browser closes).
	if (!getCookie('oshield_did')) { setCookie('oshield_did', uuid(), 365); }
	var sid = getCookie('oshield_itoken');
	if (!sid) { sid = uuid(); setCookie('oshield_itoken', sid); }

	function send(data, beacon) {
		var fd = new FormData();
		for (var k in data) { if (Object.prototype.hasOwnProperty.call(data, k)) { fd.append(k, data[k]); } }
		if (beacon && navigator.sendBeacon && navigator.sendBeacon(T.ajax, fd)) { return; }
		if (window.fetch) {
			fetch(T.ajax, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: !!beacon }).catch(function () {});
		}
	}

	/* ---------- live visitors ---------- */
	if (String(T.track) === '1') {
		var base = function (type) {
			return { action: 'oshield_track', type: type, sid: sid, url: location.href.slice(0, 255), title: (document.title || '').slice(0, 255) };
		};
		var view = base('view');
		view.ref = (document.referrer || '').slice(0, 255);
		send(view);

		var ping = function () {
			if (document.visibilityState !== 'visible' || !window.fetch) { return; }
			var fd = new FormData(), d = base('ping');
			for (var k in d) { if (Object.prototype.hasOwnProperty.call(d, k)) { fd.append(k, d[k]); } }
			fetch(T.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (r) { if (r && r.data && r.data.watch && window.OShieldWatchOn) { window.OShieldWatchOn(); } })
				.catch(function () {});
		};
		setInterval(ping, (parseInt(T.ping, 10) || 25) * 1000);
		document.addEventListener('visibilitychange', ping);
	}

	/* ---------- session recording ---------- */
	if (String(T.record) === '1' && window.rrwebRecord && window.rrwebRecord.record) {
		var buffer = [], started = 0, stop = null, limitHit = false, MAX_BEACON = 60000;
		var maxMs = (parseInt(T.maxRecord, 10) || 300) * 1000;
		var watched = false, timer = null;

		var start = function () {
			started = Date.now();
			limitHit = false;
			try {
				stop = window.rrwebRecord.record({
					emit: function (ev) {
						buffer.push(ev);
						// Stop after the per-page limit, unless an admin is watching live.
						if (stop && !watched && Date.now() - started > maxMs) {
							stop();
							stop = null;
							limitHit = true;
							flush(false);
						}
					},
					maskAllInputs: String(T.maskAll) === '1',
					maskInputOptions: { password: true },
					maskInputFn: function (text, el) {
						if (el && /card|cvv|cvc|expiry|password/i.test((el.name || '') + (el.id || ''))) { return text.replace(/./g, '*'); }
						return text;
					},
					sampling: { mousemove: 100, scroll: 150, input: 'last' },
					recordCanvas: false,
					collectFonts: false,
					inlineStylesheet: false, // replay loads your theme's CSS from the site: recordings stay small
					inlineImages: false
				});
			} catch (e) { stop = null; }
		};

		var schedule = function (ms) {
			clearInterval(timer);
			timer = setInterval(function () { flush(false); }, ms);
		};

		var flush = function (beacon) {
			if (!buffer.length && !watched) { return; }
			var body = JSON.stringify(buffer);
			buffer = [];
			if (beacon && body.length < MAX_BEACON && navigator.sendBeacon) {
				var fd = new FormData();
				fd.append('action', 'oshield_record'); fd.append('token', sid); fd.append('events', body);
				if (navigator.sendBeacon(T.ajax, fd)) { return; }
			}
			if (!window.fetch) { return; }
			var f = new FormData();
			f.append('action', 'oshield_record'); f.append('token', sid); f.append('events', body);
			fetch(T.ajax, { method: 'POST', body: f, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (r) {
					var w = !!(r && r.data && r.data.watch);
					if (w !== watched) {
						watched = w;
						// Live watching: send every second. Otherwise every 5 seconds.
						schedule(watched ? 1000 : 5000);
						if (watched && limitHit) { start(); }
					}
				})
				.catch(function () {});
		};

		// Called by the heartbeat when an admin starts watching an idle visitor.
		window.OShieldWatchOn = function () {
			if (watched) { return; }
			watched = true;
			schedule(1000);
			if (limitHit) { start(); }
		};

		start();
		// Send the big first snapshot quickly while the page is still open, then small updates.
		setTimeout(function () { flush(false); }, 1500);
		schedule(5000);
		document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { flush(true); } });
		window.addEventListener('pagehide', function () { flush(true); });
	}
})();
