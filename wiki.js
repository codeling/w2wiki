/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Script of the wiki's pages: the keyboard shortcuts of every page, the navigation drawer (which can be dragged) and the
 * editor (local drafts, warning about unsaved changes, Ctrl+S and Ctrl+Enter to save, keys for bold, italic and links,
 * indenting lines with Tab, inserting text files, preview).
 * Every page works without it: the shortcuts only follow the links and use the fields which the page has.
 */

function toggleDrawer()
{
	document.getElementById('drawer').classList.toggle('inactive');
}

// The button in the toolbar to change the color scheme: the change is applied at once and saved in the background,
// so that the text in the editor stays as it is (the form alone, without this script, loads the page again)
document.addEventListener('submit', function (event)
{
	const form = event.target;
	if (!form.classList || !form.classList.contains('themeswitch') || !event.submitter)
	{
		return;
	}
	event.preventDefault();
	const mode = event.submitter.value;
	const data = new URLSearchParams();
	for (const field of form.querySelectorAll('input[name]'))
	{
		data.append(field.name, field.value);
	}
	data.append('mode', mode);
	fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin', redirect: 'manual'}).then(function (response)
	{
		if (!response.ok && response.type !== 'opaqueredirect')
		{
			return;
		}
		if (mode === 'auto')
		{
			delete document.documentElement.dataset.theme;
		}
		else
		{
			document.documentElement.dataset.theme = mode;
		}
		// (the icon follows the root element; the button now chooses the scheme after this one)
		event.submitter.value = {auto: 'light', light: 'dark', dark: 'auto'}[mode];
	}, function () { /* no connection: the choice stays as it was */ });
});

// based on https://www.w3schools.com/howto/howto_js_draggable.asp
function makeElementDraggable(elmnt)
{
	var pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
	elmnt.onmousedown = dragMouseDown;

	function dragMouseDown(e)
	{
		e = e || window.event;
		e.preventDefault();
		pos3 = e.clientX;
		pos4 = e.clientY;
		document.onmouseup = closeDragElement;
		document.onmousemove = elementDrag;
	}

	function elementDrag(e)
	{
		e = e || window.event;
		e.preventDefault();
		pos1 = pos3 - e.clientX;
		pos2 = pos4 - e.clientY;
		pos3 = e.clientX;
		pos4 = e.clientY;
		elmnt.style.top = (elmnt.offsetTop - pos2) + "px";
		elmnt.style.left = (elmnt.offsetLeft - pos1) + "px";
	}

	function closeDragElement()
	{
		document.onmouseup = null;
		document.onmousemove = null;
	}
}
function readDraft(key)
{
	try
	{
		let json = localStorage.getItem(key);
		return json ? JSON.parse(json) : null;
	}
	catch (e)
	{
		return null;
	}
}

function writeDraft(key, draft)
{
	try
	{
		localStorage.setItem(key, JSON.stringify(draft));
	}
	catch (e)
	{
		// storage unavailable or full - editing still works, just without draft
	}
}

function removeDraft(key)
{
	try
	{
		localStorage.removeItem(key);
	}
	catch (e)
	{
	}
}

/** Sends a file to the wiki, with the progress (a fraction between 0 and 1) reported while the request is sent */
function sendUpload(url, formData, onProgress)
{
	return new Promise((resolve, reject) =>
	{
		let request = new XMLHttpRequest();
		request.open('POST', url);
		request.upload.addEventListener('progress', (event) =>
		{
			if (event.lengthComputable)
			{
				onProgress(event.loaded / event.total);
			}
		});
		request.addEventListener('load', () =>
		{
			try
			{
				resolve(JSON.parse(request.responseText));
			}
			catch (e)
			{
				// (e.g. the login page: the session has expired)
				resolve(null);
			}
		});
		request.addEventListener('error', () => reject(new Error('network')));
		request.send(formData);
	});
}

/**
 * Replaces the selection of the text area with the text, as if it was typed: it can be undone, and counts as a change
 */
function insertTyped(textArea, text)
{
	textArea.focus();
	// (a text area which is not shown, like behind the preview on narrow screens, can't get the focus: the text would go
	// into the element which has it; no text removes the selection)
	if (document.activeElement !== textArea || !document.execCommand
		|| !(text === '' ? document.execCommand('delete') : document.execCommand('insertText', false, text)))
	{
		textArea.setRangeText(text, textArea.selectionStart, textArea.selectionEnd, 'end');
		textArea.dispatchEvent(new Event('input', { bubbles: true }));
	}
}

/**
 * The buttons of the pages which link to the new page or mention it: they insert a link to that page at the cursor.
 */
function setupBacklinks(textArea)
{
	for (let button of document.querySelectorAll('button.backlink'))
	{
		button.addEventListener('click', () => insertTyped(textArea, '[[' + button.dataset.page + ']]'));
	}
}

const IMPORT_MAX_BYTES = 2 * 1024 * 1024;

/** Whether the file is one of the text files which can be inserted into the text of the editor */
function isTextFile(file)
{
	return /\.(md|markdown|txt)$/i.test(file.name);
}

/**
 * Inserting Markdown and text files into the text of the editor, at the cursor: chosen with the button below the text,
 * or dropped on the text. A new page without a title gets the name of the (first) file. The text is then saved like
 * typed text, so nothing is stored before the page is saved.
 */
