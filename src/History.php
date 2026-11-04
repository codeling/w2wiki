<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * The versions of a page in the git repository of the pages.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Reads the history of a page from git (with GIT_COMMIT_ENABLED, every change of a page is a commit): the commits
 * which changed its file (`git log --follow`, so the versions before a rename are included), and the text of the page
 * in one of them. Nothing is changed, so no lock is needed. All commands run through {@see Git::run()}, with the file
 * after "--" (as a literal path relative to the pages folder, so that no page name can be taken for an option or a
 * pattern), and revisions are only taken from the output of git (see find()).
 */
final class History
{
	/** Number of versions on a page of the list */
	public const PER_PAGE = 50;

	/** The longest text of a version which is read (in bytes); longer ones are not shown */
	public const MAX_TEXT = 8388608;

	/** The most output of the list of versions which is read (about 50,000 versions); older ones are left out */
	private const MAX_LOG = 16777216;

	/**
	 * The fields of a commit in the output of "git log -z": a record separator, then hash, short hash, author time
	 * (Unix time), author name and subject, separated by null bytes (the only byte which can't be part of them)
	 */
	private const FORMAT = '%x1e%H%x00%h%x00%at%x00%an%x00%s';

	public function __construct(private readonly Git $git, private readonly PageStore $pages)
	{
	}

	/** Whether a value can name a version: a commit hash in hexadecimal, at least 7 digits (also SHA-256 hashes) */
	public static function isRevision(mixed $revision): bool
	{
		return is_string($revision) && preg_match('/^[0-9a-f]{7,64}$/D', $revision) === 1;
	}

	/**
	 * The versions of a page, newest first. A version is a commit which changed the file of the page: its full and
	 * short hash, its time (Unix time), author and subject (the first line of the message), the file of the page in it
	 * (relative to the pages folder: it was another one before the page was renamed), and whether the commit deleted
	 * the file (if the page was deleted and created again).
	 *
	 * @return list<array{hash: string, shortHash: string, time: int, author: string, subject: string, path: string, deleted: bool}>|null
	 *         null if git failed (the error is logged)
	 */
	public function versions(string $page): ?array
	{
		if (!$this->pages->isValidName($page))
		{
			return array();
		}
		$file = $this->pages->fileName($page);
		$result = $this->git->run(array('-c', 'core.quotepath=off', '--literal-pathspecs', 'log', '--follow', '--relative', '--no-color',
			'--no-show-signature', '-z', '--name-status', '--format=' . self::FORMAT, '--', "./$file"), 0, self::MAX_LOG, true);
		if (!$result->succeeded())
		{
			w2Log("error executing command {$result->command} (return value: {$result->code}): " . $result->errors);
			return null;
		}
		if ($result->truncated)
		{
			w2Log("the history of $file is too long, the oldest versions are left out");
		}
		return self::parseLog($result->output, $file);
	}

	/**
	 * The versions in the output of the git log command of versions() (a version cut off at the end is left out)
	 *
	 * @param string $file	the file of the page now, relative to the pages folder (for a version which names no file)
	 * @return list<array{hash: string, shortHash: string, time: int, author: string, subject: string, path: string, deleted: bool}>
	 */
	public static function parseLog(string $output, string $file): array
	{
		$tokens = explode("\0", $output);
		$count = count($tokens);
		$versions = array();
		// (the file of the page in the versions before the one being read, which may have renamed it)
		$olderFile = $file;
		$i = 0;
		while ($i < $count)
		{
			// (the fields are taken by their position: only the hash and the status are checked, the others are free text)
			if (!preg_match('/^\n*\x1e([0-9a-f]{40,64})$/D', $tokens[$i], $header))
			{
				$i++;
				continue;
			}
			if ($i + 4 >= $count)
			{
				break;
			}
			$shortHash = $tokens[$i + 1];
			$version = array(
				'hash' => $header[1],
				'shortHash' => preg_match('/^[0-9a-f]{4,64}$/D', $shortHash) ? $shortHash : substr($header[1], 0, 7),
				'time' => (int)$tokens[$i + 2], 'author' => $tokens[$i + 3], 'subject' => $tokens[$i + 4],
				'path' => $olderFile, 'deleted' => false
			);
			$i += 5;
			$complete = true;
			$named = false;
			// the changed files: a status letter (with a score for renames and copies), and the file (renames and copies: from, to)
			while ($i < $count && preg_match('/^\n*([ACDMRTUXB])[0-9]*$/D', $tokens[$i], $status))
			{
				$files = ($status[1] === 'R' || $status[1] === 'C') ? 2 : 1;
				if ($i + $files >= $count)
				{
					$complete = false;
					break;
				}
				if (!$named)
				{
					$version['path'] = $tokens[$i + $files];
					$version['deleted'] = ($status[1] === 'D');
					$olderFile = ($files === 2) ? $tokens[$i + 1] : $version['path'];
					$named = true;
				}
				$i += 1 + $files;
			}
			if ($complete)
			{
				$versions[] = $version;
			}
		}
		return $versions;
	}

	/**
	 * The position in $versions of the version which a revision names (a hash, or the start of one), if it names
	 * exactly one in which the page was not deleted
	 *
	 * @param list<array{hash: string, deleted: bool}> $versions
	 */
	public static function find(array $versions, string $revision): ?int
	{
		if (!self::isRevision($revision))
		{
			return null;
		}
		$found = null;
		foreach ($versions as $index => $version)
		{
			if (str_starts_with($version['hash'], $revision))
			{
				if ($found !== null)
				{
					return null;
				}
				$found = $index;
			}
		}
		return ($found === null || $versions[$found]['deleted']) ? null : $found;
	}

	/**
	 * The text of the page in a version (see versions()), exactly as it was committed
	 *
	 * @param array{hash: string, path: string, deleted: bool} $version
	 * @return string|null	null if the page was deleted in the version, if the text is longer than MAX_TEXT or if git
	 *			failed (the last two are logged)
	 */
	public function text(array $version): ?string
	{
		if ($version['deleted'] || !preg_match('/^[0-9a-f]{40,64}$/D', $version['hash']))
		{
			return null;
		}
		// ("commit:./file" is the file relative to the folder where git runs, the pages folder)
		$result = $this->git->run(array('-c', 'core.quotepath=off', 'cat-file', 'blob', $version['hash'] . ':./' . $version['path']),
			0, self::MAX_TEXT, true);
		if (!$result->succeeded())
		{
			w2Log("error executing command {$result->command} (return value: {$result->code}): " . $result->errors);
			return null;
		}
		if ($result->truncated)
		{
			w2Log("the version {$version['hash']} of {$version['path']} is larger than " . self::MAX_TEXT . " bytes and is not shown");
			return null;
		}
		return $result->output;
	}
}
