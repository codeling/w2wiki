<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The versions of a page in the git repository, newest first, a part of them at a time (inside the main part of page).
 *
 * Values: total (number of versions), first, last (numbers: the versions shown, counted from the newest),
 *         newerHref, olderHref (text, or null if there are none: the parts of the list with newer and older versions),
 *         rows (list of: date (text), subject (text: the first line of the message), author (text), shortHash (text),
 *         deleted (bool: the page was deleted in this version, which has no links), versionHref, changesHref (what
 *         this version changed), compareHref (this version compared with the current text): text)
 */
?>
<?php if ($this->total === 0): ?>
<p><?= __('No versions of this page were found in the git repository.') ?></p>
<?php else: ?>
<p class="historynav"><?= sprintf(__('Versions %s to %s of %s'), $this->first, $this->last, $this->total) ?>
<?php if ($this->newerHref !== null): ?> <a href="<?= $this->newerHref ?>" rel="prev"><?= __('Newer versions') ?></a><?php endif ?>
<?php if ($this->olderHref !== null): ?> <a href="<?= $this->olderHref ?>" rel="next"><?= __('Older versions') ?></a><?php endif ?></p>
<table class="history"><thead><tr><th><?= __('Date') ?></th><th><?= __('Message') ?></th><th><?= __('Author') ?></th><th><?= __('Version') ?></th><th><?= __('Compare') ?></th></tr></thead><tbody>
<?php foreach ($this->rows as $row): ?>
<?php if ($row['deleted']): ?>
<tr class="deleted"><td><?= $row['date'] ?></td><td><?= $row['subject'] ?> <em>(<?= __('the page was deleted') ?>)</em></td><td><?= $row['author'] ?></td><td><code><?= $row['shortHash'] ?></code></td><td></td></tr>
<?php else: ?>
<tr><td><a href="<?= $row['versionHref'] ?>"><?= $row['date'] ?></a></td><td><?= $row['subject'] ?></td><td><?= $row['author'] ?></td><td><code><?= $row['shortHash'] ?></code></td><td class="historylinks"><a href="<?= $row['changesHref'] ?>"><?= __('Changes') ?></a> <a href="<?= $row['compareHref'] ?>"><?= __('With current text') ?></a></td></tr>
<?php endif ?>
<?php endforeach ?>
</tbody></table>
<?php endif ?>
