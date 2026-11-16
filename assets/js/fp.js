/* Order Shield: hardware fingerprint that stays the same in every browser on one device.
 * Only signals that come from the device itself are used (screen, CPU cores, GPU maker,
 * time zone, OS, installed fonts) — nothing that changes between Chrome, Firefox, Edge, etc.
 * Result is stored in the "oshield_hw" cookie and checked against the block list. */
(function () {
	'use strict';
	try {
		var nav = window.navigator, scr = window.screen || {};
		var parts = [];

		// Screen (orientation independent). Pixel ratio is skipped: browser zoom changes it.
		var w = scr.width || 0, h = scr.height || 0;
		parts.push(Math.max(w, h) + 'x' + Math.min(w, h), scr.colorDepth || 0);

		// CPU cores and touch points.
		parts.push(nav.hardwareConcurrency || 0, nav.maxTouchPoints || 0);

		// Time zone.
		var tz = '';
		try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
		parts.push(tz);

		// OS family.
		var ua = nav.userAgent || '';
		var os = /Android/.test(ua) ? 'android' : /iPhone|iPad|iPod/.test(ua) ? 'ios' : /Windows/.test(ua) ? 'win' : /Mac OS X|Macintosh/.test(ua) ? 'mac' : /Linux/.test(ua) ? 'linux' : 'other';
		parts.push(os);

		// GPU maker (browsers word the full name differently, the maker is stable).
		var gpu = '';
		try {
			var c = document.createElement('canvas');
			var gl = c.getContext('webgl') || c.getContext('experimental-webgl');
			if (gl) {
				var ext = gl.getExtension('WEBGL_debug_renderer_info');
				var r = String(ext ? gl.getParameter(ext.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER)).toLowerCase();
				var makers = ['adreno', 'mali', 'powervr', 'apple', 'nvidia', 'geforce', 'radeon', 'amd', 'intel', 'xclipse', 'immortalis', 'videocore'];
				for (var i = 0; i < makers.length; i++) { if (r.indexOf(makers[i]) !== -1) { gpu = makers[i] === 'geforce' ? 'nvidia' : makers[i] === 'radeon' ? 'amd' : makers[i]; break; } }
				// Mobile GPUs: keep the model number too (e.g. "adreno 642", "mali-g68").
				var m = r.match(/(adreno|mali-?[gt]?)\s*\(?t?m?\)?\s*([0-9]{2,4})/);
				if (m) { gpu += m[2]; }
			}
		} catch (e) {}
		parts.push(gpu);

		// Installed fonts — Bangla fonts and Office/Adobe fonts are installed per computer.
		var fonts = ['SolaimanLipi', 'Kalpurush', 'Nikosh', 'Siyam Rupali', 'Mukti', 'Vrinda', 'Shonar Bangla', 'Noto Sans Bengali', 'Hind Siliguri', 'AdorshoLipi',
			'Calibri', 'Cambria', 'Candara', 'Constantia', 'Corbel', 'Bahnschrift', 'Segoe UI Variable', 'Segoe Print', 'Aptos', 'Century Gothic', 'Garamond',
			'Franklin Gothic Medium', 'Gill Sans MT', 'Lucida Bright', 'Myriad Pro', 'Minion Pro', 'Adobe Garamond Pro', 'Roboto', 'Open Sans', 'Ubuntu', 'Helvetica Neue', 'Avenir'];
		var found = [];
		try {
			var ctx = document.createElement('canvas').getContext('2d');
			var txt = 'mmmmmmmmmmlliWWআমার১২৩';
			var base = ['monospace', 'serif', 'sans-serif'], bw = {};
			for (var b = 0; b < base.length; b++) { ctx.font = '72px ' + base[b]; bw[base[b]] = ctx.measureText(txt).width; }
			for (var f = 0; f < fonts.length; f++) {
				for (var b2 = 0; b2 < base.length; b2++) {
					ctx.font = '72px "' + fonts[f] + '",' + base[b2];
					if (ctx.measureText(txt).width !== bw[base[b2]]) { found.push(f); break; }
				}
			}
		} catch (e) {}
		parts.push(found.join('.'));

		// cyrb53 hash.
		var str = parts.join('|'), h1 = 0xdeadbeef, h2 = 0x41c6ce57;
		for (var k = 0; k < str.length; k++) {
			var ch = str.charCodeAt(k);
			h1 = Math.imul(h1 ^ ch, 2654435761);
			h2 = Math.imul(h2 ^ ch, 1597334677);
		}
		h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
		h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
		var id = 'hw-' + (h2 >>> 0).toString(16) + (h1 >>> 0).toString(16);

		document.cookie = 'oshield_hw=' + id + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
	} catch (e) {}
})();
