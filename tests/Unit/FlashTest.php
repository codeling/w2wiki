<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use W2\Flash;

/** The message for the user (W2\Flash): plain text in, escaped HTML out */
final class FlashTest extends TestCase
{
	private const HOSTILE = '<script>alert("1")</script> & \'quote\' <img src=x onerror=alert(1)>';
	private const HOSTILE_ESCAPED = '&lt;script&gt;alert(&quot;1&quot;)&lt;/script&gt; &amp; &#039;quote&#039; &lt;img src=x onerror=alert(1)&gt;';

	public function testEmptyMessage(): void
	{
		$flash = new Flash();
		$this->assertTrue($flash->isEmpty());
		$this->assertSame('', $flash->render());
		$this->assertSame([], $flash->toArray());
		$this->assertTrue((new Flash())->text('')->isEmpty(), 'empty text adds nothing');
	}

	public function testTextIsEscapedAndWrittenAsItIs(): void
	{
		$flash = (new Flash())->text('Saved ')->text(self::HOSTILE)->text(" \n end");
		$this->assertSame('Saved ' . self::HOSTILE_ESCAPED . " \n end", $flash->render());
		$this->assertFalse($flash->isEmpty());
	}

	public function testFormatReplacesEachPlaceByTheNextValueEscaped(): void
	{
		$flash = (new Flash())->format("Renamed %s to %s (<%s>)", [self::HOSTILE, 5, 'b']);
		$this->assertSame('Renamed ' . self::HOSTILE_ESCAPED . ' to 5 (&lt;b&gt;)', $flash->render());
	}

	public function testFormatTextIsEscapedToo(): void
	{
		$this->assertSame('a &lt;b&gt; c', (new Flash())->format('a <b> %s', ['c'])->render());
	}

	public function testFormatWithTooFewOrTooManyValues(): void
	{
		$this->assertSame('x  y ', (new Flash())->format('x %s y %s', [])->render());
		$this->assertSame('x 1 y', (new Flash())->format('x %s y', ['1', '2'])->render());
		$this->assertSame('no places', (new Flash())->format('no places', ['unused'])->render());
	}

	public function testFormatWithALink(): void
	{
		$flash = (new Flash())->format('Please %s the page, or %s.', [
			Flash::link('edit <it>', '?action=edit&page=' . urlencode('A & "B"')),
			Flash::link('read', 'https://example.com/a?b=c&d="e"'),
		]);
		$this->assertSame(
			'Please <a href="?action=edit&amp;page=A+%26+%22B%22">edit &lt;it&gt;</a> the page, or '
			. '<a href="https://example.com/a?b=c&amp;d=&quot;e&quot;">read</a>.',
			$flash->render()
		);
	}

	public static function addressesOfLinks(): array
	{
		return [
			'in the wiki' => ['/index.php/Home', true], 'query' => ['?action=edit&page=A', true],
			'https' => ['https://example.com/', true], 'http' => ['http://example.com/', true],
			'script' => ['javascript:alert(1)', false], 'script in capitals' => ['JavaScript:alert(1)', false],
			'data' => ['data:text/html,<script>alert(1)</script>', false], 'protocol relative' => ['//evil.example/', false],
			'backslash' => ['/\\evil.example/', false], 'relative' => ['page', false], 'empty' => ['', false],
			'leading space' => [' /index.php', false], 'other scheme' => ['ftp://example.com/', false],
		];
	}

	#[DataProvider('addressesOfLinks')]
	public function testOnlyAddressesInTheWikiAndOfWebSitesAreAcceptedForLinks(string $address, bool $accepted): void
	{
		if (!$accepted) {
			$this->expectException(\InvalidArgumentException::class);
		}
		$this->assertSame(['link', 'text', $address], Flash::link('text', $address));
	}

	public function testFormatWithABlock(): void
	{
		$flash = (new Flash())->format('Use %s to refer to it!', [Flash::block('![Image](/images/a<b>.gif)')]);
		$this->assertSame('Use <pre>![Image](/images/a&lt;b&gt;.gif)</pre> to refer to it!', $flash->render());
	}

