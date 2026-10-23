<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The form to edit a page, or to write a new one (inside the main part of page).
 *
 * Values: draftKey (text, for the drafts kept in the browser), isNew (bool), csrfField (Html),
 *         page (text: the page being edited), newPage (text: the title entered for a new page),
 *         similarPages (null, or the list of pages with a name like the new one: href, name),
 *         text (text), rows (number), gitEnabled (bool), gitMessage (text)
 */
?>
<form id="edit" method="post" action="<?= $this->e(SELF) ?>" data-draft-key="<?= $this->draftKey ?>" data-msg-restored="<?= __('Restored unsaved draft from %s.') ?>" data-msg-conflict="<?= __('Warning: the page was changed since this draft was started.') ?>" data-msg-discard="<?= __('Discard draft') ?>">
<?= $this->csrfField ?>

<?php if (!$this->isNew): ?>
<input type="hidden" name="page" value="<?= $this->page ?>" />
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
<p class="editorbox"><textarea id="text" name="newText" rows="<?= $this->rows ?>" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"><?= $this->text ?></textarea></p>
<?php if ($this->gitEnabled): ?>
<p><?= __('Message') ?>: <input type="text" id="gitmsg" name="gitmsg" value="<?= $this->gitMessage ?>" /></p>
<?php endif ?>
<p class="editactions"><input type="hidden" name="action" value="save" />
<input type="hidden" name="isNew" value="<?= $this->isNew ? 'true' : '' ?>" />
<input id="save" type="submit" value="<?= __('Save') ?>" />
<input id="cancel" type="button" onclick="<?= HANDLER_GO_BACK ?>" value="<?= __('Cancel') ?>" />
</p></form>
