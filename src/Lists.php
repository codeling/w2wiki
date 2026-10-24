<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * The lists of pages and uploaded files, and the search.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Builds the views which list pages and uploaded files, and the search. Gets what it needs as parameters (nothing is
 * read from the request) and returns the HTML of the template, or the data for a template.
 */
final class Lists
{
	private const SORT_KEYS = array('name', 'recent', 'size');

	/** The column to sort by: the requested one if it is one of the columns, else the name */
	public function sortKey(string $requested): string
	{
		return in_array($requested, self::SORT_KEYS, true) ? $requested : 'name';
	}

	/** All pages, sorted by $sortBy (see sortKey()) */
	public function allPages(string $sortBy): Html
	{
		$pageNames = getAllPageNames();
		$items = array();
		foreach($pageNames as $pageName)
		{
			$items[] = array('name' => $pageName, 'recent' => pageStore()->modified($pageName) ?? 0,
				'size' => pageStore()->size($pageName) ?? 0);
		}
		$items = $this->sorted($items, $sortBy);
		$date_format = translate('date_format', TITLE_DATE);
		$rows = array();
		foreach ($items as $item)
		{
			$rows[] = array('href' => pageURL($item['name']), 'name' => $item['name'], 'modified' => date($date_format, $item['recent']),
				'size' => humanFilesize($item['size']), 'actions' => getPageActions($item['name'], 'all', "-dark"));
		}
		return templates()->render('all-pages', array(
			'total' => count($pageNames), 'rows' => $rows,
			'head' => sortableHeadings('all', $sortBy, array(
				array(translate('Name'), 'name'), array(translate('Modified'), 'recent'), array(translate('Size'), 'size'), array(translate('Action'), null)))
		));
	}

	/**
	 * The uploaded files, sorted by $sortBy (see sortKey())
	 *
	 * @param string $prevPage	the page to return to after renaming or deleting a file
	 */
	public function uploads(string $sortBy, string $prevPage): Html
	{
		$imgNames = array_filter(glob(PAGES_PATH . "/" . UPLOAD_FOLDER . "/*") ?: array(), 'is_file');
		$items = array();
		foreach($imgNames as $imgName)
		{
			$items[] = array('name' => $imgName, 'recent' => filemtime($imgName), 'size' => filesize($imgName));
		}
		$items = $this->sorted($items, $sortBy);

		$imgPages = array();
		if (SHOW_PAGES_WHERE_FILE_USED)
		{
			foreach(getAllPageNames() as $searchPage)
			{
				$text = pageStore()->read($searchPage) ?? '';
				foreach ($imgNames as $imgName)
				{
					if ( uploadReferences()->count($text, basename($imgName)) > 0 )
					{
						$imgPages[$imgName][] = $searchPage;
					}
				}
			}
		}

		$columns = array(array(translate('Name'), 'name'), array(translate('Usage'), null),
			array(translate('Modified'), 'recent'), array(translate('Size'), 'size'), array(translate('Action'), null));
		if (SHOW_PAGES_WHERE_FILE_USED)
		{
			$columns[] = array(translate('Used on page'), null);
		}
		$date_format = __('date_format', TITLE_DATE);
		$rows = array();
		foreach ($items as $img)
		{
			$baseImgName = basename($img['name']);
			$usedOn = array();
			foreach ($imgPages[$img['name']] ?? array() as $usedPage)
			{
				$usedOn[] = array('href' => pageURL($usedPage), 'name' => $usedPage);
			}
			$rows[] = array(
				'thumbnail' => in_array(getFileExt($baseImgName), \ImageExtensions, true) ? uploadURL($baseImgName) : null,
				'name' => $baseImgName, 'markdown' => imageLinkText($baseImgName),
				'modified' => date($date_format, $img['recent']), 'size' => humanFilesize($img['size']),
				'renameHref' => SELF."?action=imgRename&prevpage=".urlencode($prevPage)."&imgName=".urlencode($baseImgName),
				'deleteHref' => SELF."?action=imgDelete&prevpage=".urlencode($prevPage)."&imgName=".urlencode($baseImgName),
				'pages' => $usedOn);
		}
		return templates()->render('upload-list', array(
			'total' => count($imgNames), 'head' => sortableHeadings('upload', $sortBy, $columns),
			'showUsage' => SHOW_PAGES_WHERE_FILE_USED, 'rows' => $rows));
	}

	/** The pages which contain the term (pages with the term in their name first), and a link to create a page of that name */
	public function search(string $q): Html
	{
		$matches = 0;
		$results = array();
		$create = null;
		if ( trim($q) != "" )
		{
			$found = false;
			$matchingPages = array();
			foreach (getAllPageNames() as $searchPage)
			{
				if (strcasecmp($searchPage, $q) == 0)
				{
					$found = true;
				}
				if (stripos($searchPage, $q) !== false)
				{
					array_unshift($matchingPages, $searchPage);
					++$matches;
				}
				else if ( stripos(pageStore()->read($searchPage) ?? '', $q) !== false )
				{
					$matchingPages[] = $searchPage;
					++$matches;
				}
			}
			foreach ($matchingPages as $matchingPage)
			{
				$results[] = array('href' => pageURL($matchingPage), 'name' => $matchingPage, 'exact' => (strcasecmp($matchingPage, $q) == 0));
			}
			if (!$found)
			{
				$create = array('href' => pageURL($q), 'label' => translate('Create page')." '".$q."'");
			}
		}
		return templates()->render('search', array(
			'heading' => translate('Search').": ".$q, 'searched' => (trim($q) != ""), 'results' => $results, 'create' => $create, 'matches' => $matches
		));
	}

	/**
	 * The pages which link to a page
	 *
	 * @return array[] the pages: href, name
	 */
	public function linksHere(string $page): array
	{
		$linksHere = array();
		foreach(getAllPageNames() as $searchPage)
		{
			if ( preg_match("/\[\[".preg_quote($page, '/')."/i", pageStore()->read($searchPage) ?? '') )
			{
				$linksHere[] = array('href' => pageURL($searchPage), 'name' => $searchPage);
			}
		}
		return $linksHere;
	}

	/**
	 * Pages with a name similar to the given one (a new page which might be a typing error)
	 *
	 * @return array[] the pages: href, name
	 */
	public function similarPages(string $name): array
	{
		$similarPages = array();
		foreach(getAllPageNames() as $pageName)
		{
			if (levenshtein(strtoupper($name), strtoupper($pageName)) < sqrt(min(strlen($name), strlen($pageName))) )
			{
				$similarPages[] = array('href' => pageURL($pageName), 'name' => $pageName);
			}
		}
		return $similarPages;
	}

	/**
	 * @param array[] $items	arrays with the keys name, recent, size
	 * @return array[]
	 */
	private function sorted(array $items, string $sortBy): array
	{
		usort($items, fn($a, $b) => ($sortBy === 'name') ? strnatcasecmp($a['name'], $b['name']) : $b[$sortBy] <=> $a[$sortBy]);
		return $items;
	}
}
