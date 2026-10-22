<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The page of the wiki (inside layout): title bar, toolbar, sidebar, and the main part with the note.
 *
 * Values: title (text), modified (text, or '' for pages without a date), pageActions (Html or null),
 *         toolbar (list of items: href, icon (address, or null for a link with a text only), label),
 *         drawer (Html or null), sidebar (Html or null),
 *         linksHere (null, or a list of pages: href, name), note (Html or null), content (Html)
 */
?>
    <div class="titlebar"><span class="title"><?= $this->title ?></span><?php if ($this->modified !== ''): ?><span class="titledate"><?= $this->modified ?></span><?php endif ?><?= $this->pageActions ?>

    </div>
    <div class="toolbar">
<?php foreach ($this->toolbar as $item): ?>
<?php if ($item['icon'] !== null): ?>
      <a href="<?= $item['href'] ?>"><img src="<?= $item['icon'] ?>" alt="<?= $item['label'] ?>" title="<?= $item['label'] ?>" class="icon"></a>
<?php else: ?>
      <a href="<?= $item['href'] ?>"><?= $item['label'] ?></a>
<?php endif ?>
<?php endforeach ?>
<?php /* (a GET request: the address of the results can be bookmarked and shared, and reloading them doesn't ask
          to send the form again; the action is a field of the form, as a query in "action" would be dropped) */ ?>
      <form method="get" action="<?= $this->e(SELF) ?>">
        <input type="hidden" name="action" value="search" />
        <input class="search" placeholder="<?= __('Search') ?>" size="20" id="search" type="text" name="q" />
      </form>
<?= $this->drawer ?>
    </div>
<?php if ($this->sidebar !== null): ?>
    <div class="sidebar">

<?= $this->sidebar ?>
    </div>
<?php endif ?>
<?php if ($this->linksHere !== null): ?>
    <div class="linkshere"><?= __('What links here:') ?><ul>
<?php foreach ($this->linksHere as $linked): ?>
        <li><a href="<?= $linked['href'] ?>"><?= $linked['name'] ?></a></li>
<?php endforeach ?>
</ul></div>
<?php endif ?>
    <div class="main">

<?php if ($this->note !== null): ?>
      <div class="note"><?= $this->note ?></div>
<?php endif ?>
<?= $this->content ?>

    </div>
