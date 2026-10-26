<?php

/**
 * CI_Oembed_service Class
 *
 * Provides unified access to oEmbed endpoints for popular media providers.
 * This service supports automatic provider detection, response normalization,
 * runtime and persistent caching, and dynamic provider registration.
 *
 * The service includes:
 * - Auto provider detection based on URL.
 * - Runtime (in-memory) caching for repeated lookups within a request.
 * - Persistent caching using CiSkeleton's cache drivers.
 * - Response normalization for consistent structure.
 * - Optional provider registration for custom APIs.
 *
 * @package     CiSkeleton\Plugins
 * @subpackage  oEmbed\Services
 * @category    Embeds
 * @author      Kader Bouyakoub <bkader[at]mail[dot]com>
 * @copyright   2018-present, Kader Bouyakoub
 * @license     MIT
 * @link        https://opensource.org/license/mit
 *
 * SPDX-License-Identifier: MIT
 */

final class CI_Oembed_service extends CI_Service
{
	/**
	 * List of supported oEmbed providers.
	 * Each provider defines:
	 *  - url: The oEmbed endpoint base.
	 *  - param: Extra query parameters if required.
	 *  - match: Array of domain substrings to auto-detect provider.
	 *
	 * @var array
	 */
	private $providers = [
		'youtube' => [
			'url' => 'https://www.youtube.com/oembed?url=%s&format=json',
			'match' => ['youtube.com', 'youtu.be'],
		],
		'vimeo' => [
			'url' => 'https://vimeo.com/api/oembed.json?url=%s',
			'match' => ['vimeo.com'],
		],
		'dailymotion' => [
			'url' => 'https://www.dailymotion.com/services/oembed?url=%s',
			'match' => ['dailymotion.com'],
		],
		'soundcloud' => [
			'url' => 'https://soundcloud.com/oembed?url=%s&format=json',
			'match' => ['soundcloud.com'],
		],
		'flickr' => [
			'url' => 'https://www.flickr.com/services/oembed?url=%s&format=json',
			'match' => ['flickr.com'],
		],
		'twitter' => [
			'url' => 'https://publish.twitter.com/oembed?url=%s',
			'match' => ['twitter.com', 'x.com'],
		],
		'tiktok' => [
			'url' => 'https://www.tiktok.com/oembed?url=%s',
			'match' => ['tiktok.com'],
		],
		'reddit' => [
			'url' => 'https://www.reddit.com/oembed?url=%s',
			'match' => ['reddit.com'],
		],
	];

	/**
	 * In-memory cache for oEmbed responses during a single request.
	 *
	 * @var array
	 */
	private $cached = [];

	/**
	 * Default configuration to use with cURL.
	 *
	 * @var array
	 */
	private $curl_config = [
		'timeout' => 20,
		'verify_ssl' => true,
		'user_agent' => 'CSK Oembed Client/1.0'
	];

	// --------------------------------------------------------------------

	/**
	 * Registers a new oEmbed provider dynamically at runtime.
	 *
	 * @param  string $name   Provider key (e.g. 'tiktok').
	 * @param  string $url    Provider API endpoint base.
	 * @param  array  $match  Domain patterns to match against.
	 * @return void
	 * @throws InvalidArgumentException  If no placeholder is present.
	 */
	public function register($name, $url, array $match = [])
	{
		// Normalize and validate
		$name = strtolower(trim($name));
		$url = trim($url);

		// Ensure placeholder is present
		if (strpos($url, '%s') === false) {
			throw new InvalidArgumentException(sprintf(
				'Provider "%s" must contain a "%%s" placeholder in its URL endpoint.',
				$name
			));
		}

		$this->providers[$name] = [
			'url' => $url,
			'match' => $match,
		];
	}

	// --------------------------------------------------------------------

	/**
	 * Fetches an oEmbed response for the given URL.
	 *
	 * @param  string      $url       The media resource URL to embed.
	 * @param  string|null $provider  Optional provider name (auto-detected if null).
	 * @param  bool        $cache     Whether to cache the result.
	 * @return array|false            The normalized oEmbed data array or false on failure.
	 */
	public function fetch($url, $provider = null, $cache = true)
	{
		// If we already have this URL cached in memory, return it immediately.
		if (isset($this->cached[$url])) {
			return $this->cached[$url];
		}

		// Validate the given URL.
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			log_message('error', "OEmbed: Invalid URL provided: {$url}");
			return false;
		}

