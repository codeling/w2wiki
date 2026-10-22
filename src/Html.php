<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Text which is HTML already.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * A piece of HTML which is meant to be written as it is (and not escaped like text), like the result of a template or of
 * the Markdown renderer. Anything which is not wrapped by raw() is text. Only wrap what the code has made: never
 * anything from a request, a file name, or a page name.
 */
final class Html
{
	private function __construct(private readonly string $html)
	{
	}

	/** Mark HTML which is trusted (made by the code, or the output of the Markdown renderer) */
	public static function raw(string $html): self
	{
		return new self($html);
	}

	/** Text as HTML: the characters which have a meaning in HTML are replaced, also in the values of attributes */
	public static function escape(string $text): string
	{
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, W2_CHARSET);
	}

	public function __toString(): string
	{
		return $this->html;
	}
}
