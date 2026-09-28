<?php

namespace W2\Tests\Support;

/** A file to upload with the given file name and MIME type sent by the "browser" */
final class CurlFileLike
{
	private string $tmpFile;

	public function __construct(string $content, private string $fileName, private string $mimeType = 'application/octet-stream')
	{
		$this->tmpFile = tempnam(sys_get_temp_dir(), 'w2up');
		file_put_contents($this->tmpFile, $content);
	}

	public function __destruct()
	{
		@unlink($this->tmpFile);
	}

	public function toCurlFile(): \CURLFile
	{
		return new \CURLFile($this->tmpFile, $this->mimeType, $this->fileName);
	}
}
