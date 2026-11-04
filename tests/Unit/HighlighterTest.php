<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use W2\Highlighter;
use W2\MarkdownParser;

final class HighlighterTest extends TestCase
{
	/** Canonical languages, with a sample of their code */
	private const SAMPLES = [
		'php' => "<?php\n// a comment\n#[Attribute]\nfunction f(int \$a): ?string { return \"x \$a\\\"\" . 'y' . 0x1F; } # hash\n/* two\nlines */\n\$s = <<<EOT\nit's <here>\nEOT;\nEcho TRUE;\n?>",
		'javascript' => "const x = `template \${a}\nline 2`; // comment\nlet y = 'it\\'s' + \"q\" + 42n; /* c */ function f() { return null; }",
		'typescript' => "interface A { b: string; type: number } // c\nconst a: A = { b: 'x', type: 1 } as A;",
		'json' => "{\"a\": 1, \"b\": [true, null, -2.5e3], \"c\": \"x\\\"y\"}",
		'python' => "@decorator\ndef f(self, x=1):\n    '''doc\n    string'''\n    return f\"{x}\" + rb'raw'  # comment\nprint(None)",
		'bash' => "\$ echo \"\$HOME\" 'single' # comment\nFOO=bar ls /usr/bin/test \${x} \$# a#b\nif [ -f x ]; then exit 1; fi",
		'css' => "/* c */\n@media (max-width: 600px) {\n  a:hover, .col-2 { color: #fff; margin: 0 -1px; background: url(http://x/y.png) !important; --bg: red; }\n}",
		'html' => "<!DOCTYPE html>\n<!-- c -->\n<a href=\"x\" class='y' data-x=z disabled>T &amp; t</a>\n<![CDATA[ x ]]>",
		'sql' => "SELECT a, COUNT(*) FROM t WHERE b = 'it''s' AND c = :p -- c\n/* m */ AND d::int = 1 AND e IS NULL",
		'yaml' => "---\n# c\nkey: value # c2\nlist:\n  - name: \"q\"\n    on: yes\n    n: 12\nanchor: &a x\nref: *a\ntag: !!str 1",
		'ini' => "; c\n[section]\nkey = \"v\" # c\nn = 1\nflag = true\n[[toml.table]]",
		'diff' => "diff --git a/x b/x\nindex 1..2\n--- a/x\n+++ b/x\n@@ -1,2 +1,2 @@\n context\n-old\n+new\n\\ No newline at end of file",
		'c' => "#include <stdio.h>\n/* c */ int main(void) { char c = '\\n'; printf(\"%d\\n\", 0x10UL); return NULL; } // e",
		'cpp' => "auto s = R\"(raw \" text)\"; std::vector<int> v = nullptr; // c",
		'java' => "@Override public String toString() { return \"\"\"\n  text\n  \"\"\"; } // c",
		'csharp' => "var s = @\"verbatim \"\"q\"\" \"; string t = \$\"{x}\"; #region r",
		'go' => "func main() { s := `raw\nline`; var r rune = 'x'; fmt.Println(nil) } // c",
		'rust' => "fn f<'a>(x: &'a str) -> Option<u32> { let r = r#\"raw\"#; println!(\"{}\", 1u32); None } #[derive(Debug)]",
		'kotlin' => "fun main() { val x: Int = 1; println(\"\$x\") } // c",
		'swift' => "func f() -> Int { let x: Int? = nil; return 1 } // c",
	];

	private static function span(string $class, string $html): string
	{
		return '<span class="' . $class . '">' . $html . '</span>';
	}

	/** The text of highlighted HTML (without the spans, unescaped), which must be the code */
	private static function text(string $html): string
	{
		return htmlspecialchars_decode((string)preg_replace('#<span class="hl-[a-z-]+">|</span>#', '', $html), ENT_NOQUOTES);
	}

	private function assertWellFormed(string $html): void
	{
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $document->loadXML('<code>' . $html . '</code>');
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$this->assertTrue($loaded && $errors === [], "not well-formed: $html");
		foreach ($document->getElementsByTagName('*') as $element) {
			$this->assertContains($element->nodeName, ['code', 'span']);
			if ($element->nodeName === 'span') {
				$this->assertSame(1, $element->attributes->length);
				$this->assertMatchesRegularExpression('/^hl-[a-z]+(-[a-z]+)?$/', $element->getAttribute('class'));
				// (the spans are never nested)
				$this->assertSame('code', $element->parentNode->nodeName);
			}
		}
	}

