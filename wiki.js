/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Script of the wiki's pages: the navigation drawer (which can be dragged) and the editor
 * (local drafts, warning about unsaved changes).
 */

function toggleDrawer()
{
	document.getElementById('drawer').classList.toggle('inactive');
}

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
 * The buttons of the pages which link to the new page or mention it: they insert a link to that page at the cursor.
 */
function setupBacklinks(textArea)
{
	for (let button of document.querySelectorAll('button.backlink'))
	{
		button.addEventListener('click', () =>
		{
			textArea.focus();
			let text = '[[' + button.dataset.page + ']]';
			// (as typed text: it can be undone, and counts as a change)
			if (!document.execCommand || !document.execCommand('insertText', false, text))
			{
				textArea.setRangeText(text, textArea.selectionStart, textArea.selectionEnd, 'end');
				textArea.dispatchEvent(new Event('input', { bubbles: true }));
			}
		});
	}
}

/**
 * Uploading files from the editor. The button of the symbol bar opens a dialog to choose a file; files dropped on the
 * text and images pasted into it open the dialog with the files already chosen. Images can be cropped in the dialog
 * (the server does it, with the part to keep sent as fractions of the image) and shrunk, which is shown as the size
 * the image will have. The link to each file is inserted at the cursor; the text of the editor is not touched otherwise.
 */
function setupUpload(form, textArea)
{
	let openButton = document.getElementById('uploadbutton');
	let dialog = document.getElementById('uploaddialog');
	if (!openButton || !dialog || !dialog.showModal)
	{
		return;
	}
	let fileInput = document.getElementById('uploadfile');
	let nameLabel = document.getElementById('uploadname');
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
	let tokenInput = form.querySelector('input[name="csrf_token"]');
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

	function insertAtCursor(text)
	{
		textArea.focus();
		// (as typed text: it can be undone, and counts as a change)
		if (!document.execCommand || !document.execCommand('insertText', false, text))
		{
			textArea.setRangeText(text, textArea.selectionStart, textArea.selectionEnd, 'end');
			textArea.dispatchEvent(new Event('input', { bubbles: true }));
		}
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
		resultSize.textContent = form.dataset.msgResultSize.replace('%s', () => width + ' × ' + height);
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
			? form.dataset.msgCount.replace('%1$s', total - queue.length).replace('%2$s', total) : '';
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

	/** Sends the chosen file; returns the answer of the server, or null when the file already exists and is not replaced */
	async function uploadOne(file)
	{
		let overwrite = false;
		let check = await fetch(form.dataset.checkUrl + '&filename=' + encodeURIComponent(file.name), { credentials: 'same-origin' });
		if (await check.json())
		{
			if (!window.confirm(form.dataset.msgOverwrite.replace('%s', () => file.name)))
			{
				show('', false);
				return null;
			}
			overwrite = true;
		}
		let data = new FormData();
		data.append('csrf_token', tokenInput ? tokenInput.value : '');
		data.append('overwrite', overwrite ? 'true' : '');
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
		let label = form.dataset.msgUploading.replace('%s', () => file.name);
		show(label, false);
		let answer = await sendUpload(form.dataset.uploadUrl, data, (fraction) =>
		{
			show(label + ' (' + Math.round(fraction * 100) + ' %)', false);
		});
		if (!answer)
		{
			throw new Error(form.dataset.msgBadAnswer);
		}
		if (!answer.success)
		{
			throw new Error(answer.message || form.dataset.msgError);
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
			next();
		}
		catch (e)
		{
			// (the file stays in the dialog: the error can be fixed, e.g. by another size, or the file is cancelled)
			busy = false;
			show(e.message === 'network' ? form.dataset.msgError : e.message, true);
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
			insertAtCursor(uploaded.join('\n'));
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
			show('', false);
			dialog.showModal();
		}
	});
	fileInput.addEventListener('change', () =>
	{
		chooseFiles(Array.from(fileInput.files));
		fileInput.value = '';
	});

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
		if (hasFiles(event) && event.dataTransfer.files.length > 0)
		{
			event.preventDefault();
			chooseFiles(Array.from(event.dataTransfer.files));
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

document.addEventListener('DOMContentLoaded', () =>
{
	// the server has saved the text of this draft (the page after saving loads this script for that):
	if (document.body.dataset.savedDraft)
	{
		removeDraft(document.body.dataset.savedDraft);
	}
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
			});
			note.appendChild(discardBtn);
			form.parentNode.insertBefore(note, form);
		}
	}

	setupUpload(form, textArea);
	setupBacklinks(textArea);

	// (the draft is only removed once the server reports that the text was saved, see data-saved-draft)
	function leaveWithDraft()
	{
		saveDraft();
		modified = false;
	}
	form.addEventListener('submit', leaveWithDraft);
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