function setupImport(form, textArea, titleInput)
{
	let button = document.getElementById('importbutton');
	let fileInput = document.getElementById('importfile');

	async function insertFiles(files)
	{
		let texts = [];
		let names = [];
		for (let file of files)
		{
			if (!isTextFile(file))
			{
				window.alert(form.dataset.msgImportNotText.replace('%s', () => file.name));
				continue;
			}
			if (file.size > IMPORT_MAX_BYTES)
			{
				window.alert(form.dataset.msgImportTooLarge.replace('%s', () => file.name));
				continue;
			}
			let text = await file.text();
			// (a NUL character is not in text files: e.g. an image renamed to .txt)
			if (text.includes('\u0000'))
			{
				window.alert(form.dataset.msgImportNotText.replace('%s', () => file.name));
				continue;
			}
			texts.push(text.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n'));
			names.push(file.name);
		}
		if (texts.length === 0)
		{
			return;
		}
		insertTyped(textArea, texts.join('\n\n'));
		if (titleInput && titleInput.value.trim() === '')
		{
			titleInput.value = names[0].replace(/\.(md|markdown|txt)$/i, '');
			titleInput.dispatchEvent(new Event('input', { bubbles: true }));
		}
	}

	if (button && fileInput)
	{
		button.addEventListener('click', () => fileInput.click());
		fileInput.addEventListener('change', () =>
		{
			let files = Array.from(fileInput.files);
			fileInput.value = '';
			insertFiles(files);
		});
	}
	let hasFiles = (event) => event.dataTransfer && Array.from(event.dataTransfer.types || []).includes('Files');
	// (also without uploads, when there is no other handler for dropped files)
	textArea.addEventListener('dragover', (event) =>
	{
		if (hasFiles(event))
		{
			event.preventDefault();
		}
	});
	textArea.addEventListener('drop', (event) =>
	{
		let files = hasFiles(event) ? Array.from(event.dataTransfer.files).filter(isTextFile) : [];
		if (files.length > 0)
		{
			event.preventDefault();
			insertFiles(files);
		}
	});
}

const INDENT = '    ';

/**
 * Indents (by INDENT) or outdents (by up to INDENT, or one tab) the lines of the text which the selection from start to
 * end touches; empty lines are left as they are. Returns the part of the text to replace (start, end), its replacement,
 * and the selection afterwards (selectionStart, selectionEnd); or null if no line changes.
 */
function indentLines(text, start, end, outdent)
{
	let blockStart = text.lastIndexOf('\n', start - 1) + 1;
	// (a selection of whole lines ends after the line break of the last one: the next line is not part of it)
	let lastPosition = (end > start && text[end - 1] === '\n') ? end - 1 : end;
	let blockEnd = text.indexOf('\n', lastPosition);
	if (blockEnd < 0)
	{
		blockEnd = text.length;
	}
	let lines = text.slice(blockStart, blockEnd).split('\n');
	let changes = lines.map((line) =>
	{
		if (outdent)
		{
			let match = /^(\t| {1,4})/.exec(line);
			return match ? -match[1].length : 0;
		}
		return line === '' ? 0 : INDENT.length;
	});
	if (changes.every((change) => change === 0))
	{
		return null;
	}
	let replacement = lines.map((line, i) => changes[i] > 0 ? INDENT + line : line.slice(-changes[i])).join('\n');
	// where a position of the old text is in the new one
	function moved(position, isStart)
	{
		let lineStart = blockStart;
		let shift = 0;
		for (let i = 0; i < lines.length; i++)
		{
			let lineEnd = lineStart + lines[i].length;
			if (position <= lineEnd)
			{
				let column = position - lineStart;
				// (a selection which starts at the beginning of a line keeps the whole line selected)
				if (isStart && column === 0 && changes[i] > 0)
				{
					return lineStart + shift;
				}
				return lineStart + shift + Math.max(0, column + changes[i]);
			}
			shift += changes[i];
			lineStart = lineEnd + 1;
		}
		return position + shift;
	}
	return { start: blockStart, end: blockEnd, replacement: replacement,
		selectionStart: moved(start, start !== end), selectionEnd: moved(end, false) };
}

/**
 * Tab in the text indents the lines of the selection (or inserts INDENT at the cursor), Shift+Tab outdents them.
 * So that the text can still be left with the keyboard, Tab right after Escape moves the focus as usual.
 */
function setupIndent(textArea)
{
	let leaveOnTab = false;
	textArea.addEventListener('blur', () => { leaveOnTab = false; });
	textArea.addEventListener('keydown', (event) =>
	{
		if (event.key === 'Escape')
		{
			leaveOnTab = true;
			return;
		}
		// (Shift of Shift+Tab is pressed before Tab)
		if (['Shift', 'Control', 'Alt', 'Meta', 'AltGraph', 'CapsLock'].includes(event.key))
		{
			return;
		}
		if (event.key !== 'Tab' || event.ctrlKey || event.altKey || event.metaKey || event.isComposing)
		{
			leaveOnTab = false;
			return;
		}
		if (leaveOnTab)
		{
			leaveOnTab = false;
			return;
		}
		event.preventDefault();
		let start = textArea.selectionStart;
		let end = textArea.selectionEnd;
		if (!event.shiftKey && start === end)
		{
			insertTyped(textArea, INDENT);
			return;
		}
		let change = indentLines(textArea.value, start, end, event.shiftKey);
		if (change)
		{
			textArea.setSelectionRange(change.start, change.end);
			insertTyped(textArea, change.replacement);
			textArea.setSelectionRange(change.selectionStart, change.selectionEnd);
		}
	});
}

/**
 * Uploading files, in the editor and on the upload page: the button (of the symbol bar of the editor, or of the upload
 * page) opens a dialog to choose files. Images can be cropped in the dialog (the server does it, with the part to keep
 * sent as fractions of the image) and shrunk, which is shown as the size the image will have, and a file can be
 * stored under another name.
 * In the editor (with a text area), files dropped on the text and images pasted into it open the dialog with the
 * files already chosen, and the link to each file is inserted at the cursor; the text is not touched otherwise.
 * On the upload page (no text area), the page is loaded again to show the new files in its list.
 */
function setupUpload(dialog, textArea)
{
	let openButton = document.getElementById('uploadbutton');
	if (!openButton || !dialog || !dialog.showModal)
	{
		return;
	}
	let fileInput = document.getElementById('uploadfile');
	let nameLabel = document.getElementById('uploadname');
	let newNameInput = document.getElementById('uploadnewname');
	let countLabel = document.getElementById('uploadcount');
	let status = document.getElementById('uploadstatus');
	let startButton = document.getElementById('uploadstart');
	let cancelButton = document.getElementById('uploadcancel');
	let resizeInput = document.getElementById('uploadresize');
	let maxSizeInput = document.getElementById('uploadmaxsize');
	let resultSize = document.getElementById('resultsize');
	let cropSection = document.getElementById('cropsection');
	let cropStage = document.getElementById('cropstage');
	let cropImage = document.getElementById('cropimage');
	// (the size of the image in pixels; the canvas of the preview shows it smaller)
	let imageSize = { width: 0, height: 0 };
	const PREVIEW_PIXELS = 1200;
	let cropBox = document.getElementById('cropbox');
	let cropReset = document.getElementById('cropreset');
	// (without image processing on the server, the dialog has no options for images)
	let hasImageOptions = !!cropSection;
	const MIN_CROP = 0.02;
	const WHOLE = { x: 0, y: 0, w: 1, h: 1 };
	// (the formats which browsers show, so that the part to keep can be chosen)
	const PREVIEW_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
	let queue = [];
	let total = 0;
	let current = null;
	let links = [];
	let busy = false;
	let crop = Object.assign({}, WHOLE);

	function show(text, isError)
	{
		status.textContent = text;
		status.className = isError ? 'error' : '';
	}

	function isWhole()
	{
		return crop.x < 0.0005 && crop.y < 0.0005 && crop.w > 0.9995 && crop.h > 0.9995;
	}

	function updateCropBox()
	{
		if (!hasImageOptions)
		{
			return;
		}
		cropBox.style.left = (crop.x * 100) + '%';
		cropBox.style.top = (crop.y * 100) + '%';
		cropBox.style.width = (crop.w * 100) + '%';
		cropBox.style.height = (crop.h * 100) + '%';
		updateResultSize();
	}

	/** The size the image will have after cropping and shrinking (as the server does it) */
	function updateResultSize()
	{
		if (!hasImageOptions)
		{
			return;
		}
		if (cropSection.hidden || !imageSize.width)
		{
			resultSize.textContent = '';
			return;
		}
		let width = Math.max(1, Math.round(crop.w * imageSize.width));
		let height = Math.max(1, Math.round(crop.h * imageSize.height));
		let maxSize = Math.max(20, Math.min(8192, parseInt(maxSizeInput.value, 10) || 1200));
		if (resizeInput.checked && Math.max(width, height) > maxSize)
		{
			let scale = maxSize / Math.max(width, height);
			width = Math.round(width * scale);
			height = Math.round(height * scale);
		}
		resultSize.textContent = dialog.dataset.msgResultSize.replace('%s', () => width + ' × ' + height);
	}

	function setCrop(rect)
	{
		crop = rect;
		updateCropBox();
	}

	/** The new part to keep when a border (or the whole frame, for "move") is dragged by dx and dy (fractions of the image) */
	function dragged(mode, start, dx, dy)
	{
		let left = start.x, top = start.y, right = start.x + start.w, bottom = start.y + start.h;
		let clamp = (value, low, high) => Math.max(low, Math.min(high, value));
		if (mode === 'move')
		{
			left = clamp(start.x + dx, 0, 1 - start.w);
			top = clamp(start.y + dy, 0, 1 - start.h);
			right = left + start.w;
			bottom = top + start.h;
		}
		else
		{
			if (mode.includes('w')) { left = clamp(left + dx, 0, right - MIN_CROP); }
			if (mode.includes('e')) { right = clamp(right + dx, left + MIN_CROP, 1); }
			if (mode.includes('n')) { top = clamp(top + dy, 0, bottom - MIN_CROP); }
			if (mode.includes('s')) { bottom = clamp(bottom + dy, top + MIN_CROP, 1); }
		}
		return { x: left, y: top, w: right - left, h: bottom - top };
	}

	if (hasImageOptions)
	{
		cropBox.addEventListener('pointerdown', (event) =>
		{
			if (busy)
			{
				return;
			}
			event.preventDefault();
			cropBox.focus();
			let mode = event.target.dataset.handle || 'move';
			let start = Object.assign({}, crop);
			let bounds = cropStage.getBoundingClientRect();
			let x0 = event.clientX, y0 = event.clientY;
			cropBox.setPointerCapture(event.pointerId);
			let move = (e) => setCrop(dragged(mode, start, (e.clientX - x0) / bounds.width, (e.clientY - y0) / bounds.height));
			let end = () =>
			{
				cropBox.removeEventListener('pointermove', move);
				cropBox.removeEventListener('pointerup', end);
				cropBox.removeEventListener('pointercancel', end);
			};
			cropBox.addEventListener('pointermove', move);
			cropBox.addEventListener('pointerup', end);
			cropBox.addEventListener('pointercancel', end);
		});
		cropBox.addEventListener('keydown', (event) =>
		{
			let step = 0.01;
			let moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
			if (!moves[event.key])
			{
				return;
			}
			event.preventDefault();
			// (Shift changes the size: the right and bottom borders move)
			setCrop(dragged(event.shiftKey ? 'se' : 'move', crop, moves[event.key][0], moves[event.key][1]));
		});
		cropReset.addEventListener('click', () => setCrop(Object.assign({}, WHOLE)));
		resizeInput.addEventListener('change', updateResultSize);
		maxSizeInput.addEventListener('input', updateResultSize);
	}

	function showPreview(file)
	{
		if (!hasImageOptions)
		{
			return;
		}
		cropSection.hidden = true;
		imageSize = { width: 0, height: 0 };
		resultSize.textContent = '';
		crop = Object.assign({}, WHOLE);
		if (!PREVIEW_TYPES.includes(file.type) || !window.createImageBitmap)
		{
			return;
		}
		// (decoded from the file itself, and drawn: not loaded from a data: or blob: address, which a
		// Content-Security-Policy without img-src for them, e.g. one added by the web server, would block)
		createImageBitmap(file, { imageOrientation: 'from-image' }).then((bitmap) =>
		{
			if (file === current)
			{
				let scale = Math.min(1, PREVIEW_PIXELS / Math.max(bitmap.width, bitmap.height));
				cropImage.width = Math.max(1, Math.round(bitmap.width * scale));
				cropImage.height = Math.max(1, Math.round(bitmap.height * scale));
				cropImage.getContext('2d').drawImage(bitmap, 0, 0, cropImage.width, cropImage.height);
				imageSize = { width: bitmap.width, height: bitmap.height };
				cropSection.hidden = false;
				setCrop(Object.assign({}, WHOLE));
			}
			bitmap.close();
		}).catch(() =>
		{
			// (a file which the browser can't show, e.g. a damaged one: it is uploaded as it is)
		});
	}

	function next()
	{
		current = queue.shift() || null;
		show('', false);
		nameLabel.textContent = current ? current.name : '';
		countLabel.textContent = (current && total > 1)
			? dialog.dataset.msgCount.replace('%1$s', total - queue.length).replace('%2$s', total) : '';
		if (current)
		{
			showPreview(current);
		}
		else
		{
			// (all files are done, or were skipped)
			dialog.close();
		}
	}

	function chooseFiles(files)
	{
		if (busy || files.length === 0)
		{
			return;
		}
		queue = files;
		total = files.length;
		if (!dialog.open)
		{
			dialog.showModal();
		}
		next();
	}

	/** The name the file is stored under: the name chosen in the dialog (which keeps the extension of the file), or its own */
	function targetName(file)
	{
		let chosen = newNameInput.value.trim().replace(/ /g, '_');
		let extension = /\.([^.]+)$/.exec(file.name);
		if (chosen === '')
		{
			return file.name;
		}
		return (extension && !chosen.toLowerCase().endsWith('.' + extension[1].toLowerCase())) ? chosen + '.' + extension[1] : chosen;
	}

	/** Sends the chosen file; returns the answer of the server, or null when the file already exists and is not replaced */
	async function uploadOne(file)
	{
		let overwrite = false;
		let check = await fetch(dialog.dataset.checkUrl + '&filename=' + encodeURIComponent(file.name) + '&newname=' + encodeURIComponent(newNameInput.value),
			{ credentials: 'same-origin' });
		if (await check.json())
		{
			if (!window.confirm(dialog.dataset.msgOverwrite.replace('%s', () => targetName(file))))
			{
				show('', false);
				return null;
			}
			overwrite = true;
		}
		let data = new FormData();
		data.append('csrf_token', dialog.dataset.token);
		data.append('overwrite', overwrite ? 'true' : '');
		data.append('newname', newNameInput.value);
		if (hasImageOptions)
		{
			data.append('resize', resizeInput.checked ? 'true' : '');
			data.append('maxsize', maxSizeInput.value);
		}
		if (hasImageOptions && !cropSection.hidden && !isWhole())
		{
			data.append('crop', [crop.x, crop.y, crop.w, crop.h].map((value) => value.toFixed(5)).join(','));
		}
		data.append('userfile', file, file.name);
		let label = dialog.dataset.msgUploading.replace('%s', () => file.name);
		show(label, false);
		let answer = await sendUpload(dialog.dataset.uploadUrl, data, (fraction) =>
		{
			show(label + ' (' + Math.round(fraction * 100) + ' %)', false);
		});
		if (!answer)
		{
			throw new Error(dialog.dataset.msgBadAnswer);
		}
		if (!answer.success)
		{
			throw new Error(answer.message || dialog.dataset.msgError);
		}
		return answer;
	}

	startButton.addEventListener('click', async () =>
	{
		if (busy)
		{
			return;
		}
		if (!current)
		{
			fileInput.click();
			return;
		}
		busy = true;
		startButton.disabled = cancelButton.disabled = true;
		try
		{
			let answer = await uploadOne(current);
			if (answer)
			{
				links.push(answer.link);
			}
			busy = false;
			// (the name was for this file: the next one keeps its own)
			newNameInput.value = '';
			next();
		}
		catch (e)
		{
			// (the file stays in the dialog: the error can be fixed, e.g. by another size, or the file is cancelled)
			busy = false;
			show(e.message === 'network' ? dialog.dataset.msgError : e.message, true);
		}
		startButton.disabled = cancelButton.disabled = false;
	});
	cancelButton.addEventListener('click', () => dialog.close());
	dialog.addEventListener('cancel', (event) =>
	{
		// (Escape does not close the dialog while a file is sent)
		if (busy)
		{
			event.preventDefault();
		}
	});
	// (however the dialog is closed: the files which were stored are linked)
	dialog.addEventListener('close', () =>
	{
		queue = [];
		current = null;
		let uploaded = links;
		links = [];
		showPreview({ type: '' });
		if (uploaded.length > 0)
		{
			if (textArea)
			{
				insertTyped(textArea, uploaded.join('\n'));
			}
			else
			{
				window.location.reload();
			}
		}
	});

	openButton.addEventListener('click', (event) =>
	{
		event.preventDefault();
		if (!dialog.open)
		{
			queue = [];
			total = 0;
			current = null;
			links = [];
			nameLabel.textContent = countLabel.textContent = '';
			newNameInput.value = '';
			show('', false);
			dialog.showModal();
		}
	});
	fileInput.addEventListener('change', () =>
	{
		chooseFiles(Array.from(fileInput.files));
		fileInput.value = '';
	});

	// (dropping files and pasting images need the text)
	if (textArea)
	{
		let box = textArea.parentNode;
		let hasFiles = (event) => event.dataTransfer && Array.from(event.dataTransfer.types || []).includes('Files');
		textArea.addEventListener('dragover', (event) =>
		{
			if (hasFiles(event))
			{
				event.preventDefault();
				box.classList.add('dropping');
			}
		});
		textArea.addEventListener('dragleave', () => box.classList.remove('dropping'));
		textArea.addEventListener('drop', (event) =>
		{
			box.classList.remove('dropping');
			// (Markdown and text files are inserted into the text instead, see setupImport())
			let files = hasFiles(event) ? Array.from(event.dataTransfer.files).filter((file) => !isTextFile(file)) : [];
			if (files.length > 0)
			{
				event.preventDefault();
				chooseFiles(files);
			}
		});
		textArea.addEventListener('paste', (event) =>
		{
			let clipboard = event.clipboardData;
			// (only an image without text: copied cells or formatted text come with a picture of themselves)
			if (!clipboard || clipboard.files.length === 0 || Array.from(clipboard.types).includes('text/plain'))
			{
				return;
			}
			event.preventDefault();
			let stamp = new Date().toISOString().replace(/\D/g, '').slice(0, 14);
			chooseFiles(Array.from(clipboard.files).map((file, i) =>
			{
				// (a pasted image is always called "image.png")
				let match = /^image\.(\w+)$/.exec(file.name);
				return match ? new File([file], 'image-' + stamp + (i > 0 ? '-' + (i + 1) : '') + '.' + match[1], { type: file.type }) : file;
			}));
		});
	}
}

const PREVIEW_DELAY = 400;
const PREVIEW_STORAGE_KEY = 'w2wiki-preview';

function readPreviewChoice()
{
	try
	{
		return localStorage.getItem(PREVIEW_STORAGE_KEY) === 'shown';
	}
	catch (e)
	{
		return false;
	}
}

function writePreviewChoice(shown)
{
	try
	{
		localStorage.setItem(PREVIEW_STORAGE_KEY, shown ? 'shown' : 'hidden');
	}
	catch (e)
	{
		// (without storage, the preview starts hidden every time)
	}
}

/**
 * The preview of the text: the button below the text shows it next to the text (on wide screens) or instead of it (on
 * narrow ones, see index.css), and the choice is remembered in the browser. The server renders the text as it renders
 * the page (api.php?task=preview), so that links, embeds, anchors and uploads look the same; a while after the last
 * change of the text it is sent again (a request which is still running is cancelled). Errors, like a session which has
 * expired, are shown in the preview; the text is not touched.
 * The HTML comes from the renderer of the pages, which escapes the text: it is safe to insert (and the
 * Content-Security-Policy would not run scripts in it anyway). The ids of its anchors are moved to data-anchor, so that
 * they can't be mistaken for the elements of the editor; links within the text scroll the preview, others open in a new
 * tab, so that the editor stays as it is.
 * Returns an object whose update() renders the text again, for changes of the text without an input event.
 */
function setupPreview(form, textArea)
{
	let pane = document.getElementById('preview');
	let button = document.getElementById('previewbutton');
	if (!pane || !button || !window.fetch || !window.AbortController)
	{
		return { update() {} };
	}
	let box = textArea.parentNode;
	let status = pane.querySelector('.previewstatus');
	let content = pane.querySelector('.previewcontent');
	let shown = false;
	let timer = null;
	let request = null;
	// (the text which the preview shows, or null)
	let renderedText = null;

	function showError(message)
	{
		status.textContent = '';
		if (message)
		{
			let label = document.createElement('strong');
			label.textContent = pane.dataset.msgUnavailable;
			status.append(label, ' ', message);
		}
	}

	/** Makes the links of the rendered text work in the preview, see above */
	function prepare()
	{
		for (let element of content.querySelectorAll('[id]'))
		{
			element.dataset.anchor = element.id;
			element.removeAttribute('id');
		}
		for (let link of content.querySelectorAll('a[href]'))
		{
			if (!link.getAttribute('href').startsWith('#'))
			{
				link.target = '_blank';
				link.rel = 'noopener';
			}
		}
	}

	content.addEventListener('click', (event) =>
	{
		let link = event.target.closest('a[href]');
		if (!link || !link.getAttribute('href').startsWith('#'))
		{
			return;
		}
		event.preventDefault();
		let name;
		try
		{
			name = decodeURIComponent(link.getAttribute('href').slice(1));
		}
		catch (e)
		{
			name = link.getAttribute('href').slice(1);
		}
		let target = Array.from(content.querySelectorAll('[data-anchor]')).find((element) => element.dataset.anchor === name);
		if (target)
		{
			target.scrollIntoView({ block: 'start' });
		}
	});

	async function render()
	{
		clearTimeout(timer);
		timer = null;
		if (request)
		{
			request.abort();
		}
		let current = request = new AbortController();
		let text = textArea.value;
		let data = new URLSearchParams();
		data.append('csrf_token', form.elements.csrf_token.value);
		data.append('newText', text);
		pane.setAttribute('aria-busy', 'true');
		try
		{
			let response = await fetch(pane.dataset.url, { method: 'POST', body: data, credentials: 'same-origin', signal: current.signal });
			let answer = null;
			try
			{
				answer = await response.json();
			}
			catch (e)
			{
				if (e.name === 'AbortError')
				{
					throw e;
				}
				// (not JSON: e.g. the page of a web server which refused the request)
			}
			if (response.ok && answer && typeof answer.html === 'string')
			{
				content.innerHTML = answer.html;
				prepare();
				renderedText = text;
				showError('');
			}
			else
			{
				showError(answer && typeof answer.message === 'string' && answer.message ? answer.message : pane.dataset.msgError);
			}
		}
		catch (e)
		{
			if (e.name === 'AbortError')
			{
				return;
			}
			showError(pane.dataset.msgOffline);
		}
		finally
		{
			if (request === current)
			{
				request = null;
				pane.removeAttribute('aria-busy');
			}
		}
	}

	function schedule()
	{
		if (!shown)
		{
			return;
		}
		clearTimeout(timer);
		timer = setTimeout(render, PREVIEW_DELAY);
	}

	function show(value)
	{
		shown = value;
		pane.hidden = !shown;
		box.classList.toggle('previewing', shown);
		button.setAttribute('aria-pressed', shown ? 'true' : 'false');
		if (shown)
		{
			if (renderedText !== textArea.value)
			{
				render();
			}
		}
		else
		{
			clearTimeout(timer);
			timer = null;
			if (request)
			{
				request.abort();
				request = null;
				pane.removeAttribute('aria-busy');
			}
		}
	}

	button.hidden = false;
	button.addEventListener('click', () =>
	{
		show(!shown);
		writePreviewChoice(shown);
	});
	// (also the changes made by the editor itself, like inserted links of uploads, which are input events too)
	textArea.addEventListener('input', schedule);
	// (roughly the same part of the text in both, while they are side by side)
	textArea.addEventListener('scroll', () =>
	{
		let range = textArea.scrollHeight - textArea.clientHeight;
		if (shown && range > 0)
		{
			pane.scrollTop = textArea.scrollTop / range * (pane.scrollHeight - pane.clientHeight);
		}
	});
	show(readPreviewChoice());
	return {
		update()
		{
			if (shown)
			{
				render();
			}
			else
			{
				renderedText = null;
			}
		}
	};
}

// (the systems of Apple use Cmd for the shortcuts of programs; Ctrl with a letter moves the cursor in their text fields)
const APPLE = /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent || '');

