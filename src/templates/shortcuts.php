<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The list of the keyboard shortcuts (inside page), which the key "?" shows (see setupShortcuts() in wiki.js); a dialog
 * which is not shown without the script. The script hides the rows of the shortcuts which the page doesn't have
 * (data-for: the keys, as in data-shortcut of the link or field which they use).
 *
 * Values: editor (bool: the page has the editor, whose keys are listed too)
 */
?>
    <dialog id="shortcuts" class="shortcuts" aria-labelledby="shortcutstitle">
      <div class="shortcutsbox">
      <button type="button" class="shortcutsclose rightaligned"><?= iconSvg('close', translate('Close')) ?></button>
      <h5 id="shortcutstitle"><?= __('Keyboard shortcuts') ?></h5>
      <table>
        <caption><?= __('On every page (not while the cursor is in a text field)') ?></caption>
        <tbody>
          <tr data-for="/"><th scope="row"><kbd>/</kbd></th><td><?= __('Search') ?></td></tr>
          <tr data-for="e"><th scope="row"><kbd>e</kbd></th><td><?= __('Edit this page') ?></td></tr>
          <tr data-for="n"><th scope="row"><kbd>n</kbd></th><td><?= __('New page') ?></td></tr>
          <tr data-for="g h"><th scope="row"><kbd>g</kbd> <?= __('then') ?> <kbd>h</kbd></th><td><?= __('Home page') ?></td></tr>
          <tr data-for="g a"><th scope="row"><kbd>g</kbd> <?= __('then') ?> <kbd>a</kbd></th><td><?= __('List of all pages') ?></td></tr>
          <tr data-for="h"><th scope="row"><kbd>h</kbd></th><td><?= __('History of this page') ?></td></tr>
          <tr><th scope="row"><kbd>?</kbd></th><td><?= __('These keyboard shortcuts') ?></td></tr>
        </tbody>
      </table>
<?php if ($this->editor): ?>
      <table>
        <caption><?= __('In the editor') ?></caption>
        <tbody>
          <tr><th scope="row"><kbd><?= __('Ctrl') ?></kbd>+<kbd>S</kbd></th><td><?= __('Save') ?></td></tr>
          <tr><th scope="row"><kbd>Tab</kbd>, <kbd><?= __('Shift') ?></kbd>+<kbd>Tab</kbd></th><td><?= __('Tab indents lines, Shift+Tab outdents them. To leave the text with Tab, press Esc first.') ?></td></tr>
        </tbody>
      </table>
      <p class="hint"><?= __('On a Mac, press Cmd instead of Ctrl, and Option instead of Alt.') ?></p>
<?php endif ?>
      </div>
    </dialog>
