<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** The answer of api.php?task=newpage, which the editor of a new page shows while the title is entered */
final class NewPageHintsTest extends AppTestCase
{
	public function testAnExistingTitleIsReportedWithLinksToThePage(): void
	{
		$this->savePage('Software/Firefox', 'x');
		$this->savePage('Notes', 'I like Firefox, and Software/Firefox.');
		$answer = $this->newPageHints('Software/Firefox');
		$this->assertTrue($answer['exists']);
		$this->assertStringContainsString('<div class="note pageexists">A page with the title <a href="/index.php/Software/Firefox">Software/Firefox</a> exists already.', $answer['html']);
		$this->assertStringContainsString('<a href="/index.php?action=edit&amp;page=Software%2FFirefox">edit</a> that page instead.', $answer['html']);
		$this->assertStringNotContainsString('Creating new page', $answer['html']);
		$this->assertStringNotContainsString('pagecontext', $answer['html'], 'the page is not new');
	}

	public function testTheTitleIsMadeLikeTheOneWhichIsSaved(): void
	{
		$this->savePage('A-B', 'x');
		foreach (['A:B', 'A|B', 'A..B', 'A#-B', 'A-B'] as $title) {
			$this->assertTrue($this->newPageHints($title)['exists'], "$title becomes A-B");
		}
		$this->assertFalse($this->newPageHints('AB')['exists']);
	}

	public function testAFreeTitleIsConfirmed(): void
	{
		$answer = $this->newPageHints('Something New');
		$this->assertFalse($answer['exists']);
		$this->assertStringContainsString('<div class="note">Creating new page since no page with given title exists!', $answer['html']);
	}

	public function testWithoutATitleThereIsNothingToSay(): void
	{
		foreach (['', '   ', '#', '# #'] as $title) {
			$this->assertSame(['exists' => false, 'html' => ''], $this->newPageHints($title), "'$title'");
		}
	}

	public function testAVeryLongTitleIsCutAndAnswered(): void
	{
		$answer = $this->newPageHints(str_repeat('ä', 5000));
		$this->assertFalse($answer['exists']);
		$this->assertStringContainsString('Creating new page', $answer['html']);
	}

	public function testTheFormsOfTheMentionsHaveTheTokenOfTheSession(): void
	{
		$this->savePage('Notes', 'A firefox here.');
		$html = $this->newPageHints('Firefox')['html'];
		$this->assertStringContainsString('<form class="linkmention" method="post" action="/index.php"><input type="hidden" name="csrf_token" value="' . $this->csrfToken() . '" />', $html);
	}
}
