
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

document.addEventListener('DOMContentLoaded', () =>
{
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

	// (this script is only loaded where the editor is, see isEditorAction())
	let form = document.getElementById("edit");
	let textArea = document.getElementById("text");
	let titleInput = document.getElementById("title");
	let gitmsgInput = document.getElementById("gitmsg");
	let key = form.dataset.draftKey;

	// content as delivered by the server:
	let baseText = textArea.value;
	let baseTitle = titleInput ? titleInput.value : "";
	let baseGitmsg = gitmsgInput ? gitmsgInput.value : "";

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
			base: baseText,
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

	// restore a previously autosaved draft:
	let draft = readDraft(key);
	if (draft)
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

	form.addEventListener('submit', () =>
	{
		modified = false;
		removeDraft(key);
	});
	document.getElementById("cancel").addEventListener('click', () =>
	{
		removeDraft(key);
	});
});
