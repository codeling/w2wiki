<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use W2\Git;
use W2\History;
use W2\PageStore;

/** The versions of a page in git (W2\History): parsing the log, and reading versions in a temporary repository */
final class HistoryTest extends TestCase
{
	private string $root;
	private Git $git;
	private History $history;
	private string|false $errorLog = false;

	protected function setUp(): void
	{
		// (failures are logged, see w2Log(); the tests which cause them don't need the messages)
		$this->errorLog = ini_set('error_log', '/dev/null');
		$this->root = sys_get_temp_dir() . '/w2-history-test-' . bin2hex(random_bytes(6)) . " it's \$(touch pwned)";
		mkdir($this->root);
		$this->git = new Git($this->root);
		$this->git->run(['init', '-q']);
		$this->git->run(['config', 'user.name', 'W2']);
		$this->git->run(['config', 'user.email', 'w2@example.com']);
		$this->git->run(['config', 'commit.gpgsign', 'false']);
		$this->history = new History($this->git, new PageStore($this->root, 'md', 'images'));
	}

	protected function tearDown(): void
	{
		$this->removeTree($this->root);
		if ($this->errorLog !== false) {
			ini_set('error_log', $this->errorLog);
		}
	}

	private function removeTree(string $folder): void
	{
		foreach (array_diff(scandir($folder), ['.', '..']) as $entry) {
			$path = "$folder/$entry";
			is_dir($path) && !is_link($path) ? $this->removeTree($path) : unlink($path);
		}
		rmdir($folder);
	}

	private function commit(string $message, ?string $file = null, ?string $text = null): void
	{
		if ($file !== null) {
			if (!is_dir(dirname("$this->root/$file"))) {
				mkdir(dirname("$this->root/$file"), 0755, true);
			}
			file_put_contents("$this->root/$file", (string)$text);
		}
		$this->git->run(['add', '-A']);
		$this->assertTrue($this->git->run(['commit', '-q', '-m', $message])->succeeded());
	}

	public static function revisions(): array
	{
		return [
			'short' => ['abcdef0', true], 'full' => [str_repeat('0123456789', 4), true], 'sha-256' => [str_repeat('ab', 32), true],
			'too short' => ['abcdef', false], 'too long' => [str_repeat('a', 65), false], 'upper case' => ['ABCDEF0', false],
			'name' => ['HEAD', false], 'option' => ['--all', false], 'line break' => ["abcdef0\n", false], 'range' => ['abcdef0..1234567', false],
			'path' => ['abcdef0:a.md', false], 'empty' => ['', false],
		];
	}

	#[DataProvider('revisions')]
	public function testRevisionsAreHexadecimalHashes(string $revision, bool $valid): void
	{
		$this->assertSame($valid, History::isRevision($revision));
	}

	public function testArraysAreNoRevisions(): void
	{
		$this->assertFalse(History::isRevision(['abcdef0']));
		$this->assertFalse(History::isRevision(null));
	}

	public function testParsesTheLogWithRenamesAndDeletions(): void
	{
		$a = str_repeat('a', 40);
		$b = str_repeat('b', 40);
		$c = str_repeat('c', 40);
		$d = str_repeat('d', 40);
		// (as "git log -z --name-status" writes it; subjects and authors are free text, also with the separator in them)
		$output = "\x1e$a\0aaaaaaa\0" . "1700000300\0Ann\0edit: M\0\nM\0sub/New.md\0"
			. "\x1e$b\0bbbbbbb\0" . "1700000200\0B\x1eob\0\x1e$d\0\nR087\0Old.md\0sub/New.md\0"
			. "\x1e$c\0ccccccc\0" . "1700000100\0Cy\0deleted\0\nD\0Old.md\0"
			. "\x1e$d\0ddddddd\0" . "1700000000\0Di\0created\0\nA\0Old.md\0";
		$versions = History::parseLog($output, 'sub/New.md');
		$this->assertSame([
			['hash' => $a, 'shortHash' => 'aaaaaaa', 'time' => 1700000300, 'author' => 'Ann', 'subject' => 'edit: M', 'path' => 'sub/New.md', 'deleted' => false],
			['hash' => $b, 'shortHash' => 'bbbbbbb', 'time' => 1700000200, 'author' => "B\x1eob", 'subject' => "\x1e$d", 'path' => 'sub/New.md', 'deleted' => false],
			['hash' => $c, 'shortHash' => 'ccccccc', 'time' => 1700000100, 'author' => 'Cy', 'subject' => 'deleted', 'path' => 'Old.md', 'deleted' => true],
			['hash' => $d, 'shortHash' => 'ddddddd', 'time' => 1700000000, 'author' => 'Di', 'subject' => 'created', 'path' => 'Old.md', 'deleted' => false],
		], $versions);
	}

	public function testAVersionWithoutFilesHasTheFileOfTheVersionAfterIt(): void
	{
		$a = str_repeat('a', 40);
		$b = str_repeat('b', 40);
		$c = str_repeat('c', 40);
		$output = "\x1e$a\0aaaaaaa\0" . "3\0A\0renamed\0\nR100\0Old.md\0New.md\0"
			. "\x1e$b\0bbbbbbb\0" . "2\0B\0merge\0"
			. "\x1e$c\0ccccccc\0" . "1\0C\0created\0\nA\0Old.md\0";
		$this->assertSame(['New.md', 'Old.md', 'Old.md'], array_column(History::parseLog($output, 'New.md'), 'path'));
	}

