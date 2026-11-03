<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * The actions which change pages and uploaded files.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Saves, renames and deletes pages and uploaded files, updates the references in the other pages and commits
 * the change. The methods get what they need as parameters (nothing is read from the request) and return what
 * happened as a {@see ChangeResult}; the entry point decides what to show or where to redirect.
 */
final class Changes
{
	/**
	 * @param string $page		name of the page, already sanitized
	 * @param bool $isNew		whether the editor was opened for a new page
	 * @param string $text		new text of the page
	 * @param string $userMessage	message for the commit, "" for the default
	 * @param string|null $baseHash	{@see textHash()} of the text the editor was opened with, to refuse to save over a
	 *				change somebody else has made since (null: no check, e.g. for forms of other versions)
	 */
	public function save(string $page, bool $isNew, string $text, string $userMessage, ?string $baseHash = null): ChangeResult
	{
		$note = new Note();
		if ($isNew)
		{
			$page = str_replace(array('|','#'), '', $page);
		}
		// (the name is checked when saving existing pages too: the client decides whether a page is new)
		if ($isNew && isExistingPage($page))
		{
			$note->format(translate("Error creating page '%s' - it already exists! Please choose a different name, or %s the existing page (this discards current text!)!"),
				array($page, Note::link(translate('edit'), "?action=edit&page=".urlencode($page))));
			return new ChangeResult(false, $page, $note);
		}
		if (!isValidPageName($page))
		{
			$note->format(translate("Error creating page '%s' - invalid page name! Page names must not start with '%s/', or contain empty or hidden ('.'-prefixed) folder names."),
				array($page, uploadFolderInPages() ?? UPLOAD_FOLDER));
			return new ChangeResult(false, $page, $note);
		}
		if (!$isNew && $baseHash !== null && !hash_equals(self::textHash(pageStore()->read($page) ?? ''), $baseHash))
		{
			$note->format(translate('The page was changed (or deleted) by someone else since you opened it. Nothing was saved. Your text is still in the editor: please check the %s of the page, then save again to replace it.'),
				array(Note::link(translate('current version'), pageURL($page), true)));
			return new ChangeResult(false, $page, $note, true);
		}
		if (!writePage($page, $text))
		{
			$note->text(translate('Error saving changes! Make sure your web server has write access to the pages folder.'));
			w2Log("error saving " . fileNameForPage($page));
			return new ChangeResult(false, $page, $note);
		}
		$note->text($isNew ? translate('Created') : translate('Saved'));
		gitChangeHandler($page . ($userMessage !== '' ? (": ".$userMessage) : ($isNew ? " created" : " changed")), $note);
		return new ChangeResult(true, $page, $note);
	}

	/** Identifies the text of a page, to see whether it has changed (the editor sends the one of the text it was opened with) */
	public static function textHash(string $text): string
	{
		return hash('sha256', $text);
	}

	/** Renames a page and changes the links to it in all pages; the result names the page to show afterwards */
	public function renamePage(string $oldName, string $newName): ChangeResult
	{
		$note = new Note();
		if (!pageStore()->rename($oldName, $newName))
		{
			$note->text(translate('Error renaming file')." ".$oldName." ".translate('to')." ".$newName);
			return new ChangeResult(false, $oldName, $note);
		}
		// (plain text, which is also the message of the commit)
		$message = translate('Renamed')." ".$oldName." ".translate('to')." ".$newName;
		$note->text($message);
		$this->updatePageLinks($oldName, "[[".pregReplacementQuote($newName)."\\1", $message, $note);
		return new ChangeResult(true, $newName, $note);
	}

	/** Deletes a page and removes the links to it from all pages; the result names the page to show afterwards */
	public function deletePage(string $name): ChangeResult
	{
		$note = new Note();
		if (!pageStore()->delete($name))
		{
			$note->text(translate('Error deleting file')." ".$name);
			return new ChangeResult(false, $name, $note);
		}
		$message = translate('Removed')." ".$name;
		$note->text($message);
		$this->updatePageLinks($name, "", $message, $note);
		return new ChangeResult(true, DEFAULT_PAGE, $note);
	}

