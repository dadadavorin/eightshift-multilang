<?php

declare(strict_types=1);

namespace EightshiftMultilang\Router;

use EightshiftMultilang\Languages\LanguageRepository;
use EightshiftMultilang\Translations\TranslationRepository;

/**
 * Filters WordPress permalink functions to inject language prefixes for
 * non-default languages.
 *
 * Default-language posts keep their canonical URL unchanged.
 * A post in /de/ with a path of /about-us/ becomes /de/about-us/.
 *
 * Hooks covered: post_link, page_link, post_type_link.
 * All three receive the same arguments so they share one handler.
 */
final class PermalinkFilter
{
	public function __construct(
		private readonly TranslationRepository $translationRepository,
		private readonly LanguageRepository $languageRepository,
	) {
	}

	public function register(): void
	{
		// Priority 10 runs after WordPress builds the base URL but before most
		// third-party plugins modify it, which is the correct insertion point.
		add_filter('post_link', [$this, 'filterPermalink'], 10, 2);
		add_filter('page_link', [$this, 'filterPermalink'], 10, 2);
		add_filter('post_type_link', [$this, 'filterPermalink'], 10, 2);
	}

	/**
	 * Prepend the language code segment to the URL for non-default-language posts.
	 *
	 * @param string        $url  The permalink URL produced by WordPress.
	 * @param \WP_Post|int  $post The post object or ID.
	 */
	public function filterPermalink(string $url, \WP_Post|int $post): string
	{
		$postId = $post instanceof \WP_Post ? $post->ID : (int) $post;

		if ($postId <= 0) {
			return $url;
		}

		$languageCode = $this->translationRepository->getLanguageCode($postId);

		// Post is not in any translation group — leave URL untouched.
		if ($languageCode === null) {
			return $url;
		}

		$defaultCode = $this->languageRepository->getDefaultCode();

		// Default language keeps the canonical (unprefixed) URL.
		if ($languageCode === $defaultCode) {
			return $url;
		}

		return $this->prependLanguageSegment($url, $languageCode);
	}

	// ---------------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------------

	/**
	 * Insert /{langCode}/ immediately after the home URL base.
	 *
	 * home_url() returns something like https://example.com (no trailing slash).
	 * trailingslashit() normalises it to https://example.com/.
	 * The relative path is everything after that base, e.g. "about-us/".
	 * Final result: https://example.com/de/about-us/
	 */
	private function prependLanguageSegment(string $url, string $langCode): string
	{
		$homeUrl = trailingslashit(home_url());

		// Guard: URL must start with the home URL (skip CDN/custom domain edge cases).
		if (!str_starts_with($url, $homeUrl)) {
			return $url;
		}

		$relativePath = substr($url, strlen($homeUrl));

		return $homeUrl . $langCode . '/' . $relativePath;
	}
}
