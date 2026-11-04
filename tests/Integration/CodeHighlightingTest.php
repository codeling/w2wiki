<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Fenced code blocks with a language are highlighted in the page (CODE_HIGHLIGHTING, see src/Highlighter.php) */
final class CodeHighlightingTest extends AppTestCase
{
	private const TEXT = "Code:\n\n```php\n// see [[Home]]\necho '<b>[[Home|x]]</b>';\n```\n\n```\necho 1;\n```\n";

	public function testCodeBlocksWithALanguageAreHighlighted(): void
	{
		$this->savePage('Code', self::TEXT);
		$body = $this->http->get($this->pageUrl('Code'))->body;
		$this->assertStringContainsString('<pre><code class="php"><span class="hl-c">// see [[Home]]</span>' . "\n"
			. '<span class="hl-k">echo</span> <span class="hl-s">\'&lt;b&gt;[[Home|x]]&lt;/b&gt;\'</span>;' . "\n</code></pre>", $body);
		// (without a language: as before)
		$this->assertStringContainsString("<pre><code>echo 1;\n</code></pre>", $body);
		$this->assertStringNotContainsString('href="/index.php/Home"', $this->elements($body, 'pre')[0]->C14N());
		$this->assertNoActiveContent($body);
	}

	public function testCodeInEmbeddedPagesIsHighlighted(): void
	{
		$this->savePage('Embedded code', "```sql\nSELECT 1\n```\n");
		$this->savePage('Embedding', "![[Embedded code]]\n");
		$body = $this->http->get($this->pageUrl('Embedding'))->body;
		$this->assertStringContainsString('<pre><code class="sql"><span class="hl-k">SELECT</span> <span class="hl-n">1</span>', $body);
	}
}
