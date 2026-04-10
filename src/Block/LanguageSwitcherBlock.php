<?php

declare(strict_types=1);

namespace EightshiftMultilang\Block;

use EightshiftMultilang\Languages\Language;
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
 * Render logic:
 *  1. For each active language, find the translated post via TranslationRepository.
 *  2. If a translation exists, use get_permalink() (PermalinkFilter prefixes it).
 *  3. If no translation exists, fall back to the language's home URL (/{lang}/).
 *  4. Mark the current-language item with aria-current and a CSS modifier class.
 *
 * Attributes:
 *   layout           (string,  default 'vertical')  — 'vertical' | 'horizontal' | 'dropdown'
 *   showNativeNames  (bool,    default false)        — display native name instead of English name
 *   showFlags        (bool,    default false)        — prepend a flag emoji span
 *   showCodes        (bool,    default false)        — display language code (EN, HR, DE…) instead of name
 *   hideActive       (bool,    default false)        — omit the currently active language from the list
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

		// Some themes (e.g. Eightshift UI Kit) enforce a strict block allow-list via
		// allowed_block_types_all at priority 50.  Run at priority 51 so we always
		// append our block after the theme has built its list.
		add_filter('allowed_block_types_all', [$this, 'allowBlock'], 51, 1);
	}

	/**
	 * Ensure the Language Switcher block is always allowed in the editor,
	 * even when a theme restricts the block allow-list.
	 *
	 * @param bool|string[] $allowedBlockTypes
	 * @return bool|string[]
	 */
	public function allowBlock(bool|array $allowedBlockTypes): bool|array
	{
		// When true every block is already allowed — nothing to do.
		if ($allowedBlockTypes === true) {
			return $allowedBlockTypes;
		}

		$allowedBlockTypes[] = 'eightshift-multilang/language-switcher';

		return $allowedBlockTypes;
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
				'layout'          => ['type' => 'string',  'default' => 'vertical'],
				'showNativeNames' => ['type' => 'boolean', 'default' => false],
				'showFlags'       => ['type' => 'boolean', 'default' => false],
				'showCodes'       => ['type' => 'boolean', 'default' => false],
				'hideActive'      => ['type' => 'boolean', 'default' => false],
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

		if ($languages === []) {
			return '';
		}

		$layout          = $this->sanitizeLayout((string) ($attributes['layout'] ?? 'vertical'));
		$showNativeNames = (bool) ($attributes['showNativeNames'] ?? false);
		$showFlags       = (bool) ($attributes['showFlags'] ?? false);
		$showCodes       = (bool) ($attributes['showCodes'] ?? false);
		$hideActive      = (bool) ($attributes['hideActive'] ?? false);

		// The queried object is available during a render_callback, but may be
		// null for non-singular contexts (archives, search results, etc.).
		$currentPostId = is_singular() ? (int) get_queried_object_id() : 0;

		// Resolve URLs and identify the active language up front.
		$items      = [];
		$activeLang = null;

		foreach ($languages as $language) {
			$isActive = ($language->code === $currentLang);

			if ($isActive) {
				$activeLang = $language;
			}

			$items[] = [
				'language' => $language,
				'url'      => $this->resolveLanguageUrl($language->code, $currentPostId, $defaultCode),
				'isActive' => $isActive,
			];
		}

		return match ($layout) {
			'dropdown' => $this->renderDropdown($items, $activeLang, $showCodes, $showNativeNames, $showFlags, $hideActive),
			default    => $this->renderList($items, $layout, $showCodes, $showNativeNames, $showFlags, $hideActive),
		};
	}

	/**
	 * Shortcode handler — delegates to render().
	 *
	 * Usage: [esml_language_switcher layout="horizontal" show_codes="1" hide_active="1" show_flags="0" show_native_names="0"]
	 *
	 * @param array<string, string>|string $atts
	 */
	public function renderShortcode(array|string $atts): string
	{
		$atts = shortcode_atts(
			[
				'layout'            => 'vertical',
				'show_native_names' => '0',
				'show_flags'        => '0',
				'show_codes'        => '0',
				'hide_active'       => '0',
			],
			$atts,
			'esml_language_switcher',
		);

		return $this->render([
			'layout'          => (string) $atts['layout'],
			'showNativeNames' => filter_var($atts['show_native_names'], FILTER_VALIDATE_BOOLEAN),
			'showFlags'       => filter_var($atts['show_flags'], FILTER_VALIDATE_BOOLEAN),
			'showCodes'       => filter_var($atts['show_codes'], FILTER_VALIDATE_BOOLEAN),
			'hideActive'      => filter_var($atts['hide_active'], FILTER_VALIDATE_BOOLEAN),
		]);
	}

	// ---------------------------------------------------------------------------
	// Rendering helpers
	// ---------------------------------------------------------------------------

	/**
	 * Render a vertical or horizontal list of language links.
	 *
	 * @param array<int, array{language: Language, url: string, isActive: bool}> $items
	 */
	private function renderList(
		array $items,
		string $layout,
		bool $showCodes,
		bool $showNativeNames,
		bool $showFlags,
		bool $hideActive,
	): string {
		$listItems = [];

		foreach ($items as $item) {
			if ($hideActive && $item['isActive']) {
				continue;
			}
			$listItems[] = $this->buildItemHtml($item, $showCodes, $showNativeNames, $showFlags);
		}

		if ($listItems === []) {
			return '';
		}

		$modifierClass = $layout === 'horizontal'
			? ' esml-language-switcher--horizontal'
			: ' esml-language-switcher--vertical';

		return sprintf(
			'<ul class="esml-language-switcher%s">%s</ul>',
			$modifierClass,
			implode('', $listItems),
		);
	}

	/**
	 * Render a CSS-only dropdown using <details>/<summary>.
	 *
	 * The summary always displays the currently active language so the user
	 * knows which language is selected.  The list items follow hideActive.
	 *
	 * @param array<int, array{language: Language, url: string, isActive: bool}> $items
	 */
	private function renderDropdown(
		array $items,
		?Language $activeLang,
		bool $showCodes,
		bool $showNativeNames,
		bool $showFlags,
		bool $hideActive,
	): string {
		// Summary label comes from the active language (always shown).
		$summaryContent = $activeLang !== null
			? $this->buildFlagHtml($activeLang, $showFlags) . esc_html($this->buildLabel($activeLang, $showCodes, $showNativeNames))
			: esc_html__('Language', 'eightshift-multilang');

		$listItems = [];

		foreach ($items as $item) {
			if ($hideActive && $item['isActive']) {
				continue;
			}
			$listItems[] = $this->buildItemHtml($item, $showCodes, $showNativeNames, $showFlags);
		}

		if ($listItems === []) {
			return '';
		}

		return sprintf(
			'<div class="esml-language-switcher esml-language-switcher--dropdown">'
			. '<details class="esml-switcher__details">'
			. '<summary class="esml-switcher__summary">%s</summary>'
			. '<ul class="esml-switcher__list">%s</ul>'
			. '</details>'
			. '</div>',
			$summaryContent,
			implode('', $listItems),
		);
	}

	/**
	 * Build the <li>…</li> HTML for a single language item.
	 *
	 * @param array{language: Language, url: string, isActive: bool} $item
	 */
	private function buildItemHtml(array $item, bool $showCodes, bool $showNativeNames, bool $showFlags): string
	{
		$language = $item['language'];
		$isActive = $item['isActive'];

		return sprintf(
			'<li class="esml-switcher__item%s">'
			. '<a href="%s" hreflang="%s" lang="%s"%s>%s%s</a>'
			. '</li>',
			$isActive ? ' esml-switcher__item--active' : '',
			esc_url($item['url']),
			esc_attr(str_replace('_', '-', $language->locale)),
			esc_attr($language->code),
			$isActive ? ' aria-current="true"' : '',
			$this->buildFlagHtml($language, $showFlags),
			esc_html($this->buildLabel($language, $showCodes, $showNativeNames)),
		);
	}

	/**
	 * Resolve the display label for a language.
	 * Priority: codes → native names → English names.
	 */
	private function buildLabel(Language $language, bool $showCodes, bool $showNativeNames): string
	{
		if ($showCodes) {
			return strtoupper($language->code);
		}

		return $showNativeNames ? $language->nativeName : $language->name;
	}

	/**
	 * Build the flag <span> HTML, or an empty string when flags are disabled.
	 */
	private function buildFlagHtml(Language $language, bool $showFlags): string
	{
		if (!$showFlags || $language->flagCode === '') {
			return '';
		}

		return sprintf(
			'<span class="esml-switcher__flag esml-flag--%s" aria-hidden="true"></span>',
			esc_attr($language->flagCode),
		);
	}

	/**
	 * Sanitise the layout attribute, falling back to 'vertical' for unknown values.
	 */
	private function sanitizeLayout(string $layout): string
	{
		return in_array($layout, ['vertical', 'horizontal', 'dropdown'], true) ? $layout : 'vertical';
	}

	// ---------------------------------------------------------------------------
	// URL resolution
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
