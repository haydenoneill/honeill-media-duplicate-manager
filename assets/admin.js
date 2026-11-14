/* Media Duplicate Manager — admin interactions */
(function () {
	'use strict';

	var L = window.honeill || {};

	function t(str) {
		return typeof str === 'string' ? str : '';
	}

	function format(str, n) {
		return t(str).replace('%d', n);
	}

	/**
	 * Within one group: the chosen master can never also be ticked for deletion.
	 * Whichever card is the master gets its "Delete this copy" box disabled + cleared.
	 */
	function syncGroup(form) {
		var masterRadio = form.querySelector('input[name="keep_id"]:checked');
		var masterId = masterRadio ? masterRadio.value : null;

		form.querySelectorAll('.honeill-card').forEach(function (card) {
			var isMaster = card.getAttribute('data-id') === masterId;
			var del = card.querySelector('input[name="merge_ids[]"]');

			card.classList.toggle('is-master', isMaster);

			if (!del) {
				return;
			}
			if (isMaster) {
				del.checked = false;
				del.disabled = true;
			} else {
				del.disabled = false;
			}
		});
	}

	function countTicked(form) {
		return form.querySelectorAll('input[name="merge_ids[]"]:checked').length;
	}

	function initMergeForm(form) {
		// Keep master/delete mutually exclusive.
		form.querySelectorAll('input[name="keep_id"]').forEach(function (radio) {
			radio.addEventListener('change', function () {
				syncGroup(form);
			});
		});

		// A ticked card cannot also be the master.
		form.querySelectorAll('input[name="merge_ids[]"]').forEach(function (box) {
			box.addEventListener('change', function () {
				if (!box.checked) {
					return;
				}
				var card = box.closest('.honeill-card');
				var radio = card ? card.querySelector('input[name="keep_id"]') : null;
				if (radio && radio.checked) {
					// User ticked the current master: move master to the first other card.
					var others = form.querySelectorAll('input[name="keep_id"]');
					for (var i = 0; i < others.length; i++) {
						if (others[i] !== radio) {
							others[i].checked = true;
							break;
						}
					}
					syncGroup(form);
				}
			});
		});

		var selectOthers = form.querySelector('.honeill-select-others');
		if (selectOthers) {
			selectOthers.addEventListener('click', function () {
				form.querySelectorAll('input[name="merge_ids[]"]').forEach(function (box) {
					if (!box.disabled) {
						box.checked = true;
					}
				});
			});
		}

		var clear = form.querySelector('.honeill-clear');
		if (clear) {
			clear.addEventListener('click', function () {
				form.querySelectorAll('input[name="merge_ids[]"]').forEach(function (box) {
					box.checked = false;
				});
			});
		}

		form.addEventListener('submit', function (e) {
			var n = countTicked(form);
			if (n < 1) {
				e.preventDefault();
				window.alert(t(L.nothingPicked));
				return;
			}
			var msg = L.deleteMode === 'repoint' ? format(L.confirmRepoint, n) : format(L.confirmDelete, n);
			if (!window.confirm(msg)) {
				e.preventDefault();
			}
		});

		syncGroup(form);
	}

	function initDismissForm(form) {
		form.addEventListener('submit', function (e) {
			if (!window.confirm(t(L.confirmDismiss))) {
				e.preventDefault();
			}
		});
	}

	function initAutoScan() {
		var form = document.querySelector('form[data-honeill-autosubmit="1"]');
		if (!form) {
			return;
		}
		// Continue the "Scan all remaining" loop after the page reloads.
		window.setTimeout(function () {
			if (typeof form.requestSubmit === 'function') {
				form.requestSubmit();
			} else {
				form.submit();
			}
		}, 500);
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.honeill-merge-form').forEach(initMergeForm);
		document.querySelectorAll('.honeill-dismiss-form').forEach(initDismissForm);
		initAutoScan();
	});
})();
