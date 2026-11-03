<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The icons to do something with a page: edit, delete, rename, show the pages which link to it.
 *
 * Values: items (list of: href, icon (Html, see iconSvg()), label)
 */
?>
<?php foreach ($this->items as $item): ?>
      <a href="<?= $item['href'] ?>"><?= $item['icon'] ?></a>
<?php endforeach ?>
