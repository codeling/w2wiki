<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use W2\Mentions;

/** Links to a page, and mentions of its name, in the text of other pages */
final class MentionsTest extends TestCase
{
	public function testFileNameIsTheLastPartOfThePageName(): void
	{
		$this->assertSame('Firefox', Mentions::fileName('Software/Firefox'));
		$this->assertSame('Firefox', Mentions::fileName('Firefox'));
		$this->assertSame('', Mentions::fileName(''));
	}

	public function testLinksAreFoundByFileNameWhateverTheFolderAndTheForm(): void
	{
		$none = fn($page) => false;
		foreach (['[[Firefox]]', '[[firefox]]', '[[Software/Firefox]]', '[[Other/Firefox|a browser]]', '[[Firefox#Add-ons]]'] as $text) {
			$this->assertTrue(Mentions::hasLinkTo("See $text.", 'Firefox', $none), $text);
		}
		foreach (['[[Firefoxes]]', '[[Fire]]', 'Firefox', '[[Home|Firefox]]', '`x`[Firefox](a)'] as $text) {
			$this->assertFalse(Mentions::hasLinkTo("See $text.", 'Firefox', $none), $text);
		}
	}

	public function testLinksToExistingPagesAreNotAboutTheNewPage(): void
	{
		$exists = fn($page) => $page === 'Other/Firefox';
		$this->assertFalse(Mentions::hasLinkTo('[[Other/Firefox]]', 'Firefox', $exists));
		$this->assertTrue(Mentions::hasLinkTo('[[Firefox]]', 'Firefox', $exists));
	}

	public function testRegexCharactersInTheNameAreLiteral(): void
	{
		$this->assertTrue(Mentions::hasLinkTo('[[C++ (language)]]', 'C++ (language)', fn($p) => false));
		$this->assertNull(Mentions::find('C+ language', 'C++'));
		$this->assertNotNull(Mentions::find('We use C++ here', 'C++'));
	}

	public function testMentionIsFoundInRunningTextOnly(): void
	{
		$found = Mentions::find("Intro\n\nI use firefox daily, and Firefoxes too.", 'Firefox');
		$this->assertNotNull($found);
		$this->assertSame('I use firefox daily, and Firefoxes too.', $found['snippet']);
		$this->assertSame('firefox', substr("Intro\n\nI use firefox daily, and Firefoxes too.", $found['offset'], $found['length']));
		foreach ([
			'only Firefoxes and MyFirefox', "```\nFirefox\n```", 'inline `Firefox` code', 'a [[Firefox]] link',
			'a [Firefox](https://example.org) link', 'see https://example.org/Firefox', '![Firefox](a.png)', "[Firefox]: https://x.org",
		] as $text) {
			$this->assertNull(Mentions::find($text, 'Firefox'), $text);
		}
		$this->assertNotNull(Mentions::find("```\ncode\n```\nFirefox", 'Firefox'), 'after a code block');
	}

	public function testMentionWithAccentsAndSnippetIsShortened(): void
	{
		$text = str_repeat('é', 100) . ' Firefox ' . str_repeat('ü', 100);
		$found = Mentions::find($text, 'Firefox');
		$this->assertSame('…' . str_repeat('é', 39) . ' Firefox ' . str_repeat('ü', 59) . '…', $found['snippet']);
	}

	public function testFirstMentionBecomesALinkWithTheWrittenTextAsLabel(): void
	{
		$this->assertSame("A [[Software/Firefox|firefox]] and Firefox.", Mentions::link("A firefox and Firefox.", 'Firefox', 'Software/Firefox'));
		$this->assertSame("A [[Firefox]] and Firefox.", Mentions::link("A Firefox and Firefox.", 'Firefox', 'Firefox'));
		$this->assertSame("`Firefox` [[Software/Firefox|Firefox]]", Mentions::link("`Firefox` Firefox", 'Firefox', 'Software/Firefox'));
		$this->assertNull(Mentions::link("`Firefox`", 'Firefox', 'Software/Firefox'));
	}
}
