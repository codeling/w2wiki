<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Support;

/** Markdown texts which try to inject scripts, for data providers */
final class MaliciousMarkdown
{
	public static function all(): array
	{
		return [
			'heading anchor attribute injection' => ['# a"/onmouseover="alert(2) & b'],
			'javascript link' => ['[c](javascript:alert(3))'],
			'mixed case javascript link' => ['[c](JaVaScRiPt:alert(3))'],
			'entity encoded javascript link' => ['[e](&#106;avascript:alert(4))'],
			'data link' => ['[f](data:text/html,<script>alert(1)</script>)'],
			'vbscript link' => ['[f](vbscript:msgbox(1))'],
			'javascript image' => ['![i](javascript:alert(6))'],
			'javascript reference link' => ["[r][1]\n\n[1]: javascript:alert(7)"],
			'javascript autolink' => ['<javascript:alert(5)>'],
			'raw script tag' => ['<script>alert(8)</script>'],
			'raw img tag' => ['<img src=x onerror=alert(9)>'],
			'raw html link' => ['<a href="javascript:alert(10)">x</a>'],
			'page link text' => ['[[Home|<img src=x onerror=alert(11)>]]'],
			'page link target' => ['[[a" onmouseover="alert(12)]]'],
			'highlighted html code' => ["```html\n<script>alert(13)</script>\n<img src=x onerror=alert(14)>\n```"],
			'highlighted code closing the block' => ["```js\n</code></pre><script>alert(15)</script>\n```"],
			'highlighted unterminated string' => ["```php\n\"</span><img src=x onerror=alert(16)>\n```"],
			'highlighted comment' => ["```css\n/* </span></code></pre><svg onload=alert(17)> */\n```"],
		];
	}
}
