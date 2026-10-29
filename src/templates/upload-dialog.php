<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The dialog to upload files, for the editor and for the upload page: the script (wiki.js) opens it from the button
 * for uploads, and sends the files with requests of its own (to the "uploaded" action, which answers in JSON).
 * The files can be cropped, shrunk and renamed in it.
 *
 * Values: shrink (bool: images can be shrunk and cropped, which needs image processing),
 *         csrfToken (text), apiUrl (text: the address of api.php, which tells whether a file exists already)
 */
?>
<dialog id="uploaddialog" aria-labelledby="uploadtitle" data-upload-url="<?= $this->e(SELF) ?>?action=uploaded&amp;json=1" data-check-url="<?= $this->apiUrl ?>?task=checkupload" data-token="<?= $this->csrfToken ?>" data-msg-uploading="<?= __('Uploading %s') ?>" data-msg-overwrite="<?= __('File %s already exists. Overwrite?') ?>" data-msg-error="<?= __('Upload error') ?>" data-msg-result-size="<?= __('Result: %s pixels') ?>" data-msg-count="<?= __('File %1$s of %2$s') ?>" data-msg-bad-answer="<?= __('The server did not answer as expected (maybe the session has expired). Please reload the page.') ?>">
<h5 id="uploadtitle"><?= __('Upload') ?> <span id="uploadcount"></span></h5>
<p><input id="uploadfile" type="file" multiple="multiple" /> <span id="uploadname"></span></p>
<p><label for="uploadnewname"><?= __('Save as') ?></label> <input id="uploadnewname" type="text" placeholder="<?= __('Keep file name') ?>" /></p>
<?php if ($this->shrink): ?>
<div id="cropsection" hidden="hidden">
<p class="hint"><?= __('Drag the borders of the frame to crop the image (or use the arrow keys, with Shift to change the size).') ?></p>
<div id="cropstage"><canvas id="cropimage" aria-hidden="true"></canvas><div id="cropbox" tabindex="0" role="group" aria-label="<?= __('Part of the image to keep') ?>"><?php foreach (array('nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w') as $handle): ?><span class="handle <?= $handle ?>" data-handle="<?= $handle ?>"></span><?php endforeach ?></div></div>
<p><input id="cropreset" type="button" value="<?= __('Whole image') ?>" /></p>
</div>
<p id="shrinkoptions"><input id="uploadresize" type="checkbox" checked="checked" /><label for="uploadresize"><?= __('Shrink if larger than ') ?></label><input id="uploadmaxsize" type="number" min="20" max="8192" value="1200" /> <label for="uploadmaxsize"><?= __('Pixels') ?></label> <span id="resultsize"></span></p>
<?php endif ?>
<p id="uploadstatus" role="status"></p>
<p class="dialogactions"><input id="uploadstart" type="button" value="<?= __('Upload') ?>" /> <input id="uploadcancel" type="button" value="<?= __('Cancel') ?>" /></p>
</dialog>
