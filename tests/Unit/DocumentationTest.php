<?php

namespace W2\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DocumentationTest extends TestCase
{
	/** @return string[] trimmed, non-empty lines */
	private static function lines(string $text): array
	{
		return array_values(array_filter(array_map('trim', explode("\n", $text)), fn($line) => $line !== ''));
	}

	/** The nginx rules tested by tests/Server (with a real nginx) must be the ones recommended in INSTALL.md */
	public function testNginxRulesInInstallGuideAreTheTestedOnes(): void
	{
		$guide = (string)file_get_contents(dirname(__DIR__, 2) . '/INSTALL.md');
		$this->assertSame(1, preg_match('/```\n(location ~ \/\\\\\.\(\?!well-known.*?)```/s', $guide, $documented), 'nginx rules not found in INSTALL.md');

		$template = (string)file_get_contents(dirname(__DIR__) . '/Server/nginx/w2.conf.template');
		$this->assertSame(1, preg_match('/# --- rules from INSTALL\.md ---\n(.*?)# --- end of rules from INSTALL\.md ---/s', $template, $tested));

		$this->assertSame(self::lines($documented[1]), self::lines($tested[1]));
	}

	public function testHtaccessFilesReferencedInInstallGuideExist(): void
	{
		foreach (['.htaccess', 'pages/.htaccess', 'pages/images/.htaccess', 'vendor/.htaccess'] as $file) {
			$this->assertFileExists(dirname(__DIR__, 2) . "/$file");
		}
	}
}
