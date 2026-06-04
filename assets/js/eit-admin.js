(function () {
	'use strict';

	document.documentElement.classList.add('eit-admin-ready');

	var filterIconClasses = {
		search: 'dashicons dashicons-search',
		checkbox: 'dashicons dashicons-yes-alt',
		radio: 'dashicons dashicons-marker',
		select: 'dashicons dashicons-category',
		chips: 'dashicons dashicons-screenoptions',
		toggle: 'dashicons dashicons-controls-repeat',
		range: 'dashicons dashicons-slides',
		date: 'dashicons dashicons-calendar-alt',
		swatch: 'dashicons dashicons-art',
		rating: 'dashicons dashicons-star-filled'
	};

	document.querySelectorAll('[data-eit-repeater]').forEach(function (repeater) {
		var list = repeater.querySelector('[data-eit-repeat-list]');
		var template = repeater.querySelector('[data-eit-row-template]');
		var addButton = repeater.querySelector('[data-eit-add-row]');

		if (!list || !template || !addButton) {
			return;
		}

		function editorRowFor(row) {
			var next = row ? row.nextElementSibling : null;

			if (!next) {
				return null;
			}

			return next.classList.contains('eit-filter-editor-row') || next.classList.contains('eit-editor-modal-row') ? next : null;
		}

		function summaryRowFor(element) {
			var row = element.closest('.eit-repeat-row');
			var editor = element.closest('.eit-filter-editor-row, .eit-editor-modal-row');

			if (row) {
				return row;
			}

			if (editor && editor.previousElementSibling && editor.previousElementSibling.classList.contains('eit-repeat-row')) {
				return editor.previousElementSibling;
			}

			return null;
		}

		function rowScope(row) {
			var editor = editorRowFor(row);

			return editor ? editor : row;
		}

		function visibleEditorRows() {
			return document.querySelectorAll('.eit-filter-editor-row:not([hidden]), .eit-editor-modal-row:not([hidden])').length;
		}

		function syncModalState() {
			document.body.classList.toggle('eit-modal-open', visibleEditorRows() > 0 || document.querySelectorAll('.eit-modal:not([hidden])').length > 0);
		}

		function openModalRow(row) {
			var editor = editorRowFor(row);

			if (!editor) {
				return;
			}

			editor.hidden = false;
			var modal = editor.querySelector('.eit-modal');

			if (modal) {
				modal.hidden = false;
			}

			syncModalState();

			var input = editor.querySelector('input, select, textarea, button');

			if (input) {
				input.focus();
			}
		}

		function closeModalRow(editor) {
			if (!editor) {
				return;
			}

			var modal = editor.querySelector('.eit-modal');

			if (modal) {
				modal.hidden = true;
			}

			editor.hidden = true;
			syncModalState();
		}

		function updateSummary(row) {
			var scope = rowScope(row);
			var title = row.querySelector('[data-eit-row-title]') || row.querySelector('summary span');
			var label = row.querySelector('[data-eit-row-label]');
			var type = row.querySelector('[data-eit-row-type]') || row.querySelector('summary small');
			var icon = row.querySelector('[data-eit-row-icon]');
			var settings = row.querySelector('[data-eit-row-settings]');
			var titleSource = title ? title.getAttribute('data-eit-row-title-source') : '';
			var labelSource = label ? label.getAttribute('data-eit-row-label-source') : '';
			var titleInput = titleSource ? scope.querySelector('input[name$="[' + titleSource + ']"]') : scope.querySelector('input[name$="[label]"], input[name$="[plural]"], input[name$="[key]"]');
			var labelInput = labelSource ? scope.querySelector('input[name$="[' + labelSource + ']"]') : scope.querySelector('input[name$="[label]"], input[name$="[plural]"], input[name$="[key]"]');
			var keyInput = scope.querySelector('input[name$="[key]"]');
			var placeholderInput = scope.querySelector('input[name$="[placeholder]"]');
			var typeSelect = scope.querySelector('[data-eit-row-type-source]') || scope.querySelector('select[name$="[type]"]');

			if (title && titleInput && titleInput.value.trim()) {
				title.textContent = titleInput.value.trim();
			}

			if (label && labelInput && labelInput.value.trim()) {
				label.textContent = labelInput.value.trim();
			}

			if (type && typeSelect) {
				type.textContent = typeSelect.options[typeSelect.selectedIndex] ? typeSelect.options[typeSelect.selectedIndex].textContent : typeSelect.value;
			}

			if (icon && typeSelect) {
				icon.className = 'eit-filter-icon ' + (filterIconClasses[typeSelect.value] || 'dashicons dashicons-filter');
			}

			if (settings) {
				var parts = [];

				if (placeholderInput && placeholderInput.value.trim()) {
					parts.push('Placeholder: ' + placeholderInput.value.trim());
				}

				if (keyInput && keyInput.value.trim()) {
					parts.push('Key: ' + keyInput.value.trim());
				}

				settings.textContent = parts.length ? parts.slice(0, 2).join(' - ') : 'Default settings';
			}
		}

		function refreshRows() {
			list.querySelectorAll('.eit-repeat-row').forEach(function (row, index) {
				var number = row.querySelector('[data-eit-row-number]');

				if (number) {
					number.textContent = String(index + 1);
				}

				updateSummary(row);
			});
		}

		function nextIndex() {
			var index = parseInt(repeater.getAttribute('data-eit-repeater-next-index') || '0', 10);
			repeater.setAttribute('data-eit-repeater-next-index', String(index + 1));
			return index;
		}

		addButton.addEventListener('click', function () {
			var html = template.innerHTML.replace(/__index__/g, String(nextIndex()));
			var previousCount = list.children.length;
			list.insertAdjacentHTML('beforeend', html);
			var inserted = Array.prototype.slice.call(list.children, previousCount);
			var row = inserted.filter(function (child) {
				return child.classList.contains('eit-repeat-row');
			})[0];

			if (row && 'open' in row) {
				row.open = true;
			}

			if (row) {
				openModalRow(row);

				refreshRows();
				var firstInput = rowScope(row).querySelector('input, select, textarea');
				if (firstInput) {
					firstInput.focus();
				}
			}
		});

		list.addEventListener('click', function (event) {
			var remove = event.target.closest('[data-eit-remove-row]');

			if (!remove) {
				return;
			}

			event.preventDefault();
			var row = summaryRowFor(remove);

			if (row) {
				var editor = editorRowFor(row);
				if (editor) {
					editor.remove();
				}

				row.remove();
				refreshRows();
			}
		});

		list.addEventListener('click', function (event) {
			var toggle = event.target.closest('[data-eit-toggle-filter], [data-eit-open-row]');

			if (!toggle) {
				return;
			}

			event.preventDefault();
			var row = summaryRowFor(toggle);

			if (row) {
				openModalRow(row);
			}
		});

		list.addEventListener('click', function (event) {
			var close = event.target.closest('[data-eit-close-modal], .eit-modal__backdrop');

			if (!close) {
				return;
			}

			event.preventDefault();
			closeModalRow(close.closest('.eit-filter-editor-row, .eit-editor-modal-row'));
		});

		list.addEventListener('input', function (event) {
			var row = summaryRowFor(event.target);

			if (row) {
				updateSummary(row);
			}
		});

		list.addEventListener('change', function (event) {
			var row = summaryRowFor(event.target);

			if (row) {
				updateSummary(row);
			}
		});

		refreshRows();
	});

	document.addEventListener('click', function (event) {
		var opener = event.target.closest('[data-eit-open-modal]');
		var closer = event.target.closest('[data-eit-close-modal], .eit-modal__backdrop');

		if (opener) {
			event.preventDefault();
			var target = document.getElementById(opener.getAttribute('data-eit-open-modal'));

			if (target) {
				target.hidden = false;
				document.body.classList.add('eit-modal-open');

				var first = target.querySelector('input, select, textarea, button');

				if (first) {
					first.focus();
				}
			}

			return;
		}

		if (closer) {
			var modal = closer.closest('.eit-modal');

			if (modal) {
				event.preventDefault();
				modal.hidden = true;
				document.body.classList.toggle('eit-modal-open', document.querySelectorAll('.eit-modal:not([hidden])').length > 0 || document.querySelectorAll('.eit-filter-editor-row:not([hidden]), .eit-editor-modal-row:not([hidden])').length > 0);
			}
		}
	});

	document.addEventListener('keydown', function (event) {
		if ('Escape' !== event.key) {
			return;
		}

		var modal = document.querySelector('.eit-modal:not([hidden])');
		var editor = document.querySelector('.eit-filter-editor-row:not([hidden]), .eit-editor-modal-row:not([hidden])');

		if (modal) {
			modal.hidden = true;
		}

		if (editor) {
			editor.hidden = true;
		}

		document.body.classList.toggle('eit-modal-open', document.querySelectorAll('.eit-modal:not([hidden])').length > 0 || document.querySelectorAll('.eit-filter-editor-row:not([hidden]), .eit-editor-modal-row:not([hidden])').length > 0);
	});
})();
