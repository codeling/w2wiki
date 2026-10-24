<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The page with the form to log in (inside layout).
 *
 * Values: heading (text), notes (list of texts), csrfField (Html), passwordLabel (text), buttonLabel (text)
 */
?>
    <h1><?= $this->heading ?></h1>
<?php foreach ($this->notes as $note): ?>
    <p class="note"><?= $note ?></p>
<?php endforeach ?>
    <form method="post">
      <?= $this->passwordLabel ?>: <input type="password" name="p">
      <?= $this->csrfField ?>

      <input type="submit" value="<?= $this->buttonLabel ?>">
    </form>
