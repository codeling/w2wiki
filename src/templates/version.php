<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * An old version of a page from the git repository, or what changed in it (inside the main part of page): a banner
 * which says which version it is, with links to the other views of the version, and the text or the differences.
 *
 * Values: page (text), pageHref (text), date (text), shortHash (text), subject (text: the first line of the message
 *         of the commit), author (text), links (list of: href (text, or null for the view being shown), label),
 *         body (Html: the text of the version, or the differences, see history-diff)
 */
?>
<div class="oldversion">
<p><strong><?= __('Old version') ?>:</strong> <?= sprintf(__('This is the version of the page %s from %s (%s), not its current text.'), '<a href="' . $this->pageHref . '">' . $this->page . '</a>', $this->date, '<code>' . $this->shortHash . '</code>') ?></p>
<p class="versionmessage"><?= $this->subject ?> (<?= $this->author ?>)</p>
<p class="versionlinks"><?php foreach ($this->links as $link): ?>
<?php if ($link['href'] === null): ?><span class="current"><?= $link['label'] ?></span>
<?php else: ?><a href="<?= $link['href'] ?>"><?= $link['label'] ?></a>
<?php endif ?>
<?php endforeach ?></p>
</div>
<?= $this->body ?>
