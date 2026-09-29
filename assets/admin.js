(function ($) {
	'use strict';

	function codeEditorSettings(type) {
		var settings = mcsmAdmin.codeSettings && (mcsmAdmin.codeSettings[type] || mcsmAdmin.codeSettings.html);
		var cloned = settings ? $.extend(true, {}, settings) : null;

		if (!cloned) {
			return null;
		}

		cloned.codemirror = cloned.codemirror || {};
		cloned.codemirror.lineWrapping = false;
		cloned.codemirror.indentUnit = 4;
		cloned.codemirror.tabSize = 4;

		if (type === 'php') {
			cloned.codemirror.mode = 'application/x-httpd-php-open';
		}

		return cloned;
	}

	function initializeCodeEditor(textarea, type) {
		var settings;
		var editor;

		if (!textarea.length || textarea.data('mcsmCodeEditor') || !window.wp || !wp.codeEditor) {
			return textarea.data('mcsmCodeEditor') || null;
		}

		settings = codeEditorSettings(type);
		if (!settings) {
			return null;
		}

		editor = wp.codeEditor.initialize(textarea, settings);
		textarea.data('mcsmCodeEditor', editor);
		if (editor.codemirror) {
			editor.codemirror.on('change', function (instance) {
				textarea.val(instance.getValue()).trigger('mcsm-code-change');
			});
			editor.codemirror.getWrapperElement().addEventListener('keydown', function (event) {
				event.stopPropagation();
			});
			window.setTimeout(function () {
				editor.codemirror.refresh();
			}, 0);
		}
		return editor;
	}

	function setEditorMode(textarea, type) {
		var editor = textarea.data('mcsmCodeEditor');
		var settings = codeEditorSettings(type);

		if (editor && editor.codemirror && settings && settings.codemirror && settings.codemirror.mode) {
			editor.codemirror.setOption('mode', settings.codemirror.mode);
			editor.codemirror.setOption('lineWrapping', false);
			editor.codemirror.refresh();
		}
	}

	function codeValue(textarea) {
		var editor = textarea.data('mcsmCodeEditor');
		return editor && editor.codemirror ? editor.codemirror.getValue() : textarea.val();
	}

	function initializeLocalEditor(row) {
		var type = row.find('.mcsm-local-type-field').val() || 'html';
		var textarea = row.find('.mcsm-local-code');
		initializeCodeEditor(textarea, type);
		setEditorMode(textarea, type);
	}

	function updateStateToggle(input) {
		var label = input.is(':checked') ? input.data('on-label') : input.data('off-label');
		input.closest('.mcsm-form-toggle').find('.mcsm-toggle-state').text(label);
	}

	function selectedPickerIds(picker) {
		var selected = {};
		picker.find('.mcsm-picker-chip').each(function () {
			selected[$(this).data('id')] = true;
		});
		return selected;
	}

	function renderPickerResults(picker, items, emptyMessage) {
		var dropdown = picker.find('.mcsm-picker-dropdown');
		var selected = selectedPickerIds(picker);
		var visible = 0;

		dropdown.empty();
		items.forEach(function (item) {
			if (selected[item.id]) {
				return;
			}

			$('<button type="button" class="mcsm-picker-option" />')
				.attr('data-id', item.id)
				.attr('data-label', item.title)
				.attr('data-type', item.type)
				.append($('<span class="mcsm-picker-option-title" />').text(item.title))
				.append($('<span class="mcsm-picker-option-type" />').text(item.type))
				.appendTo(dropdown);
			visible++;
		});

		if (!visible) {
			$('<div class="mcsm-picker-empty" />').text(emptyMessage).appendTo(dropdown);
		}

		dropdown.prop('hidden', false);
	}

	function searchPicker(picker) {
		var input = picker.find('.mcsm-picker-search');
		var query = $.trim(input.val());
		var previousRequest = picker.data('mcsmSearchRequest');

		if (previousRequest && previousRequest.readyState !== 4) {
			previousRequest.abort();
		}

		if (query.length < 2) {
			renderPickerResults(picker, [], mcsmAdmin.searchPrompt);
			return;
		}

		var request = $.post(window.ajaxurl, {
			action: 'mcsm_search_content',
			nonce: mcsmAdmin.contentSearchNonce,
			query: query,
			post_types: (picker.attr('data-post-types') || '').split(',')
		}).done(function (response) {
			var items = response && response.success && response.data ? response.data.items : [];
			renderPickerResults(picker, items || [], mcsmAdmin.noMatches);
		}).fail(function (xhr, status) {
			if (status !== 'abort') {
				renderPickerResults(picker, [], mcsmAdmin.searchFailed);
			}
		});

		picker.data('mcsmSearchRequest', request);
	}

	function updateLocalSummary(row) {
		var name = row.find('.mcsm-local-name-field').val() || mcsmAdmin.snippet;
		var type = row.find('.mcsm-local-type-field option:selected').text();
		var isPhp = row.find('.mcsm-local-type-field').val() === 'php';
		var location = isPhp ? mcsmAdmin.wordpressHooks : row.find('.mcsm-local-location-field option:selected').text();
		var devices = row.find('.mcsm-local-devices-field option:selected').text();
		var isActive = row.find('.mcsm-local-status-field').is(':checked');
		var isLoggedInOnly = row.find('.mcsm-local-visibility-field').is(':checked');
		var status = isActive ? mcsmAdmin.active : mcsmAdmin.inactive;
		var pills = row.find('.mcsm-pill');

		row.find('.mcsm-local-summary-name').text(name);
		pills.eq(0).text(status).toggleClass('is-active', isActive).toggleClass('is-inactive', !isActive);
		pills.eq(1).text(type);
		pills.eq(2).text(location);
		pills.eq(3).text(devices);
		row.find('.mcsm-pill-visibility').prop('hidden', !isLoggedInOnly);
		row.toggleClass('is-php', isPhp);
		row.find('.mcsm-local-location-control').toggle(!isPhp);
		row.find('.mcsm-code-editor-shell').toggleClass('is-php', isPhp);
	}

	function updateGlobalTypeUI() {
		var type = $('#mcsm-type').val();
		var isPhp = type === 'php';
		var isShortcodeOnly = $('#mcsm-location').val() === 'shortcode_only';

		$('.mcsm-location-row').toggle(!isPhp);
		$('.mcsm-priority-row').toggle(!isPhp && !isShortcodeOnly);
		$('.mcsm-shortcode-section').prop('hidden', isPhp);
		$('.mcsm-main-panel .mcsm-code-editor-shell').toggleClass('is-php', isPhp);
		setEditorMode($('#mcsm-code'), type);
	}

	function updateLocationHelp() {
		$('.mcsm-body-open-help').toggle($('#mcsm-location').val() === 'body_open' && $('#mcsm-type').val() !== 'php');
	}

	function validationHints(type, code, showEmpty) {
		var hints = [];
		var trimmed = $.trim(code);

		if (!trimmed) {
			if (showEmpty) {
				hints.push(mcsmAdmin.emptyCode);
			}
			return hints;
		}

		if (type === 'javascript') {
			if ((trimmed.match(/<script\b/gi) || []).length !== (trimmed.match(/<\/script>/gi) || []).length) {
				hints.push(mcsmAdmin.unmatchedScript);
			}
			if (trimmed.indexOf('<script') === -1 && trimmed.indexOf('</script>') !== -1) {
				hints.push(mcsmAdmin.orphanScriptClose);
			}
		}

		if (type === 'css') {
			if ((trimmed.match(/<style\b/gi) || []).length !== (trimmed.match(/<\/style>/gi) || []).length) {
				hints.push(mcsmAdmin.unmatchedStyle);
			}
			if (trimmed.indexOf('<script') !== -1) {
				hints.push(mcsmAdmin.cssContainsScript);
			}
		}

		if (type === 'html' && (trimmed.indexOf('<script') !== -1 || trimmed.indexOf('<style') !== -1)) {
			hints.push(mcsmAdmin.htmlMixedCode);
		}

		if (type === 'php') {
			if (trimmed.indexOf('<?') !== -1 || trimmed.indexOf('?>') !== -1) {
				hints.push(mcsmAdmin.phpTags);
			}
			if (/\b(exit|die|namespace|goto)\b/i.test(trimmed)) {
				hints.push(mcsmAdmin.phpBlocked);
			}
		}

		return hints;
	}

	function renderHints(container, hints) {
		var box = container.find('.mcsm-validation-hints').first();
		box.empty();

		if (!hints.length) {
			return;
		}

		$('<ul />').appendTo(box);
		hints.forEach(function (hint) {
			box.find('ul').append($('<li />').text(hint));
		});
	}

	function validateGlobalSnippet(showEmpty) {
		if (!$('#mcsm-code').length) {
			return true;
		}
		var type = $('#mcsm-type').val();
		var code = codeValue($('#mcsm-code'));
		var hints = validationHints(type, code, !!showEmpty);
		renderHints($('.mcsm-main-panel'), hints);
		return !isBlockingValidationError(type, code, !!showEmpty);
	}

	function validateLocalSnippet(row, showEmpty) {
		var type = row.find('.mcsm-local-type-field').val();
		var code = codeValue(row.find('.mcsm-local-code'));
		var hints = validationHints(type, code, !!showEmpty);
		renderHints(row, hints);
		return !isBlockingValidationError(type, code, !!showEmpty);
	}

	function isBlockingValidationError(type, code, showEmpty) {
		var trimmed = $.trim(code);

		if (showEmpty && !trimmed) {
			return true;
		}

		return type === 'php' && (trimmed.indexOf('<?') !== -1 || trimmed.indexOf('?>') !== -1);
	}

	$(function () {
		if ($('#mcsm-code').length) {
			initializeCodeEditor($('#mcsm-code'), $('#mcsm-type').val() || 'html');
		}

		$('.mcsm-state-toggle').each(function () {
			updateStateToggle($(this));
		});

		$(document).on('change', '.mcsm-state-toggle', function () {
			updateStateToggle($(this));
		});

		$(document).on('click', '.mcsm-status-toggle', function () {
			var toggle = $(this);
			var isActive = toggle.attr('aria-checked') === 'true';
			var nextStatus = isActive ? 'inactive' : 'active';
			var snippetName = toggle.closest('tr').find('.row-title').text();

			if (toggle.prop('disabled')) {
				return;
			}

			toggle
				.prop('disabled', true)
				.addClass('is-updating')
				.attr('aria-checked', isActive ? 'false' : 'true');

			$.post(window.ajaxurl, {
				action: 'mcsm_toggle_status',
				nonce: mcsmAdmin.statusNonce,
				id: toggle.data('snippet-id'),
				status: nextStatus
			}).done(function (response) {
				if (!response || !response.success) {
					toggle.attr('aria-checked', isActive ? 'true' : 'false');
					window.alert(response && response.data && response.data.message ? response.data.message : mcsmAdmin.statusUpdateFailed);
					return;
				}

				toggle.attr(
					'aria-label',
					(nextStatus === 'active' ? mcsmAdmin.deactivateSnippet : mcsmAdmin.activateSnippet).replace('%s', snippetName)
				);
				if (response.data.modified_at) {
					toggle.closest('tr').find('.column-updated_at').text(response.data.modified_at);
				}
			}).fail(function (request) {
				var message = request.responseJSON && request.responseJSON.data && request.responseJSON.data.message;
				toggle.attr('aria-checked', isActive ? 'true' : 'false');
				window.alert(message || mcsmAdmin.statusUpdateFailed);
			}).always(function () {
				toggle.prop('disabled', false).removeClass('is-updating');
			});
		});

		$(document).on('click', '.mcsm-confirm-delete', function (event) {
			if (!window.confirm(mcsmAdmin.deleteSnippet)) {
				event.preventDefault();
			}
		});

		$(document).on('click', '.mcsm-confirm-clear-activity', function (event) {
			if (!window.confirm(mcsmAdmin.clearActivityLog)) {
				event.preventDefault();
			}
		});

		$(document).on('submit', '.mcsm-wrap form', function (event) {
			var action = $(this).find('[name="action"]').val();
			var action2 = $(this).find('[name="action2"]').val();
			if ((action === 'delete' || action2 === 'delete') && !window.confirm(mcsmAdmin.deleteSelected)) {
				event.preventDefault();
			}
		});

		$(document).on('submit', '.mcsm-settings-form', function (event) {
			if ($(this).find('[name="delete_data_on_uninstall"]').is(':checked') && !window.confirm(mcsmAdmin.deleteDataWarning)) {
				event.preventDefault();
			}
		});

		$(document).on('submit', 'form[action*="mcsm&action=save"]', function (event) {
			if (!validateGlobalSnippet(true)) {
				event.preventDefault();
			}
		});

		$(document).on('submit', '#post', function (event) {
			var valid = true;
			$('.mcsm-local-snippet').each(function () {
				var row = $(this);
				var hasContent = $.trim(row.find('.mcsm-local-name-field').val()) || $.trim(codeValue(row.find('.mcsm-local-code')));
				if (hasContent && !validateLocalSnippet(row, true)) {
					valid = false;
					row.addClass('is-open');
					row.find('.mcsm-local-snippet-toggle').attr('aria-expanded', 'true');
				}
			});
			if (!valid) {
				event.preventDefault();
			}
		});

		$(document).on('focus', '.mcsm-picker-search', function () {
			searchPicker($(this).closest('.mcsm-content-picker'));
		});

		$(document).on('input', '.mcsm-picker-search', function () {
			var picker = $(this).closest('.mcsm-content-picker');
			window.clearTimeout(picker.data('mcsmSearchTimer'));
			picker.data('mcsmSearchTimer', window.setTimeout(function () {
				searchPicker(picker);
			}, 250));
		});

		$(document).on('click', '.mcsm-picker-option', function () {
			var option = $(this);
			var picker = option.closest('.mcsm-content-picker');
			var chip = $('<span class="mcsm-picker-chip" />')
				.attr('data-id', option.data('id'))
				.append($('<span />').text(option.data('label')))
				.append($('<small />').text(option.data('type')))
				.append($('<button type="button" class="mcsm-picker-remove">×</button>').attr('aria-label', mcsmAdmin.remove))
				.append($('<input type="hidden" />').attr('name', picker.data('field-name') + '[]').val(option.data('id')));

			picker.find('.mcsm-picker-selected').append(chip);
			picker.find('.mcsm-picker-search').val('').focus();
			searchPicker(picker);
		});

		$(document).on('click', '.mcsm-picker-remove', function () {
			var picker = $(this).closest('.mcsm-content-picker');
			$(this).closest('.mcsm-picker-chip').remove();
			searchPicker(picker);
		});

		$(document).on('click', function (event) {
			if (!$(event.target).closest('.mcsm-content-picker').length) {
				$('.mcsm-picker-dropdown').prop('hidden', true);
			}
		});

		$(document).on('click', '.mcsm-add-local-snippet', function () {
			var container = $(this).closest('.mcsm-local-box').find('.mcsm-local-snippets');
			var index = parseInt(container.attr('data-next-index'), 10) || 0;
			var template = $('#tmpl-mcsm-local-snippet-row').html();

			container.append(template.replace(/__INDEX__/g, index));
			container.attr('data-next-index', index + 1);
			var rows = container.find('.mcsm-local-snippet');
			var row = rows.eq(rows.length - 1);
			updateLocalSummary(row);
			updateStateToggle(row.find('.mcsm-state-toggle'));
			initializeLocalEditor(row);
		});

		$(document).on('change', '#mcsm-display-rule', function () {
			var rule = $(this).val();
			$('.mcsm-rule-row').hide().find('input[type="hidden"]').prop('disabled', true);
			$('.mcsm-rule-row[data-rule-row="' + rule + '"]').show().find('input[type="hidden"]').prop('disabled', false);
		});
		$('#mcsm-display-rule').trigger('change');

		$(document).on('change', '#mcsm-type', function () {
			updateGlobalTypeUI();
			updateLocationHelp();
		});

		$(document).on('change', '#mcsm-location', function () {
			updateGlobalTypeUI();
			updateLocationHelp();
		});

		$(document).on('click', '.mcsm-local-snippet-toggle', function () {
			var row = $(this).closest('.mcsm-local-snippet');
			row.toggleClass('is-open');
			$(this).attr('aria-expanded', row.hasClass('is-open') ? 'true' : 'false');
			if (row.hasClass('is-open')) {
				initializeLocalEditor(row);
			}
		});

		$(document).on('click', '.mcsm-remove-local-snippet', function () {
			if (window.confirm(mcsmAdmin.deleteLocal)) {
				$(this).closest('.mcsm-local-snippet').remove();
			}
		});

		$(document).on('input change', '.mcsm-local-name-field, .mcsm-local-type-field, .mcsm-local-location-field, .mcsm-local-devices-field, .mcsm-local-status-field, .mcsm-local-visibility-field', function () {
			var row = $(this).closest('.mcsm-local-snippet');
			updateLocalSummary(row);
			if ($(this).hasClass('mcsm-local-type-field')) {
				initializeLocalEditor(row);
				setEditorMode(row.find('.mcsm-local-code'), $(this).val());
			}
			validateLocalSnippet(row, false);
		});

		$(document).on('input change mcsm-code-change', '#mcsm-code, #mcsm-type', function () {
			validateGlobalSnippet(false);
		});

		$(document).on('input change mcsm-code-change', '.mcsm-local-code', function () {
			validateLocalSnippet($(this).closest('.mcsm-local-snippet'), false);
		});

		validateGlobalSnippet(false);
		updateGlobalTypeUI();
		updateLocationHelp();
		$('.mcsm-local-snippet').each(function () {
			var row = $(this);
			updateLocalSummary(row);
			if (row.hasClass('is-open')) {
				initializeLocalEditor(row);
			}
			validateLocalSnippet(row, false);
		});
	});
})(jQuery);
