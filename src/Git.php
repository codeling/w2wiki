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

	/** The most output of a command which is kept by default (the output is usually only logged) */
	private const MAX_OUTPUT = 65536;

	/** @param string $folder	the folder with the repository (the commands run with "git -C folder") */
	public function __construct(private readonly string $folder)
	{
	}

	/**
	 * @param string[] $arguments	the arguments for git, e.g. array('commit', '-m', $message)
	 * @param int $timeout		seconds after which the command is stopped, 0 for no limit
	 * @param int|null $maxOutput	the most bytes of the output which are kept (null: 64 KB); the rest is read and
	 *				dropped, and the result says that it was truncated
	 * @param bool $exact		false: the output is the standard output and the errors together, with white space
	 *				around it removed (for messages and the log); true: the output is the standard output
	 *				exactly as git wrote it (e.g. the text of a file in a commit), and the errors are
	 *				separate (GitResult::$errors, at most 64 KB)
	 */
	public function run(array $arguments, int $timeout = 0, ?int $maxOutput = null, bool $exact = false): GitResult
	{
		$command = array_merge(array('git', '-C', $this->folder), array_map('strval', $arguments));
		$environment = array('GIT_TERMINAL_PROMPT' => '0') + (getenv() ?: array());
		$process = @proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => $exact ? array('pipe', 'w') : array('redirect', 1)),
			$pipes, null, $environment);
		if (!is_resource($process))
		{
			return new GitResult(127, $exact ? '' : 'cannot start git', false, self::describe($command), false, $exact ? 'cannot start git' : '');
		}
		fclose($pipes[0]);
		// (the pipes which are read, by descriptor; both are read at the same time, so that git never waits for one of them)
		$streams = $exact ? array(1 => $pipes[1], 2 => $pipes[2]) : array(1 => $pipes[1]);
		$buffers = array(1 => '', 2 => '');
		$limits = array(1 => max(0, $maxOutput ?? self::MAX_OUTPUT), 2 => self::MAX_OUTPUT);
		foreach ($streams as $stream)
		{
			stream_set_blocking($stream, false);
		}
		$truncated = false;
		$timedOut = false;
		$deadline = $timeout > 0 ? microtime(true) + $timeout : null;
		while ($streams)
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
			$read = array_values($streams);
			$write = null;
			$except = null;
			$wait = min($wait, 1.0);
			if (stream_select($read, $write, $except, (int)$wait, (int)(($wait - (int)$wait) * 1000000)) > 0)
			{
				foreach ($streams as $descriptor => $stream)
				{
					if (!in_array($stream, $read, true))
					{
						continue;
					}
					$chunk = fread($stream, 65536);
					if ($chunk !== false && $chunk !== '')
					{
						$room = $limits[$descriptor] - strlen($buffers[$descriptor]);
						if (strlen($chunk) > $room)
						{
							$truncated = $truncated || $descriptor === 1;
							$chunk = substr($chunk, 0, $room);
						}
						$buffers[$descriptor] .= $chunk;
					}
					if (feof($stream))
					{
						unset($streams[$descriptor]);
					}
				}
			}
		}
		if ($timedOut)
		{
			proc_terminate($process, 9);
		}
		foreach (array_slice($pipes, 1, null, true) as $pipe)
		{
			fclose($pipe);
		}
		$code = proc_close($process);
		return new GitResult($timedOut ? self::TIMED_OUT : $code, $exact ? $buffers[1] : trim($buffers[1]), $timedOut, self::describe($command),
			$truncated, trim($buffers[2]));
	}

	/** The command as text for the log, quoted as for a shell (which does not run it) */
	private static function describe(array $command): string
	{
		return implode(' ', array_map('escapeshellarg', $command));
	}
}
