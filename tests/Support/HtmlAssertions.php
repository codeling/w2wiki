<?php

namespace W2\Tests\Support;

/** Helpers to check HTML output (for use in PHPUnit test cases) */
trait HtmlAssertions
{
	protected function dom(string $html): \DOMDocument
	{
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$document->loadHTML('<?xml encoding="UTF-8">' . $html);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		return $document;
	}

	/** @return \DOMElement[] */
	protected function elements(string $html, string $tag): array
	{
		return iterator_to_array($this->dom($html)->getElementsByTagName($tag));
	}

	/**
	 * Assert that the HTML contains nothing a browser would run: no scripts
	 * (except wiki.js), no event handler attributes (except the wiki's own),
	 * no javascript:/data: URLs and no frames or plugins.
	 */
	protected function assertNoActiveContent(string $html, bool $allowInlineScripts = false): void
	{
		$ownHandlers = ['toggleDrawer(); return false;', 'history.go(-1);'];
		$urlAttributes = ['href', 'src', 'action', 'formaction', 'xlink:href', 'data', 'poster', 'background'];
		foreach ($this->dom($html)->getElementsByTagName('*') as $element) {
			$tag = strtolower($element->nodeName);
			if ($tag === 'script') {
				if ($element->hasAttribute('src')) {
					$this->assertSame('wiki.js', $element->getAttribute('src'), 'unexpected external script');
				} elseif (!$allowInlineScripts) {
					$this->fail('inline <script> found: ' . substr($element->textContent, 0, 80));
				}
			}
			$this->assertNotContains($tag, ['iframe', 'object', 'embed', 'applet', 'base'], "unexpected <$tag> element");
			foreach ($element->attributes as $attribute) {
				$name = strtolower($attribute->name);
				if (str_starts_with($name, 'on')) {
					$this->assertContains($attribute->value, $ownHandlers, "event handler attribute $name on <$tag>");
				}
				if (in_array($name, $urlAttributes, true)) {
					$url = strtolower(preg_replace('/[\x00-\x20\x7f]+/', '', $attribute->value));
					$this->assertDoesNotMatchRegularExpression('/^(javascript|vbscript|data):/', $url, "dangerous URL in $name of <$tag>");
				}
			}
		}
	}
}
