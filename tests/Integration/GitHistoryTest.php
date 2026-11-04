<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\GitTestCase;
use W2\Tests\Support\HttpResponse;

/** The history of a page from git (GIT_COMMIT_ENABLED): the list of versions, old versions, differences and restoring */
final class GitHistoryTest extends GitTestCase
{
	private function view(string $action, string $page, array $query = []): HttpResponse
	{
		return $this->http->get('/index.php', ['action' => $action, 'page' => $page] + $query);
	}

	/** @return string[] the commits of the latest changes of the repository, newest first (all of it by default) */
	private function hashes(string $file = ''): array
	{
		return explode("\n", $this->server->git('log --format=%H' . ($file !== '' ? ' --follow -- ' . escapeshellarg($file) : '')));
	}

	/** The rows of the list of versions: date, subject, short hash, and the links */
	private function rows(string $body): array
	{
		preg_match_all('#<tr(?: class="deleted")?><td>(?:<a href="([^"]*)">)?([^<]*)(?:</a>)?</td><td>(.*?)</td><td>[^<]*</td><td><code>([0-9a-f]+)</code></td>#', $body, $matches, PREG_SET_ORDER);
		return array_map(fn($m) => ['href' => html_entity_decode($m[1]), 'subject' => html_entity_decode(strip_tags($m[3]), ENT_QUOTES), 'hash' => $m[4]], $matches);
	}

	/** The lines of the box with the differences, as "+ line", "- line", "  line" */
	private function diffLines(string $body): array
	{
		preg_match('#<div class="diff">.*?<pre>(.*?)</pre>#s', $body, $matches);
		$this->assertNotEmpty($matches, 'no differences shown');
		preg_match_all('#<span class="(?:same|removed|added|gap)">(.*?)</span>#s', $matches[1], $lines);
		return array_map(fn($line) => html_entity_decode($line, ENT_QUOTES), $lines[1]);
	}

	/** The fields of the form of the editor which are sent when saving */
	private function editorFields(string $body): array
	{
		$fields = [];
		preg_match_all('#<input type="(?:hidden|text)"[^>]* name="([^"]+)" value="([^"]*)"#', $body, $inputs, PREG_SET_ORDER);
		foreach ($inputs as $input) {
			$fields[$input[1]] = html_entity_decode($input[2], ENT_QUOTES);
		}
		preg_match('#<textarea id="text" name="newText"[^>]*>(.*?)</textarea>#s', $body, $text);
		$fields['newText'] = html_entity_decode($text[1] ?? '', ENT_QUOTES);
		return $fields;
	}

	public function testThePageActionsHaveTheHistory(): void
	{
		$body = $this->http->get($this->pageUrl('Home'))->body;
		$this->assertMatchesRegularExpression('#<a href="/index\.php\?action=history&amp;page=Home"><svg class="icon icon-history" viewBox="0 0 16 16" role="img" aria-label="History"><title>History</title><use href="/w2-icons/history\.svg\?v=\d+\#i"/></svg></a>#', $body);
		$this->assertStringContainsString('icon-history', $this->http->get('/index.php', ['action' => 'all'])->body);
		// (not in the list of versions itself)
		$this->assertStringNotContainsString('icon-history', $this->view('history', 'Home')->body);
	}

	public function testTheListHasTheVersionsNewestFirst(): void
	{
		$this->savePageWithMessage('Home', 'second text', 'second', false);
		$this->savePageWithMessage('Home', 'third text', 'third', false);
		$this->savePage('Other', 'other');
		$response = $this->view('history', 'Home');
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('<span class="title">History: Home</span>', $response->body);
		$this->assertStringContainsString('Versions 1 to 3 of 3', $response->body);
		$hashes = $this->hashes('Home.md');
		$rows = $this->rows($response->body);
		$this->assertSame(['Home: third', 'Home: second', 'Initial pages'], array_column($rows, 'subject'));
		$this->assertSame(array_map(fn($hash) => substr($hash, 0, 7), $hashes), array_map(fn($hash) => substr($hash, 0, 7), array_column($rows, 'hash')));
		$this->assertSame("/index.php?action=version&page=Home&rev=$hashes[1]", $rows[1]['href']);
		$this->assertStringContainsString(htmlspecialchars("/index.php?action=diff&page=Home&rev=$hashes[1]&to=current"), $response->body);
		$this->assertStringContainsString('W2 Test', $response->body, 'the author');
		$this->assertStringNotContainsString('older versions', strtolower(strip_tags($response->body)));
	}

