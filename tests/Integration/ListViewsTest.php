<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** The lists (all pages, uploads, search results) and the icons in them are rendered by templates */
final class ListViewsTest extends AppTestCase
{
	public function testAllPagesListsNamesWithActionIcons(): void
	{
		$this->savePage('Alpha & Beta', 'x');
		$body = $this->http->get('/index.php', ['action' => 'all'])->body;
		$this->assertStringContainsString('<a href="/index.php/Alpha%20-%20Beta">Alpha - Beta</a>', $body);
		$this->assertStringContainsString('action=rename&amp;page=Alpha+-+Beta', $body);
		$this->assertStringContainsString('action=delete&amp;page=Alpha+-+Beta', $body);
		// the heading of the sorted column is no link
		$this->assertStringContainsString('<span class="sortBy">Name</span>', $body);
		$this->assertStringContainsString('sortBy=size', $body);
	}

	public function testUploadListEscapesNamesAndLinksTheActions(): void
	{
		$this->upload('a.gif', self::gif());
		$this->savePage('Gallery', '![x](/images/a.gif)');
		$body = $this->http->get('/index.php', ['action' => 'upload'])->body;
		$this->assertStringContainsString('<span class="uploadFileName">a.gif</span>', $body);
		$this->assertStringContainsString('class="thumbImg"', $body);
		$this->assertStringContainsString('action=imgRename&amp;prevpage=Home&amp;imgName=a.gif', $body);
		$this->assertStringContainsString('<a href="/index.php/Gallery">Gallery</a>', $body);
	}

	public function testSearchResultsAndCreateLink(): void
	{
		$this->savePage('Needle', 'haystack');
		$body = $this->http->get('/index.php', ['action' => 'search', 'q' => 'Needle'])->body;
		$this->assertStringContainsString('class="literalMatch"', $body);
		$this->assertStringContainsString('class="noexist"', $body);
		$body = $this->http->get('/index.php', ['action' => 'search', 'q' => '<b>x</b>'])->body;
		$this->assertStringNotContainsString('<b>x</b>', $body);
		$this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $body);
	}
}
