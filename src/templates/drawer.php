<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The help for the Markdown syntax next to the editor, and the buttons to show it (inside the toolbar).
 * The texts are translated, and the examples show the syntax as it is written.
 *
 * Values: uploadUrl (text: the address of the uploads folder, with slashes at both ends),
 *         uploads (bool: files can be uploaded from the editor, which adds the button for that)
 */
?>
      <div id="drawer" class="inactive">
        <a href="" onclick="<?= HANDLER_TOGGLE_DRAWER ?>"><?= iconSvg('close', translate('Close'), 'rightaligned') ?></a>
        <h5><?= __('Markdown Syntax Helper') ?></h5>
        <div>
# <?= __('Header') ?> 1<br/>## <?= __('Header') ?> 2<br/>### <?= __('Header') ?> 3<br/>#### <?= __('Header') ?> 4<br/>##### <?= __('Header') ?> 5<br/>###### <?= __('Header') ?> 6<br/>
<br/>
*<?= __('Emphasize') ?>* - <em><?= __('Emphasize') ?></em><br/>_<?= __('Emphasize') ?>_ - <em><?= __('Emphasize') ?></em><br/>**<?= __('Bold') ?>** - <strong><?= __('Bold') ?></strong><br/>__<?= __('Bold') ?>__ - <strong><?= __('Bold') ?></strong><br/>
<br/>
[[<?= __('Link to page') ?>]]<br/>![[<?= __('Link to page') ?>]]<br/>&lt;http://example.com/&gt;<br/>[<?= __('link text') ?>](http://url)<br/><br/>![<?= __('Alt text') ?>](<?= $this->uploadUrl ?>image.jpg)<br/>![<?= __('Alt text') ?>](<?= $this->uploadUrl ?>image.jpg "<?= __('Optional title') ?>")<br/>
<br/>
- <?= __('Unordered list') ?><br/>+ <?= __('Unordered list') ?><br/>* <?= __('Unordered list') ?><br/>1. <?= __('Ordered list') ?><br/>
<br/>
> <?= __('Blockquote') ?><br/>```<?= __('Code') ?>```<br/>```php<br/><?= __('Code') ?><br/>```<br/>`<?= __('Inline code') ?>`<br/><br/>*** <?= __('Horizontal rule') ?><br/>--- <?= __('Horizontal rule') ?><br/>
<br/>
<?= __('Tab indents lines, Shift+Tab outdents them. To leave the text with Tab, press Esc first.') ?><br/>
<br/>
<?= __('The Preview button below the text shows how the page will look, next to the text or (on narrow screens) instead of it.') ?><br/>
        </div>
      </div>
      <a id="drawer-control" href="" onclick="<?= HANDLER_TOGGLE_DRAWER ?>">
        <span class="icongroup">
<?php foreach (array('bold', 'italic', 'code') as $style): ?>
          <?= iconSvg("format-text-$style", translate('Formatting help')) ?>
<?php endforeach ?>
        </span>
      </a>
<?php if ($this->uploads): ?>
      <a id="uploadbutton" href="" title="<?= __('Upload a file and insert a link to it at the cursor (also by dropping files on the text or pasting an image)') ?>">
        <?= iconSvg('upload', translate('Upload')) ?>
      </a>
<?php endif ?>
