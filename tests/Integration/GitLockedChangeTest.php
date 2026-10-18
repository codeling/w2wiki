<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/**
 * Pages and uploads are only changed while the git lock is free, so that a pull which is running
 * (and holds the lock) can't overwrite a change, or fail because of it.
 */
final class GitLockedChangeTest extends GitTestCase
{
	/**
	 * Run $request while another process holds the git lock for 1.5 seconds. That process looks at
	 * $watched (a path) at the end, before it releases the lock.
	 *
	 * @return array{0: mixed, 1: bool} the result of $request, and whether $watched existed at the end
	 */
	private function whileLockIsHeld(string $watched, callable $request): array
	{
		$result = tempnam(sys_get_temp_dir(), 'w2-lock-result-');
		$code = '$f = fopen($argv[1], "c+"); flock($f, LOCK_EX); echo "locked\n"; usleep(1500000);'
			. ' clearstatcache(); file_put_contents($argv[3], file_exists($argv[2]) ? "exists" : "missing");'
			. ' flock($f, LOCK_UN);';
		$holder = proc_open([PHP_BINARY, '-r', $code, $this->server->gitSyncFile(), $watched, $result],
			[1 => ['pipe', 'w']], $pipes);
		try {
			$this->assertSame("locked\n", fgets($pipes[1]));
			$response = $request();
		} finally {
			proc_close($holder);
		}
		$existed = file_get_contents($result) === 'exists';
		unlink($result);
		return [$response, $existed];
	}

	public function testSavingWaitsForTheLockBeforeWritingThePage(): void
	{
		[$response, $existedWhileLocked] = $this->whileLockIsHeld($this->pageFile('Locked'), fn() => $this->savePage('Locked', 'text'));
		$this->assertFalse($existedWhileLocked, 'the page was written while the lock was held by someone else');
		$this->assertSame(303, $response->status);
		$this->assertSame('text', $this->pageText('Locked'));
		$this->assertSame('Locked created', $this->lastCommitMessage());
		$this->assertSame('', $this->server->git('status --porcelain'));
	}

	public function testDeletingWaitsForTheLock(): void
	{
		$this->savePage('Doomed', 'text');
		[, $existedWhileLocked] = $this->whileLockIsHeld($this->pageFile('Doomed'), fn() => $this->deletePage('Doomed'));
		$this->assertTrue($existedWhileLocked, 'the page was deleted while the lock was held by someone else');
		$this->assertFileDoesNotExist($this->pageFile('Doomed'));
		$this->assertSame('', $this->server->git('status --porcelain'));
	}

	public function testUploadWaitsForTheLock(): void
	{
		[, $existedWhileLocked] = $this->whileLockIsHeld($this->imageFile('wait.gif'), fn() => $this->upload('wait.gif', self::gif()));
		$this->assertFalse($existedWhileLocked, 'the file was stored while the lock was held by someone else');
		$this->assertFileExists($this->imageFile('wait.gif'));
		$this->assertSame('', $this->server->git('status --porcelain'));
	}

	public function testReadingDoesNotWaitForTheLock(): void
	{
		[$elapsed] = $this->whileLockIsHeld($this->pageFile('Home'), function () {
			$started = microtime(true);
			$this->assertSame(200, $this->http->get('/index.php')->status);
			return microtime(true) - $started;
		});
		$this->assertLessThan(1.0, $elapsed);
	}
}
