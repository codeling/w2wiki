<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The buttons in the toolbar to choose the color scheme: the one of the system, light or dark. The choice is kept in
 * a cookie, so it works without a script (the script of the editor pages applies it without loading the page again,
 * which would lose the text in the editor).
 *
 * Values: csrfField (Html), returnTo (text: the address to go back to), choices (list of: mode, icon (Html),
 *         pressed ('true' or 'false': it is the scheme in use))
 */
?>
      <form method="post" action="<?= $this->e(SELF) ?>" class="themeswitch rightaligned" role="group" aria-label="<?= __('Color scheme') ?>">
        <input type="hidden" name="action" value="theme" />
        <?= $this->csrfField ?>

        <input type="hidden" name="return" value="<?= $this->returnTo ?>" />
<?php foreach ($this->choices as $choice): ?>
        <button type="submit" name="mode" value="<?= $choice['mode'] ?>" aria-pressed="<?= $choice['pressed'] ?>"><?= $choice['icon'] ?></button>
<?php endforeach ?>
      </form>