/**
 * The letter of the key (lower case), also with a keyboard layout without Latin letters, where the shortcuts are on the
 * key of the letter (as in the browser's own); or '' for other keys. Alt with another letter types a character of its own
 * (AltGr on Windows is Ctrl+Alt): then only Cmd+Option on a Mac counts as the key of the letter.
 */
function letterOf(event)
{
	if (/^[a-z]$/i.test(event.key))
	{
		return event.key.toLowerCase();
	}
	let code = /^Key([A-Z])$/.exec(event.code || '');
	return (code && event.key.length === 1 && (!event.altKey || event.metaKey)) ? code[1].toLowerCase() : '';
}

/**
 * Whether the event has the modifier of the shortcuts of programs: Cmd on a Mac, Ctrl elsewhere (or the Meta key, which
 * the systems usually keep for themselves), and Alt (Option) if alt, but not Shift.
 */
function isCommand(event, alt)
{
	if (event.shiftKey || event.altKey !== alt)
	{
		return false;
	}
	return event.metaKey ? !event.ctrlKey : (event.ctrlKey && !APPLE);
}

/** Skips the spaces at the start and the end of the selection from start to end of the text: returns [start, end] */
function trimSelection(text, start, end)
{
	while (start < end && /\s/.test(text[start]))
	{
		start++;
	}
	while (end > start && /\s/.test(text[end - 1]))
	{
		end--;
	}
	return [start, end];
}

