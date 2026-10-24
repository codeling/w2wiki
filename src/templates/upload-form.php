<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * The form to upload a file, with the script which asks before a file is overwritten (inside the main part of page).
 *
 * Values: csrfField (Html), prevPage (text: the page to return to), shrink (bool: images can be shrunk),
 *         nonce (text: for the script, see the Content-Security-Policy),
 *         baseUri (Html: the base address of the wiki, as the inside of a JavaScript string),
 *         noFileText, overwriteText (Html: translated texts as JavaScript string literals, with the quotes)
 */
?>
<form id="upload" method="post" action="<?= $this->e(SELF) ?>" enctype="multipart/form-data"><p>
<input type="hidden" name="action" value="uploaded" /><?= $this->csrfField ?><input type="hidden" name="prevpage" value="<?= $this->prevPage ?>" /><input type="hidden" id="overwrite" name="overwrite" value="" /><input id="file" type="file" name="userfile" />
<label for="newname"><?= __('Save as') ?></label> <input id="newname" type="text" name="newname" placeholder="<?= __('Keep file name') ?>" />
<?php if ($this->shrink): ?>
<input id="resize" type="checkbox" checked="checked" name="resize" value="true"><label for="resize"><?= __('Shrink if larger than ') ?></label><input id="maxsize" type="number" name="maxsize" min="20" max="8192" value="1200">
<label for="maxsize" id="maxsizelabel"><?= __('Pixels') ?></label>
<?php endif ?>
<input id="upload" type="submit" value="<?= __('Upload') ?>" />
</p></form>
<script type="application/javascript" nonce="<?= $this->nonce ?>">
function processForm(e) {
    e.preventDefault();
    var fileInput = document.getElementById("file");
    if (fileInput.files.length == 0) { alert(<?= $this->noFileText ?>); return; }
    var filename = fileInput.files[0].name;
    var newname = document.getElementById("newname").value;
    fetch("<?= $this->baseUri ?>/api.php?task=checkupload&filename="+encodeURIComponent(filename)+"&newname="+encodeURIComponent(newname))
        .then((response) => {
            response.json().then((data) => {
                upload = true;
                if (data) {
                     upload = window.confirm(<?= $this->overwriteText ?>.replace("%s", () => filename));
                }
                if (upload) {
                    document.getElementById("overwrite").value = data ? "true" : "";
                    var myform = document.getElementById("upload");
                    myform.submit();
                }
            });
        })
        .catch((err) => { console.log(err); });
}
window.addEventListener("load", function(event) {
    var form = document.getElementById("upload");
    form.addEventListener("submit", processForm);
});
</script>
