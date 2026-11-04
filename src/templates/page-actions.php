<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The icons to do something with a page: edit, delete, rename, show its history (with git), show the pages which link to it.
 *
 * Values: items (list of: href, icon (Html, see iconSvg()), label, shortcut (text: the key of the keyboard shortcut
 *         which follows the link, see setupShortcuts() in wiki.js; or null))
 */
?>
<?php foreach ($this->items as $item): ?>
      <a href="<?= $item['href'] ?>"<?php if ($item['shortcut'] !== null): ?> data-shortcut="<?= $item['shortcut'] ?>" aria-keyshortcuts="<?= $item['shortcut'] ?>"<?php endif ?>><?= $item['icon'] ?></a>
<?php endforeach ?>
