<?php

namespace W2\Tests\Support;

final class HttpResponse
{
	/** @param array<string, string[]> $headers header names in lower case */
	public function __construct(
		public readonly int $status,
		public readonly array $headers,
		public readonly string $body,
		public readonly float $seconds
	) {
	}

	public function header(string $name): ?string
	{
		return $this->headers[strtolower($name)][0] ?? null;
	}

	/** @return string[] */
	public function headerValues(string $name): array
	{
		return $this->headers[strtolower($name)] ?? [];
	}

	public function location(): ?string
	{
		return $this->header('location');
	}

	/** Value of a cookie set by this response (from its Set-Cookie header), if any */
	public function setCookieValue(string $name): ?string
	{
		foreach ($this->headerValues('set-cookie') as $cookie) {
			if (str_starts_with($cookie, $name . '=')) {
				return explode(';', substr($cookie, strlen($name) + 1))[0];
			}
		}
		return null;
	}
}
