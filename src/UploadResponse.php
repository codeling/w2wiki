<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * The answer to a request for an uploaded file (by file.php).
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Decides how an uploaded file is answered: which file (only regular files directly in the uploads folder, no hidden
 * files and no links), its type (from the extension, never from the content), the headers which keep it from running
 * scripts in the wiki's origin, and caching. Gets what it needs as parameters (nothing is read from the request).
 */
final class UploadResponse
{
	/** The types of the files which browsers may show (by extension); all other files are offered for download */
	private const INLINE_TYPES = [
		'avif' => 'image/avif', 'bmp' => 'image/bmp', 'gif' => 'image/gif', 'heic' => 'image/heic', 'heif' => 'image/heif',
		'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
		'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=UTF-8',
		'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
	];

	/** Content-Security-Policy of SVG files: no scripts, and a sandbox, if one is opened directly */
	public const SVG_POLICY = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox";

	/** Cache-Control of files requested with a version parameter (which changes with the file, see uploadURL()) */
	public const CACHE_VERSIONED = 'private, max-age=31536000, immutable';

	/** Cache-Control of files requested without a version parameter: the browser has to ask whether it changed */
	public const CACHE_UNVERSIONED = 'private, no-cache';

	/**
	 * @param string $folder	the uploads folder (no trailing slash)
	 */
	public function __construct(private readonly string $folder)
	{
	}

	/**
	 * The answer to a request for the file $name
	 *
	 * @param string $method			the request method
	 * @param bool $versioned			whether the address has a version parameter
	 * @param string|null $ifNoneMatch	the If-None-Match header of the request
	 * @param string|null $ifModifiedSince	the If-Modified-Since header of the request
	 * @return array{status: int, headers: array<string, string|null>, file: string|null, body: string}
	 *         the status, the headers to send (null: remove the header, e.g. the Content-Security-Policy of the pages),
	 *         the file to send as the body (null for none), or else the text to send
	 */
	public function respond(string $name, string $method, bool $versioned, ?string $ifNoneMatch = null, ?string $ifModifiedSince = null): array
	{
		$plain = ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'];
		if ($method !== 'GET' && $method !== 'HEAD') {
			return ['status' => 405, 'headers' => $plain + ['Allow' => 'GET, HEAD'], 'file' => null, 'body' => "Method not allowed\n"];
		}
		$path = $this->path($name);
		$mtime = $path === null ? false : @filemtime($path);
		$size = $path === null ? false : @filesize($path);
		if ($path === null || $mtime === false || $size === false) {
			return ['status' => 404, 'headers' => $plain, 'file' => null, 'body' => "Not found\n"];
		}
		$extension = preg_match('/\.([^.]+)$/', $name, $matches) ? strtolower($matches[1]) : '';
		$type = self::INLINE_TYPES[$extension] ?? null;
		$etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
		$headers = [
			'Content-Type' => $type ?? 'application/octet-stream',
			'Content-Disposition' => ($type === null ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode($name),
			// (the policy of the pages would e.g. keep browsers from showing PDF files)
			'Content-Security-Policy' => $extension === 'svg' ? self::SVG_POLICY : null,
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control' => $versioned ? self::CACHE_VERSIONED : self::CACHE_UNVERSIONED,
			'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
			'ETag' => $etag,
			'Accept-Ranges' => 'none',
		];
		if (self::isNotModified($etag, $mtime, $ifNoneMatch, $ifModifiedSince)) {
			return ['status' => 304, 'headers' => $headers, 'file' => null, 'body' => ''];
		}
		$headers['Content-Length'] = (string)$size;
		return ['status' => 200, 'headers' => $headers, 'file' => $method === 'GET' ? $path : null, 'body' => ''];
	}

	/**
	 * The path of an uploaded file which may be sent: a regular file directly in the uploads folder (no folders,
	 * no hidden files like .htaccess, and no links, which could point anywhere), or null
	 */
	public function path(string $name): ?string
	{
		if ($name === '' || str_starts_with($name, '.') || strpbrk($name, "/\\\0") !== false) {
			return null;
		}
		$path = "$this->folder/$name";
		return (!is_link($path) && is_file($path)) ? $path : null;
	}

	/** Whether the browser has the current version of the file (If-None-Match decides if it is given) */
	private static function isNotModified(string $etag, int $mtime, ?string $ifNoneMatch, ?string $ifModifiedSince): bool
	{
		if ($ifNoneMatch !== null && trim($ifNoneMatch) !== '') {
			foreach (explode(',', $ifNoneMatch) as $tag) {
				$tag = trim($tag);
				if ($tag === '*' || $tag === $etag || $tag === "W/$etag") {
					return true;
				}
			}
			return false;
		}
		if ($ifModifiedSince !== null && trim($ifModifiedSince) !== '') {
			$since = strtotime($ifModifiedSince);
			return $since !== false && $since >= $mtime;
		}
		return false;
	}
}
