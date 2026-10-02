/**
 * Curated emoji picker for the collectives_todos admin settings page.
 * Loaded via \OCP\Util::addScript (CSP forbids inline scripts).
 */
(function () {
	'use strict';

	function init() {
		var picker = document.getElementById('collectives-todos-emoji-picker');
		var target = null;

		document.querySelectorAll('.emoji-picker-trigger').forEach(function (button) {
			button.addEventListener('click', function (event) {
				event.preventDefault();
				target = document.getElementById(button.dataset.target);
				var rect = button.getBoundingClientRect();
				var left = Math.min(rect.left, window.innerWidth - 320);
				var top = rect.bottom + window.scrollY + 4;
				if (top + 260 > window.scrollY + window.innerHeight) {
					top = rect.top + window.scrollY - 264;
				}
				picker.style.left = left + 'px';
				picker.style.top = top + 'px';
				picker.hidden = false;
			});
		});

		picker.querySelectorAll('button[data-emoji]').forEach(function (button) {
			button.addEventListener('click', function () {
				if (target !== null) {
					target.value = button.dataset.emoji;
				}
				picker.hidden = true;
			});
		});

		document.addEventListener('click', function (event) {
			if (picker.hidden) {
				return;
			}
			if (!picker.contains(event.target) && !event.target.closest('.emoji-picker-trigger')) {
				picker.hidden = true;
			}
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				picker.hidden = true;
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
