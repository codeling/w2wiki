<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The lock which serializes git commands, pulls and the changes of pages (gitLock() in functions.php) */
final class GitLockTest extends TestCase
{
	/** Whether another process can take the lock right now */
	private function otherProcessCanLock(): bool
	{
		$code = '$f = fopen($argv[1], "c+"); exit(flock($f, LOCK_EX | LOCK_NB) ? 0 : 1);';
		exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg(gitSyncFile()), $output, $result);
		return $result === 0;
	}

	protected function tearDown(): void
	{
		// (a failing test must not leave the lock to the others)
		while (gitLockState()[0] !== null) {
			gitUnlock(gitLockState()[0]);
		}
		@unlink(gitSyncFile());
	}

	public function testLockExcludesOtherProcessesUntilReleased(): void
	{
		$lock = gitLock(true);
		$this->assertNotNull($lock);
		$this->assertFalse($this->otherProcessCanLock());
		gitUnlock($lock);
		$this->assertTrue($this->otherProcessCanLock());
	}

	public function testLockCanBeTakenAgainWhileHeld(): void
	{
		$first = gitLock(true);
		// (a second file handle would wait for the first one forever)
		$second = gitLock(true);
		$this->assertSame($first, $second);
		$this->assertSame($first, gitLock(false));
		gitUnlock($second);
		gitUnlock($second);
		$this->assertFalse($this->otherProcessCanLock(), 'still taken once');
		gitUnlock($first);
		$this->assertTrue($this->otherProcessCanLock());
	}

	public function testNotWaitingFailsWhileAnotherProcessHoldsTheLock(): void
	{
		$holder = proc_open(
			[PHP_BINARY, '-r', '$f = fopen($argv[1], "c+"); flock($f, LOCK_EX); echo "locked\n"; fgets(STDIN);', gitSyncFile()],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
		try {
			$this->assertSame("locked\n", fgets($pipes[1]));
			$this->assertNull(gitLock(false));
		} finally {
			fclose($pipes[0]);
			proc_close($holder);
		}
		$lock = gitLock(false);
		$this->assertNotNull($lock);
		gitUnlock($lock);
	}

	public function testUnlockingNothingOrAnotherHandleIsHarmless(): void
	{
		gitUnlock(null);
		$lock = gitLock(true);
		$other = fopen('php://memory', 'r');
		gitUnlock($other);
		$this->assertFalse($this->otherProcessCanLock());
		gitUnlock($lock);
		$this->assertTrue($this->otherProcessCanLock());
	}

	public function testChangesTakeNoLockWithoutGit(): void
	{
		$this->assertFalse(GIT_COMMIT_ENABLED);
		$this->assertNull(gitLockForChange());
		$this->assertTrue($this->otherProcessCanLock());
	}
}