	/**
	 * Makes the first mention of a page name in another page a link to the page (which may not exist yet)
	 *
	 * @param string $sourcePage	the page with the mention
	 * @param string $target	the page to link to; the file name of it is what is looked for in the text
	 * @param string $baseHash	{@see textHash()} of the text of $sourcePage when the mention was shown
	 * @return ChangeResult	the page to show afterwards is the target
	 */
	public function linkMention(string $sourcePage, string $target, string $baseHash): ChangeResult
	{
		$note = new Note();
		$text = isExistingPage($sourcePage) ? pageStore()->read($sourcePage) : null;
		if ($text === null || !isValidPageName($target) || !hash_equals(self::textHash($text), $baseHash))
		{
			$note->format(translate('The page %s was changed or deleted since the list was shown. Nothing was changed.'), array($sourcePage));
			return new ChangeResult(false, $target, $note);
		}
		$newText = Mentions::link($text, Mentions::fileName($target), $target);
		if ($newText === null)
		{
			$note->format(translate('No place to add a link to %s was found in the page %s. Nothing was changed.'), array($target, $sourcePage));
			return new ChangeResult(false, $target, $note);
		}
		if (!writePage($sourcePage, $newText))
		{
			$note->text(translate('Error saving changes! Make sure your web server has write access to the pages folder.'));
			w2Log("error saving " . fileNameForPage($sourcePage));
			return new ChangeResult(false, $target, $note);
		}
		$message = sprintf(translate('Linked %s in the page %s'), $target, $sourcePage);
		$note->text($message);
		gitChangeHandler($message, $note);
		return new ChangeResult(true, $target, $note);
	}

	/** Replaces the links to a page in all pages, then commits */
	private function updatePageLinks(string $oldName, string $replacement, string $message, Note $note): void
	{
		$changedPages = array();
		foreach (getAllPageNames() as $replacePage)
		{
			$content = pageStore()->read($replacePage) ?? '';
			$count = 0;
			$newContent = preg_replace("/\[\[".preg_quote($oldName, '/')."([|#].*?\]\]|\]\])/", $replacement, $content, -1, $count);
			if ($count > 0) // if something changed
			{
				if ( writePage($replacePage, $newContent) )
				{
					$changedPages[] = $replacePage." ($count ".translate('matches').")";
				}
				else
				{
					w2Log("error updating links in " . fileNameForPage($replacePage));
				}
			}
		}
		if (count($changedPages) > 0)
		{
			$note->items(translate('Updated links in the following pages:'), $changedPages);
		}
		gitChangeHandler($message, $note);
	}

	/** Renames an uploaded file and the references to it in all pages */
	public function renameImage(string $oldName, string $newName): ChangeResult
	{
		$imgPath = uploadPath() . "/";
		$oldPath = $imgPath . $oldName;
		// never touch e.g. the .htaccess file protecting the uploads folder;
		// only allow renaming to a valid upload extension; otherwise, e.g.
		// an image containing PHP code could be renamed to .php and executed;
		// the type must stay the same (a PNG must not become an unsanitized SVG)
		$success = !isHiddenFile($oldName) && is_file($oldPath) && hasValidUploadExt($newName) &&
			renameKeepsFileType($oldName, $newName) && !file_exists($imgPath.$newName) &&
			rename($oldPath, $imgPath.$newName);
		$note = new Note();
		if (!$success)
		{
			$note->text(translate('Error renaming image: ').$oldName." ".translate('to')." ".$newName);
			return new ChangeResult(false, '', $note);
		}
		$this->updateImageReferences(translate('Image renamed').": ".$oldName." ".translate('to')." ".$newName, $note,
			fn($content) => uploadReferences()->rename($content, $oldName, $newName));
		return new ChangeResult(true, '', $note);
	}

	/** Deletes an uploaded file and removes the images which show it from all pages */
	public function deleteImage(string $name): ChangeResult
	{
		$path = uploadPath($name);
		$note = new Note();
		if (isHiddenFile($name) || !is_file($path) || !unlink($path))
		{
			$note->text(translate('Error deleting image: ')." (".$name.")");
			return new ChangeResult(false, '', $note);
		}
		$this->updateImageReferences(translate('Image deleted').": ".$name, $note,
			fn($content) => uploadReferences()->removeImages($content, $name));
		return new ChangeResult(true, '', $note);
	}

	/**
	 * Changes the references to an uploaded file in all pages, then commits
	 *
	 * @param callable $change	gets the text of a page, returns the new text and the number of changes
	 */
	private function updateImageReferences(string $commitMsg, Note $note, callable $change): void
	{
		$note->text($commitMsg);
		$changedPages = array();
		$changedPageNames = array();
		foreach (getAllPageNames() as $replacePage)
		{
			list($newContent, $count) = $change(pageStore()->read($replacePage) ?? '');
			if ($count > 0) // if something changed
			{
				if ( writePage($replacePage, $newContent) )
				{
					$changedPages[] = $replacePage." ($count ".translate('matches').")";
					$changedPageNames[] = $replacePage;
				}
				else
				{
					w2Log("error updating links in " . fileNameForPage($replacePage));
				}
			}
		}
		if (count($changedPages) > 0)
		{
			$commitMsg .= " (".translate('Updated images in the following pages:')." ".implode(", ", $changedPageNames).")";
			$note->items(translate('Updated images in the following pages:'), $changedPages);
		}
		gitChangeHandler($commitMsg, $note);
	}
}
