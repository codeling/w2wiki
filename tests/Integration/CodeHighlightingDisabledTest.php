<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

final class CodeHighlightingDisabledTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['CODE_HIGHLIGHTING' => false];
	}

	public function testCodeBlocksAreOnlyEscaped(): void
	{
		$this->savePage('Code', "```php\n// see [[Home]]\necho '<b>';\n```\n");
		$body = $this->http->get($this->pageUrl('Code'))->body;
		$this->assertStringContainsString("<pre><code class=\"php\">// see [[Home]]\necho '&lt;b&gt;';\n</code></pre>", $body);
		$this->assertStringNotContainsString('hl-', $body);
	}
}
