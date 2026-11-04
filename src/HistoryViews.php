<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * The views of the history of a page: the list of versions, an old version, and what changed.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Builds the views of the actions which show the history of a page from git (see isHistoryAction()): "history" (the
 * versions, newest first, a part at a time), "version" (the text of a version), "diff" (what changed in a version, or how
 * it differs from the current text) and "restore" (the values for the editor with the text of a version: nothing is
 * written, the text is saved like any other one). Gets what it needs as parameters (nothing is read from the request).
 */
final class HistoryViews
{
	public function __construct(private readonly History $history)
	{
	}

	/**
	 * @param string $action	history, version, diff or restore
	 * @param string $page		the page (sanitized, see sanitizeFilename())
	 * @param string $revision	the version (a hash, or the start of one; not used by "history")
	 * @param bool $toCurrent	"diff": compare the version with the current text instead of the version before it
	 * @param int $part		"history": the part of the list to show (1 for the newest versions)
	 * @return array{status: int, title: string, date: string, note: Note|null, content: Html, pageExists: bool,
	 *               restore: array{text: string, baseHash: string, gitMessage: string, draftKey: string}|null}
	 *         the HTTP status, the title and the date for the title bar ('' for none), a note about the view (e.g. that
	 *         the version was not found), the content of the main part, whether the page exists (it has page actions),
	 *         and for restoring the values for the editor (the text of the version, the hash of the current text which it
	 *         would replace, the message for the commit and the key of the draft in the browser)
	 */
	public function show(string $action, string $page, string $revision, bool $toCurrent, int $part): array
	{
		$titles = array('history' => translate('History'), 'version' => translate('Old version'), 'diff' => translate('Changes'),
			'restore' => translate('Restore'));
		$view = array('status' => 200, 'title' => ($titles[$action] ?? $action) . ": " . $page, 'date' => '', 'note' => null,
			'content' => Html::raw(''), 'pageExists' => isExistingPage($page), 'restore' => null);
		if (!GIT_COMMIT_ENABLED)
		{
			return array('status' => 404, 'note' => (new Note())->text(translate('The history of pages is only available if the changes are committed to git (GIT_COMMIT_ENABLED in config.php).'))) + $view;
		}
		if (!$view['pageExists'])
		{
			return array('status' => 404, 'note' => (new Note())->format(translate('The page %s does not exist.'), array($page))) + $view;
		}
		if (($versions = $this->history->versions($page)) === null)
		{
			return array('status' => 500, 'note' => (new Note())->text(translate("The history of the page could not be read; see the web server's error log for details."))) + $view;
		}
		if ($action === 'history')
		{
			return array('content' => $this->versionList($page, $versions, $part)) + $view;
		}
		if (($index = History::find($versions, $revision)) === null)
		{
			return array('status' => 404, 'note' => (new Note())->format(translate('The version %s of the page %s was not found.'), array($revision, $page))) + $view;
		}

		$version = $versions[$index];
		$date = date(translate('date_format', TITLE_DATE), $version['time']);
		$view['date'] = $date;
		$text = $this->history->text($version);
		$previous = $versions[$index + 1] ?? null;
		$previousText = ($previous === null || $previous['deleted'] || $action !== 'diff' || $toCurrent) ? '' : $this->history->text($previous);
		if ($text === null || $previousText === null)
		{
			return array('status' => 500, 'note' => (new Note())->text(translate("The text of this version could not be read; see the web server's error log for details."))) + $view;
		}
		if ($action === 'restore')
		{
			return array(
				'note' => (new Note())->format(translate('This is the text of the version of %s (%s). Nothing has been changed yet: check the text, then save it to make it the current text of the page.'),
					array($date, $version['shortHash'])),
				'restore' => array('text' => $text, 'baseHash' => Changes::textHash(pageStore()->read($page) ?? ''),
					'gitMessage' => sprintf(translate('Restored version %s of %s'), $version['shortHash'], $date),
					// (a draft of its own, so that an unsaved draft of the page is not shown instead of the version)
					'draftKey' => "w2wiki-draft:" . SELF . ":restore:$page:" . $version['hash'])
			) + $view;
		}
		if ($action === 'version')
		{
			$body = Html::raw(toHTML($text));
		}
		elseif ($toCurrent)
		{
			$body = $this->differences($text, pageStore()->read($page) ?? '',
				translate('This version (lines marked -) compared with the current text of the page (lines marked +):'),
				translate('This version is the same as the current text of the page.'));
		}
		else
		{
			$body = $this->differences($previousText, $text,
				($previous === null || $previous['deleted'])
					? translate('The page was created in this version (lines marked +):')
					: sprintf(translate('The version before it (%s, lines marked -) compared with this version (lines marked +):'), $previous['shortHash']),
				translate('The text was not changed in this version (for example, the page was only renamed).'));
		}
		$view['content'] = templates()->render('version', array(
			'page' => $page, 'pageHref' => pageURL($page), 'date' => $date, 'shortHash' => $version['shortHash'],
			'subject' => $version['subject'], 'author' => $version['author'],
			'links' => $this->links($page, $version['hash'], $action === 'version' ? 'version' : ($toCurrent ? 'compare' : 'diff')),
			'body' => $body
		));
		return $view;
	}

