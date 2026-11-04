<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The dialog of the formatting toolbar (see format-bar) which asks for the size of a table to insert at the cursor: the
 * number of columns and of the rows below the header row (a table of PHP Markdown Extra always has one). It comes after
 * the form of the editor, so that Enter in it can't save the page; the script (setupFormatBar() in wiki.js) opens it and
 * inserts the table, whose header cells are named like data-msg-column (with the number of the column for %s).
 *
 * Values: none
 */
?>
<dialog id="tabledialog" class="tabledialog" aria-labelledby="tabletitle" data-msg-column="<?= __('Column %s') ?>">
<form method="dialog">
<h5 id="tabletitle"><?= __('Insert table') ?></h5>
<p><label for="tablecolumns"><?= __('Columns') ?></label> <input id="tablecolumns" type="number" min="1" max="20" value="3" required="required" /></p>
<p><label for="tablerows"><?= __('Rows below the header row') ?></label> <input id="tablerows" type="number" min="1" max="100" value="3" required="required" /></p>
<p class="hint"><?= __('The first row of a table is its header row: replace the names of the columns.') ?></p>
<p class="dialogactions"><button type="submit" id="tableinsert" value="insert"><?= __('Insert') ?></button> <button type="button" id="tablecancel"><?= __('Cancel') ?></button></p>
</form>
</dialog>
