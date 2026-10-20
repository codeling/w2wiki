<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * The pages of the wiki: Markdown files in a folder (and its subfolders).
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Reads, writes and lists the pages. A page name is the path of its file below the pages folder, without the
 * extension ("Home", "sub/Page"). Names which are not valid page names (see isValidName()) never name a page:
 * they don't exist, and can't be written, renamed to or deleted.
 *
 * Everything which changes a page happens here, so that this is the one place which knows how pages are stored.
 */
final class PageStore
{
	/**
	 * @param string $root			folder with the pages (no trailing slash)
	 * @param string $extension		extension of the page files, without the dot
	 * @param string $uploadFolder	name of the folder for uploads within $root, which holds no pages
	 */
	public function __construct(
		private readonly string $root,
		private readonly string $extension,
		private readonly string $uploadFolder
	) {
	}

	/**
	 * Whether a page with the given name may exist: page names may contain subfolders, but no hidden or
	 * empty path segments, and pages must not be stored within the (statically served) uploads folder
	 */
	public function isValidName(string $page): bool
	{
		$segments = explode('/', $page);
		foreach ($segments as $segment) {
			if ($segment === '' || str_starts_with($segment, '.')) {
				return false;
			}
		}
		return $segments[0] !== $this->uploadFolder;
	}

	/** The file of a page (which may not exist; the name is not checked) */
	public function path(string $page): string
	{
		return "$this->root/$page.$this->extension";
	}

	public function exists(string $page): bool
	{
		return $this->isValidName($page) && file_exists($this->path($page));
	}

	/** The text of a page, or null if there is no such page */
	public function read(string $page): ?string
	{
		if (!$this->exists($page)) {
			return null;
		}
		$text = @file_get_contents($this->path($page));
		return $text === false ? null : $text;
	}

	/** Time of the last change of a page, or null if there is no such page */
	public function modified(string $page): ?int
	{
		$time = $this->exists($page) ? @filemtime($this->path($page)) : false;
		return $time === false ? null : $time;
	}

	/** Size of a page in bytes, or null if there is no such page */
	public function size(string $page): ?int
	{
		$size = $this->exists($page) ? @filesize($this->path($page)) : false;
		return $size === false ? null : $size;
	}

	/**
	 * Create or replace a page in one step (see writeFileAtomically()), with the folders it needs
	 *
	 * @return bool whether the page was written
	 */
	public function write(string $page, string $text): bool
	{
		if (!$this->isValidName($page) || !$this->makeFolderFor($page)) {
			return false;
		}
		return writeFileAtomically($this->path($page), $text);
	}

	/** @return bool whether the page existed and was deleted */
	public function delete(string $page): bool
	{
		return $this->exists($page) && unlink($this->path($page));
	}

	/**
	 * Move a page. Nothing is replaced: it fails if $to exists.
	 *
	 * @return bool whether the page existed, $to is a valid name of a page which doesn't exist yet, and the page was moved
	 */
	public function rename(string $from, string $to): bool
	{
		if (!$this->exists($from) || !$this->isValidName($to) || file_exists($this->path($to))) {
			return false;
		}
		return $this->makeFolderFor($to) && rename($this->path($from), $this->path($to));
	}

	/**
	 * The names of all pages, including those in subfolders, in natural order without regard to case ("page 2"
	 * before "Page 10"). The order doesn't depend on the file system, which lists files in any order.
	 *
	 * @return string[]
	 */
	public function names(): array
	{
		$names = $this->namesIn('');
		usort($names, fn(string $a, string $b) => strnatcasecmp($a, $b) ?: strcmp($a, $b));
		return $names;
	}

	/** @return string[] names of the pages in a folder (given relative to the pages folder, "" for the pages folder) and below */
	private function namesIn(string $folder): array
	{
		$names = [];
		$dir = opendir($this->root . ($folder === '' ? '' : "/$folder"));
		if ($dir === false) {
			return $names;
		}
		while (($file = readdir($dir)) !== false) {
			// (hidden files and folders, like .git, never contain pages)
			if (str_starts_with($file, '.')) {
				continue;
			}
			$relative = $folder === '' ? $file : "$folder/$file";
			if (is_dir("$this->root/$relative")) {
				if ($folder === '' && $file === $this->uploadFolder) {
					continue;
				}
				array_push($names, ...$this->namesIn($relative));
			} elseif (str_ends_with($file, ".$this->extension")) {
				$names[] = $folder === '' ? substr($file, 0, -strlen(".$this->extension")) : "$folder/" . substr($file, 0, -strlen(".$this->extension"));
			}
		}
		closedir($dir);
		return $names;
	}

	/** Create the folder for the file of a page, if it is in a subfolder */
	private function makeFolderFor(string $page): bool
	{
		$folder = dirname($this->path($page));
		return is_dir($folder) || mkdir($folder, 0755, true) || is_dir($folder);
	}
}