/**
 * Bold (marker "**") or italic ("*") for the selection from start to end of the text: removes the markers if the text is
 * marked already (the markers are around the selection or at both ends of it; "***" is both), else puts them around it
 * and keeps the text selected (spaces at its ends stay outside, as Markdown doesn't mark text which starts or ends with
 * one). Without a selection, the two markers are inserted with the cursor between them (and removed again by the next
 * press). Returns the change like indentLines(): the part to replace (start, end), its replacement, and the selection
 * afterwards (selectionStart, selectionEnd).
 */
function toggleEmphasis(text, start, end, marker)
{
	let n = marker.length;
	let marks = (stars) => (n === 2) ? stars >= 2 : (stars === 1 || stars >= 3);
	[start, end] = trimSelection(text, start, end);
	let selected = text.slice(start, end);
	let before = 0;
	while (before < start && text[start - 1 - before] === '*')
	{
		before++;
	}
	let after = 0;
	while (end + after < text.length && text[end + after] === '*')
	{
		after++;
	}
	if (before >= n && after >= n && marks(Math.min(before, after)))
	{
		return { start: start - n, end: end + n, replacement: selected, selectionStart: start - n, selectionEnd: end - n };
	}
	let leading = /^\**/.exec(selected)[0].length;
	let trailing = /\**$/.exec(selected.slice(leading))[0].length;
	if (selected.length > 2 * n && leading >= n && trailing >= n && marks(Math.min(leading, trailing)))
	{
		let inner = selected.slice(n, -n);
		return { start: start, end: end, replacement: inner, selectionStart: start, selectionEnd: start + inner.length };
	}
	return { start: start, end: end, replacement: marker + selected + marker, selectionStart: start + n, selectionEnd: end + n };
}

