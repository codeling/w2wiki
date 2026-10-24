<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The list of all pages (inside the main part of page).
 *
 * Values: total (number), head (Html: the heading cells, see table-head),
 *         rows (list of: href, name, modified (text), size (text), actions (Html: see page-actions))
 */
?>
<p><?= __('Total') ?>: <?= $this->total ?> <?= __("pages") ?></p><table><thead><tr><?= $this->head ?></tr></thead><tbody>
<?php foreach ($this->rows as $row): ?>
<tr><td><a href="<?= $row['href'] ?>"><?= $row['name'] ?></a></td><td valign="top"><nobr><?= $row['modified'] ?></nobr></td><td valign="top"><nobr><?= $row['size'] ?></nobr></td><td class="pageActions"><?= $row['actions'] ?></td></tr>
<?php endforeach ?>
</tbody></table>
