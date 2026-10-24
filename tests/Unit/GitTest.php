<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use W2\Git;

/** Running git in a folder without a shell (W2\Git), tested in a temporary repository */
final class GitTest extends TestCase
{
	private string $root;
	private Git $git;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/w2-git-test-' . bin2hex(random_bytes(6)) . " it's \$(touch pwned)";
		mkdir($this->root);
		$this->git = new Git($this->root);
		$this->git->run(['init', '-q']);
		$this->git->run(['config', 'user.name', 'W2']);
		$this->git->run(['config', 'user.email', 'w2@example.com']);
	}

	protected function tearDown(): void
	{
		foreach (array_diff(scandir($this->root), ['.', '..']) as $entry) {
			$path = "$this->root/$entry";
			is_dir($path) && !is_link($path) ? $this->removeTree($path) : unlink($path);
		}
		rmdir($this->root);
	}

	private function removeTree(string $folder): void
	{
		foreach (array_diff(scandir($folder), ['.', '..']) as $entry) {
			$path = "$folder/$entry";
			is_dir($path) && !is_link($path) ? $this->removeTree($path) : unlink($path);
		}
		rmdir($folder);
	}

	public function testRunsInTheFolderAndReturnsOutputAndCode(): void
	{
		$result = $this->git->run(['rev-parse', '--is-inside-work-tree']);
		$this->assertTrue($result->succeeded());
		$this->assertSame('true', $result->output);
		$this->assertFalse($result->timedOut);
	}

	public function testFailureHasTheExitCodeAndTheErrorOutput(): void
	{
		$result = $this->git->run(['rev-parse', '--verify', 'no-such-branch']);
		$this->assertFalse($result->succeeded());
		$this->assertSame(128, $result->code);
		$this->assertNotSame('', $result->output);
	}

	public function testArgumentsAreNotInterpretedByAShell(): void
	{
		file_put_contents("$this->root/a.md", 'x');
		$this->git->run(['add', '-A']);
		$message = "it's \"quoted\" \$(touch pwned) `touch pwned2`; & -x";
		$this->assertTrue($this->git->run(['commit', '-m', $message])->succeeded());
		$this->assertSame($message, $this->git->run(['log', '-1', '--format=%B'])->output);
		$this->assertSame([], glob("$this->root/pwned*") ?: []);
		$this->assertSame([], glob(sys_get_temp_dir() . '/pwned*') ?: []);
	}

	public function testACommandWhichTakesTooLongIsStopped(): void
	{
		// (outside the folder, whose name has characters the shell of core.sshCommand would take apart)
		$script = sys_get_temp_dir() . '/w2-slow-' . bin2hex(random_bytes(6)) . '.sh';
		file_put_contents($script, "#!/bin/sh\nexec sleep 5\n");
		chmod($script, 0755);
		$start = microtime(true);
		$result = $this->git->run(['-c', "core.sshCommand=$script", 'ls-remote', 'ssh://localhost/none'], 1);
		$this->assertTrue($result->timedOut);
		$this->assertSame(Git::TIMED_OUT, $result->code);
		$elapsed = microtime(true) - $start;
		unlink($script);
		$this->assertLessThan(4, $elapsed);
	}

	public function testAUnreachableFolderFails(): void
	{
		$result = (new Git("$this->root/missing"))->run(['status']);
		$this->assertFalse($result->succeeded());
	}
}
