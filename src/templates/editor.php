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
 *         baseHash (text, or null for a new page: identifies the text of the page as it was opened, see Changes::save()),
 *         diff (null, or the lines which differ between the current text of the page and the text in the editor, with some
 *         lines around them: see LineDiff::withContext(); each is a list of the kind (LineDiff::SAME, REMOVED, ADDED,
 *         GAP) and the line, or for a gap the number of lines left out)
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
<form id="edit" method="post" action="<?= $this->e(SELF) ?>" data-draft-key="<?= $this->draftKey ?>"<?= $this->unsaved ? ' data-unsaved="true"' : '' ?> data-msg-restored="<?= __('Restored unsaved draft from %s.') ?>" data-msg-conflict="<?= __('Warning: the page was changed since this draft was started.') ?>" data-msg-discard="<?= __('Discard draft') ?>">
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
<p class="editorbox"><textarea id="text" name="newText" rows="<?= $this->rows ?>" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"><?= $this->text ?></textarea></p>
<?php if ($this->gitEnabled): ?>
<p><?= __('Message') ?>: <input type="text" id="gitmsg" name="gitmsg" value="<?= $this->gitMessage ?>" /></p>
<?php endif ?>
<p class="editactions"><input type="hidden" name="action" value="save" />
<input type="hidden" name="isNew" value="<?= $this->isNew ? 'true' : '' ?>" />
<input id="save" type="submit" value="<?= __('Save') ?>" />
<input id="cancel" type="button" onclick="<?= HANDLER_GO_BACK ?>" value="<?= __('Cancel') ?>" />
</p></form>
<?= $this->uploadDialog ?>
