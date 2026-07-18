/* global jQuery, window */
/**
 * "Field Normalization" settings tab UI (Entry Normalizer for Gravity Forms).
 *
 * Implements the Claude Design mockup (card + empty state + 3-step add/edit form)
 * on top of the plugin's AJAX endpoints. All user-facing strings come from
 * gfenData.i18n (translatable in PHP); all dynamic values interpolated into HTML
 * templates are escaped via esc().
 */
(function ($) {
	'use strict';

	var D, T;
	var $app, $rules, $editor;

	/* ------------------------------------------------------------------ */
	/* Inline SVG icons (from the design)                                  */
	/* ------------------------------------------------------------------ */

	var ICON = {
		plus: '<svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 4v12M4 10h12"/></svg>',
		plusSmall: '<svg width="13" height="13" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 4v12M4 10h12"/></svg>',
		empty: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h10M5 12h6M5 16h8"/><circle cx="18" cy="16" r="3.2"/><path d="M18 14.6v2.8M16.6 16h2.8"/></svg>',
		arrow: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#787c82" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h15M13 6l6 6-6 6"/></svg>',
		remove: '<svg width="15" height="15" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l8 8M14 6l-8 8"/></svg>',
		search: '<svg width="15" height="15" viewBox="0 0 20 20" fill="none" stroke="#2271b1" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="9" r="5.5"/><path d="M13 13l4 4"/></svg>',
		chevron: '<svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="#50575e" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8l5 5 5-5"/></svg>',
		check: '<svg width="12" height="12" viewBox="0 0 20 20" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10l4 4 8-9"/></svg>'
	};

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	// HTML-escape (safe for both element text and double/single-quoted attributes).
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	// Minimal sprintf: supports %s and positional %1$s / %2$s placeholders.
	function fmt(tpl) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(tpl).replace(/%(?:(\d+)\$)?s/g, function (m, n) {
			return String(n ? args[n - 1] : args[i++]);
		});
	}

	function post(action, data) {
		data = $.extend({ action: action, nonce: D.nonce, form_id: D.formId }, data);
		return $.post(D.ajaxUrl, data);
	}

	function notice($container, type, message) {
		$container.html('<div class="gfen-notice gfen-notice-' + type + '">' + esc(message) + '</div>');
	}

	function fieldById(id) {
		var i;
		for (i = 0; i < D.fields.length; i++) {
			if (D.fields[i].id === String(id)) {
				return D.fields[i];
			}
		}
		return null;
	}

	function fieldLabel(id) {
		if (D.fieldLabels && D.fieldLabels[String(id)]) {
			return D.fieldLabels[String(id)];
		}
		var f = fieldById(id);
		return f ? f.label : fmt(T.deletedField, id);
	}

	function ruleById(id) {
		var i;
		for (i = 0; i < D.rules.length; i++) {
			if (D.rules[i].id === id) {
				return D.rules[i];
			}
		}
		return null;
	}

	function chainLabel(chain) {
		return chain.map(function (id) {
			return D.transforms[id] || id;
		}).join(T.chainSeparator);
	}

	function chainDesc(chain) {
		return chain.map(function (id) {
			return D.transformDescriptions[id] || '';
		}).filter(Boolean).join(' · ');
	}

	function scrollToEditor() {
		if ($editor.length && $editor[0].scrollIntoView) {
			$editor[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	}

	/* ------------------------------------------------------------------ */
	/* Rules list / empty state                                            */
	/* ------------------------------------------------------------------ */

	function emptyStateHtml() {
		return '<div class="gfen-empty">'
			+ '<span class="gfen-empty__icon">' + ICON.empty + '</span>'
			+ '<div>'
			+ '<p class="gfen-empty__title">' + esc(T.emptyTitle) + '</p>'
			+ '<p class="gfen-empty__desc">' + esc(T.emptyDesc) + '</p>'
			+ '</div>'
			+ '<button type="button" class="gfen-btn gfen-btn--primary gfen-js-add">' + ICON.plus + ' ' + esc(T.addModification) + '</button>'
			+ '</div>';
	}

	function ruleCardHtml(rule) {
		var badge = rule.apply_future ? '<span class="gfen-badge">' + esc(T.futureBadge) + '</span>' : '';
		// Rules created from a field's Advanced tab carry a marker badge, but can
		// still be edited or deleted here like any other rule.
		if (rule.quick) {
			badge += '<span class="gfen-badge gfen-badge--quick">' + esc(T.quickBadge) + '</span>';
		}
		var meta = rule.examples.length ? '<div class="gfen-rule__meta">' + esc(fmt(T.examplesCount, rule.examples.length)) + '</div>' : '';
		return '<div class="gfen-rule" data-rule-id="' + esc(rule.id) + '">'
			+ '<div class="gfen-rule__top">'
			+ '<div class="gfen-rule__body">'
			+ '<div class="gfen-rule__head"><span class="gfen-rule__field">' + esc(fieldLabel(rule.field_id)) + '</span>' + badge + '</div>'
			+ '<div class="gfen-rule__chain">' + esc(chainLabel(rule.chain)) + '</div>'
			+ meta
			+ '</div>'
			+ '<div class="gfen-rule__actions">'
			+ '<button type="button" class="gfen-btn gfen-btn--ghost gfen-btn--small gfen-js-preview">' + esc(T.preview) + '</button>'
			+ '<button type="button" class="gfen-btn gfen-btn--primary gfen-btn--small gfen-js-apply">' + esc(T.applyExisting) + '</button>'
			+ '<button type="button" class="gfen-btn gfen-btn--secondary gfen-btn--small gfen-js-edit">' + esc(T.edit) + '</button>'
			+ '<button type="button" class="gfen-btn gfen-btn--danger gfen-btn--small gfen-js-delete">' + esc(T.delete) + '</button>'
			+ '</div>'
			+ '</div>'
			+ '<div class="gfen-rule__progress" hidden></div>'
			+ '<div class="gfen-rule__result"></div>'
			+ '</div>';
	}

	function renderRules() {
		if (!D.rules.length) {
			$rules.html(emptyStateHtml());
			return;
		}
		var html = '<div class="gfen-rules-list">';
		D.rules.forEach(function (rule) {
			html += ruleCardHtml(rule);
		});
		html += '</div>';
		html += '<div class="gfen-add-wrap"><button type="button" class="gfen-btn gfen-btn--primary gfen-js-add">' + ICON.plus + ' ' + esc(T.addModification) + '</button></div>';
		$rules.html(html);
	}

	/* ------------------------------------------------------------------ */
	/* Add / edit form                                                     */
	/* ------------------------------------------------------------------ */

	function exampleRowHtml(before, after) {
		return '<div class="gfen-ex">'
			+ '<div class="gfen-ex__field">'
			+ '<span class="gfen-ex__badge">' + esc(T.beforeBadge) + '</span>'
			+ '<input type="text" class="gfen-input gfen-ex-before" value="' + esc(before) + '" placeholder="' + esc(T.beforeExample) + '" />'
			+ '</div>'
			+ '<span class="gfen-ex__arrow">' + ICON.arrow + '</span>'
			+ '<div class="gfen-ex__field">'
			+ '<span class="gfen-ex__badge">' + esc(T.afterBadge) + '</span>'
			+ '<input type="text" class="gfen-input gfen-ex-after" value="' + esc(after) + '" placeholder="' + esc(T.afterExample) + '" />'
			+ '</div>'
			+ '<button type="button" class="gfen-ex__remove gfen-js-remove-example" title="' + esc(T.removeExample) + '">' + ICON.remove + '</button>'
			+ '</div>';
	}

	function optionHtml(candidate, selected) {
		return '<label class="gfen-option gfen-js-option' + (selected ? ' is-selected' : '') + '" data-chain="' + esc(JSON.stringify(candidate.chain)) + '">'
			+ '<span class="gfen-option__dot"></span>'
			+ '<span class="gfen-option__text">'
			+ '<span class="gfen-option__label">' + esc(candidate.label) + '</span>'
			+ '<span class="gfen-option__desc">' + esc(chainDesc(candidate.chain)) + '</span>'
			+ '</span>'
			+ '<span class="gfen-option__match">' + esc(T.exactMatch) + '</span>'
			+ '</label>';
	}

	function openEditor(rule) {
		var fieldOptions = D.fields.map(function (f) {
			var sel = rule && String(rule.field_id) === String(f.id) ? ' selected' : '';
			return '<option value="' + esc(f.id) + '"' + sel + '>' + esc(f.label) + '</option>';
		}).join('');

		var exampleRows;
		if (rule && rule.examples.length) {
			exampleRows = rule.examples.map(function (p) {
				return exampleRowHtml(p.before, p.after);
			}).join('');
		} else {
			exampleRows = exampleRowHtml('', '');
		}

		var html = '<div class="gform-settings-panel gform-settings-panel--full gfen-form">'
			+ '<header class="gform-settings-panel__header gfen-form__head">'
			+ '<span class="gfen-form__dot"></span>'
			+ '<legend class="gform-settings-panel__title gfen-form__title">' + esc(rule ? T.editRule : T.newRule) + '</legend>'
			+ '<span class="gfen-form__status">' + esc(T.notSaved) + '</span>'
			+ '</header>'
			+ '<div class="gform-settings-panel__content gfen-form__body">'

			// Step 1: field
			+ '<div class="gfen-step"><span class="gfen-step__num">1</span><div class="gfen-step__body">'
			+ '<label class="gfen-label" for="gfen-field">' + esc(T.fieldStepLabel) + '</label>'
			+ '<div class="gfen-select-wrap">'
			+ '<select id="gfen-field" class="gfen-select">' + fieldOptions + '</select>'
			+ '<span class="gfen-select-wrap__chevron">' + ICON.chevron + '</span>'
			+ '</div>'
			+ '<div class="gfen-field-warning"></div>'
			+ '</div></div>'

			// Step 2: examples
			+ '<div class="gfen-step"><span class="gfen-step__num">2</span><div class="gfen-step__body">'
			+ '<label class="gfen-label">' + esc(T.examplesLabel) + '</label>'
			+ '<p class="gfen-hint">' + esc(T.examplesHint) + '</p>'
			+ '<div class="gfen-examples">' + exampleRows + '</div>'
			+ '<button type="button" class="gfen-btn gfen-btn--secondary gfen-add-example gfen-js-add-example">' + ICON.plusSmall + ' ' + esc(T.addExample) + '</button>'
			+ '</div></div>'

			// Step 3: detect
			+ '<div class="gfen-step"><span class="gfen-step__num">3</span><div class="gfen-step__body">'
			+ '<label class="gfen-label">' + esc(T.detectStepLabel) + '</label>'
			+ '<button type="button" class="gfen-btn gfen-btn--ghost gfen-js-detect">' + ICON.search + ' ' + esc(T.detect) + '</button>'
			+ '<div class="gfen-detect-results" hidden></div>'
			+ '<div class="gfen-editor-msg"></div>'
			+ '</div></div>'

			+ '</div>'
			+ '<div class="gfen-form__foot">'
			+ '<button type="button" class="gfen-btn gfen-btn--primary gfen-js-save" disabled>' + esc(T.saveRule) + '</button>'
			+ '<button type="button" class="gfen-btn gfen-btn--secondary gfen-js-cancel">' + esc(T.cancel) + '</button>'
			+ '<span class="gfen-form__foot-note">' + esc(T.saveHint) + '</span>'
			+ '</div>'
			+ '</div>';

		$editor.html(html).prop('hidden', false).data('ruleId', rule ? rule.id : null);
		$app.addClass('is-editing');

		refreshFieldWarning();

		// When editing, pre-select the saved transformation as the chosen candidate.
		if (rule) {
			showCandidates([{ chain: rule.chain, label: chainLabel(rule.chain) }], !!rule.apply_future);
		}
	}

	function closeEditor() {
		$editor.prop('hidden', true).empty().removeData('ruleId');
		$app.removeClass('is-editing');
	}

	function refreshFieldWarning() {
		var $sel = $editor.find('#gfen-field');
		if (!$sel.length) {
			return;
		}
		var f = fieldById($sel.val());
		var $w = $editor.find('.gfen-field-warning').empty();
		if (f && f.warning) {
			notice($w, 'warning', f.warning);
		}
	}

	function collectExamples() {
		var pairs = [];
		$editor.find('.gfen-ex').each(function () {
			var before = $(this).find('.gfen-ex-before').val();
			var after = $(this).find('.gfen-ex-after').val();
			if (before !== '') {
				pairs.push({ before: before, after: after });
			}
		});
		return pairs;
	}

	function invalidateDetection() {
		$editor.find('.gfen-detect-results').prop('hidden', true).empty();
		$editor.find('.gfen-js-save').prop('disabled', true);
	}

	function showCandidates(candidates, future) {
		var $r = $editor.find('.gfen-detect-results');
		if (!candidates.length) {
			$r.prop('hidden', true).empty();
			return;
		}
		var opts = candidates.map(function (c, i) {
			return optionHtml(c, i === 0);
		}).join('');
		var checked = future ? ' is-checked' : '';
		var html = '<p class="gfen-detect-results__intro">' + esc(T.candidatesIntro) + '</p>'
			+ '<div class="gfen-options">' + opts + '</div>'
			+ '<label class="gfen-check gfen-js-future' + checked + '">'
			+ '<span class="gfen-check__box"><span class="gfen-check__check">' + ICON.check + '</span></span>'
			+ '<span class="gfen-check__text">'
			+ '<span class="gfen-check__title">' + esc(T.applyFutureLabel) + '</span>'
			+ '<span class="gfen-check__desc">' + esc(T.applyFutureDesc) + '</span>'
			+ '</span>'
			+ '</label>';
		$r.html(html).prop('hidden', false);
		$editor.find('.gfen-js-save').prop('disabled', false);
	}

	/* ------------------------------------------------------------------ */
	/* Preview / bulk apply                                                */
	/* ------------------------------------------------------------------ */

	function runProcess(ruleId, mode, $card) {
		var $progress = $card.find('.gfen-rule__progress');
		var $result = $card.find('.gfen-rule__result');
		var totals = { processed: 0, changed: 0, errors: 0, samples: [], unrecognized: [] };
		var page = 0;

		$card.find('button').prop('disabled', true);
		$result.empty();
		$progress.prop('hidden', false).text(fmt(mode === 'apply' ? T.applyProgress : T.previewProgress, 0, '…'));

		function finish() {
			$progress.prop('hidden', true);
			$card.find('button').prop('disabled', false);
			renderResult($result, mode, totals);
		}

		function step() {
			post('gfen_process', { rule_id: ruleId, mode: mode, page: page })
				.done(function (res) {
					if (!res.success) {
						$progress.prop('hidden', true);
						$card.find('button').prop('disabled', false);
						notice($result, 'error', (res.data && res.data.message) || T.processError);
						return;
					}
					var d = res.data;
					totals.processed += d.processed;
					totals.changed += d.changed;
					totals.errors += d.errors;
					totals.samples = totals.samples.concat(d.samples).slice(0, 100);
					totals.unrecognized = totals.unrecognized.concat(d.unrecognized).slice(0, 50);
					$progress.text(fmt(mode === 'apply' ? T.applyProgress : T.previewProgress, totals.processed, d.total));
					if (d.done) {
						finish();
					} else {
						page++;
						step();
					}
				})
				.fail(function () {
					$progress.prop('hidden', true);
					$card.find('button').prop('disabled', false);
					notice($result, 'error', T.networkError);
				});
		}

		step();
	}

	function renderResult($result, mode, totals) {
		$result.empty();

		var summary;
		if (mode === 'apply') {
			summary = fmt(T.applySummary, totals.changed, totals.processed);
			if (totals.errors) {
				summary += ' ' + fmt(T.writeErrors, totals.errors);
			}
		} else {
			summary = fmt(T.previewSummary, totals.changed, totals.processed);
		}
		notice($result, totals.errors ? 'error' : 'success', summary);

		if (totals.unrecognized.length) {
			var lis = totals.unrecognized.map(function (u) {
				return '<li>' + esc(T.entryLabel) + ' #' + esc(u.entry_id) + ' : ' + esc(u.value) + '</li>';
			}).join('');
			$result.append('<div class="gfen-notice gfen-notice-warning"><p>' + esc(fmt(T.unrecognizedPhones, totals.unrecognized.length)) + '</p><ul>' + lis + '</ul></div>');
		}

		if (totals.samples.length) {
			var rows = totals.samples.map(function (s) {
				return '<tr><td>#' + esc(s.entry_id) + '</td><td>' + esc(s.before) + '</td><td>' + esc(s.after) + '</td></tr>';
			}).join('');
			var afterCol = mode === 'apply' ? T.afterApplied : T.afterProposed;
			$result.append(
				'<table class="gfen-table"><thead><tr>'
				+ '<th>' + esc(T.entryLabel) + '</th><th>' + esc(T.beforeCol) + '</th><th>' + esc(afterCol) + '</th>'
				+ '</tr></thead><tbody>' + rows + '</tbody></table>'
			);
			if (totals.changed > totals.samples.length) {
				$result.append('<p class="gfen-more">' + esc(fmt(T.moreChanges, totals.changed - totals.samples.length)) + '</p>');
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Event wiring (delegated on the stable shell mount points)           */
	/* ------------------------------------------------------------------ */

	function bindEvents() {

		// Rules list.
		$rules.on('click', '.gfen-js-add', function () {
			if (!D.fields.length) {
				window.alert(T.noCompatibleField);
				return;
			}
			openEditor(null);
			scrollToEditor();
		});
		$rules.on('click', '.gfen-js-preview', function () {
			var $card = $(this).closest('.gfen-rule');
			runProcess($card.attr('data-rule-id'), 'preview', $card);
		});
		$rules.on('click', '.gfen-js-apply', function () {
			var $card = $(this).closest('.gfen-rule');
			if (window.confirm(T.confirmApply)) {
				runProcess($card.attr('data-rule-id'), 'apply', $card);
			}
		});
		$rules.on('click', '.gfen-js-edit', function () {
			var rule = ruleById($(this).closest('.gfen-rule').attr('data-rule-id'));
			if (rule) {
				openEditor(rule);
				scrollToEditor();
			}
		});
		$rules.on('click', '.gfen-js-delete', function () {
			var $card = $(this).closest('.gfen-rule');
			if (!window.confirm(T.confirmDelete)) {
				return;
			}
			post('gfen_delete_rule', { rule_id: $card.attr('data-rule-id') }).done(function (res) {
				if (res.success) {
					D.rules = res.data.rules;
					renderRules();
				}
			});
		});

		// Editor form.
		$editor.on('change', '#gfen-field', refreshFieldWarning);

		$editor.on('click', '.gfen-js-add-example', function () {
			$editor.find('.gfen-examples').append(exampleRowHtml('', ''));
			invalidateDetection();
		});

		$editor.on('click', '.gfen-js-remove-example', function () {
			var $rowsWrap = $editor.find('.gfen-examples');
			var $row = $(this).closest('.gfen-ex');
			if ($rowsWrap.find('.gfen-ex').length > 1) {
				$row.remove();
			} else {
				$row.find('.gfen-ex-before, .gfen-ex-after').val('');
			}
			invalidateDetection();
		});

		$editor.on('input', '.gfen-ex-before, .gfen-ex-after', invalidateDetection);

		$editor.on('click', '.gfen-js-option', function () {
			$(this).closest('.gfen-options').find('.gfen-option').removeClass('is-selected');
			$(this).addClass('is-selected');
		});

		$editor.on('click', '.gfen-js-future', function (e) {
			e.preventDefault();
			$(this).toggleClass('is-checked');
		});

		$editor.on('click', '.gfen-js-detect', function () {
			var pairs = collectExamples();
			var $msg = $editor.find('.gfen-editor-msg').empty();
			if (!pairs.length) {
				notice($msg, 'error', T.needExample);
				return;
			}
			var $btn = $(this);
			var original = $btn.html();
			var future = $editor.find('.gfen-js-future').hasClass('is-checked');
			$btn.prop('disabled', true).text(T.detecting);
			post('gfen_detect', { examples: JSON.stringify(pairs) })
				.done(function (res) {
					if (res.success) {
						if (res.data.candidates.length) {
							showCandidates(res.data.candidates, future);
						} else {
							notice($msg, 'error', T.noMatch);
						}
					} else {
						notice($msg, 'error', (res.data && res.data.message) || T.detectError);
					}
				})
				.fail(function () {
					notice($msg, 'error', T.networkError);
				})
				.always(function () {
					$btn.prop('disabled', false).html(original);
				});
		});

		$editor.on('click', '.gfen-js-save', function () {
			var $msg = $editor.find('.gfen-editor-msg').empty();
			var $opt = $editor.find('.gfen-option.is-selected');
			var chain = null;
			if ($opt.length) {
				try {
					chain = JSON.parse($opt.attr('data-chain'));
				} catch (e) {
					chain = null;
				}
			}
			if (!chain) {
				notice($msg, 'error', T.needCandidate);
				return;
			}
			var payload = {
				id: $editor.data('ruleId') || null,
				field_id: $editor.find('#gfen-field').val(),
				chain: chain,
				examples: collectExamples(),
				apply_future: $editor.find('.gfen-js-future').hasClass('is-checked')
			};
			var $btn = $(this).prop('disabled', true);
			post('gfen_save_rule', { rule: JSON.stringify(payload) })
				.done(function (res) {
					if (res.success) {
						D.rules = res.data.rules;
						closeEditor();
						renderRules();
					} else {
						notice($msg, 'error', (res.data && res.data.message) || T.saveError);
						$btn.prop('disabled', false);
					}
				})
				.fail(function () {
					notice($msg, 'error', T.networkError);
					$btn.prop('disabled', false);
				});
		});

		$editor.on('click', '.gfen-js-cancel', closeEditor);
	}

	/* ------------------------------------------------------------------ */
	/* Init                                                                */
	/* ------------------------------------------------------------------ */

	$(function () {
		$app = $('#gfen-app');
		if (!$app.length) {
			return;
		}
		// Read config on ready (the inline gfenData is printed in the page body).
		D = window.gfenData;
		if (!D) {
			return;
		}
		T = D.i18n;
		$rules = $('#gfen-rules');
		$editor = $('#gfen-editor');

		bindEvents();
		renderRules();
		maybeOpenFromDeepLink();
	});

	// "More options…" from the field editor links here with ?gfen_field=<id>.
	// Open a fresh rule editor with that field preselected.
	function maybeOpenFromDeepLink() {
		if (!D.fields.length || typeof window.URLSearchParams === 'undefined') {
			return;
		}
		var requested = new URLSearchParams(window.location.search).get('gfen_field');
		if (!requested) {
			return;
		}
		// Exact input/field match, else the first sub-input of that field.
		var match = null, i;
		for (i = 0; i < D.fields.length; i++) {
			if (D.fields[i].id === requested) { match = requested; break; }
		}
		if (!match) {
			for (i = 0; i < D.fields.length; i++) {
				if (D.fields[i].id.indexOf(requested + '.') === 0) { match = D.fields[i].id; break; }
			}
		}
		if (!match) {
			return;
		}
		openEditor(null);
		$editor.find('#gfen-field').val(match).trigger('change');
		scrollToEditor();
	}

})(jQuery);
