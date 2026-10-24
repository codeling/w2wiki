<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The result of a search (inside the main part of page).
 *
 * Values: heading (text), searched (bool: a term was entered), results (list of: href, name, exact (bool: the name
 *         is the term)), create (null, or the link to create a page with the name which was searched: href, label),
 *         matches (number)
 */
?>
    <h1><?= $this->heading ?></h1>
<?php if ($this->searched): ?>
    <ul>
<?php foreach ($this->results as $result): ?>
        <li><a href="<?= $result['href'] ?>"<?= $result['exact'] ? ' class="literalMatch"' : '' ?>><?= $result['name'] ?></a></li>
<?php endforeach ?>
<?php if ($this->create !== null): ?>
        <li><a href="<?= $this->create['href'] ?>" class="noexist"><?= $this->create['label'] ?></a></li>
<?php endif ?>
      </ul>
<?php endif ?>
      <p><?= $this->matches ?> <?= __('matches') ?></p>
