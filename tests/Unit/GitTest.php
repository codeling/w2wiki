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

	private function commitFile(string $name, string $content): void
	{
		file_put_contents("$this->root/$name", $content);
		$this->git->run(['add', '-A']);
		$this->git->run(['commit', '-q', '-m', "add $name"]);
	}

	public function testOutputIsTrimmedAndIncludesTheErrorsByDefault(): void
	{
		$this->commitFile('a.md', "\n  text  \n\n");
		$result = $this->git->run(['cat-file', 'blob', 'HEAD:a.md']);
		$this->assertSame('text', $result->output);
		$this->assertSame('', $result->errors);
		$this->assertFalse($result->truncated);
		$failed = $this->git->run(['cat-file', 'blob', 'HEAD:missing.md']);
		$this->assertStringContainsString('missing.md', $failed->output);
		$this->assertSame('', $failed->errors);
	}

	public function testExactOutputIsTheStandardOutputAsItIs(): void
	{
		$content = "\n  text with\0a null byte and trailing white space  \r\n\n";
		$this->commitFile('a.md', $content);
		$result = $this->git->run(['cat-file', 'blob', 'HEAD:a.md'], 0, null, true);
		$this->assertTrue($result->succeeded());
		$this->assertSame($content, $result->output);
		$this->assertSame('', $result->errors);
		$this->assertFalse($result->truncated);
	}

	public function testExactOutputHasTheErrorsSeparately(): void
	{
		$this->commitFile('a.md', 'x');
		$result = $this->git->run(['cat-file', 'blob', 'HEAD:missing.md'], 0, null, true);
		$this->assertFalse($result->succeeded());
		$this->assertSame('', $result->output);
		$this->assertStringContainsString('missing.md', $result->errors);
	}

	public function testOutputIsLimitedTo64KbByDefaultAndMarkedAsTruncated(): void
	{
		$this->commitFile('big.md', str_repeat('0123456789abcdef', 8192));
		foreach ([false, true] as $exact) {
			$result = $this->git->run(['cat-file', 'blob', 'HEAD:big.md'], 0, null, $exact);
			$this->assertTrue($result->succeeded());
			$this->assertTrue($result->truncated);
			$this->assertSame(65536, strlen($result->output));
		}
	}

	public function testALargerLimitKeepsLongOutput(): void
	{
		// (more than the pipe buffer of the system, while git also writes errors: both are read at the same time)
		$content = random_bytes(300000);
		$this->commitFile('big.bin', $content);
		$result = $this->git->run(['cat-file', 'blob', 'HEAD:big.bin'], 10, 1048576, true);
		$this->assertTrue($result->succeeded());
		$this->assertFalse($result->truncated);
		$this->assertFalse($result->timedOut);
		$this->assertSame($content, $result->output);
	}

	public function testASmallerLimitCutsTheOutputAtExactlyThatLength(): void
	{
		$this->commitFile('a.md', str_repeat('x', 5000));
		$result = $this->git->run(['cat-file', 'blob', 'HEAD:a.md'], 0, 1000, true);
		$this->assertTrue($result->truncated);
		$this->assertSame(str_repeat('x', 1000), $result->output);
		$exact = $this->git->run(['cat-file', 'blob', 'HEAD:a.md'], 0, 5000, true);
		$this->assertFalse($exact->truncated);
		$this->assertSame(5000, strlen($exact->output));
	}

	public function testLongErrorOutputIsReadWhileTheCommandRuns(): void
	{
		// (more errors than the pipe buffer of the system: git would wait forever if they were not read)
		$script = sys_get_temp_dir() . '/w2-noisy-' . bin2hex(random_bytes(6)) . '.sh';
		file_put_contents($script, "#!/bin/sh\nhead -c 300000 /dev/zero | tr '\\000' e >&2\nexit 1\n");
		chmod($script, 0755);
		$result = $this->git->run(['-c', "core.sshCommand=$script", 'ls-remote', 'ssh://localhost/none'], 10, null, true);
		unlink($script);
		$this->assertFalse($result->timedOut);
		$this->assertFalse($result->succeeded());
		$this->assertSame('', $result->output);
		$this->assertFalse($result->truncated);
		$this->assertSame(65536, strlen($result->errors));
	}
}
