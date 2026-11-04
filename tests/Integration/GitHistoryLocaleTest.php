<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\GitTestCase;

/**
 * The views of the history of a page (which need GIT_COMMIT_ENABLED, unlike the views of LocaleTest) with every locale
 * file: shown without PHP warnings (see AppTestCase::tearDown), with the translated texts. The locale is the name of the
 * data set.
 */
final class GitHistoryLocaleTest extends GitTestCase
{
	public static function locales(): array
	{
		return LocaleTest::locales();
	}

	protected function configOverrides(): array
	{
		return ['GIT_COMMIT_ENABLED' => true, 'W2_LOCALE' => (string)$this->dataName()];
	}

	/** @return array<string, string> */
	private static function words(string $locale): array
	{
		return (function () use ($locale) {
			defined('W2APP') || define('W2APP', true);
			$w2_word_set = [];
			require dirname(__DIR__, 2) . "/locales/$locale.php";
			return $w2_word_set;
		})();
	}

	#[DataProvider('locales')]
	public function testHistoryViewsAreShown(string $locale): void
	{
		$words = self::words($locale);
		$this->savePage('Home', "changed\ntext", false);
		$old = explode("\n", $this->server->git('log --format=%H'))[1];
		$views = [
			'history' => ['action' => 'history', 'page' => 'Home'],
			'version' => ['action' => 'version', 'page' => 'Home', 'rev' => $old],
			'diff' => ['action' => 'diff', 'page' => 'Home', 'rev' => $old],
			'compare' => ['action' => 'diff', 'page' => 'Home', 'rev' => $old, 'to' => 'current'],
			'restore' => ['action' => 'restore', 'page' => 'Home', 'rev' => $old],
		];
		foreach ($views as $name => $query) {
			$response = $this->http->get('/index.php', $query);
			$this->assertSame(200, $response->status, "$locale: $name");
			$this->assertStringContainsString('<div class="main">', $response->body, "$locale: $name");
			$this->assertStringContainsString('</html>', $response->body, "$locale: $name");
		}
		$history = $this->http->get('/index.php', $views['history'])->body;
		$this->assertStringContainsString('<span class="title">' . htmlspecialchars(($words['History'] ?? 'History') . ': Home', ENT_QUOTES, 'UTF-8') . '</span>', $history);
		$this->assertStringContainsString('aria-label="' . htmlspecialchars($words['Edit'] ?? 'Edit', ENT_QUOTES, 'UTF-8') . '"', $history);
		$version = $this->http->get('/index.php', $views['version'])->body;
		$this->assertStringContainsString(htmlspecialchars($words['Restore this version'] ?? 'Restore this version', ENT_QUOTES, 'UTF-8'), $version);
	}
}
