<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The form to rename or delete a page or an image (inside the main part of page).
 *
 * Values: id (text: the action, rename, delete, imgRename or imgDelete), label (text: the name of the action),
 *         name (text: of the page or image), askNewName (bool: renaming), nextAction (text), csrfField (Html),
 *         prevPage (text: the page to return to from an image, or null)
 */
?>
<form id="<?= $this->id ?>" method="post" action="<?= $this->e(SELF) ?>"><?= $this->csrfField ?><p><?= $this->label ?> <?= $this->name ?> <?php if ($this->askNewName): ?><?= __('to') ?> <input id="newName" type="text" name="newName" value="<?= $this->name ?>" class="pagename" /><?php else: ?>?<?php endif ?></p><p><input id="<?= $this->id ?>" type="submit" value="<?= $this->label ?>"><input id="cancel" type="button" onclick="<?= HANDLER_GO_BACK ?>" value="<?= __('Cancel') ?>" />
<input type="hidden" name="action" value="<?= $this->nextAction ?>" /><input type="hidden" name="oldPageName" value="<?= $this->name ?>" /><?php if ($this->prevPage !== null): ?><input type="hidden" name="prevpage" value="<?= $this->prevPage ?>" /><?php endif ?></p></form>
