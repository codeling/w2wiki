<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The document around every page: head and body.
 *
 * Values: title (text), pageTitle (Html, from PAGE_TITLE), bodyClass (text or ''), editor (bool: the page has
 *         the editor, which needs wiki.js and the space above the on-screen keyboard), content (Html),
 *         savedDraft (text: the key of the draft in the browser which wiki.js removes, because its text has
 *         just been saved; or '')
 */
?>
<!doctype html>
<html lang="<?= $this->e(W2_LOCALE) ?>">
  <head>
    <meta charset="<?= $this->e(W2_CHARSET) ?>">
    <link rel="apple-touch-icon" href="<?= $this->e(assetURL("w2-icons/w2-icon.png")) ?>"/>
    <link rel="icon" href="<?= $this->e(assetURL("w2-icons/w2-icon.png")) ?>"/>
<?php /* (in the editor, the page shrinks to the space above the on-screen keyboard, to keep Save in reach) */ ?>
    <meta name="viewport" content="width=device-width, initial-scale=1<?= $this->editor ? ', interactive-widget=resizes-content' : '' ?>" />
    <link type="text/css" rel="stylesheet" href="<?= $this->e(assetURL(CSS_FILE)) ?>" />
    <title><?= $this->pageTitle ?><?= $this->title ?></title>
<?php if ($this->editor || $this->savedDraft !== ''): ?>
<?php /* (warns when leaving the editor with unsaved changes; for new pages too, as they would be lost silently;
          after saving, it removes the local draft of the saved text) */ ?>
<?php /* (not relative: pages can be shown below the script, like /index.php/Page) */ ?>
    <script src="<?= $this->e(assetURL("wiki.js")) ?>"></script>
<?php endif ?>
  </head>
  <body<?= $this->bodyClass !== '' ? ' class="' . $this->bodyClass . '"' : '' ?><?= $this->savedDraft !== '' ? ' data-saved-draft="' . $this->savedDraft . '"' : '' ?>>
<?= $this->content ?>
  </body>
</html>