	public static function samples(): array
	{
		$cases = [];
		foreach (self::SAMPLES as $language => $code) {
			$cases[$language] = [$language, $code];
		}
		return $cases;
	}

	#[DataProvider('samples')]
	public function testSamplesAreHighlightedWellFormedAndKeepTheirText(string $language, string $code): void
	{
		$html = Highlighter::highlight($code, $language);
		$this->assertStringContainsString('<span class="hl-', $html);
		$this->assertWellFormed($html);
		$this->assertSame($code, self::text($html));
	}

	public static function tokens(): array
	{
		return [
			'php open tag' => ['php', '<?php', self::span('hl-p', '&lt;?php')],
			'php keyword' => ['php', 'function f', self::span('hl-k', 'function') . ' f'],
			'php keywords without case' => ['php', 'ECHO True', self::span('hl-k', 'ECHO') . ' ' . self::span('hl-n', 'True')],
			'php variable' => ['php', '$this->a', self::span('hl-v', '$this') . '-&gt;a'],
			'php type' => ['php', '(int $a)', '(' . self::span('hl-t', 'int') . ' ' . self::span('hl-v', '$a') . ')'],
			'php string with escaped quote' => ['php', '"a\"b" c', self::span('hl-s', '"a\"b"') . ' c'],
			'php string over lines' => ['php', "'a\nb'", self::span('hl-s', "'a\nb'")],
			'php hash comment' => ['php', '# x', self::span('hl-c', '# x')],
			'php attribute' => ['php', '#[A]', self::span('hl-p', '#[') . 'A]'],
			'php heredoc' => ['php', "<<<'X'\na \"b\nX;", self::span('hl-s', "&lt;&lt;&lt;'X'\na \"b\nX") . ';'],
			'php number' => ['php', '1_000.5', self::span('hl-n', '1_000.5')],
			'js template literal' => ['javascript', '`a${b}`', self::span('hl-s', '`a${b}`')],
			'js identifier with $' => ['javascript', '$if if', '$if ' . self::span('hl-k', 'if')],
			'js builtin' => ['javascript', 'Math.max', self::span('hl-t', 'Math') . '.max'],
			'js block comment' => ['javascript', "/* a\nb */x", self::span('hl-c', "/* a\nb */") . 'x'],
			'ts keyword is no js keyword' => ['javascript', 'type', 'type'],
			'ts keyword' => ['typescript', 'type', self::span('hl-k', 'type')],
			'json key' => ['json', '{"a" : "b"}', '{' . self::span('hl-a', '"a"') . ' : ' . self::span('hl-s', '"b"') . '}'],
			'json negative number' => ['json', '[-1]', '[' . self::span('hl-n', '-1') . ']'],
			'python triple quotes' => ['python', "\"\"\"a\n\"b\"\"\" c", self::span('hl-s', "\"\"\"a\n\"b\"\"\"") . ' c'],
			'python prefix' => ['python', "b'x'", self::span('hl-s', "b'x'")],
			'python identifier ending like a prefix' => ['python', "buf'x'", "buf" . self::span('hl-s', "'x'")],
			'python decorator' => ['python', '@app.route', self::span('hl-p', '@app.route')],
			'python self' => ['python', 'self.x', self::span('hl-v', 'self') . '.x'],
			'bash prompt' => ['bash', '$ ls', self::span('hl-p', '$') . ' ls'],
			'bash comment' => ['bash', 'a # b', 'a ' . self::span('hl-c', '# b')],
			'bash no comment inside a word' => ['bash', 'a#b', 'a#b'],
			'bash special variable' => ['bash', 'echo $#', self::span('hl-t', 'echo') . ' ' . self::span('hl-v', '$#')],
			'bash assignment' => ['bash', 'A=1', self::span('hl-v', 'A') . '=1'],
			'bash no builtin in a path' => ['bash', '/bin/test', '/bin/test'],
			'bash single quotes' => ['bash', "'\$a \\'", self::span('hl-s', "'\$a \\'")],
			'css property' => ['css', 'a { color: red }', 'a { ' . self::span('hl-a', 'color') . ': red }'],
			'css selector is no property' => ['css', 'a:hover {', 'a:hover {'],
			'css colors and units' => ['css', '#fa0 1.5em 50%', self::span('hl-n', '#fa0') . ' ' . self::span('hl-n', '1.5em') . ' ' . self::span('hl-n', '50%')],
			'css no number in a name' => ['css', '.col-2 h1', '.col-2 h1'],
			'css url' => ['css', 'url(http://x/a.png)', self::span('hl-s', 'url(http://x/a.png)')],
			'css at-rule and important' => ['css', '@import !important', self::span('hl-k', '@import') . ' ' . self::span('hl-k', '!important')],
			'css custom property' => ['css', 'var(--a-b)', 'var(' . self::span('hl-v', '--a-b') . ')'],
			'html tag' => ['html', '<p class="x">', '&lt;' . self::span('hl-k', 'p') . ' ' . self::span('hl-a', 'class') . '=' . self::span('hl-s', '"x"') . '&gt;'],
			'html closing tag' => ['html', '</p>', '&lt;/' . self::span('hl-k', 'p') . '&gt;'],
			'html unquoted value' => ['html', '<a b=c>', '&lt;' . self::span('hl-k', 'a') . ' ' . self::span('hl-a', 'b') . '=' . self::span('hl-s', 'c') . '&gt;'],
			'html quote with >' => ['html', '<a b="1>2">', '&lt;' . self::span('hl-k', 'a') . ' ' . self::span('hl-a', 'b') . '=' . self::span('hl-s', '"1&gt;2"') . '&gt;'],
			'html comment' => ['html', '<!-- <a> -->', self::span('hl-c', '&lt;!-- &lt;a&gt; --&gt;')],
			'html entity' => ['html', 'a &amp; b', 'a ' . self::span('hl-n', '&amp;amp;') . ' b'],
			'html text with <' => ['html', 'a < b', 'a &lt; b'],
			'xml declaration' => ['html', '<?xml version="1.0"?>', self::span('hl-p', '&lt;?xml version="1.0"?&gt;')],
			'sql keywords without case' => ['sql', 'select x From t', self::span('hl-k', 'select') . ' x ' . self::span('hl-k', 'From') . ' t'],
			'sql doubled quote' => ['sql', "'a''b' c", self::span('hl-s', "'a''b'") . ' c'],
			'sql quoted identifier' => ['sql', '"select"', '"select"'],
			'sql parameter' => ['sql', 'a = :id', 'a = ' . self::span('hl-v', ':id')],
			'sql cast' => ['sql', 'a::int', 'a::' . self::span('hl-t', 'int')],
			'yaml key in a list' => ['yaml', '- name: x', '- ' . self::span('hl-a', 'name') . ': x'],
			'yaml colon in a value' => ['yaml', 'url: http://x', self::span('hl-a', 'url') . ': http://x'],
			'yaml boolean' => ['yaml', 'a: Yes', self::span('hl-a', 'a') . ': ' . self::span('hl-n', 'Yes')],
			'yaml boolean only as a whole value' => ['yaml', 'a: yes please', self::span('hl-a', 'a') . ': yes please'],
			'yaml document' => ['yaml', "---\na: 1", self::span('hl-p', '---') . "\n" . self::span('hl-a', 'a') . ': ' . self::span('hl-n', '1')],
			'ini section and key' => ['ini', "[a]\nb=1", self::span('hl-k', '[a]') . "\n" . self::span('hl-a', 'b') . '=' . self::span('hl-n', '1')],
			'ini comment only after a space' => ['ini', 'a = b;c', self::span('hl-a', 'a') . ' = b;c'],
			'diff lines' => ['diff', "@@ -1 +1 @@\n-a\n+b\n c", self::span('hl-p', '@@ -1 +1 @@') . "\n" . self::span('hl-d-del', '-a') . "\n" . self::span('hl-d-add', '+b') . "\n c"],
			'diff file names' => ['diff', "--- a\n+++ b", self::span('hl-p', '--- a') . "\n" . self::span('hl-p', '+++ b')],
			'c preprocessor' => ['c', '#include <a.h>', self::span('hl-p', '#include') . ' &lt;a.h&gt;'],
			'c char' => ['c', "'\\''", self::span('hl-s', "'\\''")],
			'rust lifetime is no char' => ['rust', "&'a str", "&amp;'a " . self::span('hl-t', 'str')],
			'rust raw string' => ['rust', 'r##"a"#b"##', self::span('hl-s', 'r##"a"#b"##')],
			'go raw string' => ['go', "`a\\`", self::span('hl-s', "`a\\`")],
			'csharp verbatim string' => ['csharp', '@"a\\ ""b"" " + c', self::span('hl-s', '@"a\\ ""b"" "') . ' + c'],
			'java annotation' => ['java', '@Test void', self::span('hl-p', '@Test') . ' ' . self::span('hl-t', 'void')],
			'kotlin keyword' => ['kotlin', 'fun', self::span('hl-k', 'fun')],
			'swift constant' => ['swift', 'nil', self::span('hl-n', 'nil')],
			'cpp keyword is no c keyword' => ['c', 'class', 'class'],
			'cpp keyword' => ['cpp', 'class', self::span('hl-k', 'class')],
		];
	}