	/**
	 * The address of a view of the history of a page, with more of the query (like "&rev=...")
	 */
	private static function url(string $action, string $page, string $query = ''): string
	{
		return SELF . "?action=" . $action . "&page=" . urlencode($page) . $query;
	}

	/**
	 * A part of the list of versions (see History::PER_PAGE); a part beyond the end shows the last one
	 *
	 * @param list<array{hash: string, shortHash: string, time: int, author: string, subject: string, path: string, deleted: bool}> $versions
	 */
	private function versionList(string $page, array $versions, int $part): Html
	{
		$total = count($versions);
		$parts = max(1, (int)ceil($total / History::PER_PAGE));
		$part = min(max(1, $part), $parts);
		$offset = ($part - 1) * History::PER_PAGE;
		$dateFormat = translate('date_format', TITLE_DATE);
		$rows = array();
		foreach (array_slice($versions, $offset, History::PER_PAGE) as $version)
		{
			$query = "&rev=" . $version['hash'];
			$rows[] = array('date' => date($dateFormat, $version['time']), 'subject' => $version['subject'], 'author' => $version['author'],
				'shortHash' => $version['shortHash'], 'deleted' => $version['deleted'], 'versionHref' => self::url('version', $page, $query),
				'changesHref' => self::url('diff', $page, $query), 'compareHref' => self::url('diff', $page, $query . "&to=current"));
		}
		return templates()->render('history', array(
			'total' => $total, 'first' => $total > 0 ? $offset + 1 : 0, 'last' => $offset + count($rows), 'rows' => $rows,
			'newerHref' => $part > 1 ? self::url('history', $page, "&p=" . ($part - 1)) : null,
			'olderHref' => $part < $parts ? self::url('history', $page, "&p=" . ($part + 1)) : null
		));
	}

	/**
	 * The lines which differ between two texts (see LineDiff), or why there are none
	 *
	 * @param string $legend	what is compared, and which lines are which
	 * @param string $same		the message if nothing differs
	 */
	private function differences(string $old, string $new, string $legend, string $same): Html
	{
		$differences = LineDiff::compare($old, $new);
		$lines = ($differences === null) ? null : LineDiff::withContext($differences);
		return templates()->render('history-diff', array(
			'legend' => $legend, 'lines' => $lines === null ? null : templates()->render('diff-lines', array('lines' => $lines)),
			'message' => $differences === null ? translate('The texts are too long or too different to be compared line by line.') : ($lines === null ? $same : '')
		));
	}

	/**
	 * The links between the views of a version (for the template version): its text, what changed in it, the version
	 * compared with the current text, the editor to restore it, and the list of versions
	 *
	 * @param string $current	the view being shown ("version", "diff" or "compare"), which is not a link
	 * @return list<array{href: string|null, label: string}>
	 */
	private function links(string $page, string $hash, string $current): array
	{
		$query = "&rev=" . $hash;
		$links = array(
			'version' => array(self::url('version', $page, $query), translate('Text of this version')),
			'diff' => array(self::url('diff', $page, $query), translate('Changes in this version')),
			'compare' => array(self::url('diff', $page, $query . "&to=current"), translate('Compared with the current text')),
			'restore' => array(self::url('restore', $page, $query), translate('Restore this version')),
			'history' => array(self::url('history', $page), translate('All versions')),
		);
		$items = array();
		foreach ($links as $name => list($href, $label))
		{
			$items[] = array('href' => $name === $current ? null : $href, 'label' => $label);
		}
		return $items;
	}
}