	public function testAnOldVersionIsShownWithABanner(): void
	{
		$this->savePageWithMessage('Home', "# Old heading\n\nold *text*", 'old one', false);
		$old = $this->hashes()[0];
		$this->savePageWithMessage('Home', 'new text', 'new one', false);
		$response = $this->view('version', 'Home', ['rev' => $old]);
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('<div class="oldversion">', $response->body);
		$this->assertStringContainsString('This is the version of the page <a href="/index.php/Home">Home</a> from', $response->body);
		$this->assertStringContainsString('<code>' . substr($old, 0, 7) . '</code>', $response->body);
		$this->assertStringContainsString('Home: old one', $response->body);
		$this->assertStringContainsString('<h1><a id="Old-heading">Old heading</a></h1>', $response->body);
		$this->assertStringContainsString('old <em>text</em>', $response->body);
		$this->assertStringNotContainsString('new text', $response->body);
		$this->assertStringContainsString('<span class="title">Old version: Home</span>', $response->body);
		$this->assertMatchesRegularExpression('#<span class="titledate">[^<]+</span>#', $response->body);
		$this->assertStringContainsString('icon-history', $response->body, 'the page actions, with the history');
		$this->assertStringContainsString('<span class="current">Text of this version</span>', $response->body);
		foreach (["action=diff&amp;page=Home&amp;rev=$old\"", "action=diff&amp;page=Home&amp;rev=$old&amp;to=current\"",
			"action=restore&amp;page=Home&amp;rev=$old\"", 'action=history&amp;page=Home"'] as $link) {
			$this->assertStringContainsString($link, $response->body);
		}
		// (a short hash names a version too)
		$this->assertStringContainsString('old <em>text</em>', $this->view('version', 'Home', ['rev' => substr($old, 0, 7)])->body);
	}

	public function testChangesOfAVersionAreComparedWithTheVersionBeforeIt(): void
	{
		$this->savePage('Diffed', "one\ntwo\nthree");
		$this->savePage('Diffed', "one\n2\nthree\nfour", false);
		[$second, $first] = $this->hashes('Diffed.md');
		$response = $this->view('diff', 'Diffed', ['rev' => $second]);
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('<span class="title">Changes: Diffed</span>', $response->body);
		$this->assertStringContainsString('The version before it (' . substr($first, 0, 7) . ', lines marked -) compared with this version', $response->body);
		$this->assertSame(['  one', '- two', '+ 2', '  three', '+ four'], $this->diffLines($response->body));
		$this->assertStringContainsString('<span class="current">Changes in this version</span>', $response->body);

		$created = $this->view('diff', 'Diffed', ['rev' => $first]);
		$this->assertStringContainsString('The page was created in this version', $created->body);
		$this->assertSame(['+ one', '+ two', '+ three'], $this->diffLines($created->body));
	}

	public function testAVersionIsComparedWithTheCurrentText(): void
	{
		$this->savePage('Compared', "a\nb");
		$this->savePage('Compared', "a\nc", false);
		[$current, $old] = $this->hashes('Compared.md');
		$response = $this->view('diff', 'Compared', ['rev' => $old, 'to' => 'current']);
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('This version (lines marked -) compared with the current text of the page (lines marked +):', $response->body);
		$this->assertSame(['  a', '- b', '+ c'], $this->diffLines($response->body));
		$this->assertStringContainsString('<span class="current">Compared with the current text</span>', $response->body);

		$same = $this->view('diff', 'Compared', ['rev' => $current, 'to' => 'current']);
		$this->assertStringContainsString('This version is the same as the current text of the page.', $same->body);
		$this->assertStringNotContainsString('<div class="diff">', $same->body);
	}

