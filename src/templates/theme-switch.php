<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The button in the toolbar to change the color scheme: the system's, light, dark, and again. The icon shows the scheme in
 * use (index.css shows the one of the three icons which fits the root element). The choice is kept in a cookie, so it
 * works without a script (the script of the pages applies it without loading the page again, which would lose the text
 * in the editor).
 *
 * Values: csrfField (Html), returnTo (text: the address to go back to), next (text: the scheme which the button
 *         chooses), icons (list of Html: the icons of the three schemes, see iconSvg())
 */
?>
      <form method="post" action="<?= $this->e(SELF) ?>" class="themeswitch rightaligned">
        <input type="hidden" name="action" value="theme" />
        <?= $this->csrfField ?>

        <input type="hidden" name="return" value="<?= $this->returnTo ?>" />
        <button type="submit" name="mode" value="<?= $this->next ?>"><?php foreach ($this->icons as $icon): ?><?= $icon ?><?php endforeach ?></button>
      </form>
