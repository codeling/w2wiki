<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The form to edit a page, or to write a new one (inside the main part of page).
 *
 * Values: draftKey (text, for the drafts kept in the browser), unsaved (bool: saving has failed, the text is
 *         not saved), isNew (bool), csrfField (Html),
 *         page (text: the page being edited), newPage (text: the title entered for a new page),
 *         similarPages (null, or the list of pages with a name like the new one: href, name),
 *         pageContext (null, or what other pages say about the new page, see Lists::pageContext(): links (href, name) are the
 *         pages which link to it, mentions (href, name, snippet, baseHash) the pages which write its name without a link),
 *         text (text), rows (number), gitEnabled (bool), gitMessage (text),
 *         uploadDialog (Html, or null if uploads are disabled: the dialog to upload files, see upload-dialog, which comes
 *         after the form so that Enter in it can't save the page),
 *         previewUrl (text: the address which renders the text for the preview, see api.php; the preview and its
 *         button are hidden until the script shows them),
 *         baseHash (text, or null for a new page: identifies the text of the page as it was opened, see Changes::save()),
 *         diff (null, or Html: the lines which differ between the current text of the page and the text in the editor, with
 *         some lines around them, see diff-lines)
 */
?>
<?php if ($this->pageContext !== null && ($this->pageContext['links'] || $this->pageContext['mentions'])): ?>
<div class="pagecontext">
<?php if ($this->pageContext['links']): ?>
<p><?= __('Pages which already link to a page of this name:') ?></p>
<ul>
<?php foreach ($this->pageContext['links'] as $linking): ?>
<li><a href="<?= $linking['href'] ?>"><?= $linking['name'] ?></a> <button type="button" class="backlink" data-page="<?= $linking['name'] ?>" title="<?= __('Insert a link to this page at the cursor') ?>"><?= __('Insert backlink') ?></button></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<?php if ($this->pageContext['mentions']): ?>
<p><?= __('Pages which mention the name without a link:') ?></p>
<ul>
<?php foreach ($this->pageContext['mentions'] as $mention): ?>
<li><a href="<?= $mention['href'] ?>"><?= $mention['name'] ?></a>: <span class="snippet"><?= $mention['snippet'] ?></span>
<button type="button" class="backlink" data-page="<?= $mention['name'] ?>" title="<?= __('Insert a link to this page at the cursor') ?>"><?= __('Insert backlink') ?></button>
<form class="linkmention" method="post" action="<?= $this->e(SELF) ?>"><?= $this->csrfField ?>
<input type="hidden" name="action" value="linkMention" /><input type="hidden" name="page" value="<?= $mention['name'] ?>" />
<input type="hidden" name="target" value="<?= $this->newPage ?>" /><input type="hidden" name="baseHash" value="<?= $mention['baseHash'] ?>" />
<input type="submit" value="<?= __('Link the name in that page') ?>" title="<?= __('Changes that page: the first mention becomes a link to the new page. Text you have typed here stays as a draft.') ?>" /></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
</div>
<?php endif ?>
<form id="edit" method="post" action="<?= $this->e(SELF) ?>" data-draft-key="<?= $this->draftKey ?>"<?= $this->unsaved ? ' data-unsaved="true"' : '' ?> data-msg-restored="<?= __('Restored unsaved draft from %s.') ?>" data-msg-conflict="<?= __('Warning: the page was changed since this draft was started.') ?>" data-msg-discard="<?= __('Discard draft') ?>" data-msg-import-too-large="<?= __('The file %s is larger than 2 MB and was not inserted.') ?>" data-msg-import-not-text="<?= __('The file %s is not a text file (.md, .markdown or .txt) and was not inserted.') ?>">
<?= $this->csrfField ?>

<input type="hidden" name="draftKey" value="<?= $this->draftKey ?>" />
<?php if (!$this->isNew): ?>
<input type="hidden" name="page" value="<?= $this->page ?>" />
<?php if ($this->baseHash !== null): ?>
<input type="hidden" name="baseHash" value="<?= $this->baseHash ?>" />
<?php endif ?>
<?php else: ?>
<?php if ($this->similarPages !== null): ?>
<div class="note"><?= __('Creating new page since no page with given title exists!') ?>
<?php foreach ($this->similarPages as $similar): ?>
<br/><strong><?= __('Note') ?>:</strong> <?= sprintf(__('Found similar page %s. Maybe you meant to edit this instead?'), '<a href="' . $similar['href'] . '">' . $similar['name'] . '</a>') ?>
<?php endforeach ?>
</div>
<?php endif ?>
<p><?= __('Title') ?>: <input id="title" title="<?= __("Character restrictions: '#' and '|' have a special meaning in page links, they will therefore be removed; also, characters '~', '..', '\\', ':', '|', '&' might cause trouble in filenames and are therefore replaced by '-'.") ?>" type="text" name="page" value="<?= $this->newPage ?>" class="pagename" placeholder="<?= __('Name of new page (restrictions in tip)') ?>"/></p>
<?php endif ?>
<?php if ($this->diff !== null): ?>
<div class="diff"><?= __('The current version of the page (lines marked -) compared with your text (lines marked +):') ?>
<?= $this->diff ?></div>
<?php endif ?>
<div class="editorbox"><textarea id="text" name="newText" rows="<?= $this->rows ?>" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" aria-describedby="texthint"><?= $this->text ?></textarea><span id="texthint" class="visuallyhidden"><?= __('Tab indents lines, Shift+Tab outdents them. To leave the text with Tab, press Esc first.') ?></span>
<div id="preview" class="preview" role="region" aria-label="<?= __('Preview') ?>" hidden="hidden" data-url="<?= $this->previewUrl ?>" data-msg-unavailable="<?= __('No preview:') ?>" data-msg-error="<?= __('The server did not answer as expected.') ?>" data-msg-offline="<?= __('The server cannot be reached.') ?>"><p class="previewstatus" role="status"></p><div class="previewcontent"></div></div></div>
<?php if ($this->gitEnabled): ?>
<p><?= __('Message') ?>: <input type="text" id="gitmsg" name="gitmsg" value="<?= $this->gitMessage ?>" /></p>
<?php endif ?>
<p class="editactions"><input type="hidden" name="action" value="save" />
<input type="hidden" name="isNew" value="<?= $this->isNew ? 'true' : '' ?>" />
<input id="save" type="submit" value="<?= __('Save') ?>" title="<?= __('Save (Ctrl+S)') ?>" aria-keyshortcuts="Control+S Meta+S Control+Enter Meta+Enter" />
<input id="cancel" type="button" onclick="<?= HANDLER_GO_BACK ?>" value="<?= __('Cancel') ?>" />
<input id="importbutton" type="button" value="<?= __('Insert text from file…') ?>" title="<?= __('Insert the text of a Markdown or text file at the cursor (also by dropping the file on the text)') ?>" />
<input id="importfile" type="file" multiple="multiple" accept=".md,.markdown,.txt,text/markdown,text/plain" hidden="hidden" />
<input id="previewbutton" type="button" value="<?= __('Preview') ?>" title="<?= __('Show the text as it will look in the page (next to the text on wide screens, instead of it on narrow ones)') ?>" aria-pressed="false" aria-controls="preview" aria-keyshortcuts="Control+Alt+P Meta+Alt+P" hidden="hidden" />
</p></form>
<?= $this->uploadDialog ?>
