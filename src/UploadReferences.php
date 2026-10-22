<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * References to uploaded files in the text of pages.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Finds, renames and removes the references to an uploaded file in the Markdown text of a page, so that the
 * list of uploads (where is a file used?), renaming and deleting a file agree about what a reference is.
 *
 * A reference is the address of the file in a Markdown link or image, `[text](/images/name.pdf)` or
 * `![alt](/images/name.gif "title")`: the address of the uploads folder, optionally with the base address of
 * the wiki in front of it, followed by the name of the file and then the closing parenthesis or white space
 * (as before a title). Names are compared exactly, as the web server does.
 */
final class UploadReferences
{
	/** Regular expression for what may follow the address of the file: the end of the link, or a title */
	private const AFTER_ADDRESS = '(?=[)\s])';

	/**
	 * @param string $baseUri	base address of the wiki (BASE_URI), "" if it is in the root of the web server
	 * @param string $uploadUrl	address of the uploads folder below the base address (UPLOAD_URL)
	 */
	public function __construct(private readonly string $baseUri, private readonly string $uploadUrl)
	{
	}

	/*
	 * The patterns are built where they are used, so that every value in them is visibly quoted with
	 * preg_quote() (tests/Unit/SourceGuardTest.php insists on that): "(?:base)?/uploads/name", where a
	 * base address of "" matches nothing, and the name is followed by the end of the link, or a title.
	 */

	/** The number of references to a file in a text */
	public function count(string $text, string $name): int
	{
		return (int)preg_match_all(
			'/\]\((?:' . preg_quote($this->baseUri, '/') . ')?' . preg_quote("/$this->uploadUrl/", '/') . preg_quote($name, '/') . self::AFTER_ADDRESS . '/',
			$text
		);
	}

	/**
	 * Point the references to a file to another name; links and images, with or without titles
	 *
	 * @return array{0: string, 1: int} the new text, and the number of changed references
	 */
	public function rename(string $text, string $name, string $newName): array
	{
		$count = 0;
		// (a callback, so that "$" and "\" in the new name are taken literally)
		$new = preg_replace_callback(
			'/(\]\((?:' . preg_quote($this->baseUri, '/') . ')?' . preg_quote("/$this->uploadUrl/", '/') . ')' . preg_quote($name, '/') . self::AFTER_ADDRESS . '/',
			fn(array $m) => $m[1] . $newName,
			$text, -1, $count
		);
		return array($new ?? $text, $count);
	}

	/**
	 * Remove the images which show a file, including their alt text and title. Links to the file are
	 * not changed (the text of a link is worth keeping, but what to make of it is not for the wiki to say).
	 *
	 * @return array{0: string, 1: int} the new text, and the number of removed images
	 */
	public function removeImages(string $text, string $name): array
	{
		$count = 0;
		// (the alt text ends at the first "]", so that this can't reach back to an earlier image on the line)
		$new = preg_replace(
			'/!\[(?:\\\\.|[^\]\\\\\n])*\]\((?:' . preg_quote($this->baseUri, '/') . ')?' . preg_quote("/$this->uploadUrl/", '/') . preg_quote($name, '/')
				. '(?:\s+(?:"[^"]*"|\'[^\']*\'|\([^)]*\)))?\)/',
			'', $text, -1, $count
		);
		return array($new ?? $text, $count);
	}
}
