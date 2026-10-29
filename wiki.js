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
 * Uploading files from the editor: the Upload button, files dropped on the text and images pasted into it.
 * The link to each file is inserted at the cursor; the text of the editor is not touched otherwise.
 */
function setupUpload(form, textArea)
{
	let button = document.getElementById('uploadbutton');
	if (!button)
	{
		return;
	}
	let fileInput = document.getElementById('uploadfile');
	let status = document.getElementById('uploadstatus');
	let resizeInput = document.getElementById('uploadresize');
	let maxSizeInput = document.getElementById('uploadmaxsize');
	let tokenInput = form.querySelector('input[name="csrf_token"]');
	let queue = [];
	let busy = false;

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
		if (resizeInput)
		{
			data.append('resize', resizeInput.checked ? 'true' : '');
			data.append('maxsize', maxSizeInput.value);
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

	async function processQueue()
	{
		if (busy)
		{
			return;
		}
		busy = true;
		button.disabled = true;
		let links = [];
		try
		{
			while (queue.length > 0)
			{
				let answer = await uploadOne(queue.shift());
				if (answer)
				{
					links.push(answer.link);
				}
			}
			if (links.length > 0)
			{
				insertAtCursor(links.join('\n'));
				show(form.dataset.msgInserted.replace('%s', () => links.join(' ')), false);
			}
		}
		catch (e)
		{
			queue = [];
			// (the files which were stored before the error are still linked)
			if (links.length > 0)
			{
				insertAtCursor(links.join('\n'));
			}
			show(e.message === 'network' ? form.dataset.msgError : e.message, true);
		}
		busy = false;
		button.disabled = false;
	}

	function addFiles(files)
	{
		queue.push(...files);
		processQueue();
	}

	button.addEventListener('click', () => fileInput.click());
	fileInput.addEventListener('change', () =>
	{
		addFiles(Array.from(fileInput.files));
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
			addFiles(Array.from(event.dataTransfer.files));
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
		addFiles(Array.from(clipboard.files).map((file, i) =>
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
	form.addEventListener('submit', () =>
	{
		saveDraft();
		modified = false;
	});
	document.getElementById("cancel").addEventListener('click', () =>
	{
		removeDraft(key);
	});
});
