(function () {
	'use strict';

	const rows = document.getElementById('mei-event-rows');
	const addButton = document.getElementById('mei-add-row');
	const template = document.getElementById('mei-row-template');
	const form = document.querySelector('.mei-events-form');
	const modal = document.getElementById('mei-content-modal');
	const focalModal = document.getElementById('mei-focal-modal');
	const focalPreview = document.getElementById('mei-focal-preview');
	const monthSelect = document.getElementById('mei-month-select');
	let activeContent = null;
	let activeFocalRow = null;
	let focalPoint = { x: 0.5, y: 0.5 };
	let setFocalPicker = null;
	let dirty = false;

	function updateBodyModalState() {
		const open = (modal && !modal.hidden) || (focalModal && !focalModal.hidden);
		document.body.classList.toggle('mei-modal-open', Boolean(open));
	}

	function updateFocalPreview(imageUrl, point) {
		if (!focalPreview) return;
		focalPreview.style.backgroundImage = imageUrl ? 'url("' + imageUrl.replace(/"/g, '%22') + '")' : '';
		focalPreview.style.backgroundPosition = Math.round(point.x * 100) + '% ' + Math.round(point.y * 100) + '%';
	}

	function mountFocalPicker() {
		const root = document.getElementById('mei-focal-picker-root');
		if (!root || !window.wp || !wp.element || !wp.components || !wp.components.FocalPointPicker) return;
		const createElement = wp.element.createElement;
		function Picker() {
			const imageState = wp.element.useState('');
			const pointState = wp.element.useState(focalPoint);
			setFocalPicker = function (imageUrl, point) {
				imageState[1](imageUrl);
				pointState[1](point);
			};
			return createElement(wp.components.FocalPointPicker, {
				url: imageState[0],
				value: pointState[0],
				onChange: function (point) {
					focalPoint = point;
					pointState[1](point);
					updateFocalPreview(imageState[0], point);
				},
				onDrag: function (point) {
					focalPoint = point;
					pointState[1](point);
					updateFocalPreview(imageState[0], point);
				}
			});
		}
		if (typeof wp.element.createRoot === 'function') wp.element.createRoot(root).render(createElement(Picker));
		else wp.element.render(createElement(Picker), root);
	}

	function openFocal(row) {
		const preview = row.querySelector('.mei-image-preview');
		const image = preview && preview.querySelector('img');
		const imageUrl = preview ? (preview.dataset.largeUrl || (image ? image.src : '')) : '';
		if (!imageUrl || !focalModal) return;
		const x = parseInt(row.querySelector('.mei-focal-x').value, 10);
		const y = parseInt(row.querySelector('.mei-focal-y').value, 10);
		focalPoint = {
			x: Number.isFinite(x) ? x / 100 : 0.5,
			y: Number.isFinite(y) ? y / 100 : 0.5
		};
		activeFocalRow = row;
		focalModal.dataset.imageUrl = imageUrl;
		focalModal.hidden = false;
		if (setFocalPicker) setFocalPicker(imageUrl, focalPoint);
		updateFocalPreview(imageUrl, focalPoint);
		updateBodyModalState();
	}

	function closeFocal(apply) {
		if (apply && activeFocalRow) {
			activeFocalRow.querySelector('.mei-focal-x').value = String(Math.round(focalPoint.x * 100));
			activeFocalRow.querySelector('.mei-focal-y').value = String(Math.round(focalPoint.y * 100));
			dirty = true;
		}
		activeFocalRow = null;
		focalModal.hidden = true;
		updateBodyModalState();
	}

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
		updateBodyModalState();
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
		updateBodyModalState();
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
			if (event.target.closest('.mei-edit-focal-point')) openFocal(row);
		});

		rows.addEventListener('change', function (event) {
			const row = event.target.closest('.mei-event-row');
			if (!row) return;
			if (event.target.matches('.mei-all-day')) toggleAllDay(row, event.target.checked);
			if (event.target.matches('input[type="file"]') && event.target.files && event.target.files[0]) {
				const preview = row.querySelector('.mei-image-preview');
				const image = document.createElement('img');
				const imageUrl = URL.createObjectURL(event.target.files[0]);
				image.src = imageUrl;
				image.alt = '';
				preview.replaceChildren(image);
				preview.dataset.largeUrl = imageUrl;
				row.querySelector('.mei-focal-x').value = '50';
				row.querySelector('.mei-focal-y').value = '50';
				row.querySelector('.mei-edit-focal-point').disabled = false;
				row.querySelector('.mei-remove-image').checked = false;
			}
			if (event.target.matches('.mei-remove-image')) {
				const button = row.querySelector('.mei-edit-focal-point');
				const xField = row.querySelector('.mei-focal-x');
				const yField = row.querySelector('.mei-focal-y');
				if (event.target.checked) {
					row.dataset.previousFocalX = xField.value;
					row.dataset.previousFocalY = yField.value;
					xField.value = '';
					yField.value = '';
					button.disabled = true;
				} else {
					xField.value = row.dataset.previousFocalX || '';
					yField.value = row.dataset.previousFocalY || '';
					button.disabled = !row.querySelector('.mei-image-preview img');
				}
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
	mountFocalPicker();

	const sortHeadings = Array.from(document.querySelectorAll('.mei-event-table th[data-sort]'));

	function setSortState(activeHeading, direction) {
		sortHeadings.forEach(function (heading) {
			const active = heading === activeHeading;
			const button = heading.querySelector('.mei-sort-button');
			const indicator = heading.querySelector('.mei-sort-indicator');
			const status = heading.querySelector('.mei-sort-status');
			heading.setAttribute('aria-sort', active ? direction : 'none');
			if (indicator) indicator.textContent = active ? (direction === 'ascending' ? '▲' : '▼') : '↕';
			if (status) {
				status.textContent = active
					? (direction === 'ascending' ? meiAdmin.sortedAscending + ' ' + meiAdmin.sortDescending : meiAdmin.sortedDescending + ' ' + meiAdmin.sortAscending)
					: meiAdmin.sortAscending;
			}
			if (button) button.title = active && direction === 'ascending' ? meiAdmin.sortDescending : meiAdmin.sortAscending;
		});
	}

	sortHeadings.forEach(function (heading) {
		const button = heading.querySelector('.mei-sort-button');
		if (!button) return;
		function sort() {
			const key = heading.dataset.sort;
			const ascending = heading.getAttribute('aria-sort') !== 'ascending';
			const sorted = Array.from(rows.querySelectorAll('.mei-event-row')).sort(function (left, right) {
				let a = left.dataset[key] || '';
				let b = right.dataset[key] || '';
				if (key === 'cost') { a = Number(a); b = Number(b); }
				else { a = a.toLocaleLowerCase(meiAdmin.locale); b = b.toLocaleLowerCase(meiAdmin.locale); }
				return (a < b ? -1 : a > b ? 1 : 0) * (ascending ? 1 : -1);
			});
			sorted.forEach(function (row) { rows.appendChild(row); });
			setSortState(heading, ascending ? 'ascending' : 'descending');
		}
		button.addEventListener('click', sort);
	});

	if (modal) {
		document.getElementById('mei-content-apply').addEventListener('click', function () { closeContent(true); });
		document.getElementById('mei-content-cancel').addEventListener('click', function () { closeContent(false); });
		modal.querySelector('.mei-modal-backdrop').addEventListener('click', function () { closeContent(false); });
		document.addEventListener('keydown', function (event) { if (!modal.hidden && event.key === 'Escape') closeContent(false); });
	}

	if (focalModal) {
		document.getElementById('mei-focal-apply').addEventListener('click', function () { closeFocal(true); });
		document.getElementById('mei-focal-cancel').addEventListener('click', function () { closeFocal(false); });
		document.getElementById('mei-focal-reset').addEventListener('click', function () {
			focalPoint = { x: 0.5, y: 0.5 };
			if (setFocalPicker) setFocalPicker(focalModal.dataset.imageUrl || '', focalPoint);
			updateFocalPreview(focalModal.dataset.imageUrl || '', focalPoint);
		});
		focalModal.querySelector('.mei-modal-backdrop').addEventListener('click', function () { closeFocal(false); });
		document.addEventListener('keydown', function (event) { if (!focalModal.hidden && event.key === 'Escape') closeFocal(false); });
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