/**
 * A link for the selection from start to end of the text: [selection](url) with "url" selected, to be replaced by the
 * address; a selected address becomes [](address), with the cursor where the text of the link goes. Without a
 * selection, [[]] with the cursor inside, for a link to a page of the wiki. Returns the change like toggleEmphasis().
 */
function linkSelection(text, start, end)
{
	[start, end] = trimSelection(text, start, end);
	let selected = text.slice(start, end);
	if (selected === '')
	{
		return { start: start, end: end, replacement: '[[]]', selectionStart: start + 2, selectionEnd: start + 2 };
	}
	if (/^(https?:\/\/|mailto:)\S+$/i.test(selected))
	{
		return { start: start, end: end, replacement: '[](' + selected + ')', selectionStart: start + 1, selectionEnd: start + 1 };
	}
	let url = start + selected.length + 3;
	return { start: start, end: end, replacement: '[' + selected + '](url)', selectionStart: url, selectionEnd: url + 3 };
}

/** Makes the change (see toggleEmphasis()) in the text area as if it was typed: it can be undone, and counts as input */
function applyChange(textArea, change)
{
	textArea.setSelectionRange(change.start, change.end);
	insertTyped(textArea, change.replacement);
	textArea.setSelectionRange(change.selectionStart, change.selectionEnd);
}

