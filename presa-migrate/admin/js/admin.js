(function ($) {
	'use strict';

	var nonce = typeof presaMigrate !== 'undefined' ? presaMigrate.nonce : '';
	var ajaxUrl = typeof presaMigrate !== 'undefined' ? presaMigrate.ajaxUrl : '';

	function showTestResult(el, success, message) {
		el.removeClass('success error').addClass(success ? 'success' : 'error').text(message).show();
	}

	// Test connection
	$('#presa-test-connection').on('click', function () {
		var btn = $(this);
		var result = $('#presa-test-result');
		btn.prop('disabled', true);
		result.text('');
		$.post(ajaxUrl, {
			action: 'presa_test_connection',
			nonce: nonce
		})
			.done(function (r) {
				if (r.success) {
					showTestResult(result, true, r.data.message || 'OK');
				} else {
					showTestResult(result, false, r.data && r.data.message ? r.data.message : 'Greška');
				}
			})
			.fail(function () {
				showTestResult(result, false, 'Zahtev nije uspeo.');
			})
			.always(function () {
				btn.prop('disabled', false);
			});
	});

	// Test DB connection
	$('#presa-test-db-connection').on('click', function () {
		var btn = $(this);
		var result = $('#presa-test-db-result');
		btn.prop('disabled', true);
		result.text('');
		$.post(ajaxUrl, {
			action: 'presa_test_db_connection',
			nonce: nonce
		})
			.done(function (r) {
				if (r.success) {
					showTestResult(result, true, r.data.message || 'OK');
				} else {
					showTestResult(result, false, r.data && r.data.message ? r.data.message : 'Greška');
				}
			})
			.fail(function () {
				showTestResult(result, false, 'Zahtev nije uspeo.');
			})
			.always(function () {
				btn.prop('disabled', false);
			});
	});

	// Import products (10) from DB
	$('#presa-import-db-10').on('click', function () {
		var btn = $('#presa-import-db-10');
		var spinner = $('#presa-import-db-10-spinner');
		var resultEl = $('#presa-import-db-result');
		var logWrap = $('#presa-import-db-log-wrap');
		var logEl = $('#presa-import-db-log');
		btn.prop('disabled', true);
		spinner.addClass('is-active').show();
		resultEl.hide().removeClass('notice-success notice-error notice-warning');
		logWrap.hide();
		logEl.empty();
		var updateExisting = $('#presa-import-db-update-existing').is(':checked');
		$.post(ajaxUrl, {
			action: 'presa_import_db_batch',
			nonce: nonce,
			update_existing: updateExisting ? 1 : 0
		})
			.done(function (r) {
				if (!r.success) {
					resultEl.html(r.data && r.data.message ? r.data.message : 'Greška').addClass('notice notice-error').show();
					btn.prop('disabled', false);
					spinner.removeClass('is-active').hide();
					return;
				}
				var d = r.data || {};
				var migrated = (d.migrated && d.migrated.length) ? d.migrated.length : 0;
				var errors = d.errors || [];
				var logLines = d.log || [];
				var msg = 'Završeno. Migrirano: ' + migrated;
				if (errors.length) {
					msg += '. Greške: ' + errors.length;
					resultEl.addClass('notice notice-warning');
					resultEl.html(msg + '.<br>' + errors.slice(0, 20).map(function (e) { return escapeHtml(e); }).join('<br>') + (errors.length > 20 ? '<br>…' : '')).show();
				} else {
					resultEl.addClass('notice notice-success').html(msg).show();
				}
				logLines.forEach(function (line) {
					logEl.append($('<li class="presa-log-line">').text(line));
				});
				if (logLines.length) {
					logWrap.show();
					logEl[0].scrollTop = logEl[0].scrollHeight;
				}
			})
			.fail(function () {
				resultEl.html('Zahtev nije uspeo.').addClass('notice notice-error').show();
			})
			.always(function () {
				btn.prop('disabled', false);
				spinner.removeClass('is-active').hide();
			});
	});

	// Import all products from DB (batch loop)
	var DB_BATCH_SIZE = 50;
	var presaImportStopped = false;
	$('#presa-import-db-all').on('click', function () {
		var resultEl = $('#presa-import-db-result');
		var logWrap = $('#presa-import-db-log-wrap');
		var logEl = $('#presa-import-db-log');
		var btn10 = $('#presa-import-db-10');
		var btnAll = $('#presa-import-db-all');
		var btnStop = $('#presa-import-db-stop');
		var spinner = $('#presa-import-db-10-spinner');
		presaImportStopped = false;
		btn10.prop('disabled', true);
		btnAll.prop('disabled', true);
		btnStop.show().prop('disabled', false).text('Zaustavi');
		spinner.addClass('is-active').show();
		resultEl.hide().removeClass('notice-success notice-error notice-warning');
		logWrap.show();
		logEl.empty();
		var totalMigrated = 0;
		var totalErrors = [];

		function finish(msg, isWarning) {
			btn10.prop('disabled', false);
			btnAll.prop('disabled', false);
			btnStop.hide().prop('disabled', false).text('Zaustavi');
			spinner.removeClass('is-active').hide();
			if (msg !== undefined) {
				resultEl.removeClass('notice-success notice-error notice-warning').addClass('notice ' + (isWarning ? 'notice-warning' : 'notice-success')).html(msg).show();
			}
		}

		function runBatch(offset) {
			if (presaImportStopped) {
				finish('Zaustavljeno. Migrirano do sada: ' + totalMigrated + (totalErrors.length ? '. Greške: ' + totalErrors.length : '') + '.', !!totalErrors.length);
				return;
			}
			var updateExisting = $('#presa-import-db-update-existing').is(':checked');
			$.post(ajaxUrl, {
				action: 'presa_import_db_batch',
				nonce: nonce,
				offset: offset,
				limit: DB_BATCH_SIZE,
				update_existing: updateExisting ? 1 : 0
			})
				.done(function (r) {
					if (presaImportStopped) {
						finish('Zaustavljeno. Migrirano do sada: ' + totalMigrated + (totalErrors.length ? '. Greške: ' + totalErrors.length : '') + '.', !!totalErrors.length);
						return;
					}
					if (!r.success) {
						resultEl.html(r.data && r.data.message ? r.data.message : 'Greška').addClass('notice notice-error').show();
						finish(r.data && r.data.message ? r.data.message : 'Greška', false);
						return;
					}
					var d = r.data || {};
					var migrated = (d.migrated && d.migrated.length) ? d.migrated.length : 0;
					totalMigrated += migrated;
					if (d.errors && d.errors.length) {
						totalErrors = totalErrors.concat(d.errors);
					}
					if (d.log && d.log.length) {
						d.log.forEach(function (line) {
							logEl.append($('<li class="presa-log-line">').text(line));
						});
						if (logEl[0]) logEl[0].scrollTop = logEl[0].scrollHeight;
					}
					if (d.has_more === true && d.next_offset != null) {
						resultEl.removeClass('notice-error').addClass('notice notice-warning').html('Učitavam… migrirano do sada: ' + totalMigrated + ', sledeći offset: ' + d.next_offset).show();
						runBatch(d.next_offset);
					} else {
						var msg = 'Završeno. Ukupno migrirano: ' + totalMigrated;
						if (totalErrors.length) {
							msg += '. Greške: ' + totalErrors.length;
							msg += '.<br>' + totalErrors.slice(0, 30).map(function (e) { return escapeHtml(e); }).join('<br>') + (totalErrors.length > 30 ? '<br>…' : '');
							resultEl.removeClass('notice-success notice-error').addClass('notice notice-warning').html(msg).show();
						} else {
							resultEl.removeClass('notice-warning notice-error').addClass('notice notice-success').html(msg).show();
						}
						finish();
					}
				})
				.fail(function () {
					resultEl.html('Zahtev nije uspeo (offset ' + offset + ').').addClass('notice notice-error').show();
					finish('Zahtev nije uspeo (offset ' + offset + ').', false);
				});
		}
		runBatch(0);
	});
	$('#presa-import-db-stop').on('click', function () {
		$(this).prop('disabled', true).text('Zaustavljanje…');
		presaImportStopped = true;
	});

	// Delete all imported products and media
	$('#presa-delete-imported').on('click', function () {
		if (!confirm('Da li ste sigurni? Trajno će biti obrisani svi proizvodi i sve slike uvezene Presa Migrate pluginom. Ovu radnju nije moguće poništiti.')) {
			return;
		}
		var btn = $(this);
		var spinner = $('#presa-delete-imported-spinner');
		var resultEl = $('#presa-delete-imported-result');
		btn.prop('disabled', true);
		spinner.addClass('is-active').show();
		resultEl.hide().removeClass('notice-success notice-error');
		$.post(ajaxUrl, {
			action: 'presa_delete_imported',
			nonce: nonce
		})
			.done(function (r) {
				if (r.success && r.data && r.data.message) {
					resultEl.removeClass('notice-error').addClass('notice notice-success').html(r.data.message).show();
				} else {
					resultEl.removeClass('notice-success').addClass('notice notice-error').html(r.data && r.data.message ? r.data.message : 'Greška').show();
				}
			})
			.fail(function () {
				resultEl.removeClass('notice-success').addClass('notice notice-error').html('Zahtev nije uspeo.').show();
			})
			.always(function () {
				btn.prop('disabled', false);
				spinner.removeClass('is-active').hide();
			});
	});

	var productsList = [];
	var LIST_PAGE_SIZE = 100;

	function logLine(message, type) {
		var log = $('#presa-live-log');
		log.find('li.presa-live-log-idle').remove();
		var time = new Date().toLocaleTimeString('sr');
		var line = $('<li class="presa-log-line">').text('[' + time + '] ' + message);
		if (type === 'success') line.addClass('presa-log-success');
		if (type === 'error') line.addClass('presa-log-error');
		log.append(line);
		if (log[0]) log[0].scrollTop = log[0].scrollHeight;
	}

	function logClear() {
		$('#presa-live-log').empty();
	}

	// Fetch products list (page by page to avoid 500)
	function fetchNextPage(offset) {
		var loading = $('#presa-products-loading');
		var result = $('#presa-products-result');
		var tbody = $('#presa-products-tbody');
		var pageNum = Math.floor(offset / LIST_PAGE_SIZE) + 1;
		logLine('Učitavam listu… strana ' + pageNum + ' (offset ' + offset + ')', null);
		$.post(ajaxUrl, {
			action: 'presa_fetch_products_list',
			nonce: nonce,
			offset: offset,
			limit: LIST_PAGE_SIZE
		})
			.done(function (r) {
				if (!r.success) {
					loading.hide();
					var errMsg = (r.data && r.data.message) ? r.data.message : 'Nije moguće preuzeti listu.';
					logLine('Greška: ' + errMsg, 'error');
					logLine('Proverite Podešavanja: URL, API ključ i „Dispatcher” ako dobijate 404.', null);
					alert(errMsg);
					return;
				}
				var page = r.data.products || [];
				var hasMore = r.data.has_more === true;
				page.forEach(function (p) { productsList.push(p); });
				if (hasMore) {
					fetchNextPage(offset + page.length);
					return;
				}
				loading.hide();
				logLine('Uspešno. Pronađeno ' + productsList.length + ' proizvoda.', 'success');
				$('.presa-products-count').text('Ukupno proizvoda: ' + productsList.length);
				tbody.empty();
				productsList.forEach(function (p) {
					var name = p.name || '—';
					var ref = p.reference || '—';
					var price = p.price !== undefined && p.price !== '' ? p.price : '—';
					var active = (p.active === '1' || p.active === true) ? 'Da' : 'Ne';
					tbody.append(
						'<tr><td>' + (p.id || '') + '</td><td>' + escapeHtml(name) + '</td><td>' + escapeHtml(ref) + '</td><td>' + escapeHtml(String(price)) + '</td><td>' + active + '</td></tr>'
					);
				});
					$('#presa-run-migration').prop('disabled', false).removeAttr('disabled');
					$('#presa-run-migration-test').prop('disabled', false).removeAttr('disabled');
					result.show();
			})
			.fail(function (xhr, status, err) {
				loading.hide();
				var errMsg = 'Zahtev nije uspeo.';
				if (xhr && xhr.status) errMsg += ' HTTP ' + xhr.status;
				if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					errMsg = xhr.responseJSON.data.message;
				} else if (xhr && xhr.responseText) {
					try {
						var parsed = JSON.parse(xhr.responseText);
						if (parsed.data && parsed.data.message) errMsg = parsed.data.message;
					} catch (e) {}
				}
				logLine(errMsg, 'error');
				if (err && err.message) logLine(err.message, null);
				alert(errMsg);
			});
	}

	$('#presa-fetch-products').on('click', function () {
		var loading = $('#presa-products-loading');
		var result = $('#presa-products-result');
		var tbody = $('#presa-products-tbody');
		logClear();
		productsList = [];
		logLine('Povezivanje sa PrestaShop API-jem…', null);
		loading.show();
		result.hide();
		fetchNextPage(0);
	});

	function escapeHtml(s) {
		var div = document.createElement('div');
		div.textContent = s;
		return div.innerHTML;
	}

	// Shared migration runner (test = first N, or full list)
	function startMigration(listToMigrate, isTest) {
		var total = listToMigrate.length;
		if (total === 0) {
			alert('Prvo preuzmite listu proizvoda.');
			return;
		}
		var progressWrap = $('#presa-migration-progress');
		var progressBar = $('#presa-migration-progress-bar');
		var statusEl = $('#presa-migration-status');
		var errorsEl = $('#presa-migration-errors');
		var doneEl = $('#presa-migration-done');
		var runBtn = $('#presa-run-migration');
		var testBtn = $('#presa-run-migration-test');
		var BATCH = 5;
		var offset = 0;
		var totalMigrated = 0;
		var allErrors = [];
		var label = isTest ? 'Test migracija (10 proizvoda)' : 'Puna migracija';

		progressWrap.show();
		doneEl.hide();
		errorsEl.hide().empty();
		runBtn.prop('disabled', true);
		testBtn.prop('disabled', true);
		progressBar.attr({ value: 0, max: 100 });
		logClear();
		logLine(label + ': ' + total + ' proizvoda, serije po ' + BATCH + '…', null);

		function runNext() {
			var batchIds = listToMigrate.slice(offset, offset + BATCH).map(function (p) { return p.id; });
			if (batchIds.length === 0) {
				progressBar.attr('value', 100);
				statusEl.text('Završeno. Migrirano: ' + totalMigrated);
				doneEl.text('Migracija je završena. Migrirano: ' + totalMigrated + ' proizvoda.').show();
				runBtn.prop('disabled', false);
				testBtn.prop('disabled', false);
				return;
			}
			var range = 'Obrada ' + (offset + 1) + '–' + Math.min(offset + BATCH, total) + ' od ' + total + '…';
			statusEl.text(range);
			logLine(range, null);
			$.post(ajaxUrl, {
				action: 'presa_run_migration',
				nonce: nonce,
				product_ids: batchIds
			})
				.done(function (r) {
					if (!r.success) {
						var errMsg = r.data && r.data.message ? r.data.message : 'Greška u batch-u.';
						allErrors.push(errMsg);
						statusEl.text('Greška.');
						logLine(errMsg, 'error');
						if (allErrors.length) {
							errorsEl.html('Greške:<br>' + escapeHtml(allErrors.join('<br>'))).show();
						}
						runBtn.prop('disabled', false);
						testBtn.prop('disabled', false);
						return;
					}
					var d = r.data;
					if (d.errors && d.errors.length) {
						allErrors = allErrors.concat(d.errors);
					}
					if (d.migrated && d.migrated.length) {
						totalMigrated += d.migrated.length;
					}
					offset += d.total_processed;
					var pct = total > 0 ? Math.round((offset / total) * 100) : 100;
					progressBar.attr('value', pct);
					var isDone = offset >= total;
					if (isDone) {
						progressBar.attr('value', 100);
						statusEl.text('Završeno. Migrirano: ' + totalMigrated);
						logLine('Završeno. Migrirano proizvoda: ' + totalMigrated + '.', 'success');
						if (allErrors.length) {
							errorsEl.html('Greške:<br>' + escapeHtml(allErrors.join('<br>'))).show();
							allErrors.forEach(function (e) { logLine(e, 'error'); });
						}
						doneEl.text('Migracija je završena. Migrirano: ' + totalMigrated + ' proizvoda.').show();
						runBtn.prop('disabled', false);
						testBtn.prop('disabled', false);
						showLastBatchDebug();
						return;
					}
					runNext();
				})
				.fail(function (xhr) {
					var errMsg = 'Zahtev nije uspeo.' + (xhr && xhr.status ? ' HTTP ' + xhr.status : '');
					allErrors.push(errMsg);
					statusEl.text('Greška pri slanju zahteva.');
					logLine(errMsg, 'error');
					errorsEl.html('Greške:<br>' + escapeHtml(allErrors.join('<br>'))).show();
					runBtn.prop('disabled', false);
					testBtn.prop('disabled', false);
				});
		}
		runNext();
	}

	$('#presa-run-migration-test').on('click', function () {
		var list = productsList.slice(0, 10);
		if (list.length === 0) {
			alert('Prvo preuzmite listu proizvoda.');
			return;
		}
		startMigration(list, true);
	});

	$('#presa-run-migration').on('click', function () {
		startMigration(productsList, false);
	});

	// Debug: what was sent to mapper for first product in last batch
	function showLastBatchDebug() {
		var el = $('#presa-debug-last-batch-result');
		el.html('Učitavam…').show();
		$.post(ajaxUrl, {
			action: 'presa_debug_last_batch',
			nonce: nonce
		})
			.done(function (r) {
				if (!r.success || (!r.data.raw && !r.data.normalized && !r.data.raw_source && !r.data.request2)) {
					el.html('Nema podataka. Pokrenite „Test migracija (10 proizvoda)” pa ponovo kliknite.').addClass('error');
					return;
				}
				var parts = [];
				if (r.data.raw) {
					parts.push('Prvi proizvod (od API-ja): ID ' + r.data.raw.id + ', keys: ' + (r.data.raw.keys && r.data.raw.keys.join ? r.data.raw.keys.join(', ') : '-') + ', name: ' + (r.data.raw.name || '-'));
					if (r.data.raw.sample && Object.keys(r.data.raw.sample).length) {
						parts.push('Sample: ' + JSON.stringify(r.data.raw.sample).substring(0, 500));
					}
				}
				if (r.data.normalized) {
					parts.push('Posle normalizacije (mapper): name: ' + (r.data.normalized.name || '-'));
					if (r.data.normalized.sample && Object.keys(r.data.normalized.sample).length) {
						parts.push('Sample: ' + JSON.stringify(r.data.normalized.sample).substring(0, 500));
					}
				}
				if (r.data.raw_source) {
					parts.push('get_product_raw izvor: ID ' + r.data.raw_source.id + ' -> ' + r.data.raw_source.source);
				}
				if (r.data.request2) {
					var r2 = r.data.request2;
					parts.push('Zahtev 2 (sort+limit): ID ' + r2.id + ', top_level_keys: ' + (r2.top_level_keys && r2.top_level_keys.join ? r2.top_level_keys.join(', ') : '-') + ', first_has_name: ' + (r2.first_has_name ? 'da' : 'ne'));
					if (r2.sample && Object.keys(r2.sample).length) {
						parts.push('Sample prvog elementa: ' + JSON.stringify(r2.sample).substring(0, 500));
					}
				}
				el.html('<pre class="presa-debug-pre">' + escapeHtml(parts.join('\n\n')) + '</pre>').removeClass('error');
			})
			.fail(function () {
				el.html('Zahtev nije uspeo.').addClass('error');
			});
	}
	$('#presa-debug-last-batch').on('click', showLastBatchDebug);

	// Debug: show raw API response for product 1
	$('#presa-debug-product').on('click', function () {
		var result = $('#presa-debug-result');
		result.html('Učitavam…').show();
		$.post(ajaxUrl, {
			action: 'presa_debug_product',
			nonce: nonce,
			product_id: 1
		})
			.done(function (r) {
				if (!r.success) {
					result.html('Greška: ' + (r.data && r.data.message ? r.data.message : '?')).addClass('error');
					return;
				}
				var raw = r.data.raw || {};
				var keys = r.data.keys || [];
				var snippet = 'Top-level keys: ' + keys.join(', ') + '\n\n';
				if (raw.product) {
					var p = raw.product;
					snippet += 'product.name type: ' + typeof p.name + '\n';
					snippet += 'product.reference: ' + typeof p.reference + '\n';
					if (p.name !== undefined) {
						snippet += 'product.name sample: ' + JSON.stringify(p.name).substring(0, 200) + '\n';
					}
				} else if (raw.prestashop && raw.prestashop.product) {
					var p = raw.prestashop.product;
					snippet += 'prestashop.product.name: ' + typeof p.name + '\n';
					if (p.name !== undefined) {
						snippet += 'name sample: ' + JSON.stringify(p.name).substring(0, 200) + '\n';
					}
				} else {
					snippet += 'Full response (first 1500 chars):\n' + JSON.stringify(raw).substring(0, 1500);
				}
				result.html('<pre class="presa-debug-pre">' + escapeHtml(snippet) + '</pre>').removeClass('error');
			})
			.fail(function () {
				result.html('Zahtev nije uspeo.').addClass('error');
			});
	});
})(jQuery);
