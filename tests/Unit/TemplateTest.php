<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use W2\Html;
use W2\Template;

/** Templates (W2\Template): text is escaped by default, HTML only when it is marked as such (W2\Html) */
final class TemplateTest extends TestCase
{
	private const HOSTILE = '<script>alert("1")</script> & \'x\'';
	private const HOSTILE_ESCAPED = '&lt;script&gt;alert(&quot;1&quot;)&lt;/script&gt; &amp; &#039;x&#039;';

	private string $folder;
	private Template $templates;

	protected function setUp(): void
	{
		$this->folder = sys_get_temp_dir() . '/w2-template-test-' . bin2hex(random_bytes(6));
		mkdir($this->folder);
		$files = [
			'show' => '<?= $this->text ?>|<?= $this->raw ?>|<?= $this->number ?>',
			'list' => '<?php foreach ($this->items as $item): ?><li title="<?= $item["title"] ?>"><?= $item["name"] ?></li><?php endforeach ?>',
			'nested' => "[<?= \$this->render('show', ['text' => 'in <b>', 'raw' => \\W2\\Html::raw('<i>'), 'number' => 1]) ?>]<?= \$this->text ?>",
			'boom' => '<?php echo "partial output"; throw new \RuntimeException("template failed");',
			'scope' => '<?= isset($secret) ? "visible" : "hidden" ?>',
			'unknown' => '<?= $this->nothing ?>',
			'optional' => '<?= isset($this->maybe) ? "set" : "not set" ?>',
			'helper' => '<?= $this->e(strtolower("/A?b=1&c=\"2\"")) ?>|<?= $this->e("<b>") ?>|<?= $this->text ?>',
			'two-words' => 'ok',
		];
		foreach ($files as $name => $content) {
			file_put_contents("$this->folder/$name.php", $content);
		}
		$this->templates = new Template($this->folder);
	}

	protected function tearDown(): void
	{
		foreach (glob("$this->folder/*") as $file) {
			unlink($file);
		}
		rmdir($this->folder);
	}

	public function testTextIsEscapedAndHtmlIsNot(): void
	{
		$html = $this->templates->render('show', ['text' => self::HOSTILE, 'raw' => Html::raw('<b>bold</b>'), 'number' => 5]);
		$this->assertSame(self::HOSTILE_ESCAPED . '|<b>bold</b>|5', (string)$html);
	}

	public function testResultIsHtmlWhichIsNotEscapedAgain(): void
	{
		$inner = $this->templates->render('show', ['text' => '<', 'raw' => Html::raw(''), 'number' => 0]);
		$this->assertInstanceOf(Html::class, $inner);
		$outer = $this->templates->render('show', ['text' => '', 'raw' => $inner, 'number' => 0]);
		$this->assertSame('|&lt;||0|0', (string)$outer);
	}

	public function testTextInArraysIsEscaped(): void
	{
		$html = $this->templates->render('list', ['items' => [
			['title' => 'a "quoted" title', 'name' => '<one>'],
			['title' => self::HOSTILE, 'name' => Html::raw('<b>two</b>')],
		]]);
		$this->assertSame(
			'<li title="a &quot;quoted&quot; title">&lt;one&gt;</li><li title="' . self::HOSTILE_ESCAPED . '"><b>two</b></li>',
			(string)$html
		);
	}

	public function testNumbersBooleansAndNullAreWrittenAsPhpDoes(): void
	{
		$html = $this->templates->render('show', ['text' => null, 'raw' => true, 'number' => 1.5]);
		$this->assertSame('|1|1.5', (string)$html);
	}

	public function testOtherValuesAreRefused(): void
	{
		foreach ([new \stdClass(), fn() => 1, ['a' => [new \stdClass()]]] as $value) {
			try {
				$this->templates->render('show', ['text' => $value, 'raw' => '', 'number' => 0]);
				$this->fail('a value which can not be written was accepted');
			} catch (\InvalidArgumentException $e) {
				$this->assertStringContainsString('can only be given', $e->getMessage());
			}
		}
	}

