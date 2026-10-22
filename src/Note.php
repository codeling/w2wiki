<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * The note which tells the user what has happened.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * A note for the user ("Saved", with the result of git commands, a list of updated pages, ...), which is
 * shown on the next page, or on the page which is shown in response to the request.
 * (It is the `<div class="note">` at the top of a page.)
 *
 * Everything which is added is plain text, not HTML: it is escaped when the note is shown, so that a page
 * name, a file name or anything else from a request can't add markup to it, and no caller has to remember
 * to escape. The only markup is what the methods make of the parts, like links and lists.
 *
 * The parts are plain arrays of strings (toArray(), fromArray()), so that the note can be kept in the
 * session until it is shown.
 */
final class Note
{
	/** Addresses which links may have: within the wiki ("/page", "?action=edit"; not "//host"), or of a web site */
	private const ADDRESS = '~^(?:/(?![/\\\\])|\?|https?://)~';

	/** @var list<array<string, mixed>> */
	private array $parts = [];

	/** Plain text, which is written as it is (with the spaces and line breaks of the caller) */
	public function text(string $text): self
	{
		if ($text !== '') {
			$this->parts[] = ['text', $text];
		}
		return $this;
	}

	/**
	 * Text with places for values, as translated texts have them: each "%s" is replaced by the next value, which
	 * is plain text, a link (see link()), or a block of text to copy (see block()).
	 *
	 * @param string $format	plain text with "%s" for the values
	 * @param array<int, string|int|array<int, string>> $values
	 */
	public function format(string $format, array $values = []): self
	{
		$this->parts[] = ['format', $format, array_map(fn($value) => is_array($value) ? $value : (string)$value, array_values($values))];
		return $this;
	}

	/**
	 * A link, for use as a value of format(). Only addresses within the wiki and of web sites are accepted.
	 *
	 * @return array{0: string, 1: string, 2: string}
	 */
	public static function link(string $text, string $address): array
	{
		if (!preg_match(self::ADDRESS, $address)) {
			throw new \InvalidArgumentException('not an address within the wiki or of a web site');
		}
		return ['link', $text, $address];
	}

	/**
	 * Text for the user to copy, in a block of its own, for use as a value of format()
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function block(string $text): array
	{
		return ['pre', $text];
	}

	/** A line break (or more) */
	public function br(int $count = 1): self
	{
		$this->parts[] = ['br', max(1, $count)];
		return $this;
	}

	/** Code within a line, like a hash or a command */
	public function code(string $text): self
	{
		$this->parts[] = ['code', $text];
		return $this;
	}

	/** Text for the user to copy, in a block of its own */
	public function preformatted(string $text): self
	{
		$this->parts[] = ['pre', $text];
		return $this;
	}

	/**
	 * A heading in a line of its own, followed by a bulleted list
	 *
	 * @param string[] $items	plain texts
	 */
	public function items(string $heading, array $items): self
	{
		$this->parts[] = ['items', $heading, array_values(array_map('strval', $items))];
		return $this;
	}

	/**
	 * Add what another note says after this one, separated from it by a line break (if this one says
	 * anything); line breaks at the start of the other note are not needed then, and left out
	 */
	public function append(self $other): self
	{
		$parts = $other->parts;
		while ($parts !== [] && $parts[0][0] === 'br') {
			array_shift($parts);
		}
		if ($parts !== []) {
			if ($this->parts !== []) {
				$this->br();
			}
			$this->parts = array_merge($this->parts, $parts);
		}
		return $this;
	}

	public function isEmpty(): bool
	{
		return $this->parts === [];
	}

	/** @return list<array<string, mixed>> */
	public function toArray(): array
	{
		return $this->parts;
	}

	/**
	 * The note as it has been kept (see toArray()); anything which isn't a valid part is ignored
	 *
	 * @param mixed $parts
	 */
	public static function fromArray(mixed $parts): self
	{
		$note = new self();
		foreach (is_array($parts) ? $parts : [] as $part) {
			if (self::isValidPart($part)) {
				$note->parts[] = $part;
			}
		}
		return $note;
	}

	private static function isValidPart(mixed $part): bool
	{
		if (!is_array($part) || !array_is_list($part) || !is_string($part[0] ?? null)) {
			return false;
		}
		switch ($part[0]) {
			case 'text':
			case 'code':
			case 'pre':
				return count($part) === 2 && is_string($part[1]);
			case 'br':
				return count($part) === 2 && is_int($part[1]);
			case 'items':
				return count($part) === 3 && is_string($part[1]) && is_array($part[2]) && count(array_filter($part[2], 'is_string')) === count($part[2]);
			case 'format':
				if (count($part) !== 3 || !is_string($part[1]) || !is_array($part[2])) {
					return false;
				}
				foreach ($part[2] as $value) {
					$isLink = is_array($value) && count($value) === 3 && $value[0] === 'link' && is_string($value[1]) && is_string($value[2])
						&& preg_match(self::ADDRESS, $value[2]) === 1;
					$isBlock = is_array($value) && count($value) === 2 && $value[0] === 'pre' && is_string($value[1]);
					if (!is_string($value) && !$isLink && !$isBlock) {
						return false;
					}
				}
				return true;
		}
		return false;
	}

	/**
	 * The note as HTML (to be put in an element, no other markup is needed). Line breaks at the start are
	 * left out, as there is nothing to separate from.
	 */
	public function render(): string
	{
		$html = '';
		foreach ($this->parts as $part) {
			switch ($part[0]) {
				case 'text':
					$html .= self::escape($part[1]);
					break;
				case 'code':
					$html .= '<code>' . self::escape($part[1]) . '</code>';
					break;
				case 'pre':
					$html .= '<pre>' . self::escape($part[1]) . '</pre>';
					break;
				case 'br':
					$html .= $html === '' ? '' : str_repeat('<br/>', $part[1]);
					break;
				case 'items':
					$html .= ($html === '' ? '' : "<br/>\n") . self::escape($part[1]) . "\n<ul><li>"
						. implode('</li><li>', array_map([self::class, 'escape'], $part[2])) . '</li></ul>';
					break;
				case 'format':
					$html .= $this->renderFormat($part[1], $part[2]);
					break;
			}
		}
		return $html;
	}

	/** @param array<int, string|array<int, string>> $values */
	private function renderFormat(string $format, array $values): string
	{
		$html = '';
		foreach (explode('%s', $format) as $i => $segment) {
			if ($i > 0) {
				$value = $values[$i - 1] ?? '';
				if (!is_array($value)) {
					$html .= self::escape($value);
				} elseif ($value[0] === 'link') {
					$html .= '<a href="' . self::escape($value[2]) . '">' . self::escape($value[1]) . '</a>';
				} else {
					$html .= '<pre>' . self::escape($value[1]) . '</pre>';
				}
			}
			$html .= self::escape($segment);
		}
		return $html;
	}

	private static function escape(string $text): string
	{
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, W2_CHARSET);
	}
}