	public function testAVersionCutOffAtTheEndIsLeftOut(): void
	{
		$a = str_repeat('a', 40);
		$b = str_repeat('b', 40);
		$complete = "\x1e$a\0aaaaaaa\0" . "2\0A\0one\0\nM\0Page.md\0";
		foreach (["\x1e$b\0bbb", "\x1e$b\0bbbbbbb\0" . "1\0B\0two\0\nR100\0Old.md"] as $cut) {
			$this->assertSame([$a], array_column(History::parseLog($complete . $cut, 'Page.md'), 'hash'));
		}
		$this->assertSame([], History::parseLog('', 'Page.md'));
		$this->assertSame([], History::parseLog("not\0git\0output", 'Page.md'));
	}

	public function testInvalidShortHashesAreReplaced(): void
	{
		$a = str_repeat('a', 40);
		$versions = History::parseLog("\x1e$a\0--x\0" . "1\0A\0s\0\nM\0Page.md\0", 'Page.md');
		$this->assertSame('aaaaaaa', $versions[0]['shortHash']);
	}

	public function testFindsAVersionByItsHashOrTheStartOfIt(): void
	{
		$versions = [
			['hash' => 'abcdef1' . str_repeat('0', 33), 'deleted' => false],
			['hash' => 'abcdef2' . str_repeat('0', 33), 'deleted' => true],
			['hash' => '1234567' . str_repeat('0', 33), 'deleted' => false],
		];
		$this->assertSame(0, History::find($versions, 'abcdef1'));
		$this->assertSame(2, History::find($versions, '1234567' . str_repeat('0', 33)));
		$this->assertNull(History::find($versions, 'abcdef2'), 'the page was deleted in this version');
		$this->assertNull(History::find($versions, 'abcdef'), 'too short');
		$this->assertNull(History::find($versions, '7654321'), 'unknown');
		$this->assertNull(History::find([['hash' => 'abcdef1a' . str_repeat('0', 32), 'deleted' => false], ['hash' => 'abcdef1b' . str_repeat('0', 32), 'deleted' => false]], 'abcdef1'), 'ambiguous');
		$this->assertNull(History::find($versions, 'HEAD'));
	}

	public function testVersionsOfAPageInARepository(): void
	{
		$this->commit('create', 'sub/Page.md', "one\n");
		$this->commit('other page', 'Other.md', 'other');
		$this->commit('edit', 'sub/Page.md', "one\ntwo\n");
		$this->git->run(['mv', 'sub/Page.md', 'Renamed [x]*.md']);
		$this->commit('rename');
		$this->commit('after rename', 'Renamed [x]*.md', "one\ntwo\nthree\n");

		$versions = $this->history->versions('Renamed [x]*');
		$this->assertSame(['after rename', 'rename', 'edit', 'create'], array_column($versions, 'subject'));
		$this->assertSame(['Renamed [x]*.md', 'Renamed [x]*.md', 'sub/Page.md', 'sub/Page.md'], array_column($versions, 'path'));
		$this->assertSame(['W2'], array_values(array_unique(array_column($versions, 'author'))));
		$this->assertSame(explode("\n", $this->git->run(['log', '--format=%H', '--follow', '--', 'Renamed [x]*.md'])->output), array_column($versions, 'hash'));
		$this->assertSame("one\n", $this->history->text($versions[3]));
		$this->assertSame("one\ntwo\n", $this->history->text($versions[2]));
		$this->assertSame("one\ntwo\nthree\n", $this->history->text($versions[0]));
	}

	public function testPageNamesAreLiteralPaths(): void
	{
		$this->commit('star', 'a*.md', 'star');
		$this->commit('other', 'abc.md', 'abc');
		$this->commit('magic', ':(glob)x.md', 'magic');
		$this->assertSame(['star'], array_column($this->history->versions('a*'), 'subject'));
		$this->assertSame(['magic'], array_column($this->history->versions(':(glob)x'), 'subject'));
		$this->assertSame([], $this->history->versions('Missing'));
		$this->assertSame([], $this->history->versions('../outside'), 'not a valid page name');
		$this->assertSame([], $this->history->versions('images/x'), 'in the uploads folder');
	}

	public function testTextIsReadExactlyAndLongTextsAreRefused(): void
	{
		$text = "  white space, \0 null byte and Ünïcode \r\n\n";
		$this->commit('exact', 'Exact.md', $text);
		$versions = $this->history->versions('Exact');
		$this->assertSame($text, $this->history->text($versions[0]));
		$this->assertNull($this->history->text(['hash' => $versions[0]['hash'], 'path' => 'Missing.md', 'deleted' => false]));
		$this->assertNull($this->history->text(['hash' => '--output=x', 'path' => 'Exact.md', 'deleted' => false]));
		$this->assertNull($this->history->text(['hash' => $versions[0]['hash'], 'path' => 'Exact.md', 'deleted' => true]));
	}

	public function testATextLongerThanTheLimitIsNotRead(): void
	{
		$this->commit('long', 'Long.md', str_repeat('x', History::MAX_TEXT + 1));
		$versions = $this->history->versions('Long');
		$this->assertNull($this->history->text($versions[0]));
	}

	public function testAFolderWithoutRepositoryHasNoVersions(): void
	{
		$history = new History(new Git("$this->root/missing"), new PageStore("$this->root/missing", 'md', null));
		$this->assertNull($history->versions('Page'));
	}
}
