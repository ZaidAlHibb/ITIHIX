/* Ikon SVG: sumber Font Awesome Free 6 (CC BY 4.0). Sprite ada di halaman lewat icon_sprite() (includes/icons.php). */
window.icn = function (n, c) { return '<svg class="ic ic-' + n + (c ? ' ' + c : '') + '" aria-hidden="true" focusable="false"><use href="#i-' + n + '"/></svg>'; };
/* Ganti emoji yang sering muncul di teks balasan AI dengan ikon SVG (hanya untuk HTML yang sudah di-escape). */
(function () {
  var M = {"⚠": "warn", "✅": "circle-check", "✔": "check", "✓": "check", "❌": "circle-xmark", "✗": "xmark", "✘": "xmark", "ℹ": "info", "🚨": "warn", "🔒": "lock", "🔐": "key", "🔗": "link", "🛡": "shield", "📞": "phone", "☎": "phone", "💡": "lightbulb", "👉": "arrow-right", "🔍": "search", "🔎": "search", "📱": "mobile", "💳": "card", "⛔": "ban", "🚫": "ban", "⚡": "bolt", "🤝": "users", "📌": "thumbtack", "🔔": "bell", "📧": "envelope", "✉": "envelope"};
  var re = new RegExp('(' + Object.keys(M).join('|') + ')\uFE0F?', 'g');
  window.icnGlyphs = function (html) { return String(html).replace(re, function (_, g) { return window.icn(M[g]); }); };
})();
