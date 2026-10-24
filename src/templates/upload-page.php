<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The page for uploading files (inside the main part of page): the form, and the list of the uploaded files.
 *
 * Values: form (Html, or null if uploads are disabled), list (Html)
 */
?>
<?php if ($this->form === null): ?>
<p><?= __('Image uploading has been disabled on this installation.') ?></p>
<?php else: ?>
<?= $this->form ?>
<?php endif ?>
<?= $this->list ?>
