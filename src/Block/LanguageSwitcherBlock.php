<?php

declare(strict_types=1);

namespace EightshiftMultilang\Block;

use EightshiftMultilang\Languages\LanguageRepository;
use EightshiftMultilang\Router\LanguageDetector;
use EightshiftMultilang\Translations\TranslationRepository;

/**
 * Server-side-rendered Gutenberg block and shortcode for the language switcher.
 *
 * Block name: eightshift-multilang/language-switcher
 * Shortcode:  [esml_language_switcher]
 * Template tag: esml_language_switcher()  (defined in src/Helpers/LanguageHelper.php)
 *
 * The block JS (edit component) lives in src/scripts/editor/index.js alongside
 * the translation sidebar, sharing the esml-editor script handle. No separate
 * build entry is needed.
 *
 * Render logic:
 *  1. For each active language, find the translated post via TranslationRepository.
 *  2. If a translation exists, use get_permalink() (PermalinkFilter prefixes it).
 *  3. If no translation exists, fall back to the language's home URL (/{lang}/).
 *  4. Mark the current-language item with aria-current and a CSS modifier class.
 *
 * Attributes:
 *   show_native_names (bool, default false) — display native name instead of English name
 *   show_flags        (bool, default false) — prepend a flag emoji span
 */
final class LanguageSwitcherBlock
{
	public function __construct(
		private readonly LanguageRepository $languageRepository,
		private readonly TranslationRepository $translationRepository,
	) {
	}

	public function register(): void
	{
		add_action('init', [$this, 'registerBlock']);
		add_shortcode('esml_language_switcher', [$this, 'renderShortcode']);
	}

	/**
	 * Register the block type with WordPress.
	 * The editor_script references the handle registered by EditorSidebar::enqueueAssets().
	 */
	public function registerBlock(): void
	{
		register_block_type('eightshift-multilang/language-switcher', [
			'editor_script'   => 'esml-editor',
			'render_callback' => [$this, 'render'],
			'attributes'      => [
				'showNativeNames' => ['type' => 'boolean', 'default' => false],
				'showFlags'       => ['type' => 'boolean', 'default' => false],
			],
		]);
	}

	/**
	 * Server-side render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render(array $attributes): string
	{
		$languages   = $this->languageRepository->getActive();
		$currentLang = LanguageDetector::getCurrentLanguage()
			?? $this->languageRepository->getDefaultCode()
			?? '';
		$defaultCode = $this->languageRepository->getDefaultCode() ?? '';

		$showNativeNames = (bool) ($attributes['showNativeNames'] ?? false);
		$showFlags       = (bool) ($attributes['showFlags'] ?? false);

		// The queried object is available during a render_callback, but may be
		// null for non-singular contexts (archives, search results, etc.).
		$currentPostId = is_singular() ? (int) get_queried_object_id() : 0;

		$items = [];

		foreach ($languages as $language) {
			$url   = $this->resolveLanguageUrl($language->code, $currentPostId, $defaultCode);
			$label = $showNativeNames ? $language->nativeName : $language->name;
			$isActive = ($language->code === $currentLang);

			$flagHtml = '';
			if ($showFlags && $language->flagCode !== '') {
				$flagHtml = sprintf(
					'<span class="esml-switcher__flag esml-flag--%s" aria-hidden="true"></span>',
					esc_attr($language->flagCode),
				);
			}

			$items[] = sprintf(
				'<li class="esml-switcher__item%s">'
				. '<a href="%s" hreflang="%s" lang="%s"%s>%s%s</a>'
				. '</li>',
				$isActive ? ' esml-switcher__item--active' : '',
				esc_url($url),
				esc_attr(str_replace('_', '-', $language->locale)),
				esc_attr($language->code),
				$isActive ? ' aria-current="true"' : '',
				$flagHtml,
				esc_html($label),
			);
		}

		if ($items === []) {
			return '';
		}

		return sprintf(
			'<ul class="esml-language-switcher">%s</ul>',
			implode('', $items),
		);
	}

	/**
	 * Shortcode handler — delegates to render().
	 *
	 * Usage: [esml_language_switcher show_native_names="1" show_flags="1"]
	 *
	 * @param array<string, string>|string $atts
	 */
	public function renderShortcode(array|string $atts): string
	{
		$atts = shortcode_atts(
			['show_native_names' => '0', 'show_flags' => '0'],
			$atts,
			'esml_language_switcher',
		);

		return $this->render([
			'showNativeNames' => filter_var($atts['show_native_names'], FILTER_VALIDATE_BOOLEAN),
			'showFlags'       => filter_var($atts['show_flags'], FILTER_VALIDATE_BOOLEAN),
		]);
	}

	// ---------------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------------

	/**
	 * Resolve the URL for a given language, preferring the translated post's
	 * permalink and falling back to the language home URL.
	 */
	private function resolveLanguageUrl(string $langCode, int $currentPostId, string $defaultCode): string
	{
		$homeUrl = trailingslashit(home_url());

		if ($currentPostId > 0) {
			$translatedId = $this->translationRepository->getTranslatedPostId($currentPostId, $langCode);

			if ($translatedId !== null) {
				$url = get_permalink($translatedId);
				// get_permalink() goes through PermalinkFilter which adds the
				// language prefix, so we get the correct prefixed URL for free.
				if ($url) {
					return $url;
				}
			}
		}

		// No translation found — link to the language's home page.
		return $langCode === $defaultCode ? $homeUrl : $homeUrl . $langCode . '/';
	}
}
