<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * The differences between two texts, line by line.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Compares two texts line by line (the longest common subsequence of lines), to show what saving a text would change
 * in another one. Line endings don't count as a difference.
 */
final class LineDiff
{
	/** The most cells of the table which is filled for the lines between the common start and end (memory and time) */
	private const MAX_CELLS = 4000000;

	public const SAME = 'same';
	public const REMOVED = 'removed';
	public const ADDED = 'added';
	public const GAP = 'gap';

	/**
	 * @return list<array{0: string, 1: string}>|null	the lines of both texts in order, each as SAME, REMOVED (only in
	 *		$old) or ADDED (only in $new) with the line; null if the texts are too different or too long to compare
	 */
	public static function compare(string $old, string $new): ?array
	{
		$a = self::lines($old);
		$b = self::lines($new);
		$start = 0;
		$endA = count($a);
		$endB = count($b);
		while ($start < $endA && $start < $endB && $a[$start] === $b[$start])
		{
			$start++;
		}
		while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1])
		{
			$endA--;
			$endB--;
		}
		$n = $endA - $start;
		$m = $endB - $start;
		if (($n + 1) * ($m + 1) > self::MAX_CELLS)
		{
			return null;
		}
		// $table[$i][$j]: the length of the longest common subsequence of the lines from $i of the old and from $j of the new text
		$table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
		for ($i = $n - 1; $i >= 0; $i--)
		{
			for ($j = $m - 1; $j >= 0; $j--)
			{
				$table[$i][$j] = ($a[$start + $i] === $b[$start + $j])
					? $table[$i + 1][$j + 1] + 1
					: max($table[$i + 1][$j], $table[$i][$j + 1]);
			}
		}
		$ops = array();
		for ($i = 0; $i < $start; $i++)
		{
			$ops[] = array(self::SAME, $a[$i]);
		}
		$i = 0;
		$j = 0;
		while ($i < $n || $j < $m)
		{
			if ($i < $n && $j < $m && $a[$start + $i] === $b[$start + $j])
			{
				$ops[] = array(self::SAME, $a[$start + $i]);
				$i++;
				$j++;
			}
			else if ($j >= $m || ($i < $n && $table[$i + 1][$j] >= $table[$i][$j + 1]))
			{
				$ops[] = array(self::REMOVED, $a[$start + $i]);
				$i++;
			}
			else
			{
				$ops[] = array(self::ADDED, $b[$start + $j]);
				$j++;
			}
		}
		for ($k = $endA; $k < count($a); $k++)
		{
			$ops[] = array(self::SAME, $a[$k]);
		}
		return $ops;
	}

	/**
	 * Keeps the changed lines and some lines around them, and replaces each longer run of the other lines by a
	 * GAP with their number
	 *
	 * @param list<array{0: string, 1: string}> $ops	the result of compare()
	 * @return list<array{0: string, 1: string}>		null if nothing is different
	 */
	public static function withContext(array $ops, int $context = 3): ?array
	{
		$keep = array_fill(0, count($ops), false);
		$changed = false;
		foreach ($ops as $i => $op)
		{
			if ($op[0] !== self::SAME)
			{
				$changed = true;
				for ($k = max(0, $i - $context); $k <= min(count($ops) - 1, $i + $context); $k++)
				{
					$keep[$k] = true;
				}
			}
		}
		if (!$changed)
		{
			return null;
		}
		$result = array();
		$skipped = 0;
		foreach ($ops as $i => $op)
		{
			if ($keep[$i])
			{
				if ($skipped > 0)
				{
					$result[] = array(self::GAP, (string)$skipped);
					$skipped = 0;
				}
				$result[] = $op;
			}
			else
			{
				$skipped++;
			}
		}
		if ($skipped > 0)
		{
			$result[] = array(self::GAP, (string)$skipped);
		}
		return $result;
	}

	/** @return string[] */
	private static function lines(string $text): array
	{
		return $text === '' ? array() : preg_split('/\r\n|\r|\n/', $text);
	}
}
