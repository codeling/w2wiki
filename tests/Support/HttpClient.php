<?php

namespace W2\Tests\Support;

/**
 * Minimal cookie-aware HTTP client (one session per instance), which never
 * follows redirects on its own.
 */
final class HttpClient
{
	private string $cookieJar;

	public function __construct(private string $baseUrl)
	{
		$this->cookieJar = tempnam(sys_get_temp_dir(), 'w2jar');
	}

	public function __destruct()
	{
		@unlink($this->cookieJar);
	}

	/**
	 * @param array<string, string> $query
	 * @param string[] $requestHeaders extra request headers, e.g. "X-Forwarded-Proto: https"
	 */
	public function get(string $path, array $query = [], array $requestHeaders = []): HttpResponse
	{
		return $this->request('GET', $path, $query, null, $requestHeaders);
	}

	/**
	 * @param array<string, string|CurlFileLike> $fields
	 * @param array<string, string> $query
	 * @param string[] $requestHeaders extra request headers
	 */
	public function post(string $path, array $fields = [], array $query = [], array $requestHeaders = []): HttpResponse
	{
		return $this->request('POST', $path, $query, $fields, $requestHeaders);
	}

	/** Fetch the page a redirect response points to */
	public function follow(HttpResponse $response): HttpResponse
	{
		$location = $response->location();
		if ($location === null) {
			throw new \LogicException('Response has no Location header (status ' . $response->status . ')');
		}
		return $this->get($location);
	}

	/** @param array<string, string|CurlFileLike>|null $fields */
	private function request(string $method, string $path, array $query, ?array $fields = null, array $requestHeaders = []): HttpResponse
	{
		$url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
		$headers = [];
		$curl = curl_init($url);
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTPHEADER => $requestHeaders,
			CURLOPT_COOKIEJAR => $this->cookieJar,
			CURLOPT_COOKIEFILE => $this->cookieJar,
			CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers) {
				$parts = explode(':', $line, 2);
				if (count($parts) === 2) {
					$headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
				}
				return strlen($line);
			},
		]);
		if ($method === 'POST') {
			$hasFile = false;
			foreach ($fields ?? [] as $key => $value) {
				if ($value instanceof CurlFileLike) {
					$fields[$key] = $value->toCurlFile();
					$hasFile = true;
				}
			}
			curl_setopt($curl, CURLOPT_POST, true);
			// an array makes curl send multipart/form-data, a string urlencoded
			curl_setopt($curl, CURLOPT_POSTFIELDS, $hasFile ? $fields : http_build_query($fields ?? []));
		}
		$start = microtime(true);
		$body = curl_exec($curl);
		$seconds = microtime(true) - $start;
		if ($body === false) {
			$error = curl_error($curl);
			curl_close($curl);
			throw new \RuntimeException("Request $method $url failed: $error");
		}
		$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		curl_close($curl);
		return new HttpResponse($status, $headers, (string)$body, $seconds);
	}
}