/** Ctrl+B (bold), Ctrl+I (italic) and Ctrl+K (link) in the text, Cmd on a Mac; see toggleEmphasis() and linkSelection() */
function setupFormatKeys(textArea)
{
	let changes = {
		b: (text, start, end) => toggleEmphasis(text, start, end, '**'),
		i: (text, start, end) => toggleEmphasis(text, start, end, '*'),
		k: linkSelection
	};
	textArea.addEventListener('keydown', (event) =>
	{
		let change = isCommand(event, false) && !event.isComposing ? changes[letterOf(event)] : null;
		if (!change)
		{
			return;
		}
		// (instead of the browser's keys, like the bookmarks or its search field)
		event.preventDefault();
		if (!event.repeat)
		{
			applyChange(textArea, change(textArea.value, textArea.selectionStart, textArea.selectionEnd));
		}
	});
}

/**
 * Ctrl+Alt+P (Cmd+Option+P on a Mac) in the editor shows or hides the preview, like its button (which is shown when the
 * preview can be used). Not Ctrl+Shift+P, which opens a private window in Firefox; Ctrl+Alt with a letter is not used by
 * the browsers, and P is free in the desktops which use Ctrl+Alt (AltGr+P typing a character doesn't count, see letterOf()).
 */
function setupPreviewShortcut(textArea)
{
	let button = document.getElementById('previewbutton');
	document.addEventListener('keydown', (event) =>
	{
		if (!button || button.hidden || !isCommand(event, true) || event.isComposing || letterOf(event) !== 'p')
		{
			return;
		}
		event.preventDefault();
		let dialog = document.querySelector('dialog[open]');
		if (event.repeat || dialog)
		{
			return;
		}
		let inText = document.activeElement === textArea;
		button.click();
		// (on narrow screens the preview takes the place of the text, which loses the focus: it gets it back with the text)
		if ((inText || document.activeElement === document.body) && textArea.offsetParent !== null)
		{
			textArea.focus();
		}
	});
}

