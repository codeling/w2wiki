<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * What a change of pages or uploaded files has done.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Outcome of an action of {@see Changes}: whether it worked, the page to show next (if any) and the message;
 * $conflict: a save was refused because the page was changed after the editor was opened;
 * $file: the name an upload was stored under (after space replacement and format conversion), "" if nothing was stored
 */
final class ChangeResult
{
	public function __construct(
		public readonly bool $success,
		public readonly string $page,
		public readonly Note $note,
		public readonly bool $conflict = false,
		public readonly string $file = ''
	) {
	}
}
