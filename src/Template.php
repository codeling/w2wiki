<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Templates for the HTML of the pages.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Renders the templates in a folder (PHP files which are mostly HTML). A template reads its values as `$this->name`.
 *
 * Text is escaped by default: every string given to a template is escaped before it gets there, so that
 * `<?= $this->title ?>` is safe, whatever the title is. HTML which is meant to be written as it is has to be
 * wrapped explicitly (Html::raw()), and the result of a template is such HTML, so templates can be put into others
 * with `$this->render('name', [...])`. Pass plain text: a value that is escaped already would be escaped twice.
 * Arrays of values (lists, rows) are escaped inside.
 *
 * Inside a template, texts which are translated are written with __(), which escapes, and strings from other
 * functions (addresses of files, ...) with $this->e().
 *
 * Reading a value which was not given is an error, so a misspelled name can't silently print nothing.
 */
final class Template
{
	/** @var array<string, mixed> the values of the template being rendered, already escaped */
	private array $values = [];

	/** @param string $folder	the folder with the templates, without trailing slash */
	public function __construct(private readonly string $folder)
	{
	}

	/**
	 * @param string $name	name of a template, the file `$name.php` in the folder (lower case letters, digits, hyphens)
	 * @param array<string, mixed> $values	text (escaped when it is read), Html, numbers, and arrays of them
	 */
	public function render(string $name, array $values = []): Html
	{
		if (!preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
			throw new \InvalidArgumentException("invalid name of a template: $name");
		}
		$file = "$this->folder/$name.php";
		if (!is_file($file)) {
			throw new \RuntimeException("no template $name in $this->folder");
		}
		$scope = clone $this;
		$scope->values = array_map([self::class, 'prepare'], $values);
		$level = ob_get_level();
		ob_start();
		try {
			(function (string $file): void {
				include $file;
			})->call($scope, $file);
			return Html::raw((string)ob_get_clean());
		} catch (\Throwable $e) {
			while (ob_get_level() > $level) {
				ob_end_clean();
			}
			throw $e;
		}
	}

	/** Text as HTML, for strings which come from functions instead of from the values of the template */
	public function e(string $text): string
	{
		return Html::escape($text);
	}

	public function __get(string $name): mixed
	{
		if (!array_key_exists($name, $this->values)) {
			throw new \OutOfBoundsException("the template was not given a value '$name'");
		}
		return $this->values[$name];
	}

	public function __isset(string $name): bool
	{
		return isset($this->values[$name]);
	}

	/** Escape the strings of a value, and refuse what can't be written */
	private static function prepare(mixed $value): mixed
	{
		if (is_string($value)) {
			return Html::escape($value);
		}
		if (is_array($value)) {
			return array_map([self::class, 'prepare'], $value);
		}
		if ($value === null || is_scalar($value) || $value instanceof Html) {
			return $value;
		}
		throw new \InvalidArgumentException('a template can only be given text, numbers, Html and arrays of them, not ' . get_debug_type($value));
	}
}
