<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** The list of uploads, renaming and deleting a file agree about which pages refer to it (W2\UploadReferences) */
final class UploadReferencesTest extends AppTestCase
{
	private const PDF = "%PDF-1.4\n%%EOF\n";

	public function testDeletingAnImageKeepsEarlierImagesAndTextOnTheSameLine(): void
	{
		$this->upload('a.gif', self::gif());
		$this->upload('b.gif', self::gif());
		$this->savePage('Gallery', '![first](/images/b.gif) some text ![second](/images/a.gif) end');
		$this->deleteImage('a.gif');
		$this->assertSame('![first](/images/b.gif) some text  end', $this->pageText('Gallery'));
	}

	public function testRenamingAFileUpdatesLinksToIt(): void
	{
		$this->upload('doc.pdf', self::PDF, 'application/pdf');
		$this->savePage('Docs', 'Read the [manual](/images/doc.pdf) or the [manual again](/images/doc.pdf "Manual").');
		$note = $this->noteAfter($this->renameImage('doc.pdf', 'manual.pdf'));
		$this->assertStringContainsString('Image renamed', $note);
		$this->assertStringContainsString('Docs (2 matches)', $note);
		$this->assertSame('Read the [manual](/images/manual.pdf) or the [manual again](/images/manual.pdf "Manual").', $this->pageText('Docs'));
	}

	public function testDeletingAFileLeavesLinksToIt(): void
	{
		$this->upload('doc.pdf', self::PDF, 'application/pdf');
		$this->savePage('Docs', 'Read the [manual](/images/doc.pdf). ![](/images/doc.pdf)');
		$this->deleteImage('doc.pdf');
		$this->assertSame('Read the [manual](/images/doc.pdf). ', $this->pageText('Docs'));
	}

	/** The pages which the list of uploads shows for a file are exactly those which renaming the file updates */
	public function testListOfUploadsAndRenamingAgree(): void
	{
		$this->upload('a.gif', self::gif());
		$forms = [
			'AsImage' => '![x](/images/a.gif)',
			'WithTitle' => '![x](/images/a.gif "T")',
			'WithSingleQuotedTitle' => "![x](/images/a.gif 'T')",
			'AsLink' => '[x](/images/a.gif)',
			'TwiceOnALine' => '![x](/images/a.gif) ![y](/images/a.gif)',
		];
		$notReferences = [
			'OtherFile' => '![x](/images/b.gif)',
			'LongerName' => '![x](/images/a.gifx)',
			'OtherCase' => '![x](/images/A.GIF)',
			'JustText' => 'The file /images/a.gif is mentioned',
		];
		foreach ($forms + $notReferences as $page => $text) {
			$this->savePage($page, $text);
		}
		$list = $this->http->get('/index.php', ['action' => 'upload'])->body;
		foreach (array_keys($forms) as $page) {
			$this->assertStringContainsString("<a href=\"/index.php/$page\">$page</a>", $list, "listed: $page");
		}
		foreach (array_keys($notReferences) as $page) {
			$this->assertStringNotContainsString("<a href=\"/index.php/$page\">$page</a>", $list, "not listed: $page");
		}

		$this->renameImage('a.gif', 'z.gif');
		foreach ($forms as $page => $text) {
			$this->assertSame(str_replace('a.gif', 'z.gif', $text), $this->pageText($page), "updated: $page");
		}
		foreach ($notReferences as $page => $text) {
			$this->assertSame($text, $this->pageText($page), "unchanged: $page");
		}
	}
}
