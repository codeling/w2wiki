<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The page for uploading files (inside the main part of page): the button which opens the dialog to upload files
 * (the same as in the editor; files dropped on the page open it too, with the hint of data-msg-drop shown while they
 * are dragged over it), and the list of the uploaded files.
 *
 * Values: dialog (Html, or null if uploads are disabled: see upload-dialog), list (Html)
 */
?>
<?php if ($this->dialog === null): ?>
<p><?= __('Image uploading has been disabled on this installation.') ?></p>
<?php else: ?>
<p class="uploadaction" data-msg-drop="<?= __('Drop files to upload them') ?>"><input id="uploadbutton" type="button" value="<?= __('Upload') ?>" /></p>
<?= $this->dialog ?>
<?php endif ?>
<?= $this->list ?>
