<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * The Markdown parser of the wiki.
 */

namespace W2;

use Michelf\MarkdownExtra;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * PHP Markdown Extra with a limit for the nesting of blockquotes: each level re-processes and re-indents everything
 * inside it, so the memory needed grows with the square of the depth (a page of 1000 ">" needs more than 512 MB).
 * At $maxBlockquoteDepth no further blockquotes are looked for, and deeper ">" markers are shown as text.
 * The same option (max_blockquote_depth) is proposed for PHP Markdown itself; with it, this class can go.
 */
class MarkdownParser extends MarkdownExtra
{
	public int $maxBlockquoteDepth = 20;

	/** Nesting depth of the blockquote being processed */
	private int $blockquoteDepth = 0;

	protected function doBlockQuotes($text)
	{
		if ( $this->blockquoteDepth >= $this->maxBlockquoteDepth )
		{
			return $text;
		}
		return parent::doBlockQuotes($text);
	}

	protected function _doBlockQuotes_callback($matches)
	{
		// the content of the blockquote is processed (and searched for blockquotes) by this callback
		$this->blockquoteDepth++;
		try
		{
			return parent::_doBlockQuotes_callback($matches);
		}
		finally
		{
			$this->blockquoteDepth--;
		}
	}
}
