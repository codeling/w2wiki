<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use W2\LineDiff;

/** The differences between two texts, line by line (W2\LineDiff) */
final class LineDiffTest extends TestCase
{
	public function testEqualTextsHaveNoDifferences(): void
	{
		$ops = LineDiff::compare("a\nb\nc", "a\nb\nc");
		$this->assertSame([['same', 'a'], ['same', 'b'], ['same', 'c']], $ops);
		$this->assertNull(LineDiff::withContext($ops));
	}

	public function testLineEndingsAreNoDifference(): void
	{
		$this->assertNull(LineDiff::withContext(LineDiff::compare("a\r\nb\r\n", "a\nb\n")));
	}

	public function testChangedAddedAndRemovedLines(): void
	{
		$this->assertSame(
			[['same', 'a'], ['removed', 'b'], ['added', 'B'], ['same', 'c'], ['added', 'd']],
			LineDiff::compare("a\nb\nc", "a\nB\nc\nd")
		);
		$this->assertSame([['same', 'a'], ['removed', 'b'], ['same', 'c']], LineDiff::compare("a\nb\nc", "a\nc"));
	}

	public function testMovedLinesAreFoundByTheLongestCommonSubsequence(): void
	{
		$ops = LineDiff::compare("1\n2\n3\n4", "2\n3\n4\n1");
		$this->assertSame([['removed', '1'], ['same', '2'], ['same', '3'], ['same', '4'], ['added', '1']], $ops);
	}

	public function testEmptyTexts(): void
	{
		$this->assertSame([], LineDiff::compare('', ''));
		$this->assertSame([['added', 'a'], ['added', 'b']], LineDiff::compare('', "a\nb"));
		$this->assertSame([['removed', 'a']], LineDiff::compare('a', ''));
	}

	public function testOnlyTheLinesAroundTheChangesAreKept(): void
	{
		$old = implode("\n", range(1, 20));
		$new = str_replace("\n10\n", "\nten\n", $old);
		$context = LineDiff::withContext(LineDiff::compare($old, $new), 2);
		$this->assertSame(
			[['gap', '7'], ['same', '8'], ['same', '9'], ['removed', '10'], ['added', 'ten'], ['same', '11'], ['same', '12'], ['gap', '8']],
			$context
		);
	}

	public function testNothingIsLeftOutBetweenChangesWhichAreClose(): void
	{
		$context = LineDiff::withContext(LineDiff::compare("a\nb\nc\nd\ne", "A\nb\nc\nd\nE"), 3);
		$this->assertSame([['removed', 'a'], ['added', 'A'], ['same', 'b'], ['same', 'c'], ['same', 'd'], ['removed', 'e'], ['added', 'E']], $context);
	}

	public function testTextsWhichAreTooLongAndDifferentAreNotCompared(): void
	{
		$old = implode("\n", range(1, 3000));
		$new = implode("\n", range(5001, 8000));
		$this->assertNull(LineDiff::compare($old, $new));
	}

	public function testLongTextsWithAFewChangesAreCompared(): void
	{
		$old = implode("\n", range(1, 5000));
		$new = str_replace("\n2500\n", "\nchanged\n", $old);
		$this->assertSame(
			[['gap', '2496'], ['same', '2497'], ['same', '2498'], ['same', '2499'], ['removed', '2500'], ['added', 'changed'],
				['same', '2501'], ['same', '2502'], ['same', '2503'], ['gap', '2497']],
			LineDiff::withContext(LineDiff::compare($old, $new))
		);
	}
}
