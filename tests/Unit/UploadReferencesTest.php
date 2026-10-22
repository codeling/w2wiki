<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use W2\UploadReferences;

/** References to uploaded files in the Markdown text of pages (W2\UploadReferences) */
final class UploadReferencesTest extends TestCase
{
	private UploadReferences $refs;

	protected function setUp(): void
	{
		$this->refs = new UploadReferences('', 'images');
	}

	public static function referencesFound(): array
	{
		return [
			'image' => ['![alt](/images/a.gif)', 1],
			'image with title' => ['![alt](/images/a.gif "A title")', 1],
			'image with single quoted title' => ["![alt](/images/a.gif 'A title')", 1],
			'image with parenthesis title' => ['![alt](/images/a.gif (A title))', 1],
			'link' => ['[a document](/images/a.gif)', 1],
			'empty alt text' => ['![](/images/a.gif)', 1],
			'two on a line' => ['![x](/images/a.gif) and [y](/images/a.gif)', 2],
			'on several lines' => ["![x](/images/a.gif)\n\n![y](/images/a.gif)", 2],
			'none' => ['no reference here', 0],
			'other file' => ['![x](/images/b.gif)', 0],
			'longer name' => ['![x](/images/a.gifx) ![y](/images/aa.gif) ![z](/images/a.gif.bak)', 0],
			'other case' => ['![x](/images/A.GIF)', 0],
			'other folder' => ['![x](/pictures/a.gif) ![y](/other/images/a.gif) ![z](images/a.gif)', 0],
			'address as text, not as address' => ['see /images/a.gif or (/images/a.gif)', 0],
			'external address' => ['![x](https://example.com/images/a.gif)', 0],
		];
	}

	#[DataProvider('referencesFound')]
	public function testCount(string $text, int $expected): void
	{
		$this->assertSame($expected, $this->refs->count($text, 'a.gif'));
	}

	public function testNamesAreTakenLiterally(): void
	{
		$text = '![x](/images/a(1).gif) ![y](/images/aXb.gif) ![z](/images/a.b.gif)';
		$this->assertSame(1, $this->refs->count($text, 'a(1).gif'));
		$this->assertSame(1, $this->refs->count($text, 'a.b.gif'), '"." is not a wildcard');
		$this->assertSame(0, $this->refs->count($text, 'a.*'));
	}

	public function testRenameChangesLinksAndImagesAndKeepsTheRest(): void
	{
		$text = "![alt](/images/a.gif \"T\") [doc](/images/a.gif) ![other](/images/b.gif) /images/a.gif";
		$this->assertSame(
			["![alt](/images/c.gif \"T\") [doc](/images/c.gif) ![other](/images/b.gif) /images/a.gif", 2],
			$this->refs->rename($text, 'a.gif', 'c.gif')
		);
	}

	public function testRenameTakesTheNewNameLiterally(): void
	{
		$this->assertSame(['![x](/images/c$1\\0.gif)', 1], $this->refs->rename('![x](/images/a.gif)', 'a.gif', 'c$1\\0.gif'));
	}

	public function testRenameWithoutReferencesChangesNothing(): void
	{
		$this->assertSame(['text ![x](/images/b.gif)', 0], $this->refs->rename('text ![x](/images/b.gif)', 'a.gif', 'c.gif'));
	}

	public static function removals(): array
	{
		return [
			'image' => ['before ![alt](/images/a.gif) after', 'before  after', 1],
			'image with titles' => ['![x](/images/a.gif "T") ![y](/images/a.gif \'T\') ![z](/images/a.gif (T))', '  ', 3],
			'empty alt text' => ['![](/images/a.gif)', '', 1],
			'alt text with escaped bracket' => ['![a \] b](/images/a.gif)', '', 1],
			'link is kept' => ['[doc](/images/a.gif)', '[doc](/images/a.gif)', 0],
			'other images are kept' => ['![x](/images/b.gif) ![y](/images/a.gif)', '![x](/images/b.gif) ', 1],
			'the image before is not removed with the text between' => [
				'![first](/images/b.gif) some text ![second](/images/a.gif) end',
				'![first](/images/b.gif) some text  end', 1,
			],
			'images before on the line' => ['![1](/images/b.gif)![2](/images/c.gif)![3](/images/a.gif)', '![1](/images/b.gif)![2](/images/c.gif)', 1],
			'line breaks end the alt text' => ["![broken\n](/images/a.gif)", "![broken\n](/images/a.gif)", 0],
		];
	}

	#[DataProvider('removals')]
	public function testRemoveImages(string $text, string $expected, int $count): void
	{
		$this->assertSame([$expected, $count], $this->refs->removeImages($text, 'a.gif'));
	}

	public function testBaseAddressAndUploadAddress(): void
	{
		$refs = new UploadReferences('/w2', 'pics');
		$text = '![a](/w2/pics/a.gif) ![b](/pics/a.gif) ![c](/other/pics/a.gif) ![d](/w2/images/a.gif) ![e](/w2/pics/b.gif)';
		$this->assertSame(2, $refs->count($text, 'a.gif'), 'with and without the base address');
		$this->assertSame(
			['![a](/w2/pics/z.gif) ![b](/pics/z.gif) ![c](/other/pics/a.gif) ![d](/w2/images/a.gif) ![e](/w2/pics/b.gif)', 2],
			$refs->rename($text, 'a.gif', 'z.gif')
		);
		$this->assertSame(
			['  ![c](/other/pics/a.gif) ![d](/w2/images/a.gif) ![e](/w2/pics/b.gif)', 2],
			$refs->removeImages($text, 'a.gif')
		);
	}

	public function testUploadAddressWithSubfolder(): void
	{
		$refs = new UploadReferences('', 'files/images');
		$this->assertSame(1, $refs->count('![x](/files/images/a.gif) ![y](/images/a.gif)', 'a.gif'));
	}
}