		// Detect provider if not explicitly given.
		$provider = $provider ?: $this->detect_provider($url);
		if (!$provider || !isset($this->providers[$provider])) {
			log_message('error', "OEmbed: Could not detect a supported provider for URL: {$url}");
			return false;
		}


		// Attempt to retrieve from persistent cache (if CI cache is available).
		if ($cache && isset($this->ci->cache)) {
			// Generate cache key for persistent storage.
			$cache_key = 'oembed_'.md5($provider.$url);
			if ($cached = $this->ci->cache->get($cache_key)) {
				return $this->cached[$url] = $cached;
			}
		}

		// Build the provider endpoint (base URL + encoded media URL + optional params).
		$site = $this->providers[$provider];
		$endpoint = sprintf($site['url'], rawurlencode($url));

		// Execute the HTTP GET request via CSK’s cURL helper.
		$response = curl($this->curl_config)->get($endpoint);

		// Abort on failed request.
		if (!$response->is_success()) {
			log_message('error', "OEmbed: Failed to fetch response from '{$provider}' for URL: {$url}");
			return false;
		}

		// Attempt to decode JSON response.
		$data = json_decode($response->get_response(), true);
		if (empty($data) || !is_array($data)) {
			log_message('error', "OEmbed: Invalid JSON response from '{$provider}' for URL: {$url}");
			return false;
		}

		// Normalize fields to maintain consistent keys across providers.
		$data = $this->normalize($data, $provider);

		// Persist result if caching is enabled.
		if ($cache && isset($this->ci->cache)) {
			$cache_key ??= 'oembed_'.md5($provider.$url);
			$this->ci->cache->save($cache_key, $data, 86400); // Cache for 1 day.
		}

		// Store in memory for this request.
		return $this->cached[$url] = $data;
	}

	// --------------------------------------------------------------------

	/**
	 * Converts a resource URL directly into embeddable HTML.
	 *
	 * @param  string $url  Resource URL.
	 * @return string       The embeddable HTML, or empty string on failure.
	 */
	public function to_html($url)
	{
		$data = $this->fetch($url);
		return $data ? $data['html'] : '';
	}

	// --------------------------------------------------------------------

	/**
	 * Attempts to detect the oEmbed provider based on URL substring matching.
	 *
	 * @param  string $url   The target URL.
	 * @return string|false  Provider key on success, false otherwise.
	 */
	private function detect_provider($url)
	{
		foreach ($this->providers as $key => $data) {
			foreach ($data['match'] as $domain) {
				if (stripos($url, $domain) !== false) {
					return $key;
				}
			}
		}

		return false;
	}

	// --------------------------------------------------------------------

	/**
	 * Normalizes oEmbed response data to ensure a consistent structure.
	 *
	 * @param  array  $data      Raw provider response.
	 * @param  string $provider  Provider key.
	 * @return array             Standardized oEmbed data.
	 */
	private function normalize($data, $provider)
	{
		return [
			// Embed Info:
			'title' => $data['title'] ?? '',
			'author_name' => $data['author_name'] ?? '',
			'author_url' => $data['author_url'] ?? null,
			'type' => $data['type'] ?? 'rich',
			'height' => $data['height'] ?? null,
			'width' => $data['width'] ?? null,
			'version' => $data['version'] ?? null,

			// Thumbnail Info:
			'thumbnail_url' => $data['thumbnail_url'] ?? null,
			'thumbnail_height' => $data['thumbnail_height'] ?? null,
			'thumbnail_width' => $data['thumbnail_width'] ?? null,
			'thumbnail' => $data['thumbnail_url'] ?? null,

			// Provider Info:
			'provider_name' => $data['provider_name'] ?? ucwords($provider),
			'provider_url' => $data['provider_url'] ?? '',

			// HTML and Raw data:
			'html' => $data['html'] ?? '',
			'raw' => $data, // Keep the raw provider response.
		];
	}

}
