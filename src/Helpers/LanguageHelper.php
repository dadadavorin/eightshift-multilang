<?php

declare(strict_types=1);

namespace EightshiftMultilang\Helpers;

use EightshiftMultilang\Router\LanguageDetector;

/**
 * Template-tag helper functions for use in themes and other plugins.
 *
 * Functions are defined in the global namespace so they are callable from
 * any template file without namespace imports.
 */

if (! function_exists('esml_get_current_language')) {
	/**
	 * Return the language code for the current request.
	 *
	 * Returns an empty string before parse_request has fired (e.g. during
	 * plugins_loaded or init when called too early). Themes should call this
	 * from template_redirect or later hooks.
	 *
	 * @return string Language code (e.g. 'de', 'fr') or '' if not yet resolved.
	 */
	function esml_get_current_language(): string
	{
		return LanguageDetector::getCurrentLanguage() ?? '';
	}
}

if (! function_exists('esml_is_language')) {
	/**
	 * Check whether the current request is for a specific language.
	 *
	 * @param string $code Language code to test against.
	 */
	function esml_is_language(string $code): bool
	{
		return esml_get_current_language() === $code;
	}
}

if (! function_exists('esml_language_switcher')) {
	/**
	 * Output (echo) the language switcher HTML.
	 *
	 * Uses the registered LanguageSwitcherBlock renderer via the
	 * eightshift-multilang/language-switcher shortcode so the output is
	 * identical whether called from a template tag, shortcode, or block.
	 *
	 * @param bool $showNativeNames Display native language name instead of English name.
	 * @param bool $showFlags       Prepend a flag span to each item.
	 */
	function esml_language_switcher(bool $showNativeNames = false, bool $showFlags = false): void
	{
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo do_shortcode(
			sprintf(
				'[esml_language_switcher show_native_names="%d" show_flags="%d"]',
				(int) $showNativeNames,
				(int) $showFlags,
			)
		);
	}
}
