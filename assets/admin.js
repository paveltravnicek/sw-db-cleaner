document.addEventListener('DOMContentLoaded', function () {

	// ── Přesun WP notices pod hero ──────────────────────────────
	var noticesArea = document.querySelector('.swdc-page-notices');
	if (noticesArea) {
		var wrap = document.querySelector('.swdc-wrap');
		if (wrap) {
			var searchRoot = document.getElementById('wpbody-content') || document.body;
			var toMove = [];
			searchRoot.querySelectorAll('.notice, div.updated, div.error').forEach(function (el) {
				if (!wrap.contains(el)) toMove.push(el);
			});
			toMove.forEach(function (el) {
				el.classList.add('swdc-notice-hero');
				noticesArea.insertBefore(el, noticesArea.firstChild);
			});
		}
	}

	// ── Frekvence – zobrazit/skrýt den ─────────────────────────
	var freqSelect = document.getElementById('cleanup_frequency');
	var dowField   = document.getElementById('swdc-dow-field');
	var domField   = document.getElementById('swdc-dom-field');

	function updateDayFields() {
		if (!freqSelect) return;
		var val = freqSelect.value;
		if (dowField) dowField.style.display = val === 'weekly'  ? '' : 'none';
		if (domField) domField.style.display = val === 'monthly' ? '' : 'none';
	}

	if (freqSelect) {
		freqSelect.addEventListener('change', updateDayFields);
		updateDayFields();
	}

	// ── Dry-run / Analyzovat databázi ───────────────────────────
	var dryRunBtn    = document.getElementById('swdc-dry-run-btn');
	var dryRunResult = document.getElementById('swdc-dry-run-result');

	if (dryRunBtn && dryRunResult && typeof swdcAdmin !== 'undefined') {
		dryRunBtn.addEventListener('click', function () {
			dryRunBtn.disabled = true;
			dryRunBtn.textContent = swdcAdmin.analyzingText || 'Analyzuji…';
			dryRunResult.style.display = 'none';

			var data = new FormData();
			data.append('action', 'swdc_dry_run');
			data.append('nonce', swdcAdmin.dryRunNonce);

			fetch(swdcAdmin.ajaxUrl, { method: 'POST', body: data })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					dryRunBtn.disabled = false;
					dryRunBtn.textContent = 'Analyzovat databázi';

					if (!json.success) {
						dryRunResult.innerHTML = '<p class="swdc-dry-error">Chyba při analýze. Zkuste to znovu.</p>';
						dryRunResult.style.display = 'block';
						return;
					}

					var d = json.data;
					var labels = {
						revisions:            'Revize příspěvků',
						auto_drafts:          'Auto-drafty',
						trashed_posts:        'Příspěvky v koši',
						expired_transients:   'Expirované transienty',
						orphaned_transients:  'Osiřelé transienty',
						spam_comments:        'Spam komentáře',
						trashed_comments:     'Komentáře v koši',
						orphaned_postmeta:    'Osiřelé postmeta záznamy',
						orphaned_commentmeta: 'Osiřelé commentmeta záznamy',
					};

					var rows = '';
					var hasAny = false;
					for (var key in labels) {
						if (!labels.hasOwnProperty(key)) continue;
						var count = d.counts[key] || 0;
						var cls = count > 0 ? 'swdc-dry-row--has' : 'swdc-dry-row--empty';
						rows += '<li class="swdc-dry-row ' + cls + '"><span>' + labels[key] + '</span><strong>' + count + '</strong></li>';
						if (count > 0) hasAny = true;
					}

					var summary = hasAny
						? '<p class="swdc-dry-summary swdc-dry-summary--has">Nalezeno celkem <strong>' + d.total + '</strong> záznamů ke smazání (aktuální velikost DB: <strong>' + d.size_mb + ' MB</strong>).</p>'
						: '<p class="swdc-dry-summary swdc-dry-summary--clean">Databáze je čistá, není co mazat. Aktuální velikost: <strong>' + d.size_mb + ' MB</strong>.</p>';

					dryRunResult.innerHTML = summary + '<ul class="swdc-dry-list">' + rows + '</ul>';
					dryRunResult.style.display = 'block';
				})
				.catch(function () {
					dryRunBtn.disabled = false;
					dryRunBtn.textContent = 'Analyzovat databázi';
					dryRunResult.innerHTML = '<p class="swdc-dry-error">Nepodařilo se připojit k serveru.</p>';
					dryRunResult.style.display = 'block';
				});
		});
	}

	// ── Accordion ───────────────────────────────────────────────
	document.querySelectorAll('.swdc-details').forEach(function (item) {
		item.addEventListener('toggle', function () {
			if (item.open) {
				item.classList.add('is-open');
			} else {
				item.classList.remove('is-open');
				return;
			}
			var parentAccordion = item.closest('[data-accordion="true"]');
			if (!parentAccordion) return;
			parentAccordion.querySelectorAll('.swdc-accordion-item[open]').forEach(function (openItem) {
				if (openItem !== item) openItem.open = false;
			});
		});
	});

});