	public function testRestoringOpensTheEditorWithTheOldTextAndChangesNothing(): void
	{
		$this->savePage('Restored', "the <old> text\n");
		$this->savePage('Restored', 'the new text', false);
		[, $old] = $this->hashes('Restored.md');
		$commits = $this->commitCount();
		$response = $this->view('restore', 'Restored', ['rev' => $old]);
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('<form id="edit"', $response->body);
		$this->assertStringContainsString('<span class="title">Edit: Restored</span>', $response->body);
		$this->assertStringContainsString('Nothing has been changed yet', $response->body);
		$fields = $this->editorFields($response->body);
		$this->assertSame("the <old> text\n", $fields['newText']);
		$this->assertSame('Restored', $fields['page']);
		$this->assertSame('', $fields['isNew']);
		$this->assertSame(hash('sha256', 'the new text'), $fields['baseHash'], 'the hash of the current text');
		$this->assertMatchesRegularExpression('/^Restored version ' . substr($old, 0, 7) . ' of \d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $fields['gitmsg']);
		$this->assertStringContainsString(':restore:Restored:' . $old, $fields['draftKey']);
		$this->assertSame('the new text', $this->pageText('Restored'));
		$this->assertSame($commits, $this->commitCount());

		// saving it is a new commit
		$this->assertSame('Saved', $this->noteAfter($this->postAction('save', $fields)));
		$this->assertSame("the <old> text\n", $this->pageText('Restored'));
		$this->assertSame($commits + 1, $this->commitCount());
		$this->assertSame('Restored: ' . $fields['gitmsg'], $this->lastCommitMessage());
		$this->assertCount(3, $this->rows($this->view('history', 'Restored')->body));
	}

	public function testRestoringDoesNotOverwriteAChangeMadeInTheMeantime(): void
	{
		$this->savePage('Busy', 'first');
		$this->savePage('Busy', 'second', false);
		[, $old] = $this->hashes('Busy.md');
		$fields = $this->editorFields($this->view('restore', 'Busy', ['rev' => $old])->body);
		$this->savePage('Busy', 'changed by somebody else', false);
		$response = $this->postAction('save', $fields);
		$this->assertStringContainsString('changed (or deleted) by someone else', $this->noteInResponse($response));
		$this->assertSame('changed by somebody else', $this->pageText('Busy'));
		$this->assertStringContainsString('<div class="diff">', $response->body);
	}

	public function testPagesInSubfoldersHaveAHistory(): void
	{
		$this->savePage('sub/folder/Deep page', 'deep one');
		$this->savePage('sub/folder/Deep page', 'deep two', false);
		[, $old] = $this->hashes('sub/folder/Deep page.md');
		$rows = $this->rows($this->view('history', 'sub/folder/Deep page')->body);
		$this->assertSame(['sub/folder/Deep page changed', 'sub/folder/Deep page created'], array_column($rows, 'subject'));
		$this->assertStringContainsString('<p>deep one</p>', $this->view('version', 'sub/folder/Deep page', ['rev' => $old])->body);
		$this->assertSame(['- deep one', '+ deep two'], $this->diffLines($this->view('diff', 'sub/folder/Deep page', ['rev' => $old, 'to' => 'current'])->body));
		$this->assertSame("deep one", $this->editorFields($this->view('restore', 'sub/folder/Deep page', ['rev' => $old])->body)['newText']);
	}

	public function testVersionsBeforeARenameAreIncluded(): void
	{
		$this->savePage('Before', "text\nof the page\nbefore it was renamed");
		$this->savePage('Before', "text\nof the page\nstill before it was renamed", false);
		$this->renamePage('Before', 'moved/After');
		$this->savePage('moved/After', "text\nof the page\nafter it was renamed", false);
		$rows = $this->rows($this->view('history', 'moved/After')->body);
		$this->assertSame(['moved/After changed', 'Renamed Before to moved/After', 'Before changed', 'Before created'], array_column($rows, 'subject'));
		$hashes = $this->hashes('moved/After.md');
		$this->assertCount(4, $hashes);
		$this->assertStringContainsString('before it was renamed', $this->view('version', 'moved/After', ['rev' => $hashes[3]])->body);
		$this->assertSame(['  text', '  of the page', '- before it was renamed', '+ still before it was renamed'],
			$this->diffLines($this->view('diff', 'moved/After', ['rev' => $hashes[2]])->body));
		$this->assertStringContainsString('The text was not changed in this version', $this->view('diff', 'moved/After', ['rev' => $hashes[1]])->body);
		$this->assertSame("text\nof the page\nbefore it was renamed", $this->editorFields($this->view('restore', 'moved/After', ['rev' => $hashes[3]])->body)['newText']);
	}

	public function testAPageWhichWasDeletedAndCreatedAgainShowsTheDeletion(): void
	{
		$this->savePage('Phoenix', 'first life');
		$this->deletePage('Phoenix');
		$this->savePage('Phoenix', 'second life');
		$body = $this->view('history', 'Phoenix')->body;
		$this->assertSame(['Phoenix created', 'Removed Phoenix (the page was deleted)', 'Phoenix created'], array_column($this->rows($body), 'subject'));
		$this->assertStringContainsString('<tr class="deleted">', $body);
		[$second, $deleted, $first] = $this->hashes('Phoenix.md');
		$this->assertSame(404, $this->view('version', 'Phoenix', ['rev' => $deleted])->status);
		$this->assertStringContainsString('first life', $this->view('version', 'Phoenix', ['rev' => $first])->body);
		$this->assertStringContainsString('The page was created in this version', $this->view('diff', 'Phoenix', ['rev' => $second])->body);
	}

	public function testTheListIsShownInParts(): void
	{
		// (56 versions, committed directly in the repository)
		$file = $this->pageFile('Long');
		for ($i = 1; $i <= 56; $i++) {
			file_put_contents($file, "version $i");
			$this->server->git('add -A');
			$this->server->git('commit -q -m ' . escapeshellarg("change $i"));
		}
		$first = $this->view('history', 'Long');
		$rows = $this->rows($first->body);
		$this->assertCount(50, $rows);
		$this->assertSame('change 56', $rows[0]['subject']);
		$this->assertStringContainsString('Versions 1 to 50 of 56', $first->body);
		$this->assertStringContainsString('<a href="/index.php?action=history&amp;page=Long&amp;p=2" rel="next">Older versions</a>', $first->body);
		$this->assertStringNotContainsString('Newer versions', $first->body);

		$second = $this->view('history', 'Long', ['p' => '2']);
		$this->assertSame(['change 6', 'change 5', 'change 4', 'change 3', 'change 2', 'change 1'], array_column($this->rows($second->body), 'subject'));
		$this->assertStringContainsString('Versions 51 to 56 of 56', $second->body);
		$this->assertStringContainsString('<a href="/index.php?action=history&amp;page=Long&amp;p=1" rel="prev">Newer versions</a>', $second->body);
		$this->assertStringNotContainsString('Older versions', $second->body);

		// (numbers beyond the end, or no numbers, show the nearest part)
		$this->assertStringContainsString('Versions 51 to 56 of 56', $this->view('history', 'Long', ['p' => '99'])->body);
		$this->assertStringContainsString('Versions 1 to 50 of 56', $this->view('history', 'Long', ['p' => 'x'])->body);
		$this->assertStringContainsString('Versions 1 to 50 of 56', $this->view('history', 'Long', ['p' => '-3'])->body);
	}

	public function testAPageWithoutCommitsHasAnEmptyList(): void
	{
		file_put_contents($this->pageFile('Uncommitted'), 'text');
		$response = $this->view('history', 'Uncommitted');
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('No versions of this page were found in the git repository.', $response->body);
	}

	public function testMessagesAndAuthorsAreEscaped(): void
	{
		$this->savePageWithMessage('Home', 'changed', '<script>alert(1)</script> & "quotes"', false);
		file_put_contents($this->pageFile('Home'), 'by mallory');
		$this->server->git('add -A');
		$this->server->git('-c ' . escapeshellarg('user.name=Mallory & "Co"') . ' commit -q -m ' . escapeshellarg("<img src=x onerror=alert(2)>\n\nmore"));
		$body = $this->view('history', 'Home')->body;
		$this->assertStringContainsString('Home: &lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;quotes&quot;', $body);
		$this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $body);
		$this->assertStringContainsString('<td>Mallory &amp; &quot;Co', $body);
		$this->assertNoActiveContent($body);
		$version = $this->view('version', 'Home', ['rev' => $this->hashes('Home.md')[1]])->body;
		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $version);
		$this->assertNoActiveContent($version);
	}

	public function testANonExistingPageHasNoHistory(): void
	{
		foreach (['history', 'version', 'diff', 'restore'] as $action) {
			$response = $this->view($action, 'Does not exist', ['rev' => $this->hashes()[0]]);
			$this->assertSame(404, $response->status, $action);
			$this->assertStringContainsString('The page Does not exist does not exist.', $response->body, $action);
			$this->assertStringNotContainsString('icon-edit', $response->body, $action);
		}
	}

	public static function invalidRevisions(): array
	{
		return [
			'missing' => [''], 'name of a branch' => ['main'], 'HEAD' => ['HEAD'], 'relative' => ['HEAD~1'], 'too short' => ['abc12'],
			'upper case' => ['ABCDEF0'], 'option' => ['--output=/tmp/w2-pwned'], 'range' => ['0000000..1111111'],
			'with a path' => ['0000000:Home.md'], 'shell' => ['$(touch pwned)'], 'unknown hash' => [str_repeat('0', 40)],
			'too long' => [str_repeat('a', 65)],
		];
	}

	#[DataProvider('invalidRevisions')]
	public function testInvalidRevisionsAreNotFound(string $revision): void
	{
		foreach (['version', 'diff', 'restore'] as $action) {
			$response = $this->view($action, 'Home', ['rev' => $revision]);
			$this->assertSame(404, $response->status, $action);
			$this->assertStringContainsString('was not found', $response->body, $action);
			$this->assertStringNotContainsString('<form id="edit"', $response->body, $action);
		}
		$this->assertSame([], glob($this->server->rootDir() . '/pwned*') ?: []);
		$this->assertSame([], glob($this->server->pagesDir() . '/pwned*') ?: []);
		$this->assertFileDoesNotExist('/tmp/w2-pwned');
	}

	public function testOnlyVersionsOfThePageCanBeShown(): void
	{
		// (the commit exists, but didn't change the page: its text is not shown as a version of it)
		$this->savePage('Secret', 'secret text');
		$other = $this->hashes()[0];
		foreach (['version', 'diff', 'restore'] as $action) {
			$response = $this->view($action, 'Home', ['rev' => $other]);
			$this->assertSame(404, $response->status, $action);
			$this->assertStringNotContainsString('secret text', $response->body, $action);
		}
	}

	public function testPageNamesAreNotOptionsOrPatterns(): void
	{
		$this->savePage('Star*', 'star text');
		$this->savePage('Starlight', 'light text');
		$this->savePage('-n', 'dash text');
		$rows = $this->rows($this->view('history', 'Star*')->body);
		$this->assertSame(['Star* created'], array_column($rows, 'subject'));
		$this->assertSame(['-n created'], array_column($this->rows($this->view('history', '-n')->body), 'subject'));
		$this->assertStringContainsString('<p>star text</p>', $this->view('version', 'Star*', ['rev' => $this->hashes()[2]])->body);
		foreach (['--output=/tmp/w2-pwned', ':(glob)*', ':!Home', '../Home', '.git/config', "Home\nx"] as $page) {
			$response = $this->view('history', $page);
			$this->assertSame(404, $response->status, $page);
		}
		$this->assertFileDoesNotExist('/tmp/w2-pwned');
	}

	public function testHistoryActionsChangeNothing(): void
	{
		$this->savePage('Home', 'changed', false);
		$hash = $this->hashes()[0];
		$commits = $this->commitCount();
		$files = $this->server->pageFiles();
		foreach (['history', 'version', 'diff', 'restore'] as $action) {
			$this->view($action, 'Home', ['rev' => $hash]);
			$this->http->post('/index.php', ['action' => $action, 'page' => 'Home', 'rev' => $hash, 'csrf_token' => $this->csrfToken()]);
		}
		$this->assertSame($commits, $this->commitCount());
		$this->assertSame($files, $this->server->pageFiles());
		$this->assertSame('', $this->server->git('status --porcelain'));
	}
}
