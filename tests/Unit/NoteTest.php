<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use W2\Note;

/** The note for the user (W2\Note): plain text in, escaped HTML out */
final class NoteTest extends TestCase
{
	private const HOSTILE = '<script>alert("1")</script> & \'quote\' <img src=x onerror=alert(1)>';
	private const HOSTILE_ESCAPED = '&lt;script&gt;alert(&quot;1&quot;)&lt;/script&gt; &amp; &#039;quote&#039; &lt;img src=x onerror=alert(1)&gt;';

	public function testEmptyMessage(): void
	{
		$note = new Note();
		$this->assertTrue($note->isEmpty());
		$this->assertSame('', $note->render());
		$this->assertSame([], $note->toArray());
		$this->assertTrue((new Note())->text('')->isEmpty(), 'empty text adds nothing');
	}

	public function testTextIsEscapedAndWrittenAsItIs(): void
	{
		$note = (new Note())->text('Saved ')->text(self::HOSTILE)->text(" \n end");
		$this->assertSame('Saved ' . self::HOSTILE_ESCAPED . " \n end", $note->render());
		$this->assertFalse($note->isEmpty());
	}

	public function testFormatReplacesEachPlaceByTheNextValueEscaped(): void
	{
		$note = (new Note())->format("Renamed %s to %s (<%s>)", [self::HOSTILE, 5, 'b']);
		$this->assertSame('Renamed ' . self::HOSTILE_ESCAPED . ' to 5 (&lt;b&gt;)', $note->render());
	}

	public function testFormatTextIsEscapedToo(): void
	{
		$this->assertSame('a &lt;b&gt; c', (new Note())->format('a <b> %s', ['c'])->render());
	}

	public function testFormatWithTooFewOrTooManyValues(): void
	{
		$this->assertSame('x  y ', (new Note())->format('x %s y %s', [])->render());
		$this->assertSame('x 1 y', (new Note())->format('x %s y', ['1', '2'])->render());
		$this->assertSame('no places', (new Note())->format('no places', ['unused'])->render());
	}

	public function testFormatWithALink(): void
	{
		$note = (new Note())->format('Please %s the page, or %s.', [
			Note::link('edit <it>', '?action=edit&page=' . urlencode('A & "B"')),
			Note::link('read', 'https://example.com/a?b=c&d="e"'),
		]);
		$this->assertSame(
			'Please <a href="?action=edit&amp;page=A+%26+%22B%22">edit &lt;it&gt;</a> the page, or '
			. '<a href="https://example.com/a?b=c&amp;d=&quot;e&quot;">read</a>.',
			$note->render()
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
		$this->assertSame(['link', 'text', $address], Note::link('text', $address));
	}

	public function testALinkCanOpenInANewWindow(): void
	{
		$note = (new Note())->format('See the %s.', [Note::link('current <version>', '/index.php/A', true)]);
		$this->assertSame('See the <a href="/index.php/A" target="_blank" rel="noopener">current &lt;version&gt;</a>.', $note->render());
		// (and stays so when the note has been kept in the session)
		$this->assertSame($note->render(), Note::fromArray($note->toArray())->render());
	}

	public function testALinkWithAnUnknownFlagIsIgnored(): void
	{
		$this->assertTrue(Note::fromArray([['format', '%s', [['link', 't', '/p', 'x']]]])->isEmpty());
	}

	public function testFormatWithABlock(): void
	{
		$note = (new Note())->format('Use %s to refer to it!', [Note::block('![Image](/images/a<b>.gif)')]);
		$this->assertSame('Use <pre>![Image](/images/a&lt;b&gt;.gif)</pre> to refer to it!', $note->render());
	}

	public function testLineBreaksCodeAndPreformattedText(): void
	{
		$note = (new Note())->text('a')->br()->text('b')->br(2)->code('<hash>')->text(' ')->preformatted('x & y');
		$this->assertSame('a<br/>b<br/><br/><code>&lt;hash&gt;</code> <pre>x &amp; y</pre>', $note->render());
	}

	public function testLineBreaksAtTheStartAreLeftOut(): void
	{
		$this->assertSame('error', (new Note())->br()->br(2)->text('error')->render());
		$this->assertSame('', (new Note())->br()->render());
	}

	public function testItemsAreAHeadingAndAListWithEscapedTexts(): void
	{
		$note = (new Note())->text('Renamed')->items('Updated <links>:', ['A & B (2 matches)', self::HOSTILE]);
		$this->assertSame(
			"Renamed<br/>\nUpdated &lt;links&gt;:\n<ul><li>A &amp; B (2 matches)</li><li>" . self::HOSTILE_ESCAPED . '</li></ul>',
			$note->render()
		);
		$this->assertSame("Updated:\n<ul><li>a</li></ul>", (new Note())->items('Updated:', ['a'])->render(), 'no line break at the start');
	}

	public function testAppendSeparatesWithOneLineBreak(): void
	{
		$pull = (new Note())->br()->text('pull failed');
		$this->assertSame('Saved<br/>pull failed', (new Note())->text('Saved')->append($pull)->render());
		$this->assertSame('pull failed', (new Note())->append($pull)->render(), 'nothing to separate from');
		$this->assertSame('Saved', (new Note())->text('Saved')->append(new Note())->render());
		$this->assertSame('Saved', (new Note())->text('Saved')->append((new Note())->br())->render(), 'only line breaks add nothing');
		$this->assertTrue((new Note())->append(new Note())->isEmpty());
	}

	public function testPartsSurviveTheSession(): void
	{
		$note = (new Note())->text('a')->br(2)->format('%s and %s', ['x', Note::link('l', '/p')])->code('c')
			->preformatted('p')->items('h', ['i', 'j'])->format('%s', [Note::block('b')]);
		$kept = unserialize(serialize($note->toArray()));
		$this->assertSame($note->render(), Note::fromArray($kept)->render());
		$this->assertSame($note->toArray(), Note::fromArray(json_decode(json_encode($note->toArray()), true))->toArray());
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
		$note = Note::fromArray($parts);
		$this->assertTrue($note->isEmpty());
		$this->assertSame('', $note->render());
	}

	public function testValidPartsAreKeptNextToInvalidOnes(): void
	{
		$note = Note::fromArray([['text', 'a'], ['html', '<b>'], ['code', 'c'], 'junk']);
		$this->assertSame('a<code>c</code>', $note->render());
	}
}
