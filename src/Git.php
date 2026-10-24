<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Running git in the pages folder.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Runs git commands in one folder. The command is started directly (no shell), as a list of arguments, so
 * nothing in the folder name, a commit message or a page name can be taken for shell syntax. git never asks
 * for a password, and a command which takes longer than its time limit (e.g. a remote which does not answer)
 * is stopped.
 */
final class Git
{
	/** Result code of a command which was stopped because of the time limit (as with the timeout command) */
	public const TIMED_OUT = 124;

	/** The most output of a command which is kept (the output is only logged) */
	private const MAX_OUTPUT = 65536;

	/** @param string $folder	the folder with the repository (the commands run with "git -C folder") */
	public function __construct(private readonly string $folder)
	{
	}

	/**
	 * @param string[] $arguments	the arguments for git, e.g. array('commit', '-m', $message)
	 * @param int $timeout		seconds after which the command is stopped, 0 for no limit
	 */
	public function run(array $arguments, int $timeout = 0): GitResult
	{
		$command = array_merge(array('git', '-C', $this->folder), array_map('strval', $arguments));
		$environment = array('GIT_TERMINAL_PROMPT' => '0') + (getenv() ?: array());
		$process = @proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('redirect', 1)),
			$pipes, null, $environment);
		if (!is_resource($process))
		{
			return new GitResult(127, 'cannot start git', false, self::describe($command));
		}
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		$output = '';
		$timedOut = false;
		$deadline = $timeout > 0 ? microtime(true) + $timeout : null;
		while (!feof($pipes[1]))
		{
			$wait = 1.0;
			if ($deadline !== null)
			{
				$wait = $deadline - microtime(true);
				if ($wait <= 0)
				{
					$timedOut = true;
					break;
				}
			}
			$read = array($pipes[1]);
			$write = null;
			$except = null;
			$wait = min($wait, 1.0);
			if (stream_select($read, $write, $except, (int)$wait, (int)(($wait - (int)$wait) * 1000000)) > 0)
			{
				$chunk = fread($pipes[1], 8192);
				if ($chunk !== false && strlen($output) < self::MAX_OUTPUT)
				{
					$output .= $chunk;
				}
			}
		}
		if ($timedOut)
		{
			proc_terminate($process, 9);
		}
		fclose($pipes[1]);
		$code = proc_close($process);
		return new GitResult($timedOut ? self::TIMED_OUT : $code, trim($output), $timedOut, self::describe($command));
	}

	/** The command as text for the log, quoted as for a shell (which does not run it) */
	private static function describe(array $command): string
	{
		return implode(' ', array_map('escapeshellarg', $command));
	}
}
