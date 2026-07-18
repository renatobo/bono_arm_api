(function () {
	'use strict';

	function announce(message) {
		const region = document.getElementById('bono-arm-api-copy-status');
		if (region) {
			region.textContent = '';
			window.setTimeout(function () {
				region.textContent = message;
			}, 50);
		}
	}

	async function copyValue(value) {
		if (navigator.clipboard && window.isSecureContext) {
			await navigator.clipboard.writeText(value);
			return;
		}

		const input = document.createElement('textarea');
		input.value = value;
		input.setAttribute('readonly', '');
		input.style.position = 'fixed';
		input.style.opacity = '0';
		document.body.appendChild(input);
		input.select();
		const copied = document.execCommand('copy');
		input.remove();
		if (!copied) {
			throw new Error('copy failed');
		}
	}

	document.addEventListener('click', function (event) {
		const button = event.target.closest('.bono-arm-api-copy');
		if (!button) {
			return;
		}

		const source = document.getElementById(button.dataset.copyTarget);
		if (!source) {
			return;
		}

		copyValue(source.textContent.trim())
			.then(function () { announce(bonoArmApiAdmin.copied); })
			.catch(function () { announce(bonoArmApiAdmin.failed); });
	});
}());