	#[DataProvider('tokens')]
	public function testTokens(string $language, string $code, string $expected): void
	{
		$this->assertSame($expected, Highlighter::highlight($code, $language));
	}

	public static function aliases(): array
	{
		return [
			['js', 'javascript'], ['jsx', 'javascript'], ['ts', 'typescript'], ['tsx', 'typescript'], ['py', 'python'],
			['sh', 'bash'], ['shell', 'bash'], ['zsh', 'bash'], ['console', 'bash'], ['scss', 'css'], ['xml', 'html'],
			['svg', 'html'], ['h', 'c'], ['hpp', 'cpp'], ['cs', 'csharp'], ['rs', 'rust'], ['kt', 'kotlin'],
			['yml', 'yaml'], ['toml', 'ini'], ['patch', 'diff'], ['golang', 'go'], ['jsonc', 'json'],
			['PHP', 'php'], ['JavaScript', 'javascript'], ['language-php', 'php'], ['.py', 'python'],
		];
	}

	#[DataProvider('aliases')]
	public function testAliasesAndCase(string $alias, string $language): void
	{
		$this->assertSame($language, Highlighter::language($alias));
		$code = self::SAMPLES[$language];
		$this->assertSame(Highlighter::highlight($code, $language), Highlighter::highlight($code, $alias));
	}

