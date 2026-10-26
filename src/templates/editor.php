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
 *         text (text), rows (number), gitEnabled (bool), gitMessage (text),
 *         uploads (bool: files can be uploaded from the editor), shrink (bool: uploaded images can be shrunk),
 *         apiUrl (text: the address of api.php, which tells whether an uploaded file exists already),
 *         baseHash (text, or null for a new page: identifies the text of the page as it was opened, see Changes::save()),
 *         diff (null, or the lines which differ between the current text of the page and the text in the editor, with some
 *         lines around them: see LineDiff::withContext(); each is a list of the kind (LineDiff::SAME, REMOVED, ADDED,
 *         GAP) and the line, or for a gap the number of lines left out)
 */
?>
<form id="edit" method="post" action="<?= $this->e(SELF) ?>" data-draft-key="<?= $this->draftKey ?>"<?= $this->unsaved ? ' data-unsaved="true"' : '' ?> data-msg-restored="<?= __('Restored unsaved draft from %s.') ?>" data-msg-conflict="<?= __('Warning: the page was changed since this draft was started.') ?>" data-msg-discard="<?= __('Discard draft') ?>"<?php if ($this->uploads): ?> data-upload-url="<?= $this->e(SELF) ?>?action=uploaded&amp;json=1" data-check-url="<?= $this->e($this->apiUrl) ?>?task=checkupload" data-msg-uploading="<?= __('Uploading %s') ?>" data-msg-overwrite="<?= __('File %s already exists. Overwrite?') ?>" data-msg-inserted="<?= __('Inserted %s') ?>" data-msg-error="<?= __('Upload error') ?>" data-msg-bad-answer="<?= __('The server did not answer as expected (maybe the session has expired). Please reload the page; the text is kept as a draft.') ?>"<?php endif ?>>
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
<pre><?php foreach ($this->diff as $line): ?>
<?php if ($line[0] === 'gap'): ?>
<span class="gap">@@ <?= sprintf(__('%s unchanged lines'), $line[1]) ?> @@</span>
<?php elseif ($line[0] === 'removed'): ?>
<span class="removed">- <?= $line[1] ?></span>
<?php elseif ($line[0] === 'added'): ?>
<span class="added">+ <?= $line[1] ?></span>
<?php else: ?>
<span class="same">  <?= $line[1] ?></span>
<?php endif ?>
<?php endforeach ?></pre></div>
<?php endif ?>
<?php if ($this->uploads): ?>
<p class="uploadbar"><input id="uploadbutton" type="button" value="<?= __('Upload') ?>" title="<?= __('Upload a file and insert a link to it at the cursor (also by dropping files on the text or pasting an image)') ?>" /><input id="uploadfile" type="file" multiple="multiple" hidden="hidden" />
<?php if ($this->shrink): ?>
<input id="uploadresize" type="checkbox" checked="checked" /><label for="uploadresize"><?= __('Shrink if larger than ') ?></label><input id="uploadmaxsize" type="number" min="20" max="8192" value="1200" /> <label for="uploadmaxsize"><?= __('Pixels') ?></label>
<?php endif ?>
<span id="uploadstatus" role="status"></span></p>
<?php endif ?>
<p class="editorbox"><textarea id="text" name="newText" rows="<?= $this->rows ?>" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"><?= $this->text ?></textarea></p>
<?php if ($this->gitEnabled): ?>
<p><?= __('Message') ?>: <input type="text" id="gitmsg" name="gitmsg" value="<?= $this->gitMessage ?>" /></p>
<?php endif ?>
<p class="editactions"><input type="hidden" name="action" value="save" />
<input type="hidden" name="isNew" value="<?= $this->isNew ? 'true' : '' ?>" />
<input id="save" type="submit" value="<?= __('Save') ?>" />
<input id="cancel" type="button" onclick="<?= HANDLER_GO_BACK ?>" value="<?= __('Cancel') ?>" />
</p></form>
