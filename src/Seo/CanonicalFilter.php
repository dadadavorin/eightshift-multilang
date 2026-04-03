<?php

declare(strict_types=1);

namespace EightshiftMultilang\Seo;

use EightshiftMultilang\Languages\LanguageRepository;
use EightshiftMultilang\Translations\TranslationRepository;

/**
 * Ensures canonical URLs carry the correct language prefix.
 *
 * PermalinkFilter already hooks post_link/page_link/post_type_link so that
 * get_permalink() returns prefixed URLs. WordPress core's wp_get_canonical_url()
 * calls get_permalink() internally, so it is already correct in most cases.
 *
 * This class guards two remaining edge cases:
 *
 *  1. wp_get_canonical_url — a late priority (20) filter that re-applies the
 *     prefix if another plugin has reset the canonical to an unprefixed URL.
 *
 *  2. Yoast SEO — filters wpseo_canonical, which Yoast builds independently
 *     from get_permalink().
 *
 * Double-prefixing is prevented by checking whether the prefix is already
 * present before inserting it.
 */
final class CanonicalFilter
{
	public function __construct(
		private readonly TranslationRepository $translationRepository,
		private readonly LanguageRepository $languageRepository,
	) {
	}

	public function register(): void
	{
		// Priority 20 — runs after PermalinkFilter (priority 10) and most
		// third-party plugins, so we can repair any overwrites.
		add_filter('wp_get_canonical_url', [$this, 'filterCanonicalUrl'], 20, 2);

		// Yoast SEO — the canonical it outputs is built independently of
		// get_permalink(), so it needs explicit filtering.
		add_filter('wpseo_canonical', [$this, 'filterWpseoCanonical']);

		// RankMath SEO.
		add_filter('rank_math/frontend/canonical', [$this, 'filterWpseoCanonical']);
	}

	// ---------------------------------------------------------------------------
	// Handlers
	// ---------------------------------------------------------------------------

	/**
	 * Filter WordPress core's canonical URL.
	 *
	 * @param string|false $canonical
	 * @param \WP_Post     $post
	 */
	public function filterCanonicalUrl(string|false $canonical, \WP_Post $post): string|false
	{
		if (! $canonical) {
			return $canonical;
		}

		$langCode = $this->translationRepository->getLanguageCode($post->ID);

		if ($langCode === null || $langCode === $this->languageRepository->getDefaultCode()) {
			return $canonical;
		}

		return $this->ensurePrefixed($canonical, $langCode);
	}

	/**
	 * Filter Yoast SEO / RankMath canonical URL (same signature: string → string).
	 */
	public function filterWpseoCanonical(string $canonical): string
	{
		if (! is_singular()) {
			return $canonical;
		}

		$postId   = (int) get_queried_object_id();
		$langCode = $this->translationRepository->getLanguageCode($postId);

		if ($langCode === null || $langCode === $this->languageRepository->getDefaultCode()) {
			return $canonical;
		}

		return $this->ensurePrefixed($canonical, $langCode);
	}

	// ---------------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------------

	/**
	 * Prepend /{langCode}/ after the home URL if not already present.
	 * Mirrors the logic in PermalinkFilter::prependLanguageSegment().
	 */
	private function ensurePrefixed(string $url, string $langCode): string
	{
		$homeUrl = trailingslashit(home_url());

		if (! str_starts_with($url, $homeUrl)) {
			return $url;
		}

		$prefix = $homeUrl . $langCode . '/';

		// Already correct — avoid double-prefixing.
		if (str_starts_with($url, $prefix)) {
			return $url;
		}

		$relativePath = substr($url, strlen($homeUrl));

		return $homeUrl . $langCode . '/' . $relativePath;
	}
}