	public static function unknownLanguages(): array
	{
		return [[''], ['text'], ['plain'], ['brainfuck'], ['c++'], ['php-'], ['__proto__'], ['0']];
	}

	/** Without a known language, the code is escaped as PHP Markdown does it */
	#[DataProvider('unknownLanguages')]
	public function testUnknownLanguagesGiveTheEscapedCode(string $language): void
	{
		$code = "<b> & \"q\" 'a' // if\n";
		$this->assertNull(Highlighter::language($language));
		$this->assertSame(htmlspecialchars($code, ENT_NOQUOTES), Highlighter::highlight($code, $language));
	}

	public static function payloads(): array
	{
		$payloads = [
			'script' => '<script>alert(1)</script>',
			'img' => '<img src=x onerror=alert(1)>',
			'closing the block' => '</code></pre><script>alert(1)</script>',
			'closing a span' => '"</span><script>alert(1)</script>',
			'in a comment' => '/* </span><svg onload=alert(1)> */ # <b> -- <i>',
			'in a string' => "'<img src=x onerror=alert(1)>' \"<a href='javascript:alert(1)'>\" `<x>`",
			'attribute breakout' => '<a class="x" onclick="alert(1)" b=\'"><script>alert(1)</script>\'>',
			'entities' => '&lt;script&gt; &#60;script&#62; &amp;',
			'unterminated' => "\"<script>\n'<script>\n/* <script>\n<!-- <script>",
			'heredoc' => "<<<X\n</span><script>alert(1)</script>\nX;",
		];
		$cases = [];
		foreach (array_keys(self::SAMPLES) as $language) {
			foreach ($payloads as $name => $payload) {
				$cases["$language: $name"] = [$language, $payload];
			}
		}
		return $cases;
	}

	/** Everything is escaped: the only tags are the spans, the text is the code */
	#[DataProvider('payloads')]
	public function testPayloadsAreEscaped(string $language, string $code): void
	{
		$html = Highlighter::highlight($code, $language);
		$this->assertDoesNotMatchRegularExpression('#<(?!span class="hl-[a-z-]+">|/span>)#', $html);
		$this->assertWellFormed($html);
		$this->assertSame($code, self::text($html));
	}

