<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The differences between a version of a page and the one before it, or its current text (inside version).
 *
 * Values: legend (text: what is compared, and which lines are which), lines (Html, or null if nothing differs or the
 *         texts can't be compared: the lines, see diff-lines), message (text: why there are no lines, or '')
 */
?>
<?php if ($this->lines !== null): ?>
<div class="diff"><?= $this->legend ?>
<?= $this->lines ?></div>
<?php else: ?>
<p class="nodiff"><?= $this->message ?></p>
<?php endif ?>