// The time to press the second key of a shortcut of two keys, like "g h"
const SHORTCUT_SEQUENCE_TIME = 1500;

/** Whether typing into the element (which has the focus) is typing text: then single keys are not shortcuts */
function takesText(element)
{
	if (!element || !element.closest)
	{
		return false;
	}
	if (element.isContentEditable)
	{
		return true;
	}
	let field = element.closest('input, textarea, select');
	return !!field && (field.localName !== 'input'
		|| !['button', 'submit', 'reset', 'checkbox', 'radio', 'image', 'file', 'range', 'color'].includes(field.type));
}

/**
 * The keyboard shortcuts of every page: single keys (and "g" followed by a key) which follow the links of the page or
 * go to its search box, as data-shortcut of them names them (see page.php), so that a page has only the shortcuts of
 * what it shows. "?" shows the list of the shortcuts (the dialog of shortcuts.php, which hides those the page doesn't
 * have); it closes with Esc, its button, or a click next to it.
 * The keys are left alone while text is typed (in a field, also during the composition of an input method), with Ctrl,
 * Alt or Cmd (the shortcuts of the browser and the system), when a key repeats, and while a dialog is open.
 */
function setupShortcuts()
{
	let dialog = document.getElementById('shortcuts');
	let prefix = '';
	let prefixTimer = null;

	function target(keys)
	{
		for (let element of document.querySelectorAll('[data-shortcut]'))
		{
			if (element.dataset.shortcut === keys && !element.closest('[hidden]'))
			{
				return element;
			}
		}
		return null;
	}

	function use(element)
	{
		if (element.localName === 'input')
		{
			element.focus();
			element.select();
		}
		else
		{
			element.click();
		}
	}

	if (dialog && dialog.showModal)
	{
		dialog.querySelector('.shortcutsclose').addEventListener('click', () => dialog.close());
		// (a click on the backdrop: the content of the dialog is in a box which fills it)
		dialog.addEventListener('click', (event) =>
		{
			if (event.target === dialog)
			{
				dialog.close();
			}
		});
	}
	else
	{
		dialog = null;
	}

	document.addEventListener('keydown', (event) =>
	{
		// (Shift, for keys like "?", is pressed before them)
		if (['Shift', 'Control', 'Alt', 'Meta', 'AltGraph', 'CapsLock'].includes(event.key))
		{
			return;
		}
		let first = prefix;
		prefix = '';
		clearTimeout(prefixTimer);
		if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || event.isComposing || event.repeat
			|| takesText(event.target) || document.querySelector('dialog[open]'))
		{
			return;
		}
		let element = (first !== '' ? target(first + ' ' + event.key) : null) || target(event.key);
		if (element)
		{
			event.preventDefault();
			use(element);
		}
		else if (event.key === '?' && dialog)
		{
			event.preventDefault();
			for (let row of dialog.querySelectorAll('tr[data-for]'))
			{
				row.hidden = !target(row.dataset.for);
			}
			dialog.showModal();
		}
		else if (first === '' && Array.from(document.querySelectorAll('[data-shortcut]')).some((other) => other.dataset.shortcut.startsWith(event.key + ' ')))
		{
			prefix = event.key;
			prefixTimer = setTimeout(() => { prefix = ''; }, SHORTCUT_SEQUENCE_TIME);
		}
	});
}

/**
 * Ctrl+S (Cmd+S on a Mac) in the editor saves the page, as the Save button does (instead of saving the HTML of the
 * page in the browser); so does Ctrl+Enter (Cmd+Enter) in the text fields of the form (the text, the title, the message).
 * The form is sent like with the button, so the draft is kept until the page is saved.
 */
