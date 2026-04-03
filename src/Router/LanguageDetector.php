<?php

declare(strict_types=1);

namespace EightshiftMultilang\Router;

use EightshiftMultilang\Languages\LanguageRepository;

/**
 * Detects the current request language from the URL prefix and makes it
 * available to the rest of the plugin via a static property and the
 * esml_get_current_language() template tag.
 *
 * Detection order:
 *  1. esml_language query var (set by UrlRouter rewrite rules)
 *  2. Default language (fallback for requests without a language prefix)
 *
 * The resolved code is stored in a static property so it can be read at any
 * point after parse_request without additional database lookups.
 */
final class LanguageDetector
{
	/**
	 * The language code detected for the current request.
	 * Null until parse_request has fired.
	 */
	private static ?string $currentLanguage = null;

	public function __construct(
		private readonly LanguageRepository $languageRepository,
	) {
	}

	public function register(): void
	{
		// parse_request fires after rewrite rules have been resolved.
		add_action('parse_request', [$this, 'detectLanguage']);
	}

	/**
	 * Resolve the current language from the request query vars.
	 * Hooked into parse_request; receives the WP object.
	 *
	 * @param \WP $wp The WordPress environment instance.
	 */
	public function detectLanguage(\WP $wp): void
	{
		$langCode = $wp->query_vars['esml_language'] ?? '';

		if ($langCode !== '') {
			$activeCodes = $this->languageRepository->getActiveCodes();

			// Only accept the detected code if it's actually active.
			if (in_array($langCode, $activeCodes, true)) {
				self::$currentLanguage = $langCode;
				return;
			}
		}

		// Fall back to the default language.
		self::$currentLanguage = $this->languageRepository->getDefaultCode() ?? '';
	}

	/**
	 * Return the language code for the current request.
	 * Returns null only before parse_request has fired.
	 */
	public static function getCurrentLanguage(): ?string
	{
		return self::$currentLanguage;
	}

	/**
	 * Reset the static state. Used in tests to ensure isolation between cases.
	 */
	public static function reset(): void
	{
		self::$currentLanguage = null;
	}
}
