<?php

declare(strict_types=1);

namespace EightshiftMultilang\Seo;

use EightshiftMultilang\Cache\CacheManager;
use EightshiftMultilang\Languages\LanguageRepository;
use EightshiftMultilang\Translations\TranslationRepository;

/**
 * Outputs hreflang alternate link tags in wp_head.
 *
 * Two cases are handled:
 *
 *  1. Singular post/page — outputs one hreflang per translation in the group
 *     plus an x-default pointing to the default-language version.
 *     Results are cached per post ID using CacheManager::keyHreflang().
 *
 *  2. Home / front page — outputs one hreflang per active language pointing
 *     to that language's home URL (/{lang}/ for non-default, / for default).
 *
 * Locale format is converted from WordPress style (de_DE) to BCP 47 (de-DE)
 * as required by the hreflang specification.
 */
final class HreflangManager
{
	public function __construct(
		private readonly TranslationRepository $translationRepository,
		private readonly LanguageRepository $languageRepository,
		private readonly CacheManager $cacheManager,
	) {
	}

	public function register(): void
	{
		// Priority 1 keeps hreflang tags near the top of <head>.
		add_action('wp_head', [$this, 'outputHreflangTags'], 1);
	}

	/**
	 * Route to the correct output method based on the current request type.
	 */
	public function outputHreflangTags(): void
	{
		if (is_singular()) {
			$postId = (int) get_queried_object_id();
			if ($postId > 0) {
				$this->outputForSingular($postId);
			}
			return;
		}

		if (is_home() || is_front_page()) {
			$this->outputForHome();
		}
	}

	// ---------------------------------------------------------------------------
	// Per-post hreflang
	// ---------------------------------------------------------------------------

	private function outputForSingular(int $postId): void
	{
		$cacheKey = $this->cacheManager->keyHreflang($postId);
		$cached   = $this->cacheManager->get($cacheKey);

		if ($cached !== false) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $cached;
			return;
		}

		$groupId = $this->translationRepository->getGroupId($postId);

		if ($groupId === null) {
			return;
		}

		$links       = $this->translationRepository->getByGroup($groupId);
		$defaultCode = $this->languageRepository->getDefaultCode();
		$output      = '';
		$defaultUrl  = '';

		foreach ($links as $link) {
			$language = $this->languageRepository->getByCode($link->languageCode);

			if ($language === null || ! $language->isActive) {
				continue;
			}

			$url = get_permalink($link->postId);

			if (! $url) {
				continue;
			}

			$hreflang = $this->toHreflang($language->locale);
			$output  .= sprintf(
				'<link rel="alternate" hreflang="%s" href="%s">' . "\n",
				esc_attr($hreflang),
				esc_url($url),
			);

			if ($link->languageCode === $defaultCode) {
				$defaultUrl = $url;
			}
		}

		if ($defaultUrl !== '') {
			$output .= sprintf(
				'<link rel="alternate" hreflang="x-default" href="%s">' . "\n",
				esc_url($defaultUrl),
			);
		}

		if ($output !== '') {
			$this->cacheManager->set($cacheKey, $output);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $output;
		}
	}

	// ---------------------------------------------------------------------------
	// Home / front-page hreflang
	// ---------------------------------------------------------------------------

	private function outputForHome(): void
	{
		$languages   = $this->languageRepository->getActive();
		$defaultCode = $this->languageRepository->getDefaultCode();
		$homeUrl     = trailingslashit(home_url());

		foreach ($languages as $language) {
			$url = $language->code === $defaultCode
				? $homeUrl
				: $homeUrl . $language->code . '/';

			printf(
				'<link rel="alternate" hreflang="%s" href="%s">' . "\n",
				esc_attr($this->toHreflang($language->locale)),
				esc_url($url),
			);
		}

		// x-default points to the unqualified home URL.
		printf(
			'<link rel="alternate" hreflang="x-default" href="%s">' . "\n",
			esc_url($homeUrl),
		);
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Convert a WordPress locale (de_DE) to a BCP 47 hreflang value (de-DE).
	 */
	private function toHreflang(string $locale): string
	{
		return str_replace('_', '-', $locale);
	}
}
