<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The list of the uploaded files (inside the main part of page).
 *
 * Values: total (number), head (Html: the heading cells, see table-head), showUsage (bool: where the files are used
 *         is shown), rows (list of: thumbnail (the address of the image, or null for files which are no images), name,
 *         markdown (text: how to refer to the file in a page), modified (text), size (text), renameHref, deleteHref,
 *         pages (list of: href, name))
 */
?>
<p><?= __('Total') ?>: <?= $this->total ?> <?= __('images') ?></p><table><thead><tr><?= $this->head ?></tr></thead><tbody>
<?php foreach ($this->rows as $row): ?>
<tr><td><?php if ($row['thumbnail'] !== null): ?><img class="thumbImg" src="<?= $row['thumbnail'] ?>" /><?php else: ?><span class="thumbPlaceHolder"></span><?php endif ?><span class="uploadFileName"><?= $row['name'] ?></span></td><td><pre><?= $row['markdown'] ?></pre></td><td><nobr><?= $row['modified'] ?></nobr></td><td><nobr><?= $row['size'] ?></nobr></td><td><a href="<?= $row['renameHref'] ?>"><img src="<?= $this->e(assetURL("w2-icons/rename-dark.svg")) ?>" alt="<?= __('Rename') ?>" title="<?= __('Rename') ?>" class="icon"/></a><a href="<?= $row['deleteHref'] ?>"><img src="<?= $this->e(assetURL("w2-icons/delete.svg")) ?>" alt="<?= __('Delete') ?>" title="<?= __('Delete') ?>" class="icon"/></a></td><?php if ($this->showUsage): ?><td><?php foreach ($row['pages'] as $page): ?><a href="<?= $page['href'] ?>"><?= $page['name'] ?></a><?php endforeach ?></td><?php endif ?></tr>
<?php endforeach ?>
</tbody></table>
