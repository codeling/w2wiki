<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The icons to do something with a page: edit, delete, rename, show the pages which link to it.
 *
 * Values: items (list of: href, icon (address), label)
 */
?>
<?php foreach ($this->items as $item): ?>
      <a href="<?= $item['href'] ?>"><img src="<?= $item['icon'] ?>" alt="<?= $item['label'] ?>" title="<?= $item['label'] ?>" class="icon"></a>
<?php endforeach ?>
