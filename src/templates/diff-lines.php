<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The lines which differ between two texts, with some lines around them (in a box of the class "diff"): in the editor
 * after somebody else has changed the page, and in the history of a page.
 *
 * Values: lines (see LineDiff::withContext(): each is a list of the kind (LineDiff::SAME, REMOVED, ADDED, GAP) and the
 *         line, or for a gap the number of lines left out)
 */
?>
<?php /* (no line breaks between the lines, which are blocks: they would be shown as empty lines in the pre element) */ ?>
<pre><?php foreach ($this->lines as $line): ?>
<?php if ($line[0] === 'gap'): ?>
<span class="gap">@@ <?= sprintf(__('%s unchanged lines'), $line[1]) ?> @@</span><?php elseif ($line[0] === 'removed'): ?>
<span class="removed">- <?= $line[1] ?></span><?php elseif ($line[0] === 'added'): ?>
<span class="added">+ <?= $line[1] ?></span><?php else: ?>
<span class="same">  <?= $line[1] ?></span><?php endif ?>
<?php endforeach ?></pre>
