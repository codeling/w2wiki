<?php

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\AppTestCase;

/** The page which the upload, image rename and image delete pages return to must be an existing page */
final class PreviousPageTest extends AppTestCase
{
	public static function invalidPages(): array
	{
		return [
			'missing page' => ['No such page'],
			'script' => ['"><script>alert(1)</script>'],
			'empty' => [''],
			'parent folder' => ['../config'],
			'url' => ['http://example.com/'],
			'uploads folder' => ['images/a'],
		];
	}

	public static function formActions(): array
	{
		return [
			'upload (page)' => [['action' => 'upload'], 'page'],
			'image rename' => [['action' => 'imgRename', 'imgName' => 'a.gif'], 'prevpage'],
			'image delete' => [['action' => 'imgDelete', 'imgName' => 'a.gif'], 'prevpage'],
		];
	}

	#[DataProvider('formActions')]
	public function testFormsRefusePagesWhichDoNotExist(array $query, string $parameter): void
	{
		foreach (self::invalidPages() as $description => [$page]) {
			$response = $this->http->get('/index.php', $query + [$parameter => $page]);
			$this->assertSame(400, $response->status, $description);
			$this->assertStringContainsString('Invalid page name', $response->body);
			$this->assertStringNotContainsString('<script>', $response->body);
		}
	}

	#[DataProvider('formActions')]
	public function testFormsKeepExistingPagesWithSpecialNames(array $query, string $parameter): void
	{
		foreach (['A+B', 'x%41y', 'C D', '100%', 'sub/page', 'Q-A?'] as $name) {
			$this->savePage($name, 'text');
			$response = $this->http->get('/index.php', $query + [$parameter => $name]);
			$this->assertSame(200, $response->status, $name);
			$this->assertStringContainsString('name="prevpage" value="' . htmlspecialchars($name, ENT_QUOTES) . '"', $response->body, $name);
		}
	}

	#[DataProvider('formActions')]
	public function testDefaultPageIsUsedIfNoPageIsGiven(array $query, string $parameter): void
	{
		$response = $this->http->get('/index.php', $query);
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('name="prevpage" value="Home"', $response->body);
	}

	public function testUploadReturnsToTheGivenPage(): void
	{
		$this->savePage('Gallery', 'text');
		$response = $this->postAction('uploaded', ['userfile' => new \W2\Tests\Support\CurlFileLike(self::gif(), 'a.gif', 'image/gif'), 'prevpage' => 'Gallery']);
		$this->assertSame(303, $response->status);
		$this->assertSame('/index.php/Gallery', $response->location());
	}

	public function testUploadWithoutPreviousPageReturnsToTheDefaultPage(): void
	{
		$response = $this->postAction('uploaded', ['userfile' => new \W2\Tests\Support\CurlFileLike(self::gif(), 'a.gif', 'image/gif')]);
		$this->assertSame(303, $response->status);
		$this->assertSame('/index.php/Home', $response->location());
	}

	public function testImageActionsReturnToTheGivenPage(): void
	{
		$this->savePage('Gallery', 'text');
		$this->upload('a.gif', self::gif());
		$response = $this->postAction('imgRenamed', ['oldPageName' => 'a.gif', 'newName' => 'b.gif', 'prevpage' => 'Gallery']);
		$this->assertSame('/index.php/Gallery', $response->location());
		$response = $this->postAction('imgDeleted', ['oldPageName' => 'b.gif', 'prevpage' => 'Gallery']);
		$this->assertSame('/index.php/Gallery', $response->location());
	}

	public function testUploadLinkOfThePageBeingCreatedLeadsToAWorkingUploadPage(): void
	{
		$page = $this->http->get($this->pageUrl('Not yet existing'));
		$this->assertStringContainsString('Creating new page', $page->body);
		$this->assertSame(1, preg_match('/href="([^"]*action=upload[^"]*)"/', $page->body, $matches));
		$upload = $this->http->get(html_entity_decode($matches[1]));
		$this->assertSame(200, $upload->status);
		$this->assertStringContainsString('type="file"', $upload->body);
	}

	public function testUploadLinkOfExistingPagesReturnsThere(): void
	{
		$this->savePage('Page with + sign', 'text');
		$page = $this->http->get($this->pageUrl('Page with + sign'));
		preg_match('/href="([^"]*action=upload[^"]*)"/', $page->body, $matches);
		$upload = $this->http->get(html_entity_decode($matches[1]));
		$this->assertSame(200, $upload->status);
		$this->assertStringContainsString('name="prevpage" value="Page with + sign"', $upload->body);
	}
}
