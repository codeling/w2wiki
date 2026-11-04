<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The help for the Markdown syntax next to the editor (inside the toolbar of the page), which the last button of the
 * formatting toolbar above the text shows (see format-bar).
 * The texts are translated, and the examples show the syntax as it is written.
 *
 * Values: uploadUrl (text: the address of the uploads folder, with slashes at both ends)
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
| <?= sprintf(__('Column %s'), 1) ?> | <?= sprintf(__('Column %s'), 2) ?> |<br/>| --- | --- |<br/>| a | b |<br/>
<br/>
<?= __('The buttons above the text format the selected text or the lines of the cursor, and insert rules and tables; each change can be undone with Ctrl+Z. Alt+F10 in the text goes to the buttons, the arrow keys move between them, and Esc goes back to the text.') ?><br/>
<br/>
<?= __('Files dropped on the text go where they are dropped (elsewhere in the editor: to the cursor): Markdown and text files are inserted, other files uploaded and linked. Images dragged from other pages or the list of uploads are linked.') ?><br/>
<br/>
<?= __('Tab indents lines, Shift+Tab outdents them. To leave the text with Tab, press Esc first.') ?><br/>
<br/>
<?= __('The Preview button below the text shows how the page will look, next to the text or (on narrow screens) instead of it.') ?><br/>
<br/>
<?= __('Keys: Ctrl+B bold, Ctrl+I italic, Ctrl+K link, Ctrl+S or Ctrl+Enter save, Ctrl+Alt+P preview (on a Mac: Cmd and Option). Outside of the text, ? shows all keyboard shortcuts.') ?><br/>
        </div>
      </div>
