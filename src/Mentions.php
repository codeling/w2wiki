<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Finds where the name of a page is used in the text of other pages.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Finds the links to a page and the places where its name is mentioned without a link, and makes such a mention
 * a link. All methods work on the text of one page and get what they need as parameters.
 *
 * Pages can be in folders, but the links and mentions usually have the file name only ("Firefox" for the
 * page "Software/Firefox"), so everything here looks for the file name ($term, see {@see fileName()}).
 */
final class Mentions
{
	private const LETTER = '[\p{L}\p{N}_]';

	/** The part of a page name which is used to look for links and mentions: the file name, without the folders */
	public static function fileName(string $page): string
	{
		return trim(basename(str_replace('\\', '/', $page)));
	}

	/**
	 * Whether the text has a link to a page of the file name which is not a page which exists (so that it is a link
	 * which the page of that name, created now, would make a working link: "[[Firefox]]", "[[Software/Firefox|text]]";
	 * a link to another page "Other/Firefox" which exists isn't about the new page)
	 *
	 * @param callable $exists	gets a page name, returns whether the page exists
	 */
	public static function hasLinkTo(string $text, string $term, callable $exists): bool
	{
		if ($term === '' || !preg_match_all('/\[\[([^\]|#]*?)([|#][^\]]*)?\]\]/u', $text, $matches))
		{
			return false;
		}
		foreach ($matches[1] as $target)
		{
			if (strcasecmp(self::fileName($target), $term) === 0 && !$exists(sanitizeFilename($target)))
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * The first place in the text where the term is written in running text (not as part of a longer word, and
	 * not in code, in a link or in an address).
	 *
	 * @return array{offset: int, length: int, snippet: string}|null	byte position and length of the term in the text,
	 *								the text around it
	 */
	public static function find(string $text, string $term): ?array
	{
		if ($term === '' || !preg_match(self::pattern($term), self::masked($text), $match, PREG_OFFSET_CAPTURE))
		{
			return null;
		}
		$offset = $match[0][1];
		$length = strlen($match[0][0]);
		return array('offset' => $offset, 'length' => $length, 'snippet' => self::snippet($text, $offset, $length));
	}

	/**
	 * The text with the first mention of the term made a link to the target page (a name like "Software/Firefox"),
	 * or null if there is none
	 */
	public static function link(string $text, string $term, string $target): ?string
	{
		$found = self::find($text, $term);
		if ($found === null)
		{
			return null;
		}
		$written = substr($text, $found['offset'], $found['length']);
		$link = '[[' . $target . ($written === $target ? '' : '|' . $written) . ']]';
		return substr($text, 0, $found['offset']) . $link . substr($text, $found['offset'] + $found['length']);
	}

	private static function pattern(string $term): string
	{
		return '/(?<!' . self::LETTER . ')' . preg_quote($term, '/') . '(?!' . self::LETTER . ')/iu';
	}

	/** The text with the parts which must not get a link replaced by filler of the same length (so positions stay valid) */
	private static function masked(string $text): string
	{
		$patterns = array(
			'/^[ \t]*(`{3,}|~{3,})[^\n]*\n.*?(?:^[ \t]*\1[^\n]*$|\z)/ms',	// fenced code
			'/(`+)(?!`).*?(?<!`)\1(?!`)/s',					// inline code
			'/!?\[\[.*?\]\]/s',							// page links and embeds
			'/!?\[[^\]]*\]\([^)]*\)/',						// Markdown links and images
			'/^[ \t]*\[[^\]]+\]:[^\n]*$/m',						// link definitions
			'/<?\b[a-z][a-z0-9+.\-]*:\/\/[^\s>]*>?/i',				// addresses
		);
		foreach ($patterns as $pattern)
		{
			$text = preg_replace_callback($pattern, fn($m) => str_repeat("\x01", strlen($m[0])), $text) ?? $text;
		}
		return $text;
	}

	/** The line with the term, shortened to some text around it */
	private static function snippet(string $text, int $offset, int $length): string
	{
		$start = strrpos(substr($text, 0, $offset), "\n");
		$start = ($start === false) ? 0 : $start + 1;
		$end = strpos($text, "\n", $offset + $length);
		$end = ($end === false) ? strlen($text) : $end;
		$before = ltrim(rtrim(substr($text, $start, $offset - $start), "\r"));
		$after = rtrim(substr($text, $offset + $length, $end - $offset - $length));
		$term = substr($text, $offset, $length);
		$before = mb_strlen($before) > 40 ? '…' . mb_substr($before, -40) : $before;
		$after = mb_strlen($after) > 60 ? mb_substr($after, 0, 60) . '…' : $after;
		return $before . $term . $after;
	}
}