	public function testLargeCodeIsNotHighlighted(): void
	{
		$code = str_repeat("echo '<a>';\n", intdiv(Highlighter::MAX_SIZE, 12) + 1);
		$this->assertGreaterThan(Highlighter::MAX_SIZE, strlen($code));
		$this->assertSame(htmlspecialchars($code, ENT_NOQUOTES), Highlighter::highlight($code, 'php'));
		$this->assertStringContainsString('hl-k', Highlighter::highlight(substr($code, 0, Highlighter::MAX_SIZE), 'php'));
	}

	/** (with invalid UTF-8 the regular expressions fail; the result is that of htmlspecialchars(), as without highlighting) */
	public function testInvalidUtf8GivesTheResultOfPhpMarkdown(): void
	{
		$code = "echo '\xC3\x28';";
		$this->assertSame(htmlspecialchars($code, ENT_NOQUOTES), Highlighter::highlight($code, 'php'));
	}

	public function testUnicodeIsNotSplit(): void
	{
		$code = "\$größe = 'ä' . \"€\"; // ü\nfunction ñ() {}";
		$html = Highlighter::highlight($code, 'php');
		$this->assertStringContainsString(self::span('hl-v', '$größe'), $html);
		$this->assertTrue(mb_check_encoding($html, 'UTF-8'));
		$this->assertSame($code, self::text($html));
	}

	public static function pathologicalInputs(): array
	{
		$units = ['"\\', '/*', "'", '<a "', '(/[', 'a:a:', "<<<A\n", 'R"(', 'r#"', '"""', "'''", '- ', 'key ', '#', '$',
			'@', '`', '<!--', '&#', "\\\n", 'a=', ' : ', "[a\n", '1.', 'url(', '<?', "-\n", "+\n", 'é', "\"a\n", '<a b=', '0x'];
		$cases = [];
		foreach (array_keys(self::SAMPLES) as $language) {
			$cases[$language] = [$language, $units];
		}
		return $cases;
	}

	/** Every alternative of a pattern either fails at once or takes what it starts: the time grows linearly */
	#[DataProvider('pathologicalInputs')]
	public function testPathologicalInputsAreFast(string $language, array $units): void
	{
		foreach ($units as $unit) {
			$code = substr(str_repeat($unit, intdiv(Highlighter::MAX_SIZE, strlen($unit))), 0, Highlighter::MAX_SIZE);
			$code = mb_strcut($code, 0, strlen($code), 'UTF-8');
			$start = microtime(true);
			$html = Highlighter::highlight($code, $language);
			$this->assertLessThan(1.0, microtime(true) - $start, "$language: " . json_encode($unit));
			$this->assertSame($code, self::text($html), "$language: " . json_encode($unit));
		}
	}

	private static function markdown(string $text, bool $highlight): string
	{
		$parser = new MarkdownParser;
		$parser->no_markup = true;
		if ($highlight) {
			$parser->code_block_content_func = [Highlighter::class, 'highlight'];
		}
		return $parser->transform($text);
	}

	public function testFencedCodeBlocksWithALanguageAreHighlighted(): void
	{
		foreach (["```php\necho 1;\n```", "~~~ php\necho 1;\n~~~", "```PHP\necho 1;\n```"] as $text) {
			$this->assertMatchesRegularExpression(
				'#<pre><code class="(php|PHP)">' . preg_quote(self::span('hl-k', 'echo') . ' ' . self::span('hl-n', '1') . ";\n</code></pre>", '#') . '#',
				self::markdown($text, true)
			);
		}
	}

	public function testOtherCodeIsUnchanged(): void
	{
		$texts = [
			'indented' => "    if (a < b) echo '&';\n",
			'fence without a language' => "```\nif (a < b) echo '&';\n```",
			'unknown language' => "```text\nif (a < b) echo '&';\n```",
			'inline code' => "`if (a < b) echo '&';`",
			'language as attribute' => "```{.php}\nif (a < b) echo '&';\n```",
			'leading empty lines' => "```\n\n\nif\n```",
		];
		foreach ($texts as $name => $text) {
			$this->assertSame(self::markdown($text, false), self::markdown($text, true), $name);
		}
	}

	public function testLeadingEmptyLinesOfHighlightedBlocksStayLineBreaks(): void
	{
		$this->assertStringContainsString('<code class="php"><br /><br />' . self::span('hl-k', 'if'), self::markdown("```php\n\n\nif\n```", true));
	}
}