	public function testTemplateCanRenderTemplatesWithValuesOfItsOwn(): void
	{
		$html = $this->templates->render('nested', ['text' => '<outer>']);
		$this->assertSame('[in &lt;b&gt;|<i>|1]&lt;outer&gt;', (string)$html);
	}

	public function testReadingAValueWhichWasNotGivenIsAnError(): void
	{
		$this->expectException(\OutOfBoundsException::class);
		$this->expectExceptionMessage("'nothing'");
		$this->templates->render('unknown', ['other' => 'x']);
	}

	public function testValuesCanBeTestedWithIsset(): void
	{
		$this->assertSame('not set', (string)$this->templates->render('optional'));
		$this->assertSame('not set', (string)$this->templates->render('optional', ['maybe' => null]));
		$this->assertSame('set', (string)$this->templates->render('optional', ['maybe' => '']));
	}

	public function testValuesDoNotLeakBetweenRenders(): void
	{
		$this->templates->render('show', ['text' => 'first', 'raw' => '', 'number' => 1]);
		$this->expectException(\OutOfBoundsException::class);
		$this->templates->render('show', []);
	}

	public function testTemplateDoesNotSeeTheVariablesOfTheCaller(): void
	{
		$secret = 'x';
		$this->assertSame('hidden', (string)$this->templates->render('scope', ['secret' => $secret]));
	}

	public function testEscapeHelperForStringsFromFunctions(): void
	{
		// (values are escaped when they are given: they must not be escaped again, only strings from functions need e())
		$this->assertSame('/a?b=1&amp;c=&quot;2&quot;|&lt;b&gt;|&lt;x&gt;', (string)$this->templates->render('helper', ['text' => '<x>']));
	}

	public function testOutputOfAFailingTemplateIsDiscarded(): void
	{
		$level = ob_get_level();
		try {
			$this->templates->render('boom');
			$this->fail('the exception of the template was not passed on');
		} catch (\RuntimeException $e) {
			$this->assertSame('template failed', $e->getMessage());
		}
		$this->assertSame($level, ob_get_level(), 'output buffers were left open');
	}

	public static function invalidNames(): array
	{
		return [
			'parent folder' => ['../show'], 'folder' => ['sub/show'], 'absolute' => ['/etc/passwd'], 'extension' => ['show.php'],
			'upper case' => ['Show'], 'space' => ['two words'], 'empty' => [''], 'starts with a digit' => ['1show'],
			'null byte' => ["show\0.txt"], 'dots' => ['..'],
		];
	}

	#[DataProvider('invalidNames')]
	public function testOnlyNamesOfTemplatesAreAccepted(string $name): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->templates->render($name);
	}

	public function testMissingTemplate(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('no template');
		$this->templates->render('does-not-exist');
	}

	public function testNamesMayHaveHyphensAndDigits(): void
	{
		$this->assertSame('ok', (string)$this->templates->render('two-words'));
	}

	public function testEscapingText(): void
	{
		$this->assertSame(self::HOSTILE_ESCAPED, Html::escape(self::HOSTILE));
		$this->assertSame('&lt;', Html::escape('<'));
		$this->assertSame('', Html::escape(''));
	}

	public function testHtmlIsWrittenAsItIs(): void
	{
		$this->assertSame('<b>&amp;</b>', (string)Html::raw('<b>&amp;</b>'));
	}

	public function testTemplatesOfTheWikiAreRenderedWithTheirValues(): void
	{
		// the templates of the wiki read exactly the values which their first comment lists
		$wiki = new Template(dirname(__DIR__, 2) . '/src/templates');
		$html = (string)$wiki->render('login', [
			'heading' => 'Log <In>', 'notes' => ['<b>no</b>'], 'passwordLabel' => 'Password', 'buttonLabel' => 'Go',
			'csrfField' => Html::raw('<input type="hidden" name="csrf_token" value="t"/>'),
		]);
		$this->assertStringContainsString('<h1>Log &lt;In&gt;</h1>', $html);
		$this->assertStringContainsString('<p class="note">&lt;b&gt;no&lt;/b&gt;</p>', $html);
		$this->assertStringContainsString('<input type="hidden" name="csrf_token" value="t"/>', $html);
		$this->assertStringContainsString('<input type="submit" value="Go">', $html);
	}
}
