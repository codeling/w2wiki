<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The headings of a table which can be sorted by some of its columns (one cell each).
 *
 * Values: columns (list of: label, href (the address to sort by this column, or null), current (bool: the table is
 *         sorted by this column, which is shown without a link))
 */
?>
<?php foreach ($this->columns as $column): ?>
<th><?php if ($column['href'] !== null): ?><a href="<?= $column['href'] ?>"><?= $column['label'] ?></a><?php elseif ($column['current']): ?><span class="sortBy"><?= $column['label'] ?></span><?php else: ?><?= $column['label'] ?><?php endif ?></th>
<?php endforeach ?>
