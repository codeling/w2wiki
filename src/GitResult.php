<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * The result of a git command.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * What a command of {@see Git} returned: the exit code, the output (standard output and errors, or only the standard
 * output with the errors separately, see Git::run()) and whether it was stopped; also the command as text, quoted as
 * for a shell, for the log (it is never run as text), and whether output was dropped because it was too long
 */
final class GitResult
{
	public function __construct(
		public readonly int $code,
		public readonly string $output,
		public readonly bool $timedOut,
		public readonly string $command = 'git',
		public readonly bool $truncated = false,
		public readonly string $errors = ''
	) {
	}

	public function succeeded(): bool
	{
		return $this->code === 0;
	}
}
