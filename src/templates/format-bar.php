<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The formatting toolbar directly above the text of the editor (inside the form of editor): buttons which format the
 * selected text or the lines of the cursor (bold, italic, headings, links, lists, quotes, code), insert a rule or a
 * table (with the dialog of table-dialog), upload files, and show the Markdown help (the drawer). The buttons only work
 * with the script (setupFormatBar() in wiki.js), so the toolbar is hidden until the script shows it.
 * The buttons name their keyboard shortcut in their label (their tooltip); the arrow keys move between them, and Alt+F10
 * in the text goes to them.
 *
 * Values: uploads (bool: files can be uploaded from the editor, which adds the button for that, see upload-dialog)
 */
?>
<div id="formatbar" class="formatbar" role="toolbar" aria-label="<?= __('Formatting') ?>" aria-controls="text" aria-keyshortcuts="Alt+F10" hidden="hidden">
<button type="button" data-format="bold" title="<?= __('Bold (Ctrl+B)') ?>" aria-keyshortcuts="Control+B Meta+B"><?= iconSvg('format-text-bold', translate('Bold (Ctrl+B)')) ?></button>
<button type="button" data-format="italic" title="<?= __('Italic (Ctrl+I)') ?>" aria-keyshortcuts="Control+I Meta+I"><?= iconSvg('format-text-italic', translate('Italic (Ctrl+I)')) ?></button>
<span class="formatmenu"><button type="button" id="headingbutton" title="<?= __('Heading: level 1 to 4 for the lines of the cursor (the same level again removes it)') ?>" aria-haspopup="menu" aria-expanded="false" aria-controls="headingmenu"><?= iconSvg('format-text-heading', translate('Heading: level 1 to 4 for the lines of the cursor (the same level again removes it)')) ?></button>
<span id="headingmenu" class="headingmenu" role="menu" aria-label="<?= __('Heading') ?>" hidden="hidden">
<?php foreach (array(1, 2, 3, 4) as $level): ?>
<button type="button" role="menuitemradio" aria-checked="false" tabindex="-1" data-level="<?= $level ?>"><?= sprintf(__('Heading %s'), $level) ?></button>
<?php endforeach ?>
</span></span>
<span class="formatsep" role="separator"></span>
<button type="button" data-format="link" title="<?= __('Link (Ctrl+K)') ?>" aria-keyshortcuts="Control+K Meta+K"><?= iconSvg('link', translate('Link (Ctrl+K)')) ?></button>
<button type="button" data-format="wikilink" class="textlabel" title="<?= __('Link to a page of the wiki: [[selected text]]') ?>" aria-label="<?= __('Link to a page of the wiki: [[selected text]]') ?>">[[ ]]</button>
<span class="formatsep" role="separator"></span>
<button type="button" data-format="bullets" title="<?= __('Bulleted list') ?>"><?= iconSvg('format-list-unordered', translate('Bulleted list')) ?></button>
<button type="button" data-format="numbers" title="<?= __('Numbered list') ?>"><?= iconSvg('format-list-ordered', translate('Numbered list')) ?></button>
<button type="button" data-format="quote" title="<?= __('Quote') ?>"><?= iconSvg('format-text-blockquote', translate('Quote')) ?></button>
<button type="button" data-format="code" title="<?= __('Code: `code` in a line, or a code block for several lines (type the language after ```)') ?>"><?= iconSvg('format-text-code', translate('Code: `code` in a line, or a code block for several lines (type the language after ```)')) ?></button>
<span class="formatsep" role="separator"></span>
<button type="button" data-format="rule" title="<?= __('Horizontal rule') ?>"><?= iconSvg('format-horizontal-rule', translate('Horizontal rule')) ?></button>
<button type="button" id="tablebutton" title="<?= __('Table: of a size to choose, or of the selected lines with cells separated by tabs or commas (as copied from a spreadsheet)') ?>" aria-haspopup="dialog"><?= iconSvg('insert-table', translate('Table: of a size to choose, or of the selected lines with cells separated by tabs or commas (as copied from a spreadsheet)')) ?></button>
<?php if ($this->uploads): ?>
<button type="button" id="uploadbutton" title="<?= __('Upload a file and insert a link to it at the cursor (also by dropping files on the editor, or pasting an image)') ?>" aria-haspopup="dialog"><?= iconSvg('upload', translate('Upload a file and insert a link to it at the cursor (also by dropping files on the editor, or pasting an image)')) ?></button>
<?php endif ?>
<button type="button" id="drawer-control" class="helpbutton" title="<?= __('Formatting help') ?>" aria-controls="drawer" aria-expanded="false"><?= iconSvg('help', translate('Formatting help')) ?></button>
</div>
