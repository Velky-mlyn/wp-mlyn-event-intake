(function () {
	'use strict';

	const rows = document.getElementById('mei-event-rows');
	const addButton = document.getElementById('mei-add-row');
	const template = document.getElementById('mei-row-template');
	const form = document.querySelector('.mei-events-form');
	const modal = document.getElementById('mei-content-modal');
	const monthSelect = document.getElementById('mei-month-select');
	let activeContent = null;
	let dirty = false;

	function uuid() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (character) {
			const random = Math.random() * 16 | 0;
			return (character === 'x' ? random : (random & 0x3 | 0x8)).toString(16);
		});
	}

	function editorValue(value) {
		const editor = window.tinymce && window.tinymce.get('mei_content_editor');
		const textarea = document.getElementById('mei_content_editor');
		if (typeof value !== 'undefined') {
			if (editor) editor.setContent(value || '');
			if (textarea) textarea.value = value || '';
			return value || '';
		}
		return editor && !editor.isHidden() ? editor.getContent() : (textarea ? textarea.value : '');
	}

	function textExcerpt(html) {
		const holder = document.createElement('div');
		holder.innerHTML = html || '';
		const text = (holder.textContent || '').trim();
		return text.length > 70 ? text.slice(0, 70) + '…' : text;
	}

	function openContent(row) {
		activeContent = row.querySelector('.mei-content');
		editorValue(activeContent.value);
		modal.hidden = false;
		document.body.classList.add('mei-modal-open');
		setTimeout(function () {
			const editor = window.tinymce && window.tinymce.get('mei_content_editor');
			if (editor) editor.focus();
		}, 0);
	}

	function closeContent(apply) {
		if (apply && activeContent) {
			activeContent.value = editorValue();
			activeContent.closest('td').querySelector('.mei-content-excerpt').textContent = textExcerpt(activeContent.value);
			dirty = true;
		}
		activeContent = null;
		modal.hidden = true;
		document.body.classList.remove('mei-modal-open');
	}

	function toggleAllDay(row, checked) {
		const start = row.querySelector('.mei-start');
		const end = row.querySelector('.mei-end');
		[start, end].forEach(function (field, index) {
			if (checked) {
				field.dataset.timedValue = field.value;
				field.type = 'date';
				field.value = field.value.slice(0, 10);
			} else {
				const date = field.value.slice(0, 10);
				const remembered = field.dataset.timedValue || '';
				field.type = 'datetime-local';
				field.value = remembered.slice(0, 10) === date ? remembered : (date ? date + (index ? 'T10:00' : 'T09:00') : '');
			}
		});
	}

	function updateSortData(row) {
		row.dataset.title = (row.querySelector('.mei-title') || {}).value || '';
		row.dataset.start = (row.querySelector('.mei-start') || {}).value || '';
		row.dataset.end = (row.querySelector('.mei-end') || {}).value || '';
		row.dataset.cost = (row.querySelector('.mei-cost') || {}).value || '0';
	}

	function addRow() {
		const index = rows.querySelectorAll('.mei-event-row').length + '-' + Date.now();
		rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', index));
		const row = rows.lastElementChild;
		row.querySelector('input[name$="[uuid]"]').value = uuid();
		row.querySelector('.mei-title').focus();
		dirty = true;
	}

	function validateRowMonths() {
		if (!form) return true;
		const month = form.dataset.month || '';
		const displayMonth = month.length === 7 ? month.slice(5, 7) + '/' + month.slice(0, 4) : month;
		const editableRows = Array.from(rows.querySelectorAll('.mei-event-row:not(.is-readonly)'));
		for (let index = 0; index < editableRows.length; index += 1) {
			const field = editableRows[index].querySelector('.mei-start');
			if (field && field.value && field.value.slice(0, 7) !== month) {
				const message = meiAdmin.monthError.replace('%1$d', index + 1).replace('%2$s', displayMonth);
				field.setCustomValidity(message);
				field.focus();
				field.reportValidity();
				return false;
			}
		}
		return true;
	}

	if (rows) {
		rows.addEventListener('click', function (event) {
			const row = event.target.closest('.mei-event-row');
			if (!row) return;
			if (event.target.closest('.mei-remove-row')) {
				if (window.confirm(meiAdmin.confirmRemove)) {
					row.remove();
					dirty = true;
				}
				return;
			}
			if (event.target.closest('.mei-edit-content')) openContent(row);
		});

		rows.addEventListener('change', function (event) {
			const row = event.target.closest('.mei-event-row');
			if (!row) return;
			if (event.target.matches('.mei-all-day')) toggleAllDay(row, event.target.checked);
			if (event.target.matches('input[type="file"]') && event.target.files && event.target.files[0]) {
				const preview = row.querySelector('.mei-image-preview');
				const image = document.createElement('img');
				image.src = URL.createObjectURL(event.target.files[0]);
				image.alt = '';
				preview.replaceChildren(image);
			}
			updateSortData(row);
			dirty = true;
		});

		rows.addEventListener('input', function (event) {
			const row = event.target.closest('.mei-event-row');
			if (row) updateSortData(row);
			if (event.target.matches('.mei-start')) event.target.setCustomValidity('');
			dirty = true;
		});
	}

	if (addButton && template) addButton.addEventListener('click', addRow);
	if (monthSelect) monthSelect.addEventListener('change', function () { window.location.href = this.value; });

	document.querySelectorAll('.mei-event-table th[data-sort]').forEach(function (heading) {
		let ascending = true;
		heading.tabIndex = 0;
		heading.setAttribute('role', 'button');
		function sort() {
			const key = heading.dataset.sort;
			const sorted = Array.from(rows.querySelectorAll('.mei-event-row')).sort(function (left, right) {
				let a = left.dataset[key] || '';
				let b = right.dataset[key] || '';
				if (key === 'cost') { a = Number(a); b = Number(b); }
				else { a = a.toLocaleLowerCase(meiAdmin.locale); b = b.toLocaleLowerCase(meiAdmin.locale); }
				return (a < b ? -1 : a > b ? 1 : 0) * (ascending ? 1 : -1);
			});
			sorted.forEach(function (row) { rows.appendChild(row); });
			ascending = !ascending;
		}
		heading.addEventListener('click', sort);
		heading.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); sort(); } });
	});

	if (modal) {
		document.getElementById('mei-content-apply').addEventListener('click', function () { closeContent(true); });
		document.getElementById('mei-content-cancel').addEventListener('click', function () { closeContent(false); });
		modal.querySelector('.mei-modal-backdrop').addEventListener('click', function () { closeContent(false); });
		document.addEventListener('keydown', function (event) { if (!modal.hidden && event.key === 'Escape') closeContent(false); });
	}

	if (form) {
		form.addEventListener('change', function () { dirty = true; });
		form.addEventListener('submit', function (event) {
			if (!validateRowMonths()) {
				event.preventDefault();
				return;
			}
			dirty = false;
		});
		window.addEventListener('beforeunload', function (event) {
			if (!dirty) return;
			event.preventDefault();
			event.returnValue = '';
		});
	}
}());