	public function testLineBreaksCodeAndPreformattedText(): void
	{
		$flash = (new Flash())->text('a')->br()->text('b')->br(2)->code('<hash>')->text(' ')->preformatted('x & y');
		$this->assertSame('a<br/>b<br/><br/><code>&lt;hash&gt;</code> <pre>x &amp; y</pre>', $flash->render());
	}

	public function testLineBreaksAtTheStartAreLeftOut(): void
	{
		$this->assertSame('error', (new Flash())->br()->br(2)->text('error')->render());
		$this->assertSame('', (new Flash())->br()->render());
	}

	public function testItemsAreAHeadingAndAListWithEscapedTexts(): void
	{
		$flash = (new Flash())->text('Renamed')->items('Updated <links>:', ['A & B (2 matches)', self::HOSTILE]);
		$this->assertSame(
			"Renamed<br/>\nUpdated &lt;links&gt;:\n<ul><li>A &amp; B (2 matches)</li><li>" . self::HOSTILE_ESCAPED . '</li></ul>',
			$flash->render()
		);
		$this->assertSame("Updated:\n<ul><li>a</li></ul>", (new Flash())->items('Updated:', ['a'])->render(), 'no line break at the start');
	}

	public function testAppendSeparatesWithOneLineBreak(): void
	{
		$pull = (new Flash())->br()->text('pull failed');
		$this->assertSame('Saved<br/>pull failed', (new Flash())->text('Saved')->append($pull)->render());
		$this->assertSame('pull failed', (new Flash())->append($pull)->render(), 'nothing to separate from');
		$this->assertSame('Saved', (new Flash())->text('Saved')->append(new Flash())->render());
		$this->assertSame('Saved', (new Flash())->text('Saved')->append((new Flash())->br())->render(), 'only line breaks add nothing');
		$this->assertTrue((new Flash())->append(new Flash())->isEmpty());
	}

	public function testPartsSurviveTheSession(): void
	{
		$flash = (new Flash())->text('a')->br(2)->format('%s and %s', ['x', Flash::link('l', '/p')])->code('c')
			->preformatted('p')->items('h', ['i', 'j'])->format('%s', [Flash::block('b')]);
		$kept = unserialize(serialize($flash->toArray()));
		$this->assertSame($flash->render(), Flash::fromArray($kept)->render());
		$this->assertSame($flash->toArray(), Flash::fromArray(json_decode(json_encode($flash->toArray()), true))->toArray());
	}

	public static function invalidParts(): array
	{
		return [
			'not an array' => ['<b>html</b>'], 'null' => [null], 'a number' => [5],
			'an old message (a string of HTML)' => ['Saved<br/>x'],
			'unknown type' => [[['html', '<script>alert(1)</script>']]],
			'text with a number' => [[['text', 5]]], 'text without text' => [[['text']]], 'text with more' => [[['text', 'a', 'b']]],
			'type is not a string' => [[[5, 'a']]], 'not a list' => [[['type' => 'text', 'text' => 'a']]], 'a string as part' => [['text']],
			'br with text' => [[['br', 'x']]],
			'items with a number' => [[['items', 'h', ['a', 5]]]], 'items without a list' => [[['items', 'h', 'a']]],
			'format with a script link' => [[['format', '%s', [['link', 't', 'javascript:alert(1)']]]]],
			'format with a protocol relative link' => [[['format', '%s', [['link', 't', '//evil.example']]]]],
			'format with an unknown value' => [[['format', '%s', [['html', '<b>']]]]],
			'format with a number' => [[['format', '%s', [5]]]],
		];
	}

	#[DataProvider('invalidParts')]
	public function testAnythingWhichIsNoValidPartIsIgnored(mixed $parts): void
	{
		$flash = Flash::fromArray($parts);
		$this->assertTrue($flash->isEmpty());
		$this->assertSame('', $flash->render());
	}

	public function testValidPartsAreKeptNextToInvalidOnes(): void
	{
		$flash = Flash::fromArray([['text', 'a'], ['html', '<b>'], ['code', 'c'], 'junk']);
		$this->assertSame('a<code>c</code>', $flash->render());
	}
}
