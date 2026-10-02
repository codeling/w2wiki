<?php

namespace W2\Tests\Support;

/** Base class for tests with SVG uploads enabled and the sanitizer library installed */
abstract class SvgAppTestCase extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['SVG_UPLOADS_ENABLED' => true];
	}

	protected function serverOptions(): array
	{
		return ['svgSanitizer' => true];
	}

	protected function svgFixture(string $name): string
	{
		return (string)file_get_contents(dirname(__DIR__) . "/fixtures/svg/$name.svg");
	}

	protected function uploadSvg(string $fileName, string $content, array $extra = []): HttpResponse
	{
		return $this->upload($fileName, $content, 'image/svg+xml', $extra);
	}

	/**
	 * Assert that a (sanitized) SVG file contains nothing that runs scripts or loads other content
	 */
	protected function assertSafeSvg(string $svg): void
	{
		$this->assertDoesNotMatchRegularExpression('/javascript:|vbscript:|data:text|evil\.example|<!ENTITY|<!DOCTYPE|@import|xml-stylesheet/i', $svg);

		$document = new \DOMDocument();
		$this->assertTrue(@$document->loadXML($svg, LIBXML_NONET), 'the stored file must be well-formed XML');
		$this->assertSame('svg', $document->documentElement->localName);
		$this->assertNull($document->doctype);
		foreach ($document->childNodes as $node) {
			$this->assertNotSame(XML_PI_NODE, $node->nodeType, 'processing instructions must be removed');
		}
		foreach ($document->getElementsByTagName('*') as $element) {
			$name = strtolower($element->localName);
			$this->assertNotContains($name, ['script', 'foreignobject', 'iframe', 'object', 'embed', 'handler', 'listener'], "<$name> element");
			foreach ($element->attributes as $attribute) {
				$attributeName = strtolower($attribute->localName);
				$this->assertStringStartsNotWith('on', $attributeName, "$attributeName attribute on <$name>");
				if ($attributeName === 'href') {
					$this->assertStringStartsWith('#', trim($attribute->value), "external reference in <$name>");
				}
				if ($attributeName === 'style') {
					$this->assertDoesNotMatchRegularExpression('/url\(\s*[^#\s)]|expression\(/i', $attribute->value);
				}
			}
			if ($name === 'style') {
				$this->assertDoesNotMatchRegularExpression('/url\(\s*[^#\s)]|@import/i', $element->textContent);
			}
		}
	}
}
