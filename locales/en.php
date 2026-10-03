<?php if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Locale set
 *
 * Do not change variable name $w2_word_set.
 * Rewrite values in your locale and rename this file to locale code.
 * Set "LOCALE" to your locale in config.php.
 *
 * The English texts are the keys themselves: __() shows the key of a text which has no entry
 * here, so this file only has the entries which are not the same as their key. To translate,
 * add an entry for every text used with __() in the code (see the other files in this folder,
 * and tests/Unit/LocaleFilesTest.php for the list of missing translations).
 */
$w2_word_set = array(
	// Titles of the actions (the key is the name of the action)
	'imgDelete' => 'Delete image',
	'imgRename' => 'Rename image',
	// Override TITLE_DATE and TITLE_DATE_NO_TIME if set.
	'date_format'         => 'Y-m-d H:i:s',
	'date_format_no_time' => 'Y-m-d',
);
