<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\AppTestCase;

/** The search box of the toolbar sends a GET request, so that the address of the results can be bookmarked and shared */
final class SearchFormTest extends AppTestCase
{
	/** @return \DOMElement the form around the search field */
	private function searchForm(string $html): \DOMElement
	{
		foreach ($this->elements($html, 'form') as $form) {
			foreach ($form->getElementsByTagName('input') as $input) {
				if ($input->getAttribute('id') === 'search') {
					return $form;
				}
			}
		}
		$this->fail('no search form found');
	}

	/** @return array<string, string> the fields of the form which are sent (name => value) */
	private function fields(\DOMElement $form): array
	{
		$fields = [];
		foreach ($form->getElementsByTagName('input') as $input) {
			if ($input->getAttribute('name') !== '') {
				$fields[$input->getAttribute('name')] = $input->getAttribute('value');
			}
		}
		return $fields;
	}

	public static function views(): array
	{
		return [
			'page' => ['/index.php/Home'],
			'all pages' => ['/index.php?action=all'],
			'editor' => ['/index.php?action=edit&page=Home'],
			'search results' => ['/index.php?action=search&q=home'],
		];
	}

	#[DataProvider('views')]
	public function testSearchFormIsAGetFormWithTheAction(string $url): void
	{
		$form = $this->searchForm($this->http->get($url)->body);
		$this->assertSame('get', strtolower($form->getAttribute('method')));
		// (no query in the address of the form: a browser replaces it with the fields)
		$this->assertSame('/index.php', $form->getAttribute('action'));
		$this->assertSame(['action' => 'search', 'q' => ''], $this->fields($form));
	}

	public function testSubmittingTheFormGivesTheResultsUnderTheAddressOfTheForm(): void
	{
		$form = $this->searchForm($this->http->get('/index.php')->body);
		$query = array_merge($this->fields($form), ['q' => 'rare word']);
		$response = $this->http->get($form->getAttribute('action'), $query);
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('<h1>Search: rare word</h1>', $response->body);
	}

	public function testResultsAreTheSameForGetAndForTheOldPostRequests(): void
	{
		$this->savePage('Findable', 'contains zqx-unique-term');
		$get = $this->http->get('/index.php', ['action' => 'search', 'q' => 'zqx-unique-term'])->body;
		$post = $this->http->post('/index.php', ['q' => 'zqx-unique-term'], ['action' => 'search'])->body;
		$this->assertStringContainsString('Findable', $get);
		$this->assertStringContainsString('1 matches', $get);
		$this->assertStringContainsString('Findable', $post);
		$this->assertStringContainsString('1 matches', $post);
	}
}
