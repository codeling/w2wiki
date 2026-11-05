/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Script of the wiki's pages: the keyboard shortcuts of every page, the navigation drawer (which can be dragged) and the
 * editor (local drafts, warning about unsaved changes, Ctrl+S and Ctrl+Enter to save, keys for bold, italic and links,
 * the formatting toolbar, indenting lines with Tab, inserting text files, preview), the dialog to upload files, and
 * dropping files and images on the editor and the upload page.
 * Every page works without it: the shortcuts only follow the links and use the fields which the page has.
 */

function toggleDrawer()
{
	let drawer = document.getElementById('drawer');
	drawer.classList.toggle('inactive');
	// (the button of the formatting toolbar which shows it; the drawer is also closed by its own link)
	let control = document.getElementById('drawer-control');
	if (control)
	{
		control.setAttribute('aria-expanded', drawer.classList.contains('inactive') ? 'false' : 'true');
	}
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

/**
 * The lines which differ between two texts, as the server shows them in the history (see LineDiff): a list of [kind, line]
 * with the kind "same", "removed", "added" or "gap" (the line is then the number of lines left out), with some lines
 * around each change. The texts are compared by their lines; if they are too long or too different, all the
 * lines between the common start and end are shown as removed and added.
 */
function diffLines(oldText, newText)
{
	const CONTEXT = 3;
	const MAX_CELLS = 4000000;
	let a = oldText.split("\n");
	let b = newText.split("\n");
	let start = 0;
	let endA = a.length;
	let endB = b.length;
	while (start < endA && start < endB && a[start] === b[start])
	{
		start++;
	}
	while (endA > start && endB > start && a[endA - 1] === b[endB - 1])
	{
		endA--;
		endB--;
	}
	let n = endA - start;
	let m = endB - start;
	let ops = a.slice(0, start).map((line) => ["same", line]);
	if ((n + 1) * (m + 1) > MAX_CELLS)
	{
		ops.push(...a.slice(start, endA).map((line) => ["removed", line]));
		ops.push(...b.slice(start, endB).map((line) => ["added", line]));
	}
	else
	{
		// table[i][j]: the length of the longest common run of lines from i of the old and from j of the new text
		let table = [];
		for (let i = 0; i <= n; i++)
		{
			table.push(new Array(m + 1).fill(0));
		}
		for (let i = n - 1; i >= 0; i--)
		{
			for (let j = m - 1; j >= 0; j--)
			{
				table[i][j] = (a[start + i] === b[start + j]) ? table[i + 1][j + 1] + 1 : Math.max(table[i + 1][j], table[i][j + 1]);
			}
		}
		let i = 0;
		let j = 0;
		while (i < n || j < m)
		{
			if (i < n && j < m && a[start + i] === b[start + j])
			{
				ops.push(["same", a[start + i]]);
				i++;
				j++;
			}
			else if (j >= m || (i < n && table[i + 1][j] >= table[i][j + 1]))
			{
				ops.push(["removed", a[start + i]]);
				i++;
			}
			else
			{
				ops.push(["added", b[start + j]]);
				j++;
			}
		}
	}
	ops.push(...a.slice(endA).map((line) => ["same", line]));

	let keep = new Array(ops.length).fill(false);
	ops.forEach((op, i) =>
	{
		if (op[0] !== "same")
		{
			for (let k = Math.max(0, i - CONTEXT); k <= Math.min(ops.length - 1, i + CONTEXT); k++)
			{
				keep[k] = true;
			}
		}
	});
	let result = [];
	let skipped = 0;
	ops.forEach((op, i) =>
	{
		if (keep[i])
		{
			if (skipped > 0)
			{
				result.push(["gap", String(skipped)]);
				skipped = 0;
			}
			result.push(op);
		}
		else
		{
			skipped++;
		}
	});
	if (skipped > 0)
	{
		result.push(["gap", String(skipped)]);
	}
	return result;
}

/** The box with the lines which differ (the same as the server's, see diff-lines.php); unchanged lines: "%s unchanged lines" */
function diffBox(oldText, newText, unchangedMessage)
{
	let box = document.createElement("div");
	box.className = "diff";
	let pre = document.createElement("pre");
	for (let [kind, line] of diffLines(oldText, newText))
	{
		let span = document.createElement("span");
		span.className = kind;
		span.textContent = kind === "gap" ? "@@ " + unchangedMessage.replace("%s", line) + " @@"
			: (kind === "removed" ? "- " : (kind === "added" ? "+ " : "  ")) + line;
		pre.appendChild(span);
	}
	box.appendChild(pre);
	return box;
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
 * or dropped on the editor (see setupDrops(), which puts the cursor where they are dropped). A new page without a title
 * gets the name of the (first) file. The text is then saved like typed text, so nothing is stored before the page is
 * saved. Returns the function which inserts files.
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
	// (dropped files come from setupDrops())
	return insertFiles;
}

const INDENT = '    ';

/**
 * The lines of the text which the selection from start to end touches: where they start and end in the text, and the
 * lines (without line breaks). A selection of whole lines ends after the line break of the last one: the next line is
 * not part of it.
 */
function touchedLines(text, start, end)
{
	let blockStart = start > 0 ? text.lastIndexOf('\n', start - 1) + 1 : 0;
	let lastPosition = (end > start && text[end - 1] === '\n') ? end - 1 : end;
	let blockEnd = text.indexOf('\n', lastPosition);
	if (blockEnd < 0)
	{
		blockEnd = text.length;
	}
	return { start: blockStart, end: blockEnd, lines: text.slice(blockStart, blockEnd).split('\n') };
}

/**
 * Indents (by INDENT) or outdents (by up to INDENT, or one tab) the lines of the text which the selection from start to
 * end touches; empty lines are left as they are. Returns the part of the text to replace (start, end), its replacement,
 * and the selection afterwards (selectionStart, selectionEnd); or null if no line changes.
 */
function indentLines(text, start, end, outdent)
{
	let block = touchedLines(text, start, end);
	let blockStart = block.start;
	let blockEnd = block.end;
	let lines = block.lines;
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
 * In the editor (with a text area), images pasted into the text open the dialog with the files already chosen, and the
 * link to each file is inserted at the cursor; the text is not touched otherwise. Dropped files are chosen the same way
 * (see setupDrops(), which first puts the cursor where they are dropped).
 * On the upload page (no text area), the page is loaded again to show the new files in its list.
 * Returns the function which chooses files for the dialog (and opens it), or null without uploads.
 */
function setupUpload(dialog, textArea)
{
	let openButton = document.getElementById('uploadbutton');
	if (!openButton || !dialog || !dialog.showModal)
	{
		return null;
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

	// (pasting images needs the text; dropped files come from setupDrops())
	if (textArea)
	{
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
	return chooseFiles;
}

/** Whether a drag carries files (from the computer; browsers may add the picture of an image dragged from a page too) */
function dragHasFiles(event)
{
	return !!event.dataTransfer && Array.from(event.dataTransfer.types || []).includes('Files');
}

// (the styles of a text area which decide where its lines break, see textOffsetAt())
const MIRROR_STYLES = ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'fontVariant', 'fontStretch', 'fontKerning',
	'fontFeatureSettings', 'fontVariantLigatures', 'lineHeight', 'letterSpacing', 'wordSpacing', 'textIndent', 'textTransform',
	'tabSize', 'whiteSpace', 'wordBreak', 'overflowWrap', 'direction', 'textAlign', 'paddingTop', 'paddingRight',
	'paddingBottom', 'paddingLeft'];

/**
 * The position in the text of the text area under the point (in the coordinates of the window, like clientX and clientY
 * of an event), or null if the point is not on the text area or the browser can't tell.
 * Browsers don't tell where dropped files land in a text area: a copy of its text, with its font, size, padding and
 * scroll position, is laid over it for a moment (invisible), and the browser finds the position in the copy.
 */
function textOffsetAt(textArea, x, y)
{
	let rect = textArea.getBoundingClientRect();
	if (!(x >= rect.left && x < rect.right && y >= rect.top && y < rect.bottom)
		|| !(document.caretPositionFromPoint || document.caretRangeFromPoint))
	{
		return null;
	}
	let style = getComputedStyle(textArea);
	let mirror = document.createElement('div');
	for (let name of MIRROR_STYLES)
	{
		mirror.style[name] = style[name];
	}
	let padding = (side) => parseFloat(style['padding' + side]) || 0;
	let border = (side) => parseFloat(style['border' + side + 'Width']) || 0;
	// (inside the border and without the scrollbar, where the text area shows its text)
	Object.assign(mirror.style, {
		position: 'fixed', boxSizing: 'content-box', margin: '0', border: '0', overflow: 'hidden', opacity: '0',
		zIndex: '2147483647', pointerEvents: 'auto',
		left: (rect.left + border('Left')) + 'px', top: (rect.top + border('Top')) + 'px',
		width: Math.max(0, textArea.clientWidth - padding('Left') - padding('Right')) + 'px',
		height: Math.max(0, textArea.clientHeight - padding('Top') - padding('Bottom')) + 'px'
	});
	// (the character after the text gives a line break at the end a line of its own, as in the text area)
	mirror.textContent = textArea.value + '​';
	document.body.appendChild(mirror);
	mirror.scrollTop = textArea.scrollTop;
	mirror.scrollLeft = textArea.scrollLeft;
	let node = null;
	let offset = 0;
	if (document.caretPositionFromPoint)
	{
		let position = document.caretPositionFromPoint(x, y);
		if (position)
		{
			node = position.offsetNode;
			offset = position.offset;
		}
	}
	else
	{
		let range = document.caretRangeFromPoint(x, y);
		if (range)
		{
			node = range.startContainer;
			offset = range.startOffset;
		}
	}
	let text = mirror.firstChild;
	mirror.remove();
	if (node === mirror)
	{
		return offset > 0 ? textArea.value.length : 0;
	}
	return node === text ? Math.min(offset, textArea.value.length) : null;
}

// (the extensions of image files, for addresses of images without an <img>)
const IMAGE_EXTENSIONS = /\.(png|jpe?g|gif|webp|avif|svg|bmp|ico)$/i;

/**
 * The image which a drag from a web page carries (an image of a page, maybe in a link, or the address of an image
 * file), as { url (URL), alt (text), isImage (bool: certainly an image, not only an address) }; null for other drags
 * (text, links, files of the computer), which keep their usual behaviour. Only http and https addresses.
 * The HTML of the drag is only parsed, in a document which loads nothing and runs nothing.
 */
function draggedImage(data)
{
	let types = Array.from(data.types || []);
	let source = null;
	let alt = '';
	let isImage = false;
	if (types.includes('text/html'))
	{
		let html = new DOMParser().parseFromString(data.getData('text/html'), 'text/html');
		let images = html.querySelectorAll('img');
		// (a selection of text with an image is text)
		if (images.length !== 1 || html.body.textContent.trim() !== '')
		{
			return null;
		}
		source = images[0].getAttribute('src');
		alt = images[0].getAttribute('alt') || '';
		isImage = true;
	}
	else if (types.includes('text/uri-list'))
	{
		let addresses = data.getData('text/uri-list').split(/\r?\n/).map((line) => line.trim()).filter((line) => line !== '' && line[0] !== '#');
		source = addresses.length === 1 ? addresses[0] : null;
	}
	if (!source)
	{
		return null;
	}
	let url;
	try
	{
		url = new URL(source, location.href);
	}
	catch (e)
	{
		return null;
	}
	// (no javascript:, data:, blob: or file: addresses)
	return (url.protocol === 'http:' || url.protocol === 'https:') ? { url, alt, isImage } : null;
}

/**
 * The Markdown which an image dragged into the editor becomes (see draggedImage()), or null if the drag is no image:
 * for a file of the wiki's uploads (as file.php serves it, or under the address of the uploads folder) the same link as
 * after uploading it, for other images ![alt](address).
 * form.dataset: fileUrl (the address of file.php), uploadPrefix (the address of the uploads folder in links, with a
 * slash at the end), msgImageAlt (the alt text of the links to uploads)
 */
function imageMarkdown(form, image)
{
	if (!image)
	{
		return null;
	}
	let url = image.url;
	let name = null;
	if (url.origin === location.origin)
	{
		let fileUrl = new URL(form.dataset.fileUrl, location.href);
		let prefix = new URL(form.dataset.uploadPrefix, location.href).pathname;
		if (url.pathname === fileUrl.pathname)
		{
			name = url.searchParams.get('name');
		}
		else if (url.pathname.startsWith(prefix))
		{
			try
			{
				name = decodeURIComponent(url.pathname.slice(prefix.length));
			}
			catch (e)
			{
				name = null;
			}
		}
	}
	// (a name the uploads can have: no folders, no hidden files, nothing which would end the link)
	if (name && !/[\/\\\s<>()\[\]\u0000-\u001f\u007f]/.test(name) && name[0] !== '.')
	{
		return (image.isImage || IMAGE_EXTENSIONS.test(name)) ? '![' + form.dataset.msgImageAlt + '](' + form.dataset.uploadPrefix + name + ')' : null;
	}
	if (!image.isImage && !IMAGE_EXTENSIONS.test(url.pathname))
	{
		return null;
	}
	let alt = image.alt.replace(/\s+/g, ' ').trim().slice(0, 200).replace(/[\\\[\]]/g, '\\$&');
	// (parentheses would end the address early)
	return '![' + alt + '](' + url.href.replace(/\(/g, '%28').replace(/\)/g, '%29') + ')';
}

/**
 * The whole page as a place to drop files (the editor, or the upload page): while files are dragged over it, a hint
 * covers it (only to be seen: the overlay lets the events through, and screen readers skip it), and files dropped
 * anywhere are taken, so that the browser does not open them instead of the page (which would lose the text).
 * Nothing changes for drags within the page or of text and links, except that a link dropped next to the text is not
 * opened either.
 * In the editor (textArea), files dropped on the text go where they are dropped: the cursor is put there before the
 * files are inserted or the dialog to upload them opens (while they are dragged over the text, the cursor follows the
 * mouse, and goes back if they are not dropped there). Files dropped elsewhere go to the cursor, or at the end of the
 * text if it never had the focus. An image dragged from a page (another tab, the upload page, the preview) becomes a
 * link to it, see imageMarkdown().
 * options: message (the text of the hint), files(files) (takes the dropped files), image(dataTransfer) (the text which a
 * dropped image becomes, or null; not on the upload page), uploadFiles(files) (chooses files for the open dialog to upload)
 */
function setupDrops(textArea, options)
{
	let overlay = document.createElement('div');
	overlay.className = 'dropoverlay';
	overlay.hidden = true;
	overlay.setAttribute('aria-hidden', 'true');
	let label = document.createElement('p');
	label.textContent = options.message;
	overlay.appendChild(label);
	document.body.appendChild(overlay);
	let box = textArea ? textArea.parentNode : null;
	// (dragenter and dragleave come for every element: the hint goes when the drag has left them all)
	let depth = 0;
	// (a drag which started in this page: an image of the preview, selected text)
	let internal = false;
	// (the cursor before the drag moved it, to go back to)
	let saved = null;
	let lastPoint = null;
	let everFocused = false;

	let dialogOpen = () => document.querySelector('dialog[open]');
	let isFileInput = (element) => element instanceof HTMLInputElement && element.type === 'file';
	let isEditable = (element) => element instanceof HTMLTextAreaElement || (element instanceof HTMLInputElement && element.type !== 'file')
		|| (element instanceof HTMLElement && element.isContentEditable);
	let fileDrag = (event) => !internal && dragHasFiles(event);

	if (textArea)
	{
		textArea.addEventListener('focus', () => { everFocused = true; });
	}

	function moveCaret(event)
	{
		let point = event.clientX + ',' + event.clientY + ',' + textArea.scrollTop;
		if (point === lastPoint)
		{
			return;
		}
		lastPoint = point;
		let offset = textOffsetAt(textArea, event.clientX, event.clientY);
		if (offset === null)
		{
			return;
		}
		if (!saved)
		{
			saved = {
				start: textArea.selectionStart, end: textArea.selectionEnd, direction: textArea.selectionDirection,
				scrollTop: textArea.scrollTop, active: document.activeElement, everFocused
			};
		}
		textArea.focus({ preventScroll: true });
		textArea.setSelectionRange(offset, offset);
	}

	function restoreCaret()
	{
		lastPoint = null;
		if (!saved)
		{
			return;
		}
		let previous = saved;
		saved = null;
		textArea.setSelectionRange(previous.start, previous.end, previous.direction);
		textArea.scrollTop = previous.scrollTop;
		if (previous.active !== textArea)
		{
			if (previous.active && previous.active !== document.body && previous.active.focus)
			{
				previous.active.focus({ preventScroll: true });
			}
			else
			{
				textArea.blur();
			}
			everFocused = previous.everFocused;
		}
	}

	/** The drag is over: dropped (in the text: the cursor stays where it was dropped), cancelled, or gone */
	function endDrag(droppedInText)
	{
		depth = 0;
		overlay.hidden = true;
		if (box)
		{
			box.classList.remove('dropping');
		}
		if (droppedInText)
		{
			saved = null;
			lastPoint = null;
		}
		else if (textArea)
		{
			restoreCaret();
		}
	}

	/** Puts the cursor where something is dropped: at the offset in the text, or else where it was (the end of a text which never had the focus) */
	function placeCaret(offset)
	{
		if (offset !== null)
		{
			textArea.focus({ preventScroll: true });
			textArea.setSelectionRange(offset, offset);
		}
		else if (!everFocused)
		{
			textArea.setSelectionRange(textArea.value.length, textArea.value.length);
		}
	}

	document.addEventListener('dragstart', () => { internal = true; });
	document.addEventListener('dragend', () =>
	{
		internal = false;
		endDrag(false);
	});
	document.addEventListener('dragenter', (event) =>
	{
		if (fileDrag(event) && !dialogOpen())
		{
			depth++;
			overlay.hidden = false;
		}
	});
	document.addEventListener('dragleave', (event) =>
	{
		if (!fileDrag(event) || overlay.hidden)
		{
			return;
		}
		depth = Math.max(0, depth - 1);
		if (depth === 0)
		{
			endDrag(false);
		}
	});
	document.addEventListener('dragover', (event) =>
	{
		// (the file chooser of the dialog takes files itself)
		if (!event.dataTransfer || isFileInput(event.target))
		{
			return;
		}
		let files = fileDrag(event);
		if (files && !dialogOpen())
		{
			// (also when dragenter was missed)
			overlay.hidden = false;
			if (textArea && event.target === textArea)
			{
				event.preventDefault();
				event.dataTransfer.dropEffect = 'copy';
				box.classList.add('dropping');
				moveCaret(event);
				return;
			}
		}
		if (textArea && event.target === textArea)
		{
			// (text, links and images: the browser shows where they go)
			return;
		}
		if (box)
		{
			box.classList.remove('dropping');
			restoreCaret();
		}
		// (files, and in the editor links and images, are not opened instead of the page)
		if (files || (textArea && !isEditable(event.target) && Array.from(event.dataTransfer.types || []).includes('text/uri-list')))
		{
			event.preventDefault();
			event.dataTransfer.dropEffect = 'copy';
		}
	});
	document.addEventListener('drop', (event) =>
	{
		let data = event.dataTransfer;
		if (!data || isFileInput(event.target))
		{
			endDrag(false);
			return;
		}
		let files = fileDrag(event);
		let inText = !!textArea && event.target === textArea;
		let offset = inText ? textOffsetAt(textArea, event.clientX, event.clientY) : null;
		endDrag(inText);
		internal = false;
		if (dialogOpen())
		{
			if (files)
			{
				event.preventDefault();
				// (on the dialog to upload files, as with its file chooser)
				if (options.uploadFiles && document.getElementById('uploaddialog') === dialogOpen())
				{
					options.uploadFiles(Array.from(data.files));
				}
			}
			return;
		}
		let image = (textArea && options.image) ? options.image(data) : null;
		if (image !== null)
		{
			event.preventDefault();
			placeCaret(offset);
			insertTyped(textArea, image);
		}
		else if (files && data.files.length > 0)
		{
			event.preventDefault();
			if (textArea)
			{
				placeCaret(offset);
			}
			options.files(Array.from(data.files));
		}
		else if (textArea && !isEditable(event.target) && Array.from(data.types || []).includes('text/uri-list'))
		{
			event.preventDefault();
		}
	});
	// (no drag events come any more: the drag has ended somehow, e.g. outside of the window)
	for (let type of ['mousemove', 'pointerdown'])
	{
		document.addEventListener(type, () =>
		{
			if (!overlay.hidden)
			{
				endDrag(false);
			}
		});
	}
	document.addEventListener('keydown', (event) =>
	{
		if (event.key === 'Escape' && !overlay.hidden)
		{
			endDrag(false);
		}
	});
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

/** Whether the line has no text (only spaces, or nothing) */
function isBlank(line)
{
	return line.trim() === '';
}

/**
 * Changes the starts of the lines which the selection from start to end touches (see touchedLines()): starts(lines)
 * gives for every line the length of the part at its start which is replaced, and its replacement. The selection moves
 * with the text: a position in a replaced part goes to the end of its replacement, except the start of a selection at
 * the start of a line, which stays there (so that whole lines stay selected). Returns the change like toggleEmphasis(),
 * or null if the text stays as it is.
 */
function changeLineStarts(text, start, end, starts)
{
	let block = touchedLines(text, start, end);
	let parts = starts(block.lines);
	let replacement = block.lines.map((line, i) => parts[i][1] + line.slice(parts[i][0])).join('\n');
	if (replacement === text.slice(block.start, block.end))
	{
		return null;
	}
	function moved(position, isStart)
	{
		let lineStart = block.start;
		let newLineStart = block.start;
		for (let i = 0; i < block.lines.length; i++)
		{
			let [length, prefix] = parts[i];
			let lineEnd = lineStart + block.lines[i].length;
			// (the end of a selection of whole lines is after the last of them)
			if (position <= lineEnd || i === block.lines.length - 1)
			{
				let column = position - lineStart;
				return (isStart && column === 0) ? newLineStart : newLineStart + prefix.length + Math.max(0, column - length);
			}
			newLineStart += prefix.length + block.lines[i].length - length + 1;
			lineStart = lineEnd + 1;
		}
		return position;
	}
	return { start: block.start, end: block.end, replacement: replacement,
		selectionStart: moved(start, start !== end), selectionEnd: moved(end, false) };
}

/** The level of the heading which the line is (the number of "#" at its start), or 0 */
function headingLevel(line)
{
	let match = /^#{1,6}/.exec(line);
	return match ? match[0].length : 0;
}

/**
 * Headings of the level (1 to 6) for the lines which the selection from start to end touches: their "#" are set, or
 * replaced if they are headings of another level; if all of them are headings of this level already, the "#" are
 * removed. Empty lines stay as they are, unless all lines are empty (like the line of the cursor): then the heading
 * starts there. Returns the change like toggleEmphasis(), or null.
 */
function setHeading(text, start, end, level)
{
	return changeLineStarts(text, start, end, (lines) =>
	{
		let used = lines.filter((line) => !isBlank(line));
		let remove = used.length > 0 && used.every((line) => headingLevel(line) === level);
		return lines.map((line) =>
		{
			if (used.length > 0 && isBlank(line))
			{
				return [0, ''];
			}
			return [/^#{0,6}[ \t]*/.exec(line)[0].length, remove ? '' : '#'.repeat(level) + ' '];
		});
	});
}

// The start of an item of a list: the indentation, the marker ("-", "*", "+", or a number with "." or ")"), spaces
const LIST_ITEM = /^([ \t]*)([-*+]|\d+[.)])[ \t]+/;

/**
 * A bulleted ("- ") or numbered ("1. ", "2. ", ...) list of the lines which the selection from start to end touches
 * (empty lines are skipped, unless all are empty); the marker of another kind of list is replaced, and the indentation
 * is kept. If all lines are items of such a list already, their markers are removed. Returns the change like
 * toggleEmphasis(), or null.
 */
function toggleList(text, start, end, numbered)
{
	return changeLineStarts(text, start, end, (lines) =>
	{
		let used = lines.filter((line) => !isBlank(line));
		let isItem = (line) =>
		{
			let match = LIST_ITEM.exec(line);
			return !!match && /\d/.test(match[2]) === numbered;
		};
		let remove = used.length > 0 && used.every(isItem);
		let number = 0;
		return lines.map((line) =>
		{
			if (used.length > 0 && isBlank(line))
			{
				return [0, ''];
			}
			let match = LIST_ITEM.exec(line);
			let indentation = match ? match[1] : /^[ \t]*/.exec(line)[0];
			let length = match ? match[0].length : indentation.length;
			if (remove)
			{
				return [length, indentation];
			}
			number++;
			return [length, indentation + (numbered ? number + '. ' : '- ')];
		});
	});
}

/**
 * A quote ("> ") of the lines which the selection from start to end touches; empty lines between them get ">", so that
 * they stay one quote, and those before and after them stay as they are. If all lines are quoted already, the ">" are
 * removed. Returns the change like toggleEmphasis(), or null.
 */
function toggleQuote(text, start, end)
{
	return changeLineStarts(text, start, end, (lines) =>
	{
		let first = lines.findIndex((line) => !isBlank(line));
		let last = lines.length - 1 - lines.slice().reverse().findIndex((line) => !isBlank(line));
		let remove = first >= 0 && lines.every((line) => isBlank(line) || /^ {0,3}>/.test(line));
		return lines.map((line, i) =>
		{
			if (remove)
			{
				let match = /^ {0,3}> ?/.exec(line);
				return [match ? match[0].length : 0, ''];
			}
			if (first < 0)
			{
				return [line.length, '> '];
			}
			if (i < first || i > last)
			{
				return [0, ''];
			}
			return isBlank(line) ? [line.length, '>'] : [0, '> '];
		});
	});
}

/**
 * Puts the block (lines, like a table) in place of the part of the text from start to end, with blank lines before and
 * after it as needed, so that it is a block of its own. Returns the change like toggleEmphasis(), with the cursor after
 * the block, and where the block starts in the text afterwards (blockStart).
 */
function asBlock(text, start, end, block)
{
	let before = text.slice(0, start);
	let after = text.slice(end);
	let pre = (before === '' || before.endsWith('\n\n')) ? '' : (before.endsWith('\n') ? '\n' : '\n\n');
	let post = after.startsWith('\n\n') ? '' : ((after === '' || after.startsWith('\n')) ? '\n' : '\n\n');
	let replacement = pre + block + post;
	return { start: start, end: end, replacement: replacement, selectionStart: start + replacement.length,
		selectionEnd: start + replacement.length, blockStart: start + pre.length };
}

/**
 * Where a new block goes for the cursor (or the end of the selection) at the position: before its line if it is at the
 * start of the line, else after the line, so that no line is split
 */
function blockPosition(text, position)
{
	if (position === 0 || text[position - 1] === '\n')
	{
		return position;
	}
	let lineEnd = text.indexOf('\n', position);
	return lineEnd < 0 ? text.length : lineEnd;
}

/**
 * Code: `code` for a selection within a line (or `` ` `` at the cursor, removed again by the next press), removing the
 * backticks of code which is marked already; a selection which contains a backtick gets two, with spaces. Returns the
 * change like toggleEmphasis().
 */
function toggleInlineCode(text, start, end)
{
	[start, end] = trimSelection(text, start, end);
	let selected = text.slice(start, end);
	if (start > 0 && text[start - 1] === '`' && text[end] === '`')
	{
		return { start: start - 1, end: end + 1, replacement: selected, selectionStart: start - 1, selectionEnd: end - 1 };
	}
	let marked = /^(`+) ?([^]*?) ?\1$/.exec(selected);
	if (selected.length >= 2 && marked)
	{
		return { start: start, end: end, replacement: marked[2], selectionStart: start, selectionEnd: start + marked[2].length };
	}
	let [open, close] = selected.includes('`') ? ['`` ', ' ``'] : ['`', '`'];
	return { start: start, end: end, replacement: open + selected + close, selectionStart: start + open.length,
		selectionEnd: end + open.length };
}

// A line which starts or ends a fenced code block
const FENCE = /^ {0,3}(`{3,}|~{3,})/;

/**
 * Code for the selection from start to end: inline code for a selection within a line, or the cursor in a line with
 * text (see toggleInlineCode()); a fenced code block of the lines for a selection of several lines, or the cursor in
 * an empty line, with the cursor after the opening ``` to type the language (like ```php, see the highlighting of code
 * in toHTML()). Lines which are a fenced code block already, with the fences, lose the fences. Returns the change like
 * toggleEmphasis().
 */
function toggleCode(text, start, end)
{
	let block = touchedLines(text, start, end);
	let lines = block.lines;
	if (lines.length >= 2 && FENCE.test(lines[0]) && FENCE.test(lines[lines.length - 1]))
	{
		let inner = lines.slice(1, -1).join('\n');
		return { start: block.start, end: block.end, replacement: inner, selectionStart: block.start, selectionEnd: block.start + inner.length };
	}
	if (!text.slice(start, end).includes('\n') && (start !== end || !isBlank(lines[0])))
	{
		return toggleInlineCode(text, start, end);
	}
	let change = asBlock(text, block.start, block.end, '```\n' + (lines.every(isBlank) ? '' : lines.join('\n') + '\n') + '```');
	change.selectionStart = change.selectionEnd = change.blockStart + 3;
	return change;
}

/** A horizontal rule after the line of the cursor (see blockPosition()), as a block of its own; returns the change like toggleEmphasis() */
function insertRule(text, start, end)
{
	let position = blockPosition(text, end);
	return asBlock(text, position, position, '---');
}

/**
 * The lines of a table of PHP Markdown Extra: the rows are lists of cells (the first one is the header row; rows with
 * fewer cells get empty ones), and the columns are as wide as their widest cell, so that the table can be read as text
 */
function tableLines(rows)
{
	let columns = Math.max(...rows.map((row) => row.length));
	let cells = rows.map((row) => Array.from({ length: columns }, (_, i) => row[i] || ''));
	let widths = Array.from({ length: columns }, (_, i) => Math.max(3, ...cells.map((row) => row[i].length)));
	let line = (row) => '| ' + row.map((cell, i) => cell + ' '.repeat(widths[i] - cell.length)).join(' | ') + ' |';
	return [line(cells[0]), line(widths.map((width) => '-'.repeat(width)))].concat(cells.slice(1).map(line)).join('\n');
}

/**
 * A table with the number of columns, and of rows below the header row, after the line of the cursor (see
 * blockPosition()), as a block of its own. The header cells are named by columnName (with the number of the column for
 * %s); the first one is selected, to type the name of the column. Returns the change like toggleEmphasis().
 */
function insertTable(text, start, end, columns, rows, columnName)
{
	let header = Array.from({ length: columns }, (_, i) => columnName.replace('%s', () => String(i + 1)));
	let position = blockPosition(text, end);
	let change = asBlock(text, position, position, tableLines([header].concat(Array.from({ length: rows }, () => []))));
	change.selectionStart = change.blockStart + 2;
	change.selectionEnd = change.selectionStart + header[0].length;
	return change;
}

/**
 * A table of the selected lines if they are rows of cells separated by tabs (as copied from a spreadsheet) or by commas
 * (the same number in every line, no quotes): at least two lines, the first of which is the header row. Returns the
 * change like toggleEmphasis(), or null if the selection is not such a list.
 */
function tableOfSelection(text, start, end)
{
	if (start === end)
	{
		return null;
	}
	let block = touchedLines(text, start, end);
	let lines = block.lines.filter((line) => !isBlank(line));
	if (lines.length < 2)
	{
		return null;
	}
	let separator = null;
	if (lines.every((line) => line.includes('\t')))
	{
		separator = '\t';
	}
	else if (lines[0].includes(',') && !lines.some((line) => /["|]/.test(line))
		&& lines.every((line) => line.split(',').length === lines[0].split(',').length))
	{
		separator = ',';
	}
	if (separator === null)
	{
		return null;
	}
	// (a "|" in a cell would end it: it is escaped)
	let rows = lines.map((line) => line.split(separator).map((cell) => cell.trim().replace(/\|/g, '\\|')));
	return asBlock(text, block.start, block.end, tableLines(rows));
}

/**
 * A link to a page of the wiki: [[selection]], with the name selected (or the cursor between the brackets); the
 * brackets are removed if the selection is such a link already. Returns the change like toggleEmphasis().
 */
function wikiLinkSelection(text, start, end)
{
	[start, end] = trimSelection(text, start, end);
	let selected = text.slice(start, end);
	if (start >= 2 && text.slice(start - 2, start) === '[[' && text.slice(end, end + 2) === ']]')
	{
		return { start: start - 2, end: end + 2, replacement: selected, selectionStart: start - 2, selectionEnd: end - 2 };
	}
	if (/^\[\[[^\]]*\]\]$/.test(selected))
	{
		let inner = selected.slice(2, -2);
		return { start: start, end: end, replacement: inner, selectionStart: start, selectionEnd: start + inner.length };
	}
	return { start: start, end: end, replacement: '[[' + selected + ']]', selectionStart: start + 2, selectionEnd: end + 2 };
}

/**
 * The changes of the formatting toolbar (see setupFormatBar()) and of the keys of the editor (setupFormatKeys()), by
 * the name in data-format of the buttons: each takes the text and the selection, and returns the change (see
 * toggleEmphasis()) or null
 */
const FORMATS = {
	bold: (text, start, end) => toggleEmphasis(text, start, end, '**'),
	italic: (text, start, end) => toggleEmphasis(text, start, end, '*'),
	link: linkSelection,
	wikilink: wikiLinkSelection,
	bullets: (text, start, end) => toggleList(text, start, end, false),
	numbers: (text, start, end) => toggleList(text, start, end, true),
	quote: toggleQuote,
	code: toggleCode,
	rule: insertRule
};

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
	// (the same as the buttons of the formatting toolbar)
	let changes = { b: FORMATS.bold, i: FORMATS.italic, k: FORMATS.link };
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
 * The number in the field (of type number), within its limits, or its default for no number (its limits keep the
 * dialog open with a number outside of them; this is for browsers which don't check them)
 */
function numberIn(input)
{
	let value = parseInt(input.value, 10);
	return Number.isNaN(value) ? Number(input.defaultValue) : Math.max(Number(input.min), Math.min(Number(input.max), value));
}

/**
 * The formatting toolbar above the text (format-bar.php), which is hidden without the script: its buttons change the
 * text like the keys of the editor (FORMATS, the same functions as Ctrl+B, Ctrl+I and Ctrl+K), as if it was typed (see
 * applyChange(): one step to undo, and an input event for the draft, the warning about leaving and the preview).
 * The buttons don't take the focus from the text when they are clicked, so that the selection stays; used with the
 * keyboard, the text gets the focus back after the change. The toolbar is one stop of Tab: the arrow keys (and Home,
 * End) move between its buttons, Esc goes back to the text, and Alt+F10 in the text goes to the toolbar.
 * The heading button opens a menu of the levels (the level of the cursor's line is checked), the table button a dialog
 * for the size of a table (table-dialog.php) unless the selected lines can be made a table (see tableOfSelection()),
 * the last button shows the Markdown help (the drawer).
 */
function setupFormatBar(textArea)
{
	let bar = document.getElementById('formatbar');
	if (!bar)
	{
		return;
	}
	let items = Array.from(bar.querySelectorAll('button:not([role^="menuitem"])'));
	let menuButton = document.getElementById('headingbutton');
	let menu = document.getElementById('headingmenu');
	let menuItems = Array.from(menu.querySelectorAll('[role="menuitemradio"]'));
	let dialog = document.getElementById('tabledialog');
	let current = 0;

	function apply(format)
	{
		let change = format(textArea.value, textArea.selectionStart, textArea.selectionEnd);
		if (change)
		{
			applyChange(textArea, change);
		}
		else
		{
			textArea.focus();
		}
	}

	// (one button can have the focus with Tab: the last one which had it)
	function setCurrent(index, focus)
	{
		current = index;
		items.forEach((item, i) => { item.tabIndex = (i === index) ? 0 : -1; });
		if (focus)
		{
			items[index].focus();
		}
	}

	function openMenu(focus)
	{
		let level = headingLevel(touchedLines(textArea.value, textArea.selectionStart, textArea.selectionStart).lines[0]);
		for (let item of menuItems)
		{
			item.setAttribute('aria-checked', Number(item.dataset.level) === level ? 'true' : 'false');
		}
		menu.hidden = false;
		menuButton.setAttribute('aria-expanded', 'true');
		if (focus !== null)
		{
			menuItems[focus === 'last' ? menuItems.length - 1 : Math.max(0, level - 1)].focus();
		}
	}

	function closeMenu(focusButton)
	{
		if (menu.hidden)
		{
			return;
		}
		menu.hidden = true;
		menuButton.setAttribute('aria-expanded', 'false');
		if (focusButton)
		{
			menuButton.focus();
		}
	}

	bar.hidden = false;
	setCurrent(0, false);
	// (a click on a button leaves the focus, and the selection, in the text)
	bar.addEventListener('mousedown', (event) =>
	{
		if (event.target.closest('button'))
		{
			event.preventDefault();
		}
	});
	bar.addEventListener('focusin', (event) =>
	{
		let index = items.indexOf(event.target);
		if (index >= 0)
		{
			setCurrent(index, false);
		}
	});
	bar.addEventListener('keydown', (event) =>
	{
		let index = items.indexOf(event.target);
		if (index < 0 || event.ctrlKey || event.altKey || event.metaKey)
		{
			return;
		}
		let next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: items.length - 1 }[event.key];
		if (next !== undefined)
		{
			event.preventDefault();
			setCurrent((next + items.length) % items.length, true);
		}
		else if (event.key === 'Escape')
		{
			event.preventDefault();
			textArea.focus();
		}
		else if (event.target === menuButton && (event.key === 'ArrowDown' || event.key === 'ArrowUp'))
		{
			event.preventDefault();
			openMenu(event.key === 'ArrowUp' ? 'last' : 'first');
		}
	});
	textArea.addEventListener('keydown', (event) =>
	{
		if (event.key === 'F10' && event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey)
		{
			event.preventDefault();
			closeMenu(false);
			items[current].focus();
		}
	});
	// (Esc closes the menu wherever the focus is; in the menu, see below, it goes back to its button)
	document.addEventListener('keydown', (event) =>
	{
		if (event.key === 'Escape')
		{
			closeMenu(false);
		}
	});

	for (let button of bar.querySelectorAll('button[data-format]'))
	{
		button.addEventListener('click', () => apply(FORMATS[button.dataset.format]));
	}

	// the menu of the headings: opened by a click (the text keeps the focus) or with the keyboard (the menu gets it)
	menuButton.addEventListener('click', (event) =>
	{
		if (!menu.hidden)
		{
			closeMenu(false);
			return;
		}
		openMenu(event.detail === 0 || document.activeElement === menuButton ? 'first' : null);
	});
	for (let item of menuItems)
	{
		item.addEventListener('click', () =>
		{
			closeMenu(false);
			apply((text, start, end) => setHeading(text, start, end, Number(item.dataset.level)));
		});
	}
	menu.addEventListener('keydown', (event) =>
	{
		let index = menuItems.indexOf(event.target);
		if (index < 0)
		{
			return;
		}
		let next = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: menuItems.length - 1 }[event.key];
		if (next !== undefined)
		{
			event.preventDefault();
			menuItems[(next + menuItems.length) % menuItems.length].focus();
		}
		else if (event.key === 'Escape' || event.key === 'Tab' || event.key === 'ArrowLeft' || event.key === 'ArrowRight')
		{
			event.preventDefault();
			event.stopPropagation();
			closeMenu(true);
		}
	});
	document.addEventListener('mousedown', (event) =>
	{
		if (!menu.hidden && !menu.contains(event.target) && !menuButton.contains(event.target))
		{
			closeMenu(false);
		}
	});
	menu.addEventListener('focusout', (event) =>
	{
		if (event.relatedTarget && !menu.contains(event.relatedTarget) && event.relatedTarget !== menuButton)
		{
			closeMenu(false);
		}
	});

	// the table: of the selected lines, or of the size chosen in the dialog, at the selection which the text had
	let tableButton = document.getElementById('tablebutton');
	let columnsInput = document.getElementById('tablecolumns');
	let rowsInput = document.getElementById('tablerows');
	let selection = null;
	let insertTableOf = (columns, rows) => apply((text, start, end) => insertTable(text, start, end, columns, rows, dialog.dataset.msgColumn));
	tableButton.addEventListener('click', () =>
	{
		let converted = tableOfSelection(textArea.value, textArea.selectionStart, textArea.selectionEnd);
		if (converted)
		{
			applyChange(textArea, converted);
		}
		else if (!dialog.showModal)
		{
			insertTableOf(Number(columnsInput.defaultValue), Number(rowsInput.defaultValue));
		}
		else
		{
			selection = [textArea.selectionStart, textArea.selectionEnd];
			dialog.returnValue = '';
			dialog.showModal();
			columnsInput.focus();
			columnsInput.select();
		}
	});
	document.getElementById('tablecancel').addEventListener('click', () => dialog.close());
	// (however it is closed: Insert sends its form, which closes it with the value of the button)
	dialog.addEventListener('close', () =>
	{
		if (!selection)
		{
			return;
		}
		textArea.setSelectionRange(selection[0], selection[1]);
		selection = null;
		if (dialog.returnValue === 'insert')
		{
			insertTableOf(numberIn(columnsInput), numberIn(rowsInput));
		}
		else
		{
			textArea.focus();
		}
	});

	document.getElementById('drawer-control').addEventListener('click', toggleDrawer);
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
	let chooseUploads = setupUpload(document.getElementById('uploaddialog'), document.getElementById('text'));
	let form = document.getElementById("edit");
	if (!form)
	{
		// (the upload page: files dropped anywhere are uploaded)
		let dropHint = document.querySelector('.uploadaction[data-msg-drop]');
		if (chooseUploads && dropHint)
		{
			setupDrops(null, { message: dropHint.dataset.msgDrop, files: chooseUploads, uploadFiles: chooseUploads });
		}
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
	// the draft is meant to come back with the page (the forms which reload it, see leaveWithDraft()), and is not only offered
	let restoreNext = false;
	// the visitor has chosen to leave with the Cancel button: the draft is not kept when the page is left
	// (if the visitor stays after all, the next interaction with the page ends this)
	let abandoning = false;
	// (see setupPreview(), after the draft is restored)
	let preview = null;

	// autosave the current content as local draft, so that it survives
	// the tab being unloaded (e.g. by mobile browsers after inactivity):
	function saveDraft()
	{
		// (not when the visitor is leaving with Cancel: the page is hidden, which saves the draft, before it is removed)
		if (!modified || abandoning)
		{
			return;
		}
		writeDraft(key, {
			text: textArea.value,
			title: titleInput ? titleInput.value : "",
			gitmsg: gitmsgInput ? gitmsgInput.value : "",
			base: draftBase,
			restore: restoreNext,
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
				abandoning = false;
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
	addEventListener('pagehide', () =>
	{
		// (a saved draft which was only offered, and not touched, stays)
		if (abandoning && modified)
		{
			removeDraft(key);
		}
		else
		{
			saveDraft();
		}
	});
	for (let type of ['pointerdown', 'keydown'])
	{
		document.addEventListener(type, () => { abandoning = false; }, true);
	}
	// (back from a failed save to this page from the browser's cache: its text is still unsaved)
	addEventListener('pageshow', (event) =>
	{
		if (event.persisted && readDraft(key))
		{
			modified = true;
		}
	});

	// puts the text of a draft into the editor (typed: it can be undone)
	function applyDraft(draft, typed)
	{
		draftBase = draft.base;
		if (typed)
		{
			textArea.focus();
			textArea.select();
			insertTyped(textArea, draft.text);
		}
		else
		{
			textArea.value = draft.text;
		}
		if (titleInput)
		{
			titleInput.value = draft.title;
		}
		if (gitmsgInput && draft.gitmsg)
		{
			gitmsgInput.value = draft.gitmsg;
		}
		modified = true;
		// (the draft is kept, and not only offered the next time)
		saveDraft();
		if (preview)
		{
			preview.update();
		}
	}

	// offer a previously autosaved draft:
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
		else if (draft.restore)
		{
			// (the page was reloaded by a form of the editor, the text must not go: no offer, it comes back)
			applyDraft(draft, false);
		}
		else
		{
			// the saved draft is only offered: the editor shows the text of the page, until the visitor applies the draft
			// (it is kept until it is applied, discarded, or replaced by text typed now)
			let note = document.createElement("div");
			note.className = "note";
			note.appendChild(document.createTextNode(
				form.dataset.msgAvailable.replace("%s", new Date(draft.time).toLocaleString())));
			if (draft.base !== baseText)
			{
				note.appendChild(document.createElement("br"));
				let warning = document.createElement("strong");
				warning.textContent = form.dataset.msgConflict;
				note.appendChild(warning);
			}
			note.appendChild(document.createTextNode(" "));
			let changes = null;
			let addButton = (label, onclick) =>
			{
				let button = document.createElement("input");
				button.type = "button";
				button.value = label;
				button.addEventListener('click', onclick);
				note.appendChild(button);
				note.appendChild(document.createTextNode(" "));
				return button;
			};
			// (the changes the draft would make to what the editor shows now; a new page has a title as its first line)
			let showButton = addButton(form.dataset.msgShow, () =>
			{
				if (changes)
				{
					changes.remove();
					changes = null;
					showButton.value = form.dataset.msgShow;
					return;
				}
				let current = (titleInput ? titleInput.value + "\n" : "") + textArea.value;
				let offered = (titleInput ? draft.title + "\n" : "") + draft.text;
				changes = diffBox(current, offered, form.dataset.msgUnchanged);
				note.appendChild(changes);
				showButton.value = form.dataset.msgHide;
			});
			addButton(form.dataset.msgApply, () =>
			{
				applyDraft(draft, true);
				note.remove();
			});
			addButton(form.dataset.msgDiscard, () =>
			{
				removeDraft(key);
				note.remove();
			});
			form.parentNode.insertBefore(note, form);
		}
	}

	setupBacklinks(textArea);
	setupIndent(textArea);
	setupFormatKeys(textArea);
	setupFormatBar(textArea);
	let importFiles = setupImport(form, textArea, titleInput);
	// (Markdown and text files are inserted as text, other files uploaded; without uploads, the others are refused with a message)
	setupDrops(textArea, {
		message: form.dataset.msgDrop,
		files: (files) =>
		{
			let texts = chooseUploads ? files.filter(isTextFile) : files;
			let others = chooseUploads ? files.filter((file) => !isTextFile(file)) : [];
			if (texts.length > 0)
			{
				importFiles(texts);
			}
			if (others.length > 0)
			{
				chooseUploads(others);
			}
		},
		image: (data) => imageMarkdown(form, draggedImage(data)),
		uploadFiles: chooseUploads
	});
	preview = setupPreview(form, textArea);
	setupPreviewShortcut(textArea);

	// (the draft is only removed once the server reports that the text was saved, see data-saved-draft)
	function leaveWithDraft(restore)
	{
		restoreNext = restore === true;
		saveDraft();
		modified = false;
	}
	form.addEventListener('submit', () => leaveWithDraft(false));
	setupSaveShortcut(form);
	// (the forms which link a mention in another page reload this page, which restores the draft: no warning needed)
	for (let linkForm of document.querySelectorAll('form.linkmention'))
	{
		// (the page is shown again with the text: the draft comes back without being asked for)
		linkForm.addEventListener('submit', () => leaveWithDraft(true));
	}
	// (the button goes back; the warning about unsaved changes asks for confirmation, and only if that is given,
	// the draft is removed - when the page is hidden, which is the last chance to keep it from being written again)
	document.getElementById("cancel").addEventListener('click', () =>
	{
		abandoning = true;
	});
});
