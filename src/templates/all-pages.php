<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The list of all pages (inside the main part of page).
 *
 * Values: total (number), head (Html: the heading cells, see table-head; narrow screens don't show the size, see
 *         "wideonly" in index.css, and show each row in two lines: the name, then the date and the icons),
 *         rows (list of: href, name, modified (text), size (text), actions (Html: see page-actions))
 */
?>
<p><?= __('Total') ?>: <?= $this->total ?> <?= __("pages") ?></p><table class="pagelist"><thead><tr><?= $this->head ?></tr></thead><tbody>
<?php foreach ($this->rows as $row): ?>
<tr><td class="pagename"><a href="<?= $row['href'] ?>"><?= $row['name'] ?></a></td><td valign="top" class="modified"><nobr><?= $row['modified'] ?></nobr></td><td valign="top" class="wideonly"><nobr><?= $row['size'] ?></nobr></td><td class="pageActions"><?= $row['actions'] ?></td></tr>
<?php endforeach ?>
</tbody></table>
