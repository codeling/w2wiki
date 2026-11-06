<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * What the editor of a new page tells about the title which has been entered (the script of the editor asks api.php for
 * it when the title changes, and when the editor is opened with a title), see Lists::newPageHints().
 *
 * Values: csrfField (Html), name (text: the title as it would be saved),
 *         exists (null, or the page of that name: href, editHref, name),
 *         similarPages (the list of pages with a name like it: href, name),
 *         pageContext (what other pages say about it, see Lists::pageContext(): links (href, name) are the
 *         pages which link to it, mentions (href, name, snippet, baseHash) the pages which write its name without a link)
 */
?>
<?php if ($this->exists !== null): ?>
<div class="note pageexists"><?= sprintf(__('A page with the title %s exists already. Please choose a different title, or %s that page instead.'), '<a href="' . $this->exists['href'] . '">' . $this->exists['name'] . '</a>', '<a href="' . $this->exists['editHref'] . '">' . __('edit') . '</a>') ?></div>
<?php else: ?>
<div class="note"><?= __('Creating new page since no page with given title exists!') ?>
<?php foreach ($this->similarPages as $similar): ?>
<br/><strong><?= __('Note') ?>:</strong> <?= sprintf(__('Found similar page %s. Maybe you meant to edit this instead?'), '<a href="' . $similar['href'] . '">' . $similar['name'] . '</a>') ?>
<?php endforeach ?>
</div>
<?php if ($this->pageContext['links'] || $this->pageContext['mentions']): ?>
<details class="pagecontext">
<summary><?= sprintf(__('There are %s pages which link to this name or mention it'), count($this->pageContext['links']) + count($this->pageContext['mentions'])) ?></summary>
<?php if ($this->pageContext['links']): ?>
<p><?= __('Pages which already link to a page of this name:') ?></p>
<ul>
<?php foreach ($this->pageContext['links'] as $linking): ?>
<li><a href="<?= $linking['href'] ?>"><?= $linking['name'] ?></a> <button type="button" class="backlink" data-page="<?= $linking['name'] ?>" title="<?= __('Insert a link to this page at the cursor') ?>"><?= __('Insert backlink') ?></button></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<?php if ($this->pageContext['mentions']): ?>
<p><?= __('Pages which mention the name without a link:') ?></p>
<ul>
<?php foreach ($this->pageContext['mentions'] as $mention): ?>
<li><a href="<?= $mention['href'] ?>"><?= $mention['name'] ?></a>: <span class="snippet"><?= $mention['snippet'] ?></span>
<button type="button" class="backlink" data-page="<?= $mention['name'] ?>" title="<?= __('Insert a link to this page at the cursor') ?>"><?= __('Insert backlink') ?></button>
<form class="linkmention" method="post" action="<?= $this->e(SELF) ?>"><?= $this->csrfField ?>
<input type="hidden" name="action" value="linkMention" /><input type="hidden" name="page" value="<?= $mention['name'] ?>" />
<input type="hidden" name="target" value="<?= $this->name ?>" /><input type="hidden" name="baseHash" value="<?= $mention['baseHash'] ?>" />
<input type="submit" value="<?= __('Link the name in that page') ?>" title="<?= __('Changes that page: the first mention becomes a link to the new page. Text you have typed here stays as a draft.') ?>" /></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
</details>
<?php endif ?>
<?php endif ?>