function setupSaveShortcut(form)
{
	document.addEventListener('keydown', (event) =>
	{
		if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey || event.isComposing)
		{
			return;
		}
		let field = event.target.closest ? event.target.closest('textarea, input[type="text"]') : null;
		if (letterOf(event) !== 's' && !(event.key === 'Enter' && field && form.contains(field)))
		{
			return;
		}
		event.preventDefault();
		let dialog = document.getElementById('uploaddialog');
		// (not while files are chosen or sent: their links are not in the text yet)
		if (event.repeat || (dialog && dialog.open))
		{
			return;
		}
		let saveButton = document.getElementById('save');
		if (form.requestSubmit)
		{
			form.requestSubmit(saveButton);
		}
		else
		{
			saveButton.click();
		}
	});
}

document.addEventListener('DOMContentLoaded', () =>
{
	setupShortcuts();
	// the server has saved the text of this draft (the page after saving loads this script for that):
	if (document.body.dataset.savedDraft)
	{
		removeDraft(document.body.dataset.savedDraft);
	}
	// (the editor has the text area, the upload page only the button)
	setupUpload(document.getElementById('uploaddialog'), document.getElementById('text'));
	let form = document.getElementById("edit");
	if (!form)
	{
		return;
	}
	makeElementDraggable(document.getElementById("drawer"));

	// prevent inadvertent navigation away from edited content:
	let modified = false;
	addEventListener('beforeunload', (event) =>
	{
		if (modified)
		{
			event.preventDefault();
		}
	});

	let textArea = document.getElementById("text");
	let titleInput = document.getElementById("title");
	let gitmsgInput = document.getElementById("gitmsg");
	let key = form.dataset.draftKey;

	// content as delivered by the server:
	let baseText = textArea.value;
	let baseTitle = titleInput ? titleInput.value : "";
	let baseGitmsg = gitmsgInput ? gitmsgInput.value : "";
	// (the editor shown again after saving failed contains the unsaved text, not the saved one)
	let unsaved = form.dataset.unsaved === "true";
	let draftBase = baseText;
	// (see setupPreview(), after the draft is restored)
	let preview = null;

	// autosave the current content as local draft, so that it survives
	// the tab being unloaded (e.g. by mobile browsers after inactivity):
	function saveDraft()
	{
		if (!modified)
		{
			return;
		}
		writeDraft(key, {
			text: textArea.value,
			title: titleInput ? titleInput.value : "",
			gitmsg: gitmsgInput ? gitmsgInput.value : "",
			base: draftBase,
			time: Date.now()
		});
	}
	for (let input of [textArea, titleInput, gitmsgInput])
	{
		if (input)
		{
			input.addEventListener('input', () =>
			{
				modified = true;
				saveDraft();
			});
		}
	}
	document.addEventListener('visibilitychange', () =>
	{
		if (document.visibilityState === 'hidden')
		{
			saveDraft();
		}
	});
	addEventListener('pagehide', saveDraft);
	// (back from a failed save to this page from the browser's cache: its text is still unsaved)
	addEventListener('pageshow', (event) =>
	{
		if (event.persisted && readDraft(key))
		{
			modified = true;
		}
	});

	// restore a previously autosaved draft:
	let draft = readDraft(key);
	if (unsaved)
	{
		// (the text is the one of the draft, if it was kept: keep it, with the text it was started from)
		if (draft)
		{
			draftBase = draft.base;
		}
		modified = true;
		saveDraft();
	}
	else if (draft)
	{
		if (draft.text === baseText && (!titleInput || draft.title === baseTitle))
		{
			removeDraft(key);
		}
		else
		{
			textArea.value = draft.text;
			if (titleInput)
			{
				titleInput.value = draft.title;
			}
			if (gitmsgInput && draft.gitmsg)
			{
				gitmsgInput.value = draft.gitmsg;
			}
			modified = true;

			let note = document.createElement("div");
			note.className = "note";
			note.appendChild(document.createTextNode(
				form.dataset.msgRestored.replace("%s", new Date(draft.time).toLocaleString())));
			if (draft.base !== baseText)
			{
				note.appendChild(document.createElement("br"));
				let warning = document.createElement("strong");
				warning.textContent = form.dataset.msgConflict;
				note.appendChild(warning);
			}
			note.appendChild(document.createTextNode(" "));
			let discardBtn = document.createElement("input");
			discardBtn.type = "button";
			discardBtn.value = form.dataset.msgDiscard;
			discardBtn.addEventListener('click', () =>
			{
				textArea.value = baseText;
				if (titleInput)
				{
					titleInput.value = baseTitle;
				}
				if (gitmsgInput)
				{
					gitmsgInput.value = baseGitmsg;
				}
				removeDraft(key);
				modified = false;
				note.remove();
				if (preview)
				{
					preview.update();
				}
			});
			note.appendChild(discardBtn);
			form.parentNode.insertBefore(note, form);
		}
	}

	setupBacklinks(textArea);
	setupIndent(textArea);
	setupFormatKeys(textArea);
	setupImport(form, textArea, titleInput);
	preview = setupPreview(form, textArea);
	setupPreviewShortcut(textArea);

	// (the draft is only removed once the server reports that the text was saved, see data-saved-draft)
	function leaveWithDraft()
	{
		saveDraft();
		modified = false;
	}
	form.addEventListener('submit', leaveWithDraft);
	setupSaveShortcut(form);
	// (the forms which link a mention in another page reload this page, which restores the draft: no warning needed)
	for (let linkForm of document.querySelectorAll('form.linkmention'))
	{
		linkForm.addEventListener('submit', leaveWithDraft);
	}
	document.getElementById("cancel").addEventListener('click', () =>
	{
		removeDraft(key);
	});
});
